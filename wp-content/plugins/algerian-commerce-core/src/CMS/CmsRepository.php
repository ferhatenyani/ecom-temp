<?php

declare(strict_types=1);

namespace AlgerianCommerce\CMS;

use AlgerianCommerce\API\ApiException;
use AlgerianCommerce\SEO\SeoInput;
use AlgerianCommerce\SEO\SeoRepository;
use WP_Post;
use WP_Query;
use WP_Term;

/**
 * Reads and writes content in WordPress — roadmap §61, §89.
 *
 * The only file in `CMS/` that knows post types, meta keys and nav-menu
 * functions exist (docs/ARCHITECTURE.md §2). Everything above it deals in
 * `WP_Post` and arrays.
 *
 * §61 built the read half and said a write surface belonged to PLAN §52's admin
 * coverage. §89 is that, and it changed one thing about the reads: they asked
 * for published rows only, on the argument that "a draft banner is a banner
 * somebody is not finished writing". That was right while nothing here could
 * create one. It is wrong the moment `POST /cms/pages` can answer 201 for a
 * resource whose `GET` is a 404 — so every read takes a `status` list, and it
 * **defaults to `publish`**, which leaves §61's contract and every existing
 * caller exactly where they were.
 */
final class CmsRepository
{
    /** The homepage document — see HomepageSections for why it is an option. */
    public const HOMEPAGE_OPTION = 'ac_cms_homepage';

    /** What a read asks for unless the caller says otherwise. */
    public const DEFAULT_STATUSES = ['publish'];

    public function homepage(): HomepageSections
    {
        return HomepageSections::fromStored(get_option(self::HOMEPAGE_OPTION, []));
    }

    /** @param array{sections: list<array{type: string, data: array<string, mixed>}>} $document */
    public function saveHomepage(array $document): void
    {
        update_option(self::HOMEPAGE_OPTION, $document, false);
    }

    /**
     * A page by path.
     *
     * `get_page_by_path()` rather than a WP_Query on `name`, which makes the
     * address of a page its **path**: a page filed under Legal is reached at
     * `legal/terms`, and its bare slug is a 404. That is how WordPress itself
     * addresses a hierarchical page, and it is the unambiguous reading — two
     * children called `terms` under different parents are two pages, and a
     * slug lookup would have to pick one of them.
     *
     * `get_page_by_path()` resolves a draft as readily as a published page —
     * measured 2026-08-17 — so the status filter is applied here rather than
     * assumed of it.
     *
     * @param list<string> $statuses
     */
    public function page(string $path, array $statuses = self::DEFAULT_STATUSES): ?WP_Post
    {
        $page = get_page_by_path($path, OBJECT, 'page');

        if (!$page instanceof WP_Post || !in_array($page->post_status, $statuses, true)) {
            return null;
        }

        return $page;
    }

    /**
     * The page index.
     *
     * §89 could address a page and could not list one, because every route it
     * added was single-resource: `POST /cms/pages` and `GET, PATCH, DELETE
     * /cms/pages/{path}`. That is a complete write surface and an incomplete
     * read one — a content manager could edit any page whose path they already
     * knew and could not discover a single one — and a panel built on it would
     * be the only screen in the shop with no index behind it.
     *
     * The criteria are `baseArgs()`, unchanged and untyped to this post type, so
     * `?status=`, `?search=`, `?page=` and `?per_page=` mean here exactly what
     * they mean on banners and FAQs. The one addition is the exclusion, and
     * `SystemPages` carries the argument for it.
     *
     * @param array<string, mixed> $criteria
     * @return array{items: list<WP_Post>, total: int, excluded: int}
     */
    public function pages(array $criteria): array
    {
        $args = $this->baseArgs('page', $criteria);

        $excluded = SystemPages::functional();

        if ($excluded !== []) {
            $args['post__not_in'] = $excluded;
        }

        /*
         * Ordered by title rather than by `menu_order` then date, which is the
         * one place this list departs from `baseArgs()`'s default and does so
         * deliberately. A banner strip and an FAQ list are *ordered things* — the
         * order is the content — while pages are a set somebody searches. Every
         * page on this install has `menu_order` 0, so the default degenerates to
         * "newest first", and a page index sorted by creation date is one where
         * the page you are looking for moves every time somebody adds another.
         */
        $args['orderby'] = ['title' => 'ASC'];

        return $this->run($args) + ['excluded' => count($excluded)];
    }

