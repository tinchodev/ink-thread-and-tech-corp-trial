<?php
/**
 * InkThread Decoration Pricing
 */
declare(strict_types=1);

namespace InkThread\DecorationPricing\Exception;

use Magento\Framework\Exception\LocalizedException;

/**
 * Thrown when no configured price covers a selected location at the requested quantity.
 */
class PricingNotConfiguredException extends LocalizedException
{
}
