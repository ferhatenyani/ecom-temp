<?php

declare(strict_types=1);

namespace AlgerianCommerce\Shipping;

use AlgerianCommerce\API\ApiException;
use Throwable;

/**
 * Why no parcel appeared when an order was confirmed — backend step 2, item 5.
 *
 * Pure — no WordPress, no database — so the one rule that matters here is
 * unit-testable directly: **nothing a courier or a PHP runtime says reaches an
 * operator unless somebody decided it should.**
 *
 * ## Why this exists at all
 *
 * `ShipmentSubscriber` creates the parcel on confirmation and never throws, and
 * a thing that never throws has to say what went wrong some other way. The
 * reference shop's answer is `OrderService.java:441` — `createShippingParcel()`
 * returns a `String` and `updateStatus()` hangs it on the response DTO as
 * `shippingProviderError`. A bare string is not enough for us, for three
 * reasons that are facts about this system rather than preferences:
 *
 *  - **Our failure does not arrive on a response.** The hook fires on a
 *    WooCommerce status transition, which happens from wp-admin, WP-CLI, cron
 *    and payment gateways as well as from `PATCH /orders/{id}` — see
 *    `ShipmentSubscriber`. Most confirmations have no HTTP response to hang
 *    anything on, so the failure has to be stored, and a stored failure needs a
 *    time or an operator cannot tell last Tuesday's from this morning's.
 *  - **The courier's own sentence is the useful half.** "Yalidine would not
 *    create this parcel" is our message and it is not actionable; *"commune
 *    introuvable"* is theirs and it tells the operator which field to fix. Both
 *    adapters already publish that under `provider_message` in an
 *    `ApiException`'s details — `YalidineProvider::createShipment()` writes it,
 *    `ZRExpressProvider` reads its own back at `:230` and `:319` — so it is a
 *    settled convention rather than a key invented here.
 *  - **A code is what a panel branches on.** A message is prose and gets
 *    rewritten; `yalidine_parcel_refused` and `order_destination_missing` want
 *    different screens.
 *
 * ## What is deliberately *not* carried
 *
 * A `Throwable` that is not an `ApiException` never contributes its message.
 * `fromThrowable()` writes a fixed sentence and keeps the class name only,
 * because a `TypeError` or a `PDOException` carries file paths, SQL and
 * occasionally a credential, and this value is published to a client through
 * `OrderPresenter` (docs/SECURITY.md). The real message is not lost — the
 * subscriber logs it, where a developer can read it and a customer cannot.
 *
 * An `ApiException`'s message *is* carried, and may be, because an adapter's
 * whole job is to translate a provider's errors into ours: the interface says
 * so in terms, and adds that an adapter "must never let a raw provider message,
 * URL or credential reach the response body". `provider_message` is the one
 * exception both adapters make deliberately and argue for on the spot.
 *
 * Every string is bounded, for `Shipment`'s reason turned around: that class
 * truncates so MySQL cannot reject a write after a parcel is already real, and
 * this one truncates so a courier that answers with a kilobyte of HTML cannot
 * put a kilobyte of HTML on an order's meta and into every list response that
 * order appears in.
 */
final class ShipmentFailure
{
    public const MAX_MESSAGE = 300;

    /** Our own, for the two refusals that are not a courier's fault. */
    public const NO_DESTINATION = 'order_destination_missing';
    public const UNEXPECTED = 'shipment_create_failed';

    public readonly string $provider;
    public readonly string $code;
    public readonly string $message;
    public readonly string $providerMessage;

    public function __construct(
        string $provider,
        string $code,
        string $message,
        string $providerMessage = '',
        public readonly string $at = ''
    ) {
        $this->provider = mb_substr(trim($provider), 0, Shipment::MAX_PROVIDER);
        $this->code = mb_substr(trim($code), 0, 64);
        $this->message = mb_substr(trim($message), 0, self::MAX_MESSAGE);
        $this->providerMessage = mb_substr(trim($providerMessage), 0, self::MAX_MESSAGE);
    }

    /**
     * A courier — or one of our own guards — refusing in our own vocabulary.
     *
     * `ApiException` is what every adapter throws and what `ShippingService`,
     * `ProviderRegistry` and `ShipmentInput` throw too, so this one constructor
     * covers a courier that refused the address, a courier that is unreachable,
     * a courier that has been de-registered since the order named it, and a
     * destination that does not validate. They differ by `code`, which is
     * exactly what a code is for.
     */
    public static function fromApiException(string $provider, ApiException $exception, string $at): self
    {
        $details = $exception->details();
        $providerMessage = $details['provider_message'] ?? '';

        return new self(
            $provider,
            $exception->errorCode(),
            $exception->getMessage(),
            is_scalar($providerMessage) ? (string) $providerMessage : '',
            $at
        );
    }

