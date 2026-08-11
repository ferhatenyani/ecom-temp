<?php

declare(strict_types=1);

namespace AlgerianCommerce\Tests\Unit;

use AlgerianCommerce\API\ApiException;
use AlgerianCommerce\COD\CodAttemptInput;
use AlgerianCommerce\COD\CodSettingsInput;
use AlgerianCommerce\COD\CodState;
use PHPUnit\Framework\TestCase;

final class CodAttemptInputTest extends TestCase
{
    public function testAValidAttempt(): void
    {
        $input = CodAttemptInput::fromPayload(['outcome' => 'confirmed', 'reason' => '  by phone  ']);

        self::assertSame('confirmed', $input->outcome);
        self::assertSame('by phone', $input->reason);
    }

    public function testReasonIsOptional(): void
    {
        self::assertSame('', CodAttemptInput::fromPayload(['outcome' => 'unreachable'])->reason);
        self::assertSame('', CodAttemptInput::fromPayload(['outcome' => 'rejected', 'reason' => null])->reason);
    }

    public function testOutcomeIsRequired(): void
    {
        $this->expectException(ApiException::class);

        CodAttemptInput::fromPayload(['reason' => 'no answer']);
    }

    /**
     * `pending` is where an order starts and `cancelled` happens to the order
     * itself — neither is something a confirmation call concludes with.
     */
    public function testStatesThatAreNotCallOutcomesAreRefused(): void
    {
        foreach (['pending', 'cancelled', 'delivered', ''] as $outcome) {
            $refused = false;

            try {
                CodAttemptInput::fromPayload(['outcome' => $outcome]);
            } catch (ApiException $exception) {
                $refused = true;
                self::assertArrayHasKey('outcome', $exception->details()['fields']);
            }

            self::assertTrue($refused, "{$outcome} must not be accepted as an outcome");
        }
    }

    public function testUnknownFieldsAreRejected(): void
    {
        try {
            CodAttemptInput::fromPayload(['outcome' => 'confirmed', 'attempts' => 9]);
            self::fail('an unknown field must be rejected');
        } catch (ApiException $exception) {
            self::assertSame(400, $exception->statusCode());
            self::assertArrayHasKey('attempts', $exception->details()['fields']);
        }
    }

    public function testAnOverlongReasonIsRefused(): void
    {
        $this->expectException(ApiException::class);

        CodAttemptInput::fromPayload([
            'outcome' => 'rejected',
            'reason' => str_repeat('a', CodState::MAX_REASON + 1),
        ]);
    }

    public function testANonStringReasonIsRefused(): void
    {
        $this->expectException(ApiException::class);

        CodAttemptInput::fromPayload(['outcome' => 'rejected', 'reason' => ['too', 'long']]);
    }

    public function testSettingsAcceptOnlyABoolean(): void
    {
        self::assertTrue(CodSettingsInput::fromPayload(['enabled' => true])->enabled);
        self::assertFalse(CodSettingsInput::fromPayload(['enabled' => false])->enabled);
    }

    /**
     * A JSON body distinguishes true from "true", and a shop that reads the
     * string "false" as true keeps calling customers it should have stopped
     * calling.
     */
    public function testSettingsRefuseAStringThatLooksLikeABoolean(): void
    {
        foreach (['true', 'false', 1, 0, null] as $value) {
            $refused = false;

            try {
                CodSettingsInput::fromPayload(['enabled' => $value]);
            } catch (ApiException $exception) {
                $refused = true;
            }

            self::assertTrue($refused, var_export($value, true) . ' must not be accepted');
        }
    }

    public function testSettingsRequireTheField(): void
    {
        $this->expectException(ApiException::class);

        CodSettingsInput::fromPayload([]);
    }

    /**
     * GET the state, flip one field, PATCH the whole object back — the same
     * contract an order offers.
     */
    public function testSettingsDropTheFieldsTheApiEmitsButDoesNotAccept(): void
    {
        $state = CodState::fromMeta([], true)->toArray();
        $state['enabled'] = false;

        self::assertFalse(CodSettingsInput::fromPayload($state)->enabled);
    }

    public function testSettingsRejectUnknownFields(): void
    {
        try {
            CodSettingsInput::fromPayload(['enabled' => true, 'confirm' => true]);
            self::fail('an unknown field must be rejected');
        } catch (ApiException $exception) {
            self::assertArrayHasKey('confirm', $exception->details()['fields']);
        }
    }
}
