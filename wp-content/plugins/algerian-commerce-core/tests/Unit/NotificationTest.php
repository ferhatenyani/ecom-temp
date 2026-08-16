<?php

declare(strict_types=1);

namespace AlgerianCommerce\Tests\Unit;

use AlgerianCommerce\Notifications\Notification;
use AlgerianCommerce\Notifications\NotificationEvent;
use AlgerianCommerce\Notifications\NotificationMessages;
use AlgerianCommerce\Notifications\NotificationResult;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The pure half of the notification layer — docs/PLAN.md §29, §30.
 *
 * The dedupe key is the piece worth testing hardest: it is what turns eight
 * hook firings into one message, and `ac_notifications`' unique index is only
 * as good as the string handed to it.
 */
final class NotificationTest extends TestCase
{
    public function testTheSameEventAboutTheSameOrderDeduplicates(): void
    {
        $a = Notification::toCustomer(NotificationEvent::ORDER_PLACED, 'a@x.test', 's', 'b', 'order', 42);
        $b = Notification::toCustomer(NotificationEvent::ORDER_PLACED, 'a@x.test', 'different', 'body', 'order', 42);

        self::assertSame($a->dedupeKey(), $b->dedupeKey(), 'a re-render must not produce a second message');
    }

    public function testDifferentEventsAboutOneOrderDoNot(): void
    {
        $placed = Notification::toCustomer(NotificationEvent::ORDER_PLACED, 'a@x.test', 's', 'b', 'order', 42);
        $shipped = Notification::toCustomer(NotificationEvent::SHIPMENT_SHIPPED, 'a@x.test', 's', 'b', 'order', 42);

        self::assertNotSame($placed->dedupeKey(), $shipped->dedupeKey());
    }

    public function testDifferentOrdersDoNot(): void
    {
        $one = Notification::toCustomer(NotificationEvent::ORDER_PLACED, 'a@x.test', 's', 'b', 'order', 42);
        $two = Notification::toCustomer(NotificationEvent::ORDER_PLACED, 'a@x.test', 's', 'b', 'order', 43);

        self::assertNotSame($one->dedupeKey(), $two->dedupeKey());
    }

    /** With no subject id the recipient is what identifies the message. */
    public function testWithoutASubjectTheRecipientKeysIt(): void
    {
        $a = Notification::toAdmin(NotificationEvent::ADMIN_NEW_ORDER, 'ops@x.test', 's', 'b');
        $b = Notification::toAdmin(NotificationEvent::ADMIN_NEW_ORDER, 'other@x.test', 's', 'b');

        self::assertNotSame($a->dedupeKey(), $b->dedupeKey());
    }

    /** The column is varchar(191); a longer key would be truncated by MySQL. */
    public function testTheKeyFitsItsColumn(): void
    {
        $long = Notification::toAdmin(NotificationEvent::STOCK_LOW, str_repeat('a', 400) . '@x.test', 's', 'b');

        self::assertLessThanOrEqual(191, strlen($long->dedupeKey()));
    }

    public function testItSurvivesTheQueue(): void
    {
        $original = Notification::toCustomer(
            NotificationEvent::ORDER_PLACED,
            'amina@x.test',
            'Order 12 received',
            'Bonjour Amina,',
            'order',
            12,
            ['total' => '4500.00']
        );

        $restored = Notification::fromArray(json_decode((string) json_encode($original->toArray()), true));

        self::assertSame($original->toArray(), $restored->toArray());
    }

    public function testAdminEventsAreNamedApartFromCustomerOnes(): void
    {
        self::assertTrue(NotificationEvent::isAdmin(NotificationEvent::STOCK_LOW));
        self::assertTrue(NotificationEvent::isAdmin(NotificationEvent::ADMIN_NEW_ORDER));
        self::assertFalse(NotificationEvent::isAdmin(NotificationEvent::ORDER_PLACED));
    }

    public function testUnknownEventsAreNotKnown(): void
    {
        self::assertFalse(NotificationEvent::isKnown('order.exploded'));
        self::assertTrue(NotificationEvent::isKnown(NotificationEvent::ORDER_PLACED));
    }

    /** Every event in the vocabulary renders something; a silent one is a bug. */
    #[DataProvider('eventProvider')]
    public function testEveryEventRenders(string $event): void
    {
        $message = NotificationMessages::render($event, 'Tapis DZ', [
            'order_number' => '1234', 'total' => '4500.00', 'currency' => 'DZD',
            'customer_name' => 'Amina', 'product_name' => 'Rug', 'sku' => 'R-1', 'stock' => '2',
        ]);

        self::assertNotSame('', $message['subject'], "{$event} rendered no subject");
        self::assertNotSame('', $message['body'], "{$event} rendered no body");
    }

    /** @return array<string, array{0: string}> */
    public static function eventProvider(): array
    {
        $out = [];

        foreach (NotificationEvent::ALL as $event) {
            $out[$event] = [$event];
        }

        return $out;
    }

    public function testAnUnknownEventRendersNothingRatherThanGuessing(): void
    {
        self::assertSame(['subject' => '', 'body' => ''], NotificationMessages::render('nope', 'Shop', []));
    }

    public function testAShipmentMessageWorksWithoutATrackingNumber(): void
    {
        // §57 records that a parcel exists before its number is readable.
        $message = NotificationMessages::render(NotificationEvent::SHIPMENT_SHIPPED, 'Tapis DZ', [
            'order_number' => '12', 'provider' => 'yalidine', 'tracking_number' => '',
        ]);

        self::assertStringContainsString('yalidine', $message['body']);
        self::assertStringNotContainsString('Tracking number', $message['body']);
    }

    public function testAShipmentMessageIncludesATrackingNumberWhenThereIsOne(): void
    {
        $message = NotificationMessages::render(NotificationEvent::SHIPMENT_SHIPPED, 'Tapis DZ', [
            'order_number' => '12', 'provider' => 'yalidine', 'tracking_number' => 'YAL-9',
        ]);

        self::assertStringContainsString('YAL-9', $message['body']);
    }

    public function testAnAnonymousCustomerStillGetsAGreeting(): void
    {
        $message = NotificationMessages::render(NotificationEvent::ORDER_PLACED, 'Shop', ['order_number' => '1']);

        self::assertStringContainsString('Bonjour', $message['body']);
    }

    public function testResultsCarryTheirReason(): void
    {
        self::assertTrue(NotificationResult::sent()->sent);
        self::assertTrue(NotificationResult::failed('timeout')->retryable);
        self::assertFalse(NotificationResult::rejected('bad address')->retryable);
        self::assertSame('bad address', NotificationResult::rejected('bad address')->error);
    }
}
