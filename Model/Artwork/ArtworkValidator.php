<?php
/**
 * InkThread Decoration Pricing
 */
declare(strict_types=1);

namespace InkThread\DecorationPricing\Model\Artwork;

use Magento\Framework\Exception\LocalizedException;

/**
 * Rules for uploaded artwork: PNG, JPG or PDF, at most 10 MB, and the content must match the extension.
 */
class ArtworkValidator
{
    public const MAX_SIZE = 10 * 1024 * 1024;

    /**
     * Extension => accepted MIME types (detected from content, not from the browser).
     */
    public const ALLOWED_TYPES = [
        'png' => ['image/png'],
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'pdf' => ['application/pdf'],
    ];

    /**
     * Validate an uploaded file's metadata and content.
     *
     * @param string $originalName file name sent by the browser
     * @param string $tmpPath path of the uploaded temp file
     * @param int $size bytes
     * @param int $uploadError PHP UPLOAD_ERR_* code
     * @return string detected MIME type
     * @throws LocalizedException
     */
    public function validate(
        string $originalName,
        string $tmpPath,
        int $size,
        int $uploadError = UPLOAD_ERR_OK
    ): string {
        if ($uploadError === UPLOAD_ERR_INI_SIZE || $uploadError === UPLOAD_ERR_FORM_SIZE || $size > self::MAX_SIZE) {
            throw new LocalizedException(__('Artwork files can be at most %1 MB.', self::MAX_SIZE / 1024 / 1024));
        }
        if ($uploadError !== UPLOAD_ERR_OK || $size <= 0) {
            throw new LocalizedException(__('The artwork file could not be uploaded. Please try again.'));
        }

        $extension = $this->getExtension($originalName);
        if (!isset(self::ALLOWED_TYPES[$extension])) {
            throw new LocalizedException(__('Artwork must be a PNG, JPG or PDF file.'));
        }

        $mimeType = (string)(new \finfo(FILEINFO_MIME_TYPE))->file($tmpPath);
        if (!in_array($mimeType, self::ALLOWED_TYPES[$extension], true)) {
            throw new LocalizedException(__('The artwork file content does not match its .%1 extension.', $extension));
        }

        return $mimeType;
    }

    /**
     * Lower-case extension of a file name.
     *
     * @param string $name
     * @return string
     */
    public function getExtension(string $name): string
    {
        $dot = strrpos($name, '.');

        return $dot === false ? '' : strtolower(substr($name, $dot + 1));
    }

    /**
     * Display-safe version of the original file name: no path, only letters, digits, space, dot, dash, underscore.
     *
     * @param string $name
     * @return string
     */
    public function sanitizeName(string $name): string
    {
        $normalised = str_replace('\\', '/', $name);
        $slash = strrpos($normalised, '/');
        $base = $slash === false ? $normalised : substr($normalised, $slash + 1);
        $clean = trim((string)preg_replace('/[^A-Za-z0-9 ._-]+/', '_', $base), ' .');

        return mb_substr($clean !== '' ? $clean : 'artwork', 0, 100);
    }
}
