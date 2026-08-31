<?php

declare(strict_types=1);

namespace AlgerianCommerce\Campaigns;

use AlgerianCommerce\API\ApiException;
use AlgerianCommerce\Audit\AuditLogger;
use AlgerianCommerce\Core\Logger;
use AlgerianCommerce\Notifications\MailTransport;
use AlgerianCommerce\Permissions\Capabilities;
use AlgerianCommerce\Permissions\Permissions;
use AlgerianCommerce\Settings\SettingsRepository;

/**
 * Campaign business rules — roadmap §85, docs/PLAN.md §27.
 *
 * ## Nothing is sent on a request path
 *
 * `send()` resolves the audience, freezes the recipient rows, claims the campaign and
 * returns. `drain()` does the sending, from `wp algerian-commerce send-campaigns` or
 * the cron beside it. §62b settled this argument for conversions, §63 for rollups and
 * §29 for transactional mail, and it is not close here: a 5,000-recipient send on a
 * request path is 5,000 SMTP conversations inside one HTTP request.
 *
 * **And the second reason, which is the one that decides the two tables:** a
 * 5,000-recipient campaign sharing `ac_notifications` delays every order confirmation
 * behind it. A customer waiting to learn their order was received is not going to
 * wait out a newsletter.
 *
 * ## Two capabilities, and the second one is on the send
 *
 * `ac_manage_marketing` covers drafting, templates and segments — **no new
 * capability**, matching §61's media precedent and §63's analytics one.
 *
 * **Sending additionally requires `ac_manage_customers`.** §63 set this pattern:
 * money in analytics additionally requires `ac_manage_orders`, the capability that
 * already reads an order's total. A campaign discloses nothing, but it *reaches* every
 * customer record in the shop, and the person permitted to mail the customer list
 * should be the person already trusted with the customer list. Reading the recipient
 * rows needs both for the same reason — those rows are the customer list, in the form
 * of addresses.
 *
 * A Marketing Manager holds `ac_manage_marketing` and **not** `ac_manage_customers`
 * (roadmap §45's matrix), so this is a live restriction rather than a theoretical
 * one: that role drafts and previews and cannot send.
 *
 * ## Every send is an audit event carrying the count, never the list
 */
final class CampaignService
{
    /** Rows the drain attempts per invocation, unless told otherwise. */
    public const DEFAULT_BATCH = 50;

    /** Days after completion before recipient addresses are purged. */
    public const PURGE_AFTER_DAYS = 30;

    public function __construct(
        private readonly CampaignRepository $campaigns,
        private readonly RecipientRepository $recipients,
        private readonly SegmentRepository $segments,
        private readonly AudienceResolver $audience,
        private readonly Consent $consent,
        private readonly SettingsRepository $settings,
        private readonly MailTransport $mail,
        private readonly AuditLogger $audit,
        private readonly Logger $logger
    ) {
    }

    // ------------------------------------------------------------- campaigns --

    /**
     * @param array<string, mixed> $criteria
     * @return array{items: list<Campaign>, total: int}
     */
    public function list(array $criteria): array
    {
        Permissions::assert(Capabilities::MANAGE_MARKETING);

        $filters = [
            'status' => (string) ($criteria['status'] ?? ''),
            'search' => (string) ($criteria['search'] ?? ''),
            'segment_id' => (int) ($criteria['segment_id'] ?? 0),
        ];

        return [
            'items' => $this->campaigns->paginate(
                $filters,
                (int) ($criteria['page'] ?? 1),
                (int) ($criteria['per_page'] ?? 20),
                (string) ($criteria['orderby'] ?? 'created_at'),
                (string) ($criteria['order'] ?? 'desc')
            ),
            'total' => $this->campaigns->count($filters),
        ];
    }

    public function get(int $id): Campaign
    {
        Permissions::assert(Capabilities::MANAGE_MARKETING);

        return $this->requireCampaign($id);
    }

