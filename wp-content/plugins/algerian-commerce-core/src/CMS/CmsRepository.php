<?php

declare(strict_types=1);

namespace AlgerianCommerce\CMS;

use WP_Post;
use WP_Query;
use WP_Term;

/**
 * Reads content out of WordPress — roadmap §61.
 *
 * The only file in `CMS/` that knows post types, meta keys and nav-menu
 * functions exist (docs/ARCHITECTURE.md §2). Everything above it deals in
 * `WP_Post` and arrays.
 *
 * Every query is read-only and every one of them asks for published rows only:
 * a draft banner is a banner somebody is not finished writing, and the API is
 * read by a storefront.
 */
final class CmsRepository
{
    /** The homepage document — see HomepageSections for why it is an option. */
    public const HOMEPAGE_OPTION = 'ac_cms_homepage';

    public function homepage(): HomepageSections
    {
        return HomepageSections::fromStored(get_option(self::HOMEPAGE_OPTION, []));
    }

    /**
     * A published page by path.
     *
     * `get_page_by_path()` rather than a WP_Query on `name`, which makes the
     * address of a page its **path**: a page filed under Legal is reached at
     * `legal/terms`, and its bare slug is a 404. That is how WordPress itself
     * addresses a hierarchical page, and it is the unambiguous reading — two
     * children called `terms` under different parents are two pages, and a
     * slug lookup would have to pick one of them.
     */
    public function page(string $slug): ?WP_Post
    {
        $page = get_page_by_path($slug, OBJECT, 'page');

        if (!$page instanceof WP_Post || $page->post_status !== 'publish') {
            return null;
        }

        return $page;
    }

    /**
     * @param array<string, mixed> $criteria
     * @return array{items: list<WP_Post>, total: int}
     */
    public function banners(array $criteria): array
    {
        $args = $this->baseArgs(ContentTypes::BANNER, $criteria);

        $placement = (string) ($criteria['placement'] ?? '');

        if ($placement !== '') {
            $args['meta_query'] = [
                [
                    'key' => ContentTypes::BANNER_PLACEMENT,
                    'value' => $placement,
                ],
            ];
        }

        return $this->run($args);
    }

    /**
     * @param array<string, mixed> $criteria
     * @return array{items: list<WP_Post>, total: int}
     */
    public function faqs(array $criteria): array
    {
        $args = $this->baseArgs(ContentTypes::FAQ, $criteria);

        $category = (string) ($criteria['category'] ?? '');

        if ($category !== '') {
            $args['tax_query'] = [
                [
                    'taxonomy' => ContentTypes::FAQ_CATEGORY,
                    'field' => 'slug',
                    'terms' => [$category],
                ],
            ];
        }

        return $this->run($args);
    }

    /**
     * The menu assigned to a theme location, with its items.
     *
     * @return array{menu: object, items: list<WP_Post>}|null
     */
    public function menu(string $location): ?array
    {
        $locations = get_nav_menu_locations();
        $menuId = (int) ($locations[$location] ?? 0);

        if ($menuId <= 0) {
            return null;
        }

        $menu = wp_get_nav_menu_object($menuId);

        if ($menu === false) {
            return null;
        }

        $items = wp_get_nav_menu_items($menuId, ['update_post_term_cache' => false]);

        return [
            'menu' => $menu,
            'items' => is_array($items) ? array_values($items) : [],
        ];
    }

    /** @return list<WP_Term> */
    public function faqCategories(): array
    {
        $terms = get_terms([
            'taxonomy' => ContentTypes::FAQ_CATEGORY,
            'hide_empty' => false,
        ]);

        return is_array($terms) ? array_values(array_filter($terms, static fn ($t): bool => $t instanceof WP_Term)) : [];
    }

    /**
     * @param array<string, mixed> $criteria
     * @return array<string, mixed>
     */
    private function baseArgs(string $postType, array $criteria): array
    {
        $args = [
            'post_type' => $postType,
            'post_status' => 'publish',
            'paged' => max(1, (int) ($criteria['page'] ?? 1)),
            'posts_per_page' => max(1, (int) ($criteria['per_page'] ?? 20)),
            /*
             * menu_order first: it is the field the "page attributes" box in
             * the editor writes, so ordering a banner strip or an FAQ list is
             * something a content manager does without leaving WordPress.
             */
            'orderby' => ['menu_order' => 'ASC', 'date' => 'DESC'],
            'ignore_sticky_posts' => true,
            'no_found_rows' => false,
        ];

        $search = (string) ($criteria['search'] ?? '');

        if ($search !== '') {
            $args['s'] = $search;
        }

        return $args;
    }

    /**
     * @param array<string, mixed> $args
     * @return array{items: list<WP_Post>, total: int}
     */
    private function run(array $args): array
    {
        $query = new WP_Query($args);

        return [
            'items' => array_values(array_filter($query->posts, static fn ($p): bool => $p instanceof WP_Post)),
            'total' => (int) $query->found_posts,
        ];
    }
}
