<?php

declare(strict_types=1);

namespace AlgerianCommerce\Media;

use AlgerianCommerce\API\ApiException;
use finfo;

/**
 * What may be uploaded — roadmap §61, docs/SECURITY.md "File uploads".
 *
 * `POST /media` is the only endpoint in this API that writes a file a web
 * server might later execute, so this class holds every rule that decides
 * whether a byte stream is allowed to become one. It is **pure**: no WordPress,
 * no filesystem writes, no globals, so every abuse case in roadmap §65 —
 * a PHP file renamed `.jpg`, a polyglot, a double extension, a traversal
 * filename, an oversized file — is a unit test rather than a live experiment.
 *
 * The checks run in this order, and the order is load-bearing:
 *
 * ```
 * size        cheapest, and refuses before anything reads the file
 * filename    hostile shapes are rejected, not silently repaired
 * contents    the real type, from the magic bytes and the image header
 * agreement   the extension the client sent must match what it proved
 * ```
 *
 * **Three independent checks, not one.** The client's `Content-Type` is never
 * consulted — it is a claim, and this class is what the claim is checked
 * against. `finfo` reads the magic bytes, `getimagesize()` parses the image
 * header, and the extension allowlist is compared against both; WordPress's own
 * `wp_check_filetype_and_ext()` then runs a fourth time inside
 * `wp_handle_upload()`. Any one of them passing is not enough.
 *
 * **Images only, and only three of them.** JPEG, PNG and WebP are what a shop
 * needs — product images, banners, content images (docs/PLAN.md §24) — and each
 * survives the metadata strip in `ImageSanitizer` intact. The exclusions are
 * deliberate, and each has a reason rather than an oversight:
 *
 * ```
 * svg   XML, so it carries <script> and external entities. An "image" that
 *         executes in the viewer's origin is not an image.
 * pdf   a document format with its own scripting engine, and nothing in a
 *         commerce backend needs one uploaded through this route.
 * gif   the only reason to want one is animation, and animation cannot
 *         survive the re-encode that strips metadata. Silently flattening
 *         somebody's animated banner is worse than refusing it.
 * avif  the sanitiser must be able to re-encode every accepted type on every
 *         host; AVIF support in GD is a build option, so a file accepted here
 *         could be unsanitisable there.
 * ```
 *
 * Adding a type means adding it to both maps below *and* confirming
 * `ImageSanitizer` can re-encode it — an accepted type that cannot be
 * sanitised would be stored exactly as it arrived.
 */
final class UploadPolicy
{
    /** 8 MiB. Raise with `AC_MEDIA_MAX_BYTES`; PHP's own limit still wins. */
    public const DEFAULT_MAX_BYTES = 8388608;

    /** Below this nothing is a decodable image, so it is a malformed request. */
    public const MIN_BYTES = 64;

    public const MAX_FILENAME_LENGTH = 255;
    public const MAX_STEM_LENGTH = 80;

