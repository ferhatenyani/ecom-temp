<?php

declare(strict_types=1);

namespace AlgerianCommerce\Tests\Unit;

use AlgerianCommerce\API\ApiException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ApiExceptionTest extends TestCase
{
    public function testCarriesErrorCodeStatusAndDetails(): void
    {
        $exception = new ApiException('invalid_product', 'The product is invalid.', 422, ['field' => 'sku']);

        self::assertSame('invalid_product', $exception->errorCode());
        self::assertSame(422, $exception->statusCode());
        self::assertSame(['field' => 'sku'], $exception->details());
        self::assertSame('The product is invalid.', $exception->getMessage());
    }

    public function testToPayloadMatchesTheErrorEnvelope(): void
    {
        $payload = ApiException::notFound('No such order.')->toPayload();

        self::assertFalse($payload['success']);
        self::assertSame('not_found', $payload['error']['code']);
        self::assertSame('No such order.', $payload['error']['message']);
    }

    /** @return array<string, array{0: ApiException, 1: string, 2: int}> */
    public static function factoryProvider(): array
    {
        return [
            'invalid request' => [ApiException::invalidRequest(), 'invalid_request', 400],
            'unauthenticated' => [ApiException::unauthenticated(), 'unauthenticated', 401],
            'forbidden' => [ApiException::forbidden(), 'forbidden', 403],
            'not found' => [ApiException::notFound(), 'not_found', 404],
            'conflict' => [ApiException::conflict(), 'conflict', 409],
            'internal' => [ApiException::internal(), 'internal_error', 500],
        ];
    }

    #[DataProvider('factoryProvider')]
    public function testFactoriesMapToTheExpectedCodeAndStatus(
        ApiException $exception,
        string $expectedCode,
        int $expectedStatus
    ): void {
        self::assertSame($expectedCode, $exception->errorCode());
        self::assertSame($expectedStatus, $exception->statusCode());
    }

    public function testInternalPreservesThePreviousExceptionForLoggingButNotForClients(): void
    {
        $cause = new RuntimeException('Connection refused to 10.0.0.5');
        $exception = ApiException::internal(previous: $cause);

        self::assertSame($cause, $exception->getPrevious());
        self::assertStringNotContainsString('10.0.0.5', $exception->getMessage());
        self::assertStringNotContainsString('10.0.0.5', json_encode($exception->toPayload(), JSON_THROW_ON_ERROR));
    }

    public function testIsThrowable(): void
    {
        $this->expectException(ApiException::class);

        throw ApiException::forbidden();
    }
}
