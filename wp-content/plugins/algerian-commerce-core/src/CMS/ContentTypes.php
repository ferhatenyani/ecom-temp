<?php

declare(strict_types=1);

namespace AlgerianCommerce\CMS;

use AlgerianCommerce\Permissions\Capabilities;

/**
 * The content types §61 exposes — roadmap §61, docs/PLAN.md §22.
 *
 * "WordPress stores content" is the whole instruction, so banners and FAQs are
 * ordinary post types and menus are ordinary nav menus. Nothing here is a
 * custom table: docs/ARCHITECTURE.md §7 reserves those for high-volume custom
 * domains, and a shop's FAQ list is neither.
 *
 * Three deliberate choices in the registration:
 *
 *  - **`public => false`, `show_ui => true`.** This plugin renders no frontend,
 *    so a banner must never acquire a URL of its own, an archive page or a feed
 *    — the Next.js storefront is the only thing that renders. The editor
 *    screens are WordPress's own, not ours; without them these types would be
 *    unauthorable, because §61 specifies read endpoints only.
 *  - **`show_in_rest => false`.** `/wp/v2` is not this project's contract
 *    (docs/ARCHITECTURE.md §1) and a second, unversioned way to read the same
 *    content is a second thing to secure.
 *  - **Capabilities mapped to `ac_manage_content`.** The plugin's own roles
 *    carry `read` and nothing else from core, so with the default `post`
 *    capabilities an `ac_admin` could sign in and not see these screens at all,
 *    while the API let them read the same rows. One capability governs both.
 */
final class ContentTypes
{
    public const BANNER = 'ac_banner';
    public const FAQ = 'ac_faq';
    public const FAQ_CATEGORY = 'ac_faq_category';

    /** Where a banner is meant to appear — a free key, not an enum. */
    public const BANNER_PLACEMENT = '_ac_banner_placement';
    public const BANNER_LINK = '_ac_banner_link';

    public const DEFAULT_PLACEMENT = 'home_hero';

    /**
     * Menu locations this backend publishes.
     *
     * A headless install has no theme to register these, so the plugin does it;
     * without them `GET /cms/menus/{location}` could never resolve anything.
     * Adding a location is one line here plus a menu assigned in Appearance →
     * Menus.
     */
    public const MENU_LOCATIONS = [
        'primary' => 'Primary navigation',
        'footer' => 'Footer navigation',
    ];

    public function register(): void
    {
        add_action('init', [$this, 'registerAll']);
    }

