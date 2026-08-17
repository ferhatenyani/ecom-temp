<?php

declare(strict_types=1);

namespace AlgerianCommerce\Products;

use AlgerianCommerce\API\ApiException;

/**
 * A product's configurable options — roadmap §83.
 *
 * ## The boundary this class exists on
 *
 * **A variation is a thing with a SKU and stock. An option is a modifier with
 * neither.** That is the whole design rule, and it is why a configurator cannot
 * be built out of more variations: variations are *enumerated* and options are
 * *combinatorial*. Five attributes of six options each is 7,776 variations —
 * every one a post row, a meta-lookup row and a line in every export. The same
 * product with five option groups is one product.
 *
 * If a choice needs its own stock count or its own SKU it is a variation and
 * belongs to §47. Anything ambiguous is a variation, because that is the
 * direction that stays correct as the shop grows.
 *
 * ## Three group types, one document
 *
 *  - **`choice`** — pick from a fixed list. Each choice carries a price delta.
 *  - **`text`** — free text with a length cap. Engraving, a gift message.
 *  - **`bundle`** — component products this one draws stock from. §83: bundle
 *    contents are *the same document with a different group type*, so a bundle
 *    is not a second mechanism and not a second product type.
 *
 * ## Pure, and stored in product meta
 *
 * No WordPress here, so what a storefront is handed and what a write is allowed
 * to say are both unit-testable. `fromStored()` is lenient in the manner of
 * `HomepageSections` — a malformed group is dropped **and reported**, never
 * silently vanished — while `fromPayload()` is strict and refuses by field,
 * because a write is somebody typing now and can be told what is wrong.
 *
 * **No migration and no table.** WordPress stores the content, we store the
 * structure, and §61 and §62 both settled that. The trigger for a table is
 * named and it is not this: when an option set is *shared across products* —
 * one "Gift wrap" definition applied to two hundred items and edited once — a
 * copy in each product's meta is the thing that drifts, and `ac_option_sets`
 * earns its place. Until then it does not.
 */
final class OptionSet
{
    public const TYPES = ['choice', 'text', 'bundle'];

    /**
     * Caps, each because an unbounded one turns a product read into an
     * unbounded response or a cart mutation into unbounded arithmetic.
     */
    public const MAX_GROUPS = 20;
    public const MAX_CHOICES = 50;
    public const MAX_BUNDLE_ITEMS = 20;

    /** The ceiling a group's own `max_length` may not exceed — §83's cap. */
    public const MAX_TEXT_LENGTH = 500;
    public const DEFAULT_TEXT_LENGTH = 100;

    private const ID_PATTERN = '/^[a-z0-9][a-z0-9_-]{0,31}$/';
    private const MAX_LABEL = 120;

    /**
     * @param list<array<string, mixed>> $groups
     * @param list<string>               $problems
     */
    private function __construct(
        public readonly array $groups,
        public readonly array $problems
    ) {
    }

    public static function empty(): self
    {
        return new self([], []);
    }

    public function isEmpty(): bool
    {
        return $this->groups === [];
    }

    /** @return array<string, mixed>|null */
    public function group(string $id): ?array
    {
        foreach ($this->groups as $group) {
            if ($group['id'] === $id) {
                return $group;
            }
        }

        return null;
    }

    /** Groups a shopper chooses from — everything that is not bundle contents. */
    public function selectableGroups(): array
    {
        return array_values(array_filter($this->groups, static fn (array $g): bool => $g['type'] !== 'bundle'));
    }

    /**
     * The components this product draws stock from, flattened.
     *
     * @return list<array{product_id: int, quantity: int}>
     */
    public function bundleItems(): array
    {
        $items = [];

        foreach ($this->groups as $group) {
            if ($group['type'] !== 'bundle') {
                continue;
            }

            foreach ($group['items'] as $item) {
                $items[] = $item;
            }
        }

        return $items;
    }

    public function isBundle(): bool
    {
        return $this->bundleItems() !== [];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['groups' => $this->groups];
    }

