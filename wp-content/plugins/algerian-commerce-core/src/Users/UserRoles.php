<?php

declare(strict_types=1);

namespace AlgerianCommerce\Users;

use AlgerianCommerce\Permissions\Capabilities;

/**
 * Which roles this API manages, and which it refuses to hand out.
 *
 * Pure — no WordPress — so the rules that decide whether an assignment is an
 * escalation are unit-testable on their own, which is the whole reason §45
 * put the capability matrix in a pure class to begin with.
 *
 * **A role is never created here.** `Capabilities::roles()` is the matrix, it is
 * unit-tested, and `Roles::install()` writes it into WordPress. A role invented
 * at runtime and stored in the options table would be a capability set no test
 * enumerates and no review has seen — §63's argument against a rollup table and
 * §68's against a version table, applied to authorization.
 */
final class UserRoles
{
    /**
     * WordPress's own roles, refused with their own message.
     *
     * `administrator` is the one that matters: it installs plugins, edits files
     * and reads every table, none of which is commerce. The rest are listed so
     * the refusal reads as a boundary rather than as a typo, because
     * "editor" is a plausible thing for somebody to try.
     */
    public const CORE_ROLES = [
        'administrator',
        'editor',
        'author',
        'contributor',
        'subscriber',
        // WooCommerce's own two. `shop_manager` is the closest thing core
        // commerce has to `ac_manager`, which is exactly why naming it here
        // matters: it carries WooCommerce capabilities this matrix does not
        // model, so granting it would put a staff account outside §45.
        'shop_manager',
        'customer',
    ];

    /** The roles this API assigns — §45's seven, and nothing else. */
    public static function managed(): array
    {
        return Capabilities::roleKeys();
    }

    /**
     * The roles that make an account *staff* for the purposes of `/users`.
     *
     * A WordPress administrator is included because `Roles::install()` grants
     * them every `ac_*` capability, so they can already do everything this API
     * permits; hiding them from the staff list would mean the one account with
     * the most access is the one nobody can see.
     */
    public static function staff(): array
    {
        return [...self::managed(), 'administrator'];
    }

    public static function isManaged(string $role): bool
    {
        return in_array($role, self::managed(), true);
    }

    /**
     * Why this role may not be assigned, or null when it may.
     *
     * Vocabulary only. Whether the *caller* is allowed to grant it is
     * `capabilitiesBeyond()`, because that needs to know who is asking and this
     * class deliberately does not.
     */
    public static function assignmentError(string $role): ?string
    {
        if ($role === '') {
            return 'A role is required. An account with no role is a customer, and customers are managed at /customers.';
        }

        if (self::isManaged($role)) {
            return null;
        }

        if (in_array($role, self::CORE_ROLES, true)) {
            return sprintf(
                'This API manages commerce roles and does not grant "%s". A WordPress role carries platform access — installing plugins, editing files — that no capability in this matrix models.',
                $role
            );
        }

        return sprintf('Unknown role "%s". Choose one of: %s.', $role, implode(', ', self::managed()));
    }

    /**
     * The capabilities a role holds that the caller does not.
     *
     * A non-empty result is an escalation: the caller would be creating an
     * account able to do something they cannot, which is how a compromised
     * account of one privilege becomes an account of another.
     *
     * Empty today for every legitimate caller, because `ac_manage_users` is
     * Super Admin's and Super Admin holds `Capabilities::ALL`. That is the
     * point — the rule exists so that the eighth role, or a capability granted
     * to one account by hand, cannot open a path nobody thought to re-check.
     *
     * @param callable(string): bool $callerHas
     * @return list<string>
     */
    public static function capabilitiesBeyond(string $role, callable $callerHas): array
    {
        $beyond = [];

        foreach (Capabilities::forRole($role) as $capability) {
            if (!$callerHas($capability)) {
                $beyond[] = $capability;
            }
        }

        return $beyond;
    }

    /** The display name for a managed role, or the slug when it is not one. */
    public static function label(string $role): string
    {
        return Capabilities::roles()[$role]['name'] ?? $role;
    }
}
