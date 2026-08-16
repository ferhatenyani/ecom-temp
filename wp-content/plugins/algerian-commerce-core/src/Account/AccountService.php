<?php

declare(strict_types=1);

namespace AlgerianCommerce\Account;

use AlgerianCommerce\API\ApiException;
use AlgerianCommerce\Audit\AuditLogger;
use AlgerianCommerce\Customers\CustomerInput;
use AlgerianCommerce\Customers\CustomerPresenter;
use AlgerianCommerce\Customers\CustomerRepository;
use AlgerianCommerce\Orders\OrderPresenter;
use AlgerianCommerce\Orders\OrderRepository;
use AlgerianCommerce\Permissions\Capabilities;
use AlgerianCommerce\Permissions\Permissions;
use AlgerianCommerce\Security\RateLimiter;
use WC_Customer;
use WC_Order;
use WP_REST_Request;
use WP_User;

/**
 * Shopper accounts — roadmap §59c, docs/PLAN.md §53.
 *
 * **This is the module roadmap §59c was written to warn about**, and the
 * warning is worth restating where the code is: order history is where this
 * project's first real IDOR lands. A shopper opens their orders, sees
 * `/orders/123`, edits it to `124`, and reads a stranger's name, phone,
 * address and basket. Nothing crashes and nothing is logged as an error,
 * because the request is *valid* — only the authorization is missing.
 *
 * Three things had to be true and the third is the one that gets skipped:
 *
 *  1. the storefront asks for **"my orders"**, never "orders for customer 5" —
 *     satisfied structurally, because `AccountSession` takes no user id and
 *     these routes declare no parameter that could carry one;
 *  2. the Next.js server never forwards a browser-supplied id under its own
 *     privileged credential — that half lives in the storefront repository;
 *  3. **this API checks ownership itself**, which is `orders()` and `order()`
 *     below. A check that lives only in the storefront is a check the second
 *     client removes.
 *
 * `Permissions::assertOwnsOr()` was written in §50 and has had no call sites
 * since. These are the first two.
 */
final class AccountService
{
    /** Long enough for a real address, short enough not to be a payload. */
    private const MAX_ORDERS_PER_PAGE = 50;

    public function __construct(
        private readonly AccountSession $session,
        private readonly CustomerRepository $customers,
        private readonly OrderRepository $orders,
        private readonly AuditLogger $audit,
        private readonly RateLimiter $rateLimiter
    ) {
    }

    /**
     * Create a shopper account.
     *
     * @param array{email: string, password: string, first_name: string, last_name: string} $input
     * @return array<string, mixed>
     */
    public function register(WP_REST_Request $request, array $input): array
    {
        $this->guardCredentialAttempts($request);

        if (email_exists($input['email']) !== false) {
            // A conflict rather than a 400: the address is well formed, an
            // account simply already uses it. This *is* a user-enumeration
            // oracle, and it is the one place the trade goes the other way —
            // a registration form that accepts a duplicate silently, or lies,
            // cannot tell a customer why they cannot sign in afterwards.
            throw ApiException::conflict('An account already uses that email address.', [
                'email' => $input['email'],
            ]);
        }

        $userId = wp_insert_user([
            'user_login' => $input['email'],
            'user_email' => $input['email'],
            'user_pass' => $input['password'],
            'first_name' => $input['first_name'],
            'last_name' => $input['last_name'],
            // Forced, never taken from the payload. `AccountInput` refuses
            // `role`, `roles` and `capabilities` by name as well, so this is
            // the second of two locks rather than the only one.
            'role' => AccountSession::ROLE,
        ]);

        if (is_wp_error($userId)) {
            throw ApiException::invalidRequest('The account could not be created.', [
                'fields' => ['email' => $userId->get_error_message()],
            ]);
        }

        $user = get_user_by('id', (int) $userId);

        if (!$user instanceof WP_User) {
            throw ApiException::internal('The account could not be created.');
        }

        $this->audit->record('customer.registered', 'customer', (int) $userId, [
            'email' => $input['email'],
        ]);

        return $this->sessionPayload($user, $this->session->issue($user));
    }

