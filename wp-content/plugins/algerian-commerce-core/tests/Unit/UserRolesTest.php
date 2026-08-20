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

        // The roles it offers are the ones that can actually be granted, not
        // every role the API recognises — half of which would 400 if chosen.
        foreach (UserRoles::assignable() as $role) {
            self::assertStringContainsString($role, $error);
        }
    }

    public function testEveryAssignableRoleIsAssignable(): void
    {
        foreach (UserRoles::assignable() as $role) {
            self::assertNull(UserRoles::assignmentError($role), $role);
        }
    }

    /**
     * The two-tier model, as a fact rather than as a convention.
     *
     * `ac_manager` is the Assistant tier verbatim — the capability set the shop
     * already ran on — which is why the collapse adds no matrix entry.
     */
    public function testExactlyTwoRolesAreAssignable(): void
    {
        self::assertSame(
            [Capabilities::SUPER_ADMIN, Capabilities::MANAGER],
            UserRoles::assignable()
        );
    }

    /**
     * The distinction the collapse rests on. Narrowing `managed()` alongside
     * `assignable()` would make every read path — `UserPresenter::role()`,
     * `UserService::currentRole()` — stop recognising the role an account
     * visibly holds, and a Support Agent would present as having none.
     */
    public function testRetiredRolesStayRecognisedEvenThoughTheyAreNotGranted(): void
    {
        self::assertCount(7, UserRoles::managed());
        self::assertCount(5, UserRoles::retired());

        foreach (UserRoles::retired() as $role) {
            self::assertTrue(UserRoles::isManaged($role), $role);
            self::assertFalse(UserRoles::isAssignable($role), $role);
        }

        self::assertNotContains(Capabilities::SUPER_ADMIN, UserRoles::retired());
        self::assertNotContains(Capabilities::MANAGER, UserRoles::retired());
    }

    /**
     * A retired role is not an unknown one, and must not be reported as though
     * it were — the account in front of the operator is still holding it.
     */
    public function testARetiredRoleIsRefusedByNameRatherThanAsUnknown(): void
    {
        $error = (string) UserRoles::assignmentError(Capabilities::SUPPORT_AGENT);

        self::assertStringContainsString('retired', $error);
        self::assertStringNotContainsString('Unknown role', $error);

        // And it says what to choose instead.
        self::assertStringContainsString(Capabilities::MANAGER, $error);
    }

    /**
     * Positive control for the test above: the three refusal arms are distinct,
     * so "contains the word retired" cannot pass by matching every message.
     */
    public function testTheThreeRefusalsAreDistinguishable(): void
    {
        $retired = (string) UserRoles::assignmentError(Capabilities::MARKETING_MANAGER);
        $core = (string) UserRoles::assignmentError('administrator');
        $unknown = (string) UserRoles::assignmentError('ac_wizard');

        self::assertStringContainsString('retired', $retired);
        self::assertStringNotContainsString('retired', $core);
        self::assertStringNotContainsString('retired', $unknown);

        self::assertStringContainsString('commerce roles', $core);
        self::assertStringNotContainsString('commerce roles', $retired);

        self::assertStringContainsString('Unknown role', $unknown);
        self::assertStringNotContainsString('Unknown role', $retired);
    }

    /**
     * Retired roles keep their place in the staff list. An account that dropped
     * out of `/users` because its role was retired would be an account with live
     * access and no way to see it — the same argument that keeps
     * `administrator` on the list.
     */
    public function testRetiredRolesStillCountAsStaff(): void
    {
        foreach (UserRoles::retired() as $role) {
            self::assertContains($role, UserRoles::staff(), $role);
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
