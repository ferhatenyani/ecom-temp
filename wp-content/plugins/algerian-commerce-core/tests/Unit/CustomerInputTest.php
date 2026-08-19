<?php

declare(strict_types=1);

namespace AlgerianCommerce\Tests\Unit;

use AlgerianCommerce\API\ApiException;
use AlgerianCommerce\Customers\CustomerInput;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CustomerInputTest extends TestCase
{
    /**
     * @param array<string, mixed> $payload
     * @return array<string, string>
     */
    private function fieldErrors(array $payload): array
    {
        try {
            CustomerInput::fromPayload($payload);
        } catch (ApiException $exception) {
            return $exception->details()['fields'] ?? [];
        }

        self::fail('Expected the payload to be rejected.');
    }

    public function testAcceptsTheProfileFields(): void
    {
        $input = CustomerInput::fromPayload([
            'first_name' => ' Amina ',
            'last_name' => 'Benali',
            'email' => 'amina@example.test',
        ]);

        self::assertSame('Amina', $input->get('first_name'));
        self::assertSame('Benali', $input->get('last_name'));
        self::assertSame('amina@example.test', $input->get('email'));
    }

    /**
     * The whole reason this class has a field list. ac_manage_customers is held
     * by Support Agent — the thinnest role — so a credential or role field
     * reaching WC_Customer would turn support into an escalation path.
     *
     * @return array<string, array{0: string}>
     */
    public static function privilegeFieldProvider(): array
    {
        return [
            'password' => ['password'],
            'user_pass' => ['user_pass'],
            'roles' => ['roles'],
            'capabilities' => ['capabilities'],
        ];
    }

    #[DataProvider('privilegeFieldProvider')]
    public function testCredentialAndRoleFieldsAreRefusedByName(string $field): void
    {
        $errors = $this->fieldErrors([$field => 'administrator']);

        self::assertArrayHasKey($field, $errors);
        self::assertNotSame('Unknown field.', $errors[$field], 'the refusal should say where the boundary is');
    }

    /**
     * The consent flag is the one refused field a client reaches by accident.
     *
     * Everything else in `REFUSED` appears in no response, so it is only ever typed
     * on purpose. `marketing_consent` **is** emitted, so a client that GETs a
     * customer, edits a name and PATCHes the object back arrives at it — and it was
     * answering "Unknown field.", which is what a misspelling gets. A panel
     * developer reading that concluded the field did not exist; it exists and
     * belongs to somebody else.
     *
     * Refused rather than dropped, unlike `role`, and the difference is the point:
     * a silently ignored consent write leaves a shop believing it recorded consent
     * it does not have.
     */
    public function testMarketingConsentIsRefusedByNameAndSaysWhose(): void
    {
        $errors = $this->fieldErrors(['marketing_consent' => true]);

        self::assertArrayHasKey('marketing_consent', $errors);
        self::assertNotSame('Unknown field.', $errors['marketing_consent']);
        self::assertStringContainsString(
            '/account/marketing-consent',
            $errors['marketing_consent'],
            'the refusal names the route that can set it'
        );
    }

    /**
     * The date and the source are derived from the decision, not decisions
     * themselves, so they drop the way `role` drops — a client PATCHing back a body
     * it read must not have to strip them.
     */
    public function testTheConsentRecordDropsRatherThanRefusing(): void
    {
        $input = CustomerInput::fromPayload([
            'marketing_consent_at' => '2026-03-03T09:00:00+00:00',
            'marketing_consent_source' => 'account',
            'first_name' => 'Lila',
        ]);

        self::assertSame(['first_name' => 'Lila'], $input->fields);
    }

    /**
     * `role` is emitted by the presenter, so it is dropped rather than refused
     * — and dropping is what makes it safe. A dropped field is never applied.
     */
    public function testRoleIsDroppedNotApplied(): void
    {
        $input = CustomerInput::fromPayload(['role' => 'administrator', 'first_name' => 'Amina']);

        self::assertFalse($input->has('role'));
        self::assertSame(['first_name' => 'Amina'], $input->fields);
    }

    public function testTheWholeGetBodyRoundTrips(): void
    {
        $input = CustomerInput::fromPayload([
            'id' => 11,
            'username' => 'amina',
            'role' => 'customer',
            'is_paying_customer' => true,
            'date_created' => '2026-01-01T00:00:00+00:00',
            'date_modified' => '2026-02-01T00:00:00+00:00',
            'orders_count' => 4,
            'statistics' => ['total_orders' => 4],
            'email' => 'amina@example.test',
        ]);

        self::assertSame(['email' => 'amina@example.test'], $input->fields);
    }

    public function testUnknownFieldsAreRejected(): void
    {
        self::assertSame(['wilaya' => 'Unknown field.'], $this->fieldErrors(['wilaya' => 16]));
    }

    public function testEmailMustBeValid(): void
    {
        self::assertArrayHasKey('email', $this->fieldErrors(['email' => 'not-an-email']));
    }

    /**
     * A user without an email cannot be sent a password reset or an order
     * notification — the account becomes unrecoverable.
     */
    public function testEmailCannotBeEmptied(): void
    {
        self::assertArrayHasKey('email', $this->fieldErrors(['email' => '']));
        self::assertArrayHasKey('email', $this->fieldErrors(['email' => null]));
    }

    public function testNamesCanBeClearedButNotOverlong(): void
    {
        self::assertSame('', CustomerInput::fromPayload(['first_name' => null])->get('first_name'));
        self::assertArrayHasKey('last_name', $this->fieldErrors(['last_name' => str_repeat('x', 201)]));
    }

    public function testAddressesAreValidatedAndPrefixed(): void
    {
        $errors = $this->fieldErrors([
            'billing' => ['country' => 'Algeria'],
            'shipping' => ['email' => 'someone@example.test'],
        ]);

        self::assertArrayHasKey('billing.country', $errors);
        self::assertArrayHasKey('shipping.email', $errors);
    }

    public function testAValidAddressSurvives(): void
    {
        $input = CustomerInput::fromPayload([
            'billing' => ['city' => 'Alger', 'country' => 'dz', 'phone' => '0550123456'],
        ]);

        self::assertSame('DZ', $input->get('billing')->fields['country']);
    }

    public function testAnEmptyPayloadIsEmptyRatherThanAnError(): void
    {
        // The service turns this into "No supported fields were provided.",
        // which is a clearer message than a field-level complaint.
        self::assertTrue(CustomerInput::fromPayload([])->isEmpty());
    }
}
