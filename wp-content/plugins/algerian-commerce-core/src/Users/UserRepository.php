<?php

declare(strict_types=1);

namespace AlgerianCommerce\Users;

use AlgerianCommerce\API\ApiException;
use WP_Application_Passwords;
use WP_Error;
use WP_User;
use WP_User_Query;

/**
 * The WordPress adapter for staff accounts.
 *
 * Nothing above this file knows that a role is a row in `wp_usermeta` or that
 * an application password is a serialised array beside it.
 *
 * **Staff is defined by role, not by capability**, and the two would give
 * different answers. A capability granted directly to one account — by another
 * plugin, or by hand in `functions.php` — would make that account staff by the
 * capability test while remaining unfindable by `WP_User_Query`, which filters
 * on roles. One rule that the list and the single read both use is worth more
 * than a wider rule the list cannot honour, so: an account is staff when it
 * holds one of §45's seven roles, or `administrator`. `UserRoles::staff()` is
 * that list.
 */
final class UserRepository
{
    /** Sortable columns WP_User_Query understands and that mean something here. */
    public const ORDERBY = ['registered', 'ID', 'display_name', 'user_email', 'user_login'];

    /**
     * A staff account, or null.
     *
     * The role check is what stops this endpoint becoming a second reader of
     * customer records with a different permission model — `CustomerRepository`
     * makes the same check in the other direction, and between them every
     * WordPress user is readable through exactly one door.
     */
    public function find(int $id): ?WP_User
    {
        $user = $this->findAny($id);

        return $user !== null && self::isStaff($user) ? $user : null;
    }

    /**
     * Any user, staff or not.
     *
     * Used only by the promotion path: `PATCH /users/{id}` with a role assigns
     * one to an account that does not yet have one, and that account is by
     * definition not findable by `find()` until the write lands.
     */
    public function findAny(int $id): ?WP_User
    {
        if ($id <= 0) {
            return null;
        }

        $user = get_userdata($id);

        return $user instanceof WP_User ? $user : null;
    }

    public static function isStaff(WP_User $user): bool
    {
        return array_intersect(UserRoles::staff(), array_map('strval', (array) $user->roles)) !== [];
    }

    /**
     * @param array{page: int, per_page: int, search: string, role: string, status: string, orderby: string, order: string} $criteria
     * @return array{items: list<WP_User>, total: int}
     */
    public function paginate(array $criteria): array
    {
        $roles = UserRoles::staff();

        if ($criteria['role'] !== '' && in_array($criteria['role'], $roles, true)) {
            $roles = [$criteria['role']];
        }

        $args = [
            'role__in' => $roles,
            'number' => $criteria['per_page'],
            'paged' => $criteria['page'],
            'count_total' => true,
            'fields' => 'all',
            'orderby' => in_array($criteria['orderby'], self::ORDERBY, true) ? $criteria['orderby'] : 'registered',
            'order' => strtoupper($criteria['order']) === 'ASC' ? 'ASC' : 'DESC',
        ];

        if ($criteria['search'] !== '') {
            $args['search'] = '*' . $criteria['search'] . '*';
            $args['search_columns'] = ['user_login', 'user_email', 'user_nicename', 'display_name'];
        }

        /*
         * Status is user meta, and a meta_query is how it has to be filtered.
         * `NOT EXISTS` is the active half because the meta row is absent for an
         * active account — `UserStatus` stores only the suspension, so a
         * comparison against `'active'` would return nobody.
         */
        if ($criteria['status'] === UserStatus::SUSPENDED) {
            $args['meta_query'] = [[
                'key' => UserStatus::META_KEY,
                'value' => UserStatus::SUSPENDED,
                'compare' => '=',
            ]];
        } elseif ($criteria['status'] === UserStatus::ACTIVE) {
            $args['meta_query'] = [[
                'key' => UserStatus::META_KEY,
                'compare' => 'NOT EXISTS',
            ]];
        }

        $query = new WP_User_Query($args);

        $users = [];

        foreach ($query->get_results() as $user) {
            if ($user instanceof WP_User) {
                $users[] = $user;
            }
        }

        return ['items' => $users, 'total' => (int) $query->get_total()];
    }

    /**
     * `username_exists()` returns the id or **false**, and `email_exists()`
     * does the same — neither returns null, despite the docblock shape that
     * invites `!== null`. Written that way, this method reported every username
     * as taken and `POST /users` was a 409 for every payload; the suite's
     * positive control is what caught it, because the duplicate-username test
     * passed for the wrong reason.
     *
     * Casting to int is what makes both the false and the (filtered) null case
     * read the same way.
     */
    public function usernameExists(string $username): bool
    {
        return (int) username_exists($username) > 0;
    }

    public function emailExists(string $email, int $ignoreId = 0): bool
    {
        $existing = (int) email_exists($email);

        return $existing > 0 && $existing !== $ignoreId;
    }

