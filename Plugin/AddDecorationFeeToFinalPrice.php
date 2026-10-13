<?php
/**
 * InkThread Decoration Pricing
 */
declare(strict_types=1);

namespace InkThread\DecorationPricing\Plugin;

use InkThread\DecorationPricing\Exception\PricingNotConfiguredException;
use InkThread\DecorationPricing\Model\FeeCalculator;
use InkThread\DecorationPricing\Model\Quote\ItemFeeProvider;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Type\Price;
use Magento\Quote\Model\Quote\Item\AbstractItem;

/**
 * Adds the per-unit decoration fee to the final price of a decorated cart line, like core custom options do.
 *
 * Applies to subclasses too (configurable). A product without the decoration custom option is untouched, so catalog
 * and listing prices never change.
 */
class AddDecorationFeeToFinalPrice
{
    /**
     * @param ItemFeeProvider $itemFeeProvider
     * @param FeeCalculator $feeCalculator
     */
    public function __construct(
        private readonly ItemFeeProvider $itemFeeProvider,
        private readonly FeeCalculator $feeCalculator
    ) {
    }

    /**
     * Add the unit fee to the final price.
     *
     * @param Price $subject
     * @param float|int|string $result
     * @param float|int|string|null $qty
     * @param Product $product
     * @return float
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function afterGetFinalPrice(Price $subject, $result, $qty, $product): float
    {
        $finalPrice = (float)$result;
        $option = $product->getCustomOption(ItemFeeProvider::OPTION_CODE);
        if (!$option) {
            return $finalPrice;
        }

        $unitFee = $this->getUnitFee($option, $qty);
        if ($unitFee > 0) {
            $finalPrice += $unitFee;
            $product->setFinalPrice($finalPrice);
        }

        return $finalPrice;
    }

    /**
     * Unit fee from the quote item that owns the option (pooled tier), else from the option alone (line qty tier).
     *
     * @param object $option
     * @param float|int|string|null $qty
     * @return float
     */
    private function getUnitFee(object $option, $qty): float
    {
        $item = method_exists($option, 'getItem') ? $option->getItem() : null;
        if ($item instanceof AbstractItem) {
            return $this->itemFeeProvider->getFee($item)?->getUnitFee() ?? 0.0;
        }

        $selection = $this->itemFeeProvider->decode((string)$option->getValue());
        if ($selection === null || (float)$qty <= 0) {
            return 0.0;
        }
        try {
            return $this->feeCalculator->calculate($selection, (float)$qty)->getUnitFee();
        } catch (PricingNotConfiguredException $e) {
            return 0.0;
        }
    }
}
