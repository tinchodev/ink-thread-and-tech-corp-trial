<?php
/**
 * InkThread Decoration Pricing
 */
declare(strict_types=1);

namespace InkThread\DecorationPricing\ViewModel;

use InkThread\DecorationPricing\Api\PriceTableProviderInterface;
use InkThread\DecorationPricing\Model\SelectionFactory;
use InkThread\DecorationPricing\Model\SelectionValidator;
use InkThread\DecorationPricing\Model\Source\DecorationMethod;
use InkThread\DecorationPricing\Model\Source\PrintLocation;
use InkThread\DecorationPricing\Observer\CaptureDecorationSelection;
use InkThread\DecorationPricing\Setup\Patch\Data\AddDecorationEnabledAttribute;
use Magento\Catalog\Model\Product;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\View\Element\Block\ArgumentInterface;

/**
 * Data for the product page decoration fieldset.
 */
class DecorationOptions implements ArgumentInterface
{
    /**
     * @param DecorationMethod $decorationMethod
     * @param PrintLocation $printLocation
     * @param PriceTableProviderInterface $priceTableProvider
     * @param SelectionFactory $selectionFactory
     * @param Json $serializer
     */
    public function __construct(
        private readonly DecorationMethod $decorationMethod,
        private readonly PrintLocation $printLocation,
        private readonly PriceTableProviderInterface $priceTableProvider,
        private readonly SelectionFactory $selectionFactory,
        private readonly Json $serializer
    ) {
    }

    /**
     * Whether the product offers decoration.
     *
     * @param Product $product
     * @return bool
     */
    public function isEnabled(Product $product): bool
    {
        return (bool)$product->getData(AddDecorationEnabledAttribute::ATTRIBUTE_CODE) && $product->isSaleable();
    }

    /**
     * Name of the buy request field.
     *
     * @return string
     */
    public function getFieldName(): string
    {
        return CaptureDecorationSelection::REQUEST_KEY;
    }

    /**
     * Method code => label.
     *
     * @return array<string, string>
     */
    public function getMethods(): array
    {
        return $this->decorationMethod->getLabels();
    }

    /**
     * Location code => label.
     *
     * @return array<string, string>
     */
    public function getLocations(): array
    {
        return $this->printLocation->getLabels();
    }

    /**
     * Maximum note length.
     *
     * @return int
     */
    public function getMaxNoteLength(): int
    {
        return SelectionValidator::MAX_NOTE_LENGTH;
    }

    /**
     * Current selection when editing a cart item (null on a fresh product page).
     *
     * @param Product $product
     * @return array{method: string, locations: string[], note: string}|null
     */
    public function getPreconfigured(Product $product): ?array
    {
        $data = $product->getPreconfiguredValues()->getData(CaptureDecorationSelection::REQUEST_KEY);

        return $this->selectionFactory->createFromRequestData($data)?->toArray();
    }

    /**
     * Widget config: limits, rules and which locations each method offers.
     *
     * Locations without a price table are disabled instead of failing on add to cart.
     *
     * @return string
     */
    public function getJsConfig(): string
    {
        $offered = [];
        foreach ($this->decorationMethod->getPricedMethods() as $method) {
            foreach (array_keys($this->printLocation->getLabels()) as $location) {
                if ($this->priceTableProvider->getTiers($method, $location)) {
                    $offered[$method][] = $location;
                }
            }
        }

        return $this->serializer->serialize([
            'noneMethod' => DecorationMethod::NONE,
            'maxLocations' => SelectionValidator::MAX_LOCATIONS,
            'exclusivePairs' => SelectionValidator::EXCLUSIVE_PAIRS,
            'offeredLocations' => $offered,
            'messages' => [
                'maxLocations' => (string)__(
                    'You can choose up to %1 print locations.',
                    SelectionValidator::MAX_LOCATIONS
                ),
                'exclusive' => (string)__('%1 and %2 cannot both be selected.'),
                'noMarkup' => (string)__('The decoration note cannot contain the characters < or >.'),
            ],
            'labels' => $this->printLocation->getLabels(),
        ]);
    }
}
