<?php

declare(strict_types=1);

namespace AlgerianCommerce\Integrations\Chargily;

use AlgerianCommerce\API\ApiException;
use AlgerianCommerce\Core\Logger;
use AlgerianCommerce\Payments\PaymentProviderInterface;
use AlgerianCommerce\Payments\PaymentReport;
use AlgerianCommerce\Payments\PaymentRequest;
use AlgerianCommerce\Payments\PaymentResult;
use AlgerianCommerce\Payments\PaymentStatus;
use AlgerianCommerce\Payments\WebhookResult;
use Closure;

/**
 * Chargily Pay V2 behind `PaymentProviderInterface` — roadmap §59, PLAN §18.
 *
 * Written from Chargily's current official documentation (§54 forbids memory),
 * cross-checked against their own MIT-licensed WooCommerce plugin and PHP SDK —
 * read for facts, never copied, exactly as §56 handled Createk's Yalidine
 * plugin.
 *
 * **Verified against the live test API on 2026-08-15**, which is the difference
 * between this adapter and §56's: Chargily hands out test keys to anyone with an
 * email address, so nothing here had to stay a guess. There are no
 * `ASSUMPTION (unverified)` markers left — `grep -rn ASSUMPTION
 * integrations/Chargily` is empty, and it should stay that way or say why. Four
 * things the run settled that the reference does not:
 *
 * ```
 * expired          a real status, absent from the documented enum
 * 1500.50          a fractional amount is accepted, despite `integer`
 * checkout_url     comes back http://, though the docs write https://
 * account{…}       every response embeds the merchant's own record
 * ```
 *
 * **Adding this changed nothing above `PaymentProviderInterface`** — the
 * standard ZR Express met on the shipping side. Cash on delivery answers
 * `pending` forever with no URL and no credentials; this one opens a hosted
 * checkout, hands back a link, and is told about the outcome twice over. Both
 * are the same four methods.
 *
 * ## The shape of it
 *
 * ```
 * createPayment   POST checkouts        → id + checkout_url, status "pending"
 * verifyPayment   GET  checkouts/{id}   → the authoritative answer about money
 * handleWebhook   signature header      → HMAC-SHA256 hex over the raw body
 * ```
 *
 * ## What is not trusted
 *
 * The webhook payload's `status` is **not** what marks an order paid. Two
 * reasons, and the second is the one that decides it: docs/SECURITY.md requires
 * amount *and currency* to be re-checked against the order before anything is
 * settled, and **the checkout object inside a webhook has no `currency` field**
 * — it is a different shape from the API's, missing `currency` and calling
 * `checkout_url` plain `url`. A rule that cannot be obeyed from the payload is a
 * rule that has to be obeyed elsewhere, so `PaymentService` re-fetches with
 * `verifyPayment()` and acts on that. The signature proves who sent the message;
 * the re-fetch is what proves the money.
 *
 * ## Money
 *
 * Chargily quotes in **dinars, not centimes** — `{"amount": 2000, "currency":
 * "dzd"}` is two thousand dinars. Their WooCommerce plugin passes WooCommerce's
 * order total straight through, unscaled, which is the same statement from a
 * second direction. (§58's `PaymentRequest` docblock guessed centimes before
 * anybody had read the docs; it has been corrected.)
 */
final class ChargilyProvider implements PaymentProviderInterface
{
    public const NAME = 'chargily';

    /** The header Chargily puts the HMAC in — lower-cased, as WordPress gives them to us. */
    public const SIGNATURE_HEADER = 'signature';

    /** docs/SECURITY.md → "Webhooks": five minutes, in either direction. */
    public const TIMESTAMP_TOLERANCE = 300;

    /** Their currency code is lower-case ISO 4217; ours is upper-case everywhere else. */
    private const CURRENCY = 'dzd';

