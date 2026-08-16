<?php

declare(strict_types=1);

namespace AlgerianCommerce\Coupons;

use WC_Coupon;
use WP_Query;

/**
 * Reads and writes coupons through WooCommerce's own CRUD — docs/PLAN.md §21.
 *
 * **Coupons are still posts**, and that is not an oversight to be corrected:
 * HPOS moved *orders* to custom tables and left `shop_coupon` where it was, so
 * `WP_Query` is WooCommerce's current storage for them. The rule CLAUDE.md sets
 * — never `get_post()` against orders — is about orders specifically, and
 * applying it here would mean inventing a query API WooCommerce has not.
 *
 * There is no `wc_get_coupons()` to mirror `wc_get_products()`. Listing
 * therefore goes through `WP_Query` on the post type and hydrates each row into
 * a `WC_Coupon`, so everything above this class works with the same object the
 * cart and the order use when they apply a discount.
 */
final class CouponRepository
{
    public const POST_TYPE = 'shop_coupon';

    /** @var list<string> */
    public const ORDERBY = ['date', 'id', 'code', 'usage'];

    public function find(int $id): ?WC_Coupon
    {
        if ($id <= 0 || get_post_type($id) !== self::POST_TYPE) {
            return null;
        }

        $coupon = new WC_Coupon($id);

        return $coupon->get_id() > 0 ? $coupon : null;
    }

    /**
     * A coupon by its code, whatever case it was typed in.
     *
     * `wc_get_coupon_id_by_code()` is WooCommerce's own lookup and it expects
     * the normalised form, which is why `CouponInput` lower-cases on the way in.
     */
    public function findByCode(string $code): ?WC_Coupon
    {
        $code = wc_format_coupon_code(trim($code));

        if ($code === '') {
            return null;
        }

        $id = (int) wc_get_coupon_id_by_code($code);

        return $id > 0 ? $this->find($id) : null;
    }

    /**
     * @param array{page: int, per_page: int, search: string, status: string, orderby: string, order: string} $criteria
     * @return array{items: list<WC_Coupon>, total: int}
     */
    public function paginate(array $criteria): array
    {
        $args = [
            'post_type' => self::POST_TYPE,
            'post_status' => $criteria['status'] !== '' ? $criteria['status'] : ['publish', 'draft'],
            'posts_per_page' => $criteria['per_page'],
            'paged' => $criteria['page'],
            'orderby' => 'date',
            'order' => strtoupper($criteria['order']) === 'ASC' ? 'ASC' : 'DESC',
            // A coupon list is an admin screen, never a hot path, and skipping
            // the term cache would make each row re-query its restrictions.
            'no_found_rows' => false,
        ];

        if ($criteria['search'] !== '') {
            // The code is the post title, so a search is a title search — a
            // coupon's description is not what anyone looks one up by.
            $args['s'] = $criteria['search'];
        }

        $args = self::applyOrderby($args, $criteria['orderby']);

        $query = new WP_Query($args);
        $items = [];

        foreach ($query->posts as $post) {
            $coupon = $this->find((int) $post->ID);

            if ($coupon !== null) {
                $items[] = $coupon;
            }
        }

        return ['items' => $items, 'total' => (int) $query->found_posts];
    }

    public function create(CouponInput $input): WC_Coupon
    {
        $coupon = new WC_Coupon();

        $this->apply($coupon, $input);
        $coupon->save();

        return $coupon;
    }

    public function update(WC_Coupon $coupon, CouponInput $input): WC_Coupon
    {
        $this->apply($coupon, $input);
        $coupon->save();

        return new WC_Coupon($coupon->get_id());
    }

    /**
     * Delete, or trash.
     *
     * `force` matches the products endpoint, and for the same reason: a trashed
     * coupon still holds its code, so a shop that trashes `SUMMER10` and tries
     * to recreate it needs the conflict to say so rather than to fail inside
     * WooCommerce. `CouponService::guardCode()` is where that check lives.
     */
    public function delete(WC_Coupon $coupon, bool $force): bool
    {
        return $coupon->delete($force);
    }

    /** Whether a code is taken, including by a trashed coupon. */
    public function codeExists(string $code, int $ignoreId = 0): bool
    {
        $code = wc_format_coupon_code(trim($code));

        if ($code === '') {
            return false;
        }

        $query = new WP_Query([
            'post_type' => self::POST_TYPE,
            'post_status' => ['publish', 'draft', 'pending', 'private', 'trash'],
            'title' => $code,
            'posts_per_page' => 2,
            'fields' => 'ids',
            'no_found_rows' => true,
        ]);

        foreach ($query->posts as $id) {
            if ((int) $id !== $ignoreId) {
                return true;
            }
        }

        return false;
    }

    private function apply(WC_Coupon $coupon, CouponInput $input): void
    {
        $setters = [
            'code' => 'set_code',
            'discount_type' => 'set_discount_type',
            'amount' => 'set_amount',
            'description' => 'set_description',
            'minimum_amount' => 'set_minimum_amount',
            'maximum_amount' => 'set_maximum_amount',
            'usage_limit' => 'set_usage_limit',
            'usage_limit_per_user' => 'set_usage_limit_per_user',
            'limit_usage_to_x_items' => 'set_limit_usage_to_x_items',
            'individual_use' => 'set_individual_use',
            'free_shipping' => 'set_free_shipping',
            'exclude_sale_items' => 'set_exclude_sale_items',
            'product_ids' => 'set_product_ids',
            'excluded_product_ids' => 'set_excluded_product_ids',
            'product_categories' => 'set_product_categories',
            'excluded_product_categories' => 'set_excluded_product_categories',
            'email_restrictions' => 'set_email_restrictions',
        ];

        foreach ($setters as $field => $setter) {
            if ($input->has($field)) {
                $coupon->{$setter}($input->get($field));
            }
        }

        if ($input->has('date_expires')) {
            $coupon->set_date_expires($input->get('date_expires'));
        }

        if ($input->has('status')) {
            $coupon->set_status((string) $input->get('status'));
        }
    }

    /**
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    private static function applyOrderby(array $args, string $orderby): array
    {
        switch ($orderby) {
            case 'id':
                $args['orderby'] = 'ID';
                break;
            case 'code':
                $args['orderby'] = 'title';
                break;
            case 'usage':
                $args['orderby'] = 'meta_value_num';
                $args['meta_key'] = 'usage_count';
                break;
            default:
                $args['orderby'] = 'date';
        }

        return $args;
    }
}
