<?php
/**
 * InkThread Decoration Pricing
 */
declare(strict_types=1);

namespace InkThread\DecorationPricing\Model;

use InkThread\DecorationPricing\Model\Artwork\ArtworkFile;
use InkThread\DecorationPricing\Model\Source\DecorationMethod;

/**
 * A customer's decoration choice for one cart line. Immutable; not validated by itself (see SelectionValidator).
 */
class Selection
{
    /**
     * @param string $method
     * @param string[] $locations
     * @param string $note
     * @param array<string,string> $locationTexts location code => text to decorate (selected locations only)
     * @param array<string,ArtworkFile> $artwork location code => stored artwork file (selected locations only)
     */
    public function __construct(
        private readonly string $method,
        private readonly array $locations,
        private readonly string $note,
        private readonly array $locationTexts = [],
        private readonly array $artwork = []
    ) {
    }

    /**
     * Text to decorate per location.
     *
     * @return array<string,string>
     */
    public function getLocationTexts(): array
    {
        return $this->locationTexts;
    }

    /**
     * Text for one location ('' when none).
     *
     * @param string $location
     * @return string
     */
    public function getLocationText(string $location): string
    {
        return $this->locationTexts[$location] ?? '';
    }

    /**
     * Artwork per location.
     *
     * @return array<string,ArtworkFile>
     */
    public function getArtwork(): array
    {
        return $this->artwork;
    }

    /**
     * Copy with the given artwork (kept only for selected locations).
     *
     * @param array<string,ArtworkFile> $artwork
     * @return self
     */
    public function withArtwork(array $artwork): self
    {
        return new self(
            $this->method,
            $this->locations,
            $this->note,
            $this->locationTexts,
            array_intersect_key($artwork, array_flip($this->locations))
        );
    }

    /**
     * Decoration method code.
     *
     * @return string
     */
    public function getMethod(): string
    {
        return $this->method;
    }

    /**
     * Print location codes, in the order the customer chose them.
     *
     * @return string[]
     */
    public function getLocations(): array
    {
        return $this->locations;
    }

    /**
     * Free-text note (raw; escape on output).
     *
     * @return string
     */
    public function getNote(): string
    {
        return $this->note;
    }

    /**
     * Whether a decoration (and therefore a fee) applies.
     *
     * @return bool
     */
    public function isDecorated(): bool
    {
        return $this->method !== DecorationMethod::NONE;
    }

    /**
     * Identity of the decoration for tier pooling: method + location set.
     *
     * Order-independent; note, per-location text and artwork do not split a pool.
     *
     * @return string
     */
    public function getPricingKey(): string
    {
        $locations = $this->locations;
        sort($locations);

        return $this->method . ':' . implode(',', $locations);
    }

    /**
     * Plain array form, used for storage on the quote item and the order snapshot.
     *
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'method' => $this->method,
            'locations' => $this->locations,
            'note' => $this->note,
            'location_texts' => $this->locationTexts,
            'artwork' => array_map(
                static fn (ArtworkFile $artworkFile): array => $artworkFile->toArray(),
                $this->artwork
            ),
        ];
    }
}
