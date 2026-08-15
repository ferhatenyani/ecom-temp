<?php

declare(strict_types=1);

namespace AlgerianCommerce\Tests\Unit;

use AlgerianCommerce\API\ApiException;
use AlgerianCommerce\Payments\PaymentInput;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PaymentInputTest extends TestCase
{
    /**
     * The amount is never a client's to name.
     *
     * It comes from the order, server-side. A caller that could set it could pay
     * 45 DZD for a 45,000 DZD basket — the same attack docs/SECURITY.md's
     * re-check catches on the way back, refused here on the way out.
     */
    public function testAnAmountSentByTheCallerIsRefused(): void
    {
        try {
            PaymentInput::fromPayload(['provider' => 'cod', 'amount' => '1.00']);
            self::fail('An amount must not be accepted from a caller.');
        } catch (ApiException $exception) {
            self::assertSame('Unknown field.', $exception->details()['fields']['amount']);
        }
    }

    public function testUnknownFieldsAreNamedRatherThanDropped(): void
    {
        try {
            PaymentInput::fromPayload(['provider' => 'cod', 'retrun_url' => 'x', 'evil' => 1]);
            self::fail('Unknown fields must be refused.');
        } catch (ApiException $exception) {
            $fields = $exception->details()['fields'];
            self::assertArrayHasKey('retrun_url', $fields);
            self::assertArrayHasKey('evil', $fields);
        }
    }

    /** @return array<string, array{0: string}> */
    public static function badReturnUrlProvider(): array
    {
        return [
            'plain http' => ['http://store.example.dz/thanks'],
            'not a url' => ['javascript:alert(1)'],
            'protocol relative' => ['//evil.example.com'],
            'bare host' => ['store.example.dz'],
        ];
    }

    /**
     * The return URL is handed to a provider that redirects a customer's browser
     * to it. Unchecked, this endpoint becomes an open redirect with a payment
     * provider's credibility behind it.
     */
    #[DataProvider('badReturnUrlProvider')]
    public function testAReturnUrlMustBeAnHttpsUrl(string $url): void
    {
        $this->expectException(ApiException::class);

        PaymentInput::fromPayload(['return_url' => $url]);
    }

    public function testAValidPayloadIsAccepted(): void
    {
        $input = PaymentInput::fromPayload([
            'provider' => 'COD',
            'reference' => '42-2',
            'description' => 'Order 42',
            'return_url' => 'https://store.example.dz/thanks',
        ]);

        // Lower-cased, because the registry keys on the slug.
        self::assertSame('cod', $input->provider);
        self::assertSame('42-2', $input->reference);
        self::assertSame('https://store.example.dz/thanks', $input->returnUrl);
    }

    public function testAnEmptyPayloadIsValidAndMeansDefaults(): void
    {
        $input = PaymentInput::fromPayload([]);

        // Empty provider means "the shop's default", resolved by the registry.
        self::assertSame('', $input->provider);
        self::assertSame('', $input->returnUrl);
    }
}
