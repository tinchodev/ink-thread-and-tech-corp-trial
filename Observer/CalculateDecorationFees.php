<?php
/**
 * InkThread Decoration Pricing
 */
declare(strict_types=1);

namespace InkThread\DecorationPricing\Observer;

use InkThread\DecorationPricing\Model\Quote\ItemFeeProvider;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Quote\Model\Quote;

/**
 * Before quote totals are collected, pool tier quantities and calculate every line's fee, so the final price plugin
 * (called per item by the subtotal collector) uses the pooled tier.
 *
 * Listens to sales_quote_collect_totals_before.
 */
class CalculateDecorationFees implements ObserverInterface
{
    /**
     * @param ItemFeeProvider $itemFeeProvider
     */
    public function __construct(
        private readonly ItemFeeProvider $itemFeeProvider
    ) {
    }

    /**
     * @inheritdoc
     */
    #[\Override]
    public function execute(Observer $observer): void
    {
        $quote = $observer->getData('quote');
        if ($quote instanceof Quote) {
            $this->itemFeeProvider->calculateForQuote($quote);
        }
    }
}
