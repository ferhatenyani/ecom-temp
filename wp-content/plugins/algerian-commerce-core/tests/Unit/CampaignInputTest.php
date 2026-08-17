<?php

declare(strict_types=1);

namespace AlgerianCommerce\Tests\Unit;

use AlgerianCommerce\API\ApiException;
use AlgerianCommerce\Campaigns\Campaign;
use AlgerianCommerce\Campaigns\CampaignInput;
use AlgerianCommerce\Campaigns\CampaignStatus;
use AlgerianCommerce\Campaigns\UnsubscribeToken;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * §85's write rules and its lifecycle, pure.
 *
 * The refusal list is the half worth reading, and it is asserted the way
 * `CustomerInputTest` asserts its own: **by name, with a reason**. Three of them are
 * genuinely load-bearing — `status`, because a payload that could write `sent` would
 * mark a campaign delivered without mailing anybody; the three `recipients_*` counts,
 * because they are the record that survives §85's purge; and `recipients`/`emails`/`to`,
 * because an audience of arbitrary addresses would bypass the consent filter entirely
 * and turn this into an open mail relay for whoever holds `ac_manage_marketing`.
 */
final class CampaignInputTest extends TestCase
{
    public function testAcceptsAMinimalCampaign(): void
    {
        $input = CampaignInput::forCreate([
            'name' => 'August sale',
            'subject' => 'Big news',
            'audience_type' => 'all',
        ]);

        self::assertSame('August sale', $input->get('name'));
        self::assertSame('all', $input->get('audience_type'));
    }

    #[DataProvider('refusedFields')]
    public function testRefusesByNameWithAReason(string $field): void
    {
        try {
            CampaignInput::forCreate(['name' => 'x', 'subject' => 'y', 'audience_type' => 'all', $field => 'anything']);
            self::fail("{$field} was accepted");
        } catch (ApiException $exception) {
            $fields = $exception->details()['fields'] ?? [];

            self::assertArrayHasKey($field, $fields);
            self::assertGreaterThan(20, strlen((string) $fields[$field]), 'the refusal must explain itself');
        }
    }

    /** @return list<array{string}> */
    public static function refusedFields(): array
    {
        return array_map(static fn (string $f): array => [$f], array_keys(CampaignInput::REFUSED));
    }

    /**
     * The three that matter most, named individually so a future edit to the list
     * cannot quietly drop one.
     */
    public function testTheLoadBearingRefusals(): void
    {
        foreach (['status', 'recipients_total', 'recipients_sent', 'recipients_failed', 'recipients', 'emails', 'to'] as $field) {
            self::assertArrayHasKey($field, CampaignInput::REFUSED, "{$field} must be refused by name");
        }
    }

    public function testAnUnknownFieldIsRefusedRatherThanDropped(): void
    {
        try {
            CampaignInput::forCreate(['name' => 'x', 'subject' => 'y', 'audience_type' => 'all', 'subjcet' => 'typo']);
            self::fail('accepted');
        } catch (ApiException $exception) {
            self::assertSame('Unknown field.', $exception->details()['fields']['subjcet'] ?? null);
        }
    }

    // ------------------------------------------------------------- required --

    public function testACampaignNeedsANameAndASubject(): void
    {
        try {
            CampaignInput::forCreate(['audience_type' => 'all']);
            self::fail('accepted');
        } catch (ApiException $exception) {
            $fields = $exception->details()['fields'] ?? [];

            self::assertArrayHasKey('name', $fields);
            self::assertArrayHasKey('subject', $fields);
        }
    }

    public function testABlankSubjectIsRefusedOnUpdateToo(): void
    {
        $this->expectException(ApiException::class);

        CampaignInput::forUpdate(['subject' => '   ']);
    }

    public function testAnUpdateMayCarryOneField(): void
    {
        $input = CampaignInput::forUpdate(['subject' => 'Corrected']);

        self::assertSame(['subject' => 'Corrected'], $input->fields);
        self::assertFalse($input->has('name'));
    }

    public function testAnEmptyUpdateIsEmptyRatherThanAnError(): void
    {
        // Reporting "no supported fields" is the service's job, so it can say so with
        // the campaign's status in hand.
        self::assertTrue(CampaignInput::forUpdate([])->isEmpty());
    }

    // ------------------------------------------------------------- audience --

    public function testAnIdAudienceNeedsIds(): void
    {
        try {
            CampaignInput::forCreate(['name' => 'x', 'subject' => 'y', 'audience_type' => 'ids']);
            self::fail('accepted');
        } catch (ApiException $exception) {
            self::assertArrayHasKey('customer_ids', $exception->details()['fields'] ?? []);
        }
    }

