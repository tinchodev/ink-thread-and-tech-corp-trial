<?php
/**
 * InkThread Decoration Pricing
 */
declare(strict_types=1);

namespace InkThread\DecorationPricing\Api;

/**
 * Source of per-unit decoration prices by method, location and quantity tier.
 *
 * @api
 */
interface PriceTableProviderInterface
{
    /**
     * Tiers for one method/location, sorted by min_qty ascending. Empty when the location is not offered.
     *
     * @param string $method
     * @param string $location
     * @return list<array{min_qty: int, price: float}>
     */
    public function getTiers(string $method, string $location): array;
}