    /**
     * The types a file may prove itself to be, and the extension each is
     * stored under. The stored extension comes from **here**, keyed by what
     * the contents proved — never from the name the client sent.
     */
    public const ACCEPTED_TYPES = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    /** The extensions a client may present, and the type each must prove. */
    public const ALLOWED_EXTENSIONS = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
    ];

    /**
     * Refused **anywhere** in a filename, not only at the end.
     *
     * `shell.php.jpg` ends in an allowed extension and, on a server configured
     * to hand any path containing `.php` to the interpreter, is a web shell.
     * The stem is rewritten anyway a few lines further down, so this list is
     * belt to that braces — but a hostile name is rejected rather than quietly
     * repaired, because a caller who sent one should be told, and because a
     * repair that is one day wrong fails silently.
     */
    public const FORBIDDEN_SEGMENTS = [
        'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phps', 'pht', 'phtml', 'phar',
        'shtml', 'htaccess', 'htpasswd', 'ini', 'cgi', 'pl', 'py', 'rb', 'sh', 'bash',
        'exe', 'dll', 'so', 'jar', 'jsp', 'asp', 'aspx', 'cer', 'swf',
        'html', 'htm', 'xhtml', 'svg', 'js', 'mjs',
    ];

    public function __construct(private readonly int $maxBytes = self::DEFAULT_MAX_BYTES)
    {
    }

    /**
     * The effective cap: ours, but never above what PHP will actually accept.
     *
     * A configured 50 MB on a host whose `upload_max_filesize` is 2 MB is not a
     * 50 MB cap — it is a 2 MB cap that reports the wrong number and fails in
     * the web server rather than in this API, where nothing can explain it.
     *
     * @param string|null $configured    `AC_MEDIA_MAX_BYTES`, unparsed
     * @param int|null    $hostMaxBytes  what PHP allows, or null when unknown
     */
    public static function withCap(?string $configured, ?int $hostMaxBytes = null): self
    {
        $bytes = self::DEFAULT_MAX_BYTES;

        if ($configured !== null && ctype_digit(trim($configured)) && (int) trim($configured) >= self::MIN_BYTES) {
            $bytes = (int) trim($configured);
        }

        if ($hostMaxBytes !== null && $hostMaxBytes > 0) {
            $bytes = min($bytes, $hostMaxBytes);
        }

        return new self($bytes);
    }

    public function maxBytes(): int
    {
        return $this->maxBytes;
    }

    /**
     * Run every check and return what the file is allowed to become.
     *
     * @return array{extension: string, mime: string, filename: string}
     *
     * @throws ApiException
     */
    public function accept(string $clientName, int $bytes, string $tmpPath): array
    {
        $this->assertSize($bytes);
        $claimed = $this->assertFilename($clientName);
        $mime = $this->sniff($tmpPath);
        $this->assertAgrees($claimed, $mime);

        return [
            'extension' => self::ACCEPTED_TYPES[$mime],
            'mime' => $mime,
            'filename' => $this->storedFilename($clientName, $mime),
        ];
    }

    /** @throws ApiException */
    public function assertSize(int $bytes): void
    {
        if ($bytes < self::MIN_BYTES) {
            throw new ApiException(
                'invalid_upload',
                'The uploaded file is empty or truncated.',
                400,
                ['size' => $bytes]
            );
        }

        if ($bytes > $this->maxBytes) {
            throw new ApiException(
                'file_too_large',
                sprintf('The file is larger than the %d byte limit.', $this->maxBytes),
                413,
                ['size' => $bytes, 'max_bytes' => $this->maxBytes]
            );
        }
    }

    /**
     * Reject a hostile filename; return the extension the client claimed.
     *
     * A path separator, a `..`, a NUL byte or a control character is never a
     * mistake, so none of them is repaired into something acceptable.
     *
     * @throws ApiException
     */
    public function assertFilename(string $name): string
    {
        $name = trim($name);

        if ($name === '' || strlen($name) > self::MAX_FILENAME_LENGTH) {
            throw self::badName('The filename is missing or too long.');
        }

        if (str_contains($name, "\0") || preg_match('/[\x00-\x1F\x7F]/', $name) === 1) {
            throw self::badName('The filename contains control characters.');
        }

        if (str_contains($name, '/') || str_contains($name, '\\') || str_contains($name, '..')) {
            throw self::badName('The filename must not contain a path.');
        }

        $segments = explode('.', strtolower($name));
        $extension = array_pop($segments);

        if ($segments === [] || $extension === null) {
            throw self::badName('The filename has no extension.');
        }

        // Every interior segment, and the stem itself: `.htaccess` arrives as
        // stem "" and extension "htaccess", `shell.php.jpg` as an interior one.
        foreach ($segments as $segment) {
            if (in_array($segment, self::FORBIDDEN_SEGMENTS, true)) {
                throw self::badName('The filename contains a disallowed extension.');
            }
        }

        if (!array_key_exists($extension, self::ALLOWED_EXTENSIONS)) {
            throw new ApiException(
                'unsupported_media_type',
                sprintf('Only %s files are accepted.', implode(', ', array_keys(self::ACCEPTED_TYPES))),
                415,
                ['extension' => $extension]
            );
        }

        return $extension;
    }

    /**
     * The real type, read from the bytes.
     *
     * Two readers that fail differently: `finfo` matches magic numbers at the
     * head of the file, `getimagesize()` parses enough of the image header to
     * report dimensions. A file that satisfies one and not the other is not an
     * image we are prepared to store.
     *
     * @throws ApiException
     */
    public function sniff(string $path): string
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new ApiException('invalid_upload', 'The uploaded file could not be read.', 400);
        }

        $detected = (new finfo(FILEINFO_MIME_TYPE))->file($path);
        $detected = is_string($detected) ? strtolower($detected) : '';

        if (!array_key_exists($detected, self::ACCEPTED_TYPES)) {
            throw self::badType($detected);
        }

        $image = @getimagesize($path);

        if (!is_array($image) || !isset($image['mime']) || strtolower((string) $image['mime']) !== $detected) {
            throw self::badType($detected);
        }

        return $detected;
    }

    /**
     * The extension the client sent must name the type the file proved.
     *
     * A JPEG called `.png` is refused rather than renamed. It is almost always
     * a mistake — but "almost always" is the wrong basis for the one endpoint
     * that writes files, and a caller told "these disagree" fixes it in
     * seconds.
     *
     * @throws ApiException
     */
    public function assertAgrees(string $extension, string $mime): void
    {
        $expected = self::ALLOWED_EXTENSIONS[$extension] ?? null;

        if ($expected !== $mime) {
            throw new ApiException(
                'unsupported_media_type',
                'The file contents do not match its extension.',
                415,
                ['extension' => $extension, 'detected' => $mime]
            );
        }
    }

    /**
     * The name the file is stored under.
     *
     * The stem keeps whatever was readable in the client's name — an image
     * called `tapis-berbere.jpg` should still be called that, because the URL
     * ends up in a product page — and nothing else. The extension is taken from
     * the sniffed type, so a double extension cannot survive this function even
     * if `assertFilename()` were one day loosened.
     */
    public function storedFilename(string $clientName, string $mime): string
    {
        $stem = (string) preg_replace('/\.[^.]*$/', '', trim($clientName));
        $stem = strtolower($stem);
        // Latin letters, digits and separators; everything else — including any
        // dot that made this a multi-extension name — becomes a hyphen.
        $stem = (string) preg_replace('/[^a-z0-9]+/', '-', $stem);
        $stem = trim($stem, '-');

        if (strlen($stem) > self::MAX_STEM_LENGTH) {
            $stem = rtrim(substr($stem, 0, self::MAX_STEM_LENGTH), '-');
        }

        // A name made entirely of characters we drop — Arabic, for one — is
        // normal here and must not produce a file called ".jpg".
        if ($stem === '') {
            $stem = 'image';
        }

        return $stem . '.' . self::ACCEPTED_TYPES[$mime];
    }

    /**
     * What `wp_handle_upload()` is told to allow, in WordPress's own
     * `ext|ext => mime` shape, so its check and ours cannot drift.
     *
     * @return array<string, string>
     */
    public static function wordPressMimes(): array
    {
        $grouped = [];

        foreach (self::ALLOWED_EXTENSIONS as $extension => $mime) {
            $grouped[$mime][] = $extension;
        }

        $mimes = [];

        foreach ($grouped as $mime => $extensions) {
            $mimes[implode('|', $extensions)] = $mime;
        }

        return $mimes;
    }

    private static function badName(string $message): ApiException
    {
        return new ApiException('invalid_upload', $message, 400);
    }

    private static function badType(string $detected): ApiException
    {
        return new ApiException(
            'unsupported_media_type',
            sprintf('Only %s files are accepted.', implode(', ', array_keys(self::ACCEPTED_TYPES))),
            415,
            // The detected type is echoed because the caller is authenticated
            // and holds ac_manage_content: they are debugging their own upload,
            // not probing a stranger's server.
            ['detected' => $detected === '' ? 'unknown' : $detected]
        );
    }
}
