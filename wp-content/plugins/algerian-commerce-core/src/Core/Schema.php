<?php

declare(strict_types=1);

namespace AlgerianCommerce\Core;

/**
 * The schema version, in one place.
 *
 * It used to be declared twice — once as `AlgerianCommerce\DB_VERSION` in the
 * plugin bootstrap and once, independently, in the unit test bootstrap. Tests
 * never load the plugin file, so the guard in MigrationPlanTest was asserting
 * against the *test* copy: bumping that one and forgetting the real constant
 * left a green suite and an install that silently skipped the new migration.
 *
 * Both now derive from here, so the two cannot disagree.
 *
 * **Bump this when adding a migration.** It must equal the highest numbered
 * file in migrations/, which MigrationPlanTest enforces against this value.
 */
final class Schema
{
    public const VERSION = 8;

    private function __construct()
    {
    }
}
