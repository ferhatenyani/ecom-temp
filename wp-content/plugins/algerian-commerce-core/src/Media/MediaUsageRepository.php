<?php

declare(strict_types=1);

namespace AlgerianCommerce\Media;

use AlgerianCommerce\CMS\ContentTypes;
use AlgerianCommerce\Products\OptionSetRepository;
use AlgerianCommerce\SEO\SeoFields;
use AlgerianCommerce\Settings\SettingsRepository;
use wpdb;

/**
 * Every place this codebase stores an attachment id — roadmap §61.
 *
 * The read behind `GET /media/{id}/usage`. It exists because
 * `MediaRepository::delete()` is `wp_delete_attachment($id, true)`: the file
 * leaves the disk and the row leaves the database, so the panel has to be able
 * to say what breaks *before* somebody presses the button.
 *
 * ## Five stores, not the eight it looks like
 *
 * A product's featured image, a variation's image, a CMS page's thumbnail and a
 * banner's thumbnail are **one meta key**. WooCommerce maps `image_id` onto
 * `_thumbnail_id` for both products and variations
 * (`WC_Product_Data_Store_CPT::$meta_key_to_props`), and `CmsRepository`'s
 * `applyThumbnail()` is `set_post_thumbnail()`, which writes the same key. One
 * indexed query answers all four, and the post type is what tells them apart.
 *
 *   `_thumbnail_id`           featured_image        = on the id
 *   `_product_image_gallery`  gallery               LIKE, then split and compare
 *   `_ac_option_set`          option_choice_image   LIKE, then decode and compare
 *   `_ac_seo_image_id`        seo_image             = on the id
 *   `ac_client_settings`      store_logo            one option read
 *
 * The two LIKEs are the two documents this project stores as text — a
 * comma-separated list and a JSON blob — and neither can be matched exactly in
 * SQL. Both are treated as **candidate filters only**: the answer is decided in
 * PHP, by splitting the list or decoding the document, so `12` never reports
 * `120` and a substring never becomes a reference.
 *
 * All four queries are bounded by `MAX_MATCHES`, and one that reaches that
 * bound says so rather than quietly returning a short list — see `find()`.
 *
 * ## Nothing here filters by post type
 *
 * `_thumbnail_id` is WordPress's own key and `_ac_seo_image_id` is written by
 * `SeoRepository::save()` for *any* post id. Restricting the query to the four
 * types this plugin knows about would silently miss a fifth, which is the one
 * thing this endpoint must not do. An unrecognised type is reported under its
 * own name instead.
 */
final class MediaUsageRepository
{
    /** WordPress's featured image, which is also every image this plugin sets. */
    private const META_THUMBNAIL = '_thumbnail_id';

    /** WooCommerce's gallery: `"12,34,56"`. */
    private const META_GALLERY = '_product_image_gallery';

    /**
     * The slots this repository actually searches.
     *
     * Reported to the client verbatim, because a `total` of 0 means nothing
     * without the list of places that were looked in.
     */
    public const SCOPES = [
        'featured_image',
        'gallery',
        'option_choice_image',
        'seo_image',
        'store_logo',
    ];

    /**
     * The places an attachment can be referenced that **no query can find**.
     *
     * `homepage_section_data` is the homepage document: `HomepageSections`
     * validates a section's `type` and that its `data` is an object, and this
     * API defines no schema per type — so `{"type":"hero","data":{"image":42}}`
     * is a valid, stored, unfindable reference.
     *
     * `content_html` is the same problem one level down: page, banner, product
     * and email bodies are HTML, and `ContentHtml::ALLOWED` permits `<img>`.
     * An image dropped into body text is stored as a URL, never as an id.
     *
     * Named rather than omitted. A caller that is told what was checked can
     * still assume the complement was checked too, and this endpoint's whole
     * value is that the sentence it lets the panel write stays true.
     */
    public const UNSEARCHABLE = [
        'homepage_section_data',
        'content_html',
    ];

    /**
     * The ceiling on rows any one query may return.
     *
     * A safety bound, not a page size: an image held by more than a hundred
     * products is a data problem rather than a shop, and this route answers a
     * dialog, so an unbounded list would be neither useful nor cheap. Reaching
     * it is reported through `incomplete`, so a truncated answer is never
     * presented as a whole one.
     */
    private const MAX_MATCHES = 100;

    /** post type → the word the panel puts in a sentence. */
    private const KINDS = [
        'product' => 'product',
        'product_variation' => 'variation',
        'page' => 'page',
        ContentTypes::BANNER => 'banner',
    ];

    public function __construct(
        private readonly wpdb $wpdb,
        private readonly SettingsRepository $settings
    ) {
    }

