<?php

declare(strict_types=1);

namespace AlgerianCommerce\CMS;

/**
 * The pages the shop owns, as opposed to the pages an editor writes.
 *
 * `GET /cms/pages` exists so a content manager can find a page without already
 * knowing its path. The moment such a list exists, it has a problem §89 never
 * had to think about, because §89 could only address one page at a time: a
 * WordPress install running WooCommerce has pages in it that nobody wrote.
 * Measured on this install, 2026-08-21:
 *
 *   shop            (empty body — it is a product archive)
 *   cart            <!-- wp:woocommerce/cart -->
 *   checkout        <!-- wp:woocommerce/checkout -->
 *   my-account      [woocommerce_my_account]
 *   privacy-policy  "Who we are", real headings, real prose
 *   refund_returns  real prose, and referenced by no option at all
 *
 * That measurement is the whole design here, because it shows the six are not
 * one kind of thing. Four of them have a body the shop *generates* — a block or
 * a shortcode — and editing one from a CMS screen is only ever a mistake. Two of
 * them are prose somebody has to be able to edit, and one of those two is
 * pointed at by an option, which means deleting it silently breaks a link the
 * storefront and the law both expect to work.
 *
 * So this class publishes two sets rather than one flag, and neither is a
 * hand-maintained list of paths: both are derived from options WordPress and
 * WooCommerce already store, so a shop that moves its checkout page is right
 * without anybody editing this file.
 *
 *   functional()  — the body is generated. Hidden from the index entirely.
 *   referenced()  — something stores this id. Refused for DELETE, at any status.
 *
 * `functional()` is a subset of `referenced()`. `refund_returns` is in neither,
 * which is the correct answer: it is an ordinary page that WooCommerce happened
 * to create, and an editor may rename, edit or delete it like any other.
 *
 * **The exclusion is not a UI concern and is deliberately not one.** Hiding the
 * checkout page from a list while `DELETE /cms/pages/checkout` still answers 200
 * would be a panel that is safe and an API that is not. The refusal below is
 * what makes it true for every caller.
 */
final class SystemPages
{
    /**
     * The page is a container for something the shop renders.
     *
     * A block, a shortcode, or nothing at all in the case of `shop`, which is a
     * product archive whose body is never displayed. There is no editorial
     * content in any of them to put on a CMS screen.
     */
    private const FUNCTIONAL_OPTIONS = [
        'page_on_front',
        'page_for_posts',
        'woocommerce_shop_page_id',
        'woocommerce_cart_page_id',
        'woocommerce_checkout_page_id',
        'woocommerce_myaccount_page_id',
        'woocommerce_view_order_page_id',
        'woocommerce_edit_address_page_id',
        'woocommerce_lost_password_page_id',
    ];

    /**
     * The body is prose, and an option stores the id.
     *
     * Both of these are pages a content manager legitimately edits — a privacy
     * policy is exactly the sort of thing this panel exists to change — so they
     * stay in the index and stay writable. Only the delete is refused, because
     * `wp_delete_post()` leaves the option pointing at nothing and WordPress
     * reports that as a missing page rather than as a broken setting.
     */
    private const REFERENCED_ONLY_OPTIONS = [
        'wp_page_for_privacy_policy',
        'woocommerce_terms_page_id',
    ];

    /**
     * Pages whose body the shop generates, and which the index therefore omits.
     *
     * Filtered to ids that resolve to a real page: an option left pointing at a
     * deleted post would otherwise put a phantom id into `post__not_in` and, more
     * to the point, would make `excluded_system` count something that is not
     * there.
     *
     * @return list<int>
     */
    public static function functional(): array
    {
        return self::existing(self::idsFrom(self::FUNCTIONAL_OPTIONS));
    }

    /**
     * Every page an option points at — the functional ones plus the prose ones.
     *
     * @return list<int>
     */
    public static function referenced(): array
    {
        return self::existing(self::idsFrom(
            array_merge(self::FUNCTIONAL_OPTIONS, self::REFERENCED_ONLY_OPTIONS)
        ));
    }

    /**
     * Which option claims this page, or null if none does.
     *
     * The name is returned rather than a boolean so the refusal can say *which*
     * setting to clear. "This page cannot be deleted" is a dead end; "this page
     * is registered as `woocommerce_checkout_page_id`" is something an
     * administrator can act on in one step.
     */
    public static function optionFor(int $pageId): ?string
    {
        if ($pageId <= 0) {
            return null;
        }

        foreach (array_merge(self::FUNCTIONAL_OPTIONS, self::REFERENCED_ONLY_OPTIONS) as $option) {
            if ((int) get_option($option) === $pageId) {
                return $option;
            }
        }

        return null;
    }

    /**
     * @param list<string> $options
     * @return list<int>
     */
    private static function idsFrom(array $options): array
    {
        $ids = [];

        foreach ($options as $option) {
            $id = (int) get_option($option);

            if ($id > 0) {
                $ids[$id] = $id;
            }
        }

        return array_values($ids);
    }

    /**
     * @param list<int> $ids
     * @return list<int>
     */
    private static function existing(array $ids): array
    {
        return array_values(array_filter($ids, static function (int $id): bool {
            $post = get_post($id);

            return $post !== null && $post->post_type === 'page';
        }));
    }
}
