<?php
/**
 * InkThread Decoration Pricing
 */
declare(strict_types=1);

namespace InkThread\DecorationPricing\Model;

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

        return new Selection($method, $locations, $note);
    }
}
