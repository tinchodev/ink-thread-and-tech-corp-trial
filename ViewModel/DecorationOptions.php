<?php
/**
 * InkThread Decoration Pricing
 */
declare(strict_types=1);

namespace InkThread\DecorationPricing\ViewModel;

use InkThread\DecorationPricing\Api\PriceTableProviderInterface;
use InkThread\DecorationPricing\Model\Artwork\ArtworkFile;
use InkThread\DecorationPricing\Model\Artwork\ArtworkToken;
use InkThread\DecorationPricing\Model\Artwork\ArtworkValidator;
use InkThread\DecorationPricing\Model\Artwork\RequestArtworkResolver;
use InkThread\DecorationPricing\Model\Selection;
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
     * @param ArtworkToken $artworkToken
     * @param Json $json
     */
    public function __construct(
        private readonly DecorationMethod $decorationMethod,
        private readonly PrintLocation $printLocation,
        private readonly PriceTableProviderInterface $priceTableProvider,
        private readonly SelectionFactory $selectionFactory,
        private readonly ArtworkToken $artworkToken,
        private readonly Json $json
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
     * Maximum text length per location.
     *
     * @return int
     */
    public function getMaxLocationTextLength(): int
    {
        return SelectionValidator::MAX_LOCATION_TEXT_LENGTH;
    }

    /**
     * File input name for a location's artwork.
     *
     * @param string $location
     * @return string
     */
    public function getArtworkFieldName(string $location): string
    {
        return RequestArtworkResolver::FILE_FIELD_PREFIX . $location;
    }

    /**
     * Value for the file input's accept attribute.
     *
     * @return string
     */
    public function getArtworkAccept(): string
    {
        return implode(
            ',',
            array_map(static fn ($ext): string => '.' . $ext, array_keys(ArtworkValidator::ALLOWED_TYPES))
        );
    }

    /**
     * Current selection when editing a cart item (null on a fresh product page).
     *
     * Artwork comes from the encrypted tokens in the buy request: each entry has the display name, a display label and
     * the token to post back to keep the file.
     *
     * @param Product $product
     * @return array<string,mixed>|null
     */
    public function getPreconfigured(Product $product): ?array
    {
        $data = $product->getPreconfiguredValues()->getData(CaptureDecorationSelection::REQUEST_KEY);
        $selection = $this->selectionFactory->createFromRequestData($data);
        if (!$selection instanceof Selection) {
            return null;
        }

        $result = $selection->toArray();
        $result['artwork'] = [];
        $tokens = is_array($data['artwork_tokens'] ?? null) ? $data['artwork_tokens'] : [];
        foreach ($tokens as $location => $token) {
            $file = $this->artworkToken->decode($token);
            if ($file instanceof ArtworkFile && in_array($location, $selection->getLocations(), true)) {
                $result['artwork'][$location] = [
                    'name' => $file->getName(),
                    'token' => $token,
                    'label' => (string)__('Current artwork: %1 (upload a file to replace it)', $file->getName()),
                ];
            }
        }

        return $result;
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

        return $this->json->serialize([
            'noneMethod' => DecorationMethod::NONE,
            'maxLocations' => SelectionValidator::MAX_LOCATIONS,
            'exclusivePairs' => SelectionValidator::EXCLUSIVE_PAIRS,
            'offeredLocations' => $offered,
            'messages' => [
                // Shown next to a greyed-out location to say why; not an error about the current choice.
                'exclusive' => (string)__('(not with %1)'),
                'notOffered' => (string)__('(not available)'),
                'noMarkup' => (string)__('The decoration note cannot contain the characters < or >.'),
            ],
            'textMethods' => DecorationMethod::LOCATION_TEXT_METHODS,
            'artworkMethods' => DecorationMethod::ARTWORK_METHODS,
            'artwork' => [
                'maxSize' => ArtworkValidator::MAX_SIZE,
                'extensions' => array_keys(ArtworkValidator::ALLOWED_TYPES),
            ],
            'labels' => $this->printLocation->getLabels(),
        ]);
    }
}
