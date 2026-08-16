<?php

declare(strict_types=1);

namespace AlgerianCommerce\Seed;

use AlgerianCommerce\Commerce\AddressInput;
use AlgerianCommerce\Coupons\CouponInput;
use AlgerianCommerce\Orders\OrderStatus;
use AlgerianCommerce\Products\ProductInput;
use AlgerianCommerce\Products\VariationInput;

/**
 * Validates the development seed datasets — roadmap §67, docs/PLAN.md §46.
 *
 * `Geography\GeoDataset` is the model, for the same reason: pure, no WordPress,
 * so every rule about the fixtures is a unit test rather than something you
 * discover by running the seeder against a live database and reading the
 * wreckage. Validate everything, then write.
 *
 * It does **not** re-implement the write validation. `ProductInput`,
 * `CouponInput` and `OrderInput` still see every payload, and they are the
 * authority on what a price or a coupon amount may be — a second copy of those
 * rules here would be a second copy that disagrees. What this checks is the
 * layer above: that the fixtures are internally consistent (a product's
 * category exists, an order's SKU exists, a variation's attribute is one the
 * parent actually offers), and the one rule that has no other home —
 * **§46's "never use real customer data"**, which is enforced against the
 * email domain rather than left as a comment somebody has to obey.
 *
 * Every problem is reported, not just the first: a seed run writes nothing at
 * all if any file is bad, so the operator needs the whole list.
 */
final class SeedDataset
{
    /**
     * Domains a seeded shopper may live on.
     *
     * RFC 6761 reserves `.test`, `.example`, `.invalid` and `.localhost`, and
     * RFC 2606 reserves `example.com` and its siblings. None of them resolve
     * anywhere, which is the property that matters: `send-notifications` drains
     * a queue full of seeded orders, and a fixture carrying a colleague's real
     * address would mail them. §46 says not to use real customer data; this is
     * that sentence with teeth.
     *
     * @var list<string>
     */
    public const TEST_DOMAINS = ['.test', '.example', '.invalid', '.localhost', 'example.com', 'example.net', 'example.org'];

    /** Product keys the seeder owns; everything else is handed to ProductInput. */
    private const PRODUCT_SEED_KEYS = ['categories', 'variations', 'low_stock_amount'];

    private const CUSTOMER_KEYS = ['email', 'first_name', 'last_name', 'phone', 'billing'];

    private const ORDER_KEYS = [
        'ref', 'customer', 'status', 'final_status', 'payment_method',
        'payment_method_title', 'customer_note', 'billing', 'shipping', 'items',
    ];

    private const COUPON_KEYS = [
        'code', 'discount_type', 'amount', 'description', 'status', 'date_expires',
        'individual_use', 'free_shipping', 'exclude_sale_items',
        'minimum_amount', 'maximum_amount',
        'usage_limit', 'usage_limit_per_user', 'limit_usage_to_x_items',
        'product_categories',
    ];

    /**
     * Categories and products.
     *
     * @return array{
     *     categories: list<array{slug: string, name: string, description: string}>,
     *     products: list<array<string, mixed>>,
     *     errors: list<string>
     * }
     */
    public static function catalogue(mixed $decoded): array
    {
        $result = ['categories' => [], 'products' => [], 'errors' => []];

        if (!is_array($decoded)) {
            $result['errors'][] = 'catalogue.json must contain an object.';

            return $result;
        }

        $categories = self::listAt($decoded, 'categories');
        $products = self::listAt($decoded, 'products');

        if ($categories === null) {
            $result['errors'][] = 'catalogue.json must have a "categories" list.';
        }

        if ($products === null) {
            $result['errors'][] = 'catalogue.json must have a "products" list.';
        }

        if ($result['errors'] !== []) {
            return $result;
        }

        /** @var list<mixed> $categories */
        /** @var list<mixed> $products */
        $slugs = [];

        foreach ($categories as $index => $entry) {
            $where = "categories[{$index}]";

            if (!is_array($entry)) {
                $result['errors'][] = "{$where} must be an object.";
                continue;
            }

            $unknown = array_diff(array_keys($entry), ['slug', 'name', 'description']);

            if ($unknown !== []) {
                $result['errors'][] = "{$where} has unknown keys: " . implode(', ', $unknown) . '.';
                continue;
            }

            $slug = self::text($entry['slug'] ?? null);
            $name = self::text($entry['name'] ?? null);

            if ($slug === '' || preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $slug) !== 1) {
                $result['errors'][] = "{$where} needs a lowercase hyphenated slug.";
                continue;
            }

            if ($name === '') {
                $result['errors'][] = "{$where} ({$slug}) needs a name.";
                continue;
            }

            if (isset($slugs[$slug])) {
                $result['errors'][] = "categories: \"{$slug}\" appears more than once.";
                continue;
            }

            $slugs[$slug] = true;
            $result['categories'][] = [
                'slug' => $slug,
                'name' => $name,
                'description' => self::text($entry['description'] ?? null),
            ];
        }