    public function __construct(
        private readonly ChargilyClient $client,
        private readonly ChargilySettings $settings,
        private readonly ChargilyCredentials $credentials,
        private readonly Logger $logger,
        /**
         * Where Chargily should send events for checkouts this shop creates.
         *
         * A callable rather than a string, and that is not indirection for its
         * own sake. The value is `rest_url()` of our own webhook route, and
         * **`rest_url()` cannot be called when providers are wired**: the
         * registry is built at `plugins_loaded`, where `$wp_rewrite` is still
         * null and `get_rest_url()` fatals on it. Resolving it at the moment a
         * checkout is created is both correct and late enough — and it keeps
         * this class from knowing WordPress exists, which a string built here
         * would not.
         *
         * Null is a valid answer: a client may register the endpoint in
         * Chargily's dashboard instead, which is what their documentation
         * describes first. The field is then omitted rather than sent blank.
         */
        private readonly ?Closure $webhookUrl = null
    ) {
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function label(): string
    {
        return 'Chargily (EDAHABIA / CIB)';
    }

    /**
     * Open a hosted checkout and hand back the link.
     *
     * `success_url` is required by Chargily on every checkout. The caller's
     * `return_url` wins where it sent one — it is already validated as https by
     * `PaymentInput` — and the client's configured storefront URL is the
     * fallback. With neither there is nothing to send, and that is refused here
     * rather than as an opaque 400 from the gateway.
     *
     * The result is always `pending`. A created checkout is a page the customer
     * has not visited yet; treating the gateway's acceptance as payment is the
     * single mistake this whole layer exists to prevent.
     */
    public function createPayment(PaymentRequest $request): PaymentResult
    {
        $successUrl = $request->returnUrl !== '' ? $request->returnUrl : $this->settings->successUrl;

        if ($successUrl === '') {
            throw ApiException::conflict(
                'Chargily needs a success URL: set one in the Chargily settings or send return_url.',
                ['provider' => self::NAME]
            );
        }

        /*
         * Refused here rather than discovered later. Chargily takes dinars and
         * nothing else, so a shop whose orders are denominated in anything else
         * would send a "dzd" amount for a total that is not one — and the
         * customer would pay it. The failure would surface as an amount
         * mismatch *after* the money had moved, which is the worst place to
         * find out. This is not hypothetical: a WooCommerce install ships with
         * the store currency set to USD.
         */
        if (strtoupper(trim($request->currency)) !== strtoupper(self::CURRENCY)) {
            throw ApiException::conflict(
                'Chargily can only take payments in DZD; this order is not.',
                ['provider' => self::NAME, 'order_currency' => $request->currency]
            );
        }

        $payload = array_filter([
            'amount' => $this->amount($request->amount),
            'currency' => self::CURRENCY,
            'success_url' => $successUrl,
            'failure_url' => $this->settings->failureUrl,
            'webhook_endpoint' => $this->webhookEndpoint(),
            'locale' => $this->settings->locale,
            'payment_method' => $this->settings->paymentMethod,
            'chargily_pay_fees_allocation' => $this->settings->feesAllocation,
            'description' => $request->description,
            /*
             * Round-tripped on the webhook, which is how their own plugin finds
             * the order. This adapter does not rely on it — a checkout is found
             * by its id, which cannot be forged into a different shop's order —
             * but it is what a support conversation starts from inside
             * Chargily's dashboard.
             */
            'metadata' => array_filter([
                'order_id' => (string) $request->orderId,
                'reference' => $request->reference,
            ]),
        ], static fn (mixed $value): bool => $value !== '' && $value !== []);

        $checkout = $this->client->post('checkouts', $payload);

        $id = $this->string($checkout, 'id');
        $url = $this->httpsOnly($this->string($checkout, 'checkout_url'));

        if ($id === '' || $url === '') {
            // Both are the entire point of the call: without the id nothing can
            // ever be verified, and without the URL the customer has nowhere to
            // go. A checkout may well exist at Chargily by now, which is why
            // this is logged rather than only thrown.
            $this->logger->error('Chargily created a checkout this store cannot use', [
                'has_id' => $id !== '',
                'has_url' => $url !== '',
            ]);

            throw new ApiException(
                'provider_response_invalid',
                'Chargily did not return a usable checkout.',
                502,
                ['provider' => self::NAME]
            );
        }

        return new PaymentResult($id, PaymentStatus::PENDING, $url, $this->metadata($checkout));
    }

    /**
     * Ask Chargily what actually happened.
     *
     * This is the server-side confirmation docs/SECURITY.md requires, and it is
     * the only thing in this adapter whose word is taken for money. Both the
     * verification endpoint and the webhook path end here.
     */
    public function verifyPayment(string $providerPaymentId): PaymentReport
    {
        $id = trim($providerPaymentId);

        if ($id === '') {
            throw ApiException::invalidRequest('The payment data is invalid.', [
                'fields' => ['provider_payment_id' => 'A Chargily checkout id is required.'],
            ]);
        }

        $checkout = $this->client->get('checkouts/' . rawurlencode($id));
        $providerStatus = $this->string($checkout, 'status');

        return new PaymentReport(
            ChargilyStatusMap::toPaymentStatus($providerStatus),
            $providerStatus,
            $this->reportedAmount($checkout),
            strtoupper($this->string($checkout, 'currency')),
            $this->metadata($checkout)
        );
    }

    /**
     * Verify one inbound event — docs/SECURITY.md → "Webhooks", to the letter.
     *
     * ```
     * signature header present     → else unverified
     * HMAC-SHA256 hex over the raw bytes, hash_equals() against the secret key
     * created_at within 5 minutes  → the timestamp is inside the signed body,
     *                                so unlike a body-secret scheme it binds
     * an event id to claim         → the caller claims it, never checks it
     * ```
     *
     * **Everything above happens before a single field of `$payload` is read.**
     * The decode itself is the caller's, and is a parse with no side effect; the
     * signature is checked against the bytes as they arrived, which is the part
     * that matters — decoding and re-encoding changes them and breaks every
     * signature scheme there is.
     *
     * Every failure throws the *same* 401 `webhook_unverified` with no detail.
     * A verifier that distinguishes "bad timestamp" from "bad signature" is an
     * oracle for building a valid one.
     *
     * A verified event of a type we do not handle returns a null status and is
     * acknowledged and dropped, so Chargily stops retrying over a gap on our
     * side.
     *
     * @param array<string, mixed>  $payload
     * @param array<string, string> $headers lower-cased header names
     */
    public function handleWebhook(array $payload, array $headers, string $rawBody = ''): WebhookResult
    {
        $signature = trim($headers[self::SIGNATURE_HEADER] ?? '');
        $secret = $this->credentials->signingSecret();

        if ($signature === '' || $secret === '' || $rawBody === '') {
            throw $this->unverified();
        }

        $expected = hash_hmac('sha256', $rawBody, $secret);

        // hash_equals, never == or ===: a timing-variable comparison leaks the
        // signature one byte at a time to anyone willing to send enough requests.
        if (!hash_equals($expected, $signature)) {
            throw $this->unverified();
        }

        /*
         * The timestamp is `created_at` in the body rather than a header, and
         * that is *better* here than it sounds: the body is what the HMAC
         * covers, so the timestamp is signed material and cannot be edited to
         * refresh a captured event. This is exactly the condition
         * docs/SECURITY.md attaches to the tolerance being meaningful at all.
         *
         * Chargily does not document how long it keeps retrying, so a genuine
         * retry arriving after five minutes is refused here. That is the reason
         * `sync-payments` exists: a dropped event costs a few minutes' delay
         * rather than a lost payment.
         */
        $createdAt = $payload['created_at'] ?? null;

        if (!is_numeric($createdAt) || abs(time() - (int) $createdAt) > self::TIMESTAMP_TOLERANCE) {
            throw $this->unverified();
        }

        $type = $this->string($payload, 'type');
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];

        /*
         * Where a provider sends no event id, docs/SECURITY.md derives one by
         * hashing the signed material: stable across a genuine retransmission,
         * distinct between real events. Chargily does send one, so this is the
         * fallback for a payload shaped unlike the documented one that still
         * carried a valid signature.
         */
        $eventId = $this->string($payload, 'id');
        $eventId = $eventId !== '' ? $eventId : hash('sha256', $rawBody);

        if (!str_starts_with($type, 'checkout.')) {
            // Verified, and nothing to do with a payment we track.
            return new WebhookResult($eventId, '', null, '', '', ['event_type' => $type]);
        }

        $providerStatus = $this->string($data, 'status');

        return new WebhookResult(
            $eventId,
            $this->string($data, 'id'),
            ChargilyStatusMap::isKnown($providerStatus)
                ? ChargilyStatusMap::toPaymentStatus($providerStatus)
                : null,
            $this->reportedAmount($data),
            // Absent from the webhook's checkout object — see the class
            // docblock. Empty, rather than assumed to be DZD, because the whole
            // point of the field is to be checked.
            strtoupper($this->string($data, 'currency')),
            [
                'event_type' => $type,
                'provider_status' => $providerStatus,
            ]
        );
    }

