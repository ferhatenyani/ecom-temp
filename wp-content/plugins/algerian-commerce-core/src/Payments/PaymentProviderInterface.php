<?php

declare(strict_types=1);

namespace AlgerianCommerce\Payments;

use AlgerianCommerce\API\ApiException;

/**
 * What every payment provider looks like from inside this system — roadmap §58,
 * docs/PLAN.md §17, docs/ARCHITECTURE.md §4.
 *
 * The order service says *take payment*; it never says *call the Chargily
 * endpoint*. One codebase serves several Algerian clients on different
 * providers, and a provider is added by writing an adapter against its current
 * official documentation (roadmap §54) without a line changing above this
 * interface. Adding ZR Express to the shipping side changed nothing above
 * `ShippingProviderInterface`, and the two couriers disagree about almost
 * everything — that is the standard this interface is held to.
 *
 * Everything crossing the boundary is a value object of ours: `PaymentRequest`
 * in, `PaymentResult` / `PaymentReport` / `WebhookResult` out. No `WC_Order`, no
 * loose arrays, no provider payloads leaking upward. An adapter owns, for its
 * provider alone: authentication, endpoint URLs, payload shapes, minor-unit
 * conversion, status mapping, timeouts, retries, idempotency keys, webhook
 * signature verification, and the translation of the provider's errors into
 * ours.
 *
 * **Failures are ApiException**, thrown by the adapter and mapped to our codes —
 * 400 for a request the provider rejected, 409 for a state it refuses, 502 for a
 * provider that cannot be reached. A raw provider message, URL or credential
 * must never reach a response body (docs/SECURITY.md).
 *
 * Two rules that are not negotiable per provider, both from docs/SECURITY.md:
 *
 *  - **A payment is confirmed server-side.** Never by a client callback, never
 *    by a query parameter on a return URL. `verifyPayment()` or a
 *    signature-verified webhook, and nothing else.
 *  - **Amount and currency are re-checked against the order** before anything is
 *    marked paid, which is why `PaymentReport` and `WebhookResult` both carry
 *    them.
 */
interface PaymentProviderInterface
{
    /**
     * The provider's slug — `cod`, `chargily`.
     *
     * Recorded against every transaction and used to route a later verification
     * or webhook back to the same provider, so it must not change once payments
     * exist.
     */
    public function name(): string;

    /** Human-readable, for a payment-method picker at checkout. */
    public function label(): string;

    /**
     * Start a payment.
     *
     * The result carries the provider's id for it and, for a redirect provider,
     * the URL to send the shopper to. A created payment is never a paid one.
     *
     * @throws ApiException when the provider refuses it or cannot be reached
     */
    public function createPayment(PaymentRequest $request): PaymentResult;

    /**
     * Ask the provider what actually happened — the server-side confirmation
     * that docs/SECURITY.md requires before an order is treated as paid.
     *
     * @throws ApiException
     */
    public function verifyPayment(string $providerPaymentId): PaymentReport;

    /**
     * Verify and translate one inbound event.
     *
     * The adapter does the verifying, because the scheme is a fact about the
     * provider: Chargily signs, and a courier might instead put a shared secret
     * in the body. Follow `docs/SECURITY.md` → "Webhooks" exactly — raw body
     * before any JSON decode, `hash_equals()`, a 5-minute timestamp tolerance
     * where the timestamp is inside the signed material.
     *
     * **Throw on anything that does not verify**, with code
     * `webhook_unverified` and status 401, saying nothing about which check
     * failed — a verifier that distinguishes "bad timestamp" from "bad
     * signature" is an oracle for building a valid one. Returning a result with
     * a flag on it would be a check a caller can forget; an exception is not.
     *
     * A verified event we do not recognise is not an error: return a result with
     * a null status and it is acknowledged and dropped, so the provider stops
     * retrying over a gap on our side.
     *
     * @param array<string, mixed>  $payload the decoded body, for reading
     * @param array<string, string> $headers lower-cased header names
     * @param string                $rawBody the bytes as received — what the
     *                                       signature is actually over, since
     *                                       decoding and re-encoding changes them
     *
     * @throws ApiException 401 `webhook_unverified` when verification fails
     */
    public function handleWebhook(array $payload, array $headers, string $rawBody = ''): WebhookResult;
}
