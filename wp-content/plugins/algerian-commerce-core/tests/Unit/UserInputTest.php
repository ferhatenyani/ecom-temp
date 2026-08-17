<?php

declare(strict_types=1);

namespace AlgerianCommerce\Tests\Unit;

use AlgerianCommerce\API\ApiException;
use AlgerianCommerce\Users\UserInput;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UserInputTest extends TestCase
{
    /**
     * @param array<string, mixed> $payload
     * @return array<string, string>
     */
    private function createErrors(array $payload): array
    {
        try {
            UserInput::forCreate($payload);
        } catch (ApiException $exception) {
            return $exception->details()['fields'] ?? [];
        }

        self::fail('Expected the payload to be rejected.');
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, string>
     */
    private function updateErrors(array $payload): array
    {
        try {
            UserInput::forUpdate($payload);
        } catch (ApiException $exception) {
            return $exception->details()['fields'] ?? [];
        }

        self::fail('Expected the payload to be rejected.');
    }

    public function testAcceptsAStaffAccount(): void
    {
        $input = UserInput::forCreate([
            'username' => ' karim ',
            'email' => 'karim@example.test',
            'role' => 'ac_order_manager',
            'first_name' => ' Karim ',
        ]);

        self::assertSame('karim', $input->get('username'));
        self::assertSame('karim@example.test', $input->get('email'));
        self::assertSame('ac_order_manager', $input->get('role'));
        self::assertSame('Karim', $input->get('first_name'));
    }

    /**
     * The boundary between this endpoint and `/customers`. An account created
     * with no role would be invisible to `GET /users` the moment it existed.
     */
    public function testCreateRequiresARole(): void
    {
        $errors = $this->createErrors([
            'username' => 'karim',
            'email' => 'karim@example.test',
        ]);

        self::assertArrayHasKey('role', $errors);
        self::assertStringContainsString('/customers', $errors['role']);
    }

    public function testCreateRequiresAUsernameAndEmail(): void
    {
        $errors = $this->createErrors(['role' => 'ac_support_agent']);

        self::assertArrayHasKey('username', $errors);
        self::assertArrayHasKey('email', $errors);
    }

    /**
     * `docs/API.md`: a 400 names every bad field, so a client can render the
     * whole form's errors in one pass.
     */
    public function testEveryBadFieldIsReportedAtOnce(): void
    {
        $errors = $this->createErrors([
            'username' => 'no',
            'email' => 'not-an-email',
            'role' => 'ac_wizard',
        ]);

        self::assertCount(3, $errors);
    }

    /**
     * The mirror image of `CustomerInputTest::privilegeFieldProvider()`. That
     * class refuses roles because it sits behind `ac_manage_customers`; this one
     * accepts a role and still refuses credentials, because a password chosen by
     * somebody else is one its owner cannot trust.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function refusedFieldProvider(): array
    {
        return [
            'password' => ['password', 'application-passwords'],
            'user_pass' => ['user_pass', 'application-passwords'],
            'capabilities' => ['capabilities', 'come from the role'],
            'roles' => ['roles', 'exactly one role'],
            'user_login' => ['user_login', 'identity'],
        ];
    }

    #[DataProvider('refusedFieldProvider')]
    public function testRefusesByNameWithAReason(string $field, string $needle): void
    {
        $errors = $this->createErrors([
            'username' => 'karim',
            'email' => 'karim@example.test',
            'role' => 'ac_support_agent',
            $field => 'anything',
        ]);

        self::assertArrayHasKey($field, $errors);
        self::assertNotSame('Unknown field.', $errors[$field], 'refused generically rather than by name');
        self::assertStringContainsString($needle, $errors[$field]);
    }

    public function testRefusesAnUnknownFieldGenerically(): void
    {
        $errors = $this->createErrors([
            'username' => 'karim',
            'email' => 'karim@example.test',
            'role' => 'ac_support_agent',
            'nickname' => 'k',
        ]);

        self::assertSame('Unknown field.', $errors['nickname']);
    }

    /**
     * Emitted on read, dropped on write, so `GET` → edit → `PATCH` round trips.
     * A field that is both published and refused turns an ordinary round trip
     * into a 400.
     */
    public function testDropsReadOnlyFieldsInsteadOfRefusingThem(): void
    {
        $input = UserInput::forUpdate([
            'id' => 12,
            'username' => 'karim',
            'role_name' => 'Order Manager',
            'is_administrator' => false,
            'date_created' => '2026-01-01T00:00:00+00:00',
            'application_passwords' => [],
            'display_name' => 'Karim B.',
        ]);

        self::assertSame(['display_name' => 'Karim B.'], $input->fields);
    }

    /** @return array<string, array{0: string}> */
    public static function coreRoleProvider(): array
    {
        return [
            'administrator' => ['administrator'],
            'editor' => ['editor'],
            'shop_manager' => ['shop_manager'],
            'customer' => ['customer'],
        ];
    }

    #[DataProvider('coreRoleProvider')]
    public function testRefusesCoreRoles(string $role): void
    {
        $errors = $this->updateErrors(['role' => $role]);

        self::assertArrayHasKey('role', $errors);
        self::assertStringContainsString('commerce roles', $errors['role']);
    }

    public function testAnUnknownRoleListsTheRealOnes(): void
    {
        $errors = $this->updateErrors(['role' => 'ac_wizard']);

        self::assertStringContainsString('ac_super_admin', $errors['role']);
        self::assertStringContainsString('ac_support_agent', $errors['role']);
    }

    /**
     * There is no way to send a role that removes one: the empty string is
     * refused with the same message a missing role gets, so "PATCH refuses to
     * remove the last role" has no payload that expresses it.
     */
    public function testAnEmptyRoleIsRefused(): void
    {
        $errors = $this->updateErrors(['role' => '']);

        self::assertArrayHasKey('role', $errors);
        self::assertStringContainsString('/customers', $errors['role']);
    }

    /** @return array<string, array{0: string}> */
    public static function badUsernameProvider(): array
    {
        return [
            'too short' => ['ab'],
            'slash' => ['a/b'],
            'angle bracket' => ['<script>'],
            'quote' => ["o'brien"],
            'percent' => ['ka%rim'],
        ];
    }

    #[DataProvider('badUsernameProvider')]
    public function testRefusesUsernamesOutsideTheVocabulary(string $username): void
    {
        $errors = $this->createErrors([
            'username' => $username,
            'email' => 'karim@example.test',
            'role' => 'ac_support_agent',
        ]);

        self::assertArrayHasKey('username', $errors);
    }

    public function testStatusIsAnEnum(): void
    {
        self::assertSame('suspended', UserInput::forUpdate(['status' => 'suspended'])->get('status'));
        self::assertArrayHasKey('status', $this->updateErrors(['status' => 'disabled']));
    }

    /** Status is a lifecycle change, not part of creating an account. */
    public function testStatusCannotBeSetAtCreation(): void
    {
        $errors = $this->createErrors([
            'username' => 'karim',
            'email' => 'karim@example.test',
            'role' => 'ac_support_agent',
            'status' => 'suspended',
        ]);

        self::assertSame('Unknown field.', $errors['status']);
    }

    public function testEmailIsNotEmptiable(): void
    {
        self::assertArrayHasKey('email', $this->updateErrors(['email' => '']));
        self::assertArrayHasKey('email', $this->updateErrors(['email' => null]));
    }

    public function testAnUpdateCanBeEmpty(): void
    {
        self::assertTrue(UserInput::forUpdate([])->isEmpty());
    }
}
