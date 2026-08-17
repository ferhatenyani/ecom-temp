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
            ];
        }

        return $roles;
    }
}
