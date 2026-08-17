<?php

declare(strict_types=1);

namespace AlgerianCommerce\CMS;

use AlgerianCommerce\API\ApiException;
use AlgerianCommerce\Audit\AuditLogger;
use AlgerianCommerce\Core\Logger;
use AlgerianCommerce\Permissions\Capabilities;
use AlgerianCommerce\Permissions\Permissions;
use WP_Post;
use WP_Term;

/**
 * CMS reads and writes — roadmap §61, §89, docs/PLAN.md §22–§23.
 *
 * §61 was read-only and said so: *"a CMS write surface belongs to the admin
 * coverage sweep in docs/PLAN.md §52, not here."* §89 is that sweep. Nothing
 * about the read half changed except that it can now be asked for drafts.
 *
 * Authorization is asserted here as well as on the route, and the capability is
 * `ac_manage_content` for reads and writes alike — the same one that governs
 * the editor screens `ContentTypes` registers, so a person's access to a banner
 * does not depend on which door they came through. **No new capability**: §45's
 * matrix already draws the line between somebody who edits content and somebody
 * who does not, and splitting read from write would put a Marketing Manager in
 * a role that can see a page and not fix a typo in it. §61's media gap set the
 * precedent for naming a gap rather than inventing a capability; this is the
 * case where there is no gap.
 *
 * **Every write is audited, and the page path is recorded by value.** §71's
 * rule keeps values out of the trail because a shop's trade-register numbers
 * are not a thing to leave in a table nobody cleans, and it still governs
 * everything else here — a content edit records `fields: ["content"]` and not a
 * word of the content. A page's path is the exception for §88's reason: it is a
 * public identifier that every storefront link is built on, so "a page was
 * renamed" without saying from what to what is a row nobody can act on.
 */
final class CmsService
{
    /** How many FAQ ids a category's 409 names. Enough to investigate. */
    private const SAMPLE = 5;

    public function __construct(
        private readonly CmsRepository $repository,
        private readonly Logger $logger,
        private readonly AuditLogger $audit
    ) {
    }

    /**
     * @return array{data: array<string, mixed>, problems: list<string>}
     */
    public function homepage(): array
    {
        Permissions::assert(Capabilities::MANAGE_CONTENT);

        $sections = $this->repository->homepage();

        if ($sections->problems !== []) {
            /*
             * Logged as well as returned. The response reaches whoever asked;
             * the log is what an operator reads when a storefront section
             * disappeared last Tuesday and nobody remembers editing anything.
             */
            $this->logger->warning('The homepage document has problems', [
                'option' => CmsRepository::HOMEPAGE_OPTION,
                'problems' => $sections->problems,
            ]);
        }

        return [
            'data' => $sections->toArray(),
            'problems' => $sections->problems,
        ];
    }

    /**
     * Replace the homepage document.
     *
     * The read drops a malformed section and reports it; this refuses with a
     * 400 naming `sections[2].type`. §89 states the asymmetry and
     * `HomepageInput` carries the argument: an option edited by hand must
     * degrade, and a form filled in by a person must not lose their work
     * quietly.
     *
     * @param array<string, mixed> $payload
     * @return array{data: array<string, mixed>, problems: list<string>}
     */
    public function updateHomepage(array $payload): array
    {
        Permissions::assert(Capabilities::MANAGE_CONTENT);

        $input = HomepageInput::fromPayload($payload);

        $this->repository->saveHomepage($input->toArray());

        $this->audit->record('cms.homepage_updated', 'cms', CmsRepository::HOMEPAGE_OPTION, [
            'sections' => count($input->sections),
            // The section *types*, never their data: a hero's headline is
            // content, and §71's rule is that content does not belong in a
            // table nobody cleans. The shape of the document is what an
            // operator asks the trail about.
            'types' => array_values(array_unique(array_column($input->sections, 'type'))),
        ]);

        // Read back through the reader, so what a PUT returns is what the next
        // GET will return rather than what the writer believed it stored.
        return $this->homepage();
    }

    /** @param list<string> $statuses */
    public function page(string $path, array $statuses = CmsRepository::DEFAULT_STATUSES): WP_Post
    {
        Permissions::assert(Capabilities::MANAGE_CONTENT);

        $page = $this->repository->page($path, $statuses);

        if ($page === null) {
            throw ApiException::notFound('No page at that path.');
        }

        return $page;
    }