    /**
     * @return array{
     *     references: list<array{kind: string, id: int, title: string, slot: string}>,
     *     checked: list<string>,
     *     incomplete: list<string>
     * }
     */
    public function find(int $id): array
    {
        $references = [];
        $incomplete = self::UNSEARCHABLE;

        $thumbnails = $this->rows(self::META_THUMBNAIL, (string) $id);

        foreach ($thumbnails as $row) {
            $references[] = $this->reference($row, 'featured_image');
        }

        /*
         * The gallery is one string, so SQL can only narrow. `%34%` also matches
         * `340` and `1345`; the split below is what decides, and it is the
         * reason the LIKE may be loose without the answer being wrong.
         */
        $galleries = $this->rows(self::META_GALLERY, $this->like((string) $id), true);

        foreach ($galleries as $row) {
            if (in_array($id, array_map('intval', explode(',', (string) $row->meta_value)), true)) {
                $references[] = $this->reference($row, 'gallery');
            }
        }

        /*
         * `"image_id":42` is what `OptionSetRepository::save()` writes —
         * `wp_json_encode()` puts no space after the colon, and that method is
         * the only writer, because the key is underscore-prefixed precisely so
         * wp-admin does not offer the document for hand-editing. The decode is
         * still the authority; the pattern only keeps the candidate set small.
         */
        $optionSets = $this->rows(
            OptionSetRepository::META_KEY,
            $this->like('"image_id":' . $id),
            true
        );

        foreach ($optionSets as $row) {
            if ($this->optionSetUses((string) $row->meta_value, $id)) {
                $references[] = $this->reference($row, 'option_choice_image');
            }
        }

        $seo = $this->rows(SeoFields::META_IMAGE_ID, (string) $id);

        foreach ($seo as $row) {
            $references[] = $this->reference($row, 'seo_image');
        }

        $logo = $this->storeLogo($id);

        if ($logo !== null) {
            $references[] = $logo;
        }

        foreach (['featured_image' => $thumbnails, 'gallery' => $galleries,
            'option_choice_image' => $optionSets, 'seo_image' => $seo] as $scope => $rows) {
            if (count($rows) >= self::MAX_MATCHES) {
                $incomplete[] = $scope;
            }
        }

        return [
            'references' => $references,
            'checked' => self::SCOPES,
            'incomplete' => $incomplete,
        ];
    }

    /**
     * Candidate rows for one meta key, with the post they belong to.
     *
     * `meta_value` is compared as a string rather than with `%d`: the column is
     * `longtext`, and handing MySQL an integer makes it coerce every row in the
     * key rather than compare bytes.
     *
     * @return list<object>
     */
    private function rows(string $metaKey, string $value, bool $like = false): array
    {
        $sql = "SELECT pm.post_id, pm.meta_value, p.post_type, p.post_title
                FROM {$this->wpdb->postmeta} pm
                INNER JOIN {$this->wpdb->posts} p ON p.ID = pm.post_id
                WHERE pm.meta_key = %s AND pm.meta_value " . ($like ? 'LIKE' : '=') . " %s
                ORDER BY pm.post_id
                LIMIT %d";

        $rows = $this->wpdb->get_results(
            $this->wpdb->prepare($sql, $metaKey, $value, self::MAX_MATCHES)
        );

        return is_array($rows) ? $rows : [];
    }

    private function like(string $needle): string
    {
        return '%' . $this->wpdb->esc_like($needle) . '%';
    }

    /** @return array{kind: string, id: int, title: string, slot: string} */
    private function reference(object $row, string $slot): array
    {
        $postId = (int) $row->post_id;
        $title = trim((string) $row->post_title);

        return [
            'kind' => self::KINDS[(string) $row->post_type] ?? (string) $row->post_type,
            'id' => $postId,
            // A variation and a draft can both have an empty title, and "" is
            // not something a shopkeeper can identify in a warning.
            'title' => $title !== '' ? $title : '#' . $postId,
            'slot' => $slot,
        ];
    }

    private function optionSetUses(string $document, int $id): bool
    {
        $decoded = json_decode($document, true);

        if (!is_array($decoded) || !is_array($decoded['groups'] ?? null)) {
            return false;
        }

        foreach ($decoded['groups'] as $group) {
            foreach ((is_array($group) ? $group['choices'] ?? [] : []) as $choice) {
                if (is_array($choice) && (int) ($choice['image_id'] ?? 0) === $id) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * The shop's logo, which lives in an option rather than on a post.
     *
     * Id 0 for the same reason the audit trail uses it for settings: there is
     * one settings document and it has no row id. The title is the shop's own
     * name, so the panel can write "the logo of Boutique Amel" rather than
     * naming a table.
     *
     * @return array{kind: string, id: int, title: string, slot: string}|null
     */
    private function storeLogo(int $id): ?array
    {
        $stored = $this->settings->stored();
        $logoId = (int) ($stored['store']['logo_id'] ?? 0);

        if ($logoId !== $id) {
            return null;
        }

        $name = trim($this->settings->storeName());

        return [
            'kind' => 'settings',
            'id' => 0,
            'title' => $name !== '' ? $name : 'Store settings',
            'slot' => 'store_logo',
        ];
    }
}
