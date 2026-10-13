<?php
/**
 * InkThread Decoration Pricing
 */
declare(strict_types=1);

namespace InkThread\DecorationPricing\Plugin;

use InkThread\DecorationPricing\Observer\CaptureDecorationSelection;
use Magento\Catalog\Model\Product;
use Magento\Framework\DataObject;

/**
 * Keeps the decoration in the product's preconfigured values.
 *
 * Product::processBuyRequest() only carries the type's own keys (custom options, super attributes) into the values
 * used to prefill the product page when a cart / wishlist item is edited, so the decoration would otherwise be lost.
 */
class KeepDecorationInPreconfiguredValues
{
    /**
     * Copy the decoration from the buy request.
     *
     * @param Product $subject
     * @param DataObject $result
     * @param DataObject $buyRequest
     * @return DataObject
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function afterProcessBuyRequest(Product $subject, DataObject $result, DataObject $buyRequest): DataObject
    {
        $decoration = $buyRequest->getData(CaptureDecorationSelection::REQUEST_KEY);
        if (is_array($decoration)) {
            $result->setData(CaptureDecorationSelection::REQUEST_KEY, $decoration);
        }

        return $result;
    }
}