    /** @param array<string, mixed> $payload */
    public function createPage(array $payload): WP_Post
    {
        Permissions::assert(Capabilities::MANAGE_CONTENT);

        $input = PageInput::forCreate($payload);
        $parentId = $this->repository->resolveParent((string) ($input->get('parent_path') ?? ''));

        if ($input->has('slug')) {
            $this->assertPathFree($this->join((string) ($input->get('parent_path') ?? ''), (string) $input->get('slug')), 0);
        }

        $this->assertPageReferences($input);

        $page = $this->repository->createPage($input, $parentId);
        $this->repository->applyPageThumbnail($page, $input);
        $this->repository->applyPageSeo($page, $input);

        $this->audit->record('cms.page_created', 'page', (int) $page->ID, [
            'path' => $this->repository->pathFor($page),
            'status' => $page->post_status,
        ]);

        return $page;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{page: WP_Post, path_changed: bool, path: string}
     */
    public function updatePage(string $path, array $payload): array
    {
        Permissions::assert(Capabilities::MANAGE_CONTENT);

        $page = $this->page($path, PageInput::STATUSES);
        $input = PageInput::forUpdate($payload);

        if ($input->isEmpty()) {
            throw ApiException::invalidRequest('No supported fields were provided.');
        }

        $before = $this->repository->pathFor($page);
        $beforeStatus = (string) $page->post_status;

        $parentId = null;

        if ($input->has('parent_path')) {
            $parentPath = (string) $input->get('parent_path');
            $parentId = $this->repository->resolveParent($parentPath);

            if ($parentId === (int) $page->ID) {
                throw ApiException::invalidRequest('The page data is invalid.', [
                    'fields' => ['parent_path' => 'A page cannot be its own parent.'],
                ]);
            }

            $this->assertNotDescendant($page, $parentId);
        }

        /*
         * The target address, checked before the write. `wp_update_post()`
         * uniquifies a colliding slug — `terms` becomes `terms-2` — and answers
         * 200, so without this a rename onto an occupied path succeeds at a
         * path the caller never asked for and every link they then built is
         * wrong. Checked, not repaired: which of the two pages should keep the
         * name is not a decision this API gets to make.
         */
        if ($input->has('slug') || $input->has('parent_path')) {
            $parentPath = $input->has('parent_path')
                ? (string) $input->get('parent_path')
                : $this->parentPathOf($before);

            $slug = $input->has('slug') ? (string) $input->get('slug') : (string) $page->post_name;

            $this->assertPathFree($this->join($parentPath, $slug), (int) $page->ID);
        }

        $this->assertPageReferences($input);

        $page = $this->repository->updatePage($page, $input, $parentId);
        $this->repository->applyPageThumbnail($page, $input);
        $this->repository->applyPageSeo($page, $input);

        $after = $this->repository->pathFor($page);
        $pathChanged = $before !== $after;

        $this->audit->record('cms.page_updated', 'page', (int) $page->ID, [
            'fields' => array_keys($input->fields),
            'path_from' => $pathChanged ? $before : null,
            'path_to' => $pathChanged ? $after : null,
            // Publishing is what makes a page visible, so the transition is
            // recorded by value while the content it carries is not.
            'status_from' => $beforeStatus !== $page->post_status ? $beforeStatus : null,
            'status_to' => $beforeStatus !== $page->post_status ? (string) $page->post_status : null,
        ]);

        return ['page' => $page, 'path_changed' => $pathChanged, 'path' => $after];
    }

    /** @return array{id: int, path: string} */
    public function deletePage(string $path, bool $force): array
    {
        Permissions::assert(Capabilities::MANAGE_CONTENT);

        $page = $this->page($path, PageInput::STATUSES);
        $id = (int) $page->ID;
        $actual = $this->repository->pathFor($page);

        $children = $this->repository->childPageIds($id);

        if ($children !== [] && !$force) {
            /*
             * WordPress promotes a deleted page's children to the root, which
             * changes the address of every one of them and reports nothing.
             * §88's delete guards, one level up: refuse, name the count, and
             * let `?force=true` mean it.
             */
            throw ApiException::conflict(
                sprintf(
                    '%d page(s) are filed under this one and would move to the root, changing their paths. Move them first, or repeat with ?force=true.',
                    count($children)
                ),
                ['children' => count($children), 'child_ids' => array_slice($children, 0, self::SAMPLE)]
            );
        }

        if (!$this->repository->deletePost($page, $force)) {
            throw ApiException::internal('The page could not be deleted.');
        }

        $this->audit->record('cms.page_deleted', 'page', $id, [
            'path' => $actual,
            'children_reparented' => count($children),
            'forced' => $force,
        ]);

        return ['id' => $id, 'path' => $actual];
    }

    /**
     * @param array<string, mixed> $criteria
     * @return array{items: list<WP_Post>, total: int}
     */
    public function banners(array $criteria): array
    {
        Permissions::assert(Capabilities::MANAGE_CONTENT);

        return $this->repository->banners($criteria);
    }

    /** @param array<string, mixed> $payload */
    public function createBanner(array $payload): WP_Post
    {
        Permissions::assert(Capabilities::MANAGE_CONTENT);

        $input = BannerInput::forCreate($payload);

        if ($input->has('image_id')) {
            $this->repository->assertImageAttachment((int) $input->get('image_id'), 'image_id', 'banner');
        }

        $banner = $this->repository->createBanner($input);

        $this->audit->record('cms.banner_created', 'banner', (int) $banner->ID, [
            'placement' => (string) ($input->get('placement') ?? ContentTypes::DEFAULT_PLACEMENT),
            'status' => $banner->post_status,
        ]);

        return $banner;
    }

    /** @param array<string, mixed> $payload */
    public function updateBanner(int $id, array $payload): WP_Post
    {
        Permissions::assert(Capabilities::MANAGE_CONTENT);

        $banner = $this->requirePost($id, ContentTypes::BANNER, 'banner');
        $input = BannerInput::forUpdate($payload);

        if ($input->isEmpty()) {
            throw ApiException::invalidRequest('No supported fields were provided.');
        }

        if ($input->has('image_id')) {
            $this->repository->assertImageAttachment((int) $input->get('image_id'), 'image_id', 'banner');
        }

        $banner = $this->repository->updateBanner($banner, $input);

        $this->audit->record('cms.banner_updated', 'banner', $id, ['fields' => array_keys($input->fields)]);

        return $banner;
    }

    public function deleteBanner(int $id, bool $force): int
    {
        return $this->deleteContent($id, ContentTypes::BANNER, 'banner', 'cms.banner_deleted', $force);
    }

    /**
     * @param array<string, mixed> $criteria
     * @return array{items: list<WP_Post>, total: int}
     */
    public function faqs(array $criteria): array
    {
        Permissions::assert(Capabilities::MANAGE_CONTENT);

        return $this->repository->faqs($criteria);
    }

    /** @param array<string, mixed> $payload */
    public function createFaq(array $payload): WP_Post
    {
        Permissions::assert(Capabilities::MANAGE_CONTENT);

        $input = FaqInput::forCreate($payload);
        $faq = $this->repository->createFaq($input);

        $this->audit->record('cms.faq_created', 'faq', (int) $faq->ID, ['status' => $faq->post_status]);

        return $faq;
    }

    /** @param array<string, mixed> $payload */
    public function updateFaq(int $id, array $payload): WP_Post
    {
        Permissions::assert(Capabilities::MANAGE_CONTENT);

        $faq = $this->requirePost($id, ContentTypes::FAQ, 'FAQ');
        $input = FaqInput::forUpdate($payload);

        if ($input->isEmpty()) {
            throw ApiException::invalidRequest('No supported fields were provided.');
        }

        $faq = $this->repository->updateFaq($faq, $input);

        $this->audit->record('cms.faq_updated', 'faq', $id, ['fields' => array_keys($input->fields)]);

        return $faq;
    }

    public function deleteFaq(int $id, bool $force): int
    {
        return $this->deleteContent($id, ContentTypes::FAQ, 'FAQ', 'cms.faq_deleted', $force);
    }

    /** @return list<WP_Term> */
    public function faqCategories(): array
    {
        Permissions::assert(Capabilities::MANAGE_CONTENT);

        return $this->repository->faqCategories();
    }

    /** @param array<string, mixed> $payload */
    public function createFaqCategory(array $payload): WP_Term
    {
        Permissions::assert(Capabilities::MANAGE_CONTENT);

        $input = FaqCategoryInput::forCreate($payload);

        if ($input->has('slug') && $this->repository->faqCategoryBySlug((string) $input->get('slug')) !== null) {
            throw ApiException::conflict('That slug is already used by another FAQ category.', [
                'slug' => (string) $input->get('slug'),
            ]);
        }

        $term = $this->repository->createFaqCategory($input);

        $this->audit->record('cms.faq_category_created', 'faq_category', (int) $term->term_id, [
            'slug' => (string) $term->slug,
        ]);

        return $term;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{term: WP_Term, slug_changed: bool}
     */
    public function updateFaqCategory(int $id, array $payload): array
    {
        Permissions::assert(Capabilities::MANAGE_CONTENT);

        $term = $this->requireFaqCategory($id);
        $input = FaqCategoryInput::forUpdate($payload);

        if ($input->isEmpty()) {
            throw ApiException::invalidRequest('No supported fields were provided.');
        }

        if ($input->has('slug')) {
            $clash = $this->repository->faqCategoryBySlug((string) $input->get('slug'));

            if ($clash !== null && (int) $clash->term_id !== $id) {
                throw ApiException::conflict('That slug is already used by another FAQ category.', [
                    'slug' => (string) $input->get('slug'),
                ]);
            }
        }

        $before = (string) $term->slug;
        $updated = $this->repository->updateFaqCategory($term, $input);
        $after = (string) $updated->slug;
        $slugChanged = $before !== $after;

        /*
         * §88's rule, one taxonomy over: a term slug is a public identifier —
         * here it is what `GET /cms/faqs?category=…` matches — so the change is
         * audited by value and reported in `meta.slug_changed`. Not refused,
         * because sometimes a slug is genuinely wrong; never incidental.
         */
        $this->audit->record('cms.faq_category_updated', 'faq_category', $id, [
            'fields' => array_keys($input->fields),
            'slug_from' => $slugChanged ? $before : null,
            'slug_to' => $slugChanged ? $after : null,
        ]);

        return ['term' => $updated, 'slug_changed' => $slugChanged];
    }

    /** @return array{id: int, faqs_detached: int} */
    public function deleteFaqCategory(int $id, bool $force): array
    {
        Permissions::assert(Capabilities::MANAGE_CONTENT);

        $term = $this->requireFaqCategory($id);
        $count = (int) $term->count;

        if (!$force && $count > 0) {
            // §88's term guard, verbatim in shape: deleting this detaches every
            // FAQ on it, and WordPress reports nothing when it does.
            throw ApiException::conflict(
                sprintf(
                    '%d FAQ(s) are in this category. Deleting it detaches them. Re-file them first, or repeat with ?force=true.',
                    $count
                ),
                ['faqs' => $count, 'term_id' => $id]
            );
        }

        if (!$this->repository->deleteFaqCategory($id)) {
            throw ApiException::internal('The FAQ category could not be deleted.');
        }

        $this->audit->record('cms.faq_category_deleted', 'faq_category', $id, [
            'slug' => (string) $term->slug,
            'faqs_detached' => $count,
            'forced' => $force,
        ]);

        return ['id' => $id, 'faqs_detached' => $count];
    }

    /**
     * A menu by theme location.
     *
     * Two different 404s, deliberately merged into one message: a location this
     * install does not register, and a registered location with no menu
     * assigned to it. Both mean "there is no menu there" to a storefront, and
     * the distinction only matters to whoever is configuring WordPress — who
     * can see it in Appearance → Menus.
     *
     * @return array{menu: object, items: list<WP_Post>}
     */
    public function menu(string $location): array
    {
        Permissions::assert(Capabilities::MANAGE_CONTENT);

        $menu = $this->repository->menu($location);

        if ($menu === null) {
            throw ApiException::notFound('No menu is assigned to that location.');
        }

        return $menu;
    }

    /**
     * Replace a location's menu.
     *
     * The read merges its two 404s; the write must not. "There is no menu at
     * `footer`" is the ordinary state a PUT exists to fix, and "`footre` is not
     * a location this install has" is a typo — answering both with the same
     * 404 would send somebody looking for a menu that was never the problem.
     * So an unknown location is a 400 naming the registered ones, which is
     * §82's `facetable_attributes` device.
     *
     * @param array<string, mixed> $payload
     * @return array{menu: object, items: list<WP_Post>}
     */
    public function updateMenu(string $location, array $payload): array
    {
        Permissions::assert(Capabilities::MANAGE_CONTENT);

        $locations = array_keys(ContentTypes::MENU_LOCATIONS);

        if (!in_array($location, $locations, true)) {
            throw ApiException::invalidRequest('That menu location is not registered.', [
                'fields' => ['location' => 'Must be one of: ' . implode(', ', $locations) . '.'],
                'locations' => $locations,
            ]);
        }

        $input = MenuInput::fromPayload($payload);
        $result = $this->repository->saveMenu($location, $input->items);

        $this->audit->record('cms.menu_updated', 'menu', $location, [
            // The location and the count, never the labels: a menu's labels are
            // content. How many links a shop's navigation carries, and when
            // somebody emptied it, are the questions this row answers.
            'items' => count($result['items']),
        ]);

        return $result;
    }

    private function deleteContent(int $id, string $postType, string $label, string $action, bool $force): int
    {
        Permissions::assert(Capabilities::MANAGE_CONTENT);

        $post = $this->requirePost($id, $postType, $label);

        if (!$this->repository->deletePost($post, $force)) {
            throw ApiException::internal("The {$label} could not be deleted.");
        }

        $this->audit->record($action, $postType, $id, ['forced' => $force]);

        return $id;
    }

    private function requirePost(int $id, string $postType, string $label): WP_Post
    {
        $post = $this->repository->findPost($id, $postType);

        if ($post === null) {
            throw ApiException::notFound("No {$label} with that id.");
        }

        return $post;
    }

    private function requireFaqCategory(int $id): WP_Term
    {
        $term = $this->repository->findFaqCategory($id);

        if ($term === null) {
            throw ApiException::notFound('No FAQ category with that id.');
        }

        return $term;
    }

    /**
     * Every attachment a page write names, checked before the page is written.
     *
     * Both of them, and the second is why this is a method rather than two
     * lines: `seo.image_id` used to be checked inside `applyPageSeo()`, which
     * runs *after* the row exists — so a page naming a bad share image was
     * created, then refused with a 400. Same shape as `saveMenu()`'s and
     * `resolveFaqCategories()`'s, and the third time is what turned it into a
     * rule: **resolve every reference before the first write**.
     */
    private function assertPageReferences(PageInput $input): void
    {
        if ($input->has('image_id')) {
            $this->repository->assertImageAttachment((int) $input->get('image_id'), 'image_id', 'page');
        }

        $seoImage = $this->repository->seoImageId($input);

        if ($seoImage !== null) {
            $this->repository->assertImageAttachment($seoImage, 'seo.image_id', 'page');
        }
    }

    /**
     * Refuse a path another page already occupies.
     *
     * Any status counts: a draft sitting at `legal/terms` is a page somebody is
     * about to publish there, and letting a second one take the address would
     * make the collision surface on the day the first is published.
     */
    private function assertPathFree(string $path, int $exceptId): void
    {
        $existing = $this->repository->page($path, PageInput::STATUSES);

        if ($existing !== null && (int) $existing->ID !== $exceptId) {
            throw ApiException::conflict('Another page already sits at that path.', ['path' => $path]);
        }
    }

    /**
     * A page cannot be moved under its own descendant.
     *
     * WordPress does not stop this, and the result is a cycle: the branch
     * disappears from every tree walk and `get_page_uri()` recurses. Cheap to
     * check, impossible to diagnose afterwards.
     */
    private function assertNotDescendant(WP_Post $page, int $parentId): void
    {
        if ($parentId === 0) {
            return;
        }

        foreach ($this->repository->ancestorIds($parentId) as $ancestor) {
            if ($ancestor === (int) $page->ID) {
                throw ApiException::invalidRequest('The page data is invalid.', [
                    'fields' => ['parent_path' => 'That page is filed under this one, so this one cannot move under it.'],
                ]);
            }
        }
    }

    private function parentPathOf(string $path): string
    {
        $position = strrpos($path, '/');

        return $position === false ? '' : substr($path, 0, $position);
    }

    private function join(string $parentPath, string $slug): string
    {
        return $parentPath === '' ? $slug : $parentPath . '/' . $slug;
    }
}
