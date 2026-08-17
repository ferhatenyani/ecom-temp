<?php

declare(strict_types=1);

namespace AlgerianCommerce\Users;

use WP_User;

/**
 * Shapes a WP_User for the API.
 *
 * **No capability map, and that is deliberate even here.** `CustomerPresenter`
 * omits it because Support Agent reads customers; this endpoint is Super
 * Admin's, so the argument is different but lands in the same place: the role
 * is the fact, `GET /roles` publishes what each role holds, and a capability
 * list copied onto every user row is a second copy of the matrix that drifts
 * the first time somebody edits `Capabilities`. `GET /auth/me` remains the only
 * route that reports capabilities, and only for the caller's own account.
 *
 * Nothing from the user object beyond identity, role and status: no password
 * hash, no session tokens, no `user_activation_key`, no application-password
 * hashes.
 */
final class UserPresenter
{
    /**
     * @param list<array<string, mixed>>|null $applicationPasswords omitted from
     *        list rows, where reading them per user would be a meta read a page
     *        of twenty does not need — the `CustomerPresenter` statistics rule
     * @return array<string, mixed>
     */
    public static function toArray(WP_User $user, ?array $applicationPasswords = null): array
    {
        $role = self::role($user);

        $payload = [
            'id' => (int) $user->ID,
            'username' => (string) $user->user_login,
            'email' => (string) $user->user_email,
            'first_name' => (string) $user->first_name,
            'last_name' => (string) $user->last_name,
            'display_name' => (string) $user->display_name,
            'role' => $role,
            'role_name' => UserRoles::label($role),
            /*
             * A WordPress administrator is staff here — Roles::install() grants
             * them every ac_* capability — but their role is not one this API
             * assigns, so a client that renders a role picker needs to know not
             * to offer one. Flagged rather than hidden: the account with the
             * most access is the worst one to leave off the list.
             */
            'is_administrator' => in_array('administrator', (array) $user->roles, true),
            'status' => UserStatus::of((int) $user->ID),
            'date_created' => self::date((string) $user->user_registered),
        ];

        if ($applicationPasswords !== null) {
            $payload['application_passwords'] = $applicationPasswords;
        }

        return $payload;
    }

    /**
     * @param list<WP_User> $users
     * @return list<array<string, mixed>>
     */
    public static function toArrayList(array $users): array
    {
        return array_values(array_map(
            static fn (WP_User $user): array => self::toArray($user),
            $users
        ));
    }

    /**
     * The one role this API recognises for the account.
     *
     * WordPress stores roles as a list and an account can hold several. This
     * API assigns exactly one, so a managed role wins over anything else the
     * account happens to carry, and `administrator` is reported when there is
     * no managed role to report — which is what a site owner's own account
     * looks like on a fresh install.
     */
    private static function role(WP_User $user): string
    {
        $roles = array_values(array_map('strval', (array) $user->roles));

        foreach ($roles as $role) {
            if (UserRoles::isManaged($role)) {
                return $role;
            }
        }

        return $roles[0] ?? '';
    }

    private static function date(string $registered): ?string
    {
        if ($registered === '' || $registered === '0000-00-00 00:00:00') {
            return null;
        }

        $timestamp = strtotime($registered . ' UTC');

        return $timestamp === false ? null : gmdate('c', $timestamp);
    }
}
