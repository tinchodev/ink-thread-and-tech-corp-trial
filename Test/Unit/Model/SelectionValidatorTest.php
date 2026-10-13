<?php
/**
 * InkThread Decoration Pricing
 */
declare(strict_types=1);

namespace InkThread\DecorationPricing\Test\Unit\Model;

use InkThread\DecorationPricing\Model\Artwork\ArtworkFile;
use InkThread\DecorationPricing\Model\Selection;
use InkThread\DecorationPricing\Model\SelectionValidator;
use InkThread\DecorationPricing\Model\Source\DecorationMethod;
use InkThread\DecorationPricing\Model\Source\PrintLocation;
use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\TestCase;

class SelectionValidatorTest extends TestCase
{
    /**
     * @var SelectionValidator
     */
    private SelectionValidator $selectionValidator;

    #[\Override]
    protected function setUp(): void
    {
        $this->selectionValidator = new SelectionValidator(new DecorationMethod(), new PrintLocation());
    }

    /**
     * @dataProvider validSelectionProvider
     */
    public function testValidSelections(Selection $selection): void
    {
        $this->assertSame([], $this->messages($selection));
    }

    /**
     * @return array<string, array{Selection}>
     */
    public static function validSelectionProvider(): array
    {
        return [
            'none' => [new Selection(DecorationMethod::NONE, [], '')],
            'one location' => [new Selection(DecorationMethod::EMBROIDERY, [PrintLocation::LEFT_CHEST], '')],
            'three locations' => [new Selection(
                DecorationMethod::SCREEN_PRINT,
                [PrintLocation::LEFT_CHEST, PrintLocation::RIGHT_CHEST, PrintLocation::SLEEVE],
                ''
            )],
            'upper back with chest' => [new Selection(
                DecorationMethod::EMBROIDERY,
                [PrintLocation::UPPER_BACK, PrintLocation::LEFT_CHEST],
                ''
            )],
            'full back alone' => [new Selection(DecorationMethod::SCREEN_PRINT, [PrintLocation::FULL_BACK], '')],
            'note at max length (multibyte)' => [new Selection(
                DecorationMethod::EMBROIDERY,
                [PrintLocation::SLEEVE],
                str_repeat('é', SelectionValidator::MAX_NOTE_LENGTH)
            )],
        ];
    }

    public function testUnknownMethodIsRejected(): void
    {
        $this->assertSame(
            ['Please choose a valid decoration method.'],
            $this->messages(new Selection('laser', [PrintLocation::SLEEVE], ''))
        );
    }

    public function testLocationIsRequiredWhenMethodIsChosen(): void
    {
        $this->assertSame(
            ['Please choose at least one print location for Embroidery.'],
            $this->messages(new Selection(DecorationMethod::EMBROIDERY, [], ''))
        );
    }

    public function testMoreThanThreeLocationsIsRejected(): void
    {
        $selection = new Selection(
            DecorationMethod::SCREEN_PRINT,
            [PrintLocation::LEFT_CHEST, PrintLocation::RIGHT_CHEST, PrintLocation::SLEEVE, PrintLocation::UPPER_BACK],
            ''
        );

        $this->assertSame(['You can choose up to 3 print locations.'], $this->messages($selection));
    }

    public function testDuplicateLocationIsRejected(): void
    {
        $selection = new Selection(DecorationMethod::EMBROIDERY, [PrintLocation::SLEEVE, PrintLocation::SLEEVE], '');

        $this->assertSame(['Each print location can only be chosen once.'], $this->messages($selection));
    }

    public function testUnknownLocationIsRejected(): void
    {
        $selection = new Selection(DecorationMethod::EMBROIDERY, ['pocket'], '');

        $this->assertSame(['"pocket" is not a valid print location.'], $this->messages($selection));
    }

    /**
     * @dataProvider backConflictProvider
     * @param string[] $locations
     */
    public function testUpperBackAndFullBackCannotBeCombined(array $locations): void
    {
        $this->assertSame(
            ['Upper back and Full back cannot both be selected.'],
            $this->messages(new Selection(DecorationMethod::EMBROIDERY, $locations, ''))
        );
    }

    /**
     * @return array<string, array{string[]}>
     */
    public static function backConflictProvider(): array
    {
        return [
            'pair' => [[PrintLocation::UPPER_BACK, PrintLocation::FULL_BACK]],
            'reversed order' => [[PrintLocation::FULL_BACK, PrintLocation::UPPER_BACK]],
            'with a third location' => [
                [PrintLocation::LEFT_CHEST, PrintLocation::UPPER_BACK, PrintLocation::FULL_BACK],
            ],
        ];
    }

    public function testNoteLongerThanLimitIsRejected(): void
    {
        $selection = new Selection(
            DecorationMethod::EMBROIDERY,
            [PrintLocation::SLEEVE],
            str_repeat('a', SelectionValidator::MAX_NOTE_LENGTH + 1)
        );

        $this->assertSame(
            ['The decoration note cannot be longer than 255 characters.'],
            $this->messages($selection)
        );
    }

