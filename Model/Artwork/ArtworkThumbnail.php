<?php
/**
 * InkThread Decoration Pricing
 */
declare(strict_types=1);

namespace InkThread\DecorationPricing\Model\Artwork;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\WriteInterface;
use Magento\Framework\Image\Adapter\AbstractAdapter;
use Magento\Framework\Image\Adapter\AdapterInterface;
use Magento\Framework\Image\AdapterFactory;

/**
 * Small previews of PNG/JPEG artwork for the admin order view.
 *
 * The preview is decoded and re-encoded by the GD2 image adapter (same format as the upload), so the uploaded bytes
 * are never sent to the browser as an image. PDFs, and anything that fails to decode, have no preview.
 * Previews are cached under var/ next to the artwork.
 */
class ArtworkThumbnail
{
    public const MAX_EDGE = 160;

    /**
     * Images larger than this are not decoded (a 10 MB file can expand to gigabytes of pixels).
     */
    public const MAX_PIXELS = 40_000_000;

    private const string CACHE_PATH = 'inkthread_decoration/artwork_thumbnails';

    private const array PREVIEW_TYPES = ['image/png', 'image/jpeg'];

    /**
     * @param Filesystem $filesystem
     * @param ArtworkStorage $artworkStorage
     * @param AdapterFactory $imageAdapterFactory
     */
    public function __construct(
        private readonly Filesystem $filesystem,
        private readonly ArtworkStorage $artworkStorage,
        private readonly AdapterFactory $imageAdapterFactory
    ) {
    }

    /**
     * Whether a preview can be attempted for this file type.
     *
     * @param ArtworkFile $artworkFile
     * @return bool
     */
    public function supports(ArtworkFile $artworkFile): bool
    {
        return in_array($artworkFile->getMimeType(), self::PREVIEW_TYPES, true);
    }

    /**
     * Preview bytes (same image type as the artwork), from the cache or freshly rendered; null when there is none.
     *
     * @param ArtworkFile $artworkFile
     * @return string|null
     */
    public function get(ArtworkFile $artworkFile): ?string
    {
        if (!$this->supports($artworkFile) || !$this->artworkStorage->exists($artworkFile)) {
            return null;
        }

        $directory = $this->getDirectory();
        $path = $artworkFile->getPath();
        $cachePath = self::CACHE_PATH . '/' . substr($path, (int)strrpos($path, '/') + 1);
        if ($directory->isFile($cachePath)) {
            return $directory->readFile($cachePath);
        }

        $content = $this->render($directory->getAbsolutePath($artworkFile->getPath()));
        if ($content !== null) {
            $directory->writeFile($cachePath, $content);
        }

        return $content;
    }

    /**
     * Decode a PNG/JPEG file and re-encode it to fit MAX_EDGE x MAX_EDGE (never scaled up), keeping transparency.
     *
     * @param string $absolutePath
     * @return string|null
     */
    public function render(string $absolutePath): ?string
    {
        try {
            // Header-only size check, before anything is decoded.
            // phpcs:ignore Magento2.Functions.DiscouragedFunction
            $info = getimagesize($absolutePath);
            if ($info === false || $info[0] < 1 || $info[1] < 1 || $info[0] * $info[1] > self::MAX_PIXELS) {
                return null;
            }

            $image = $this->imageAdapterFactory->create(AdapterInterface::ADAPTER_GD2);
            if (!$image instanceof AbstractAdapter) {
                return null;
            }
            $image->open($absolutePath);
            $image->keepAspectRatio(true);
            $image->keepTransparency(true);
            $image->keepFrame(false);
            $image->constrainOnly(true);
            $image->resize(self::MAX_EDGE, self::MAX_EDGE);
            $content = $image->getImage();
        } catch (\Throwable) {
            return null;
        }

        return is_string($content) && $content !== '' ? $content : null;
    }

    /**
     * Writable var/ directory.
     *
     * @return WriteInterface
     */
    private function getDirectory(): WriteInterface
    {
        return $this->filesystem->getDirectoryWrite(DirectoryList::VAR_DIR);
    }
}
