<?php
/**
 * InkThread Decoration Pricing
 */
declare(strict_types=1);

namespace InkThread\DecorationPricing\Model;

use InkThread\DecorationPricing\Model\Artwork\ArtworkFileMapper;
use InkThread\DecorationPricing\Model\Source\DecorationMethod;

/**
 * Builds a Selection from untrusted input (buy request) or from stored data.
 *
 * Normalises shape only (types, whitespace, method None drops locations and note). Business rules are checked by
 * SelectionValidator, so e.g. duplicate locations are kept here and rejected there.
 */
class SelectionFactory
{
    /**
     * @param ArtworkFileMapper $artworkFileMapper
     */
    public function __construct(
        private readonly ArtworkFileMapper $artworkFileMapper
    ) {
    }

    /**
     * Create from the `inkthread_decoration` buy request value. Returns null when no decoration data was posted.
     *
     * @param mixed $data
     * @return Selection|null
     */
    public function createFromRequestData(mixed $data): ?Selection
    {
        if (!is_array($data)) {
            return null;
        }

        $method = is_scalar($data['method'] ?? null) ? trim((string)$data['method']) : '';
        if ($method === '' || $method === DecorationMethod::NONE) {
            return new Selection(DecorationMethod::NONE, [], '');
        }

        $rawLocations = $data['locations'] ?? [];
        if (!is_array($rawLocations)) {
            $rawLocations = [$rawLocations];
        }
        $locations = [];
        foreach ($rawLocations as $location) {
            if (is_scalar($location) && trim((string)$location) !== '') {
                $locations[] = trim((string)$location);
            }
        }

        $note = is_scalar($data['note'] ?? null) ? trim((string)$data['note']) : '';

        $texts = [];
        $rawTexts = is_array($data['location_texts'] ?? null) ? $data['location_texts'] : [];
        foreach (array_unique($locations) as $location) {
            $text = $rawTexts[$location] ?? null;
            if (is_scalar($text) && trim((string)$text) !== '') {
                $texts[$location] = trim((string)$text);
            }
        }

        return new Selection($method, $locations, $note, $texts);
    }

    /**
     * Create from data the server stored itself (quote item option, order snapshot), including artwork references.
     *
     * Never use this for request data: artwork paths would then be client-controlled.
     *
     * @param mixed $data
     * @return Selection|null
     */
    public function createFromStored(mixed $data): ?Selection
    {
        $selection = $this->createFromRequestData($data);
        if ($selection === null || !is_array($data) || !is_array($data['artwork'] ?? null)) {
            return $selection;
        }

        $artwork = [];
        foreach ($data['artwork'] as $location => $file) {
            $file = $this->artworkFileMapper->fromArray($file);
            if ($file !== null) {
                $artwork[(string)$location] = $file;
            }
        }

        return $selection->withArtwork($artwork);
    }
}
