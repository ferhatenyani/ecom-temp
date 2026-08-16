<?php

declare(strict_types=1);

namespace AlgerianCommerce\SEO;

/**
 * The thing SEO data is being resolved *for* — roadmap §62.
 *
 * A plain carrier, and the reason `SEO/` contains no WooCommerce: a product and
 * a page have a title, some text, a picture and a slug, and everything this
 * module does is the same for both. The caller — which already knows whether it
 * is holding a `WC_Product` or a `WP_Post` — flattens it to this, so adding a
 * third kind of content later means building one more subject rather than
 * teaching the resolver about a third class.
 */
final class SeoSubject
{
    public const TYPE_PRODUCT = 'product';
    public const TYPE_PAGE = 'page';

    /**
     * @param string               $text     the description source; may be HTML
     * @param bool                 $isPublic whether the content is published — a
     *                                       draft defaults to noindex
     * @param array<string, mixed> $commerce price, currency, sku, availability;
     *                                       empty for anything that is not sold
     */
    public function __construct(
        public readonly int $id,
        public readonly string $type,
        public readonly string $title,
        public readonly string $text,
        public readonly int $imageId,
        public readonly string $slug,
        public readonly bool $isPublic = true,
        public readonly array $commerce = []
    ) {
    }
}
