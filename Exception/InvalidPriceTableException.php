<?php
/**
 * InkThread Decoration Pricing
 */
declare(strict_types=1);

namespace InkThread\DecorationPricing\Exception;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Phrase;

/**
 * Thrown when an admin-configured price table contains invalid rows.
 */
class InvalidPriceTableException extends LocalizedException
{
    /**
     * @param Phrase[] $errors
     */
    public function __construct(private readonly array $errors)
    {
        parent::__construct(__(implode(' ', array_map('strval', $this->errors))));
    }

    /**
     * Individual validation errors.
     *
     * @return Phrase[]
     */
    public function getErrors(): array
    {
        return $this->errors;
    }
}
