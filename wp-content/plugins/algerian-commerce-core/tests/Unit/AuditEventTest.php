<?php

declare(strict_types=1);

namespace AlgerianCommerce\Tests\Unit;

use AlgerianCommerce\Audit\AuditEvent;
use AlgerianCommerce\Core\Logger;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AuditEventTest extends TestCase
{
    public function testKeepsTheFieldsItIsGiven(): void
    {
        $event = new AuditEvent(
            'product.deleted',
            'product',
            '412',
            7,
            'amina',
            '41.100.1.5',
            ['sku' => 'DZ-001'],
            '2026-08-10 09:30:00'
        );

        self::assertSame('product.deleted', $event->action);
        self::assertSame('product', $event->resourceType);
        self::assertSame('412', $event->resourceId);
        self::assertSame(7, $event->actorId);
        self::assertSame('amina', $event->actorLogin);
        self::assertSame('41.100.1.5', $event->ipAddress);
        self::assertSame('2026-08-10 09:30:00', $event->createdAt);
    }

    public function testActionIsRequired(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new AuditEvent('   ');
    }

    public function testMetadataIsRedactedBeforeStorage(): void
    {
        // The audit table is append-only: a secret written here cannot be
        // edited out later.
        $event = new AuditEvent('payment.verified', 'order', '9', 1, 'admin', '', [
            'order_id' => 9,
            'chargily_secret_key' => 'sk_live_leak',
            'headers' => ['Authorization' => 'Bearer abc'],
        ]);

        self::assertSame(Logger::MASK, $event->metadata['chargily_secret_key']);
        self::assertSame(Logger::MASK, $event->metadata['headers']['Authorization']);
        self::assertSame(9, $event->metadata['order_id']);
        self::assertStringNotContainsString('sk_live_leak', $event->encodedMetadata());
    }

    /** @return array<string, array{0: string, 1: int}> */
    public static function widthProvider(): array
    {
        return [
            'action' => ['action', AuditEvent::MAX_ACTION],
            'resource type' => ['resourceType', AuditEvent::MAX_RESOURCE_TYPE],
            'resource id' => ['resourceId', AuditEvent::MAX_RESOURCE_ID],
            'actor login' => ['actorLogin', AuditEvent::MAX_ACTOR_LOGIN],
            'ip address' => ['ipAddress', AuditEvent::MAX_IP],
        ];
    }

    /**
     * MySQL in strict mode rejects an over-length value, which would turn a
     * long field into a failed audit write.
     */
    #[DataProvider('widthProvider')]
    public function testFieldsAreTruncatedToTheirColumnWidth(string $property, int $limit): void
    {
        $long = str_repeat('x', $limit + 50);

        $event = new AuditEvent($long, $long, $long, 1, $long, $long);

        self::assertSame($limit, mb_strlen($event->{$property}));
    }

    public function testTruncationDoesNotSplitMultibyteCharacters(): void
    {
        $event = new AuditEvent(str_repeat('é', AuditEvent::MAX_ACTION + 10));

        self::assertSame(AuditEvent::MAX_ACTION, mb_strlen($event->action));
        self::assertSame($event->action, mb_convert_encoding($event->action, 'UTF-8', 'UTF-8'));
    }

    public function testNegativeActorIdIsClampedToZero(): void
    {
        self::assertSame(0, (new AuditEvent('x', '', '', -5))->actorId);
    }

    public function testRowMatchesTheMigrationColumnsAndFormats(): void
    {
        $event = new AuditEvent('order.cancelled', 'order', '3', 2, 'kamel', '10.0.0.1', ['reason' => 'stock']);
        $row = $event->toRow();

        self::assertSame([
            'actor_id', 'actor_login', 'action', 'resource_type',
            'resource_id', 'ip_address', 'metadata', 'created_at',
        ], array_keys($row));

        self::assertCount(count($row), $event->rowFormats());
        self::assertSame('%d', $event->rowFormats()[0], 'actor_id is numeric');
    }

    public function testEmptyMetadataEncodesToAnEmptyStringNotNull(): void
    {
        self::assertSame('', (new AuditEvent('x'))->encodedMetadata());
    }

    public function testCreatedAtDefaultsToUtcInMysqlFormat(): void
    {
        $event = new AuditEvent('x');

        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $event->createdAt);
    }
}
