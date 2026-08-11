<?php

declare(strict_types=1);

namespace AlgerianCommerce\Tests\Unit;

use AlgerianCommerce\API\ApiException;
use AlgerianCommerce\Orders\OrderNoteInput;
use PHPUnit\Framework\TestCase;

final class OrderNoteInputTest extends TestCase
{
    /**
     * @param array<string, mixed> $payload
     * @return array<string, string>
     */
    private function fieldErrors(array $payload): array
    {
        try {
            OrderNoteInput::fromPayload($payload);
        } catch (ApiException $exception) {
            return $exception->details()['fields'] ?? [];
        }

        self::fail('Expected the note to be rejected.');
    }

    public function testAnInternalNote(): void
    {
        $note = OrderNoteInput::fromPayload(['note' => '  Customer called back  ']);

        self::assertSame('Customer called back', $note->note);
        self::assertFalse($note->customerNote);
    }

    /**
     * The default is the security-relevant part: WooCommerce emails a customer
     * note to the buyer, so silence has to mean internal.
     */
    public function testAnAbsentFlagMeansInternal(): void
    {
        self::assertFalse(OrderNoteInput::fromPayload(['note' => 'x'])->customerNote);
    }

    public function testACustomerNoteIsOptedIntoExplicitly(): void
    {
        self::assertTrue(OrderNoteInput::fromPayload(['note' => 'x', 'customer_note' => true])->customerNote);
    }

    /**
     * No loose casting. The string "false" is truthy in PHP, and coercing it
     * would email an internal remark to the customer.
     */
    public function testTheFlagIsNotCoercedFromStringsOrNumbers(): void
    {
        foreach (['false', 'true', 1, 0, 'yes', null] as $value) {
            self::assertArrayHasKey(
                'customer_note',
                $this->fieldErrors(['note' => 'x', 'customer_note' => $value]),
                var_export($value, true) . ' should not be accepted as a boolean'
            );
        }
    }

    public function testANoteIsRequired(): void
    {
        self::assertArrayHasKey('note', $this->fieldErrors([]));
    }

    public function testAnEmptyOrWhitespaceNoteIsRejected(): void
    {
        self::assertArrayHasKey('note', $this->fieldErrors(['note' => '']));
        self::assertArrayHasKey('note', $this->fieldErrors(['note' => "   \n  "]));
    }

    public function testAnOverLongNoteIsRejected(): void
    {
        $errors = $this->fieldErrors(['note' => str_repeat('x', OrderNoteInput::MAX_LENGTH + 1)]);

        self::assertArrayHasKey('note', $errors);
    }

    public function testTheMaximumLengthItselfIsAccepted(): void
    {
        $note = OrderNoteInput::fromPayload(['note' => str_repeat('x', OrderNoteInput::MAX_LENGTH)]);

        self::assertSame(OrderNoteInput::MAX_LENGTH, mb_strlen($note->note));
    }

    public function testUnknownFieldsAreRejected(): void
    {
        self::assertSame(['autor' => 'Unknown field.'], $this->fieldErrors(['note' => 'x', 'autor' => 'me']));
    }

    /** GET a note, resend it, and the emitted fields must not be errors. */
    public function testEmittedFieldsAreDroppedSoANoteRoundTrips(): void
    {
        $note = OrderNoteInput::fromPayload([
            'id' => 12,
            'added_by' => 'amina',
            'date_created' => '2026-08-11T10:00:00+00:00',
            'note' => 'Customer called back',
            'customer_note' => false,
        ]);

        self::assertSame('Customer called back', $note->note);
    }
}
