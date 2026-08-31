<?php

declare(strict_types=1);

namespace AlgerianCommerce\Tests\Unit;

use AlgerianCommerce\API\ApiException;
use AlgerianCommerce\Campaigns\Campaign;
use AlgerianCommerce\Campaigns\CampaignInput;
use AlgerianCommerce\Campaigns\CampaignStatus;
use AlgerianCommerce\Campaigns\EmailHtml;
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

    // --------------------------------------------- the composer's answers --
    //
    // `body_fields` is the one field this class validates as a *container* and
    // never by name, because its key list belongs to the admin panel's composer
    // form rather than to this API. What is asserted here is exactly that split:
    // an unrecognised key inside the document is accepted (it is the panel's
    // vocabulary), while the shape, the depth, the key names and the size are
    // not negotiable.

    /**
     * The load-bearing one. A key this repository has never heard of must be
     * stored, not refused — otherwise adding a control to the panel's form is a
     * deploy-order bug with no good ordering.
     */
    public function testTheDocumentsOwnKeysAreNotValidated(): void
    {
        $answers = [
            'headline' => 'Soldes d’été',
            'a_control_added_next_month' => ['nested' => ['deeply' => 'yes']],
            'blocks' => [['type' => 'text', 'value' => 'hi'], ['type' => 'button', 'label' => 'Shop']],
            'discount' => 20,
            'enabled' => true,
        ];

        $input = CampaignInput::forCreate([
            'name' => 'x', 'subject' => 'y', 'audience_type' => 'all',
            'body_fields' => $answers,
        ]);

        self::assertSame($answers, $input->get('body_fields'), 'the blob is stored as sent');
    }

    /**
     * `null` clears it, and clearing is a real write rather than an absent field:
     * it is how the panel says "no longer form-composed", which is what makes an
     * undo back to the template survive a reload.
     */
    public function testNullIsAValueRatherThanAnAbsentField(): void
    {
        $input = CampaignInput::forUpdate(['body_fields' => null]);

        self::assertFalse($input->isEmpty(), 'clearing the answers is an update');
        self::assertTrue($input->has('body_fields'));
        self::assertNull($input->get('body_fields'));
    }

    public function testAnAbsentFieldIsNotAWrite(): void
    {
        self::assertFalse(CampaignInput::forUpdate(['subject' => 'z'])->has('body_fields'));
    }

    /** `{}` and `[]` are the same value after decoding, and both mean the empty form. */
    public function testAnEmptyDocumentIsAcceptedAsTheEmptyObject(): void
    {
        $input = CampaignInput::forUpdate(['body_fields' => []]);

        self::assertTrue($input->has('body_fields'));
        self::assertSame([], $input->get('body_fields'));
    }

    #[DataProvider('documentsThatAreNotObjects')]
    public function testOnlyAnObjectIsAccepted(mixed $value): void
    {
        try {
            CampaignInput::forUpdate(['body_fields' => $value]);
            self::fail('accepted a body_fields that is not an object');
        } catch (ApiException $exception) {
            $fields = $exception->details()['fields'] ?? [];

            self::assertArrayHasKey('body_fields', $fields);
            self::assertGreaterThan(20, strlen((string) $fields['body_fields']), 'the refusal must explain itself');
        }
    }

    /** @return list<array{mixed}> */
    public static function documentsThatAreNotObjects(): array
    {
        return [
            // The double-encoded case, which a client will get wrong at least once.
            ['{"headline":"hi"}'],
            [42],
            [true],
            // A non-empty top-level list: the answers are named, so there is
            // nowhere for the names to go.
            [[['type' => 'text']]],
            [['a', 'b']],
        ];
    }

    /**
     * Refused rather than trimmed. `EmailHtml::sanitizeDocument()` drops a branch
     * past the cap because it has nobody to tell; this layer has a caller, and a
     * saved campaign that reads back missing answers is the bug the field exists
     * to prevent.
     */
    public function testADocumentDeeperThanTheCapIsRefusedRatherThanTrimmed(): void
    {
        $document = ['leaf' => 'ok'];

        for ($i = 0; $i <= EmailHtml::MAX_DOCUMENT_DEPTH + 1; $i++) {
            $document = ['nested' => $document];
        }

        try {
            CampaignInput::forUpdate(['body_fields' => $document]);
            self::fail('accepted a document past the depth cap');
        } catch (ApiException $exception) {
            self::assertArrayHasKey('body_fields', $exception->details()['fields'] ?? []);
        }
    }

    public function testADocumentAtTheDepthCapIsAccepted(): void
    {
        $document = ['leaf' => 'ok'];

        for ($i = 0; $i < EmailHtml::MAX_DOCUMENT_DEPTH; $i++) {
            $document = ['nested' => $document];
        }

        self::assertTrue(CampaignInput::forUpdate(['body_fields' => $document])->has('body_fields'));
    }

    /**
     * A *name* containing markup is never a form's answer. Refused rather than
     * rewritten, because a rewritten key is a value the panel can no longer find —
     * the one case where sanitising would be the corrupting choice.
     */
    public function testAFieldNameMayNotBeMarkupOrOverLong(): void
    {
        foreach (['<script>x</script>', str_repeat('k', CampaignInput::MAX_FIELD_KEY + 1)] as $key) {
            try {
                CampaignInput::forUpdate(['body_fields' => [$key => 'value']]);
                self::fail('accepted a bad field name');
            } catch (ApiException $exception) {
                self::assertArrayHasKey('body_fields', $exception->details()['fields'] ?? []);
            }
        }
    }

    /** A nested list's integer keys are indices, not names, and are not checked. */
    public function testAListsIntegerKeysAreNotTreatedAsNames(): void
    {
        $input = CampaignInput::forUpdate(['body_fields' => ['blocks' => ['a', 'b', 'c']]]);

        self::assertSame(['blocks' => ['a', 'b', 'c']], $input->get('body_fields'));
    }

    /**
     * Bounded in bytes, and refused rather than truncated: half a JSON document is
     * not a shorter document, it is one that reads back as no answers at all.
     */
    public function testADocumentOverTheByteCapIsRefused(): void
    {
        $document = ['headline' => str_repeat('a', CampaignInput::MAX_FIELDS_BYTES)];

        self::assertGreaterThan(CampaignInput::MAX_FIELDS_BYTES, strlen((string) json_encode($document)));

        try {
            CampaignInput::forUpdate(['body_fields' => $document]);
            self::fail('accepted an oversize document');
        } catch (ApiException $exception) {
            self::assertStringContainsString(
                (string) CampaignInput::MAX_FIELDS_BYTES,
                (string) ($exception->details()['fields']['body_fields'] ?? ''),
                'the refusal must name the limit'
            );
        }
    }

    /**
     * Bytes rather than characters, because the column is sized in bytes: a
     * document of Arabic answers that passed an `mb_strlen` check at this number
     * would be twice its real size on disk.
     */
    public function testTheCapIsBytesNotCharacters(): void
    {
        // Two bytes per character in UTF-8, so this is just over the cap in bytes
        // and comfortably under it in characters.
        $document = ['headline' => str_repeat('ا', (int) (CampaignInput::MAX_FIELDS_BYTES / 2))];
        $encoded = (string) json_encode($document, Campaign::FIELDS_JSON_FLAGS);

        self::assertLessThan(CampaignInput::MAX_FIELDS_BYTES, mb_strlen($encoded), 'under the cap in characters');
        self::assertGreaterThan(CampaignInput::MAX_FIELDS_BYTES, strlen($encoded), 'over it in bytes');

        $this->expectException(ApiException::class);

        CampaignInput::forUpdate(['body_fields' => $document]);
    }

    /**
     * The encoding flags are load-bearing on the bound, not cosmetic.
     *
     * PHP's default escapes every non-ASCII character to a six-byte `\uXXXX`, so
     * measuring the default form would make the same 64 KiB limit refuse an Arabic
     * newsletter at roughly a sixth the length of a French one — an unequal limit
     * in a bilingual shop, arrived at by an encoder's default rather than by
     * anyone's decision. The check and the column must use the same flags, or the
     * bound is enforced against a form the column never sees.
     */
    public function testArabicAnswersAreNotChargedSixBytesACharacter(): void
    {
        $document = ['headline' => str_repeat('ا', 20_000)];

        self::assertGreaterThan(
            CampaignInput::MAX_FIELDS_BYTES,
            strlen((string) json_encode($document)),
            'escaped, this document would be refused'
        );

        // Unescaped it is well inside the bound, and is therefore accepted.
        self::assertTrue(CampaignInput::forUpdate(['body_fields' => $document])->has('body_fields'));
    }

    /**
     * The answers must be smaller than the HTML they generate. A blob allowed to
     * approach `MAX_BODY` would no longer be being used as a set of answers.
     */
    public function testTheAnswersAreBoundedWellBelowTheHtmlTheyGenerate(): void
    {
        self::assertLessThan(CampaignInput::MAX_BODY, CampaignInput::MAX_FIELDS_BYTES);
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

    /**
     * The distinction the whole field turns on, asserted at the storage boundary.
     *
     * `null` is "no answers were ever recorded" and sends the panel to the HTML
     * editor over whatever `body_html` holds. `{}` is "the form was used and every
     * answer is blank" and sends the panel to the form. Collapsing them lets a
     * reopened campaign regenerate empty HTML over a body somebody wrote by hand.
     */
    public function testNullAndTheEmptyObjectAreDifferentValuesThroughTheRow(): void
    {
        $absent = new Campaign('x', 'y', bodyFields: null);
        $blank = new Campaign('x', 'y', bodyFields: []);

        self::assertNull($absent->toRow()['body_fields']);
        self::assertSame('{}', $blank->toRow()['body_fields'], 'not "[]" — the column holds objects');

        self::assertNull(Campaign::fromRow($absent->toRow())->bodyFields);
        self::assertSame([], Campaign::fromRow($blank->toRow())->bodyFields);

        self::assertNull($absent->toArray()['body_fields']);
        self::assertEquals(new \stdClass(), $blank->toArray()['body_fields'], 'reaches the panel as {}');
    }

    /** A campaign written before migration 014 has no column value at all. */
    public function testACampaignThatPredatesTheColumnReadsBackAsNull(): void
    {
        $before = Campaign::fromRow(['name' => 'x', 'subject' => 'y', 'body_html' => '<p>hand-written</p>']);

        self::assertNull($before->bodyFields);
        self::assertNull($before->toArray()['body_fields']);
        self::assertSame('<p>hand-written</p>', $before->toArray()['body_html'], 'and its body is untouched');
    }

    /**
     * An unreadable column fails towards `null` rather than `{}` — the opposite
     * direction from `Segment`, and for the same underlying rule: fail to the
     * answer that destroys nothing. Here `{}` would tell the panel to regenerate
     * empty HTML over a body that is still perfectly good.
     */
    public function testAnUnreadableDocumentReadsAsNoAnswersRatherThanBlankAnswers(): void
    {
        foreach (['not json at all', '{"unclosed":', '"a string"', '42'] as $stored) {
            $campaign = Campaign::fromRow(['name' => 'x', 'subject' => 'y', 'body_fields' => $stored]);

            self::assertNull($campaign->bodyFields, "\"{$stored}\" must not read as blank answers");
        }
    }

    public function testTheDocumentSurvivesTheRowRoundTrip(): void
    {
        $answers = ['headline' => 'Soldes', 'blocks' => [['type' => 'text', 'value' => 'hi']], 'n' => 3];
        $campaign = new Campaign('x', 'y', bodyFields: $answers);

        self::assertSame($answers, Campaign::fromRow($campaign->toRow())->bodyFields);
    }

    /**
     * `with()` uses `array_key_exists`, so a PATCH carrying an explicit null
     * clears the answers instead of being read as "not supplied" — which is what
     * makes an undo back to the template survive a reload.
     */
    public function testAnExplicitNullClearsTheAnswersWhileAnAbsentKeyKeepsThem(): void
    {
        $campaign = new Campaign('x', 'y', bodyFields: ['headline' => 'Soldes']);

        self::assertNull($campaign->with(['body_fields' => null], 'now')->bodyFields);
        self::assertSame(['headline' => 'Soldes'], $campaign->with(['subject' => 'z'], 'now')->bodyFields);
        self::assertSame(['headline' => 'New'], $campaign->with(['body_fields' => ['headline' => 'New']], 'now')->bodyFields);
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
