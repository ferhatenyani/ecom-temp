<?php

declare(strict_types=1);

namespace AlgerianCommerce\Customers;

use AlgerianCommerce\API\ApiException;
use AlgerianCommerce\Audit\AuditLogger;
use AlgerianCommerce\Orders\OrderRepository;
use AlgerianCommerce\Permissions\Capabilities;
use AlgerianCommerce\Permissions\Permissions;
use WC_Customer;
use WC_Order;

/**
 * Customer business rules — roadmap §50, docs/PLAN.md §9.
 *
 * Depends on the order repository, one way: a customer's history and their
 * statistics are made of orders. Nothing in `Orders/` reaches back — an order
 * holds a `customer_id`, an integer, and never a customer object — so the two
 * domains do not form a cycle.
 *
 * Authorization is asserted here as well as on the route, and the capability is
 * `ac_manage_customers`, which Support Agent holds. That is the whole reason
 * CustomerInput refuses roles and credentials: this service is reachable by the
 * least-privileged staff account in the system.
 */
final class CustomerService
{
    public function __construct(
        private readonly CustomerRepository $repository,
        private readonly OrderRepository $orders,
        private readonly AuditLogger $audit
    ) {
    }

    /**
     * @param array<string, mixed> $criteria
     * @return array{items: list<WC_Customer>, total: int}
     */
    public function list(array $criteria): array
    {
        Permissions::assert(Capabilities::MANAGE_CUSTOMERS);

        return $this->repository->paginate($criteria);
    }

    public function get(int $id): WC_Customer
    {
        Permissions::assert(Capabilities::MANAGE_CUSTOMERS);

        return $this->requireCustomer($id);
    }

    /**
     * Lifetime statistics, computed from the customer's orders on read.
     *
     * Not stored. A cached total is a number that can be wrong, and every
     * event that would invalidate it — a status change, a refund, an order
     * deleted in wp-admin — happens somewhere this plugin does not control.
     *
     * @return array<string, mixed>
     */
    public function statistics(int $id): array
    {
        Permissions::assert(Capabilities::MANAGE_CUSTOMERS);

        $this->requireCustomer($id);

        return CustomerStatistics::compute(
            $this->orders->customerOrderSummaries($id),
            function_exists('wc_get_price_decimals') ? (int) wc_get_price_decimals() : 2
        );
    }

    /** @param array<string, mixed> $payload */
    public function update(int $id, array $payload): WC_Customer
    {
        Permissions::assert(Capabilities::MANAGE_CUSTOMERS);

        $customer = $this->requireCustomer($id);
        $input = CustomerInput::fromPayload($payload);

        if ($input->isEmpty()) {
            throw ApiException::invalidRequest('No supported fields were provided.');
        }

        if ($input->has('email')) {
            $this->guardEmail((string) $input->get('email'), $id);
        }

        $before = [
            'email' => $customer->get_email(),
            'first_name' => $customer->get_first_name(),
            'last_name' => $customer->get_last_name(),
        ];

        $updated = $this->repository->update($customer, $input);

        $this->audit->record('customer.updated', 'customer', $id, [
            'fields' => array_keys($input->fields),
            'before' => $before,
            'after' => [
                'email' => $updated->get_email(),
                'first_name' => $updated->get_first_name(),
                'last_name' => $updated->get_last_name(),
            ],
        ]);

        return $updated;
    }

    /**
     * A customer's order history.
     *
     * Goes through the order repository's ordinary paginated query with the
     * customer filter set, so this list and `/orders?customer_id=` cannot drift
     * apart in shape, sorting or pagination.
     *
     * @param array<string, mixed> $criteria
     * @return array{items: list<WC_Order>, total: int}
     */
    public function orders(int $id, array $criteria): array
    {
        Permissions::assert(Capabilities::MANAGE_CUSTOMERS);

        $this->requireCustomer($id);

        return $this->orders->paginate(['customer_id' => $id] + $criteria);
    }

    private function requireCustomer(int $id): WC_Customer
    {
        $customer = $this->repository->find($id);

        if ($customer === null) {
            throw ApiException::notFound('No customer with that id.');
        }

        return $customer;
    }

    /**
     * A duplicate email is a conflict, not a validation error — the address is
     * well formed, another account simply already uses it. WordPress would
     * otherwise fail the save with a WP_Error nobody translates, or silently
     * leave the old address in place.
     */
    private function guardEmail(string $email, int $id): void
    {
        if ($this->repository->emailExists($email, $id)) {
            throw ApiException::conflict('That email address is already in use.', ['email' => $email]);
        }
    }
}
