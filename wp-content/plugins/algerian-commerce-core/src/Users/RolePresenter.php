<?php

declare(strict_types=1);

namespace AlgerianCommerce\Users;

use AlgerianCommerce\Permissions\Capabilities;

/**
 * Publishes §45's capability matrix.
 *
 * Read straight out of `Capabilities::roles()` on every request rather than
 * from the roles WordPress has stored, and the difference matters: the matrix
 * is the source, `Roles::install()` is the copy, and a shop whose install has
 * not re-synced after a matrix change would otherwise be told about a role that
 * no longer exists as described. `Roles::isInstalled()` is what detects that
 * gap; this endpoint reports the intent.
 *
 * There is no write. See `UserRoles` for why.
 *
 * **Every recognised role is published, including the retired ones**, each
 * carrying whether it may still be assigned. Publishing only the two assignable
 * roles would be the tidier payload and the wrong one: accounts still hold the
 * other five, and a client that cannot look up `ac_support_agent` here has no
 * way to render the role an account visibly has. A picker filters on
 * `assignable`; a label reads the whole list.
 */
final class RolePresenter
{
    /** @return list<array<string, mixed>> */
    public static function all(): array
    {
        $roles = [];

        foreach (Capabilities::roles() as $key => $definition) {
            $roles[] = [
                'role' => $key,
                'name' => $definition['name'],
                'capabilities' => array_values($definition['capabilities']),
                /*
                 * The two-tier split, as a fact about each role rather than as a
                 * shape of the list. A client that ignores this field sees
                 * exactly what it saw before, which is what keeps the change
                 * additive for anything already reading this route.
                 */
                'assignable' => UserRoles::isAssignable($key),
            ];
        }

        return $roles;
    }
}
