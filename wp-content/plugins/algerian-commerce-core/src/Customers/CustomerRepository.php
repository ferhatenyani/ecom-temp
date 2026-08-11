<?php

declare(strict_types=1);

namespace AlgerianCommerce\Customers;

use AlgerianCommerce\Commerce\AddressInput;
use WC_Customer;
use WP_User_Query;

/**
 * The WooCommerce/WordPress adapter for customers.
 *
 * A customer is a WordPress user that WooCommerce decorates, so this file talks
 * to both — WP_User_Query to find them, WC_Customer to read and write the
 * commerce fields. Nothing above it knows either exists.
 *
 * **Guests are not customers here.** A cash-on-delivery shop takes most orders
 * without an account, and those orders carry `customer_id` 0 and their buyer's
 * details on the order itself. There is no user row to list, profile or update,
 * so they are found through `/orders` and its billing search, not here.
 */
final class CustomerRepository
{
    /**
     * The role WooCommerce assigns on registration.
     *
     * Fixed rather than a request parameter: a `role` filter on this endpoint
     * would let `ac_manage_customers` enumerate administrators, which is a
     * reconnaissance step, not customer management.
     */
    public const ROLE = 'customer';

    /** Sortable columns WP_User_Query understands and that mean something here. */
    public const ORDERBY = ['registered', 'ID', 'display_name', 'user_email'];

    /**
     * Only users holding the customer role.
     *
     * WC_Customer wraps *any* WordPress user, so without this check
     * `GET /customers/{id}` would happily return an administrator's name and
     * email to anyone with `ac_manage_customers` — which is Support Agent, the
     * thinnest role there is. Guarding the list against role enumeration and
     * leaving the single read open would be no guard at all.
     *
     * The cost is that a staff account which also shops is not a customer
     * record. Its orders are still visible under `/orders`; it simply has no
     * profile here.
     */
    public function find(int $id): ?WC_Customer
    {
        if ($id <= 0) {
            return null;
        }

        $user = get_userdata($id);

        if ($user === false || !in_array(self::ROLE, (array) $user->roles, true)) {
            return null;
        }

        $customer = new WC_Customer($id);

        // WC_Customer swallows the read failure for a missing user and resets
        // the id to 0 rather than throwing, so this is how "not found" arrives.
        return $customer->get_id() > 0 ? $customer : null;
    }

    /**
     * @param array{page: int, per_page: int, search: string, orderby: string, order: string} $criteria
     * @return array{items: list<WC_Customer>, total: int}
     */
    public function paginate(array $criteria): array
    {
        $args = [
            'role' => self::ROLE,
            'number' => $criteria['per_page'],
            'paged' => $criteria['page'],
            'count_total' => true,
            'fields' => 'ID',
            'orderby' => in_array($criteria['orderby'], self::ORDERBY, true) ? $criteria['orderby'] : 'registered',
            'order' => strtoupper($criteria['order']) === 'ASC' ? 'ASC' : 'DESC',
        ];

        if ($criteria['search'] !== '') {
            /*
             * Wildcards on both sides so "ben" finds "benali". Restricted to
             * the user columns: matching on billing meta would need a
             * meta_query join per field, and the display name already carries
             * the customer's name for anyone who registered.
             */
            $args['search'] = '*' . $criteria['search'] . '*';
            $args['search_columns'] = ['user_login', 'user_email', 'user_nicename', 'display_name'];
        }

        $query = new WP_User_Query($args);

        $customers = [];

        foreach ($query->get_results() as $id) {
            $customer = $this->find((int) $id);

            if ($customer !== null) {
                $customers[] = $customer;
            }
        }

        return ['items' => $customers, 'total' => (int) $query->get_total()];
    }

    public function emailExists(string $email, int $ignoreId): bool
    {
        $existing = email_exists($email);

        return $existing !== false && (int) $existing !== $ignoreId;
    }

    public function update(WC_Customer $customer, CustomerInput $input): WC_Customer
    {
        foreach (['first_name' => 'set_first_name', 'last_name' => 'set_last_name', 'email' => 'set_email'] as $field => $setter) {
            if ($input->has($field)) {
                $customer->{$setter}((string) $input->get($field));
            }
        }

        foreach (['billing', 'shipping'] as $type) {
            if (!$input->has($type)) {
                continue;
            }

            /** @var AddressInput $address */
            $address = $input->get($type);

            foreach ($address->fields as $field => $value) {
                $customer->{"set_{$type}_{$field}"}($value);
            }
        }

        $customer->save();

        return $this->find($customer->get_id()) ?? $customer;
    }
}
