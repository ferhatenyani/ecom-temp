<?php

declare(strict_types=1);

namespace AlgerianCommerce\Users;

use AlgerianCommerce\API\ApiException;
use AlgerianCommerce\Audit\AuditLogger;
use AlgerianCommerce\Orders\OrderRepository;
use AlgerianCommerce\Permissions\Capabilities;
use AlgerianCommerce\Permissions\Permissions;
use WP_User;

/**
 * Staff account rules — roadmap §87, docs/PLAN.md §52, docs/ADMIN_PANEL.md.
 *
 * `ac_manage_users` was declared in §45 and had no call site until this file.
 * It is Super Admin's alone, and it is the capability that makes Super Admin
 * different from Admin: an Admin holds every operational capability and still
 * cannot create an account, move a role or mint a credential.
 *
 * **Privilege escalation is the whole risk of this class**, so the guards are
 * named and separate rather than folded into the writes:
 *
 *  - `guardAssignable()`  — no core roles, and nothing beyond the caller's own
 *  - `guardNotSelfRole()` — you may not move your own role
 *  - `guardNotSelf()`     — you may not delete or suspend yourself
 *  - `guardNoOrders()`    — deleting an account that owns orders is refused
 *
 * Each answers 403 or 409 with the reason in the body, because a refusal a
 * client cannot explain is one a user works around by asking somebody with more
 * access to do it for them.
 */
final class UserService
{
    public function __construct(
        private readonly UserRepository $repository,
        private readonly OrderRepository $orders,
        private readonly AuditLogger $audit
    ) {
    }

    /**
     * @param array<string, mixed> $criteria
     * @return array{items: list<WP_User>, total: int}
     */
    public function list(array $criteria): array
    {
        Permissions::assert(Capabilities::MANAGE_USERS);

        return $this->repository->paginate($criteria);
    }

    public function get(int $id): WP_User
    {
        Permissions::assert(Capabilities::MANAGE_USERS);

        return $this->requireStaff($id);
    }

    /** @return list<array<string, mixed>> */
    public function roles(): array
    {
        Permissions::assert(Capabilities::MANAGE_USERS);

        return RolePresenter::all();
    }