    /**
     * Sign in.
     *
     * @return array<string, mixed>
     */
    public function login(WP_REST_Request $request, string $email, string $password): array
    {
        $this->guardCredentialAttempts($request);

        try {
            $user = $this->session->authenticate($email, $password);
        } catch (ApiException $e) {
            // **The brute-force guard does not watch this door on its own.**
            // `RateLimitGuard` hooks `application_password_failed_authentication`,
            // which is WordPress's admin path; a customer login goes nowhere
            // near it. Recording the failure here is what puts this endpoint
            // behind the same 10-failures-per-15-minutes lockout, and
            // scripts/test-api.sh asserts it, because only that stage can see
            // an IP at all.
            $this->rateLimiter->recordAuthFailure($this->clientIp(), time());

            throw $e;
        }

        return $this->sessionPayload($user, $this->session->issue($user));
    }

    /** @return array<string, mixed> */
    public function logout(WP_REST_Request $request): array
    {
        $user = $this->session->require($request);

        $this->session->destroy($request, $user);

        return ['signed_out' => true];
    }

    /**
     * The shopper's own profile.
     *
     * @return array<string, mixed>
     */
    public function profile(WP_REST_Request $request): array
    {
        $user = $this->session->require($request);

        return CustomerPresenter::toArray($this->requireCustomer($user->ID));
    }

    /**
     * Update the shopper's own profile, including the address book.
     *
     * Reuses `CustomerInput`, which already refuses `roles`, `capabilities` and
     * `user_pass` **by name** — the escalation path this endpoint would
     * otherwise open, since it is the one write a non-staff caller can make to
     * a WordPress user. `CustomerInputTest` proves the refusal; the account
     * suite proves it survives this route.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function updateProfile(WP_REST_Request $request, array $payload): array
    {
        $user = $this->session->require($request);
        $customer = $this->requireCustomer($user->ID);

        $input = CustomerInput::fromPayload($payload);

        if ($input->has('email')) {
            $email = (string) $input->get('email');

            if ($email !== $customer->get_email() && email_exists($email) !== false) {
                throw ApiException::conflict('An account already uses that email address.', [
                    'email' => $email,
                ]);
            }
        }

        $updated = $this->customers->update($customer, $input);

        $this->audit->record('customer.updated', 'customer', $user->ID, [
            'fields' => array_keys($payload),
            'by' => 'self',
        ]);

        return CustomerPresenter::toArray($updated);
    }

    /**
     * Change the password, which signs every other session out.
     *
     * The current password is required even though the caller already holds a
     * valid session: a session token left open on a shared machine must not be
     * enough to take the account over. That is the same reason WordPress asks.
     *
     * @return array<string, mixed>
     */
    public function changePassword(WP_REST_Request $request, array $payload): array
    {
        // **The session is required before the payload is even looked at.**
        // Validating first meant an anonymous caller got a 400 listing the
        // fields this endpoint wants — authentication answering after input
        // validation, which is backwards, and which told someone with no
        // session more about the endpoint than the 401 they should have had.
        // Caught by tests/Api/account.php.
        $user = $this->session->require($request);

        $passwords = AccountInput::forPasswordChange($payload);
        $current = $passwords['current'];
        $new = $passwords['new'];

        if (!wp_check_password($current, $user->user_pass, $user->ID)) {
            $this->rateLimiter->recordAuthFailure($this->clientIp(), time());

            throw ApiException::invalidRequest('That password is not correct.', [
                'fields' => ['current_password' => 'Incorrect.'],
            ]);
        }

        wp_set_password($new, $user->ID);

        $this->audit->record('customer.password_changed', 'customer', $user->ID, ['by' => 'self']);

        // Every session, this one included, is now invalid — the auth-cookie
        // HMAC covers a fragment of the password hash. Issuing a fresh one is
        // what stops a password change reading as a random sign-out.
        $refreshed = get_user_by('id', $user->ID);

        return $refreshed instanceof WP_User
            ? $this->sessionPayload($refreshed, $this->session->issue($refreshed))
            : ['signed_out' => true];
    }