    /**
     * The payment page, over TLS.
     *
     * **Chargily returns this URL as `http://`.** Their documentation writes it
     * `https://`, and the https form serves the same page — both checked on
     * 2026-08-15. So the scheme is corrected rather than passed on: this value
     * is where a shopper's browser is sent to type card details, and handing
     * them a cleartext hop is an invitation to have the redirect rewritten in
     * front of them. Same host, same path, same page — only the scheme differs,
     * and only in the safe direction.
     */
    private function httpsOnly(string $url): string
    {
        return str_starts_with($url, 'http://')
            ? 'https://' . substr($url, strlen('http://'))
            : $url;
    }

    /** Resolved now rather than at wiring time — see the constructor. */
    private function webhookEndpoint(): string
    {
        return $this->webhookUrl === null ? '' : trim((string) ($this->webhookUrl)());
    }

    /**
     * The amount as Chargily wants it: a number of **dinars**.
     *
     * The reference types it `integer`, and their WooCommerce plugin sends
     * WooCommerce's order total unchanged, which for a taxed basket is not
     * always whole — so the two disagreed. **Verified live on 2026-08-15: a
     * fractional amount is accepted.** A checkout created for 1500.50 read back
     * as 1500.50 dzd. A whole amount is still sent as an integer, because that
     * is what the reference asks for and what every example shows.
     *
     * **Nothing is rounded here** either way: silently turning 4500.50 into
     * 4500 is a shop that is wrong about money in a way no log would show.
     */
    private function amount(string $amount): int|float
    {
        $value = (float) $amount;

        return $value === floor($value) ? (int) $value : $value;
    }

