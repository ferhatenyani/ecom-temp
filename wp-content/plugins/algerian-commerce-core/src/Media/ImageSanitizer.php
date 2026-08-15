<?php

declare(strict_types=1);

namespace AlgerianCommerce\Media;

use AlgerianCommerce\API\ApiException;
use AlgerianCommerce\Core\Logger;

/**
 * Strips an uploaded image down to its pixels — roadmap §61,
 * docs/SECURITY.md "File uploads".
 *
 * The file is decoded and written back out, so what is stored contains the
 * picture and nothing else: no EXIF, no GPS coordinates, no camera serial, no
 * JPEG comment, and nothing appended after the end-of-image marker. That last
 * one is what makes a polyglot a polyglot, and it is why this runs on **every**
 * accepted upload rather than only on suspicious ones.
 *
 * ## Why the editor is pinned to GD
 *
 * Measured in this stack on 2026-08-15, against a JPEG carrying an EXIF
 * `ImageDescription`, a JPEG comment and a `<?php … ?>` payload appended after
 * EOI:
 *
 * ```
 * default editor (Imagick)   payload gone   comment KEPT   EXIF KEPT
 * forced WP_Image_Editor_GD  payload gone   comment gone   EXIF gone
 * ```
 *
 * `WP_Image_Editor_Imagick::save()` writes the image with its profiles intact —
 * `strip_meta()` exists but is only reached through `thumbnail_image()`, on the
 * resize path, and a same-size resize early-returns before it. So on an Imagick
 * host "metadata stripped" would quietly not be true.
 *
 * Worse than either behaviour is *both*: the two containers in this very stack
 * disagreed about which editor WordPress picks — the Debian `wordpress` image
 * chose Imagick, the Alpine `wordpress:cli` image fell back to GD because its
 * ImageMagick has no JPEG delegate. A security property that depends on which
 * PHP process handled the request is not a property. Pinning the editor for
 * this one operation makes the outcome identical everywhere, and
 * `wp_image_editors` is WordPress's own supported filter for saying so.
 *
 * The costs are real and accepted: GD re-encodes, so a JPEG is recompressed
 * once at quality 90, and an ICC colour profile does not survive. Alpha does —
 * a transparent PNG comes back transparent, which was measured too. Anything
 * GD cannot decode is refused rather than stored unsanitised.
 */
final class ImageSanitizer
{
    /**
     * Higher than WordPress's default 82, because this is the *original* a
     * shop keeps, not a generated thumbnail, and it is recompressed once for
     * a security reason rather than for size.
     */
    public const QUALITY = 90;

    public function __construct(private readonly Logger $logger)
    {
    }

    /**
     * Rewrite the file in place, keeping only the pixels.
     *
     * @throws ApiException when the image cannot be decoded and re-encoded —
     *                      an unsanitisable file is never stored
     */
    public function sanitize(string $path, string $mime): void
    {
        $pin = static fn (): array => ['WP_Image_Editor_GD'];

        add_filter('wp_image_editors', $pin, 99);

        try {
            $editor = wp_get_image_editor($path);

            if (is_wp_error($editor)) {
                throw $this->refuse($path, 'the image could not be opened', $editor->get_error_message());
            }

            $editor->set_quality(self::QUALITY);

            $saved = $editor->save($path, $mime);

            if (is_wp_error($saved)) {
                throw $this->refuse($path, 'the image could not be re-encoded', $saved->get_error_message());
            }
        } finally {
            // In a finally, so a refusal cannot leave the editor pinned for
            // the rest of the request — thumbnail generation runs after this.
            remove_filter('wp_image_editors', $pin, 99);
        }
    }

    /**
     * Refusing means the bytes must not survive: the file has already been
     * moved into the uploads directory by this point, and leaving an
     * unsanitised image there under a predictable name is the whole problem.
     */
    private function refuse(string $path, string $reason, string $detail): ApiException
    {
        @unlink($path);

        $this->logger->warning('Refused an image that could not be sanitised', [
            'reason' => $reason,
            'detail' => $detail,
        ]);

        return new ApiException(
            'unsupported_media_type',
            'The image could not be processed.',
            415
        );
    }
}