    /** @param array<string, mixed> $payload */
    public function create(array $payload): Campaign
    {
        Permissions::assert(Capabilities::MANAGE_MARKETING);

        $input = CampaignInput::forCreate($payload);
        $now = self::now();

        $campaign = new Campaign(
            (string) $input->get('name'),
            (string) $input->get('subject'),
            $this->cleanHtml((string) ($input->get('body_html') ?? '')),
            EmailHtml::sanitizeText((string) ($input->get('body_text') ?? '')),
            (string) ($input->get('audience_type') ?? Campaign::AUDIENCE_SEGMENT),
            (array) ($input->get('audience_ids') ?? []),
            (int) ($input->get('segment_id') ?? 0),
            (int) ($input->get('template_id') ?? 0),
            CampaignStatus::DRAFT,
            0,
            0,
            0,
            get_current_user_id(),
            $now,
            $now,
            // Named, so the four defaulted parameters between `updated_at` and this
            // one do not have to be restated as nulls — see `Campaign::$bodyFields`
            // for why it sits at the end of the signature at all.
            bodyFields: $this->cleanFields($input->get('body_fields'))
        );

        $this->guardReferences($campaign);

        $id = $this->campaigns->insert($campaign);

        if ($id === null) {
            throw ApiException::internal('The campaign could not be saved.');
        }

        $this->audit->record('campaign.created', 'campaign', $id, [
            'name' => $campaign->name,
            'audience_type' => $campaign->audienceType,
            'segment_id' => $campaign->segmentId,
        ]);

        return $this->campaigns->find($id) ?? $campaign;
    }

    /** @param array<string, mixed> $payload */
    public function update(int $id, array $payload): Campaign
    {
        Permissions::assert(Capabilities::MANAGE_MARKETING);

        $existing = $this->requireCampaign($id);
        $input = CampaignInput::forUpdate($payload);

        if ($input->isEmpty()) {
            throw ApiException::invalidRequest('No supported fields were provided.');
        }

        /*
         * **A campaign that has begun going out cannot be edited.** Some of its
         * recipients already have the old message, and the shop would afterwards have
         * no record of which version anybody received. Refused with the status named
         * so a client can tell this apart from a validation error.
         */
        if (!$existing->isEditable()) {
            throw ApiException::conflict('A campaign can only be edited while it is a draft.', [
                'status' => $existing->status,
            ]);
        }

        $fields = $input->fields;

        if (isset($fields['body_html'])) {
            $fields['body_html'] = $this->cleanHtml((string) $fields['body_html']);
        }

        if (isset($fields['body_text'])) {
            $fields['body_text'] = EmailHtml::sanitizeText((string) $fields['body_text']);
        }

        /*
         * `array_key_exists`, not `isset`: `null` is a value this field is set
         * *to* — it is how the panel says "no longer form-composed", which is what
         * lets an undo back to the template survive a reload — and `isset` reads a
         * deliberate null as an absent key. `Campaign::with()` makes the same
         * distinction one layer down for the same reason.
         */
        if (array_key_exists('body_fields', $fields)) {
            $fields['body_fields'] = $this->cleanFields($fields['body_fields']);
        }

        $updated = $existing->with($fields, self::now());

        $this->guardReferences($updated);

        if (!$this->campaigns->update($updated)) {
            throw ApiException::internal('The campaign could not be updated.');
        }

        $this->audit->record('campaign.updated', 'campaign', $id, ['fields' => array_keys($input->fields)]);

        return $this->campaigns->find($id) ?? $updated;
    }

    public function delete(int $id): void
    {
        Permissions::assert(Capabilities::MANAGE_MARKETING);

        $campaign = $this->requireCampaign($id);

        /*
         * A campaign that has sent anything is kept, and cancelled instead. It is the
         * record of mail that left the building — the same rule that keeps a shipment
         * row after a parcel is cancelled, and the reason `ac_campaigns` carries
         * counts that survive the purge.
         */
        if ($campaign->status !== CampaignStatus::DRAFT) {
            throw ApiException::conflict('Only a draft can be deleted. Cancel the campaign instead.', [
                'status' => $campaign->status,
            ]);
        }

        if (!$this->campaigns->delete($id)) {
            throw ApiException::internal('The campaign could not be deleted.');
        }

        $this->audit->record('campaign.deleted', 'campaign', $id, ['name' => $campaign->name]);
    }

