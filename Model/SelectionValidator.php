<?php
/**
 * InkThread Decoration Pricing
 */
declare(strict_types=1);

namespace InkThread\DecorationPricing\Model;

use InkThread\DecorationPricing\Model\Source\DecorationMethod;
use InkThread\DecorationPricing\Model\Source\PrintLocation;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Phrase;

/**
 * Location rules for a decoration selection.
 */
class SelectionValidator
{
    public const MAX_LOCATIONS = 3;

    public const MAX_NOTE_LENGTH = 255;

    public const MAX_LOCATION_TEXT_LENGTH = 100;

    /**
     * Location pairs that cannot be chosen together.
     */
    public const EXCLUSIVE_PAIRS = [
        [PrintLocation::UPPER_BACK, PrintLocation::FULL_BACK],
    ];

    /**
     * @param DecorationMethod $decorationMethod
     * @param PrintLocation $printLocation
     */
    public function __construct(
        private readonly DecorationMethod $decorationMethod,
        private readonly PrintLocation $printLocation
    ) {
    }

    /**
     * Return every broken rule as a customer-facing message (empty when valid).
     *
     * @param Selection $selection
     * @return Phrase[]
     */
    public function validate(Selection $selection): array
    {
        if (!$this->decorationMethod->isValid($selection->getMethod())) {
            return [__('Please choose a valid decoration method.')];
        }

        if (!$selection->isDecorated()) {
            return [];
        }

        $errors = [];
        $locations = $selection->getLocations();

        if ($locations === []) {
            $errors[] = __(
                'Please choose at least one print location for %1.',
                $this->decorationMethod->getLabel($selection->getMethod())
            );
        }

        if (count($locations) > self::MAX_LOCATIONS) {
            $errors[] = __('You can choose up to %1 print locations.', self::MAX_LOCATIONS);
        }

        if (count($locations) !== count(array_unique($locations))) {
            $errors[] = __('Each print location can only be chosen once.');
        }

        foreach (array_unique($locations) as $location) {
            if (!$this->printLocation->isValid($location)) {
                $errors[] = __('"%1" is not a valid print location.', $location);
            }
        }

        foreach (self::EXCLUSIVE_PAIRS as [$first, $second]) {
            if (in_array($first, $locations, true) && in_array($second, $locations, true)) {
                $errors[] = __(
                    '%1 and %2 cannot both be selected.',
                    $this->printLocation->getLabel($first),
                    $this->printLocation->getLabel($second)
                );
            }
        }

        if (mb_strlen($selection->getNote()) > self::MAX_NOTE_LENGTH) {
            $errors[] = __('The decoration note cannot be longer than %1 characters.', self::MAX_NOTE_LENGTH);
        }

        // Core renders non-native item option values as HTML in some places (checkout summary sidebar), so markup
        // is refused at input rather than relying on every output path to escape it.
        if (strpbrk($selection->getNote(), '<>') !== false) {
            $errors[] = __('The decoration note cannot contain the characters < or >.');
        }

        foreach ($selection->getLocationTexts() as $location => $text) {
            $label = $this->printLocation->getLabel($location);
            if (mb_strlen($text) > self::MAX_LOCATION_TEXT_LENGTH) {
                $errors[] = __(
                    'The text for %1 cannot be longer than %2 characters.',
                    $label,
                    self::MAX_LOCATION_TEXT_LENGTH
                );
            }

            if (strpbrk($text, '<>') !== false) {
                $errors[] = __('The text for %1 cannot contain the characters < or >.', $label);
            }
        }

        return $errors;
    }

    /**
     * Required per-location details: text for each embroidery location, artwork for each screen print location.
     *
     * Separate from validate() because artwork is only known once uploads are resolved.
     *
     * @param Selection $selection
     * @return Phrase[]
     */
    public function validateCompleteness(Selection $selection): array
    {
        $errors = [];
        $method = $selection->getMethod();
        foreach (array_unique($selection->getLocations()) as $location) {
            $label = $this->printLocation->getLabel($location);
            if ($this->decorationMethod->usesLocationText($method) && $selection->getLocationText($location) === '') {
                $errors[] = __('Please enter the text to embroider for %1.', $label);
            }

            if ($this->decorationMethod->usesArtwork($method) && !isset($selection->getArtwork()[$location])) {
                $errors[] = __('Please upload artwork for %1.', $label);
            }
        }

        return $errors;
    }

    /**
     * Throw when required per-location details are missing.
     *
     * @param Selection $selection
     * @return void
     * @throws LocalizedException
     */
    public function assertComplete(Selection $selection): void
    {
        $errors = $this->validateCompleteness($selection);
        if ($errors !== []) {
            throw new LocalizedException(__(implode(' ', array_map('strval', $errors))));
        }
    }

    /**
     * Throw with all broken rules joined, if any.
     *
     * @param Selection $selection
     * @return void
     * @throws LocalizedException
     */
    public function assertValid(Selection $selection): void
    {
        $errors = $this->validate($selection);
        if ($errors !== []) {
            throw new LocalizedException(__(implode(' ', array_map('strval', $errors))));
        }
    }
}
