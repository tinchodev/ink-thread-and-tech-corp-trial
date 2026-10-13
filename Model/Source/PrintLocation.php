<?php
/**
 * InkThread Decoration Pricing
 */
declare(strict_types=1);

namespace InkThread\DecorationPricing\Model\Source;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * Print locations a customer can choose for a decoration.
 */
class PrintLocation implements OptionSourceInterface
{
    public const LEFT_CHEST = 'left_chest';
    public const RIGHT_CHEST = 'right_chest';
    public const SLEEVE = 'sleeve';
    public const UPPER_BACK = 'upper_back';
    public const FULL_BACK = 'full_back';

    /**
     * Location code => label, in display order.
     *
     * @return array<string, string>
     */
    public function getLabels(): array
    {
        return [
            self::LEFT_CHEST => (string)__('Left chest'),
            self::RIGHT_CHEST => (string)__('Right chest'),
            self::SLEEVE => (string)__('Sleeve'),
            self::UPPER_BACK => (string)__('Upper back'),
            self::FULL_BACK => (string)__('Full back'),
        ];
    }

    /**
     * Whether the code is a known location.
     *
     * @param string $location
     * @return bool
     */
    public function isValid(string $location): bool
    {
        return array_key_exists($location, $this->getLabels());
    }

    /**
     * Label for a location code, falling back to the code itself.
     *
     * @param string $location
     * @return string
     */
    public function getLabel(string $location): string
    {
        return $this->getLabels()[$location] ?? $location;
    }

    /**
     * @inheritdoc
     *
     * @return list<array{value: string, label: string}>
     */
    public function toOptionArray(): array
    {
        $options = [];
        foreach ($this->getLabels() as $value => $label) {
            $options[] = ['value' => $value, 'label' => $label];
        }

        return $options;
    }
}