    /**
     * The shopper's own orders — **the IDOR route**.
     *
     * There is no `customer_id` parameter and there will never be one. The id
     * comes from the session and is passed to the same
     * `OrderRepository::paginate()` the staff list uses, so the two cannot
     * drift apart in shape or sorting.
     *
     * @param array<string, mixed> $criteria
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function orders(WP_REST_Request $request, array $criteria): array
    {
        $user = $this->session->require($request);

        $perPage = min(self::MAX_ORDERS_PER_PAGE, max(1, (int) ($criteria['per_page'] ?? 10)));

        $result = $this->orders->paginate([
            // Not from the request. This single line is the whole defence, and
            // the test that matters asserts a second customer cannot reach
            // these rows by any parameter at all.
            'customer_id' => $user->ID,
            'page' => max(1, (int) ($criteria['page'] ?? 1)),
            'per_page' => $perPage,
            'search' => '',
            'status' => (string) ($criteria['status'] ?? ''),
            'date_from' => '',
            'date_to' => '',
            'orderby' => 'date',
            'order' => 'desc',
        ]);

        return [
            'items' => array_map(
                static fn (WC_Order $order): array => OrderPresenter::toArray($order),
                $result['items']
            ),
            'total' => $result['total'],
        ];
    }

    /**
     * One of the shopper's own orders — **the other IDOR route**.
     *
     * The id *is* taken from the path here, because a customer legitimately
     * addresses their own order by number. What makes that safe is the line
     * below it: `assertOwnsOr()` compares the order's customer against the
     * session's, and a caller who is neither the owner nor staff gets 403.
     *
     * A **guest order belongs to nobody** (`customer_id` 0) and is therefore
     * reachable by no session — the comparison can never succeed, which is the
     * correct answer rather than an oversight. A shopper who checked out as a
     * guest and later registered has no claim this API can verify: the only
     * evidence is an email address, and treating that as proof of ownership
     * would make an order readable by anyone who could name the address on it.
     *
     * @return array<string, mixed>
     */
    public function order(WP_REST_Request $request, int $orderId): array
    {
        $this->session->require($request);

        $order = wc_get_order($orderId);

        if (!$order instanceof WC_Order) {
            throw ApiException::notFound('No order with that id.');
        }

        $owner = (int) $order->get_customer_id();

        if ($owner <= 0) {
            throw ApiException::forbidden();
        }

        // The first call site `Permissions::assertOwnsOr()` has ever had.
        Permissions::assertOwnsOr($owner, Capabilities::MANAGE_ORDERS);

        return OrderPresenter::toArray($order);
    }

    /**
     * Refuse the request outright while this address is locked out.
     *
     * Checked *before* the password is, so a locked-out attacker learns nothing
     * from the timing of a wrong guess.
     *
     * @throws ApiException 429
     */
    private function guardCredentialAttempts(WP_REST_Request $request): void
    {
        unset($request);

        if ($this->rateLimiter->isDisabled()) {
            return;
        }

        if ($this->rateLimiter->isAuthBlocked($this->clientIp(), time())) {
            throw new ApiException(
                'rate_limited',
                'Too many attempts. Try again later.',
                429
            );
        }
    }

    private function clientIp(): string
    {
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');

        return $ip === '' ? '0.0.0.0' : $ip;
    }

    private function requireCustomer(int $id): WC_Customer
    {
        $customer = $this->customers->find($id);

        if ($customer === null) {
            throw ApiException::notFound('No customer with that id.');
        }

        return $customer;
    }

    /**
     * @param array{token: string, expires_at: string} $session
     * @return array<string, mixed>
     */
    private function sessionPayload(WP_User $user, array $session): array
    {
        return [
            'customer' => [
                'id' => $user->ID,
                'email' => $user->user_email,
                'first_name' => (string) $user->first_name,
                'last_name' => (string) $user->last_name,
            ],
            'token' => $session['token'],
            'expires_at' => $session['expires_at'],
        ];
    }
}
