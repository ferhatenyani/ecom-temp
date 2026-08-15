<?php

declare(strict_types=1);

namespace AlgerianCommerce\Tests\Unit;

use AlgerianCommerce\API\ApiException;
use AlgerianCommerce\Media\UploadPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The rules that guard the only endpoint which writes a file — roadmap §61,
 * §65 ("file upload abuse"), docs/SECURITY.md "File uploads".
 *
 * Every abuse case §61 names is here, against **real bytes on disk** rather
 * than a mocked sniffer: a PHP file renamed `.jpg`, a polyglot image, a double
 * extension, a path-traversal filename and an oversized file. Sniffing is the
 * one check that cannot be faked without defeating the point of it, so these
 * write actual files to a temporary directory and let `finfo` and
 * `getimagesize()` read them.
 *
 * No WordPress. That is what makes it possible to assert on the hostile cases
 * at all — the alternative is uploading a web shell to a running install and
 * hoping the assertion is the thing that fails.
 */
final class UploadPolicyTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/ac-upload-' . bin2hex(random_bytes(6));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->dir);
    }

    // ------------------------------------------------------------- fixtures --

    private function write(string $name, string $bytes): string
    {
        $path = $this->dir . '/' . $name;
        file_put_contents($path, $bytes);

        return $path;
    }

    private function jpeg(int $width = 20, int $height = 20): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, 180, 40, 40));
        ob_start();
        imagejpeg($image, null, 85);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }

    private function png(): string
    {
        $image = imagecreatetruecolor(20, 20);
        imagefill($image, 0, 0, imagecolorallocate($image, 20, 90, 200));
        ob_start();
        imagepng($image);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }

    private function policy(int $maxBytes = UploadPolicy::DEFAULT_MAX_BYTES): UploadPolicy
    {
        return new UploadPolicy($maxBytes);
    }

    /** @return array{0: int, 1: string} status and error code */
    private function refusal(callable $act): array
    {
        try {
            $act();
        } catch (ApiException $exception) {
            return [$exception->statusCode(), $exception->errorCode()];
        }

        self::fail('the policy accepted something it should have refused');
    }

    // --------------------------------------------------------- the happy path --

    public function testARealImageIsAccepted(): void
    {
        $path = $this->write('tapis.jpg', $this->jpeg());

        $accepted = $this->policy()->accept('tapis.jpg', (int) filesize($path), $path);

        self::assertSame('jpg', $accepted['extension']);
        self::assertSame('image/jpeg', $accepted['mime']);
        self::assertSame('tapis.jpg', $accepted['filename']);
    }

    public function testAPngIsAcceptedToo(): void
    {
        $path = $this->write('logo.png', $this->png());

        $accepted = $this->policy()->accept('logo.png', (int) filesize($path), $path);

        self::assertSame('image/png', $accepted['mime']);
        self::assertSame('logo.png', $accepted['filename']);
    }

    // --------------------------------------------------------- §65 abuse cases --

    /**
     * The one that matters most: a web shell wearing an image's extension.
     *
     * Nothing about the *name* gives this away — the sniff is what catches it,
     * which is why the client's Content-Type is never consulted.
     */
    public function testAPhpFileRenamedToJpgIsRefused(): void
    {
        $path = $this->write('shell.jpg', "<?php system(\$_GET['c']); ?>\n" . str_repeat('#', 200));

        self::assertSame(
            [415, 'unsupported_media_type'],
            $this->refusal(fn () => $this->policy()->accept('shell.jpg', (int) filesize($path), $path))
        );
    }

    /**
     * A double extension is refused by name, before anything reads the file.
     *
     * `shell.php.jpg` ends in an allowed extension, and on a server that hands
     * any path containing `.php` to the interpreter it is a live web shell.
     */
    public function testADoubleExtensionIsRefused(): void
    {
        $path = $this->write('double.jpg', $this->jpeg());

        self::assertSame(
            [400, 'invalid_upload'],
            $this->refusal(fn () => $this->policy()->accept('shell.php.jpg', (int) filesize($path), $path))
        );
    }

    /**
     * A polyglot — a genuinely valid JPEG with PHP appended after the
     * end-of-image marker — is **accepted here**, and that is correct.
     *
     * It is an image by every test this class can apply, and refusing every
     * image with trailing bytes would refuse a great many cameras. What
     * neutralises it is `ImageSanitizer`, which re-encodes from decoded pixels
     * so the payload does not survive being stored. This test exists to pin
     * that division of labour down: if someone ever deletes the sanitiser
     * believing the policy catches this, the comment above is the answer.
     */
    public function testAPolyglotPassesTheContentCheckAndIsLeftToTheSanitizer(): void
    {
        $bytes = $this->jpeg() . "\n<?php system(\$_GET['c']); ?>";
        $path = $this->write('polyglot.jpg', $bytes);

        $accepted = $this->policy()->accept('polyglot.jpg', (int) filesize($path), $path);

        self::assertSame('image/jpeg', $accepted['mime']);
        self::assertStringContainsString('<?php', (string) file_get_contents($path));
    }

    /** @return array<string, array{0: string}> */
    public static function traversalProvider(): array
    {
        return [
            'parent directory' => ['../../evil.jpg'],
            'absolute path' => ['/etc/passwd.jpg'],
            'windows separator' => ['..\\..\\evil.jpg'],
            'dot segment only' => ['..jpg.jpg'],
            'nested' => ['uploads/2026/evil.jpg'],
        ];
    }

    #[DataProvider('traversalProvider')]
    public function testAPathInTheFilenameIsRefused(string $name): void
    {
        $path = $this->write('real.jpg', $this->jpeg());

        self::assertSame(
            [400, 'invalid_upload'],
            $this->refusal(fn () => $this->policy()->accept($name, (int) filesize($path), $path))
        );
    }

    public function testANullByteInTheFilenameIsRefused(): void
    {
        $path = $this->write('real.jpg', $this->jpeg());

        self::assertSame(
            [400, 'invalid_upload'],
            $this->refusal(fn () => $this->policy()->accept("evil.php\0.jpg", (int) filesize($path), $path))
        );
    }

    public function testAnOversizedFileIsRefusedBeforeAnythingReadsIt(): void
    {
        // A path that does not exist: if the size check did not run first, the
        // sniff would fail with a different error and this would still "pass"
        // for the wrong reason.
        self::assertSame(
            [413, 'file_too_large'],
            $this->refusal(fn () => $this->policy(1024)->accept('big.jpg', 4096, $this->dir . '/nothing-here.jpg'))
        );
    }

    public function testAnEmptyFileIsRefused(): void
    {
        $path = $this->write('empty.jpg', '');

        self::assertSame(
            [400, 'invalid_upload'],
            $this->refusal(fn () => $this->policy()->accept('empty.jpg', 0, $path))
        );
    }

    // ------------------------------------------------------- the type allowlist --

    /** @return array<string, array{0: string}> */
    public static function refusedExtensionProvider(): array
    {
        return [
            'svg executes in the viewer origin' => ['drawing.svg'],
            'pdf has its own scripting engine' => ['catalogue.pdf'],
            'gif cannot survive the strip' => ['animation.gif'],
            'avif is not re-encodable everywhere' => ['photo.avif'],
            'no extension at all' => ['photo'],
            'dotfile' => ['.htaccess'],
        ];
    }

    #[DataProvider('refusedExtensionProvider')]
    public function testOnlyThreeExtensionsAreAccepted(string $name): void
    {
        $path = $this->write('real.jpg', $this->jpeg());

        [$status] = $this->refusal(fn () => $this->policy()->accept($name, (int) filesize($path), $path));

        self::assertContains($status, [400, 415], "{$name} must be refused");
    }

    /**
     * A real PNG called `.jpg` is refused rather than renamed.
     *
     * Almost always a mistake — and "almost always" is not a basis on which to
     * guess, at the one endpoint that writes files.
     */
    public function testAnExtensionThatDisagreesWithTheContentsIsRefused(): void
    {
        $path = $this->write('mislabelled.jpg', $this->png());

        self::assertSame(
            [415, 'unsupported_media_type'],
            $this->refusal(fn () => $this->policy()->accept('mislabelled.jpg', (int) filesize($path), $path))
        );
    }

    /**
     * PNG magic bytes with nothing decodable behind them.
     *
     * `finfo` is satisfied by the eight-byte signature; `getimagesize()` is
     * not. This is the case that justifies running both.
     */
    public function testAFileWithOnlyMagicBytesIsRefused(): void
    {
        $path = $this->write('fake.png', "\x89PNG\r\n\x1a\n" . str_repeat("\0", 400));

        self::assertSame(
            [415, 'unsupported_media_type'],
            $this->refusal(fn () => $this->policy()->accept('fake.png', (int) filesize($path), $path))
        );
    }

    // ---------------------------------------------------------- stored filenames --

    /** @return array<string, array{0: string, 1: string}> */
    public static function filenameProvider(): array
    {
        return [
            'kept readable' => ['Tapis Berbère.jpg', 'tapis-berb-re.jpg'],
            'uppercase folded' => ['PHOTO.JPG', 'photo.jpg'],
            'interior dots collapse' => ['my.holiday.photo.jpg', 'my-holiday-photo.jpg'],
            'spaces and symbols' => ['a  b__c!!.jpg', 'a-b-c.jpg'],
            'nothing latin left' => ['صورة.jpg', 'image.jpg'],
            'leading and trailing junk' => ['---x---.jpg', 'x.jpg'],
        ];
    }

    /**
     * The extension comes from the sniffed type, never from the name — so even
     * if `assertFilename()` were one day loosened, a double extension could not
     * reach the disk through this function.
     */
    #[DataProvider('filenameProvider')]
    public function testTheStoredNameIsRewritten(string $client, string $expected): void
    {
        self::assertSame($expected, $this->policy()->storedFilename($client, 'image/jpeg'));
    }

    public function testAVeryLongNameIsTruncated(): void
    {
        $stored = $this->policy()->storedFilename(str_repeat('a', 300) . '.jpg', 'image/png');

        self::assertSame(UploadPolicy::MAX_STEM_LENGTH + 4, strlen($stored));
        self::assertStringEndsWith('.png', $stored);
    }

    // ------------------------------------------------------------------- the cap --

    public function testTheCapNeverExceedsWhatPhpWillAccept(): void
    {
        // A generous setting on a host that accepts 2 MB is a 2 MB cap.
        self::assertSame(2097152, UploadPolicy::withCap('52428800', 2097152)->maxBytes());
        // And a small setting is not widened by a generous host.
        self::assertSame(1048576, UploadPolicy::withCap('1048576', 16777216)->maxBytes());
    }

    /** @return array<string, array{0: string|null}> */
    public static function badCapProvider(): array
    {
        return [
            'unset' => [null],
            'not a number' => ['eight megabytes'],
            'negative' => ['-1'],
            'absurdly small' => ['3'],
        ];
    }

    /** A mistyped cap falls back to the default rather than to zero. */
    #[DataProvider('badCapProvider')]
    public function testAnUnusableCapFallsBackToTheDefault(?string $configured): void
    {
        self::assertSame(
            UploadPolicy::DEFAULT_MAX_BYTES,
            UploadPolicy::withCap($configured, null)->maxBytes()
        );
    }

    /**
     * WordPress is handed an allowlist generated from ours, so
     * `wp_handle_upload()`'s own check and this class cannot drift apart.
     */
    public function testTheWordPressAllowlistIsDerivedFromOurs(): void
    {
        self::assertSame(
            ['jpg|jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'],
            UploadPolicy::wordPressMimes()
        );
    }
}
