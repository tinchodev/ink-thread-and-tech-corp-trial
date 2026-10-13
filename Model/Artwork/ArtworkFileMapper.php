<?php
/**
 * InkThread Decoration Pricing
 */
declare(strict_types=1);

namespace InkThread\DecorationPricing\Model\Artwork;

/**
 * Rebuilds ArtworkFile objects from trusted (server-written) data.
 */
class ArtworkFileMapper
{
    /**
     * Artwork from stored data; null when it is not a valid reference inside the artwork storage.
     *
     * @param mixed $data
     * @return ArtworkFile|null
     */
    public function fromArray(mixed $data): ?ArtworkFile
    {
        if (!is_array($data) || !is_string($data['path'] ?? null) || !is_string($data['name'] ?? null)) {
            return null;
        }

        $path = $data['path'];
        if (!str_starts_with($path, ArtworkStorage::BASE_PATH . '/') || str_contains($path, '..')) {
            return null;
        }

        return new ArtworkFile(
            $path,
            $data['name'],
            (int)($data['size'] ?? 0),
            (string)($data['mime_type'] ?? 'application/octet-stream')
        );
    }
}