    public function cancel(int $id): Campaign
    {
        Permissions::assert(Capabilities::MANAGE_MARKETING);

        $campaign = $this->requireCampaign($id);

        if (!CampaignStatus::accepts($campaign->status, CampaignStatus::CANCELLED)) {
            throw ApiException::conflict("A campaign in \"{$campaign->status}\" cannot be cancelled.", [
                'status' => $campaign->status,
                'allowed' => CampaignStatus::allowedFrom($campaign->status),
            ]);
        }

        $now = self::now();
        $this->campaigns->setStatus($id, CampaignStatus::CANCELLED, $now, true);

        // Whatever had not gone out does not go out. The rows are left in place so
        // "who got this before we stopped" is still answerable until the purge.
        $this->audit->record('campaign.cancelled', 'campaign', $id, [
            'was' => $campaign->status,
            'remaining' => $this->recipients->remaining($id),
        ]);

        return $this->campaigns->find($id) ?? $campaign;
    }

    // ----------------------------------------------------------- the message --

    /**
     * The rendered message for one sample recipient — §85's `GET /preview`.
     *
     * It exists because "the first thing anybody does with a template is get it
     * wrong, and the second thing is send it to five thousand people". The merge
     * fields are checked here, before the test send rather than after it.
     *
     * @return array<string, mixed>
     */
    public function preview(int $id): array
    {
        Permissions::assert(Capabilities::MANAGE_MARKETING);

        $campaign = $this->requireCampaign($id);
        $message = $this->compose($campaign);

        $rendered = TemplateRenderer::render(
            $message['subject'],
            $message['html'],
            $message['text'],
            $this->sampleContext()
        );

        return [
            'campaign_id' => $id,
            'subject' => $rendered['subject'],
            'html' => $rendered['html'],
            'text' => $rendered['text'],
            // Reported rather than silently dropped — §61's precedent. An unknown
            // token renders empty, and this is the only way the author finds out.
            'unknown_tokens' => $rendered['unknown_tokens'],
            'unsubscribe_appended' => $rendered['unsubscribe_appended'],
            'sample_recipient' => $this->sampleContext(),
            'audience_count' => $this->audienceCount($campaign),
        ];
    }

    /**
     * One copy to a named address — §85's `POST /test`.
     *
     * Sent **synchronously**, unlike a campaign, and that is not an inconsistency:
     * one message is not a queue, and an admin who clicked "send me a test" and was
     * told to wait five minutes will click it four more times. `PasswordResetService`
     * made the same call for the same reason.
     *
     * **It writes no recipient row and touches no count.** A test that consumed a
     * real recipient's slot would make the campaign's own record wrong, and a test
     * that incremented `recipients_sent` would make the figure that survives the
     * purge a lie.
     *
     * @return array<string, mixed>
     */
    public function test(int $id, string $to): array
    {
        Permissions::assert(Capabilities::MANAGE_MARKETING);

        $campaign = $this->requireCampaign($id);

        if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            throw ApiException::invalidRequest('The test send is invalid.', [
                'fields' => ['to' => 'Must be an email address.'],
            ]);
        }

        $this->requireWorkingMail();

        $message = $this->compose($campaign);
        $rendered = TemplateRenderer::render(
            $message['subject'],
            $message['html'],
            $message['text'],
            $this->sampleContext()
        );

        $sent = $this->deliver($to, $rendered);

        $this->audit->record('campaign.test_sent', 'campaign', $id, [
            // The address is recorded because a test send is an operator action on
            // an address the operator typed, not a customer's — which is the
            // distinction that keeps the customer list out of the audit trail.
            'to' => $to,
            'accepted' => $sent,
        ]);

