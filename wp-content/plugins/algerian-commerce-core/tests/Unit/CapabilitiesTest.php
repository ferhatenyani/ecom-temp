<?php

declare(strict_types=1);

namespace AlgerianCommerce\Tests\Unit;

use AlgerianCommerce\Permissions\Capabilities;
use PHPUnit\Framework\TestCase;

final class CapabilitiesTest extends TestCase
{
    public function testDefinesEveryCapabilityFromThePlan(): void
    {
        // docs/PLAN.md §3 — the suffixes must match the specification.
        $expected = [
            'manage_products', 'manage_inventory', 'manage_orders', 'manage_customers',
            'manage_coupons', 'manage_content', 'manage_marketing', 'view_analytics',
            'manage_shipping', 'manage_payments', 'manage_settings', 'manage_users',
            'view_audit_logs',
        ];

        $actual = array_map(
            static fn (string $cap): string => substr($cap, strlen(Capabilities::PREFIX)),
            Capabilities::ALL
        );

        self::assertSame($expected, $actual);
        self::assertCount(13, Capabilities::ALL);
    }

    public function testEveryCapabilityIsPrefixed(): void
    {
        // Unprefixed capabilities share a global namespace with core and
        // WooCommerce, where a collision silently grants access.
        foreach (Capabilities::ALL as $capability) {
            self::assertStringStartsWith(Capabilities::PREFIX, $capability);
        }
    }

    public function testCapabilityListHasNoDuplicates(): void
    {
        self::assertSame(Capabilities::ALL, array_values(array_unique(Capabilities::ALL)));
    }

    public function testDefinesTheSevenRolesFromThePlan(): void
    {
        $names = array_column(Capabilities::roles(), 'name');

        self::assertSame([
            'Super Admin', 'Admin', 'Manager', 'Product Manager',
            'Order Manager', 'Marketing Manager', 'Support Agent',
        ], $names);
    }

    public function testEveryRoleGrantsOnlyKnownCapabilities(): void
    {
        foreach (Capabilities::roles() as $role => $definition) {
            foreach ($definition['capabilities'] as $capability) {
                self::assertTrue(
                    Capabilities::isKnownCapability($capability),
                    "{$role} grants unknown capability {$capability}"
                );
            }
        }
    }

    public function testNoRoleGrantsTheSameCapabilityTwice(): void
    {
        foreach (Capabilities::roles() as $role => $definition) {
            $caps = $definition['capabilities'];
            self::assertSame(count($caps), count(array_unique($caps)), "{$role} has duplicates");
        }
    }

    public function testSuperAdminHoldsEveryCapability(): void
    {
        self::assertSame(Capabilities::ALL, Capabilities::forRole(Capabilities::SUPER_ADMIN));
    }

    public function testOnlySuperAdminManagesUsersAndSettings(): void
    {
        // The privilege-escalation boundary: an Admin must not be able to
        // grant itself more, or reconfigure the platform.
        foreach (Capabilities::roleKeys() as $role) {
            if ($role === Capabilities::SUPER_ADMIN) {
                continue;
            }

            self::assertFalse(
                Capabilities::roleHas($role, Capabilities::MANAGE_USERS),
                "{$role} must not manage users"
            );
            self::assertFalse(
                Capabilities::roleHas($role, Capabilities::MANAGE_SETTINGS),
                "{$role} must not manage settings"
            );
        }
    }

    public function testAuditLogsAreVisibleOnlyToSuperAdminAndAdmin(): void
    {
        $allowed = [Capabilities::SUPER_ADMIN, Capabilities::ADMIN];

        foreach (Capabilities::roleKeys() as $role) {
            self::assertSame(
                in_array($role, $allowed, true),
                Capabilities::roleHas($role, Capabilities::VIEW_AUDIT_LOGS),
                "unexpected audit-log access for {$role}"
            );
        }
    }

    public function testLeastPrivilegeForNarrowRoles(): void
    {
        self::assertFalse(Capabilities::roleHas(Capabilities::PRODUCT_MANAGER, Capabilities::MANAGE_ORDERS));
        self::assertFalse(Capabilities::roleHas(Capabilities::ORDER_MANAGER, Capabilities::MANAGE_PRODUCTS));
        self::assertFalse(Capabilities::roleHas(Capabilities::MARKETING_MANAGER, Capabilities::MANAGE_ORDERS));
        self::assertFalse(Capabilities::roleHas(Capabilities::SUPPORT_AGENT, Capabilities::MANAGE_PRODUCTS));
        self::assertFalse(Capabilities::roleHas(Capabilities::SUPPORT_AGENT, Capabilities::MANAGE_PAYMENTS));
    }

    public function testNobodyButSuperAdminAndAdminTouchesPayments(): void
    {
        foreach (Capabilities::roleKeys() as $role) {
            if (in_array($role, [Capabilities::SUPER_ADMIN, Capabilities::ADMIN], true)) {
                continue;
            }

            self::assertFalse(Capabilities::roleHas($role, Capabilities::MANAGE_PAYMENTS));
        }
    }

    public function testEveryRoleCanSeeAnalytics(): void
    {
        foreach (Capabilities::roleKeys() as $role) {
            self::assertTrue(Capabilities::roleHas($role, Capabilities::VIEW_ANALYTICS));
        }
    }

    public function testUnknownRolesAndCapabilities(): void
    {
        self::assertFalse(Capabilities::isKnownRole('administrator'));
        self::assertFalse(Capabilities::isKnownCapability('manage_products'), 'unprefixed is not ours');
        self::assertFalse(Capabilities::isKnownCapability('ac_delete_everything'));
        self::assertSame([], Capabilities::forRole('nope'));
    }
}