    /**
     * What is on the product now.
     *
     * Lenient: meta can be edited by `wp post meta update` and by a plugin that
     * has nothing to do with us, so a bad group degrades rather than breaking
     * every read of the product. Problems are *reported* — §61's rule, because
     * a group that silently vanishes is the one failure a shop cannot diagnose.
     */
    public static function fromStored(mixed $stored): self
    {
        if (is_string($stored)) {
            /*
             * A string that will not decode is **reported**, not read as "this
             * product has no options" — the distinction `OptionSetTest` insists
             * on. Meta is reachable by `wp post meta update` and by any other
             * plugin, so a mangled document is a thing that happens; treating it
             * as absence is the silent vanishing §61 rules out, one layer down.
             * Only a genuinely empty string is absence.
             */
            if (trim($stored) === '') {
                return self::empty();
            }

            $decoded = json_decode($stored, true);

            if (!is_array($decoded)) {
                return new self([], ['The option set is not readable: ' . json_last_error_msg() . '.']);
            }

            $stored = $decoded;
        }

        if (!is_array($stored)) {
            return $stored === null || $stored === false || $stored === ''
                ? self::empty()
                : new self([], ['The option set is not a document.']);
        }

        $raw = array_key_exists('groups', $stored) ? $stored['groups'] : $stored;

        if (!is_array($raw)) {
            return new self([], ['"groups" is not a list.']);
        }

        $groups = [];
        $problems = [];
        $seen = [];
        $position = 0;

        foreach ($raw as $entry) {
            $position++;

            if (count($groups) >= self::MAX_GROUPS) {
                $problems[] = sprintf('More than %d option groups; the rest were dropped.', self::MAX_GROUPS);
                break;
            }

            $errors = [];
            $group = is_array($entry) ? self::parseGroup($entry, $position - 1, $errors, $seen) : null;

            if ($group === null) {
                $problems[] = sprintf('Option group %d was dropped: %s', $position, implode(' ', $errors) ?: 'invalid.');
                continue;
            }

            $seen[$group['id']] = true;
            $groups[] = $group;
        }

        return new self($groups, $problems);
    }

    /**
     * A write payload.
     *
     * Strict: every problem is a named field error and nothing is dropped. A
     * shop author who mistypes a price delta must be told, not handed a product
     * whose gift-wrap option quietly costs nothing.
     *
     * @throws ApiException
     */
    public static function fromPayload(mixed $payload): self
    {
        if ($payload === null || $payload === [] || $payload === '') {
            return self::empty();
        }

        if (!is_array($payload)) {
            throw ApiException::invalidRequest('The product data is invalid.', [
                'fields' => ['options' => 'Must be an object with a "groups" list, or null to clear.'],
            ]);
        }

        $raw = array_key_exists('groups', $payload) ? $payload['groups'] : $payload;

        if (!is_array($raw)) {
            throw ApiException::invalidRequest('The product data is invalid.', [
                'fields' => ['options.groups' => 'Must be a list of option groups.'],
            ]);
        }

        if (count($raw) > self::MAX_GROUPS) {
            throw ApiException::invalidRequest('The product data is invalid.', [
                'fields' => ['options.groups' => 'At most ' . self::MAX_GROUPS . ' groups.'],
            ]);
        }

        $groups = [];
        $errors = [];
        $seen = [];
        $index = 0;

        foreach (array_values($raw) as $entry) {
            if (!is_array($entry)) {
                $errors["options.groups[{$index}]"] = 'Must be an object.';
                $index++;
                continue;
            }

            $group = self::parseGroup($entry, $index, $errors, $seen);

            if ($group !== null) {
                $seen[$group['id']] = true;
                $groups[] = $group;
            }

            $index++;
        }

        if ($errors !== []) {
            throw ApiException::invalidRequest('The product data is invalid.', ['fields' => $errors]);
        }

        return new self($groups, []);
    }

    /**
     * One group, validated. Returns null when it could not be built, having
     * written the reasons into `$errors`.
     *
     * @param array<string, mixed> $entry
     * @param array<string, string> $errors
     * @param array<string, true>   $seen  group ids already taken
     * @return array<string, mixed>|null
     */
    private static function parseGroup(array $entry, int $index, array &$errors, array $seen): ?array
    {
        $field = "options.groups[{$index}]";

        $type = is_string($entry['type'] ?? null) ? trim($entry['type']) : '';

        if (!in_array($type, self::TYPES, true)) {
            $errors[$field . '.type'] = 'Must be one of: ' . implode(', ', self::TYPES) . '.';

            return null;
        }

        $allowed = match ($type) {
            'choice' => ['id', 'type', 'label', 'required', 'min', 'max', 'choices'],
            'text' => ['id', 'type', 'label', 'required', 'max_length', 'price_delta'],
            'bundle' => ['id', 'type', 'label', 'items'],
        };

        $unknown = array_diff(array_keys($entry), $allowed);

        if ($unknown !== []) {
            $errors[$field] = 'Unknown keys for a ' . $type . ' group: ' . implode(', ', $unknown) . '.';

            return null;
        }

        $id = is_string($entry['id'] ?? null) ? strtolower(trim($entry['id'])) : '';

        if (preg_match(self::ID_PATTERN, $id) !== 1) {
            $errors[$field . '.id'] = 'Must be 1–32 characters of a–z, 0–9, hyphen or underscore.';

            return null;
        }

        if (isset($seen[$id])) {
            $errors[$field . '.id'] = "Duplicate group id \"{$id}\".";

            return null;
        }

        $label = self::label($entry['label'] ?? null, $field . '.label', $errors);

        if ($label === null) {
            return null;
        }

        $group = ['id' => $id, 'type' => $type, 'label' => $label];

        return match ($type) {
            'choice' => self::choiceGroup($entry, $group, $field, $errors),
            'text' => self::textGroup($entry, $group, $field, $errors),
            'bundle' => self::bundleGroup($entry, $group, $field, $errors),
        };
    }

