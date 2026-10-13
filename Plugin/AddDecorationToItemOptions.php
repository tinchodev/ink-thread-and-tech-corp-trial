<?php
/**
 * InkThread Decoration Pricing
 */
declare(strict_types=1);

namespace InkThread\DecorationPricing\Plugin;

use InkThread\DecorationPricing\Model\BreakdownFormatter;
use InkThread\DecorationPricing\Model\Quote\ItemFeeProvider;
use InkThread\DecorationPricing\Model\Selection;
use Magento\Catalog\Helper\Product\Configuration;
use Magento\Catalog\Model\Product\Configuration\Item\ItemInterface;
use Magento\Quote\Model\Quote\Item\AbstractItem;

/**
 * Appends the decoration breakdown to an item's displayed options.
 *
 * The catalog configuration helper is what the cart, minicart, checkout summary and wishlist use (the configurable
 * helper delegates to it), so this one plugin covers all of them. The fee is computed from the current cart, so it
 * never goes stale when quantities change.
 */
class AddDecorationToItemOptions
{
    /**
     * @param ItemFeeProvider $itemFeeProvider
     * @param BreakdownFormatter $breakdownFormatter
     */
    public function __construct(
        private readonly ItemFeeProvider $itemFeeProvider,
        private readonly BreakdownFormatter $breakdownFormatter
    ) {
    }

    /**
     * Add breakdown lines after the item's own options.
     *
     * @param Configuration $configuration
     * @param array<int,array<string,mixed>> $result
     * @param ItemInterface $item
     * @return array<int,array<string,mixed>>
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function afterGetCustomOptions(Configuration $configuration, array $result, ItemInterface $item): array
    {
        $selection = $this->itemFeeProvider->getSelection($item);
        if (!$selection instanceof Selection) {
            return $result;
        }

        $fee = null;
        $scope = null;
        if ($item instanceof AbstractItem) {
            $fee = $this->itemFeeProvider->getFee($item);
            $scope = $item->getStore();
        }

        return array_merge($result, $this->breakdownFormatter->format($selection, $fee, $scope));
    }
}
