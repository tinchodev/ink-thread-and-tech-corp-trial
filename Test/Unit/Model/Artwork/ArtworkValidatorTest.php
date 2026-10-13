<?php
/**
 * InkThread Decoration Pricing
 */
declare(strict_types=1);

namespace InkThread\DecorationPricing\Test\Unit\Model\Artwork;

use InkThread\DecorationPricing\Model\Artwork\ArtworkValidator;
use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\TestCase;

class ArtworkValidatorTest extends TestCase
{
    /**
     * Smallest valid PNG (1x1 transparent).
     */
    private const string PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8'
        . 'AAAAASUVORK5CYII=';

    /**
     * @var ArtworkValidator
     */
    private ArtworkValidator $artworkValidator;

    /**
     * @var string[]
     */
    private array $files = [];

    #[\Override]
    protected function setUp(): void
    {
        $this->artworkValidator = new ArtworkValidator();
    }

    #[\Override]
    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            // phpcs:ignore Magento2.Functions.DiscouragedFunction
            unlink($file);
        }
    }

    /**
     * @dataProvider validFileProvider
     */
    public function testAcceptsMatchingContent(string $name, string $content, string $expectedMime): void
    {
        $path = $this->tempFile($content);

        $this->assertSame($expectedMime, $this->artworkValidator->validate($name, $path, strlen($content)));
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function validFileProvider(): array
    {
        // phpcs:ignore Magento2.Functions.DiscouragedFunction
        $png = base64_decode(self::PNG);
        $jpeg = "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00\xFF\xD9";
        $pdf = "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n";

        return [
            'png' => ['logo.png', $png, 'image/png'],
            'jpg upper-case extension' => ['LOGO.JPG', $jpeg, 'image/jpeg'],
            'jpeg' => ['logo.jpeg', $jpeg, 'image/jpeg'],
            'pdf' => ['artwork.pdf', $pdf, 'application/pdf'],
        ];
    }

    /**
     * @dataProvider invalidFileProvider
     */
    public function testRejectsInvalidFiles(string $name, string $content, int $size, int $error, string $message): void
    {
        $path = $this->tempFile($content);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage($message);

        $this->artworkValidator->validate($name, $path, $size, $error);
    }

    /**
     * @return array<string, array{string, string, int, int, string}>
     */
    public static function invalidFileProvider(): array
    {
        // phpcs:ignore Magento2.Functions.DiscouragedFunction
        $png = base64_decode(self::PNG);
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><script>x()</script></svg>';

        return [
            'svg' => ['logo.svg', $svg, strlen($svg), UPLOAD_ERR_OK, 'Artwork must be a PNG, JPG or PDF file.'],
            'php renamed' => ['shell.php', '<?php echo 1;', 13, UPLOAD_ERR_OK,
                'Artwork must be a PNG, JPG or PDF file.'],
            'html as png' => ['logo.png', '<html><body>x</body></html>', 27, UPLOAD_ERR_OK,
                'The artwork file content does not match its .png extension.'],
            'png as pdf' => ['logo.pdf', $png, strlen($png), UPLOAD_ERR_OK,
                'The artwork file content does not match its .pdf extension.'],
            'over 10 MB' => ['big.png', $png, ArtworkValidator::MAX_SIZE + 1, UPLOAD_ERR_OK,
                'Artwork files can be at most 10 MB.'],
            'php ini size' => ['big.png', $png, 0, UPLOAD_ERR_INI_SIZE, 'Artwork files can be at most 10 MB.'],
            'partial upload' => ['logo.png', $png, strlen($png), UPLOAD_ERR_PARTIAL,
                'The artwork file could not be uploaded.'],
            'empty file' => ['logo.png', '', 0, UPLOAD_ERR_OK, 'The artwork file could not be uploaded.'],
        ];
    }

    /**
     * @dataProvider nameProvider
     */
    public function testSanitizesDisplayName(string $name, string $expected): void
    {
        $this->assertSame($expected, $this->artworkValidator->sanitizeName($name));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function nameProvider(): array
    {
        return [
            'plain' => ['team logo_v2.png', 'team logo_v2.png'],
            'path stripped' => ['../../etc/passwd.png', 'passwd.png'],
            'windows path' => ['C:\\Users\\me\\logo.png', 'logo.png'],
            'markup replaced' => ['<img onerror=x>.png', '_img onerror_x_.png'],
            'nothing left' => ['...', 'artwork'],
        ];
    }

    /**
     * @param string $content
     * @return string
     */
    private function tempFile(string $content): string
    {
        // phpcs:ignore Magento2.Functions.DiscouragedFunction
        $path = tempnam(sys_get_temp_dir(), 'artwork');
        // phpcs:ignore Magento2.Functions.DiscouragedFunction
        file_put_contents($path, $content);
        $this->files[] = $path;

        return $path;
    }
}
