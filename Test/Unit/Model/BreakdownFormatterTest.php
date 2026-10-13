<?php
/**
 * InkThread Decoration Pricing
 */
declare(strict_types=1);

namespace InkThread\DecorationPricing\Test\Unit\Model;

use InkThread\DecorationPricing\Model\Artwork\ArtworkFile;
use InkThread\DecorationPricing\Model\BreakdownFormatter;
use InkThread\DecorationPricing\Model\FeeCalculator;
use InkThread\DecorationPricing\Model\Selection;
use InkThread\DecorationPricing\Model\Source\DecorationMethod;
use InkThread\DecorationPricing\Model\Source\PrintLocation;
use InkThread\DecorationPricing\Test\Unit\Fixture\ShippedPriceTableProvider;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use PHPUnit\Framework\TestCase;

class BreakdownFormatterTest extends TestCase
{
    /**
     * @var BreakdownFormatter
     */
    private BreakdownFormatter $breakdownFormatter;

    /**
     * @var FeeCalculator
     */
    private FeeCalculator $feeCalculator;

    #[\Override]
    protected function setUp(): void
    {
        $priceCurrency = $this->createMock(PriceCurrencyInterface::class);
        $priceCurrency->method('convertAndFormat')
            ->willReturnCallback(fn ($amount): string => '$' . number_format((float)$amount, 2));

        $this->breakdownFormatter = new BreakdownFormatter(new DecorationMethod(), new PrintLocation(), $priceCurrency);
        $this->feeCalculator = new FeeCalculator(
            new ShippedPriceTableProvider(),
            new DecorationMethod(),
            new PrintLocation()
        );
    }

    public function testBriefWorkedExampleBreakdown(): void
    {
        $selection = new Selection(
            DecorationMethod::EMBROIDERY,
            [PrintLocation::LEFT_CHEST, PrintLocation::SLEEVE],
            ''
        );

        $this->assertSame(
            [
                ['label' => 'Decoration', 'value' => 'Embroidery'],
                ['label' => 'Left chest', 'value' => '$3.25 / unit'],
                ['label' => 'Sleeve', 'value' => '$2.75 / unit'],
                ['label' => 'Decoration fee', 'value' => '$6.00 / unit × 12 = $72.00'],
            ],
            $this->breakdownFormatter->format($selection, $this->feeCalculator->calculate($selection, 12))
        );
    }

    public function testPooledTierAndNoteAreShown(): void
    {
        $selection = new Selection(DecorationMethod::EMBROIDERY, [PrintLocation::SLEEVE], 'Logo v2 & "white"');

        $lines = $this->breakdownFormatter->format($selection, $this->feeCalculator->calculate($selection, 6, 12));

        $this->assertSame(
            ['label' => 'Decoration fee', 'value' => '$2.75 / unit × 6 = $16.50 (tier for 12 matching units)'],
            $lines[2]
        );
        $this->assertSame(
            ['label' => 'Decoration note', 'value' => 'Logo v2 & "white"'],
            $lines[3],
            'Note is passed through as plain text; markup is refused at input by SelectionValidator.'
        );
    }

    public function testEmbroideryTextIsShownOnTheLocationLine(): void
    {
        $selection = new Selection(
            DecorationMethod::EMBROIDERY,
            [PrintLocation::LEFT_CHEST, PrintLocation::SLEEVE],
            '',
            [PrintLocation::LEFT_CHEST => 'ACME', PrintLocation::SLEEVE => '2026']
        );

        $lines = $this->breakdownFormatter->format($selection, $this->feeCalculator->calculate($selection, 12));

        $this->assertSame(['label' => 'Left chest', 'value' => '$3.25 / unit · Text: "ACME"'], $lines[1]);
        $this->assertSame(['label' => 'Sleeve', 'value' => '$2.75 / unit · Text: "2026"'], $lines[2]);
    }

    public function testScreenPrintArtworkIsShownOnTheLocationLine(): void
    {
        $selection = new Selection(
            DecorationMethod::SCREEN_PRINT,
            [PrintLocation::FULL_BACK],
            '',
            [],
            [
                PrintLocation::FULL_BACK => new ArtworkFile(
                    'inkthread_decoration/artwork/a.pdf',
                    'back.pdf',
                    1,
                    'application/pdf'
                ),
            ]
        );

        $lines = $this->breakdownFormatter->format($selection, $this->feeCalculator->calculate($selection, 100));

        $this->assertSame(['label' => 'Full back', 'value' => '$2.50 / unit · Artwork: back.pdf'], $lines[1]);
    }

    public function testWithoutFeeOnlyTheSelectionIsShown(): void
    {
        $selection = new Selection(
            DecorationMethod::SCREEN_PRINT,
            [PrintLocation::LEFT_CHEST, PrintLocation::FULL_BACK],
            ''
        );

        $this->assertSame(
            [['label' => 'Decoration', 'value' => 'Screen print: Left chest, Full back']],
            $this->breakdownFormatter->format($selection)
        );
    }
}
