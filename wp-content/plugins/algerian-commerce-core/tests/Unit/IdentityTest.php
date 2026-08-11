<?php

declare(strict_types=1);

namespace AlgerianCommerce\Tests\Unit;

use AlgerianCommerce\Auth\AuthService;
use AlgerianCommerce\Auth\Identity;
use AlgerianCommerce\Permissions\Capabilities;
use PHPUnit\Framework\TestCase;

final class IdentityTest extends TestCase
{
    /** @param array<string, bool>|list<string> $caps */
    private function identity(array $caps, array $roles = ['ac_admin']): Identity
    {
        return Identity::create(
            7,
            'ac_service',
            'Service Account',
            'svc@example.test',
            $roles,
            $caps,
            AuthService::METHOD_APPLICATION_PASSWORD
        );
    }

    public function testExposesTheCallerRecord(): void
    {
        $identity = $this->identity([Capabilities::MANAGE_PRODUCTS => true]);

        self::assertSame(7, $identity->id);
        self::assertSame('ac_service', $identity->username);
        self::assertSame('Service Account', $identity->displayName);
        self::assertSame(['ac_admin'], $identity->roles);
        self::assertSame(AuthService::METHOD_APPLICATION_PASSWORD, $identity->authMethod);
    }

    /**
     * A WordPress administrator carries dozens of core capabilities that say
     * nothing about commerce. Echoing them back hands any client a map of the
     * platform underneath.
     */
    public function testDropsCapabilitiesThatAreNotOurs(): void
    {
        $identity = $this->identity([
            'edit_themes' => true,
            'install_plugins' => true,
            'manage_options' => true,
            Capabilities::MANAGE_PRODUCTS => true,
        ]);

        self::assertSame([Capabilities::MANAGE_PRODUCTS], $identity->capabilities);
    }

    /**
     * WordPress's allcaps is a map, and `false` means explicitly denied.
     * Treating a present key as a grant is how a revoked capability returns.
     */
    public function testDeniedCapabilitiesAreNotReported(): void
    {
        $identity = $this->identity([
            Capabilities::MANAGE_PRODUCTS => true,
            Capabilities::MANAGE_ORDERS => false,
        ]);

        self::assertSame([Capabilities::MANAGE_PRODUCTS], $identity->capabilities);
        self::assertFalse($identity->can(Capabilities::MANAGE_ORDERS));
        self::assertTrue($identity->can(Capabilities::MANAGE_PRODUCTS));
    }

    public function testAcceptsAPlainListOfCapabilities(): void
    {
        $identity = $this->identity([Capabilities::MANAGE_INVENTORY, 'edit_posts']);

        self::assertSame([Capabilities::MANAGE_INVENTORY], $identity->capabilities);
    }

    public function testCapabilitiesAreSortedAndDeduplicated(): void
    {
        $identity = $this->identity([
            Capabilities::MANAGE_ORDERS => true,
            Capabilities::MANAGE_INVENTORY => true,
            Capabilities::MANAGE_CUSTOMERS => true,
        ]);

        $sorted = $identity->capabilities;
        sort($sorted);

        self::assertSame($sorted, $identity->capabilities, 'shape must be stable between requests');
        self::assertSame(array_unique($identity->capabilities), $identity->capabilities);
    }

    public function testAnAccountWithNoCommerceCapabilitiesReportsNone(): void
    {
        $identity = $this->identity(['read' => true, 'edit_posts' => true], ['subscriber']);

        self::assertSame([], $identity->capabilities);
    }

    public function testSuperAdminReportsEveryCapability(): void
    {
        $all = [];
        foreach (Capabilities::ALL as $capability) {
            $all[$capability] = true;
        }

        $identity = $this->identity($all, [Capabilities::SUPER_ADMIN]);

        self::assertCount(count(Capabilities::ALL), $identity->capabilities);
    }

    public function testWireShapeIsStable(): void
    {
        self::assertSame(
            ['id', 'username', 'display_name', 'email', 'roles', 'capabilities', 'auth_method'],
            array_keys($this->identity([])->toArray())
        );
    }

    /**
     * The uuid of the application password identifies a specific credential
     * and must not travel back to the client.
     *
     * Asserted against the key set rather than a substring search — the value
     * of `auth_method` is legitimately "application_password", so grepping the
     * encoded payload for "password" only tests the test.
     */
    public function testDoesNotLeakACredentialIdentifier(): void
    {
        $payload = $this->identity([Capabilities::MANAGE_PRODUCTS => true])->toArray();

        self::assertArrayNotHasKey('uuid', $payload);
        self::assertArrayNotHasKey('app_password_uuid', $payload);
        self::assertSame(
            ['id', 'username', 'display_name', 'email', 'roles', 'capabilities', 'auth_method'],
            array_keys($payload),
            'a new field reaching the wire needs a deliberate decision, not a silent addition'
        );
    }
}
