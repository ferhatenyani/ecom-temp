<?php

declare(strict_types=1);

namespace AlgerianCommerce\Tests\Unit;

use AlgerianCommerce\Account\AccountInput;
use AlgerianCommerce\API\ApiException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Registration and password rules — roadmap §59c.
 *
 * The escalation half is the one that matters: a registration endpoint is the
 * only place an anonymous caller creates a WordPress user, so a `role` it
 * honoured would be a public administrator factory.
 */
final class AccountInputTest extends TestCase
{
    public function testAcceptsARegistration(): void
    {
        $input = AccountInput::forRegistration([
            'email' => 'amina@example.test',
            'password' => 'CorrectHorseBattery',
            'first_name' => 'Amina',
            'last_name' => 'B',
        ]);

        self::assertSame('amina@example.test', $input->email);
        self::assertSame('Amina', $input->firstName);
    }

    /** @return array<string, array{0: string}> */
    public static function escalationFieldProvider(): array
    {
        return [
            'role' => ['role'],
            'roles' => ['roles'],
            'capabilities' => ['capabilities'],
            'user_pass' => ['user_pass'],
            'id' => ['id'],
            'user_login' => ['user_login'],
        ];
    }

    #[DataProvider('escalationFieldProvider')]
    public function testEscalationFieldsAreRefusedByName(string $field): void
    {
        try {
            AccountInput::forRegistration([
                'email' => 'a@example.test',
                'password' => 'CorrectHorseBattery',
                $field => 'administrator',
            ]);
            self::fail("{$field} was accepted");
        } catch (ApiException $e) {
            $fields = $e->details()['fields'] ?? [];
            self::assertArrayHasKey($field, $fields);
            self::assertNotSame(
                'Unknown field.',
                $fields[$field],
                'it should say why, so an attempted escalation is legible in a log'
            );
        }
    }

    public function testUnknownFieldsAreRejected(): void
    {
        $this->expectException(ApiException::class);

        AccountInput::forRegistration([
            'email' => 'a@example.test',
            'password' => 'CorrectHorseBattery',
            'nonsense' => 1,
        ]);
    }

    /** @return array<string, array{0: mixed}> */
    public static function badEmailProvider(): array
    {
        return [
            'empty' => [''],
            'no at' => ['nope'],
            'no domain' => ['a@'],
            'spaces' => ['a b@example.test'],
            'an array' => [['a@example.test']],
            'null' => [null],
        ];
    }

    #[DataProvider('badEmailProvider')]
    public function testEmailMustBeValid(mixed $email): void
    {
        $this->expectException(ApiException::class);

        AccountInput::forRegistration(['email' => $email, 'password' => 'CorrectHorseBattery']);
    }

    public function testPasswordHasAFloor(): void
    {
        $this->expectException(ApiException::class);

        AccountInput::forRegistration([
            'email' => 'a@example.test',
            'password' => str_repeat('a', AccountInput::MIN_PASSWORD - 1),
        ]);
    }

    public function testPasswordAtTheFloorIsAccepted(): void
    {
        $input = AccountInput::forRegistration([
            'email' => 'a@example.test',
            'password' => str_repeat('a', AccountInput::MIN_PASSWORD),
        ]);

        self::assertSame(AccountInput::MIN_PASSWORD, strlen($input->password));
    }

    public function testPasswordHasACeiling(): void
    {
        $this->expectException(ApiException::class);

        AccountInput::forRegistration([
            'email' => 'a@example.test',
            'password' => str_repeat('a', AccountInput::MAX_PASSWORD + 1),
        ]);
    }

    /**
     * No composition rules on purpose — NIST SP 800-63B. A passphrase of
     * ordinary words is the thing that should pass most easily.
     */
    public function testAPassphraseWithNoSymbolsIsFine(): void
    {
        $input = AccountInput::forRegistration([
            'email' => 'a@example.test',
            'password' => 'correct horse battery staple',
        ]);

        self::assertSame('correct horse battery staple', $input->password);
    }

    public function testPasswordChangeNeedsBoth(): void
    {
        $this->expectException(ApiException::class);

        AccountInput::forPasswordChange(['new_password' => 'CorrectHorseBattery']);
    }

    /**
     * A "change" to the same value leaves every other session valid while the
     * shopper believes they have just revoked them.
     */
    public function testANewPasswordMustDiffer(): void
    {
        try {
            AccountInput::forPasswordChange([
                'current_password' => 'CorrectHorseBattery',
                'new_password' => 'CorrectHorseBattery',
            ]);
            self::fail('accepted');
        } catch (ApiException $e) {
            self::assertArrayHasKey('new_password', $e->details()['fields'] ?? []);
        }
    }

    public function testAValidPasswordChange(): void
    {
        $result = AccountInput::forPasswordChange([
            'current_password' => 'OldPassphraseHere',
            'new_password' => 'NewPassphraseHere',
        ]);

        self::assertSame(['current' => 'OldPassphraseHere', 'new' => 'NewPassphraseHere'], $result);
    }

    public function testEveryBadFieldIsReportedAtOnce(): void
    {
        try {
            AccountInput::forRegistration(['email' => 'nope', 'password' => 'short', 'role' => 'administrator']);
            self::fail('accepted');
        } catch (ApiException $e) {
            self::assertSame(
                ['role', 'email', 'password'],
                array_keys($e->details()['fields'] ?? [])
            );
        }
    }
}
