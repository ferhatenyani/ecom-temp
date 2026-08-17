<?php

declare(strict_types=1);

namespace AlgerianCommerce\Tests\Unit;

use AlgerianCommerce\Permissions\Capabilities;
use AlgerianCommerce\Users\UserRoles;
use PHPUnit\Framework\TestCase;

final class UserRolesTest extends TestCase
{
    public function testManagedRolesAreExactlyTheMatrix(): void
    {
        self::assertSame(Capabilities::roleKeys(), UserRoles::managed());
        self::assertCount(7, UserRoles::managed());
    }

    /**
     * An administrator holds every `ac_*` capability once `Roles::install()`
     * has run, so hiding them from the staff list would mean the account with
     * the most access is the one nobody can see.
     */
    public function testAdministratorCountsAsStaff(): void
    {
        self::assertContains('administrator', UserRoles::staff());
        self::assertContains('ac_super_admin', UserRoles::staff());
        self::assertNotContains('customer', UserRoles::staff());
    }

    /** An administrator is staff, but is not a role this API hands out. */
    public function testAdministratorIsNotAssignable(): void
    {
        self::assertFalse(UserRoles::isManaged('administrator'));
        self::assertStringContainsString(
            'commerce roles',
            (string) UserRoles::assignmentError('administrator')
        );
    }

    public function testCoreRolesAreRefusedWithTheirOwnMessage(): void
    {
        foreach (UserRoles::CORE_ROLES as $role) {
            $error = (string) UserRoles::assignmentError($role);

            self::assertStringContainsString('commerce roles', $error, $role);
            self::assertStringNotContainsString('Unknown role', $error, $role);
        }
    }

    public function testAnUnknownRoleListsTheAvailableOnes(): void
    {
        $error = (string) UserRoles::assignmentError('ac_wizard');

        self::assertStringContainsString('Unknown role', $error);

        foreach (UserRoles::managed() as $role) {
            self::assertStringContainsString($role, $error);
        }
    }

    public function testEveryManagedRoleIsAssignable(): void
    {
        foreach (UserRoles::managed() as $role) {
            self::assertNull(UserRoles::assignmentError($role), $role);
        }
    }

    public function testAnEmptyRolePointsAtCustomers(): void
    {
        self::assertStringContainsString('/customers', (string) UserRoles::assignmentError(''));
    }

    /**
     * The escalation rule. A caller holding everything can grant everything; a
     * caller holding nothing can grant nothing — and the gap between them is
     * reported by capability name, so the refusal can say what is missing.
     */
    public function testCapabilitiesBeyondAnOmnipotentCallerIsEmpty(): void
    {
        self::assertSame([], UserRoles::capabilitiesBeyond(
            Capabilities::SUPER_ADMIN,
            static fn (string $capability): bool => true
        ));
    }

    public function testCapabilitiesBeyondNamesWhatIsMissing(): void
    {
        // An Admin's capability set, which is everything except settings and
        // user management — §45's boundary between Admin and Super Admin.
        $adminHolds = Capabilities::forRole(Capabilities::ADMIN);

        $beyond = UserRoles::capabilitiesBeyond(
            Capabilities::SUPER_ADMIN,
            static fn (string $capability): bool => in_array($capability, $adminHolds, true)
        );

        self::assertContains(Capabilities::MANAGE_SETTINGS, $beyond);
        self::assertContains(Capabilities::MANAGE_USERS, $beyond);
        self::assertNotContains(Capabilities::MANAGE_ORDERS, $beyond);
    }

    /**
     * The control for the test above: the same caller granting a role at or
     * below their own is not an escalation. Without this, "returns a non-empty
     * list" would pass for an implementation that always did.
     */
    public function testARoleAtOrBelowTheCallerIsNotAnEscalation(): void
    {
        $adminHolds = Capabilities::forRole(Capabilities::ADMIN);

        foreach ([Capabilities::SUPPORT_AGENT, Capabilities::ORDER_MANAGER, Capabilities::PRODUCT_MANAGER] as $role) {
            self::assertSame([], UserRoles::capabilitiesBeyond(
                $role,
                static fn (string $capability): bool => in_array($capability, $adminHolds, true)
            ), $role);
        }
    }

    public function testLabelFallsBackToTheSlug(): void
    {
        self::assertSame('Support Agent', UserRoles::label(Capabilities::SUPPORT_AGENT));
        self::assertSame('administrator', UserRoles::label('administrator'));
    }
}