    /**
     * Anything else at all — and it is reported without being repeated.
     *
     * This is the branch that makes "never throws" true rather than likely. A
     * provider is third-party code behind an interface: it can return a shape
     * `ShipmentResult` refuses and raise an `InvalidArgumentException`, it can
     * hit a `TypeError` on a null it did not expect, its HTTP client can raise
     * something no one has catalogued. None of those is a sentence to show an
     * operator, and all of them are a parcel that did not get created.
     *
     * The class name survives because it is the one part that is safe and the
     * one part a developer asks for first; `$exception->getMessage()` does not,
     * for the reason in the class docblock.
     */
    public static function fromThrowable(string $provider, Throwable $exception, string $at): self
    {
        return new self(
            $provider,
            self::UNEXPECTED,
            'The parcel could not be created. The failure was logged as ' . $exception::class . '.',
            '',
            $at
        );
    }

    /**
     * The order has no destination to send a parcel to.
     *
     * Not a courier failure, and the message says so, because the fix is
     * completely different: a courier refusal is corrected by editing the order
     * and confirming again, and this one cannot be corrected on the order at
     * all. `ShipmentSubscriber::destinationOf()` carries the whole argument —
     * briefly, a wilaya and a commune are stored as ids by
     * `Cart\CheckoutService::createOrder()` and there is no field on
     * `POST /orders` that writes them, so a back-office order reaches here.
     * The route named in the message is the one that takes the two ids in its
     * body, which is why it is named rather than described.
     */
    public static function noDestination(string $provider, string $at): self
    {
        return new self(
            $provider,
            self::NO_DESTINATION,
            'This order records no wilaya and commune, so no parcel could be addressed. '
                . 'Create it with POST /orders/{id}/shipments, which takes both.',
            '',
            $at
        );
    }

    /**
     * How it is stored on the order, and read back.
     *
     * A plain array under one meta key rather than four keys, for
     * `Shipment::toRow()`'s reason inverted: that class spreads its fields into
     * columns because a column is queryable, and this one does not because
     * nothing queries an order by why its parcel failed. One key is also one
     * write and one clear — a four-key version has a state where half of last
     * week's failure is still on the order.
     *
     * @return array<string, string>
     */
    public function toMeta(): array
    {
        return [
            'provider' => $this->provider,
            'code' => $this->code,
            'message' => $this->message,
            'provider_message' => $this->providerMessage,
            'at' => $this->at,
        ];
    }

    /**
     * Read one back, or null when the stored value is not one.
     *
     * `null` for anything unrecognisable rather than a half-built object.
     * Order meta is a public store — the argument `OrderPresenter::manualPrice()`
     * makes about another plugin writing a word under our key — and a failure
     * this system did not record is not a failure it should publish.
     *
     * @param mixed $stored as `get_meta()` returned it
     */
    public static function fromMeta(mixed $stored): ?self
    {
        if (!is_array($stored)) {
            return null;
        }

        $code = $stored['code'] ?? '';

        if (!is_scalar($code) || trim((string) $code) === '') {
            return null;
        }

        $string = static fn (string $key): string => is_scalar($stored[$key] ?? null)
            ? (string) $stored[$key]
            : '';

        return new self(
            $string('provider'),
            (string) $code,
            $string('message'),
            $string('provider_message'),
            $string('at')
        );
    }

    /**
     * The wire shape — what a panel renders.
     *
     * `provider_message` is `null` rather than `''` when the courier said
     * nothing of its own, so a client can test one thing to decide whether to
     * show the second line. That is `OrderPresenter`'s convention throughout:
     * an absent value is `null`, and the empty string is not used to mean
     * absent.
     *
     * `at` is ISO-8601, like every other timestamp this API emits, converted
     * from the `'Y-m-d H:i:s'` UTC the meta stores — `Shipment::iso()` does the
     * identical conversion for the same reason.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'provider' => $this->provider,
            'code' => $this->code,
            'message' => $this->message,
            'provider_message' => $this->providerMessage === '' ? null : $this->providerMessage,
            'at' => self::iso($this->at),
        ];
    }

    private static function iso(string $stored): ?string
    {
        if (trim($stored) === '') {
            return null;
        }

        $timestamp = strtotime($stored . ' UTC');

        return $timestamp === false ? null : gmdate('c', $timestamp);
    }
}
