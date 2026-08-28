<?php

declare(strict_types=1);

namespace AlgerianCommerce\Media;

use WP_Post;

/**
 * Attachment output shapes — roadmap §61, docs/PLAN.md §24.
 *
 * Three of them, deliberately. `toArray()` is the media library's own view, for
 * `/media`; `image()` is the compact reference other content embeds — an id, a
 * URL, a thumbnail and the alt text, which is everything a storefront needs to
 * render an `<img>` and nothing it does not; `usage()` is the mirror of
 * `image()`, pointing the other way — the compact reference to whatever holds
 * an attachment, which is everything the delete dialog needs to name it in a
 * sentence and nothing it does not.
 */
final class MediaPresenter
{
    /** @param list<WP_Post> $attachments @return list<array<string, mixed>> */
    public static function toArrayList(array $attachments): array
    {
        return array_values(array_map([self::class, 'toArray'], $attachments));
    }

    /** @return array<string, mixed> */
    public static function toArray(WP_Post $attachment): array
    {
        $id = (int) $attachment->ID;
        $metadata = wp_get_attachment_metadata($id);
        $metadata = is_array($metadata) ? $metadata : [];

        return [
            'id' => $id,
            'title' => $attachment->post_title,
            'slug' => $attachment->post_name,
            'alt' => (string) get_post_meta($id, '_wp_attachment_image_alt', true),
            'caption' => $attachment->post_excerpt,
            'mime_type' => $attachment->post_mime_type,
            'url' => (string) wp_get_attachment_url($id),
            'filename' => (string) wp_basename((string) get_attached_file($id)),
            'filesize' => isset($metadata['filesize']) ? (int) $metadata['filesize'] : self::filesize($id),
            'width' => isset($metadata['width']) ? (int) $metadata['width'] : null,
            'height' => isset($metadata['height']) ? (int) $metadata['height'] : null,
            'sizes' => self::sizes($metadata),
            'uploaded_by' => (int) $attachment->post_author,
            'date_created' => mysql_to_rfc3339($attachment->post_date_gmt),
            'date_modified' => mysql_to_rfc3339($attachment->post_modified_gmt),
        ];
    }

    /**
     * The compact reference embedded in a product, a banner or a page.
     *
     * @return array<string, mixed>|null null when there is no image, or when
     *         the attachment has since been deleted — a dangling id is a
     *         missing picture, never a broken response
     */
    public static function image(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }

        $src = wp_get_attachment_image_url($id, 'full');

        if ($src === false) {
            return null;
        }

        return [
            'id' => $id,
            'src' => $src,
            'thumbnail' => wp_get_attachment_image_url($id, 'medium') ?: $src,
            'alt' => (string) get_post_meta($id, '_wp_attachment_image_alt', true),
        ];
    }

    /**
     * What holds an attachment, for `GET /media/{id}/usage`.
     *
     * Four fields per reference, the same discipline `image()` keeps: a `kind`
     * and a `title` to name the thing, an `id` to link to it, and the `slot` it
     * occupies. Nothing about the attachment itself — the caller asked about a
     * specific id and already has it.
     *
     * `checked` and `incomplete` ride in `data` rather than `meta` because they
     * are not envelope bookkeeping: they are the qualification on `total`
     * without which it would be read as a guarantee. `total` counts references
     * found in `checked`; `incomplete` names the places this answer does not
     * cover, so a `total` of 0 reads as *no known uses* and never as *no uses*.
     * A slot in both lists was searched and hit its bound — see
     * `MediaUsageRepository::MAX_MATCHES`.
     *
     * @param array{
     *     references: list<array{kind: string, id: int, title: string, slot: string}>,
     *     checked: list<string>,
     *     incomplete: list<string>
     * } $usage
     * @return array<string, mixed>
     */
    public static function usage(array $usage): array
    {
        return [
            'total' => count($usage['references']),
            'references' => $usage['references'],
            'checked' => $usage['checked'],
            'incomplete' => $usage['incomplete'],
        ];
    }

    /**
     * The generated sub-sizes, keyed by name.
     *
     * @param array<string, mixed> $metadata
     * @return array<string, array<string, mixed>>
     */
    private static function sizes(array $metadata): array
    {
        $sizes = $metadata['sizes'] ?? [];

        if (!is_array($sizes)) {
            return [];
        }

        $out = [];

        foreach ($sizes as $name => $size) {
            if (!is_string($name) || !is_array($size)) {
                continue;
            }

            $out[$name] = [
                'width' => (int) ($size['width'] ?? 0),
                'height' => (int) ($size['height'] ?? 0),
                'mime_type' => (string) ($size['mime-type'] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * Only reached for an attachment whose metadata predates WordPress storing
     * a filesize, or is not an image at all.
     */
    private static function filesize(int $id): ?int
    {
        $path = get_attached_file($id);

        if (!is_string($path) || !is_readable($path)) {
            return null;
        }

        $bytes = filesize($path);

        return $bytes === false ? null : $bytes;
    }
}
