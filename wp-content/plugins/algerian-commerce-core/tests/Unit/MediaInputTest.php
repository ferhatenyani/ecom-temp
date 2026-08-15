<?php

declare(strict_types=1);

namespace AlgerianCommerce\Tests\Unit;

use AlgerianCommerce\API\ApiException;
use AlgerianCommerce\Media\MediaInput;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What a PATCH may change about an attachment — roadmap §61.
 *
 * The field list is the boundary: an attachment is a WordPress post, so a write
 * path that passed fields through would let content management change
 * `post_type` or `post_author` on a row the media library believes is an image.
 */
final class MediaInputTest extends TestCase
{
    public function testTheThreeFieldsAreAccepted(): void
    {
        $input = MediaInput::fromPayload([
            'alt' => '  Tapis berbère  ',
            'title' => 'Tapis',
            'caption' => 'Fait main',
        ]);

        // Trimmed, because trailing whitespace in alt text is nobody's intent.
        self::assertSame('Tapis berbère', $input->get('alt'));
        self::assertSame('Tapis', $input->get('title'));
        self::assertSame('Fait main', $input->get('caption'));
    }

    public function testAnEmptyPayloadIsEmptyRatherThanAnError(): void
    {
        // The service decides what "nothing to do" means; this only reports it.
        self::assertTrue(MediaInput::fromPayload([])->isEmpty());
    }

    /** Clearing a wrong alt text is a real edit, not a missing field. */
    public function testNullClearsAField(): void
    {
        $input = MediaInput::fromPayload(['alt' => null]);

        self::assertTrue($input->has('alt'));
        self::assertSame('', $input->get('alt'));
    }

    /** @return array<string, array{0: string}> */
    public static function refusedFieldProvider(): array
    {
        return [
            'the bytes themselves' => ['file'],
            'the post type' => ['post_type'],
            'the post status' => ['post_status'],
            'the author' => ['post_author'],
            'the parent' => ['parent_id'],
        ];
    }

    #[DataProvider('refusedFieldProvider')]
    public function testDangerousFieldsAreRefusedByName(string $field): void
    {
        $this->expectException(ApiException::class);

        MediaInput::fromPayload([$field => 'anything']);
    }

    public function testARefusedFieldSaysWhyRatherThanUnknownField(): void
    {
        try {
            MediaInput::fromPayload(['file' => 'new-bytes.jpg']);
        } catch (ApiException $exception) {
            self::assertStringContainsString(
                'upload a new one',
                (string) ($exception->details()['fields']['file'] ?? '')
            );

            return;
        }

        self::fail('replacing the stored file must be refused');
    }

    public function testAnUnknownFieldIsRefused(): void
    {
        $this->expectException(ApiException::class);

        MediaInput::fromPayload(['description' => 'not one of the three']);
    }

    /**
     * Read-only fields are dropped rather than refused, so a client can GET an
     * item, change the alt text and PATCH the whole object back.
     */
    public function testReadOnlyFieldsAreDroppedNotRefused(): void
    {
        $input = MediaInput::fromPayload([
            'id' => 7,
            'url' => 'https://example.test/x.jpg',
            'mime_type' => 'image/jpeg',
            'filesize' => 1234,
            'sizes' => [],
            'alt' => 'kept',
        ]);

        self::assertSame(['alt'], array_keys($input->fields));
    }

    public function testAnOverlongValueIsRefused(): void
    {
        $this->expectException(ApiException::class);

        MediaInput::fromPayload(['caption' => str_repeat('a', 501)]);
    }

    public function testANonScalarValueIsRefused(): void
    {
        $this->expectException(ApiException::class);

        MediaInput::fromPayload(['alt' => ['nested' => true]]);
    }
}
