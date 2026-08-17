<?php

declare(strict_types=1);

namespace AlgerianCommerce\Products;

use AlgerianCommerce\API\ApiException;
use WC_Product;

/**
 * Where an option set lives — roadmap §83.
 *
 * One product meta key holding one JSON document. **No migration and no
 * table**, per §83 and following §61 and §62: WordPress stores the content, we
 * store the structure. The trigger for `ac_option_sets` is named in `OptionSet`
 * and it is sharing across products, which nothing needs yet.
 *
 * The key is underscore-prefixed so WooCommerce and wp-admin treat it as
 * protected meta rather than offering it as a custom field for somebody to
 * hand-edit into a shape `OptionSet::fromStored()` then has to report on.
 *
 * This class is the only place the document meets the database, which is where
 * the two checks that need it live: an image must really be an attachment, and
 * a bundle component must really be a product — and not this product.
 */
final class OptionSetRepository
{
    public const META_KEY = '_ac_option_set';

    /** Kept per request; a cart mutation reads the same product several times. */
    private array $cache = [];

    public function forProduct(WC_Product $product): OptionSet
    {
        return $this->find($product->get_id());
    }

    public function find(int $productId): OptionSet
    {
        if (isset($this->cache[$productId])) {
            return $this->cache[$productId];
        }

        $stored = get_post_meta($productId, self::META_KEY, true);

        return $this->cache[$productId] = OptionSet::fromStored($stored);
    }

    /**
     * A variation inherits its parent's options.
     *
     * The definition belongs to the product a shopper is configuring, and a
     * variation is that product in one size. Storing a set per variation would
     * be the combinatorial explosion `OptionSet` exists to avoid, one level
     * down.
     */
    public function forPurchase(WC_Product $product): OptionSet
    {
        $parentId = (int) $product->get_parent_id();

        return $parentId > 0 ? $this->find($parentId) : $this->find($product->get_id());
    }

    public function save(int $productId, OptionSet $set): void
    {
        $this->cache[$productId] = $set;

        if ($set->isEmpty()) {
            delete_post_meta($productId, self::META_KEY);

            return;
        }

        /*
         * `wp_slash()` because `update_post_meta()` unslashes what it is given,
         * and a JSON document is full of quotes and backslashes. Without it a
         * label reading O'Brien comes back one save later as O'Brien with the
         * escape eaten, and a `é` becomes a broken byte.
         */
        update_post_meta($productId, self::META_KEY, wp_slash(wp_json_encode($set->toArray())));
    }

    /**
     * The checks that need the database, run before anything is stored.
     *
     * @throws ApiException
     */
    public function assertReferences(OptionSet $set, int $productId): void
    {
        $errors = [];

        foreach ($set->groups as $index => $group) {
            $field = "options.groups[{$index}]";

            foreach ($group['choices'] ?? [] as $choiceIndex => $choice) {
                if ((int) $choice['image_id'] === 0) {
                    continue;
                }

                if (!$this->isImage((int) $choice['image_id'])) {
                    $errors["{$field}.choices[{$choiceIndex}].image_id"] =
                        $choice['image_id'] . ' is not an image attachment.';
                }
            }

            foreach ($group['items'] ?? [] as $itemIndex => $item) {
                $componentField = "{$field}.items[{$itemIndex}].product_id";
                $component = wc_get_product($item['product_id']);

                if (!$component instanceof WC_Product) {
                    $errors[$componentField] = 'No product with id ' . $item['product_id'] . '.';
                    continue;
                }

                /*
                 * A bundle that contains itself is an infinite stock
                 * decrement, and it is the sort of thing that gets typed once.
                 * Only the direct case is caught: a chain — A contains B, B
                 * contains A — would need a graph walk on every write, and
                 * `BundleStock` deliberately does not recurse, so a nested
                 * bundle's own components are simply not drawn from. That
                 * limitation is named in `BundleStock`.
                 */
                if ((int) $item['product_id'] === $productId) {
                    $errors[$componentField] = 'A bundle cannot contain itself.';
                    continue;
                }

                if ($component->is_type('variable')) {
                    $errors[$componentField] =
                        'A variable product cannot be a bundle component; name one of its variations.';
                }
            }
        }

        if ($errors !== []) {
            throw ApiException::invalidRequest('The product data is invalid.', ['fields' => $errors]);
        }
    }

    private function isImage(int $id): bool
    {
        $post = get_post($id);

        return is_object($post) && $post->post_type === 'attachment' && wp_attachment_is_image($id);
    }
}
