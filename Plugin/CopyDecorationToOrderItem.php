<?php
/**
 * InkThread Decoration Pricing
 */
declare(strict_types=1);

namespace InkThread\DecorationPricing\Plugin;

use InkThread\DecorationPricing\Model\BreakdownFormatter;
use InkThread\DecorationPricing\Model\FeeResult;
use InkThread\DecorationPricing\Model\Quote\ItemFeeProvider;
use InkThread\DecorationPricing\Model\Selection;
use Magento\Quote\Model\Quote\Item\AbstractItem;
use Magento\Quote\Model\Quote\Item\ToOrderItem;
use Magento\Sales\Api\Data\OrderItemInterface;
use Magento\Sales\Model\Order\Item;

/**
 * Freezes the decoration into the order item's product_options when the order is placed:
 * - inkthread_decoration: structured snapshot (method, locations, prices, note, fee, quantities)
 * - additional_options: label/value lines, rendered by core in admin/customer order views, emails, invoices, PDFs
 *
 * A snapshot (not a live calculation) means later price table changes never alter placed orders.
 */
class CopyDecorationToOrderItem
{
    public const SNAPSHOT_KEY = 'inkthread_decoration';

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
     * Add the snapshot to the converted order item.
     *
     * @param ToOrderItem $toOrderItem
     * @param OrderItemInterface $orderItem
     * @param AbstractItem $item
     * @param array<string,mixed> $data
     * @return OrderItemInterface
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function afterConvert(
        ToOrderItem $toOrderItem,
        OrderItemInterface $orderItem,
        AbstractItem $item,
        array $data = []
    ): OrderItemInterface {
        $selection = $this->itemFeeProvider->getSelection($item);
        if (!$selection instanceof Selection || !$orderItem instanceof Item) {
            return $orderItem;
        }

        $fee = $this->itemFeeProvider->getFee($item);
        $options = $orderItem->getProductOptions() ?: [];
        $options[self::SNAPSHOT_KEY] = $fee instanceof FeeResult ? $fee->toArray() : $selection->toArray();
        $options['additional_options'] = array_merge(
            $options['additional_options'] ?? [],
            $this->breakdownFormatter->format($selection, $fee, $item->getStore())
        );
        $orderItem->setProductOptions($options);

        return $orderItem;
    }
}
