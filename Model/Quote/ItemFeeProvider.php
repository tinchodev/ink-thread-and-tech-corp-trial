<?php
/**
 * InkThread Decoration Pricing
 */
declare(strict_types=1);

namespace InkThread\DecorationPricing\Model\Quote;

use InkThread\DecorationPricing\Exception\PricingNotConfiguredException;
use InkThread\DecorationPricing\Model\FeeCalculator;
use InkThread\DecorationPricing\Model\FeeResult;
use InkThread\DecorationPricing\Model\Selection;
use InkThread\DecorationPricing\Model\SelectionFactory;
use InkThread\DecorationPricing\Model\TierLine;
use InkThread\DecorationPricing\Model\TierQuantityResolver;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Item\AbstractItem;

/**
 * Bridges quote items to the pricing core: reads the stored selection, pools tier quantities across the quote and
 * keeps the resulting FeeResult on each quote item.
 *
 * The fee is kept on the item, not on its product: quote items of the same product share one product instance.
 */
class ItemFeeProvider
{
    public const OPTION_CODE = 'inkthread_decoration';
    public const ERROR_ORIGIN = 'inkthread_decoration';
    public const ERROR_CODE_NOT_PRICED = 1;
    private const FEE_DATA_KEY = 'inkthread_decoration_fee';

    /**
     * @param SelectionFactory $selectionFactory
     * @param TierQuantityResolver $tierQuantityResolver
     * @param FeeCalculator $feeCalculator
     * @param Json $serializer
     */
    public function __construct(
        private readonly SelectionFactory $selectionFactory,
        private readonly TierQuantityResolver $tierQuantityResolver,
        private readonly FeeCalculator $feeCalculator,
        private readonly Json $serializer
    ) {
    }

    /**
     * Decoration stored on a quote item, or null when it has none.
     *
     * @param AbstractItem $item
     * @return Selection|null
     */
    public function getSelection(AbstractItem $item): ?Selection
    {
        /** @var \Magento\Quote\Model\Quote\Item\Option|null $option core docblock omits null */
        $option = $item->getOptionByCode(self::OPTION_CODE);

        return $option !== null ? $this->decode((string)$option->getValue()) : null;
    }

    /**
     * Decode a stored option value.
     *
     * @param string $value
     * @return Selection|null
     */
    public function decode(string $value): ?Selection
    {
        try {
            $selection = $this->selectionFactory->createFromRequestData($this->serializer->unserialize($value));
        } catch (\InvalidArgumentException $e) {
            return null;
        }

        return $selection !== null && $selection->isDecorated() ? $selection : null;
    }

    /**
     * Fee of a quote item (null when undecorated or not priceable); recalculates the quote when stale.
     *
     * @param AbstractItem $item
     * @return FeeResult|null
     */
    public function getFee(AbstractItem $item): ?FeeResult
    {
        if ($this->getSelection($item) === null) {
            return null;
        }

        $fee = $item->getData(self::FEE_DATA_KEY);
        if (!$fee instanceof FeeResult || abs($fee->getLineQty() - (float)$item->getQty()) > 0.0001) {
            $quote = $item->getQuote();
            if (!$quote instanceof Quote) {
                return null;
            }
            $this->calculateForQuote($quote);
            $fee = $item->getData(self::FEE_DATA_KEY);
        }

        return $fee instanceof FeeResult ? $fee : null;
    }

    /**
     * Pool tier quantities over all visible items and store each item's fee.
     *
     * Items that cannot be priced get an error, which blocks checkout until the cart or the price table is fixed.
     *
     * @param Quote $quote
     * @return void
     */
    public function calculateForQuote(Quote $quote): void
    {
        $items = [];
        $lines = [];
        foreach ($quote->getAllVisibleItems() as $item) {
            $key = (string)spl_object_id($item);
            $items[$key] = $item;
            $lines[] = new TierLine(
                $key,
                (int)$item->getData('product_id'),
                $this->getSelection($item),
                (float)$item->getQty()
            );
        }
        $tierQuantities = $this->tierQuantityResolver->resolve($lines);

        $quote->removeErrorInfosByParams('error', [
            'origin' => self::ERROR_ORIGIN,
            'code' => self::ERROR_CODE_NOT_PRICED,
        ]);
        foreach ($lines as $line) {
            $item = $items[$line->getLineId()];
            $item->removeErrorInfosByParams(['origin' => self::ERROR_ORIGIN]);
            $item->unsetData(self::FEE_DATA_KEY);

            $selection = $line->getSelection();
            if ($selection === null || $line->getQty() <= 0) {
                continue;
            }
            try {
                $item->setData(
                    self::FEE_DATA_KEY,
                    $this->feeCalculator->calculate($selection, $line->getQty(), $tierQuantities[$line->getLineId()])
                );
            } catch (PricingNotConfiguredException $e) {
                $item->addErrorInfo(self::ERROR_ORIGIN, self::ERROR_CODE_NOT_PRICED, $e->getMessage());
                $quote->addErrorInfo(
                    'error',
                    self::ERROR_ORIGIN,
                    self::ERROR_CODE_NOT_PRICED,
                    (string)__('Some decorations in your cart cannot be priced. Please review them.')
                );
            }
        }
    }
}
