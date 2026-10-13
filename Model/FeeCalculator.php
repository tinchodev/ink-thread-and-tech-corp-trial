<?php
/**
 * InkThread Decoration Pricing
 */
declare(strict_types=1);

namespace InkThread\DecorationPricing\Model;

use InkThread\DecorationPricing\Api\PriceTableProviderInterface;
use InkThread\DecorationPricing\Exception\PricingNotConfiguredException;
use InkThread\DecorationPricing\Model\Source\DecorationMethod;
use InkThread\DecorationPricing\Model\Source\PrintLocation;

/**
 * Per-unit fee = sum of each selected location's price at the quantity tier. No setup fees.
 */
class FeeCalculator
{
    /**
     * @param PriceTableProviderInterface $priceTableProvider
     * @param DecorationMethod $decorationMethod
     * @param PrintLocation $printLocation
     */
    public function __construct(
        private readonly PriceTableProviderInterface $priceTableProvider,
        private readonly DecorationMethod $decorationMethod,
        private readonly PrintLocation $printLocation
    ) {
    }

    /**
     * Calculate the fee for a line.
     *
     * @param Selection $selection a validated selection
     * @param float $lineQty quantity of the line
     * @param float|null $tierQty quantity that picks the tier (pooled qty); defaults to the line qty
     * @return FeeResult
     * @throws PricingNotConfiguredException
     */
    public function calculate(Selection $selection, float $lineQty, ?float $tierQty = null): FeeResult
    {
        $tierQty ??= $lineQty;
        if ($lineQty <= 0 || $tierQty <= 0) {
            throw new \InvalidArgumentException('Decoration fee quantities must be greater than zero.');
        }
        if (!$selection->isDecorated()) {
            return new FeeResult($selection, [], 0.0, $lineQty, $tierQty, 0.0);
        }

        $locationPrices = [];
        foreach ($selection->getLocations() as $location) {
            $locationPrices[$location] = $this->getUnitPrice($selection->getMethod(), $location, $tierQty);
        }
        $unitFee = round(array_sum($locationPrices), 4);

        return new FeeResult(
            $selection,
            $locationPrices,
            $unitFee,
            $lineQty,
            $tierQty,
            round($unitFee * $lineQty, 2)
        );
    }

    /**
     * Check every selected location has a price table for the method.
     *
     * Quantity-independent: the tier is only known once the cart is totalled.
     *
     * @param Selection $selection
     * @return void
     * @throws PricingNotConfiguredException
     */
    public function assertOffered(Selection $selection): void
    {
        if (!$selection->isDecorated()) {
            return;
        }
        foreach ($selection->getLocations() as $location) {
            if (!$this->priceTableProvider->getTiers($selection->getMethod(), $location)) {
                throw $this->notOfferedException($selection->getMethod(), $location);
            }
        }
    }

    /**
     * Price of the highest tier whose min_qty does not exceed the quantity.
     *
     * @param string $method
     * @param string $location
     * @param float $qty
     * @return float
     * @throws PricingNotConfiguredException
     */
    private function getUnitPrice(string $method, string $location, float $qty): float
    {
        $tiers = $this->priceTableProvider->getTiers($method, $location);
        if (!$tiers) {
            throw $this->notOfferedException($method, $location);
        }

        $price = null;
        foreach ($tiers as $tier) {
            if ($tier['min_qty'] <= $qty) {
                $price = $tier['price'];
            }
        }
        if ($price === null) {
            throw new PricingNotConfiguredException(__(
                '%1 on %2 requires a minimum quantity of %3.',
                $this->decorationMethod->getLabel($method),
                $this->printLocation->getLabel($location),
                $tiers[0]['min_qty']
            ));
        }

        return $price;
    }

    /**
     * Exception for a location without a price table.
     *
     * @param string $method
     * @param string $location
     * @return PricingNotConfiguredException
     */
    private function notOfferedException(string $method, string $location): PricingNotConfiguredException
    {
        return new PricingNotConfiguredException(__(
            '%1 is not available for %2.',
            $this->printLocation->getLabel($location),
            $this->decorationMethod->getLabel($method)
        ));
    }
}
