<?php

declare(strict_types=1);

namespace AlgerianCommerce\Integrations\ZRExpress;

use AlgerianCommerce\Shipping\Destination;
use AlgerianCommerce\Shipping\ProviderDestination;
use AlgerianCommerce\Shipping\ShipmentRequest;

/**
 * Turns one `ShipmentRequest` into one ZR Express parcel payload.
 *
 * Pure — no WordPress, no HTTP. The field names come from the official OpenAPI
 * definition, which ZR Express publishes per endpoint at
 * `docs.zrexpress.app/reference/createparcelendpoint.md`:
 *
 * ```
 * customer{customerId, name, phone{number1,number2,number3}}
 * deliveryAddress{cityTerritoryId, districtTerritoryId, street}
 * hubId  deliveryType  amount  weight{weight}  externalId  description
 * orderedProducts[{productName, quantity, unitPrice, stockType, …}]
 * ```
 *
 * **Destinations are UUIDs, not names** — the opposite of Yalidine, and easier:
 * there is nothing to spell wrong. Both come from the destination table, which
 * is what §57 asks for instead of the reference implementation's in-memory
 * caches and partial-name matching at request time.
 */
final class ZRExpressParcel
{
    public const HOME = 'home';
    public const PICKUP_POINT = 'pickup-point';

    /**
     * The parcel holds goods we already own, rather than stock kept in a ZR
     * warehouse. Any other value would make them look for stock that is not
     * theirs.
     */
    private const OWN_STOCK = 'none';

    /**
     * @param string|null $hubId the pickup point, when the customer collects
     *
     * @return array<string, mixed>
     */
    public static function payload(
        ShipmentRequest $request,
        ProviderDestination $wilaya,
        ProviderDestination $commune,
        string $customerId,
        ?string $hubId = null
    ): array {
        $amount = self::amount($request->codAmount);

        $payload = [
            'customer' => [
                'customerId' => $customerId,
                'name' => $request->recipient,
                'phone' => ['number1' => self::phone($request->phone)],
            ],
            'deliveryAddress' => [
                'cityTerritoryId' => $wilaya->destinationId,
                'districtTerritoryId' => $commune->destinationId,
                // Never empty: a driver with no street has only a phone number.
                'street' => $request->address !== '' ? $request->address : 'N/A',
            ],
            'deliveryType' => $request->destination->isDesk() ? self::PICKUP_POINT : self::HOME,
            /*
             * What the driver collects. ZR Express takes one figure and adds
             * its own delivery fee on top or not according to the account's
             * price list — unlike Yalidine, there is no per-parcel flag for it,
             * so this is the order total exactly as the shop charged it.
             */
            'amount' => $amount,
            'externalId' => $request->reference !== '' ? $request->reference : (string) $request->orderId,
            'description' => self::description($request),
            'orderedProducts' => [[
                'productName' => self::description($request),
                'quantity' => 1,
                'unitPrice' => $amount,
                'stockType' => self::OWN_STOCK,
            ]],
        ];

        if ($hubId !== null) {
            $payload['hubId'] = $hubId;
        }

        return $payload;
    }

    /**
     * ZR Express takes the international form, unlike Yalidine's national one.
     *
     * From the reference implementation, which stores E.164 and sends it
     * unchanged. A local `0…` number is expanded rather than passed through,
     * because our checkout accepts whatever a customer types; anything else is
     * left alone, since a "corrected" phone number reaches a stranger.
     */
    public static function phone(string $phone): string
    {
        $digits = preg_replace('/[^\d+]/', '', trim($phone)) ?? '';

        if (str_starts_with($digits, '00213')) {
            return '+213' . substr($digits, 5);
        }

        if (str_starts_with($digits, '0') && strlen($digits) === 10) {
            return '+213' . substr($digits, 1);
        }

        return $digits;
    }

    /**
     * What is in the parcel, in one line.
     *
     * One `orderedProducts` entry rather than a line per item: the API's list
     * exists for merchants whose stock ZR Express holds, where each product is
     * a real record on their side. Ours is our own stock, the shop's line items
     * are already on the order, and splitting the amount across invented rows
     * would let the sum disagree with the amount to collect — which is the one
     * number a customer feels.
     */
    private static function description(ShipmentRequest $request): string
    {
        foreach ([$request->contents, $request->note] as $candidate) {
            if (trim($candidate) !== '') {
                return mb_substr(trim($candidate), 0, 255);
            }
        }

        return 'Colis';
    }

    /** Their `amount` is a number; ours is a decimal string, converted once. */
    private static function amount(string $amount): float
    {
        return round((float) $amount, 2);
    }
}
