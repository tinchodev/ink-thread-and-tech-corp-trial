<?php
/**
 * InkThread Decoration Pricing
 */
declare(strict_types=1);

namespace InkThread\DecorationPricing\Model;

/**
 * Minimal view of a cart line for tier pooling, decoupled from quote item models.
 */
class TierLine
{
    /**
     * @param string $lineId unique key of the line within the cart
     * @param int $productId top-level product id (parent id for configurables)
     * @param Selection|null $selection
     * @param float $qty
     */
    public function __construct(
        private readonly string $lineId,
        private readonly int $productId,
        private readonly ?Selection $selection,
        private readonly float $qty
    ) {
    }

    /**
     * Unique key of the line.
     *
     * @return string
     */
    public function getLineId(): string
    {
        return $this->lineId;
    }

    /**
     * Top-level product id.
     *
     * @return int
     */
    public function getProductId(): int
    {
        return $this->productId;
    }

    /**
     * Decoration selection, null when the line has none.
     *
     * @return Selection|null
     */
    public function getSelection(): ?Selection
    {
        return $this->selection;
    }

    /**
     * Line quantity.
     *
     * @return float
     */
    public function getQty(): float
    {
        return $this->qty;
    }
}
