<?php
/**
 * InkThread Decoration Pricing
 */
declare(strict_types=1);

namespace InkThread\DecorationPricing\Model;

use InkThread\DecorationPricing\Model\Source\DecorationMethod;
use InkThread\DecorationPricing\Model\Source\PrintLocation;
use Magento\Framework\Pricing\PriceCurrencyInterface;

/**
 * Turns a decoration into label/value lines in the shape core uses for item options (cart, minicart, checkout,
 * order views, emails). Values are plain text. Core does not escape them everywhere (the checkout summary renders
 * them as HTML), which is why SelectionValidator refuses markup in the note, the only user-supplied value.
 */
class BreakdownFormatter
{
    /**
     * @param DecorationMethod $decorationMethod
     * @param PrintLocation $printLocation
     * @param PriceCurrencyInterface $priceCurrency
     */
    public function __construct(
        private readonly DecorationMethod $decorationMethod,
        private readonly PrintLocation $printLocation,
        private readonly PriceCurrencyInterface $priceCurrency
    ) {
    }

    /**
     * Breakdown lines. Without a fee (e.g. wishlist, or not priceable) only the selection is shown.
     *
     * @param Selection $selection
     * @param FeeResult|null $fee
     * @param mixed $scope store used for currency formatting
     * @return list<array{label: string, value: string}>
     */
    public function format(Selection $selection, ?FeeResult $fee = null, mixed $scope = null): array
    {
        $method = $this->decorationMethod->getLabel($selection->getMethod());

        if ($fee === null) {
            $locations = array_map([$this->printLocation, 'getLabel'], $selection->getLocations());
            $lines = [['label' => (string)__('Decoration'), 'value' => $method . ': ' . implode(', ', $locations)]];
        } else {
            $lines = [['label' => (string)__('Decoration'), 'value' => $method]];
            foreach ($fee->getLocationPrices() as $location => $price) {
                $lines[] = [
                    'label' => $this->printLocation->getLabel($location),
                    'value' => (string)__('%1 / unit', $this->money($price, $scope)),
                ];
            }
            $value = (string)__(
                '%1 / unit × %2 = %3',
                $this->money($fee->getUnitFee(), $scope),
                $this->qty($fee->getLineQty()),
                $this->money($fee->getLineFee(), $scope)
            );
            if ($fee->isPooled()) {
                $value .= ' ' . __('(tier for %1 matching units)', $this->qty($fee->getTierQty()));
            }
            $lines[] = ['label' => (string)__('Decoration fee'), 'value' => $value];
        }

        if ($selection->getNote() !== '') {
            $lines[] = ['label' => (string)__('Decoration note'), 'value' => $selection->getNote()];
        }

        return $lines;
    }

    /**
     * Format an amount as plain text (no HTML container).
     *
     * @param float $amount
     * @param mixed $scope
     * @return string
     */
    private function money(float $amount, mixed $scope): string
    {
        return (string)$this->priceCurrency->convertAndFormat(
            $amount,
            false,
            PriceCurrencyInterface::DEFAULT_PRECISION,
            $scope
        );
    }

    /**
     * Format a quantity without trailing decimals.
     *
     * @param float $qty
     * @return string
     */
    private function qty(float $qty): string
    {
        return (string)(floor($qty) === $qty ? (int)$qty : $qty);
    }
}
