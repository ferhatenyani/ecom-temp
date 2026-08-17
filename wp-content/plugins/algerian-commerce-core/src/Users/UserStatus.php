<?php

declare(strict_types=1);

namespace AlgerianCommerce\Users;

/**
 * Whether a staff account may still authenticate — one user meta key.
 *
 * Suspension exists because deletion is refused for any account that owns
 * orders, and "this person has left" is the operation a shop actually needs on
 * the day somebody leaves. It is enforced in `SuspensionGuard`, not here.
 *
 * **Absence means active**, which is the opposite of §85's consent flag and for
 * the opposite reason. Consent is a permission somebody has to give, so silence
 * has to read as a no. Suspension is a permission being taken away, and every
 * staff account that existed before this shipped never had it taken — a default
 * of `suspended` would lock the shop out of its own API on the deploy that
 * added the feature.
 *
 * Reactivating **deletes** the row rather than writing `'active'`, which is
 * §85's argument for how a withdrawn consent is stored: a stored value invites
 * a later reader to compare it loosely, and there is no loose comparison that
 * can go wrong against a key that is not there.
 */
final class UserStatus
{
    public const META_KEY = '_ac_user_status';

    public const ACTIVE = 'active';
    public const SUSPENDED = 'suspended';

    public const ALL = [self::ACTIVE, self::SUSPENDED];

    public static function of(int $userId): string
    {
        return get_user_meta($userId, self::META_KEY, true) === self::SUSPENDED
            ? self::SUSPENDED
            : self::ACTIVE;
    }

    public static function isSuspended(int $userId): bool
    {
        return self::of($userId) === self::SUSPENDED;
    }

    public static function set(int $userId, string $status): void
    {
        if ($status === self::SUSPENDED) {
            update_user_meta($userId, self::META_KEY, self::SUSPENDED);

            return;
        }

        delete_user_meta($userId, self::META_KEY);
    }
}