    /**
     * @dataProvider markupNoteProvider
     */
    public function testNoteWithMarkupIsRejected(string $note): void
    {
        $this->assertSame(
            ['The decoration note cannot contain the characters < or >.'],
            $this->messages(new Selection(DecorationMethod::EMBROIDERY, [PrintLocation::SLEEVE], $note))
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function markupNoteProvider(): array
    {
        return [
            'tag' => ['<img src=x onerror=1> Logo'],
            'lone less-than' => ['size < 10cm'],
            'lone greater-than' => ['width > 5cm'],
        ];
    }

    public function testNoteWithOtherSpecialCharactersIsAllowed(): void
    {
        $this->assertSame(
            [],
            $this->messages(new Selection(DecorationMethod::EMBROIDERY, [PrintLocation::SLEEVE], 'Logo & "white" 50%'))
        );
    }

    public function testLocationTextAtLimitIsValid(): void
    {
        $selection = new Selection(
            DecorationMethod::EMBROIDERY,
            [PrintLocation::LEFT_CHEST],
            '',
            [PrintLocation::LEFT_CHEST => str_repeat('é', SelectionValidator::MAX_LOCATION_TEXT_LENGTH)]
        );

        $this->assertSame([], $this->messages($selection));
    }

    public function testLocationTextRules(): void
    {
        $selection = new Selection(
            DecorationMethod::EMBROIDERY,
            [PrintLocation::LEFT_CHEST, PrintLocation::FULL_BACK],
            '',
            [
                PrintLocation::LEFT_CHEST => str_repeat('a', SelectionValidator::MAX_LOCATION_TEXT_LENGTH + 1),
                PrintLocation::FULL_BACK => 'TEAM <2026>',
            ]
        );

        $this->assertSame(
            [
                'The text for Left chest cannot be longer than 100 characters.',
                'The text for Full back cannot contain the characters < or >.',
            ],
            $this->messages($selection)
        );
    }

    public function testCompleteEmbroideryAndScreenPrintPass(): void
    {
        $embroidery = new Selection(
            DecorationMethod::EMBROIDERY,
            [PrintLocation::LEFT_CHEST, PrintLocation::SLEEVE],
            '',
            [PrintLocation::LEFT_CHEST => 'ACME', PrintLocation::SLEEVE => '2026']
        );
        $screenPrint = new Selection(
            DecorationMethod::SCREEN_PRINT,
            [PrintLocation::FULL_BACK],
            '',
            [],
            [PrintLocation::FULL_BACK => $this->artwork()]
        );

        $this->assertSame([], $this->selectionValidator->validateCompleteness($embroidery));
        $this->assertSame([], $this->selectionValidator->validateCompleteness($screenPrint));
        $this->assertSame([], $this->selectionValidator->validateCompleteness(new Selection('none', [], '')));
    }

    public function testEmbroideryRequiresTextForEachLocation(): void
    {
        $selection = new Selection(
            DecorationMethod::EMBROIDERY,
            [PrintLocation::LEFT_CHEST, PrintLocation::SLEEVE],
            '',
            [PrintLocation::LEFT_CHEST => 'ACME'],
            [PrintLocation::SLEEVE => $this->artwork()]
        );

        $this->assertSame(
            ['Please enter the text to embroider for Sleeve.'],
            array_map('strval', $this->selectionValidator->validateCompleteness($selection)),
            'Artwork does not stand in for embroidery text.'
        );
    }

    public function testScreenPrintRequiresArtworkForEachLocation(): void
    {
        $selection = new Selection(
            DecorationMethod::SCREEN_PRINT,
            [PrintLocation::LEFT_CHEST, PrintLocation::FULL_BACK],
            '',
            [PrintLocation::FULL_BACK => 'text does not count'],
            [PrintLocation::LEFT_CHEST => $this->artwork()]
        );

        $this->assertSame(
            ['Please upload artwork for Full back.'],
            array_map('strval', $this->selectionValidator->validateCompleteness($selection))
        );
    }

    public function testAssertCompleteThrows(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Please upload artwork for Left chest.');

        $this->selectionValidator->assertComplete(
            new Selection(DecorationMethod::SCREEN_PRINT, [PrintLocation::LEFT_CHEST], '')
        );
    }

    public function testAllBrokenRulesAreReported(): void
    {
        $selection = new Selection(
            DecorationMethod::EMBROIDERY,
            [PrintLocation::UPPER_BACK, PrintLocation::FULL_BACK, PrintLocation::SLEEVE, PrintLocation::SLEEVE],
            ''
        );

        $this->assertSame(
            [
                'You can choose up to 3 print locations.',
                'Each print location can only be chosen once.',
                'Upper back and Full back cannot both be selected.',
            ],
            $this->messages($selection)
        );
    }

    public function testAssertValidThrowsWithMessage(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Please choose at least one print location for Screen Print.');

        $this->selectionValidator->assertValid(new Selection(DecorationMethod::SCREEN_PRINT, [], ''));
    }

    public function testAssertValidPassesForValidSelection(): void
    {
        $this->selectionValidator->assertValid(
            new Selection(DecorationMethod::SCREEN_PRINT, [PrintLocation::SLEEVE], '')
        );

        $this->addToAssertionCount(1);
    }

    /**
     * @return ArtworkFile
     */
    private function artwork(): ArtworkFile
    {
        return new ArtworkFile('inkthread_decoration/artwork/a.png', 'logo.png', 1, 'image/png');
    }

    /**
     * @param Selection $selection
     * @return string[]
     */
    private function messages(Selection $selection): array
    {
        return array_map('strval', $this->selectionValidator->validate($selection));
    }
}