    public function testASegmentAudienceNeedsASegment(): void
    {
        try {
            CampaignInput::forCreate(['name' => 'x', 'subject' => 'y', 'audience_type' => 'segment']);
            self::fail('accepted');
        } catch (ApiException $exception) {
            self::assertArrayHasKey('segment_id', $exception->details()['fields'] ?? []);
        }
    }

    /**
     * A campaign to "everyone" that also names a segment is ambiguous, and the
     * resolver would silently ignore one of them. Refused rather than narrowed.
     */
    public function testAnAllAudienceMayNotAlsoNameASegmentOrAList(): void
    {
        foreach ([['segment_id' => 3], ['customer_ids' => [1, 2]]] as $extra) {
            try {
                CampaignInput::forCreate(['name' => 'x', 'subject' => 'y', 'audience_type' => 'all'] + $extra);
                self::fail('accepted ' . implode(',', array_keys($extra)));
            } catch (ApiException $exception) {
                self::assertNotSame([], $exception->details()['fields'] ?? []);
            }
        }
    }

    public function testCustomerIdsAreDeduplicatedAndTyped(): void
    {
        $input = CampaignInput::forCreate([
            'name' => 'x', 'subject' => 'y', 'audience_type' => 'ids',
            'customer_ids' => [7, '7', 9],
        ]);

        self::assertSame([7, 9], $input->get('audience_ids'));
    }

    #[DataProvider('badIdLists')]
    public function testRefusesABadIdList(mixed $ids): void
    {
        $this->expectException(ApiException::class);

        CampaignInput::forCreate(['name' => 'x', 'subject' => 'y', 'audience_type' => 'ids', 'customer_ids' => $ids]);
    }

    /** @return array<string, array{mixed}> */
    public static function badIdLists(): array
    {
        return [
            'a scalar' => ['7'],
            'a zero' => [[0]],
            'a negative' => [[-1]],
            'a name' => [['amina']],
            'an empty list' => [[]],
            'too many' => [[range(1, Campaign::MAX_EXPLICIT_IDS + 1)]],
        ];
    }

    public function testAnOversizeListSaysToUseASegment(): void
    {
        try {
            CampaignInput::forCreate([
                'name' => 'x', 'subject' => 'y', 'audience_type' => 'ids',
                'customer_ids' => range(1, Campaign::MAX_EXPLICIT_IDS + 1),
            ]);
            self::fail('accepted');
        } catch (ApiException $exception) {
            self::assertStringContainsString('segment', (string) $exception->details()['fields']['customer_ids']);
        }
    }

    public function testAnUnknownAudienceTypeIsRefused(): void
    {
        $this->expectException(ApiException::class);

        CampaignInput::forCreate(['name' => 'x', 'subject' => 'y', 'audience_type' => 'everyone']);
    }

    public function testABodyOverTheCapIsRefused(): void
    {
        $this->expectException(ApiException::class);

        CampaignInput::forCreate([
            'name' => 'x', 'subject' => 'y', 'audience_type' => 'all',
            'body_html' => str_repeat('a', CampaignInput::MAX_BODY + 1),
        ]);
    }

    // ------------------------------------------------------------ lifecycle --

    /** A campaign can be sent exactly once, and this is the whole of that rule. */
    public function testTheLifecycle(): void
    {
        self::assertTrue(CampaignStatus::accepts(CampaignStatus::DRAFT, CampaignStatus::SENDING));
        self::assertTrue(CampaignStatus::accepts(CampaignStatus::DRAFT, CampaignStatus::CANCELLED));
        self::assertTrue(CampaignStatus::accepts(CampaignStatus::SENDING, CampaignStatus::SENT));
        self::assertTrue(CampaignStatus::accepts(CampaignStatus::SENDING, CampaignStatus::CANCELLED));

        self::assertFalse(CampaignStatus::accepts(CampaignStatus::SENDING, CampaignStatus::SENDING), 'no second send');
        self::assertFalse(CampaignStatus::accepts(CampaignStatus::SENT, CampaignStatus::SENDING), 'no re-send');
        self::assertFalse(CampaignStatus::accepts(CampaignStatus::SENT, CampaignStatus::DRAFT), 'no editing back');
        self::assertFalse(CampaignStatus::accepts(CampaignStatus::CANCELLED, CampaignStatus::SENDING));
        self::assertFalse(CampaignStatus::accepts(CampaignStatus::SENDING, CampaignStatus::DRAFT));
    }

    public function testOnlyADraftIsEditable(): void
    {
        self::assertTrue(CampaignStatus::isEditable(CampaignStatus::DRAFT));

        foreach ([CampaignStatus::SENDING, CampaignStatus::SENT, CampaignStatus::CANCELLED] as $status) {
            self::assertFalse(CampaignStatus::isEditable($status), "{$status} must not be editable");
        }
    }