    /**
     * @param array<string, mixed>  $entry
     * @param array<string, mixed>  $group
     * @param array<string, string> $errors
     * @return array<string, mixed>|null
     */
    private static function choiceGroup(array $entry, array $group, string $field, array &$errors): ?array
    {
        $raw = $entry['choices'] ?? null;

        if (!is_array($raw) || $raw === []) {
            $errors[$field . '.choices'] = 'A choice group needs at least one choice.';

            return null;
        }

        if (count($raw) > self::MAX_CHOICES) {
            $errors[$field . '.choices'] = 'At most ' . self::MAX_CHOICES . ' choices.';

            return null;
        }

        $choices = [];
        $seen = [];
        $index = 0;

        foreach (array_values($raw) as $entryChoice) {
            $choiceField = $field . ".choices[{$index}]";
            $index++;

            if (!is_array($entryChoice)) {
                $errors[$choiceField] = 'Must be an object.';
                continue;
            }

            $unknown = array_diff(array_keys($entryChoice), ['id', 'label', 'price_delta', 'image_id']);

            if ($unknown !== []) {
                $errors[$choiceField] = 'Unknown keys: ' . implode(', ', $unknown) . '.';
                continue;
            }

            $id = is_string($entryChoice['id'] ?? null) ? strtolower(trim($entryChoice['id'])) : '';

            if (preg_match(self::ID_PATTERN, $id) !== 1) {
                $errors[$choiceField . '.id'] = 'Must be 1–32 characters of a–z, 0–9, hyphen or underscore.';
                continue;
            }

            if (isset($seen[$id])) {
                $errors[$choiceField . '.id'] = "Duplicate choice id \"{$id}\".";
                continue;
            }

            $label = self::label($entryChoice['label'] ?? null, $choiceField . '.label', $errors);

            if ($label === null) {
                continue;
            }

            $delta = self::delta($entryChoice['price_delta'] ?? '0', $choiceField . '.price_delta', $errors);

            if ($delta === null) {
                continue;
            }

            $imageId = $entryChoice['image_id'] ?? 0;

            /*
             * §83 refuses per-option uploads: `POST /media` is the highest-risk
             * endpoint in this API (docs/SECURITY.md → "File uploads") and an
             * option group is not a reason to widen its call sites. A choice
             * references an attachment that already exists, or nothing. That
             * the id *is* an image is `ProductRepository::assertImageAttachment()`,
             * which needs the database and already exists for product images.
             */
            if (!is_numeric($imageId) || (int) $imageId < 0) {
                $errors[$choiceField . '.image_id'] = 'Must be an existing attachment id, or 0 for none.';
                continue;
            }

            $seen[$id] = true;
            $choices[] = [
                'id' => $id,
                'label' => $label,
                'price_delta' => $delta,
                'image_id' => (int) $imageId,
            ];
        }

        if ($choices === []) {
            return null;
        }

        $required = self::bool($entry['required'] ?? false);
        $min = self::count($entry['min'] ?? 0, $field . '.min', $errors);
        $max = self::count($entry['max'] ?? 1, $field . '.max', $errors);

        if ($min === null || $max === null) {
            return null;
        }

        if ($max < 1) {
            $errors[$field . '.max'] = 'Must be at least 1.';

            return null;
        }

        if ($min > $max) {
            $errors[$field . '.min'] = 'Cannot be greater than max.';

            return null;
        }

        if ($max > count($choices)) {
            $errors[$field . '.max'] = 'Cannot be greater than the number of choices.';

            return null;
        }

        /*
         * Refused rather than normalised. "Required, choose at least none" is
         * two settings contradicting each other, and quietly raising `min` to 1
         * would make the stored document disagree with what was written — §61's
         * argument against a section that silently changes shape.
         */
        if ($required && $min < 1) {
            $errors[$field . '.min'] = 'A required group needs a min of at least 1.';

            return null;
        }

        return $group + ['required' => $required, 'min' => $min, 'max' => $max, 'choices' => $choices];
    }