    public function registerAll(): void
    {
        register_post_type(self::BANNER, [
            'label' => 'Banners',
            'labels' => $this->labels('Banner', 'Banners'),
            'public' => false,
            'publicly_queryable' => false,
            'show_ui' => true,
            'show_in_menu' => true,
            'show_in_rest' => false,
            'has_archive' => false,
            'rewrite' => false,
            'query_var' => false,
            'menu_icon' => 'dashicons-format-image',
            'menu_position' => 26,
            'supports' => ['title', 'editor', 'thumbnail', 'page-attributes', 'custom-fields'],
            'capability_type' => 'post',
            'map_meta_cap' => true,
            'capabilities' => $this->capabilities(),
        ]);

        register_post_type(self::FAQ, [
            'label' => 'FAQs',
            'labels' => $this->labels('FAQ', 'FAQs'),
            'public' => false,
            'publicly_queryable' => false,
            'show_ui' => true,
            'show_in_menu' => true,
            'show_in_rest' => false,
            'has_archive' => false,
            'rewrite' => false,
            'query_var' => false,
            'menu_icon' => 'dashicons-editor-help',
            'menu_position' => 27,
            'supports' => ['title', 'editor', 'page-attributes'],
            'capability_type' => 'post',
            'map_meta_cap' => true,
            'capabilities' => $this->capabilities(),
        ]);

        /*
         * FAQs group. A taxonomy rather than a meta string because grouping is
         * what a taxonomy is, and it arrives with the term UI, term ids and a
         * query that does not scan meta.
         */
        register_taxonomy(self::FAQ_CATEGORY, [self::FAQ], [
            'label' => 'FAQ categories',
            'public' => false,
            'publicly_queryable' => false,
            'show_ui' => true,
            'show_in_rest' => false,
            'hierarchical' => false,
            'rewrite' => false,
            'query_var' => false,
            'capabilities' => [
                'manage_terms' => Capabilities::MANAGE_CONTENT,
                'edit_terms' => Capabilities::MANAGE_CONTENT,
                'delete_terms' => Capabilities::MANAGE_CONTENT,
                'assign_terms' => Capabilities::MANAGE_CONTENT,
            ],
        ]);

        /*
         * Registered so the values are typed and sanitised wherever they are
         * written from — the dashboard's custom-fields box, WP-CLI, or a future
         * admin write endpoint. `show_in_rest` stays false for the same reason
         * the post types' does.
         */
        register_post_meta(self::BANNER, self::BANNER_LINK, [
            'type' => 'string',
            'single' => true,
            'default' => '',
            'show_in_rest' => false,
            'sanitize_callback' => 'esc_url_raw',
            'auth_callback' => static fn (): bool => current_user_can(Capabilities::MANAGE_CONTENT),
        ]);

        register_post_meta(self::BANNER, self::BANNER_PLACEMENT, [
            'type' => 'string',
            'single' => true,
            'default' => self::DEFAULT_PLACEMENT,
            'show_in_rest' => false,
            'sanitize_callback' => 'sanitize_key',
            'auth_callback' => static fn (): bool => current_user_can(Capabilities::MANAGE_CONTENT),
        ]);

        register_nav_menus(self::MENU_LOCATIONS);
    }

    /**
     * Every **primitive** capability this post type needs, all pointing at the
     * one the API uses. Spelled out rather than relying on `capability_type`,
     * because our roles carry no core post capabilities at all and would
     * otherwise see none of these screens.
     *
     * ## `edit_post`, `read_post` and `delete_post` are deliberately absent
     *
     * Those three are *meta* capabilities — the ones asked about a specific
     * post — and mapping them here does far more than it looks like it does.
     * `WP_Post_Type::register_meta_capabilities()` writes each into the global
     * `$post_type_meta_caps`, keyed by the name we give it, and `map_meta_cap()`
     * then treats **any** check of that name as a meta check. Setting
     * `'edit_post' => 'ac_manage_content'` therefore made
     * `current_user_can('ac_manage_content')` map to `delete_post` with no post
     * id, which resolves to `do_not_allow`: every CMS and media route answered
     * 403 to a Marketing Manager holding exactly the capability being asked
     * about, and to an administrator too.
     *
     * `map_meta_cap => true` derives the three from the primitives below, which
     * is the whole point of it. Found by `tests/Api/cms.php` on 2026-08-15; the
     * suite is the only reason it did not ship.
     *
     * @return array<string, string>
     */
    private function capabilities(): array
    {
        $capability = Capabilities::MANAGE_CONTENT;

        return [
            'edit_posts' => $capability,
            'edit_others_posts' => $capability,
            'delete_posts' => $capability,
            'delete_others_posts' => $capability,
            'delete_published_posts' => $capability,
            'delete_private_posts' => $capability,
            'publish_posts' => $capability,
            'read_private_posts' => $capability,
            'edit_published_posts' => $capability,
            'edit_private_posts' => $capability,
            'create_posts' => $capability,
        ];
    }

    /** @return array<string, string> */
    private function labels(string $singular, string $plural): array
    {
        return [
            'name' => $plural,
            'singular_name' => $singular,
            'add_new_item' => "Add {$singular}",
            'edit_item' => "Edit {$singular}",
            'new_item' => "New {$singular}",
            'view_item' => "View {$singular}",
            'search_items' => "Search {$plural}",
            'not_found' => "No {$plural} yet",
            'menu_name' => $plural,
        ];
    }
}
