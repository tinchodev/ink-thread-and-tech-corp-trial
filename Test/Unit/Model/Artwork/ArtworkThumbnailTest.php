<?php
/**
 * InkThread Decoration Pricing
 */
declare(strict_types=1);

namespace InkThread\DecorationPricing\Test\Unit\Model\Artwork;

use InkThread\DecorationPricing\Model\Artwork\ArtworkFile;
use InkThread\DecorationPricing\Model\Artwork\ArtworkStorage;
use InkThread\DecorationPricing\Model\Artwork\ArtworkThumbnail;
use Magento\Framework\Filesystem;
use Magento\Framework\Image\Adapter\AdapterInterface;
use Magento\Framework\Image\Adapter\Gd2;
use Magento\Framework\Image\AdapterFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @requires extension gd
 */
class ArtworkThumbnailTest extends TestCase
{
    /**
     * @var ArtworkThumbnail
     */
    private ArtworkThumbnail $artworkThumbnail;

    /**
     * @var string[]
     */
    private array $files = [];

    #[\Override]
    protected function setUp(): void
    {
        $filesystem = $this->createMock(Filesystem::class);
        $imageAdapterFactory = $this->createMock(AdapterFactory::class);
        $imageAdapterFactory->method('create')->with(AdapterInterface::ADAPTER_GD2)
            ->willReturnCallback(fn () => new Gd2($filesystem, $this->createMock(LoggerInterface::class)));

        $this->artworkThumbnail = new ArtworkThumbnail(
            $filesystem,
            $this->createMock(ArtworkStorage::class),
            $imageAdapterFactory
        );
    }

    #[\Override]
    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            // phpcs:ignore Magento2.Functions.DiscouragedFunction
            unlink($file);
        }
    }

    public function testLargePngIsScaledDownKeepingAspectRatio(): void
    {
        $png = $this->artworkThumbnail->render($this->image(800, 400, 'png'));

        $this->assertNotNull($png);
        $this->assertSame([160, 80, IMAGETYPE_PNG], array_slice((array)getimagesizefromstring($png), 0, 3));
    }

    public function testSmallImageIsNotScaledUp(): void
    {
        $png = $this->artworkThumbnail->render($this->image(40, 30, 'png'));

        $this->assertNotNull($png);
        $this->assertSame([40, 30], array_slice((array)getimagesizefromstring($png), 0, 2));
    }

    public function testJpegIsReEncodedAsJpeg(): void
    {
        $source = $this->image(300, 600, 'jpeg');
        $jpeg = $this->artworkThumbnail->render($source);

        $this->assertNotNull($jpeg);
        $this->assertSame([80, 160, IMAGETYPE_JPEG], array_slice((array)getimagesizefromstring($jpeg), 0, 3));
        // phpcs:ignore Magento2.Functions.DiscouragedFunction
        $this->assertNotSame(file_get_contents($source), $jpeg);
    }

    public function testTransparencyIsKept(): void
    {
        $image = imagecreatetruecolor(10, 10);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, (int)imagecolorallocatealpha($image, 255, 0, 0, 127));
        $path = $this->tempFile('');
        imagepng($image, $path);

        $png = (string)$this->artworkThumbnail->render($path);
        $thumbnail = imagecreatefromstring($png);

        $this->assertNotFalse($thumbnail);
        $this->assertSame(127, (imagecolorat($thumbnail, 5, 5) >> 24) & 0x7F);
    }

    /**
     * @dataProvider noPreviewProvider
     */
    public function testNoPreviewForNonImages(string $content): void
    {
        $this->assertNull($this->artworkThumbnail->render($this->tempFile($content)));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function noPreviewProvider(): array
    {
        return [
            'pdf' => ["%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n"],
            'html' => ['<html><script>alert(1)</script></html>'],
            'truncated png' => ["\x89PNG\r\n\x1a\n" . str_repeat("\0", 20)],
            'empty' => [''],
        ];
    }

    public function testHugeDimensionsAreRefusedBeforeDecoding(): void
    {
        // Valid PNG header claiming 20000 x 20000 pixels (400 MP), with no image data behind it.
        $ihdr = 'IHDR' . pack('NNCCCCC', 20000, 20000, 8, 6, 0, 0, 0);
        $header = "\x89PNG\r\n\x1a\n" . pack('N', 13) . $ihdr . pack('N', crc32($ihdr));

        $this->assertSame([20000, 20000], array_slice((array)getimagesizefromstring($header), 0, 2));
        $this->assertNull($this->artworkThumbnail->render($this->tempFile($header)));
    }

    /**
     * @dataProvider supportsProvider
     */
    public function testSupportsOnlyPngAndJpeg(string $mimeType, bool $expected): void
    {
        $file = new ArtworkFile('inkthread_decoration/artwork/a.bin', 'a', 1, $mimeType);

        $this->assertSame($expected, $this->artworkThumbnail->supports($file));
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function supportsProvider(): array
    {
        return [
            'png' => ['image/png', true],
            'jpeg' => ['image/jpeg', true],
            'pdf' => ['application/pdf', false],
            'svg' => ['image/svg+xml', false],
        ];
    }

    /**
     * Write a solid-colour test image.
     *
     * @param int $width
     * @param int $height
     * @param string $type png|jpeg
     * @return string path
     */
    private function image(int $width, int $height, string $type): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, (int)imagecolorallocate($image, 20, 120, 200));
        $path = $this->tempFile('');
        $type === 'png' ? imagepng($image, $path) : imagejpeg($image, $path);

        return $path;
    }

    /**
     * @param string $content
     * @return string path
     */
    private function tempFile(string $content): string
    {
        $path = (string)tempnam(sys_get_temp_dir(), 'thumb');
        // phpcs:ignore Magento2.Functions.DiscouragedFunction
        file_put_contents($path, $content);
        $this->files[] = $path;

        return $path;
    }
}
