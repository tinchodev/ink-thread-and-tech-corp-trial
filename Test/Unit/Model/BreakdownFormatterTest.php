<?php
/**
 * InkThread Decoration Pricing
 */
declare(strict_types=1);

namespace InkThread\DecorationPricing\Test\Unit\Model;

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
    private BreakdownFormatter $formatter;

    /**
     * @var FeeCalculator
     */
    private FeeCalculator $calculator;

    protected function setUp(): void
    {
        $priceCurrency = $this->createMock(PriceCurrencyInterface::class);
        $priceCurrency->method('convertAndFormat')
            ->willReturnCallback(fn ($amount) => '$' . number_format((float)$amount, 2));

        $this->formatter = new BreakdownFormatter(new DecorationMethod(), new PrintLocation(), $priceCurrency);
        $this->calculator = new FeeCalculator(
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
            $this->formatter->format($selection, $this->calculator->calculate($selection, 12))
        );
    }

    public function testPooledTierAndNoteAreShown(): void
    {
        $selection = new Selection(DecorationMethod::EMBROIDERY, [PrintLocation::SLEEVE], 'Logo v2 & "white"');

        $lines = $this->formatter->format($selection, $this->calculator->calculate($selection, 6, 12));

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

    public function testWithoutFeeOnlyTheSelectionIsShown(): void
    {
        $selection = new Selection(
            DecorationMethod::SCREEN_PRINT,
            [PrintLocation::LEFT_CHEST, PrintLocation::FULL_BACK],
            ''
        );

        $this->assertSame(
            [['label' => 'Decoration', 'value' => 'Screen print: Left chest, Full back']],
            $this->formatter->format($selection)
        );
    }
}
