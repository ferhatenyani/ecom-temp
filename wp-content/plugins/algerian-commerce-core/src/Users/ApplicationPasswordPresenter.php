<?php

declare(strict_types=1);

namespace AlgerianCommerce\Users;

/**
 * Shapes one of WordPress's application-password records.
 *
 * **An allowlist, not a blocklist**, for the reason `TrackingPresenter` gives:
 * it is handed the whole stored item — `password` included, which is the hash —
 * and reads out the four fields a "revoke this device" screen needs. A caller
 * that passes the raw record still cannot publish the hash, and a future
 * WordPress release that adds a field to the record adds nothing to this
 * response.
 *
 * The plaintext password exists for exactly one response, and it is attached by
 * `UserService::createApplicationPassword()` rather than by this class, so
 * there is no path through here that can emit one by accident.
 */
final class ApplicationPasswordPresenter
{
    /**
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    public static function toArray(array $item): array
    {
        return [
            'uuid' => isset($item['uuid']) ? (string) $item['uuid'] : '',
            'name' => isset($item['name']) ? (string) $item['name'] : '',
            'created' => self::date($item['created'] ?? null),
            'last_used' => self::date($item['last_used'] ?? null),
        ];
    }

    /**
     * @param list<array<string, mixed>> $items
     * @return list<array<string, mixed>>
     */
    public static function toArrayList(array $items): array
    {
        return array_values(array_map(
            static fn (array $item): array => self::toArray($item),
            $items
        ));
    }

    /**
     * `last_ip` is deliberately not published.
     *
     * It is the one field here that describes a person rather than a
     * credential — where a colleague was when they last used the panel — and a
     * staff directory is not the place to publish it. Revoking is what the
     * screen is for, and the name and last-used date are enough to decide.
     */
    private static function date(mixed $value): ?string
    {
        if (!is_numeric($value) || (int) $value <= 0) {
            return null;
        }

        return gmdate('c', (int) $value);
    }
}
