<?php

declare(strict_types=1);

namespace AlgerianCommerce\CMS;

use AlgerianCommerce\Media\MediaPresenter;
use AlgerianCommerce\SEO\SeoFields;
use AlgerianCommerce\SEO\SeoResolver;
use AlgerianCommerce\SEO\SeoSubject;
use WP_Post;
use WP_Term;

/**
 * CMS output shapes — roadmap §61.
 *
 * Content is returned **rendered**: `the_content` runs, so shortcodes and
 * blocks resolve here rather than in a Next.js component that would have to
 * reimplement them. What comes back is HTML by design, and it is HTML that only
 * an `ac_manage_content` holder can author.
 *
 * **§61 said WordPress already runs `wp_kses_post` over anything saved by a
 * user without `unfiltered_html`, and the clause carried more weight than it
 * looked like.** An administrator *holds* `unfiltered_html`, so `kses_init()`
 * removes every filter for exactly the caller most able to do damage — measured
 * 2026-08-17, `wp_insert_post()` as an administrator stored
 * `<script>alert(1)</script>` and an `onclick` byte for byte. §89 stopped
 * depending on it: every field written through this API goes through
 * `ContentHtml`, whoever is signed in. Content that predates §89, or that was
 * written in wp-admin, carries no such guarantee — a storefront renders this
 * HTML and therefore trusts it, and that trust stops where the capability does.
 */
final class CmsPresenter
{
    /** @return array<string, mixed> */
    public static function page(WP_Post $page): array
    {
        $parentId = (int) $page->post_parent;

        return [
            'id' => (int) $page->ID,
            /*
             * The address, and the field that changes it, side by side. `path`
             * is what `/cms/pages/{path}` takes and is read-only here; `slug`
             * and `parent_path` are the two halves a write may move. Publishing
             * both spellings of the same fact is what makes "GET, edit, PATCH"
             * work without a client having to know how a path is assembled.
             */
            'path' => (string) get_page_uri($page),
            'slug' => $page->post_name,
            'parent_path' => $parentId > 0 ? (string) get_page_uri($parentId) : '',
            'status' => (string) $page->post_status,
            'title' => self::title($page),
            'content' => self::content($page),
            'excerpt' => self::excerpt($page),
            'parent_id' => $parentId,
            'menu_order' => (int) $page->menu_order,
            'image' => MediaPresenter::image((int) get_post_thumbnail_id($page)),
            'seo' => SeoResolver::resolve(new SeoSubject(
                (int) $page->ID,
                SeoSubject::TYPE_PAGE,
                $page->post_title,
                // The excerpt first: it is a summary somebody wrote, which is
                // what a meta description is. The body is the fallback.
                SeoFields::firstNonEmpty($page->post_excerpt, $page->post_content),
                (int) get_post_thumbnail_id($page),
                $page->post_name,
                $page->post_status === 'publish'
            )),
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
            'status' => (string) $banner->post_status,
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
            'status' => (string) $faq->post_status,
            'position' => (int) $faq->menu_order,
            'date_modified' => mysql_to_rfc3339($faq->post_modified_gmt),
        ];
    }

    /** @param list<WP_Term> $terms @return list<array<string, mixed>> */
    public static function faqCategories(array $terms): array
    {
        return array_values(array_map([self::class, 'faqCategory'], $terms));
    }

    /** @return array<string, mixed> */
    public static function faqCategory(WP_Term $term): array
    {
        return [
            'id' => (int) $term->term_id,
            'slug' => (string) $term->slug,
            'name' => (string) $term->name,
            'description' => (string) $term->description,
            'count' => (int) $term->count,
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
