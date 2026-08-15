<?php

declare(strict_types=1);

namespace AlgerianCommerce\Integrations\ZRExpress;

use AlgerianCommerce\Shipping\ProviderPlace;

/**
 * ZR Express's own answer to "where do you deliver" — roadmap §57.
 *
 * ```
 * POST territories/search   wilayas and communes in one list, 1,585 rows
 * POST hubs/search          the pickup points, 95 of them
 * ```
 *
 * A territory is `{id, code, name, nameArabic, postalCode, level, parentId,
 * delivery:{hasHomeDelivery, hasPickupPoint, canSend}}` — verified against the
 * live API on 2026-08-15. Three fields make this courier easier to map than the
 * last one:
 *
 *  - `level` says `wilaya` or `commune` outright, so nothing has to be inferred
 *    from the shape of an id.
 *  - `parentId` ties a commune to its wilaya, so a commune is never matched
 *    against the wrong one — Algeria has several same-named communes.
 *  - `code` is the **official Algerian wilaya code**, stated by the courier
 *    rather than guessed from its UUID. That is what `ProviderPlace::$code`
 *    carries, and what lets the matcher recover a wilaya whose name we spell
 *    differently.
 *
 * `delivery.canSend` is this account's coverage, which is why §57 forbids the
 * reference implementation's hard-coded `UNSUPPORTED_WILAYAS` set: it is a
 * per-account fact that the courier already publishes.
 */
final class ZRExpressTerritories
{
    private const WILAYA = 'wilaya';
    private const COMMUNE = 'commune';

    public function __construct(private readonly ZRExpressClient $client)
    {
    }

    /** @return list<ProviderPlace> */
    public function all(): array
    {
        return [...$this->territories(), ...$this->hubs()];
    }

    /** @return list<ProviderPlace> */
    public function territories(): array
    {
        $places = [];

        foreach ($this->client->searchAll('territories/search') as $row) {
            $id = self::str($row, 'id');
            $name = self::str($row, 'name');
            $level = strtolower(self::str($row, 'level'));

            if ($id === '' || $name === '' || !in_array($level, [self::WILAYA, self::COMMUNE], true)) {
                continue;
            }

            $delivery = is_array($row['delivery'] ?? null) ? $row['delivery'] : [];

            $places[] = new ProviderPlace(
                $level === self::WILAYA ? ProviderPlace::WILAYA : ProviderPlace::COMMUNE,
                $id,
                $name,
                // A commune's wilaya is its parent; a wilaya has none.
                $level === self::COMMUNE ? self::str($row, 'parentId') : '',
                '',
                // Absent means yes, as everywhere else: a courier that stops
                // publishing a flag has not stopped delivering.
                !array_key_exists('canSend', $delivery) || (bool) $delivery['canSend'],
                [
                    'level' => $level,
                    'has_home_delivery' => (bool) ($delivery['hasHomeDelivery'] ?? true),
                    'has_pickup_point' => (bool) ($delivery['hasPickupPoint'] ?? false),
                    'postal_code' => self::str($row, 'postalCode'),
                    'name_ar' => self::str($row, 'nameArabic'),
                ],
                // Only a wilaya's code is the national one; a commune's `code`
                // is ZR's own sequence (Alger Centre is 556) and means nothing
                // outside their system, so it is not offered as a tie-break.
                $level === self::WILAYA ? self::str($row, 'code') : ''
            );
        }

        return $places;
    }

    /**
     * The hubs a customer can collect from.
     *
     * Only the visible pickup points: the list also holds sorting centres and
     * return centres, and offering a customer a warehouse they cannot walk into
     * is worse than offering them nothing.
     *
     * @return list<ProviderPlace>
     */
    public function hubs(): array
    {
        $places = [];

        foreach ($this->client->searchAll('hubs/search', [], 200) as $row) {
            $id = self::str($row, 'id');
            $address = is_array($row['address'] ?? null) ? $row['address'] : [];
            $communeId = self::str($address, 'districtTerritoryId');

            if ($id === '' || $communeId === '' || empty($row['isPickupPoint']) || empty($row['isVisible'])) {
                continue;
            }

            $places[] = new ProviderPlace(
                ProviderPlace::CENTER,
                $id,
                self::str($row, 'name'),
                self::str($address, 'cityTerritoryId'),
                $communeId,
                true,
                [
                    'address' => trim(self::str($address, 'street') . ' ' . self::str($address, 'city')),
                    'type' => self::str($row, 'type'),
                ]
            );
        }

        return $places;
    }

    /** @param array<string, mixed> $row */
    private static function str(array $row, string $key): string
    {
        return isset($row[$key]) && is_scalar($row[$key]) ? trim((string) $row[$key]) : '';
    }
}