    /**
     * The full path of a page — the address `page()` resolves.
     *
     * `get_page_uri()` walks the ancestors and returns exactly the string this
     * API addresses a page by, which is why it is used rather than assembled:
     * the two halves of an address must agree, and one of them being derived
     * from the other is how they stay that way.
     */
    public function pathFor(WP_Post $page): string
    {
        return (string) get_page_uri($page);
    }

    /**
     * Resolve `parent_path` to a post id.
     *
     * A path that names nothing is a **400 on that field**, never a silent fall
     * back to the root: "move this page under Legal" answered 200 having left
     * it where it was is the failure this whole section is written against.
     * Any status counts as a parent — filing a child under a draft section is a
     * legitimate way to stage a whole branch.
     */
    public function resolveParent(string $parentPath): int
    {
        if ($parentPath === '') {
            return 0;
        }

        $parent = get_page_by_path($parentPath, OBJECT, 'page');

        if (!$parent instanceof WP_Post) {
            throw ApiException::invalidRequest('The page data is invalid.', [
                'fields' => ['parent_path' => 'No page at "' . $parentPath . '".'],
            ]);
        }

        return (int) $parent->ID;
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
     * The pages filed directly under this one, at any status.
     *
     * Read before a delete, because `wp_delete_post()` reparents them to the
     * root and reports nothing — see `CmsService::deletePage()`.
     *
     * @return list<int>
     */
    public function childPageIds(int $parentId): array
    {
        $children = get_children([
            'post_parent' => $parentId,
            'post_type' => 'page',
            'post_status' => 'any',
            'fields' => 'ids',
            'numberposts' => -1,
        ]);

        return array_values(array_map('intval', (array) $children));
    }

    /**
     * Every ancestor of a page, nearest first.
     *
     * `get_post_ancestors()` rather than a walk of our own: it is already
     * cycle-safe, which is the property the caller needs.
     *
     * @return list<int>
     */
    public function ancestorIds(int $pageId): array
    {
        return array_values(array_map('intval', (array) get_post_ancestors($pageId)));
    }

    // ------------------------------------------------------------- writes --
    //
    // §89. Everything below goes through WordPress's own writers —
    // `wp_insert_post`, `wp_update_post`, `wp_set_object_terms`,
    // `wp_update_nav_menu_item` — and never `$wpdb` against `wp_posts` or
    // `wp_term_relationships`. §88 made the same call for
    // `wc_update_attribute()` and gave the reason: those functions maintain
    // counts, caches, revisions and relationship rows that an UPDATE leaves
    // stale, and the staleness surfaces as content that is present in the
    // database and invisible to every query.

    /** A post of one of this module's types, whatever its status. */
    public function findPost(int $id, string $postType): ?WP_Post
    {
        $post = get_post($id);

        if (!$post instanceof WP_Post || $post->post_type !== $postType) {
            return null;
        }

        // A trashed row is gone as far as this API is concerned: it has no
        // address, and `DELETE` is what put it there.
        return $post->post_status === 'trash' ? null : $post;
    }

    public function createPage(PageInput $input, int $parentId): WP_Post
    {
        $id = wp_insert_post($this->pageFields($input, $parentId) + [
            'post_type' => 'page',
            'post_status' => (string) ($input->get('status') ?? 'publish'),
        ], true);

        return $this->written($id, 'page');
    }

    public function updatePage(WP_Post $page, PageInput $input, ?int $parentId): WP_Post
    {
        $fields = $this->pageFields($input, $parentId) + ['ID' => (int) $page->ID];

        if ($input->has('status')) {
            $fields['post_status'] = (string) $input->get('status');
        }

        return $this->written(wp_update_post($fields, true), 'page');
    }

    public function createBanner(BannerInput $input): WP_Post
    {
        $id = wp_insert_post($this->bannerFields($input) + [
            'post_type' => ContentTypes::BANNER,
            'post_status' => (string) ($input->get('status') ?? 'publish'),
        ], true);

        $banner = $this->written($id, ContentTypes::BANNER);
        $this->applyBannerMeta($banner, $input);

        return $banner;
    }

    public function updateBanner(WP_Post $banner, BannerInput $input): WP_Post
    {
        $fields = $this->bannerFields($input) + ['ID' => (int) $banner->ID];

        if ($input->has('status')) {
            $fields['post_status'] = (string) $input->get('status');
        }

        $updated = $this->written(wp_update_post($fields, true), ContentTypes::BANNER);
        $this->applyBannerMeta($updated, $input);

        return $updated;
    }

    public function createFaq(FaqInput $input): WP_Post
    {
        // Before the insert. See `resolveFaqCategories()`.
        $termIds = $this->resolveFaqCategories($input);

        $id = wp_insert_post($this->faqFields($input) + [
            'post_type' => ContentTypes::FAQ,
            'post_status' => (string) ($input->get('status') ?? 'publish'),
        ], true);

        $faq = $this->written($id, ContentTypes::FAQ);

        if ($termIds !== null) {
            wp_set_object_terms((int) $faq->ID, $termIds, ContentTypes::FAQ_CATEGORY, false);
        }

        return $faq;
    }

    public function updateFaq(WP_Post $faq, FaqInput $input): WP_Post
    {
        $termIds = $this->resolveFaqCategories($input);

        $fields = $this->faqFields($input) + ['ID' => (int) $faq->ID];

        if ($input->has('status')) {
            $fields['post_status'] = (string) $input->get('status');
        }

        $updated = $this->written(wp_update_post($fields, true), ContentTypes::FAQ);

        if ($termIds !== null) {
            wp_set_object_terms((int) $updated->ID, $termIds, ContentTypes::FAQ_CATEGORY, false);
        }

        return $updated;
    }

    /**
     * Trash, or remove permanently.
     *
     * The `?force=true` split every other resource in this API uses. A page is
     * the one where the trash earns its keep: a legal page deleted by mistake
     * is a page whose URL a storefront still links to.
     */
    public function deletePost(WP_Post $post, bool $force): bool
    {
        $result = $force
            ? wp_delete_post((int) $post->ID, true)
            : wp_trash_post((int) $post->ID);

        return $result !== false && $result !== null;
    }

    public function findFaqCategory(int $id): ?WP_Term
    {
        $term = get_term($id, ContentTypes::FAQ_CATEGORY);

        return $term instanceof WP_Term ? $term : null;
    }

    public function faqCategoryBySlug(string $slug): ?WP_Term
    {
        $term = get_term_by('slug', $slug, ContentTypes::FAQ_CATEGORY);

        return $term instanceof WP_Term ? $term : null;
    }

    public function createFaqCategory(FaqCategoryInput $input): WP_Term
    {
        $result = wp_insert_term((string) $input->get('name'), ContentTypes::FAQ_CATEGORY, [
            'slug' => (string) ($input->get('slug') ?? ''),
            'description' => (string) ($input->get('description') ?? ''),
        ]);

        if (is_wp_error($result)) {
            /*
             * A duplicate **name** is a conflict, not a bad request, and it is
             * the one a real shop hits: §67's seed already ships "Livraison",
             * so the first thing anybody types is a name that exists. The slug
             * clash is caught in the service with the id of what it clashed
             * with; this is the name half, which `wp_insert_term()` owns.
             */
            if ($result->get_error_code() === 'term_exists') {
                throw ApiException::conflict('An FAQ category with that name already exists.', [
                    'term_id' => (int) $result->get_error_data('term_exists'),
                ]);
            }

            throw ApiException::invalidRequest($result->get_error_message());
        }

        return $this->requireTerm((int) $result['term_id']);
    }

    public function updateFaqCategory(WP_Term $term, FaqCategoryInput $input): WP_Term
    {
        $args = [];

        foreach (['name', 'slug', 'description'] as $field) {
            if ($input->has($field)) {
                $args[$field] = (string) $input->get($field);
            }
        }

        $result = wp_update_term((int) $term->term_id, ContentTypes::FAQ_CATEGORY, $args);

        if (is_wp_error($result)) {
            throw ApiException::invalidRequest($result->get_error_message());
        }

        return $this->requireTerm((int) $term->term_id);
    }

    public function deleteFaqCategory(int $termId): bool
    {
        $result = wp_delete_term($termId, ContentTypes::FAQ_CATEGORY);

        return $result === true;
    }

    /**
     * Replace a location's menu with an ordered tree.
     *
     * Three things happen here and each is deliberate.
     *
     * **A missing menu is created and assigned**, because a fresh install has
     * none — `get_nav_menu_locations()` returned `['primary' => 22]` and no
     * footer on this one — and a PUT that answered 404 until somebody opened
     * Appearance → Menus would make the endpoint useless for the case it exists
     * for. The assignment is a theme mod, which is where WordPress keeps it.
     *
     * **Every existing item is deleted first.** A diff would have to match
     * incoming items against stored ones, and nothing in the payload identifies
     * an item across two requests — §89 chose a shape without ids on purpose.
     * Replacing is what `PUT` means.
     *
     * **Every reference is resolved before anything is deleted**, and that
     * ordering is a bug fix rather than a preference. The first version
     * resolved a page path as it wrote each item, so a payload naming one page
     * that did not exist deleted the whole menu and *then* answered 400 — a
     * typo in one path emptied a shop's navigation, and the response said the
     * request had been refused. `tests/Api/cms.php` caught it with the control
     * beside the refusal ("and none of those refusals emptied the stored
     * menu"), which is the assertion §65 asks for and the reason it is there.
     *
     * **The parent id comes back from the insert**, so a child is attached to
     * the row its parent actually got rather than to a position guessed in
     * advance.
     *
     * @param list<array<string, mixed>> $items already validated by MenuInput
     * @return array{menu: object, items: list<WP_Post>}
     */
    public function saveMenu(string $location, array $items): array
    {
        // Before the menu is touched. Throws on the first bad reference.
        $items = $this->resolveMenuTargets($items);

        $menuId = (int) (get_nav_menu_locations()[$location] ?? 0);
        $menu = $menuId > 0 ? wp_get_nav_menu_object($menuId) : false;

        if ($menu === false) {
            $created = wp_create_nav_menu(ContentTypes::MENU_LOCATIONS[$location] ?? $location);

            if (is_wp_error($created)) {
                throw ApiException::internal('The menu could not be created: ' . $created->get_error_message());
            }

            $menuId = (int) $created;
            $locations = get_nav_menu_locations();
            $locations[$location] = $menuId;
            set_theme_mod('nav_menu_locations', $locations);

            $menu = wp_get_nav_menu_object($menuId);
        }

        foreach ((array) wp_get_nav_menu_items($menuId, ['update_post_term_cache' => false]) as $existing) {
            if ($existing instanceof WP_Post) {
                wp_delete_post((int) $existing->ID, true);
            }
        }

        $position = 0;
        $this->writeMenuItems($menuId, $items, 0, $position);

        $written = wp_get_nav_menu_items($menuId, ['update_post_term_cache' => false]);

        return [
            'menu' => $menu,
            'items' => is_array($written) ? array_values($written) : [],
        ];
    }

    /**
     * The image reference check.
     *
     * Written here rather than borrowed from `Products\ProductRepository`,
     * which has the same ten lines: `CMS/` depending on `Products/` would
     * invent a dependency the business does not have
     * (docs/ARCHITECTURE.md — a domain depends on another only where it
     * genuinely nests, as a customer's history nests orders).
     */
    public function assertImageAttachment(int $id, string $field, string $resource): void
    {
        if ($id === 0) {
            return;
        }

        $post = get_post($id);

        if (!$post instanceof WP_Post || $post->post_type !== 'attachment' || !wp_attachment_is_image($id)) {
            throw ApiException::invalidRequest("The {$resource} data is invalid.", [
                'fields' => [$field => "{$id} is not an image attachment."],
            ]);
        }
    }

    /**
     * Write one level, depth-first, numbering the whole tree from one counter.
     *
     * **One counter across every level, not one per level.** `menu_order` is
     * the only thing that orders a menu, and `wp_get_nav_menu_items()` sorts
     * the flat list by it before `CmsPresenter` rebuilds the tree from
     * `menu_item_parent` — so a per-level counter produces ties between a
     * child and a top-level item, and a tie is resolved by whatever MySQL
     * returns first. The tree would still nest correctly and the order within a
     * level would be luck. Measured 2026-08-17: core then renumbers
     * `menu_order` 1..N over that sorted list, which is why the `position` a
     * read publishes is a flat index rather than the number written here.
     *
     * @param list<array<string, mixed>> $items
     */
    private function writeMenuItems(int $menuId, array $items, int $parentId, int &$position): void
    {
        foreach ($items as $item) {
            $position++;

            $args = [
                'menu-item-title' => (string) $item['label'],
                'menu-item-status' => 'publish',
                'menu-item-parent-id' => $parentId,
                'menu-item-position' => $position,
            ];

            $args += match ((string) $item['type']) {
                'page' => [
                    'menu-item-type' => 'post_type',
                    'menu-item-object' => 'page',
                    'menu-item-object-id' => (int) $item['object_id'],
                ],
                'product' => [
                    'menu-item-type' => 'post_type',
                    'menu-item-object' => 'product',
                    'menu-item-object-id' => (int) $item['object_id'],
                ],
                'category' => [
                    'menu-item-type' => 'taxonomy',
                    'menu-item-object' => 'product_cat',
                    'menu-item-object-id' => (int) $item['object_id'],
                ],
                default => [
                    'menu-item-type' => 'custom',
                    'menu-item-url' => (string) $item['url'],
                ],
            };

            $itemId = wp_update_nav_menu_item($menuId, 0, $args);

            if (is_wp_error($itemId)) {
                throw ApiException::internal('A menu item could not be written: ' . $itemId->get_error_message());
            }

            /** @var list<array<string, mixed>> $children */
            $children = is_array($item['children'] ?? null) ? $item['children'] : [];

            if ($children !== []) {
                $this->writeMenuItems($menuId, $children, (int) $itemId, $position);
            }
        }
    }

    /**
     * Resolve what every menu item points at, and refuse what does not exist.
     *
     * A menu naming a deleted product is an item that renders as a dead link on
     * every page of the storefront, so the reference is checked here — where
     * "does 88 exist and is it a product" is answerable — rather than in
     * `MenuInput`, which is pure. §83 put the same split in the same place.
     *
     * Whole-tree, before the write, so a refusal costs nothing. See
     * `saveMenu()` for what the alternative cost.
     *
     * @param list<array<string, mixed>> $items
     * @param non-empty-string           $where the field path a refusal names
     * @return list<array<string, mixed>>
     */
    private function resolveMenuTargets(array $items, string $where = 'items'): array
    {
        $resolved = [];

        foreach ($items as $index => $item) {
            $at = "{$where}[{$index}]";
            $type = (string) $item['type'];

            if ($type !== 'url') {
                $item['object_id'] = $this->menuTarget($item, $type, $at);
            }

            /** @var list<array<string, mixed>> $children */
            $children = is_array($item['children'] ?? null) ? $item['children'] : [];
            $item['children'] = $children === [] ? [] : $this->resolveMenuTargets($children, "{$at}.children");

            $resolved[] = $item;
        }

        return $resolved;
    }

    /** @param array<string, mixed> $item */
    private function menuTarget(array $item, string $type, string $where): int
    {
        if ($type === 'page' && (string) $item['path'] !== '') {
            $page = get_page_by_path((string) $item['path'], OBJECT, 'page');

            if (!$page instanceof WP_Post) {
                throw ApiException::invalidRequest('The menu data is invalid.', [
                    'fields' => ["{$where}.path" => 'No page at "' . (string) $item['path'] . '".'],
                ]);
            }

            return (int) $page->ID;
        }

        $id = (int) $item['object_id'];

        $exists = match ($type) {
            'category' => get_term($id, 'product_cat') instanceof WP_Term,
            default => ($post = get_post($id)) instanceof WP_Post
                && $post->post_type === ($type === 'page' ? 'page' : 'product'),
        };

        if (!$exists) {
            throw ApiException::invalidRequest('The menu data is invalid.', [
                'fields' => ["{$where}.object_id" => sprintf('No %s with id %d.', $type, $id)],
            ]);
        }

        return $id;
    }

    /** @return array<string, mixed> */
    private function pageFields(PageInput $input, ?int $parentId): array
    {
        $fields = [];

        if ($input->has('title')) {
            $fields['post_title'] = (string) $input->get('title');
        }

        if ($input->has('slug')) {
            $fields['post_name'] = (string) $input->get('slug');
        }

        if ($input->has('content')) {
            $fields['post_content'] = (string) $input->get('content');
        }

        if ($input->has('excerpt')) {
            $fields['post_excerpt'] = (string) $input->get('excerpt');
        }

        if ($input->has('menu_order')) {
            $fields['menu_order'] = (int) $input->get('menu_order');
        }

        if ($parentId !== null) {
            $fields['post_parent'] = $parentId;
        }

        return $fields;
    }

    /** @return array<string, mixed> */
    private function bannerFields(BannerInput $input): array
    {
        $fields = [];

        if ($input->has('title')) {
            $fields['post_title'] = (string) $input->get('title');
        }

        if ($input->has('caption')) {
            $fields['post_content'] = (string) $input->get('caption');
        }

        if ($input->has('position')) {
            $fields['menu_order'] = (int) $input->get('position');
        }

        return $fields;
    }

    /** @return array<string, mixed> */
    private function faqFields(FaqInput $input): array
    {
        $fields = [];

        if ($input->has('question')) {
            $fields['post_title'] = (string) $input->get('question');
        }

        if ($input->has('answer')) {
            $fields['post_content'] = (string) $input->get('answer');
        }

        if ($input->has('position')) {
            $fields['menu_order'] = (int) $input->get('position');
        }

        return $fields;
    }

    private function applyBannerMeta(WP_Post $banner, BannerInput $input): void
    {
        if ($input->has('link')) {
            update_post_meta($banner->ID, ContentTypes::BANNER_LINK, (string) $input->get('link'));
        }

        if ($input->has('placement')) {
            update_post_meta($banner->ID, ContentTypes::BANNER_PLACEMENT, (string) $input->get('placement'));
        }

        $this->applyThumbnail($banner, $input->has('image_id') ? (int) $input->get('image_id') : null);
    }

    /**
     * Attach or clear a featured image.
     *
     * `delete_post_thumbnail()` rather than `set_post_thumbnail($post, 0)`,
     * which does nothing at all: core returns early on a falsy id, so clearing
     * an image by sending 0 would answer 200 and leave the old one attached.
     */
    private function applyThumbnail(WP_Post $post, ?int $imageId): void
    {
        if ($imageId === null) {
            return;
        }

        if ($imageId === 0) {
            delete_post_thumbnail($post);

            return;
        }

        set_post_thumbnail($post, $imageId);
    }

    public function applyPageThumbnail(WP_Post $page, PageInput $input): void
    {
        $this->applyThumbnail($page, $input->has('image_id') ? (int) $input->get('image_id') : null);
    }

    /**
     * A page's SEO overrides — §62's rule, written through the resource.
     *
     * After the save rather than inside it, for `ProductRepository::applySeo()`'s
     * reason: on a create there is no post id to attach meta to until the row
     * exists, and one call then serves both paths rather than the create
     * silently dropping its SEO.
     *
     * The image reference it names is checked **before** the save, by
     * `CmsService::assertPageReferences()`. Checking it here — where the value
     * is — would mean a page created and then refused, which is the bug
     * `resolveFaqCategories()` documents.
     */
    public function applyPageSeo(WP_Post $page, PageInput $input): void
    {
        $seo = $input->get('seo');

        if (!$seo instanceof SeoInput || $seo->isEmpty()) {
            return;
        }

        (new SeoRepository())->save((int) $page->ID, $seo);
    }

    /** The attachment a page's SEO block names, if it names one. */
    public function seoImageId(PageInput $input): ?int
    {
        $seo = $input->get('seo');

        return $seo instanceof SeoInput && $seo->has('image_id') ? (int) $seo->get('image_id') : null;
    }

    /**
     * Turn what `FaqInput` accepted into term ids, refusing anything unknown.
     *
     * **Called before the post is written, not after**, and that is a bug fix:
     * the first version attached categories once the row existed, so an FAQ
     * naming a category that did not exist was created, published, then
     * answered **400**. The refusal was correct and the shop still gained an
     * answer nobody wrote. Found by re-running `tests/Api/cms.php` a second
     * time — the leftover row broke an ordering assertion in §61's half, which
     * is the only reason anything noticed. It is `saveMenu()`'s bug in a second
     * place, and the rule both now follow: **resolve every reference before the
     * first write**.
     *
     * `null` means the payload did not mention categories, which is different
     * from `[]` — clearing them.
     *
     * @return list<int>|null
     */
    private function resolveFaqCategories(FaqInput $input): ?array
    {
        if (!$input->has('categories')) {
            return null;
        }

        /** @var list<array{id?: int, slug?: string}> $wanted */
        $wanted = (array) $input->get('categories');
        $termIds = [];

        foreach ($wanted as $entry) {
            $term = isset($entry['id'])
                ? $this->findFaqCategory((int) $entry['id'])
                : $this->faqCategoryBySlug((string) ($entry['slug'] ?? ''));

            if ($term === null) {
                /*
                 * Refused rather than created. `wp_set_object_terms()` will
                 * happily invent a term from a name, which would turn a typo
                 * into a category nobody meant and a filter nobody can find —
                 * and the categories route exists precisely so creating one is
                 * a deliberate act.
                 */
                throw ApiException::invalidRequest('The FAQ data is invalid.', [
                    'fields' => [
                        'categories' => 'No FAQ category '
                            . (isset($entry['id']) ? 'with id ' . (int) $entry['id'] : '"' . (string) ($entry['slug'] ?? '') . '"')
                            . '. Create it at POST /cms/faq-categories first.',
                    ],
                ]);
            }

            $termIds[] = (int) $term->term_id;
        }

        return $termIds;
    }

    private function requireTerm(int $termId): WP_Term
    {
        $term = $this->findFaqCategory($termId);

        if ($term === null) {
            throw ApiException::internal('The FAQ category could not be read back after writing.');
        }

        return $term;
    }

    /**
     * Turn what a WordPress writer returned into a post, or fail loudly.
     *
     * `wp_insert_post()` returns `0` on failure when `$wp_error` is false and a
     * `WP_Error` when it is true; both are passed `true` here so the reason
     * survives. A write that returned 0 and was treated as an id is how a
     * create answers 201 with an empty body.
     */
    private function written(mixed $result, string $postType): WP_Post
    {
        if (is_wp_error($result)) {
            throw ApiException::invalidRequest($result->get_error_message());
        }

        $post = get_post((int) $result);

        if (!$post instanceof WP_Post || $post->post_type !== $postType) {
            throw ApiException::internal('The content could not be read back after writing.');
        }

        return $post;
    }

    /**
     * @param array<string, mixed> $criteria
     * @return array<string, mixed>
     */
    private function baseArgs(string $postType, array $criteria): array
    {
        $statuses = $criteria['statuses'] ?? self::DEFAULT_STATUSES;

        $args = [
            'post_type' => $postType,
            'post_status' => is_array($statuses) && $statuses !== [] ? array_values($statuses) : self::DEFAULT_STATUSES,
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
