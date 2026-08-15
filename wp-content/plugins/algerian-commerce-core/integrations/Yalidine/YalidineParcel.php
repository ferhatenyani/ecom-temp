<?php

declare(strict_types=1);

namespace AlgerianCommerce\Integrations\Yalidine;

use AlgerianCommerce\Shipping\ProviderDestination;
use AlgerianCommerce\Shipping\ShipmentRequest;

/**
 * Turns one `ShipmentRequest` into one Yalidine parcel payload.
 *
 * Pure — no WordPress, no HTTP, no state. Every field name below is taken from
 * the two sources that agree on them (roadmap §56: the production Spring Boot
 * service and the Createk plugin's client), and none is invented:
 *
 * ```
 * order_id  from_wilaya_name  firstname  familyname  contact_phone
 * address   to_commune_name   to_wilaya_name  product_list  price
 * do_insurance  declared_value  length  width  height  weight
 * freeshipping  is_stopdesk  stopdesk_id  has_exchange
 * ```
 *
 * **Names come from the destination table, never from a table in PHP.** Yalidine
 * addresses a parcel by wilaya and commune *name* and matches them exactly —
 * "Bouzaréah" is accepted and "Bouzzerea" is not, and the rejection is an empty
 * array with no message in it. The reference implementation deals with this by
 * hard-coding 58 wilaya names and re-fetching the fees endpoint at creation time
 * to guess at the commune's spelling. Here the destination sync has already
 * recorded Yalidine's own spelling of both, so the payload quotes the courier
 * back to itself.
 *
 * `order_id` carries `ShipmentRequest::$reference` — "42-2", the second parcel
 * for order 42 — which is also how the response is found, since `POST parcels/`
 * returns an object keyed by it.
 */
final class YalidineParcel
{
    /** Yalidine's own field for what to collect at the door, in whole dinars. */
    private const CURRENCY_DECIMALS = 0;

    /**
     * @param string|null $stopdeskId the centre to deliver to, when the
     *                                customer is collecting; null for a home
     *                                delivery
     *
     * @return array<string, mixed>
     */
    public static function payload(
        ShipmentRequest $request,
        ProviderDestination $originWilaya,
        ProviderDestination $toWilaya,
        ProviderDestination $toCommune,
        YalidineSettings $settings,
        ?string $stopdeskId = null
    ): array {
        [$firstName, $familyName] = self::splitName($request->recipient);

        $price = self::amount($request->codAmount);

        $payload = [
            /*
             * The merchant reference, not the order id — and it is also the key
             * the create response comes back under.
             *
             * **Not an idempotency key.** Verified 2026-08-14: posting the same
             * `order_id` twice produced two parcels with two tracking numbers.
             * Yalidine will happily make a duplicate, so the guard is ours —
             * `YalidineProvider::findByReference()` looks the reference up
             * before creating, and ShippingService's one-live-shipment-per-order
             * rule sits behind that.
             */
            'order_id' => $request->reference !== '' ? $request->reference : (string) $request->orderId,
            'from_wilaya_name' => $originWilaya->name(),
            'firstname' => $firstName,
            'familyname' => $familyName,
            'contact_phone' => self::phone($request->phone),
            'address' => $request->address,
            'to_wilaya_name' => $toWilaya->name(),
            'to_commune_name' => $toCommune->name(),
            'product_list' => self::productList($request),
            'price' => $price,
            /*
             * What the driver collects. '0' means the order is already paid, and
             * the parcel still has to be created — Yalidine carries prepaid
             * parcels too, at price 0.
             */
            'freeshipping' => $settings->freeshipping,
            'is_stopdesk' => $stopdeskId !== null,
            'has_exchange' => $settings->hasExchange,
            'do_insurance' => $settings->doInsurance,
            // Insured for what the goods are worth, which is what is being
            // collected for them. A client who does not insure still sends it;
            // the field is what Yalidine prices insurance from when it is on.
            'declared_value' => $price,
        ];

        if ($stopdeskId !== null) {
            $payload['stopdesk_id'] = $stopdeskId;
        }

        return $payload + $settings->parcelDimensions();
    }

    /**
     * `Ahmed Ben Salah` → `Ahmed` + `Ben Salah`.
     *
     * One field in, two out, because Algerian orders are taken with a single
     * name field and Yalidine wants the halves separately. The split is at the
     * *first* space: a family name of two words is ordinary here, a first name
     * of two words is not, so everything after the first word is the family
     * name. A single word goes in as the first name with an empty family name
     * rather than being duplicated into both.
     *
     * @return array{0: string, 1: string}
     */
    public static function splitName(string $fullName): array
    {
        $parts = preg_split('/\s+/', trim($fullName), 2) ?: [];

        return [(string) ($parts[0] ?? ''), (string) ($parts[1] ?? '')];
    }

    /**
     * Algerian mobile numbers as Yalidine wants them: `0XXXXXXXXX`.
     *
     * From a service running in production against the live API, and a parcel
     * created in that form was accepted on 2026-08-14. Whether E.164 would
     * *also* be accepted was not tested — one shape that works is enough. Orders here can carry either, since a checkout takes whatever
     * a customer types, so both are normalised to one shape and anything
     * unrecognisable is passed through untouched — a wrong-looking phone number
     * that Yalidine rejects is better than a "corrected" one that reaches a
     * stranger.
     */
    public static function phone(string $phone): string
    {
        $digits = preg_replace('/[^\d+]/', '', trim($phone)) ?? '';

        foreach (['+213', '00213', '213'] as $prefix) {
            if (str_starts_with($digits, $prefix)) {
                $national = substr($digits, strlen($prefix));

                // 213 also starts a number that is simply missing its zero, so
                // only strip it when what is left is the right length.
                return strlen($national) === 9 ? '0' . $national : $digits;
            }
        }

        return $digits;
    }

    /**
     * What is in the parcel, for the driver's manifest and the customer's
     * receipt.
     *
     * Roadmap §53 deferred parcel contents to "the first adapter whose docs say
     * what it actually requires" — this is that adapter, and `product_list` is
     * required. It comes off the order's own line items; the shipment note is
     * the fallback for an order whose items say nothing useful, and a plain
     * "Colis" is the last resort, because an empty manifest is a parcel a driver
     * cannot describe on the phone.
     */
    private static function productList(ShipmentRequest $request): string
    {
        foreach ([$request->contents, $request->note] as $candidate) {
            if (trim($candidate) !== '') {
                // Yalidine prints this on the label; an unbounded product list
                // from a 30-line order has no business being sent whole.
                return mb_substr(trim($candidate), 0, 255);
            }
        }

        return 'Colis';
    }

    /**
     * A decimal string becomes whole dinars.
     *
     * Money is a string everywhere else in this codebase precisely so it is not
     * rounded by accident; Yalidine's field is an integer, so the rounding
     * happens here, at the edge, once, and never travels back inward.
     */
    private static function amount(string $amount): int
    {
        return (int) round((float) $amount, self::CURRENCY_DECIMALS);
    }
}
