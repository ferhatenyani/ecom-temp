<?php

declare(strict_types=1);

namespace AlgerianCommerce\Products;

use AlgerianCommerce\API\ApiException;

/**
 * What a shopper chose, and what the server says it costs — roadmap §83.
 *
 * ## The single most important sentence in §83
 *
 * **The client sends the option *choice*; the server reads the *price*.**
 *
 * A payload carries `options: {engraving: "AB", wrap: "gold"}` — identifiers
 * and free text, never money. Every delta is read out of the product's own
 * `OptionSet` here, on every cart mutation and again at checkout, exactly as
 * price and stock already are. `LineInput` refuses `option_price`, `surcharge`
 * and the rest **by name with a reason**, beside the six money fields §59b
 * already named.
 *
 * This is §59b's rule at its sharpest. A cart that trusts the price in its own
 * payload is a shop that sells at whatever the customer types; a configurator
 * that trusts an option's price is the same shop with a smaller blast radius
 * and a longer fuse, because the totals still look plausible.
 *
 * ## Pure
 *
 * Strings and arrays in, a decimal out. No WordPress, no product object, no
 * database — so every boundary case §83 names is a unit test rather than
 * something found against a live cart: an unknown option id, a required group
 * omitted, a min/max violation, a negative delta, and a delta that would take a
 * line below zero.
 *
 * ## The surcharge is per unit
 *
 * Engraving three mugs is three engravings. `surcharge` is what one unit costs
 * on top of its catalogue price, and the caller multiplies by quantity — which
 * is what WooCommerce's own line arithmetic then does with the unit price.
 */
final class OptionSelection
{
    /**
     * @param array<string, mixed>       $values    group id => normalised value
     * @param list<array<string, mixed>> $lines     one per chosen option, for display and fulfilment
     * @param string                     $surcharge decimal, per unit, may be negative
     */
    private function __construct(
        public readonly array $values,
        public readonly array $lines,
        public readonly string $surcharge
    ) {
    }

    public static function none(): self
    {
        return new self([], [], '0');
    }

    public function isEmpty(): bool
    {
        return $this->lines === [];
    }

    /**
     * The unit price after options, never below zero — see `price()`.
     */
    public function unitPrice(string $basePrice): string
    {
        return self::decimal((float) $basePrice + (float) $this->surcharge);
    }

    /**
     * Fulfilment's view: "Gift wrap: Gold". Order item meta, so it reaches a
     * packing slip and an email without anybody parsing JSON.
     *
     * @return array<string, string>
     */
    public function toItemMeta(): array
    {
        $meta = [];

        foreach ($this->lines as $line) {
            $meta[(string) $line['label']] = (string) $line['value_label'];
        }

        return $meta;
    }

    /** @return list<array<string, mixed>> */
    public function toArray(): array
    {
        return $this->lines;
    }

    /**
     * Price a chosen set against the product's definition.
     *
     * @param mixed  $chosen    the request's `options` value — untrusted
     * @param string $basePrice the catalogue's unit price, read on the server
     *
     * @throws ApiException 400 listing every bad group, not just the first
     */
    public static function price(OptionSet $set, mixed $chosen, string $basePrice): self
    {
        $groups = $set->selectableGroups();

        if ($chosen === null || $chosen === '' || $chosen === []) {
            $chosen = [];
        }

        if (!is_array($chosen)) {
            throw ApiException::invalidRequest('The cart line is invalid.', [
                'fields' => ['options' => 'Must be a map of option group to chosen value.'],
            ]);
        }

        $errors = [];

        /*
         * Naming an option the product does not offer is refused, not ignored.
         * Ignoring it is how a storefront built against last week's definition
         * quietly stops applying a surcharge the shop still charges for.
         */
        $known = array_column($groups, 'id');

        foreach (array_keys($chosen) as $key) {
            if (!is_string($key) || !in_array($key, $known, true)) {
                $errors['options.' . (is_string($key) ? $key : '?')] = 'This product has no such option.';
            }
        }

        if ($groups === [] && $chosen !== []) {
            $errors['options'] = 'This product has no options.';
        }

        $values = [];
        $lines = [];
        $surcharge = 0.0;

        foreach ($groups as $group) {
            $id = (string) $group['id'];
            $given = array_key_exists($id, $chosen) ? $chosen[$id] : null;

            $resolved = $group['type'] === 'text'
                ? self::text($group, $given, $errors)
                : self::choice($group, $given, $errors);

            if ($resolved === null) {
                continue;
            }

            [$value, $groupLines, $groupDelta] = $resolved;

            if ($value === null) {
                continue;
            }

            $values[$id] = $value;
            $surcharge += $groupDelta;

            foreach ($groupLines as $line) {
                $lines[] = $line;
            }
        }

        /*
         * §83's fifth boundary case, and the only one that needs the product's
         * price to decide. A delta may be negative — "without the box, −200" is
         * a real option — but a line that prices below zero is a shop paying a
         * customer to take a product away, which is not an option set, it is a
         * mistake in one. Refused with the arithmetic named, because "invalid
         * options" would send somebody looking at the wrong thing.
         */
        if ((float) $basePrice + $surcharge < 0) {
            $errors['options'] = sprintf(
                'These options reduce the price below zero (%s %s = %s).',
                self::decimal((float) $basePrice),
                $surcharge < 0 ? '− ' . self::decimal(abs($surcharge)) : '+ ' . self::decimal($surcharge),
                self::decimal((float) $basePrice + $surcharge)
            );
        }

        if ($errors !== []) {
            throw ApiException::invalidRequest('The chosen options are invalid.', ['fields' => $errors]);
        }

        return new self($values, $lines, self::decimal($surcharge));
    }

