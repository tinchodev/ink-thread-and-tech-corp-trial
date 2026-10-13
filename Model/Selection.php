<?php
/**
 * InkThread Decoration Pricing
 */
declare(strict_types=1);

namespace InkThread\DecorationPricing\Model;

use InkThread\DecorationPricing\Model\Source\DecorationMethod;

/**
 * A customer's decoration choice for one cart line. Immutable; not validated by itself (see SelectionValidator).
 */
class Selection
{
    /**
     * @param string $method
     * @param string[] $locations
     * @param string $note
     */
    public function __construct(
        private readonly string $method,
        private readonly array $locations,
        private readonly string $note
    ) {
    }

    /**
     * Decoration method code.
     *
     * @return string
     */
    public function getMethod(): string
    {
        return $this->method;
    }

    /**
     * Print location codes, in the order the customer chose them.
     *
     * @return string[]
     */
    public function getLocations(): array
    {
        return $this->locations;
    }

    /**
     * Free-text note (raw; escape on output).
     *
     * @return string
     */
    public function getNote(): string
    {
        return $this->note;
    }

    /**
     * Whether a decoration (and therefore a fee) applies.
     *
     * @return bool
     */
    public function isDecorated(): bool
    {
        return $this->method !== DecorationMethod::NONE;
    }

    /**
     * Identity of the decoration for tier pooling: method + location set (order-independent, note excluded).
     *
     * @return string
     */
    public function getPricingKey(): string
    {
        $locations = $this->locations;
        sort($locations);

        return $this->method . ':' . implode(',', $locations);
    }

    /**
     * Plain array form, used for storage on the quote item.
     *
     * @return array{method: string, locations: string[], note: string}
     */
    public function toArray(): array
    {
        return [
            'method' => $this->method,
            'locations' => $this->locations,
            'note' => $this->note,
        ];
    }
}