    /** @param array<string, mixed> $payload */
    public function create(array $payload): WP_User
    {
        Permissions::assert(Capabilities::MANAGE_USERS);

        $input = UserInput::forCreate($payload);
        $role = (string) $input->get('role');

        $this->guardAssignable($role);
        $this->guardUsername((string) $input->get('username'));
        $this->guardEmail((string) $input->get('email'), 0);

        $user = $this->repository->create($input);

        /*
         * The role is recorded as a value, and this is the one place §71's
         * "field names, never values" rule does not apply. That rule exists so
         * a shop's trade-register numbers do not end up in a table nobody
         * cleans. Here the value *is* the security fact — an audit row saying
         * "somebody's role changed" answers none of the questions the trail is
         * read to answer.
         */
        $this->audit->record('user.created', 'user', (int) $user->ID, [
            'login' => (string) $user->user_login,
            'role' => $role,
            'fields' => array_keys($input->fields),
        ]);

        return $user;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{user: WP_User, promoted: bool}
     */
    public function update(int $id, array $payload): array
    {
        Permissions::assert(Capabilities::MANAGE_USERS);

        $input = UserInput::forUpdate($payload);

        if ($input->isEmpty()) {
            throw ApiException::invalidRequest('No supported fields were provided.');
        }

        /*
         * Promotion: an account with no staff role is not findable by `find()`,
         * but assigning it one is a real operation — the shop owner's own
         * account is often a customer first. So a non-staff target is allowed
         * through *only* when the payload assigns a role, and any other write
         * against it is the 404 it would have been.
         */
        $assigningRole = $input->has('role');
        $existing = $this->repository->find($id);
        $promoted = false;

        if ($existing === null) {
            $candidate = $assigningRole ? $this->repository->findAny($id) : null;

            if ($candidate === null) {
                throw ApiException::notFound('No staff account with that id.');
            }

            $existing = $candidate;
            $promoted = true;
        }

        $before = [
            'role' => self::currentRole($existing),
            'status' => UserStatus::of($id),
        ];

        if ($assigningRole) {
            $this->guardNotSelfRole($id);
            $this->guardAssignable((string) $input->get('role'));
        }

        if ($input->has('status') && (string) $input->get('status') === UserStatus::SUSPENDED) {
            $this->guardNotSelf($id, 'You cannot suspend your own account.');
        }

        if ($input->has('email')) {
            $this->guardEmail((string) $input->get('email'), $id);
        }

        $updated = $this->repository->update($existing, $input);

        $after = [
            'role' => self::currentRole($updated),
            'status' => UserStatus::of($id),
        ];

        $this->recordUpdate($id, $input, $before, $after, $promoted, (string) $updated->user_login);

        return ['user' => $updated, 'promoted' => $promoted];
    }

    public function delete(int $id): void
    {
        Permissions::assert(Capabilities::MANAGE_USERS);

        $this->guardNotSelf($id, 'You cannot delete your own account.');

        $user = $this->requireStaff($id);
        $this->guardNoOrders($id);

        $role = self::currentRole($user);
        $login = (string) $user->user_login;

        if (!$this->repository->delete($id)) {
            throw ApiException::internal('The account could not be deleted.');
        }

        $this->audit->record('user.deleted', 'user', $id, [
            'login' => $login,
            'role' => $role,
        ]);
    }

    /** @return list<array<string, mixed>> */
    public function applicationPasswords(int $id): array
    {
        Permissions::assert(Capabilities::MANAGE_USERS);

        $this->requireStaff($id);

        return $this->repository->applicationPasswords($id);
    }

    /**
     * Mint an application password and return it once.
     *
     * This endpoint is why §87 exists. WordPress shows an application password
     * exactly once, at creation, in wp-admin — the dashboard PLAN §52 says
     * routine administration must not require. Without this, per-user staff
     * credentials have no onboarding path that does not go through the very
     * screen the admin panel replaces.
     *
     * @return array{password: string, item: array<string, mixed>}
     */
    public function createApplicationPassword(int $id, string $name): array
    {
        Permissions::assert(Capabilities::MANAGE_USERS);

        $user = $this->requireStaff($id);

        /*
         * 503 rather than 500 or a confusing 400: the caller did nothing wrong
         * and the shop's operator has something to fix. This is §59c's
         * `mail_not_configured` shape — a precondition that is about the
         * install rather than about the request, named so it can be acted on.
         */
        if (!$this->repository->canIssueApplicationPasswords()) {
            throw new ApiException(
                'application_passwords_unavailable',
                'This install cannot issue application passwords. WordPress requires HTTPS unless WP_ENVIRONMENT_TYPE is "local".',
                503
            );
        }

        /*
         * A suspended account is one whose credentials are refused at every
         * route, so minting it a new one produces a credential that cannot be
         * used and a screen that says otherwise.
         */
        if (UserStatus::isSuspended($id)) {
            throw ApiException::conflict('That account is suspended. Reactivate it before issuing a credential.');
        }

        $name = trim($name);

        /*
         * WordPress does not check this and the screen this feeds is a list of
         * devices to revoke. Two entries called "iPhone" are two entries nobody
         * can tell apart, and revoking the wrong one signs out the wrong phone.
         */
        foreach ($this->repository->applicationPasswords($id) as $existing) {
            if (strcasecmp((string) ($existing['name'] ?? ''), $name) === 0) {
                throw ApiException::conflict('That account already has an application password with this name.', ['name' => $name]);
            }
        }

        [$password, $item] = $this->repository->createApplicationPassword($id, $name);

        /*
         * The name and the uuid, never the password. `Logger::redact()` would
         * mask a key called `password` anyway — AuditEvent runs it over every
         * metadata array before storage — but relying on that would make this
         * class correct by accident, and an append-only table is the wrong
         * place to find out.
         */
        $this->audit->record('user.app_password_created', 'user', $id, [
            'login' => (string) $user->user_login,
            'name' => $name,
            'uuid' => (string) ($item['uuid'] ?? ''),
        ]);

        return ['password' => $password, 'item' => $item];
    }

    public function deleteApplicationPassword(int $id, string $uuid): void
    {
        Permissions::assert(Capabilities::MANAGE_USERS);

        $user = $this->requireStaff($id);

        $item = $this->repository->findApplicationPassword($id, $uuid);

        if ($item === null) {
            throw ApiException::notFound('No application password with that identifier.');
        }

        if (!$this->repository->deleteApplicationPassword($id, $uuid)) {
            throw ApiException::internal('The application password could not be revoked.');
        }

        $this->audit->record('user.app_password_revoked', 'user', $id, [
            'login' => (string) $user->user_login,
            'name' => (string) ($item['name'] ?? ''),
            'uuid' => $uuid,
        ]);
    }

    private function requireStaff(int $id): WP_User
    {
        $user = $this->repository->find($id);

        if ($user === null) {
            throw ApiException::notFound('No staff account with that id.');
        }

        return $user;
    }

    /**
     * Refuse a role the caller may not hand out.
     *
     * Two halves. The vocabulary half — core roles, unknown roles — is
     * `UserInput`'s and has already run by the time this is called. This is the
     * half that needs to know who is asking: a caller may not create an account
     * that can do something they cannot, or `ac_manage_users` becomes a
     * one-step path to every other capability in the matrix.
     */
    private function guardAssignable(string $role): void
    {
        $beyond = UserRoles::capabilitiesBeyond(
            $role,
            static fn (string $capability): bool => current_user_can($capability)
        );

        if ($beyond !== []) {
            throw ApiException::forbidden(sprintf(
                'You cannot grant "%s": it holds capabilities you do not have (%s).',
                $role,
                implode(', ', $beyond)
            ));
        }
    }

    /**
     * You may not move your own role.
     *
     * A demotion you can perform on yourself is one you cannot undo — the
     * capability that would let you undo it is the one you just gave away — and
     * a promotion you can perform on yourself is not a permission system.
     */
    private function guardNotSelfRole(int $id): void
    {
        if ($id === get_current_user_id()) {
            throw ApiException::forbidden(
                'You cannot change your own role. Ask another Super Admin.'
            );
        }
    }

    private function guardNotSelf(int $id, string $message): void
    {
        if ($id === get_current_user_id()) {
            throw ApiException::forbidden($message);
        }
    }

    private function guardUsername(string $username): void
    {
        // WordPress's own answer, and the second gate: UserInput checked the
        // character vocabulary, this catches a name the install has blocked.
        if (!validate_username($username)) {
            throw ApiException::invalidRequest('The user data is invalid.', [
                'fields' => ['username' => 'WordPress will not accept this username.'],
            ]);
        }

        if ($this->repository->usernameExists($username)) {
            throw ApiException::conflict('That username is already taken.', ['username' => $username]);
        }
    }

    private function guardEmail(string $email, int $ignoreId): void
    {
        if ($this->repository->emailExists($email, $ignoreId)) {
            throw ApiException::conflict('That email address is already in use.', ['email' => $email]);
        }
    }

    /**
     * Refuse to delete an account that owns orders.
     *
     * `wp_delete_user()` reassigns *posts*; it knows nothing about HPOS, so an
     * order keyed to the deleted `customer_id` becomes a row no report can
     * attribute and no customer screen can open. Suspension is what a shop
     * actually wants on the day somebody leaves, and it is offered in the same
     * error.
     */
    private function guardNoOrders(int $id): void
    {
        $count = count($this->orders->customerOrderSummaries($id));

        if ($count > 0) {
            throw ApiException::conflict(
                'That account owns orders and cannot be deleted. Suspend it instead: PATCH /users/{id} with {"status":"suspended"}.',
                ['orders' => $count]
            );
        }
    }

    /**
     * Up to three audit rows for one PATCH, because they are three separately
     * queryable facts.
     *
     * A single `user.updated` carrying "role and status both changed" is a row
     * nobody can filter for, and the two questions an audit trail is opened to
     * answer — who moved this person's role, who suspended them — are exactly
     * the two that would be buried in it.
     *
     * @param array{role: string, status: string} $before
     * @param array{role: string, status: string} $after
     */
    private function recordUpdate(
        int $id,
        UserInput $input,
        array $before,
        array $after,
        bool $promoted,
        string $login
    ): void {
        if ($before['role'] !== $after['role']) {
            $this->audit->record('user.role_changed', 'user', $id, [
                'login' => $login,
                'from' => $before['role'],
                'to' => $after['role'],
                // A customer becoming staff is the write that changes what §44
                // says about the account: a customer is issued no application
                // password and a staff member is. Flagged in the trail rather
                // than left to be inferred from a role that was previously
                // blank.
                'promoted_from_customer' => $promoted,
            ]);
        }

        if ($before['status'] !== $after['status']) {
            $this->audit->record(
                $after['status'] === UserStatus::SUSPENDED ? 'user.suspended' : 'user.reactivated',
                'user',
                $id,
                ['login' => $login]
            );
        }

        $other = array_diff(array_keys($input->fields), ['role', 'status']);

        if ($other !== []) {
            // Field names only — an email address is PII and §71's rule holds
            // for everything that is not the authorization fact itself.
            $this->audit->record('user.updated', 'user', $id, [
                'login' => $login,
                'fields' => array_values($other),
            ]);
        }
    }

    private static function currentRole(WP_User $user): string
    {
        foreach (array_map('strval', (array) $user->roles) as $role) {
            if (UserRoles::isManaged($role)) {
                return $role;
            }
        }

        return array_values(array_map('strval', (array) $user->roles))[0] ?? '';
    }
}
