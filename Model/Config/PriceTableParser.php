<?php
/**
 * InkThread Decoration Pricing
 */
declare(strict_types=1);

namespace InkThread\DecorationPricing\Model\Config;

use InkThread\DecorationPricing\Exception\InvalidPriceTableException;
use InkThread\DecorationPricing\Model\Source\PrintLocation;

/**
 * Turns the admin dynamic-rows value into a normalised, validated price table.
 *
 * Input rows: ['location' => 'left_chest', 'min_qty' => '12', 'price' => '3.25'] (string values, as posted).
 * Output: location => list of tiers sorted by min_qty ascending, each ['min_qty' => int, 'price' => float].
 */
class PriceTableParser
{
    /**
     * @param PrintLocation $printLocation
     */
    public function __construct(
        private readonly PrintLocation $printLocation
    ) {
    }

    /**
     * Parse and validate rows.
     *
     * @param array<mixed> $rows
     * @return array<string, list<array{min_qty: int, price: float}>>
     * @throws InvalidPriceTableException
     */
    public function parse(array $rows): array
    {
        $table = [];
        $errors = [];
        $rowNumber = 0;

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $rowNumber++;
            $location = trim((string)($row['location'] ?? ''));
            $minQty = trim((string)($row['min_qty'] ?? ''));
            $price = trim((string)($row['price'] ?? ''));

            if ($location === '' && $minQty === '' && $price === '') {
                continue;
            }
            if (!$this->printLocation->isValid($location)) {
                $errors[] = __('Row %1: choose a valid print location.', $rowNumber);
                continue;
            }
            if (!ctype_digit($minQty) || (int)$minQty < 1) {
                $errors[] = __('Row %1: minimum quantity must be a whole number of 1 or more.', $rowNumber);
                continue;
            }
            if (!is_numeric($price) || (float)$price < 0) {
                $errors[] = __('Row %1: unit price must be a number of 0 or more.', $rowNumber);
                continue;
            }
            if (isset($table[$location][(int)$minQty])) {
                $errors[] = __(
                    'Row %1: %2 already has a price for minimum quantity %3.',
                    $rowNumber,
                    $this->printLocation->getLabel($location),
                    (int)$minQty
                );
                continue;
            }
            $table[$location][(int)$minQty] = (float)$price;
        }

        if ($errors) {
            throw new InvalidPriceTableException($errors);
        }

        $result = [];
        foreach ($table as $location => $tiers) {
            ksort($tiers);
            foreach ($tiers as $tierMinQty => $tierPrice) {
                $result[$location][] = ['min_qty' => $tierMinQty, 'price' => $tierPrice];
            }
        }

        return $result;
    }
}
