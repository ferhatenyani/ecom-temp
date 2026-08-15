<?php

declare(strict_types=1);

namespace AlgerianCommerce\CMS;

use AlgerianCommerce\Media\MediaPresenter;
use WP_Post;
use WP_Term;

/**
 * CMS output shapes — roadmap §61.
 *
 * Content is returned **rendered**: `the_content` runs, so shortcodes and
 * blocks resolve here rather than in a Next.js component that would have to
 * reimplement them. What comes back is HTML by design, and it is HTML that only
 * an `ac_manage_content` holder can author — WordPress already runs
 * `wp_kses_post` over anything saved by a user without `unfiltered_html`.
 *
 * A storefront still renders it as HTML and therefore trusts it; that trust
 * stops at the same boundary the capability does, which is why no route here
 * accepts content and why §61 has no write surface.
 */
final class CmsPresenter
{
    /** @return array<string, mixed> */
    public static function page(WP_Post $page): array
    {
        return [
            'id' => (int) $page->ID,
            'slug' => $page->post_name,
            'title' => self::title($page),
            'content' => self::content($page),
            'excerpt' => self::excerpt($page),
            'parent_id' => (int) $page->post_parent,
            'menu_order' => (int) $page->menu_order,
            'image' => MediaPresenter::image((int) get_post_thumbnail_id($page)),
            'date_created' => mysql_to_rfc3339($page->post_date_gmt),
            'date_modified' => mysql_to_rfc3339($page->post_modified_gmt),
        ];
    }

    /** @param list<WP_Post> $banners @return list<array<string, mixed>> */
    public static function banners(array $banners): array
    {
        return array_values(array_map([self::class, 'banner'], $banners));
    }

    /** @return array<string, mixed> */
    public static function banner(WP_Post $banner): array
    {
        $link = (string) get_post_meta($banner->ID, ContentTypes::BANNER_LINK, true);
        $placement = (string) get_post_meta($banner->ID, ContentTypes::BANNER_PLACEMENT, true);

        return [
            'id' => (int) $banner->ID,
            'title' => self::title($banner),
            'caption' => self::content($banner),
            'link' => $link,
            'placement' => $placement === '' ? ContentTypes::DEFAULT_PLACEMENT : $placement,
            'position' => (int) $banner->menu_order,
            'image' => MediaPresenter::image((int) get_post_thumbnail_id($banner)),
            'date_modified' => mysql_to_rfc3339($banner->post_modified_gmt),
        ];
    }

    /** @param list<WP_Post> $faqs @return list<array<string, mixed>> */
    public static function faqs(array $faqs): array
    {
        return array_values(array_map([self::class, 'faq'], $faqs));
    }

    /** @return array<string, mixed> */
    public static function faq(WP_Post $faq): array
    {
        return [
            'id' => (int) $faq->ID,
            'question' => self::title($faq),
            'answer' => self::content($faq),
            'categories' => self::terms((int) $faq->ID),
            'position' => (int) $faq->menu_order,
            'date_modified' => mysql_to_rfc3339($faq->post_modified_gmt),
        ];
    }

    /**
     * A menu as a tree.
     *
     * Nested rather than flat because a navigation menu *is* a tree, and every
     * client that received the flat list would rebuild the same nesting from
     * `menu_item_parent` — once each, differently.
     *
     * @param object        $menu
     * @param list<WP_Post> $items
     * @return array<string, mixed>
     */
    public static function menu(string $location, object $menu, array $items): array
    {
        return [
            'location' => $location,
            'id' => (int) ($menu->term_id ?? 0),
            'name' => (string) ($menu->name ?? ''),
            'slug' => (string) ($menu->slug ?? ''),
            'items' => self::menuTree($items),
        ];
    }

    /**
     * @param list<WP_Post> $items
     * @return list<array<string, mixed>>
     */
    private static function menuTree(array $items): array
    {
        $byParent = [];

        foreach ($items as $item) {
            $byParent[(int) ($item->menu_item_parent ?? 0)][] = $item;
        }

        return self::childrenOf($byParent, 0);
    }

    /**
     * @param array<int, list<WP_Post>> $byParent
     * @return list<array<string, mixed>>
     */
    private static function childrenOf(array $byParent, int $parent): array
    {
        $out = [];

        foreach ($byParent[$parent] ?? [] as $item) {
            $id = (int) $item->ID;

            $out[] = [
                'id' => $id,
                'title' => (string) ($item->title ?? ''),
                /*
                 * The URL WordPress stored, untranslated. A storefront on its
                 * own domain has to map these to its own routes, and it is the
                 * only side that knows how — `object` and `object_id` are here
                 * so it can do that by id rather than by string surgery.
                 */
                'url' => (string) ($item->url ?? ''),
                'target' => (string) ($item->target ?? ''),
                'type' => (string) ($item->type ?? ''),
                'object' => (string) ($item->object ?? ''),
                'object_id' => (int) ($item->object_id ?? 0),
                'position' => (int) ($item->menu_order ?? 0),
                'classes' => array_values(array_filter(
                    is_array($item->classes ?? null) ? $item->classes : [],
                    static fn ($class): bool => is_string($class) && $class !== ''
                )),
                'children' => self::childrenOf($byParent, $id),
            ];
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    private static function terms(int $postId): array
    {
        $terms = get_the_terms($postId, ContentTypes::FAQ_CATEGORY);

        if (!is_array($terms)) {
            return [];
        }

        $out = [];

        foreach ($terms as $term) {
            if (!$term instanceof WP_Term) {
                continue;
            }

            $out[] = [
                'id' => (int) $term->term_id,
                'slug' => $term->slug,
                'name' => $term->name,
            ];
        }

        return $out;
    }

    private static function title(WP_Post $post): string
    {
        return (string) apply_filters('the_title', $post->post_title, $post->ID);
    }

    private static function content(WP_Post $post): string
    {
        return (string) apply_filters('the_content', $post->post_content);
    }

    private static function excerpt(WP_Post $post): string
    {
        if (trim($post->post_excerpt) !== '') {
            return (string) apply_filters('the_excerpt', $post->post_excerpt);
        }

        return wp_trim_words(wp_strip_all_tags(self::content($post)), 40);
    }
}