    /**
     * What the gateway says was paid, as a decimal string.
     *
     * Never a float beyond the parse: `PaymentReport` compares it against the
     * order total, and that comparison is the one standing between a confirmed
     * payment and a shipped order.
     */
    private function reportedAmount(array $checkout): string
    {
        $amount = $checkout['amount'] ?? null;

        return is_numeric($amount) ? number_format((float) $amount, 2, '.', '') : '';
    }

    /**
     * The provider's own particulars, kept for the record.
     *
     * A deliberate subset rather than the whole payload, and the live responses
     * are why it has to be. `checkout_url` is a one-time link to a payment page
     * for this customer's order — the same class of thing as a courier's
     * tokenised label URL — and storing it would put it somewhere it outlives
     * its usefulness. Beyond that, a real response carries an undocumented
     * `account` object describing the *merchant*: company name, trade register,
     * NIS, NIF, address and a `satim_credentials` field. None of that belongs in
     * a per-order row, and an allowlist is the only shape that keeps it out when
     * a gateway adds the next one without telling anybody.
     *
     * @param array<string, mixed> $checkout
     * @return array<string, mixed>
     */
    private function metadata(array $checkout): array
    {
        return array_filter([
            'provider_status' => $this->string($checkout, 'status'),
            'payment_method' => $this->string($checkout, 'payment_method'),
            'livemode' => (bool) ($checkout['livemode'] ?? !$this->credentials->isTestMode()),
            'fees' => $checkout['fees'] ?? null,
            'fees_on_merchant' => $checkout['fees_on_merchant'] ?? null,
            'fees_on_customer' => $checkout['fees_on_customer'] ?? null,
            'invoice_id' => $this->string($checkout, 'invoice_id'),
            'customer_id' => $this->string($checkout, 'customer_id'),
        ], static fn (mixed $value): bool => $value !== '' && $value !== null);
    }

    /** @param array<string, mixed> $data */
    private function string(array $data, string $key): string
    {
        $value = $data[$key] ?? '';

        return is_scalar($value) ? trim((string) $value) : '';
    }

    /**
     * One answer for every verification failure.
     *
     * Logged at warning with the provider and nothing else — never the body,
     * never the headers, never the secret, and never the event id, which at this
     * point is unverified attacker input (docs/SECURITY.md). The route logs the
     * source IP, which is the one useful fact about a forgery attempt.
     */
    private function unverified(): ApiException
    {
        $this->logger->warning('Chargily webhook failed verification', ['provider' => self::NAME]);

        return new ApiException(
            'webhook_unverified',
            'This request could not be verified.',
            401,
            ['provider' => self::NAME]
        );
    }
}