    /**
     * Create the account.
     *
     * **The password is random and is never returned.** A staff account has no
     * password anybody knows, on purpose: the credential this API cares about
     * is an application password, minted separately and shown once. Nothing is
     * emailed either — `wp_new_user_notification()` is not called — because a
     * send on a request path is exactly what §59d refuses, and an SMTP server
     * that hangs would hang account creation.
     */
    public function create(UserInput $input): WP_User
    {
        $id = wp_insert_user([
            'user_login' => (string) $input->get('username'),
            'user_email' => (string) $input->get('email'),
            'user_pass' => wp_generate_password(32, true, true),
            'first_name' => (string) ($input->get('first_name') ?? ''),
            'last_name' => (string) ($input->get('last_name') ?? ''),
            'display_name' => (string) ($input->get('display_name') ?? ''),
            'role' => (string) $input->get('role'),
        ]);

        if ($id instanceof WP_Error) {
            throw self::fromWpError($id, 'The account could not be created.');
        }

        $user = $this->findAny((int) $id);

        if ($user === null) {
            throw ApiException::internal('The account was created but could not be read back.');
        }

        return $user;
    }

    /**
     * Apply an update.
     *
     * `set_role()` rather than `add_role()`: WordPress lets a user hold several
     * and this API models exactly one, so adding would leave an account
     * carrying both its old capabilities and its new ones — a demotion that
     * demotes nothing.
     */
    public function update(WP_User $user, UserInput $input): WP_User
    {
        $fields = ['ID' => (int) $user->ID];

        foreach (['email' => 'user_email', 'first_name' => 'first_name', 'last_name' => 'last_name', 'display_name' => 'display_name'] as $field => $column) {
            if ($input->has($field)) {
                $fields[$column] = (string) $input->get($field);
            }
        }

        if (count($fields) > 1) {
            $result = wp_update_user($fields);

            if ($result instanceof WP_Error) {
                throw self::fromWpError($result, 'The account could not be updated.');
            }
        }

        if ($input->has('role')) {
            $user->set_role((string) $input->get('role'));
        }

        if ($input->has('status')) {
            UserStatus::set((int) $user->ID, (string) $input->get('status'));
        }

        return $this->findAny((int) $user->ID) ?? $user;
    }

    /**
     * `wp_delete_user()` lives in wp-admin and is not loaded on a REST request,
     * so it is required here rather than assumed. Reassignment is deliberately
     * not offered: the service refuses to delete an account that owns orders,
     * which is the only content this API models.
     */
    public function delete(int $id): bool
    {
        require_once ABSPATH . 'wp-admin/includes/user.php';

        return wp_delete_user($id);
    }

    /** @return list<array<string, mixed>> */
    public function applicationPasswords(int $userId): array
    {
        return array_values(WP_Application_Passwords::get_user_application_passwords($userId));
    }

    /** @return array<string, mixed>|null */
    public function findApplicationPassword(int $userId, string $uuid): ?array
    {
        $item = WP_Application_Passwords::get_user_application_password($userId, $uuid);

        return is_array($item) ? $item : null;
    }

    /**
     * Mint one.
     *
     * @return array{0: string, 1: array<string, mixed>} the plaintext password
     *         and the stored record, in that order — WordPress's own shape
     */
    public function createApplicationPassword(int $userId, string $name): array
    {
        $created = WP_Application_Passwords::create_new_application_password($userId, ['name' => $name]);

        if ($created instanceof WP_Error) {
            throw self::fromWpError($created, 'The application password could not be created.');
        }

        return [(string) $created[0], (array) $created[1]];
    }

    public function deleteApplicationPassword(int $userId, string $uuid): bool
    {
        $deleted = WP_Application_Passwords::delete_application_password($userId, $uuid);

        return !($deleted instanceof WP_Error) && $deleted !== false;
    }

    /**
     * Whether this install can issue application passwords at all.
     *
     * `wp_is_application_passwords_supported()` and **not**
     * `wp_is_application_passwords_available()`, which is filtered: §60's
     * `RateLimitGuard` returns false from that filter for an address that has
     * spent its failure budget, and reading it here would answer a rate-limited
     * caller with "this install does not support application passwords" — a
     * true-sounding statement about the wrong thing.
     *
     * The underlying rule is `is_ssl() || wp_get_environment_type() === 'local'`,
     * which is why `WP_ENVIRONMENT_TYPE` matters in development and TLS matters
     * everywhere else.
     */
    public function canIssueApplicationPasswords(): bool
    {
        return function_exists('wp_is_application_passwords_supported')
            && wp_is_application_passwords_supported();
    }

    /**
     * Turn a WP_Error into our envelope without leaking WordPress's wording as
     * the error *code*, which clients would then match on.
     */
    private static function fromWpError(WP_Error $error, string $message): ApiException
    {
        $detail = $error->get_error_message();

        return ApiException::invalidRequest($message, [
            'fields' => ['user' => $detail === '' ? $message : wp_strip_all_tags($detail)],
        ]);
    }
}