    /**
     * @param array<string, mixed>  $entry
     * @param array<string, mixed>  $group
     * @param array<string, string> $errors
     * @return array<string, mixed>|null
     */
    private static function textGroup(array $entry, array $group, string $field, array &$errors): ?array
    {
        $maxLength = $entry['max_length'] ?? self::DEFAULT_TEXT_LENGTH;

        if (!is_numeric($maxLength) || (int) $maxLength < 1 || (int) $maxLength > self::MAX_TEXT_LENGTH) {
            $errors[$field . '.max_length'] = 'Must be between 1 and ' . self::MAX_TEXT_LENGTH . '.';

            return null;
        }

        $delta = self::delta($entry['price_delta'] ?? '0', $field . '.price_delta', $errors);

        if ($delta === null) {
            return null;
        }

        return $group + [
            'required' => self::bool($entry['required'] ?? false),
            'max_length' => (int) $maxLength,
            'price_delta' => $delta,
        ];
    }

    /**
     * @param array<string, mixed>  $entry
     * @param array<string, mixed>  $group
     * @param array<string, string> $errors
     * @return array<string, mixed>|null
     */
    private static function bundleGroup(array $entry, array $group, string $field, array &$errors): ?array
    {
        $raw = $entry['items'] ?? null;

        if (!is_array($raw) || $raw === []) {
            $errors[$field . '.items'] = 'A bundle group needs at least one component.';

            return null;
        }

        if (count($raw) > self::MAX_BUNDLE_ITEMS) {
            $errors[$field . '.items'] = 'At most ' . self::MAX_BUNDLE_ITEMS . ' components.';

            return null;
        }

        $items = [];
        $seen = [];
        $index = 0;

        foreach (array_values($raw) as $item) {
            $itemField = $field . ".items[{$index}]";
            $index++;

            if (!is_array($item) || array_diff(array_keys($item), ['product_id', 'quantity']) !== []) {
                $errors[$itemField] = 'Must be an object with product_id and quantity.';
                continue;
            }

            $productId = $item['product_id'] ?? 0;
            $quantity = $item['quantity'] ?? 1;

            if (!is_numeric($productId) || (int) $productId < 1) {
                $errors[$itemField . '.product_id'] = 'Must be a positive product id.';
                continue;
            }

            if (!is_numeric($quantity) || (int) $quantity < 1 || (int) $quantity > 999) {
                $errors[$itemField . '.quantity'] = 'Must be between 1 and 999.';
                continue;
            }

            if (isset($seen[(int) $productId])) {
                $errors[$itemField . '.product_id'] = 'The same component is listed twice.';
                continue;
            }

            $seen[(int) $productId] = true;
            $items[] = ['product_id' => (int) $productId, 'quantity' => (int) $quantity];
        }

        if ($items === []) {
            return null;
        }

        return $group + ['items' => $items];
    }

    /** @param array<string, string> $errors */
    private static function label(mixed $raw, string $field, array &$errors): ?string
    {
        if (!is_scalar($raw)) {
            $errors[$field] = 'Is required.';

            return null;
        }

        $label = trim((string) $raw);

        if ($label === '') {
            $errors[$field] = 'Is required.';

            return null;
        }

        if (mb_strlen($label) > self::MAX_LABEL) {
            $errors[$field] = 'At most ' . self::MAX_LABEL . ' characters.';

            return null;
        }

        return $label;
    }

    /**
     * A price delta, kept as a decimal string.
     *
     * **Negative is legal.** "Without the presentation box, −200" is a real
     * option and refusing it would push shops into modelling a discount as a
     * coupon that anyone can guess. What is *not* legal is a delta that takes a
     * line below zero, and that cannot be decided here — it depends on the
     * product's price and on which other options were chosen, so
     * `OptionSelection` decides it at the moment of pricing.
     *
     * @param array<string, string> $errors
     */
    private static function delta(mixed $raw, string $field, array &$errors): ?string
    {
        if ($raw === '' || $raw === null) {
            return '0';
        }

        if (!is_scalar($raw) || !is_numeric((string) $raw)) {
            $errors[$field] = 'Must be a number.';

            return null;
        }

        return (string) $raw;
    }

    /** @param array<string, string> $errors */
    private static function count(mixed $raw, string $field, array &$errors): ?int
    {
        if (!is_numeric($raw) || (int) $raw < 0 || (int) $raw > self::MAX_CHOICES) {
            $errors[$field] = 'Must be a whole number between 0 and ' . self::MAX_CHOICES . '.';

            return null;
        }

        return (int) $raw;
    }

    private static function bool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower(trim((string) (is_scalar($value) ? $value : ''))), ['1', 'true', 'yes', 'on'], true);
    }
}
