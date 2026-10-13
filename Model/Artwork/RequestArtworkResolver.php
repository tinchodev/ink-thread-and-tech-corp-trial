<?php
/**
 * InkThread Decoration Pricing
 */
declare(strict_types=1);

namespace InkThread\DecorationPricing\Model\Artwork;

use InkThread\DecorationPricing\Model\Selection;
use InkThread\DecorationPricing\Model\Source\DecorationMethod;
use InkThread\DecorationPricing\Observer\CaptureDecorationSelection;
use Laminas\Stdlib\ParametersInterface;
use Magento\Framework\App\Request\Http;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\DataObject;
use Magento\Framework\Exception\LocalizedException;

/**
 * Works out the artwork of each selected location for one buy request:
 * - a new upload (file input inkthread_artwork_<location>) replaces anything else;
 * - otherwise an existing file is kept via its encrypted token (cart edit, reorder).
 * Only methods that take artwork (screen print) get any; uploads for other methods are ignored.
 *
 * The result is written back into the buy request as tokens, so core saves it in info_buyRequest for later edits
 * and reorders. Each buy request is resolved once, because an uploaded temp file can only be moved once.
 */
class RequestArtworkResolver
{
    public const FILE_FIELD_PREFIX = 'inkthread_artwork_';

    /**
     * @var \WeakMap<DataObject, array<string,ArtworkFile>>
     */
    private \WeakMap $weakMap;

    /**
     * @param RequestInterface $request
     * @param ArtworkStorage $artworkStorage
     * @param ArtworkToken $artworkToken
     */
    public function __construct(
        private readonly RequestInterface $request,
        private readonly ArtworkStorage $artworkStorage,
        private readonly ArtworkToken $artworkToken
    ) {
        $this->weakMap = new \WeakMap();
    }

    /**
     * Resolve artwork for the selection's locations.
     *
     * @param DataObject $dataObject
     * @param Selection $selection
     * @return array<string,ArtworkFile>
     * @throws LocalizedException
     */
    public function resolve(DataObject $dataObject, Selection $selection): array
    {
        if (isset($this->weakMap[$dataObject])) {
            return $this->weakMap[$dataObject];
        }

        $data = $dataObject->getData(CaptureDecorationSelection::REQUEST_KEY);
        $data = is_array($data) ? $data : [];

        $tokens = is_array($data['artwork_tokens'] ?? null) ? $data['artwork_tokens'] : [];

        $artwork = [];
        $locations = in_array($selection->getMethod(), DecorationMethod::ARTWORK_METHODS, true)
            ? array_unique($selection->getLocations())
            : [];
        foreach ($locations as $location) {
            $upload = $this->getUpload($location);
            if ($upload !== null) {
                $artwork[$location] = $this->artworkStorage->saveUpload(self::FILE_FIELD_PREFIX . $location, $upload);
                continue;
            }

            $file = $this->artworkToken->decode($tokens[$location] ?? null);
            if ($file instanceof ArtworkFile && $this->artworkStorage->exists($file)) {
                $artwork[$location] = $file;
            }
        }

        $data['artwork_tokens'] = array_map($this->artworkToken->encode(...), $artwork);
        $dataObject->setData(CaptureDecorationSelection::REQUEST_KEY, $data);

        return $this->weakMap[$dataObject] = $artwork;
    }

    /**
     * Uploaded file info for a location, or null when nothing was uploaded.
     *
     * @param string $location
     * @return array<string,mixed>|null
     */
    private function getUpload(string $location): ?array
    {
        if (!$this->request instanceof Http) {
            return null;
        }

        $file = $this->request->getFiles(self::FILE_FIELD_PREFIX . $location);
        if ($file instanceof ParametersInterface) {
            $file = $file->toArray();
        }

        if (!is_array($file) || (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return null;
        }

        return $file;
    }
}
