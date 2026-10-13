<?php
/**
 * InkThread Decoration Pricing
 */
declare(strict_types=1);

namespace InkThread\DecorationPricing\Model\Artwork;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\File\UploaderFactory;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\WriteInterface;
use Magento\Framework\Math\Random;

/**
 * Stores uploaded artwork under var/ (outside the web root, never served directly) with a random name.
 */
class ArtworkStorage
{
    public const BASE_PATH = 'inkthread_decoration/artwork';

    /**
     * @param UploaderFactory $uploaderFactory
     * @param Filesystem $filesystem
     * @param ArtworkValidator $artworkValidator
     * @param Random $random
     */
    public function __construct(
        private readonly UploaderFactory $uploaderFactory,
        private readonly Filesystem $filesystem,
        private readonly ArtworkValidator $artworkValidator,
        private readonly Random $random
    ) {
    }

    /**
     * Validate and store an uploaded file.
     *
     * @param string $fileId $_FILES key
     * @param array<string,mixed> $fileInfo $_FILES entry (name, tmp_name, size, error)
     * @return ArtworkFile
     * @throws LocalizedException
     */
    public function saveUpload(string $fileId, array $fileInfo): ArtworkFile
    {
        $name = (string)($fileInfo['name'] ?? '');
        $mimeType = $this->artworkValidator->validate(
            $name,
            (string)($fileInfo['tmp_name'] ?? ''),
            (int)($fileInfo['size'] ?? 0),
            (int)($fileInfo['error'] ?? UPLOAD_ERR_NO_FILE)
        );
        $extension = $this->artworkValidator->getExtension($name);
        $fileName = $this->random->getUniqueHash() . '.' . $extension;

        try {
            $uploader = $this->uploaderFactory->create(['fileId' => $fileId]);
            $uploader->setAllowedExtensions(array_keys(ArtworkValidator::ALLOWED_TYPES));
            $uploader->setAllowRenameFiles(false);
            $uploader->setFilesDispersion(false);
            $uploader->setAllowCreateFolders(true);
            $result = $uploader->save($this->getDirectory()->getAbsolutePath(self::BASE_PATH), $fileName);
        } catch (\Exception $exception) {
            throw new LocalizedException(__('The artwork file could not be saved. Please try again.'), $exception);
        }

        if (!is_array($result) || empty($result['file'])) {
            throw new LocalizedException(__('The artwork file could not be saved. Please try again.'));
        }

        return new ArtworkFile(
            self::BASE_PATH . '/' . $result['file'],
            $this->artworkValidator->sanitizeName($name),
            (int)($fileInfo['size'] ?? 0),
            $mimeType
        );
    }

    /**
     * Whether a stored file still exists.
     *
     * @param ArtworkFile $artworkFile
     * @return bool
     */
    public function exists(ArtworkFile $artworkFile): bool
    {
        return $this->getDirectory()->isFile($artworkFile->getPath());
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