        return [
            'sent' => $sent,
            'to' => $to,
            'subject' => $rendered['subject'],
            'unknown_tokens' => $rendered['unknown_tokens'],
        ];
    }

    // -------------------------------------------------------------- the send --

    /**
     * Resolve, freeze, claim, return.
     *
     * @return array<string, mixed>
     */
    public function send(int $id): array
    {
        // Both capabilities. See the class docblock: a campaign reaches every
        // customer record in the shop.
        Permissions::assert(Capabilities::MANAGE_MARKETING);
        Permissions::assert(Capabilities::MANAGE_CUSTOMERS);

        $campaign = $this->requireCampaign($id);

        if ($campaign->status !== CampaignStatus::DRAFT) {
            throw ApiException::conflict('That campaign has already been sent or cancelled.', [
                'status' => $campaign->status,
            ]);
        }

        $this->requireWorkingMail();

        $message = $this->compose($campaign);

        if (trim($message['html']) === '' && trim($message['text']) === '') {
            throw ApiException::conflict('That campaign has no message body to send.', ['campaign_id' => $id]);
        }

        $segment = $campaign->segmentId > 0 ? $this->segments->find($campaign->segmentId) : null;
        $recipients = $this->audience->resolve($campaign, $segment);

        if ($recipients === []) {
            /*
             * Refused rather than marked sent. An audience of nobody is almost always
             * a segment that is wrong or a customer list with no consent yet, and a
             * campaign silently marked `sent` to zero people is the failure a shop
             * discovers when it asks why nobody replied.
             */
            throw ApiException::conflict('That audience currently matches nobody, so there is nothing to send.', [
                'audience_type' => $campaign->audienceType,
                'segment_id' => $campaign->segmentId,
                'hint' => 'Only customers who have given marketing consent are ever included.',
            ]);
        }

        $now = self::now();

        // **The claim before the freeze**, so a second request that lost the race
        // writes nothing at all rather than colliding row by row.
        if (!$this->campaigns->claimForSending($id, $now)) {
            throw ApiException::conflict('That campaign is already being sent.', ['campaign_id' => $id]);
        }

        $frozen = $this->recipients->freeze($id, $recipients, $now);
        $counts = $this->recipients->counts($id);

        $this->campaigns->setCounts($id, $counts['total'], $counts['sent'], $counts['failed'], $now);

        /*
         * **The count, never the list.** §85 is explicit, and the reason is that an
         * audit row outlives the purge: a trail carrying five thousand addresses
         * would reintroduce the PII the purge exists to remove, in a table that is
         * append-only by design and therefore cannot drop them.
         */
        $this->audit->record('campaign.sent', 'campaign', $id, [
            'recipients' => $counts['total'],
            'frozen' => $frozen,
            'audience_type' => $campaign->audienceType,
            'segment_id' => $campaign->segmentId,
        ]);

        $this->logger->info('A campaign was queued', ['campaign_id' => $id, 'recipients' => $counts['total']]);

        return [
            'campaign_id' => $id,
            'status' => CampaignStatus::SENDING,
            'recipients' => $counts['total'],
            'next' => [
                'action' => 'drain',
                'command' => 'wp algerian-commerce send-campaigns',
            ],
        ];
    }

    /**
     * Who a campaign was sent to — §85's "who got this?".
     *
     * Both capabilities, because these rows *are* the customer list in the form of
     * addresses. Available until the purge, after which the counts on the campaign
     * are what remains.
     *
     * **`total` follows the filter, and once did not.** Measured 2026-08-21:
     * `?status=failed` returned **0 rows with `meta.total: 9`** — the rows were
     * filtered by `paginate()` and the total was the unfiltered count from
     * `counts()`, so a paginating client showed "9 recipients" above an empty
     * table and offered pages that do not exist. The drain's own warning sends
     * an operator to exactly that URL — *"see GET /campaigns/{id}/recipients
     * ?status=failed"* — so the one filter this route exists to serve was the
     * one that reported wrong.
     *
     * `counts()` already returns the per-status breakdown beside the total, so
     * the filtered total costs no query: it is a key lookup on a result this
     * method was already fetching.
     *
     * @param array<string, mixed> $criteria
     * @return array{items: list<array<string, mixed>>, total: int, purged: bool}
     */
    public function recipientList(int $id, array $criteria): array
    {
        Permissions::assert(Capabilities::MANAGE_MARKETING);
        Permissions::assert(Capabilities::MANAGE_CUSTOMERS);

        $campaign = $this->requireCampaign($id);
        $counts = $this->recipients->counts($id);
        $status = (string) ($criteria['status'] ?? '');

        $rows = $this->recipients->paginate(
            $id,
            ['status' => $status],
            (int) ($criteria['page'] ?? 1),
            (int) ($criteria['per_page'] ?? 20)
        );

        return [
            'items' => array_map(static fn (array $row): array => [
                'id' => (int) $row['id'],
                'customer_id' => (int) $row['customer_id'],
                'email' => (string) $row['email'],
                'status' => (string) $row['status'],
                'attempts' => (int) $row['attempts'],
                'last_error' => (string) ($row['last_error'] ?? ''),
                'sent_at' => (string) ($row['sent_at'] ?? ''),
            ], $rows),
            /*
             * The filtered count when a status is asked for, the whole count
             * otherwise. `array_key_exists` rather than `??`, so a status the
             * enum gains but `counts()` does not yet initialise reports zero
             * rather than silently falling back to the unfiltered total — which
             * is the failure this is fixing, one vocabulary change later.
             */
            'total' => $status !== '' && array_key_exists($status, $counts)
                ? (int) $counts[$status]
                : (int) $counts['total'],
            // True once the addresses are gone; the campaign's own counts still say
            // what happened.
            'purged' => $campaign->toArray()['recipients']['purged'],
        ];
    }

    // ------------------------------------------------------------- the drain --

    /**
     * Send what is waiting.
     *
     * **The rate cap is two knobs and neither is a sleep by default.**
     * `$batch` is how many rows one invocation attempts, and the deployment's
     * scheduler interval turns that into a rate — 50 rows a minute is 3,000 an hour,
     * which is inside every SMTP provider's tolerance. `$pauseMicroseconds` adds a
     * minimum gap between sends for a provider that throttles harder than that, and
     * it is **off by default** because a `usleep` inside a WP-Cron request holds that
     * request open for the duration.
     *
     * That is the answer to §85's "an SMTP provider will throttle or cut off a sender
     * that bursts": the per-recipient rows are what make a resume correct, and the
     * batch ceiling is what keeps a resume from being needed.
     *
     * @return array{campaigns: int, attempted: int, sent: int, failed: int, completed: int, purged: int}
     */
    public function drain(int $batch = self::DEFAULT_BATCH, int $campaignId = 0, int $pauseMicroseconds = 0): array
    {
        $result = ['campaigns' => 0, 'attempted' => 0, 'sent' => 0, 'failed' => 0, 'completed' => 0, 'purged' => 0];

        $queue = $campaignId > 0
            ? array_filter([$this->campaigns->find($campaignId)], static fn (?Campaign $c): bool => $c !== null
                && $c->status === CampaignStatus::SENDING)
            : $this->campaigns->sending();

        foreach ($queue as $campaign) {
            $result['campaigns']++;
            $remainingBudget = max(0, $batch - $result['attempted']);

            if ($remainingBudget === 0) {
                break;
            }

            $this->drainOne($campaign, $remainingBudget, $pauseMicroseconds, $result);
        }

        $result['purged'] = $this->purge();

        return $result;
    }

    /**
     * Drop the addresses of campaigns that finished long enough ago.
     *
     * §85's PII rule, and the counts on `ac_campaigns` are what make it possible: a
     * shop can still say a campaign reached 4,812 people and can no longer say who
     * they were.
     *
     * @return int rows removed
     */
    public function purge(int $olderThanDays = self::PURGE_AFTER_DAYS): int
    {
        $now = self::now();
        $removed = 0;

        foreach ($this->campaigns->purgeable($olderThanDays, $now) as $id) {
            $rows = $this->recipients->purge($id);
            $this->campaigns->markPurged($id, $now);
            $removed += $rows;

            if ($rows > 0) {
                $this->audit->record('campaign.recipients_purged', 'campaign', $id, ['rows' => $rows]);
            }
        }

        return $removed;
    }

    /**
     * Withdraw marketing consent from a signed link — §85's one-click rule.
     *
     * **Public, idempotent, and no login.** Requiring an account to unsubscribe is how
     * a shop's domain ends up on a blocklist, and the second click from a second
     * device must answer exactly as the first did.
     *
     * A token that does not verify gets the *same* answer as one that does. That is
     * deliberate: this route is unauthenticated, so distinguishing them would make it
     * an oracle for "is this a customer id", and there is nothing a legitimate holder
     * of a link learns from the difference.
     *
     * @return array<string, mixed>
     */
    public function unsubscribe(string $token): array
    {
        $customerId = UnsubscribeToken::verify($token, self::secret());

        if ($customerId <= 0) {
            $this->logger->info('An unsubscribe token did not verify', [
                'presented_length' => strlen(trim($token)),
            ]);

            return ['unsubscribed' => true, 'message' => self::UNSUBSCRIBED];
        }

        $this->consent->set($customerId, false, 'unsubscribe_link');

        return ['unsubscribed' => true, 'message' => self::UNSUBSCRIBED];
    }

    /** The one answer every unsubscribe request gets. */
    private const UNSUBSCRIBED = 'You will no longer receive marketing email from this shop.';

    // ------------------------------------------------------------- internals --

    /**
     * Work through one campaign's pending rows.
     *
     * @param array{campaigns: int, attempted: int, sent: int, failed: int, completed: int, purged: int} $result
     */
    private function drainOne(Campaign $campaign, int $budget, int $pauseMicroseconds, array &$result): void
    {
        $rows = $this->recipients->pending($campaign->id, $budget);
        $message = $this->compose($campaign);

        foreach ($rows as $row) {
            $rowId = (int) $row['id'];
            $email = (string) $row['email'];
            $customerId = (int) $row['customer_id'];

            /*
             * **Consent is re-checked at send time, not only at freeze time.** A
             * customer who unsubscribed after the audience was resolved — quite
             * possibly from the first batch of this same campaign — must not be
             * mailed by the second batch. The frozen row is what the admin
             * previewed and counted; consent is the one fact that is allowed to
             * have changed since.
             */
            if (!Consent::has($customerId)) {
                $this->recipients->markFailed($rowId, 'The customer withdrew marketing consent.', false);
                $result['failed']++;

                continue;
            }

            $context = json_decode((string) ($row['context'] ?? ''), true);
            $context = is_array($context) ? array_map('strval', $context) : [];

            $rendered = TemplateRenderer::render(
                $message['subject'],
                $message['html'],
                $message['text'],
                $this->recipientContext((string) $row['name'], $customerId, $context)
            );

            $result['attempted']++;

            if ($this->deliver($email, $rendered)) {
                $this->recipients->markSent($rowId);
                $result['sent']++;
            } else {
                $this->recipients->markFailed($rowId, 'wp_mail() did not accept the message.', true);
                $result['failed']++;
            }

            if ($pauseMicroseconds > 0) {
                usleep($pauseMicroseconds);
            }
        }

        $counts = $this->recipients->counts($campaign->id);
        $now = self::now();

        $this->campaigns->setCounts($campaign->id, $counts['total'], $counts['sent'], $counts['failed'], $now);

        if ($this->recipients->remaining($campaign->id) === 0) {
            $this->campaigns->setStatus($campaign->id, CampaignStatus::SENT, $now, true);
            $result['completed']++;

            $this->audit->record('campaign.completed', 'campaign', $campaign->id, [
                'sent' => $counts['sent'],
                'failed' => $counts['failed'],
            ]);
        }
    }

    /**
     * Hand one message to WordPress's mailer, multipart.
     *
     * **Both parts, always.** A text-only client shows a blank message given
     * HTML-only mail, and HTML-only mail scores worse with spam filters. The text
     * part is authored rather than stripped from the HTML — see `TemplateRenderer`.
     *
     * The recipient is **not logged**, on success or failure. `EmailChannel` already
     * declines to log one, with the reason: "which customer was emailed about which
     * order" is exactly the PII docs/SECURITY.md keeps out of logs, and a campaign
     * drain writing five thousand addresses into a log file would be the same
     * mistake at scale.
     *
     * @param array{subject: string, html: string, text: string} $rendered
     */
    private function deliver(string $to, array $rendered): bool
    {
        $headers = ['Content-Type: text/html; charset=UTF-8'];

        /*
         * WordPress's `wp_mail()` sends one content type. The text part is attached
         * as an alternative body through PHPMailer, which is what makes the message
         * genuinely multipart — a filter registered for this one send and removed
         * immediately, so no other mail in the process inherits it.
         */
        $alt = $rendered['text'];
        $attach = static function (mixed $mailer) use ($alt): void {
            if (is_object($mailer) && property_exists($mailer, 'AltBody')) {
                $mailer->AltBody = $alt;
            }
        };

        add_action('phpmailer_init', $attach, 20);

        try {
            return (bool) wp_mail($to, $rendered['subject'], $rendered['html'], $headers);
        } finally {
            remove_action('phpmailer_init', $attach, 20);
        }
    }

    /**
     * The subject and both bodies, from the template or from the campaign itself.
     *
     * A campaign's own `body_html` wins over its template, because that is what an
     * admin edited most recently; a campaign with only a `template_id` inherits the
     * template's. Either way the result is what gets rendered, and it is read fresh
     * on every drain batch — so a template corrected mid-send fixes the rest of the
     * send, which is the direction that helps.
     *
     * @return array{subject: string, html: string, text: string}
     */
    private function compose(Campaign $campaign): array
    {
        $subject = $campaign->subject;
        $html = $campaign->bodyHtml;
        $text = $campaign->bodyText;

        if ($campaign->templateId > 0) {
            $template = EmailTemplates::read($campaign->templateId);

            if ($template !== null) {
                $subject = $subject !== '' ? $subject : (string) $template['subject'];
                $html = trim($html) !== '' ? $html : (string) $template['body_html'];
                $text = trim($text) !== '' ? $text : (string) $template['body_text'];
            }
        }

        return ['subject' => $subject, 'html' => $html, 'text' => $text];
    }

    /**
     * The merge values for one real recipient.
     *
     * @param array<string, string> $frozen
     * @return array<string, string>
     */
    private function recipientContext(string $name, int $customerId, array $frozen): array
    {
        $first = trim(explode(' ', trim($name))[0] ?? '');

        return [
            'customer_name' => $name,
            'first_name' => $first,
            'shop_name' => $this->shopName(),
            'order_number' => (string) ($frozen['order_number'] ?? ''),
            'unsubscribe_url' => $this->unsubscribeUrl($customerId),
        ];
    }

    /**
     * A believable sample, for a preview and a test send.
     *
     * Deliberately not a real customer. A preview that used one would put a real
     * name and a real unsubscribe link — theirs, not the admin's — into an admin
     * screen, and a test send would mail an admin a working link to somebody else's
     * consent.
     *
     * @return array<string, string>
     */
    private function sampleContext(): array
    {
        return [
            'customer_name' => 'Amina Belkacem',
            'first_name' => 'Amina',
            'shop_name' => $this->shopName(),
            'order_number' => '1234',
            /*
             * A real base with a fake token, never a real one. A preview that used a
             * real customer's link would put their consent one click away inside an
             * admin screen and, worse, inside a test send to whatever address the
             * admin typed. It has to be non-empty so the preview shows the footer the
             * renderer appends — which is the thing an author is checking for.
             */
            'unsubscribe_url' => $this->unsubscribeBase() . '?token=sample',
        ];
    }

    /**
     * The one-click link.
     *
     * Points at the **storefront** when §71 knows where it is, and at this API's own
     * public route otherwise. That fallback is the difference from §84's tracking
     * link, and it is deliberate: a tracking page needs a storefront to render it, so
     * §84 sends no link at all rather than one on the admin domain — but an
     * unsubscribe *is* a single API call with no page behind it, and a mandatory link
     * that is sometimes absent is worse than one on an unlovely domain. A campaign
     * with no working unsubscribe link is how a sending domain gets blocklisted.
     */
    private function unsubscribeUrl(int $customerId): string
    {
        $token = UnsubscribeToken::mint($customerId, self::secret());

        if ($token === '') {
            return '';
        }

        return $this->unsubscribeBase() . '?' . http_build_query(['token' => $token]);
    }

    private function unsubscribeBase(): string
    {
        $storefront = $this->storefrontUrl();

        if ($storefront !== '') {
            return rtrim($storefront, '/') . '/marketing/unsubscribe';
        }

        return rest_url(\AlgerianCommerce\REST_NAMESPACE . '/marketing/unsubscribe');
    }

    private function storefrontUrl(): string
    {
        $stored = $this->settings->stored();

        return trim((string) ($stored['store']['storefront_url'] ?? ''));
    }

    private function shopName(): string
    {
        $name = (string) get_option('blogname', '');

        return $name !== '' ? $name : 'The shop';
    }

    private function audienceCount(Campaign $campaign): ?int
    {
        // The count reads the customer list, so it is reported only to a caller who
        // may read the customer list. A Marketing Manager sees `null` and can still
        // draft, preview and test — which is exactly the split §85 asks for.
        if (!Permissions::can(Capabilities::MANAGE_CUSTOMERS)) {
            return null;
        }

        $segment = $campaign->segmentId > 0 ? $this->segments->find($campaign->segmentId) : null;

        return $this->audience->countFor($campaign, $segment);
    }

    /**
     * A campaign must not name a template or a segment that does not exist.
     *
     * Checked at write time rather than at send time, because a campaign whose
     * audience cannot be resolved is one an admin drafts, walks away from, and
     * discovers is unsendable when the offer has expired.
     */
    private function guardReferences(Campaign $campaign): void
    {
        if ($campaign->templateId > 0 && EmailTemplates::read($campaign->templateId) === null) {
            throw ApiException::invalidRequest('The campaign is invalid.', [
                'fields' => ['template_id' => 'No email template with that id.'],
            ]);
        }

        if ($campaign->segmentId > 0 && $this->segments->find($campaign->segmentId) === null) {
            throw ApiException::invalidRequest('The campaign is invalid.', [
                'fields' => ['segment_id' => 'No segment with that id.'],
            ]);
        }
    }

    /**
     * Refuse before minting an audience, exactly as `PasswordResetService` refuses
     * before minting a token.
     *
     * §85's deliverability section is a deployment concern and this is the one part of
     * it that belongs in code: a shop with no mail transport would otherwise write
     * five thousand recipient rows, fail every one of them, and look like a broken
     * feature rather than an unconfigured one.
     *
     * @throws ApiException 503
     */
    private function requireWorkingMail(): void
    {
        if ($this->mail->isConfigured()) {
            return;
        }

        throw new ApiException(
            'mail_not_configured',
            'This shop cannot send email yet, so a campaign cannot be sent.',
            503,
            ['fix' => 'Set SMTP_HOST in .env, then check with: wp algerian-commerce mail-check']
        );
    }

    private function cleanHtml(string $html): string
    {
        return EmailHtml::sanitize($html);
    }

    /**
     * The composer's answers, with every markup-shaped leaf run through the same
     * allowlist `body_html` gets.
     *
     * ## Why it is sanitised here and not in `CampaignInput`
     *
     * The same split `body_html` already uses, and for the same two reasons.
     * `CampaignInput` is pure so its rules can be unit-tested without WordPress,
     * and `EmailHtml::sanitize()` **returns the empty string when `wp_kses` is
     * absent** — deliberately, on the argument that a sanitiser which silently
     * does nothing is worse than one that is missing. Calling it from the pure
     * class would mean every markup-shaped answer becoming `''` in a unit test.
     * So: the input object decides whether a document is acceptable, this decides
     * what its contents are allowed to be, exactly as `cleanHtml()` does one line
     * above.
     *
     * ## What this is defending, since it is not the email
     *
     * The email is already safe without this and it is worth being precise about
     * why: `compose()` reads `body_html` and the template's, `TemplateRenderer`
     * takes strings, and **no code path anywhere renders `body_fields`** — it is
     * written, stored and read back, and that is all. Nothing here needs this call
     * in order for `EmailHtml::ALLOWED` to hold over what is mailed.
     *
     * What it defends is the *next* renderer, and the next one is the panel's.
     * The blob exists to be handed to a generator that interpolates it into HTML,
     * and that generator lives in another repository, is being written right now,
     * and will be rewritten again. An answer of `<script>…</script>` pasted into
     * generated markup fires in an admin's own browser, in a live preview, before
     * a save has happened and therefore before `cleanHtml()` has ever run on the
     * result — the exact sequence `EmailHtml`'s docblock opens by warning about.
     *
     * Sanitising the stored bytes is the version of that defence which does not
     * depend on anybody else's code being right: whatever the panel does with this
     * document, the markup it can find in it is a subset of the markup `body_html`
     * was already allowed to carry, so **this field grants no new reach**. That is
     * a claim about a column, and columns keep their promises across refactors.
     *
     * It costs nothing legitimate — `EmailHtml::looksLikeMarkup()` leaves a
     * colour, a URL, a number or an ordinary sentence byte-identical.
     *
     * @param array<string, mixed>|null $fields
     * @return array<string, mixed>|null
     */
    private function cleanFields(mixed $fields): ?array
    {
        return is_array($fields) ? EmailHtml::sanitizeDocument($fields) : null;
    }

    private function requireCampaign(int $id): Campaign
    {
        $campaign = $this->campaigns->find($id);

        if ($campaign === null) {
            throw ApiException::notFound('No campaign with that id.');
        }

        return $campaign;
    }

    /** WordPress's own auth salt — see `Tracking\TrackingLink` for the reasoning. */
    private static function secret(): string
    {
        return function_exists('wp_salt') ? (string) wp_salt('auth') : '';
    }

    /** UTC, in the format every table in this plugin stores a time in. */
    private static function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }
}
