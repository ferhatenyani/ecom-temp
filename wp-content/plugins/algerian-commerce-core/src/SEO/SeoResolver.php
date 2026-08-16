<?php

declare(strict_types=1);

namespace AlgerianCommerce\SEO;

use AlgerianCommerce\Media\MediaPresenter;

/**
 * Resolves the `seo` block on a content payload — roadmap §62,
 * docs/PLAN.md §25.
 *
 * Stored overrides win; everything else is derived from the content itself, so
 * a shop that has never opened an SEO field still serves a sensible title,
 * description and share image on every page. That is the whole design goal:
 * SEO that is *correct by default* and improvable by hand, rather than a set of
 * empty fields somebody has to fill in five hundred times.
 *
 * **No SEO plugin.** PLAN §25 says to use one, and §62 was built without one on
 * purpose — see the README. The short version: in a headless install the half
 * of an SEO plugin that renders tags never runs, its sitemap and robots are
 * emitted on the backend's domain rather than the storefront's, and the only
 * part that does work is a meta box over five fields. Rank Math's `getHead`
 * endpoint is a real headless answer and remains the upgrade path — a
 * `RankMathSource` reading `rank_math_*` in place of `SeoRepository` changes
 * nothing above this class.
 */
final class SeoResolver
{
    /**
     * @return array<string, mixed> the `seo` block
     */
    public static function resolve(SeoSubject $subject): array
    {
        $repository = new SeoRepository();
        $overrides = $repository->overridesFor($subject->id);

        $siteName = (string) get_bloginfo('name');

        $title = SeoFields::firstNonEmpty(
            $overrides['title'] ?? '',
            SeoFields::composeTitle($subject->title, $siteName)
        );

        $description = SeoFields::firstNonEmpty(
            $overrides['description'] ?? '',
            SeoFields::truncate(SeoFields::toPlainText($subject->text), SeoFields::DESCRIPTION_LIMIT)
        );

        /*
         * A draft, a private page or an unpublished product defaults to
         * noindex. It is reachable through this API by an authorised operator
         * long before it is meant to be public, and a storefront that renders a
         * preview must not be the reason it gets indexed.
         */
        $robots = SeoFields::parseRobots($overrides['robots'] ?? '', $subject->isPublic);

        $imageId = (int) ($overrides['image_id'] ?? 0);
        $image = MediaPresenter::image($imageId > 0 ? $imageId : $subject->imageId);

        return [
            'title' => $title,
            'description' => $description,
            // Only ever what somebody set — see SeoFields::isAcceptableCanonical
            // for why this is never derived from a WordPress permalink.
            'canonical' => $overrides['canonical'] ?? '',
            'robots' => [
                'index' => $robots['index'],
                'follow' => $robots['follow'],
                'directive' => SeoFields::robotsDirective($robots['index'], $robots['follow']),
            ],
            'og' => [
                'title' => $title,
                'description' => $description,
                'type' => $subject->type === SeoSubject::TYPE_PRODUCT ? 'product' : 'website',
                'image' => $image,
            ],
            'image' => $image,
            'structured_data' => self::structuredData($subject, $title, $description, $image),
            /*
             * Which fields a person actually set, so an admin UI can show a
             * derived value as a placeholder rather than as content somebody
             * typed — the difference between "empty, using the excerpt" and
             * "somebody wrote this" is invisible otherwise.
             */
            'overrides' => array_keys($overrides),
        ];
    }

    /**
     * JSON-LD, "where appropriate" (docs/PLAN.md §25).
     *
     * A product gets `Product` with an `Offer`, because that is the markup that
     * produces a price and an availability badge in a search result and is
     * worth real money to a shop. Everything else gets `WebPage`, which is
     * honest and cheap. Nothing here invents a rating, a review count or a
     * brand: fabricated structured data is a manual action from Google, not a
     * clever default.
     *
     * @param array<string, mixed>|null $image
     * @return array<string, mixed>
     */
    private static function structuredData(SeoSubject $subject, string $title, string $description, ?array $image): array
    {
        $data = [
            '@context' => 'https://schema.org',
            '@type' => $subject->type === SeoSubject::TYPE_PRODUCT ? 'Product' : 'WebPage',
            'name' => $subject->title !== '' ? $subject->title : $title,
        ];

        if ($description !== '') {
            $data['description'] = $description;
        }

        if ($image !== null) {
            $data['image'] = $image['src'];
        }

        if ($subject->type !== SeoSubject::TYPE_PRODUCT || $subject->commerce === []) {
            return $data;
        }

        $sku = (string) ($subject->commerce['sku'] ?? '');

        if ($sku !== '') {
            $data['sku'] = $sku;
        }

        $price = (string) ($subject->commerce['price'] ?? '');

        /*
         * An offer only when there is a price. A variable product with no
         * resolved price, or one that is not purchasable, would otherwise
         * publish `price: ""` — which Google reads as a malformed offer rather
         * than as an absent one.
         */
        if ($price !== '' && is_numeric($price)) {
            $data['offers'] = [
                '@type' => 'Offer',
                'price' => $price,
                'priceCurrency' => (string) ($subject->commerce['currency'] ?? 'DZD'),
                'availability' => self::availability((string) ($subject->commerce['availability'] ?? '')),
            ];
        }

        return $data;
    }

    private static function availability(string $stockStatus): string
    {
        return match ($stockStatus) {
            'outofstock' => 'https://schema.org/OutOfStock',
            'onbackorder' => 'https://schema.org/BackOrder',
            default => 'https://schema.org/InStock',
        };
    }
}
