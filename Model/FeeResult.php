<?php
/**
 * InkThread Decoration Pricing
 */
declare(strict_types=1);

namespace InkThread\DecorationPricing\Model;

/**
 * Calculated decoration fee for one cart line.
 */
class FeeResult
{
    /**
     * @param Selection $selection
     * @param array<string,float> $locationPrices location code => per-unit price
     * @param float $unitFee sum of location prices (per unit)
     * @param float $lineQty quantity of this line
     * @param float $tierQty quantity the tier was chosen by (line qty, or pooled qty of matching lines)
     * @param float $lineFee unit fee x line qty
     */
    public function __construct(
        private readonly Selection $selection,
        private readonly array $locationPrices,
        private readonly float $unitFee,
        private readonly float $lineQty,
        private readonly float $tierQty,
        private readonly float $lineFee
    ) {
    }

    /**
     * The selection this fee was calculated for.
     *
     * @return Selection
     */
    public function getSelection(): Selection
    {
        return $this->selection;
    }

    /**
     * Per-unit price of each selected location.
     *
     * @return array<string, float>
     */
    public function getLocationPrices(): array
    {
        return $this->locationPrices;
    }

    /**
     * Decoration fee per unit.
     *
     * @return float
     */
    public function getUnitFee(): float
    {
        return $this->unitFee;
    }

    /**
     * Quantity of the line.
     *
     * @return float
     */
    public function getLineQty(): float
    {
        return $this->lineQty;
    }

    /**
     * Quantity used to pick the tier.
     *
     * @return float
     */
    public function getTierQty(): float
    {
        return $this->tierQty;
    }

    /**
     * Decoration fee for the whole line.
     *
     * @return float
     */
    public function getLineFee(): float
    {
        return $this->lineFee;
    }

    /**
     * Whether the tier came from more units than this line has (matching lines were pooled).
     *
     * @return bool
     */
    public function isPooled(): bool
    {
        return $this->tierQty > $this->lineQty;
    }

    /**
     * Plain array form, used for the order item snapshot.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->selection->toArray() + [
            'location_prices' => $this->locationPrices,
            'unit_fee' => $this->unitFee,
            'line_qty' => $this->lineQty,
            'tier_qty' => $this->tierQty,
            'line_fee' => $this->lineFee,
        ];
    }
}
