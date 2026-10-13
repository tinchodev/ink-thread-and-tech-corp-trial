<?php
/**
 * InkThread Decoration Pricing
 */
declare(strict_types=1);

namespace InkThread\DecorationPricing\Observer;

use InkThread\DecorationPricing\Model\Artwork\RequestArtworkResolver;
use InkThread\DecorationPricing\Model\FeeCalculator;
use InkThread\DecorationPricing\Model\Quote\ItemFeeProvider;
use InkThread\DecorationPricing\Model\Selection;
use InkThread\DecorationPricing\Model\SelectionFactory;
use InkThread\DecorationPricing\Model\SelectionValidator;
use InkThread\DecorationPricing\Setup\Patch\Data\AddDecorationEnabledAttribute;
use Magento\Catalog\Model\Product;
use Magento\Framework\DataObject;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Serialize\Serializer\Json;

/**
 * On add to cart / cart item update: validate the posted decoration and store it as a product custom option, which
 * core copies onto the quote item (and which makes differently decorated lines separate cart lines).
 *
 * Listens to catalog_product_type_prepare_full_options and _lite_options.
 */
class CaptureDecorationSelection implements ObserverInterface
{
    public const REQUEST_KEY = 'inkthread_decoration';

    /**
     * Buy request => id of the top-level product it was first prepared for.
     *
     * A configurable product prepares its child with the same buy request object, after the parent. Only the
     * top-level product carries the decoration (and the flag), so the child pass is skipped.
     *
     * @var \WeakMap<DataObject, int>
     */
    private \WeakMap $weakMap;

    /**
     * @param SelectionFactory $selectionFactory
     * @param SelectionValidator $selectionValidator
     * @param FeeCalculator $feeCalculator
     * @param RequestArtworkResolver $requestArtworkResolver
     * @param Json $json
     */
    public function __construct(
        private readonly SelectionFactory $selectionFactory,
        private readonly SelectionValidator $selectionValidator,
        private readonly FeeCalculator $feeCalculator,
        private readonly RequestArtworkResolver $requestArtworkResolver,
        private readonly Json $json
    ) {
        $this->weakMap = new \WeakMap();
    }

    /**
     * @inheritdoc
     *
     * @throws LocalizedException
     */
    #[\Override]
    public function execute(Observer $observer): void
    {
        $buyRequest = $observer->getData('buy_request');
        $product = $observer->getData('product');
        if (!$buyRequest instanceof DataObject || !$product instanceof Product) {
            return;
        }

        $productId = (int)$product->getId();
        if (isset($this->weakMap[$buyRequest]) && $this->weakMap[$buyRequest] !== $productId) {
            return;
        }

        $this->weakMap[$buyRequest] = $productId;

        $selection = $this->selectionFactory->createFromRequestData($buyRequest->getData(self::REQUEST_KEY));
        if (!$selection instanceof Selection || !$selection->isDecorated()) {
            return;
        }

        if (!$product->getData(AddDecorationEnabledAttribute::ATTRIBUTE_CODE)) {
            throw new LocalizedException(__('Decoration is not available for %1.', $product->getName()));
        }

        $this->selectionValidator->assertValid($selection);
        $this->feeCalculator->assertOffered($selection);
        $selection = $selection->withArtwork($this->requestArtworkResolver->resolve($buyRequest, $selection));
        $this->selectionValidator->assertComplete($selection);

        $product->addCustomOption(
            ItemFeeProvider::OPTION_CODE,
            $this->json->serialize($selection->toArray())
        );
    }
}
