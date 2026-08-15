<?php

declare(strict_types=1);

namespace AlgerianCommerce\Tests\Unit;

use AlgerianCommerce\API\ErrorNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ErrorNormalizerTest extends TestCase
{
    public function testConvertsAWordPressErrorBodyIntoTheEnvelope(): void
    {
        $normalized = ErrorNormalizer::normalize([
            'code' => 'rest_no_route',
            'message' => 'No route was found matching the URL and request method.',
            'data' => ['status' => 404],
        ], 404);

        self::assertNotNull($normalized);
        self::assertFalse($normalized['success']);
        self::assertSame('not_found', $normalized['error']['code']);
        self::assertSame(
            'No route was found matching the URL and request method.',
            $normalized['error']['message']
        );
    }

    public function testCarriesValidationParamsIntoDetails(): void
    {
        $normalized = ErrorNormalizer::normalize([
            'code' => 'rest_invalid_param',
            'message' => 'Invalid parameter(s): per_page',
            'data' => ['status' => 400, 'params' => ['per_page' => 'Must be <= 100.']],
        ], 400);

        self::assertSame('invalid_request', $normalized['error']['code']);
        self::assertSame(['per_page' => 'Must be <= 100.'], $normalized['error']['details']['params']);
    }

    public function testLeavesAnAlreadyEnvelopedErrorAlone(): void
    {
        $ours = ['success' => false, 'error' => ['code' => 'conflict', 'message' => 'Duplicate SKU.']];

        self::assertNull(ErrorNormalizer::normalize($ours, 409));
    }

    public function testLeavesSuccessResponsesAlone(): void
    {
        self::assertNull(ErrorNormalizer::normalize(['success' => true, 'data' => []], 200));
        self::assertNull(ErrorNormalizer::normalize(['anything' => 1], 200));
    }

    public function testIgnoresBodiesThatAreNotWordPressErrors(): void
    {
        self::assertNull(ErrorNormalizer::normalize('a string', 500));
        self::assertNull(ErrorNormalizer::normalize(['no_code_key' => true], 500));
        self::assertNull(ErrorNormalizer::normalize(['code' => 123], 500), 'non-string code');
    }

    public function testSuppliesAFallbackMessageWhenWordPressOmitsOne(): void
    {
        $normalized = ErrorNormalizer::normalize(['code' => 'rest_forbidden'], 403);

        self::assertSame('forbidden', $normalized['error']['code']);
        self::assertNotSame('', $normalized['error']['message']);
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function codeProvider(): array
    {
        return [
            'no route' => ['rest_no_route', 'not_found'],
            'invalid param' => ['rest_invalid_param', 'invalid_request'],
            'missing param' => ['rest_missing_callback_param', 'invalid_request'],
            'forbidden' => ['rest_forbidden', 'forbidden'],
            'not logged in' => ['rest_not_logged_in', 'unauthenticated'],
            'unmapped passes through' => ['some_plugin_error', 'some_plugin_error'],
        ];
    }

    #[DataProvider('codeProvider')]
    public function testCodeMapping(string $wordpressCode, string $expected): void
    {
        self::assertSame($expected, ErrorNormalizer::mapCode($wordpressCode));
    }

    public function testOnlyOwnNamespaceIsClaimed(): void
    {
        self::assertTrue(ErrorNormalizer::isOwnRoute('/algerian-commerce/v1/health'));
        self::assertTrue(ErrorNormalizer::isOwnRoute('algerian-commerce/v1/products'));
        self::assertFalse(ErrorNormalizer::isOwnRoute('/wp/v2/posts'));
        self::assertFalse(ErrorNormalizer::isOwnRoute('/wc/v3/products'));
    }
}