        // Every SKU in the file — a variation's SKU shares one namespace with a
        // product's, because WooCommerce enforces uniqueness across both.
        $skus = [];

        foreach ($products as $index => $entry) {
            $where = "products[{$index}]";

            if (!is_array($entry)) {
                $result['errors'][] = "{$where} must be an object.";
                continue;
            }

            $row = self::product($entry, $where, $slugs, $skus, $result['errors']);

            if ($row !== null) {
                $result['products'][] = $row;
            }
        }

        return $result;
    }

    /**
     * @param array<string, mixed>  $entry
     * @param array<string, true>   $categorySlugs
     * @param array<string, true>   $skus       by reference: SKUs are unique across the file
     * @param list<string>          $errors     by reference
     * @return array<string, mixed>|null
     */
    private static function product(
        array $entry,
        string $where,
        array $categorySlugs,
        array &$skus,
        array &$errors
    ): ?array {
        $allowed = [...ProductInput::allowedFields(), ...self::PRODUCT_SEED_KEYS];
        $unknown = array_diff(array_keys($entry), $allowed);

        if ($unknown !== []) {
            $errors[] = "{$where} has unknown keys: " . implode(', ', $unknown) . '.';

            return null;
        }

        $sku = self::text($entry['sku'] ?? null);
        $name = self::text($entry['name'] ?? null);
        $type = self::text($entry['type'] ?? null) ?: 'simple';

        if ($sku === '') {
            $errors[] = "{$where} needs a sku — it is the seeder's idempotency key.";

            return null;
        }

        $label = "{$where} ({$sku})";

        if ($name === '') {
            $errors[] = "{$label} needs a name.";

            return null;
        }

        if (!in_array($type, ProductInput::TYPES, true)) {
            $errors[] = "{$label} has type \"{$type}\"; allowed: " . implode(', ', ProductInput::TYPES) . '.';

            return null;
        }

        if (isset($skus[strtolower($sku)])) {
            $errors[] = "products: sku \"{$sku}\" appears more than once.";

            return null;
        }

        $skus[strtolower($sku)] = true;

        $categories = $entry['categories'] ?? [];

        if (!is_array($categories) || $categories === []) {
            $errors[] = "{$label} needs at least one category.";

            return null;
        }

        $resolved = [];

        foreach ($categories as $slug) {
            $slug = self::text($slug);

            if (!isset($categorySlugs[$slug])) {
                $errors[] = "{$label} is in category \"{$slug}\", which is not in this file.";

                return null;
            }

            $resolved[] = $slug;
        }

        $attributes = is_array($entry['attributes'] ?? null) ? $entry['attributes'] : [];
        $variationOptions = self::variationOptions($attributes);
        $rawVariations = $entry['variations'] ?? [];

        if ($type === 'variable') {
            if ($variationOptions === []) {
                $errors[] = "{$label} is variable but no attribute is marked \"variation\": true.";

                return null;
            }

            if (!is_array($rawVariations) || $rawVariations === []) {
                $errors[] = "{$label} is variable and has no variations — nothing would be buyable.";

                return null;
            }
        } else {
            if (is_array($rawVariations) && $rawVariations !== []) {
                $errors[] = "{$label} is simple and cannot have variations.";

                return null;
            }

            if ($variationOptions !== []) {
                $errors[] = "{$label} is simple and cannot have a \"variation\": true attribute.";

                return null;
            }

            if (self::text($entry['regular_price'] ?? null) === '') {
                // A variable product's price lives on its variations; a simple
                // one with no price is published at "free" and nobody notices.
                $errors[] = "{$label} needs a regular_price.";

                return null;
            }
        }

        $variations = [];
        $combinations = [];

        foreach (is_array($rawVariations) ? $rawVariations : [] as $vIndex => $variation) {
            $vWhere = "{$where}.variations[{$vIndex}]";

            if (!is_array($variation)) {
                $errors[] = "{$vWhere} must be an object.";
                continue;
            }

            $row = self::variation($variation, $vWhere, $variationOptions, $skus, $combinations, $errors);

            if ($row !== null) {
                $variations[] = $row;
            }
        }

        $lowStock = null;

        if (array_key_exists('low_stock_amount', $entry) && $entry['low_stock_amount'] !== null) {
            if (!is_numeric($entry['low_stock_amount']) || (int) $entry['low_stock_amount'] < 0) {
                $errors[] = "{$label} has a low_stock_amount that is not a whole number of zero or more.";

                return null;
            }

            $lowStock = (int) $entry['low_stock_amount'];
        }

        $fields = array_diff_key($entry, array_flip(self::PRODUCT_SEED_KEYS));
        $fields['type'] = $type;

        return [
            'sku' => $sku,
            'name' => $name,
            'type' => $type,
            'categories' => $resolved,
            'low_stock_amount' => $lowStock,
            'fields' => $fields,
            'variations' => $variations,
        ];
    }

    /**
     * The parent's variation attributes as `lowercase name => list of lowercase options`.
     *
     * Keyed exactly as `VariationService::variationAttributes()` keys them, so
     * a fixture that passes here is a fixture that passes the service. That
     * mapping is the whole reason this check is worth having: a variation whose
     * attribute name is capitalised differently from its parent's is accepted
     * by every JSON parser and refused by the API.
     *
     * @param array<mixed> $attributes
     * @return array<string, list<string>>
     */
    private static function variationOptions(array $attributes): array
    {
        $out = [];

        foreach ($attributes as $attribute) {
            if (!is_array($attribute) || empty($attribute['variation'])) {
                continue;
            }

            $name = strtolower(self::text($attribute['name'] ?? null));
            $options = is_array($attribute['options'] ?? null) ? $attribute['options'] : [];

            if ($name === '' || $options === []) {
                continue;
            }

            $out[$name] = array_map(
                static fn (mixed $option): string => strtolower(self::text($option)),
                array_values($options)
            );
        }

        return $out;
    }

    /**
     * @param array<string, mixed>        $entry
     * @param array<string, list<string>> $options
     * @param array<string, true>         $skus         by reference
     * @param array<string, true>         $combinations by reference
     * @param list<string>                $errors       by reference
     * @return array{sku: string, attributes: array<string, string>, fields: array<string, mixed>}|null
     */
    private static function variation(
        array $entry,
        string $where,
        array $options,
        array &$skus,
        array &$combinations,
        array &$errors
    ): ?array {
        $unknown = array_diff(array_keys($entry), VariationInput::allowedFields());

        if ($unknown !== []) {
            $errors[] = "{$where} has unknown keys: " . implode(', ', $unknown) . '.';

            return null;
        }

        $sku = self::text($entry['sku'] ?? null);

        if ($sku === '') {
            $errors[] = "{$where} needs a sku.";

            return null;
        }

        if (isset($skus[strtolower($sku)])) {
            $errors[] = "products: sku \"{$sku}\" appears more than once.";

            return null;
        }

        $attributes = $entry['attributes'] ?? null;

        if (!is_array($attributes) || $attributes === []) {
            $errors[] = "{$where} ({$sku}) needs an attributes map.";

            return null;
        }

        $normalized = [];

        foreach ($attributes as $name => $value) {
            $name = strtolower(self::text($name));
            $value = self::text($value);

            if (!isset($options[$name])) {
                $errors[] = "{$where} ({$sku}) sets \"{$name}\", which the parent does not vary on. "
                    . 'Parent offers: ' . (implode(', ', array_keys($options)) ?: 'nothing') . '.';

                return null;
            }

            if (!in_array(strtolower($value), $options[$name], true)) {
                $errors[] = "{$where} ({$sku}) sets {$name}=\"{$value}\", which is not one of the parent's options: "
                    . implode(', ', $options[$name]) . '.';

                return null;
            }

            $normalized[$name] = $value;
        }

        $missing = array_diff(array_keys($options), array_keys($normalized));

        if ($missing !== []) {
            // A variation that leaves an attribute blank is WooCommerce's "any"
            // — legal, but never what a fixture meant, and it silently overlaps
            // every other variation.
            $errors[] = "{$where} ({$sku}) does not set: " . implode(', ', $missing) . '.';

            return null;
        }

        ksort($normalized);
        $combination = strtolower(implode('|', $normalized));

        if (isset($combinations[$combination])) {
            $errors[] = "{$where} ({$sku}) repeats a combination another variation already claims.";

            return null;
        }

        $combinations[$combination] = true;
        $skus[strtolower($sku)] = true;

        return [
            'sku' => $sku,
            'attributes' => $normalized,
            'fields' => array_diff_key($entry, array_flip(['attributes'])),
        ];
    }

    /**
     * @return array{rows: list<array<string, mixed>>, errors: list<string>}
     */
    public static function customers(mixed $decoded): array
    {
        $result = ['rows' => [], 'errors' => []];
        $customers = is_array($decoded) ? self::listAt($decoded, 'customers') : null;

        if ($customers === null) {
            $result['errors'][] = 'customers.json must have a "customers" list.';

            return $result;
        }

        $seen = [];

        foreach ($customers as $index => $entry) {
            $where = "customers[{$index}]";

            if (!is_array($entry)) {
                $result['errors'][] = "{$where} must be an object.";
                continue;
            }

            $unknown = array_diff(array_keys($entry), self::CUSTOMER_KEYS);

            if ($unknown !== []) {
                $result['errors'][] = "{$where} has unknown keys: " . implode(', ', $unknown) . '.';
                continue;
            }

            $email = strtolower(self::text($entry['email'] ?? null));

            if ($email === '' || !str_contains($email, '@') || str_ends_with($email, '@')) {
                $result['errors'][] = "{$where} needs an email address.";
                continue;
            }

            if (!self::isTestAddress($email)) {
                $result['errors'][] = "{$where} ({$email}) is not on a reserved test domain. "
                    . 'docs/PLAN.md §46: never use real customer data as seed data. Allowed: '
                    . implode(', ', self::TEST_DOMAINS) . '.';
                continue;
            }

            if (isset($seen[$email])) {
                $result['errors'][] = "customers: \"{$email}\" appears more than once.";
                continue;
            }

            if (self::text($entry['first_name'] ?? null) === '' || self::text($entry['last_name'] ?? null) === '') {
                $result['errors'][] = "{$where} ({$email}) needs a first_name and a last_name.";
                continue;
            }

            $billing = self::address($entry['billing'] ?? [], "{$where}.billing", false, $result['errors']);

            if ($billing === null) {
                continue;
            }

            $seen[$email] = true;
            $result['rows'][] = [
                'email' => $email,
                'first_name' => self::text($entry['first_name']),
                'last_name' => self::text($entry['last_name']),
                'phone' => self::text($entry['phone'] ?? null),
                'billing' => $billing,
            ];
        }

        return $result;
    }

    /**
     * @param array<string, true> $categorySlugs
     * @return array{rows: list<array<string, mixed>>, errors: list<string>}
     */
    public static function coupons(mixed $decoded, array $categorySlugs): array
    {
        $result = ['rows' => [], 'errors' => []];
        $coupons = is_array($decoded) ? self::listAt($decoded, 'coupons') : null;

        if ($coupons === null) {
            $result['errors'][] = 'coupons.json must have a "coupons" list.';

            return $result;
        }

        $seen = [];

        foreach ($coupons as $index => $entry) {
            $where = "coupons[{$index}]";

            if (!is_array($entry)) {
                $result['errors'][] = "{$where} must be an object.";
                continue;
            }

            $unknown = array_diff(array_keys($entry), self::COUPON_KEYS);

            if ($unknown !== []) {
                $result['errors'][] = "{$where} has unknown keys: " . implode(', ', $unknown) . '.';
                continue;
            }

            // WooCommerce lowercases a coupon code on save, so two fixtures
            // differing only in case are one coupon fighting itself.
            $code = strtolower(self::text($entry['code'] ?? null));

            if ($code === '') {
                $result['errors'][] = "{$where} needs a code.";
                continue;
            }

            if (isset($seen[$code])) {
                $result['errors'][] = "coupons: \"{$code}\" appears more than once (codes are case-insensitive).";
                continue;
            }

            $type = self::text($entry['discount_type'] ?? null);

            if (!in_array($type, CouponInput::TYPES, true)) {
                $result['errors'][] = "{$where} ({$code}) has discount_type \"{$type}\"; allowed: "
                    . implode(', ', CouponInput::TYPES) . '.';
                continue;
            }

            $categories = $entry['product_categories'] ?? [];

            if (!is_array($categories)) {
                $result['errors'][] = "{$where} ({$code}) has a product_categories that is not a list.";
                continue;
            }

            $resolved = [];
            $badCategory = false;

            foreach ($categories as $slug) {
                $slug = self::text($slug);

                if (!isset($categorySlugs[$slug])) {
                    $result['errors'][] = "{$where} ({$code}) restricts to category \"{$slug}\", "
                        . 'which is not in catalogue.json.';
                    $badCategory = true;
                    break;
                }

                $resolved[] = $slug;
            }

            if ($badCategory) {
                continue;
            }

            $seen[$code] = true;
            $result['rows'][] = [
                'code' => $code,
                'categories' => $resolved,
                'fields' => array_diff_key($entry, array_flip(['product_categories'])),
            ];
        }

        return $result;
    }

    /**
     * @param array<string, true> $skus   every SKU the catalogue defines, lowercased
     * @param array<string, true> $emails every seeded customer, lowercased
     * @return array{rows: list<array<string, mixed>>, errors: list<string>}
     */
    public static function orders(mixed $decoded, array $skus, array $emails): array
    {
        $result = ['rows' => [], 'errors' => []];
        $orders = is_array($decoded) ? self::listAt($decoded, 'orders') : null;

        if ($orders === null) {
            $result['errors'][] = 'orders.json must have an "orders" list.';

            return $result;
        }

        $refs = [];

        foreach ($orders as $index => $entry) {
            $where = "orders[{$index}]";

            if (!is_array($entry)) {
                $result['errors'][] = "{$where} must be an object.";
                continue;
            }

            $unknown = array_diff(array_keys($entry), self::ORDER_KEYS);

            if ($unknown !== []) {
                $result['errors'][] = "{$where} has unknown keys: " . implode(', ', $unknown) . '.';
                continue;
            }

            $ref = self::text($entry['ref'] ?? null);

            if ($ref === '') {
                $result['errors'][] = "{$where} needs a ref — it is the seeder's idempotency key.";
                continue;
            }

            if (isset($refs[$ref])) {
                $result['errors'][] = "orders: ref \"{$ref}\" appears more than once.";
                continue;
            }

            $customer = $entry['customer'] ?? null;
            $customer = $customer === null ? null : strtolower(self::text($customer));

            if ($customer !== null && !isset($emails[$customer])) {
                $result['errors'][] = "{$where} ({$ref}) belongs to \"{$customer}\", who is not in customers.json.";
                continue;
            }

            $status = self::text($entry['status'] ?? null) ?: OrderStatus::PENDING;

            if (!OrderStatus::canCreateAs($status)) {
                $result['errors'][] = "{$where} ({$ref}) cannot be created as \"{$status}\"; allowed: "
                    . implode(', ', OrderStatus::CREATABLE) . '.';
                continue;
            }

            $final = self::text($entry['final_status'] ?? null);

            if ($final !== '') {
                if ($final === $status) {
                    $result['errors'][] = "{$where} ({$ref}) has a final_status equal to its status.";
                    continue;
                }

                if (!OrderStatus::canTransition($status, $final)) {
                    // Reaching `cancelled` or `refunded` is what final_status is
                    // for, and both are reachable only from somewhere.
                    $result['errors'][] = "{$where} ({$ref}) cannot move {$status} → {$final}; allowed from "
                        . "{$status}: " . implode(', ', OrderStatus::allowedFrom($status)) . '.';
                    continue;
                }
            }

            $items = $entry['items'] ?? null;

            if (!is_array($items) || $items === []) {
                $result['errors'][] = "{$where} ({$ref}) needs at least one item.";
                continue;
            }

            $lines = [];
            $badLine = false;

            foreach ($items as $lineIndex => $item) {
                $lineWhere = "{$where}.items[{$lineIndex}]";

                if (!is_array($item)) {
                    $result['errors'][] = "{$lineWhere} must be an object.";
                    $badLine = true;
                    break;
                }

                $unknownLine = array_diff(array_keys($item), ['sku', 'quantity']);

                if ($unknownLine !== []) {
                    $result['errors'][] = "{$lineWhere} has unknown keys: " . implode(', ', $unknownLine) . '.';
                    $badLine = true;
                    break;
                }

                $sku = self::text($item['sku'] ?? null);
                $quantity = $item['quantity'] ?? null;

                if (!isset($skus[strtolower($sku)])) {
                    $result['errors'][] = "{$lineWhere} names sku \"{$sku}\", which catalogue.json does not define.";
                    $badLine = true;
                    break;
                }

                if (!is_numeric($quantity) || (int) $quantity < 1) {
                    $result['errors'][] = "{$lineWhere} needs a quantity of one or more.";
                    $badLine = true;
                    break;
                }

                $lines[] = ['sku' => $sku, 'quantity' => (int) $quantity];
            }

            if ($badLine) {
                continue;
            }

            $billing = self::address($entry['billing'] ?? [], "{$where}.billing", true, $result['errors']);
            $shipping = self::address($entry['shipping'] ?? [], "{$where}.shipping", false, $result['errors']);

            if ($billing === null || $shipping === null) {
                continue;
            }

            if ($customer === null && ($billing['email'] ?? '') === '') {
                // A guest order is reachable by nobody through /account/orders
                // (roadmap §59c), so its email is the only handle anyone has on
                // it. A guest fixture without one is a dead end.
                $result['errors'][] = "{$where} ({$ref}) is a guest order and needs a billing email.";
                continue;
            }

            $refs[$ref] = true;
            $result['rows'][] = [
                'ref' => $ref,
                'customer' => $customer,
                'status' => $status,
                'final_status' => $final === '' ? null : $final,
                'items' => $lines,
                'billing' => $billing,
                'shipping' => $shipping,
                'fields' => array_diff_key(
                    $entry,
                    array_flip(['ref', 'customer', 'status', 'final_status', 'items', 'billing', 'shipping'])
                ),
            ];
        }

        return $result;
    }

    /**
     * A billing or shipping block, checked against `AddressInput`'s own field
     * list rather than a second copy of it.
     *
     * @param list<string> $errors by reference
     * @return array<string, string>|null null when the block is unusable
     */
    private static function address(mixed $raw, string $where, bool $allowEmail, array &$errors): ?array
    {
        if ($raw === null || $raw === []) {
            return [];
        }

        if (!is_array($raw)) {
            $errors[] = "{$where} must be an object.";

            return null;
        }

        $allowed = $allowEmail
            ? [...AddressInput::FIELDS, ...AddressInput::BILLING_ONLY]
            : AddressInput::FIELDS;

        $unknown = array_diff(array_keys($raw), $allowed);

        if ($unknown !== []) {
            $errors[] = "{$where} has unknown keys: " . implode(', ', $unknown) . '.';

            return null;
        }

        $clean = [];

        foreach ($raw as $field => $value) {
            $clean[(string) $field] = self::text($value);
        }

        $email = $clean['email'] ?? '';

        if ($email !== '' && !self::isTestAddress(strtolower($email))) {
            $errors[] = "{$where} ({$email}) is not on a reserved test domain — docs/PLAN.md §46.";

            return null;
        }

        return $clean;
    }

    /** Is this address guaranteed to reach nobody? */
    public static function isTestAddress(string $email): bool
    {
        $at = strrpos($email, '@');

        if ($at === false) {
            return false;
        }

        $domain = strtolower(substr($email, $at + 1));

        foreach (self::TEST_DOMAINS as $reserved) {
            if (str_starts_with($reserved, '.')) {
                // A reserved top-level domain: anything under it is safe.
                if (str_ends_with($domain, $reserved)) {
                    return true;
                }

                continue;
            }

            // A reserved second-level domain. `shop.example.com` counts and
            // `badexample.com` does not, which is why the dot is not optional.
            if ($domain === $reserved || str_ends_with($domain, '.' . $reserved)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<mixed> $decoded
     * @return list<mixed>|null
     */
    private static function listAt(array $decoded, string $key): ?array
    {
        $value = $decoded[$key] ?? null;

        return is_array($value) && array_is_list($value) ? $value : null;
    }

    private static function text(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }
}
