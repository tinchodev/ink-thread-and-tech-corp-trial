<?php
/**
 * InkThread Decoration Pricing
 */
declare(strict_types=1);

namespace InkThread\DecorationPricing\Model\Source;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * Decoration methods a customer can choose on the product page.
 */
class DecorationMethod implements OptionSourceInterface
{
    public const NONE = 'none';

    public const EMBROIDERY = 'embroidery';

    public const SCREEN_PRINT = 'screen_print';

    /**
     * Methods that take a text per location (embroidery).
     */
    public const LOCATION_TEXT_METHODS = [self::EMBROIDERY];

    /**
     * Methods that take an artwork file per location (screen print).
     */
    public const ARTWORK_METHODS = [self::SCREEN_PRINT];

    /**
     * Method code => label.
     *
     * @return array<string, string>
     */
    public function getLabels(): array
    {
        return [
            self::NONE => (string)__('None'),
            self::EMBROIDERY => (string)__('Embroidery'),
            self::SCREEN_PRINT => (string)__('Screen Print'),
        ];
    }

    /**
     * Methods that carry a fee and therefore have a price table.
     *
     * @return string[]
     */
    public function getPricedMethods(): array
    {
        return [self::EMBROIDERY, self::SCREEN_PRINT];
    }

    /**
     * Whether the method takes a text per location.
     *
     * @param string $method
     * @return bool
     */
    public function usesLocationText(string $method): bool
    {
        return in_array($method, self::LOCATION_TEXT_METHODS, true);
    }

    /**
     * Whether the method takes an artwork file per location.
     *
     * @param string $method
     * @return bool
     */
    public function usesArtwork(string $method): bool
    {
        return in_array($method, self::ARTWORK_METHODS, true);
    }

    /**
     * Whether the code is a known method.
     *
     * @param string $method
     * @return bool
     */
    public function isValid(string $method): bool
    {
        return array_key_exists($method, $this->getLabels());
    }

    /**
     * Label for a method code, falling back to the code itself.
     *
     * @param string $method
     * @return string
     */
    public function getLabel(string $method): string
    {
        return $this->getLabels()[$method] ?? $method;
    }

    /**
     * @inheritdoc
     *
     * @return list<array{value: string, label: string}>
     */
    #[\Override]
    public function toOptionArray(): array
    {
        $options = [];
        foreach ($this->getLabels() as $value => $label) {
            $options[] = ['value' => $value, 'label' => $label];
        }

        return $options;
    }
}