    public function testTerminalStatusesAreTerminal(): void
    {
        self::assertSame([CampaignStatus::SENT, CampaignStatus::CANCELLED], CampaignStatus::TERMINAL);
        self::assertSame([], CampaignStatus::allowedFrom(CampaignStatus::SENT));
    }

    // ------------------------------------------------------------- the row --

    public function testTheRowRoundTrips(): void
    {
        $campaign = new Campaign(
            'August sale',
            'Big news',
            '<p>hi</p>',
            'hi',
            Campaign::AUDIENCE_IDS,
            [4, 5],
            0,
            0,
            CampaignStatus::DRAFT,
            0,
            0,
            0,
            1,
            '2026-08-17 10:00:00',
            '2026-08-17 10:00:00'
        );

        $row = $campaign->toRow() + ['id' => 9];
        $back = Campaign::fromRow($row);

        self::assertSame('August sale', $back->name);
        self::assertSame([4, 5], $back->audienceIds);
        self::assertSame(9, $back->id);
        self::assertCount(count($campaign->toRow()), $campaign->rowFormats());
    }

    public function testAnOverLongNameIsTruncatedRatherThanRefused(): void
    {
        // MySQL in strict mode rejects an over-length value outright, and a write that
        // failed *after* an audience was resolved is the failure worth avoiding —
        // `Shipping\Shipment`'s reasoning.
        $campaign = new Campaign(str_repeat('n', 500), str_repeat('s', 500));

        self::assertSame(Campaign::MAX_NAME, mb_strlen($campaign->name));
        self::assertSame(Campaign::MAX_SUBJECT, mb_strlen($campaign->subject));
    }

    public function testTheCountsSurviveAPurgeFlag(): void
    {
        $campaign = Campaign::fromRow([
            'name' => 'x', 'subject' => 'y',
            'recipients_total' => 4812, 'recipients_sent' => 4793, 'recipients_failed' => 19,
            'purged_at' => '2026-09-20 00:00:00',
            'status' => CampaignStatus::SENT,
        ]);

        $payload = $campaign->toArray();

        self::assertSame(4812, $payload['recipients']['total']);
        self::assertTrue($payload['recipients']['purged']);
    }

    public function testAZeroDateReadsAsNull(): void
    {
        $campaign = Campaign::fromRow(['name' => 'x', 'subject' => 'y', 'claimed_at' => '0000-00-00 00:00:00']);

        self::assertNull($campaign->claimedAt);
    }

    // ------------------------------------------------------ unsubscribe token --

    public function testTheUnsubscribeTokenRoundTrips(): void
    {
        $token = UnsubscribeToken::mint(77, 'a-site-salt-long-enough');

        self::assertSame(77, UnsubscribeToken::customerIdFrom($token));
        self::assertSame(77, UnsubscribeToken::verify($token, 'a-site-salt-long-enough'));
    }

    public function testAnUnsubscribeTokenIsBoundToItsCustomer(): void
    {
        $seven = explode('.', UnsubscribeToken::mint(7, 'salty-salty-salty'))[1];

        self::assertSame(0, UnsubscribeToken::verify('8.' . $seven, 'salty-salty-salty'));
    }

    /**
     * Namespaced, so §84's tracking token can never validate here. Both are
     * `{id}.{HMAC}` over the same salt, and without a distinct context string a
     * tracking link for order 7 would unsubscribe customer 7.
     */
    public function testATrackingTokenDoesNotUnsubscribeAnybody(): void
    {
        $tracking = \AlgerianCommerce\Tracking\TrackingToken::mint(7, 'nonce-value-here', 'shared-salt');

        self::assertSame(0, UnsubscribeToken::verify($tracking, 'shared-salt'));
    }

    #[DataProvider('malformedUnsubscribeTokens')]
    public function testMalformedUnsubscribeTokensAreRefused(string $token): void
    {
        self::assertSame(0, UnsubscribeToken::customerIdFrom($token));
        self::assertSame(0, UnsubscribeToken::verify($token, 'salty-salty-salty'));
    }

    /** @return array<string, array{string}> */
    public static function malformedUnsubscribeTokens(): array
    {
        $mac = str_repeat('a', UnsubscribeToken::LENGTH);

        return [
            'empty' => [''],
            'sample' => ['sample'],
            'no mac' => ['7.'],
            'short mac' => ['7.' . substr($mac, 0, 6)],
            'non-hex' => ['7.' . str_repeat('z', UnsubscribeToken::LENGTH)],
            'non-numeric id' => ['seven.' . $mac],
            'zero id' => ['0.' . $mac],
        ];
    }

    public function testNoSecretMintsNothing(): void
    {
        self::assertSame('', UnsubscribeToken::mint(7, ''));
        self::assertSame(0, UnsubscribeToken::verify('7.' . str_repeat('a', 32), ''));
    }
}
