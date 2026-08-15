<?php

declare(strict_types=1);

namespace AlgerianCommerce\CMS;

use AlgerianCommerce\API\ApiException;
use AlgerianCommerce\Core\Logger;
use AlgerianCommerce\Permissions\Capabilities;
use AlgerianCommerce\Permissions\Permissions;
use WP_Post;

/**
 * CMS reads — roadmap §61, docs/PLAN.md §22–§23.
 *
 * Read-only, and that is the whole of §61: WordPress stores content, this
 * exposes it, Next.js renders it. Authoring is the WordPress editor and WP-CLI
 * (see the README); a CMS write surface belongs to the admin coverage sweep in
 * docs/PLAN.md §52, not here.
 *
 * Authorization is asserted here as well as on the route, and the capability is
 * `ac_manage_content` — the same one that governs the editor screens
 * `ContentTypes` registers, so a person's access to a banner does not depend on
 * which door they came through.
 */
final class CmsService
{
    public function __construct(
        private readonly CmsRepository $repository,
        private readonly Logger $logger
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

    public function page(string $slug): WP_Post
    {
        Permissions::assert(Capabilities::MANAGE_CONTENT);

        $page = $this->repository->page($slug);

        if ($page === null) {
            throw ApiException::notFound('No published page with that slug.');
        }

        return $page;
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

    /**
     * @param array<string, mixed> $criteria
     * @return array{items: list<WP_Post>, total: int}
     */
    public function faqs(array $criteria): array
    {
        Permissions::assert(Capabilities::MANAGE_CONTENT);

        return $this->repository->faqs($criteria);
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
}
