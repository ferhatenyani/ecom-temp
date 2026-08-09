<?php

declare(strict_types=1);

namespace AlgerianCommerce\Tests\Unit;

use AlgerianCommerce\API\Response;
use PHPUnit\Framework\TestCase;

final class ResponseTest extends TestCase
{
    public function testSuccessPayloadHasEnvelopeShape(): void
    {
        $payload = Response::successPayload(['id' => 7]);

        self::assertSame(['success' => true, 'data' => ['id' => 7]], $payload);
    }

    public function testSuccessPayloadDefaultsToEmptyDataObject(): void
    {
        self::assertSame(['success' => true, 'data' => []], Response::successPayload());
    }

    public function testNullDataBecomesEmptyArrayRatherThanNull(): void
    {
        $payload = Response::successPayload(null);

        self::assertSame([], $payload['data']);
    }

    public function testMetaIsOmittedWhenEmptyAndIncludedWhenPresent(): void
    {
        self::assertArrayNotHasKey('meta', Response::successPayload(['a' => 1]));

        $withMeta = Response::successPayload([], ['total' => 3]);
        self::assertSame(['total' => 3], $withMeta['meta']);
    }

    public function testRootKeysAppearBeforeDataAndAfterSuccess(): void
    {
        // The health contract requires a root-level "status" alongside success.
        $payload = Response::successPayload(['checks' => []], [], ['status' => 'ok']);

        self::assertSame(['success', 'status', 'data'], array_keys($payload));
        self::assertTrue($payload['success']);
        self::assertSame('ok', $payload['status']);
    }

    public function testErrorPayloadHasEnvelopeShape(): void
    {
        $payload = Response::errorPayload('invalid_product', 'The product is invalid.');

        self::assertSame([
            'success' => false,
            'error' => [
                'code' => 'invalid_product',
                'message' => 'The product is invalid.',
            ],
        ], $payload);
    }

    public function testErrorDetailsAreOmittedWhenEmpty(): void
    {
        $payload = Response::errorPayload('invalid_request', 'Bad.');

        self::assertArrayNotHasKey('details', $payload['error']);
    }

    public function testErrorDetailsAreIncludedWhenPresent(): void
    {
        $payload = Response::errorPayload('invalid_request', 'Bad.', ['field' => 'sku']);

        self::assertSame(['field' => 'sku'], $payload['error']['details']);
    }

    public function testPaginationMetaComputesTotalPages(): void
    {
        self::assertSame([
            'total' => 45,
            'page' => 2,
            'per_page' => 20,
            'total_pages' => 3,
        ], Response::paginationMeta(45, 2, 20));
    }

    public function testPaginationMetaHandlesEmptyResultSet(): void
    {
        $meta = Response::paginationMeta(0);

        self::assertSame(0, $meta['total']);
        self::assertSame(0, $meta['total_pages']);
    }

    public function testPaginationMetaClampsOutOfRangeInput(): void
    {
        $meta = Response::paginationMeta(-5, 0, 5000);

        self::assertSame(0, $meta['total'], 'negative total clamps to zero');
        self::assertSame(1, $meta['page'], 'page is at least 1');
        self::assertSame(Response::MAX_PER_PAGE, $meta['per_page'], 'per_page is capped');
    }

    public function testPaginationMetaRejectsZeroPerPageWithoutDividingByZero(): void
    {
        $meta = Response::paginationMeta(10, 1, 0);

        self::assertSame(1, $meta['per_page']);
        self::assertSame(10, $meta['total_pages']);
    }
}