    /**
     * @param array<string, mixed>  $group
     * @param array<string, string> $errors
     * @return array{0: mixed, 1: list<array<string, mixed>>, 2: float}|null
     */
    private static function choice(array $group, mixed $given, array &$errors): ?array
    {
        $id = (string) $group['id'];
        $field = 'options.' . $id;

        $selected = match (true) {
            $given === null || $given === '' => [],
            is_array($given) => array_values($given),
            is_scalar($given) => [$given],
            default => null,
        };

        if ($selected === null) {
            $errors[$field] = 'Must be a choice id, or a list of them.';

            return null;
        }

        $ids = [];

        foreach ($selected as $value) {
            if (!is_scalar($value)) {
                $errors[$field] = 'Every choice must be a choice id.';

                return null;
            }

            $ids[] = strtolower(trim((string) $value));
        }

        $ids = array_values(array_unique(array_filter($ids, static fn (string $v): bool => $v !== '')));

        if ($group['required'] && $ids === []) {
            $errors[$field] = sprintf('"%s" is required.', $group['label']);

            return null;
        }

        if (count($ids) < (int) $group['min'] && ($ids !== [] || $group['required'])) {
            $errors[$field] = sprintf('Choose at least %d.', (int) $group['min']);

            return null;
        }

        if (count($ids) > (int) $group['max']) {
            $errors[$field] = sprintf('Choose at most %d.', (int) $group['max']);

            return null;
        }

        $lines = [];
        $delta = 0.0;

        foreach ($ids as $choiceId) {
            $choice = null;

            foreach ($group['choices'] as $candidate) {
                if ($candidate['id'] === $choiceId) {
                    $choice = $candidate;
                    break;
                }
            }

            if ($choice === null) {
                $errors[$field] = sprintf('"%s" is not one of the choices.', $choiceId);

                return null;
            }

            $delta += (float) $choice['price_delta'];
            $lines[] = [
                'group_id' => $id,
                'label' => $group['label'],
                'value' => $choiceId,
                'value_label' => $choice['label'],
                'price_delta' => self::decimal((float) $choice['price_delta']),
                'image_id' => (int) $choice['image_id'],
            ];
        }

        return [$ids === [] ? null : $ids, $lines, $delta];
    }

    /**
     * @param array<string, mixed>  $group
     * @param array<string, string> $errors
     * @return array{0: mixed, 1: list<array<string, mixed>>, 2: float}|null
     */
    private static function text(array $group, mixed $given, array &$errors): ?array
    {
        $id = (string) $group['id'];
        $field = 'options.' . $id;

        if ($given === null || (is_string($given) && trim($given) === '')) {
            if ($group['required']) {
                $errors[$field] = sprintf('"%s" is required.', $group['label']);

                return null;
            }

            return [null, [], 0.0];
        }

        if (!is_string($given)) {
            $errors[$field] = 'Must be text.';

            return null;
        }

        $text = self::sanitizeText($given);

        if ($text === '') {
            $errors[$field] = 'Contains no usable text.';

            return null;
        }

        if (mb_strlen($text) > (int) $group['max_length']) {
            $errors[$field] = sprintf('At most %d characters.', (int) $group['max_length']);

            return null;
        }

        return [
            $text,
            [[
                'group_id' => $id,
                'label' => $group['label'],
                'value' => $text,
                'value_label' => $text,
                'price_delta' => self::decimal((float) $group['price_delta']),
                'image_id' => 0,
            ]],
            (float) $group['price_delta'],
        ];
    }

    /**
     * Free text, cleaned — §83's third refusal.
     *
     * Engraving text ends up on a packing slip, in an email and in a CSV
     * export, so it is capped (by the group's own `max_length`) and stripped of
     * the two things that are never engraving: markup, and control characters
     * that would break a line of a CSV or a mail header.
     *
     * **A leading `=`, `+`, `-` or `@` is deliberately left alone.** §64's
     * formula-injection rule is real and it applies the moment this text
     * reaches a spreadsheet — but the fix belongs at the CSV boundary, where
     * `ImportExport\CsvWriter` already escapes exactly as
     * `WC_CSV_Exporter::escape_data()` does. Stripping it here would mangle
     * "A=B" and "-1928-" on a keepsake and would still not protect any other
     * export path. Escape where the danger is, not where the data is born.
     */
    private static function sanitizeText(string $raw): string
    {
        $text = strip_tags($raw);
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text) ?? '';

        // Newlines and tabs collapse to spaces: one option is one line, on a
        // packing slip and in a CSV cell alike.
        $text = preg_replace('/\s+/u', ' ', $text) ?? '';

        return trim($text);
    }

    /** Money as a decimal string, at the shop's precision. Never a float on the wire. */
    private static function decimal(float $value): string
    {
        return number_format($value, 2, '.', '');
    }
}
