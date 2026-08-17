<?php

declare(strict_types=1);

namespace AlgerianCommerce\Tests\Unit;

use AlgerianCommerce\Notifications\NotificationPresenter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * §90's two shapes, asserted against a hostile row.
 *
 * `Tracking\TrackingPresenter`'s test is the model and so is its argument: the
 * failure this file exists for is not a leak today but a presenter that grows a
 * field in six months and nobody notices. So the assertions are written against
 * the **shape of the output** — the list row's keys must be exactly the list
 * below — rather than against a set of leaks somebody thought of.
 *
 * The fixture matters as much as the assertions. `row()` is handed a whole
 * database row, `payload` included, exactly as `find()` returns one — and must
 * publish none of it. Filtering the *input contract* instead would depend on
 * every future caller reading a docblock, which is the mistake §84 named.
 */
final class NotificationPresenterTest extends TestCase
{
    /**
     * A row as `SELECT *` returns one, with a payload carrying the customer's
     * name, their order contents and a `context` block a channel put there.
     *
     * @return array<string, mixed>
     */
    private function hostileRow(): array
    {
        return [
            'id' => '77',
            'channel' => 'email',
            'event' => 'order.placed',
            'dedupe_key' => 'order.placed:4211',
            'audience' => 'customer',
            'recipient' => 'amina@example.test',
            'subject_type' => 'order',
            'subject_id' => '4211',
            'status' => 'failed',
            'attempts' => '2',
            'last_error' => 'SMTP connect() failed',
            'payload' => (string) json_encode([
                'event' => 'order.placed',
                'audience' => 'customer',
                'recipient' => 'amina@example.test',
                'subject' => 'Commande 4211 confirmée',
                'body' => "Bonjour Amina,\n\nUn tapis Ghardaïa, 12 000,00 DA.",
                'subject_type' => 'order',
                'subject_id' => 4211,
                'context' => ['order_number' => '4211'],
                // A key a future channel might add. It must not appear.
                'provider_message_id' => 'smtp-abc-123',
            ]),
            'created_at' => '2026-08-17 09:14:02',
            'sent_at' => null,
        ];
    }

    /** Exactly these, in this order. Adding one is a deliberate act. */
    private const LIST_FIELDS = [
        'id', 'channel', 'event', 'dedupe_key', 'audience', 'recipient',
        'subject_type', 'subject_id', 'status', 'attempts', 'last_error',
        'created_at', 'sent_at',
    ];

    public function testAListRowPublishesExactlyTheListFields(): void
    {
        self::assertSame(self::LIST_FIELDS, array_keys(NotificationPresenter::row($this->hostileRow())));
    }

    /**
     * §90: the list omits the message body, so a support agent scanning a queue
     * does not pull five hundred customers' order contents into one response.
     * Asserted by value as well as by key, because the key half cannot catch a
     * rename.
     */
    public function testAListRowCarriesNoneOfTheMessage(): void
    {
        $encoded = (string) json_encode(NotificationPresenter::row($this->hostileRow()));

        foreach (['Bonjour Amina', 'Ghardaïa', '12 000', 'Commande 4211', 'smtp-abc-123', 'payload'] as $secret) {
            self::assertStringNotContainsString($secret, $encoded, "{$secret} reached a list row");
        }
    }

    public function testTypesAreCoercedFromTheStringsMysqlReturns(): void
    {
        $row = NotificationPresenter::row($this->hostileRow());

        self::assertSame(77, $row['id']);
        self::assertSame(4211, $row['subject_id']);
        self::assertSame(2, $row['attempts']);
    }

    /** A notification with no subject reports null rather than 0. */
    public function testAnAbsentSubjectIdIsNull(): void
    {
        $row = NotificationPresenter::row(['id' => 1, 'subject_id' => null]);

        self::assertNull($row['subject_id']);
    }

    public function testTheSingleReadAddsTheMessageAndNothingElse(): void
    {
        $full = NotificationPresenter::full($this->hostileRow());

        self::assertSame([...self::LIST_FIELDS, 'message'], array_keys($full));
    }

    /**
     * The message is read **out of** the payload by allowlist, not the payload
     * with a few keys removed — so a key a future channel adds is not published
     * by default. `provider_message_id` in the fixture is that key.
     */
    public function testTheMessageIsAnAllowlist(): void
    {
        $message = NotificationPresenter::full($this->hostileRow())['message'];

        self::assertSame(['readable', 'subject', 'body', 'context'], array_keys($message));
        self::assertStringNotContainsString('smtp-abc-123', (string) json_encode($message));
    }

    public function testTheMessageIsTheOneFrozenAtQueueTime(): void
    {
        $message = NotificationPresenter::full($this->hostileRow())['message'];

        self::assertTrue($message['readable']);
        self::assertSame('Commande 4211 confirmée', $message['subject']);
        self::assertStringContainsString('Bonjour Amina', $message['body']);
        self::assertSame(['order_number' => '4211'], $message['context']);
    }

    /**
     * The payload `drain()` marks permanently failed over. An operator reading
     * that row needs to see what the drain saw, so it is reported as unreadable
     * rather than as an empty message.
     */
    #[DataProvider('unreadablePayloads')]
    public function testAnUnreadablePayloadSaysSo(mixed $payload): void
    {
        $message = NotificationPresenter::message($payload);

        self::assertFalse($message['readable']);
        self::assertSame('', $message['body']);
        self::assertSame([], $message['context']);
    }

    /** @return array<string, array{mixed}> */
    public static function unreadablePayloads(): array
    {
        return [
            'truncated JSON' => ['{"subject":"x"'],
            'not JSON at all' => ['a message'],
            'a JSON scalar' => ['"just a string"'],
            'empty' => [''],
            'null' => [null],
        ];
    }

    /**
     * Stored UTC by every writer here — `current_time('mysql', true)` — so it
     * is published as UTC. `gmdate()` rather than `mysql_to_rfc3339()` is what
     * keeps this class pure enough to assert without WordPress at all.
     */
    public function testTimesAreUtc(): void
    {
        $row = NotificationPresenter::row($this->hostileRow());

        self::assertSame('2026-08-17T09:14:02+00:00', $row['created_at']);
        self::assertNull($row['sent_at']);
    }

    #[DataProvider('emptyTimes')]
    public function testAnEmptyTimeIsNull(mixed $value): void
    {
        self::assertNull(NotificationPresenter::row(['id' => 1, 'created_at' => $value])['created_at']);
    }

    /** @return array<string, array{mixed}> */
    public static function emptyTimes(): array
    {
        return [
            'null' => [null],
            'empty' => [''],
            // What MySQL used to store for "never", and what a restored dump
            // from an older install can still hold.
            'the zero date' => ['0000-00-00 00:00:00'],
        ];
    }
}
