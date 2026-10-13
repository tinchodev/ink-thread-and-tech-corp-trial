<?php
/**
 * InkThread Decoration Pricing
 */
declare(strict_types=1);

namespace InkThread\DecorationPricing\Test\Unit\Model;

use InkThread\DecorationPricing\Api\PriceTableProviderInterface;
use InkThread\DecorationPricing\Exception\PricingNotConfiguredException;
use InkThread\DecorationPricing\Model\FeeCalculator;
use InkThread\DecorationPricing\Model\Selection;
use InkThread\DecorationPricing\Model\Source\DecorationMethod;
use InkThread\DecorationPricing\Model\Source\PrintLocation;
use InkThread\DecorationPricing\Test\Unit\Fixture\ShippedPriceTableProvider;
use PHPUnit\Framework\TestCase;

class FeeCalculatorTest extends TestCase
{
    private const float DELTA = 0.00001;

    /**
     * @var FeeCalculator
     */
    private FeeCalculator $feeCalculator;

    #[\Override]
    protected function setUp(): void
    {
        $this->feeCalculator = $this->createCalculator(new ShippedPriceTableProvider());
    }

    public function testBriefWorkedExampleEmbroidery(): void
    {
        $result = $this->feeCalculator->calculate(
            new Selection(DecorationMethod::EMBROIDERY, [PrintLocation::LEFT_CHEST, PrintLocation::SLEEVE], ''),
            12
        );

        $this->assertEqualsWithDelta(
            [PrintLocation::LEFT_CHEST => 3.25, PrintLocation::SLEEVE => 2.75],
            $result->getLocationPrices(),
            self::DELTA
        );
        $this->assertEqualsWithDelta(6.00, $result->getUnitFee(), self::DELTA);
        $this->assertEqualsWithDelta(72.00, $result->getLineFee(), self::DELTA);
    }

    public function testBriefWorkedExampleScreenPrint(): void
    {
        $result = $this->feeCalculator->calculate(
            new Selection(DecorationMethod::SCREEN_PRINT, [PrintLocation::FULL_BACK], ''),
            100
        );

        $this->assertEqualsWithDelta(2.50, $result->getUnitFee(), self::DELTA);
        $this->assertEqualsWithDelta(250.00, $result->getLineFee(), self::DELTA);
    }

    /**
     * @dataProvider tierBoundaryProvider
     */
    public function testTierBoundaries(string $method, string $location, float $qty, float $expectedUnitPrice): void
    {
        $result = $this->feeCalculator->calculate(new Selection($method, [$location], ''), $qty);

        $this->assertEqualsWithDelta($expectedUnitPrice, $result->getUnitFee(), self::DELTA);
        $this->assertEqualsWithDelta($expectedUnitPrice * $qty, $result->getLineFee(), self::DELTA);
    }

    /**
     * @return array<string, array{string, string, float, float}>
     */
    public static function tierBoundaryProvider(): array
    {
        $emb = DecorationMethod::EMBROIDERY;
        $scr = DecorationMethod::SCREEN_PRINT;

        return [
            'embroidery left chest 1' => [$emb, PrintLocation::LEFT_CHEST, 1, 4.00],
            'embroidery left chest 11' => [$emb, PrintLocation::LEFT_CHEST, 11, 4.00],
            'embroidery left chest 12' => [$emb, PrintLocation::LEFT_CHEST, 12, 3.25],
            'embroidery left chest 47' => [$emb, PrintLocation::LEFT_CHEST, 47, 3.25],
            'embroidery left chest 48' => [$emb, PrintLocation::LEFT_CHEST, 48, 2.50],
            'embroidery right chest 12' => [$emb, PrintLocation::RIGHT_CHEST, 12, 3.25],
            'embroidery sleeve 11' => [$emb, PrintLocation::SLEEVE, 11, 3.50],
            'embroidery sleeve 48' => [$emb, PrintLocation::SLEEVE, 48, 2.00],
            'embroidery upper back 47' => [$emb, PrintLocation::UPPER_BACK, 47, 4.25],
            'embroidery upper back 48' => [$emb, PrintLocation::UPPER_BACK, 48, 3.50],
            'embroidery full back 11' => [$emb, PrintLocation::FULL_BACK, 11, 7.50],
            'embroidery full back 12' => [$emb, PrintLocation::FULL_BACK, 12, 6.50],
            'embroidery full back 10000' => [$emb, PrintLocation::FULL_BACK, 10000, 5.50],
            'screen left chest 1' => [$scr, PrintLocation::LEFT_CHEST, 1, 2.00],
            'screen left chest 23' => [$scr, PrintLocation::LEFT_CHEST, 23, 2.00],
            'screen left chest 24' => [$scr, PrintLocation::LEFT_CHEST, 24, 1.50],
            'screen left chest 95' => [$scr, PrintLocation::LEFT_CHEST, 95, 1.50],
            'screen left chest 96' => [$scr, PrintLocation::LEFT_CHEST, 96, 1.00],
            'screen sleeve 23' => [$scr, PrintLocation::SLEEVE, 23, 1.75],
            'screen sleeve 24' => [$scr, PrintLocation::SLEEVE, 24, 1.25],
            'screen sleeve 96' => [$scr, PrintLocation::SLEEVE, 96, 0.90],
            'screen upper back 95' => [$scr, PrintLocation::UPPER_BACK, 95, 2.00],
            'screen upper back 96' => [$scr, PrintLocation::UPPER_BACK, 96, 1.50],
            'screen full back 23' => [$scr, PrintLocation::FULL_BACK, 23, 4.00],
            'screen full back 24' => [$scr, PrintLocation::FULL_BACK, 24, 3.25],
        ];
    }

    public function testThreeLocationsAreSummed(): void
    {
        $result = $this->feeCalculator->calculate(
            new Selection(
                DecorationMethod::EMBROIDERY,
                [PrintLocation::LEFT_CHEST, PrintLocation::SLEEVE, PrintLocation::FULL_BACK],
                ''
            ),
            48
        );

        $this->assertEqualsWithDelta(2.50 + 2.00 + 5.50, $result->getUnitFee(), self::DELTA);
        $this->assertEqualsWithDelta(10.00 * 48, $result->getLineFee(), self::DELTA);
    }

    public function testLeftAndRightChestAreChargedAsTwoLocations(): void
    {
        $result = $this->feeCalculator->calculate(
            new Selection(DecorationMethod::SCREEN_PRINT, [PrintLocation::LEFT_CHEST, PrintLocation::RIGHT_CHEST], ''),
            24
        );

        $this->assertEqualsWithDelta(3.00, $result->getUnitFee(), self::DELTA);
    }

    public function testPooledTierPricesLineAtPooledQuantity(): void
    {
        $selection = new Selection(
            DecorationMethod::EMBROIDERY,
            [PrintLocation::LEFT_CHEST, PrintLocation::SLEEVE],
            ''
        );

        $result = $this->feeCalculator->calculate($selection, 6, 12);

        $this->assertEqualsWithDelta(6.00, $result->getUnitFee(), self::DELTA);
        $this->assertEqualsWithDelta(36.00, $result->getLineFee(), self::DELTA);
        $this->assertTrue($result->isPooled());
        $this->assertEqualsWithDelta(6, $result->getLineQty(), self::DELTA);
        $this->assertEqualsWithDelta(12, $result->getTierQty(), self::DELTA);
    }

    public function testPooledQuantityBelowBoundaryStaysInLowerTier(): void
    {
        $selection = new Selection(
            DecorationMethod::EMBROIDERY,
            [PrintLocation::LEFT_CHEST, PrintLocation::SLEEVE],
            ''
        );

        $result = $this->feeCalculator->calculate($selection, 6, 11);

        $this->assertEqualsWithDelta(4.00 + 3.50, $result->getUnitFee(), self::DELTA);
        $this->assertEqualsWithDelta(45.00, $result->getLineFee(), self::DELTA);
    }

    public function testUnpooledLineIsNotFlaggedAsPooled(): void
    {
        $result = $this->feeCalculator->calculate(
            new Selection(DecorationMethod::EMBROIDERY, [PrintLocation::SLEEVE], ''),
            12
        );

        $this->assertFalse($result->isPooled());
    }

    public function testNoDecorationHasNoFee(): void
    {
        $result = $this->feeCalculator->calculate(new Selection(DecorationMethod::NONE, [], ''), 50);

        $this->assertSame([], $result->getLocationPrices());
        $this->assertSame(0.0, $result->getUnitFee());
        $this->assertSame(0.0, $result->getLineFee());
    }

    public function testUnconfiguredLocationFailsClosed(): void
    {
        $feeCalculator = $this->createCalculator($this->createProvider([]));

        $this->expectException(PricingNotConfiguredException::class);
        $this->expectExceptionMessage('Sleeve is not available for Embroidery.');

        $feeCalculator->calculate(new Selection(DecorationMethod::EMBROIDERY, [PrintLocation::SLEEVE], ''), 12);
    }

    public function testQuantityBelowLowestTierFailsClosed(): void
    {
        $feeCalculator = $this->createCalculator($this->createProvider([
            DecorationMethod::EMBROIDERY => [
                PrintLocation::SLEEVE => [['min_qty' => 12, 'price' => 2.75]],
            ],
        ]));

        $this->expectException(PricingNotConfiguredException::class);
        $this->expectExceptionMessage('Embroidery on Sleeve requires a minimum quantity of 12.');

        $feeCalculator->calculate(new Selection(DecorationMethod::EMBROIDERY, [PrintLocation::SLEEVE], ''), 11);
    }

    public function testAssertOfferedPassesWhenEveryLocationHasATable(): void
    {
        $this->feeCalculator->assertOffered(
            new Selection(DecorationMethod::EMBROIDERY, [PrintLocation::LEFT_CHEST, PrintLocation::FULL_BACK], '')
        );
        $this->feeCalculator->assertOffered(new Selection(DecorationMethod::NONE, [], ''));

        $this->addToAssertionCount(1);
    }

    public function testAssertOfferedRejectsLocationWithoutTable(): void
    {
        $feeCalculator = $this->createCalculator($this->createProvider([
            DecorationMethod::SCREEN_PRINT => [
                PrintLocation::SLEEVE => [['min_qty' => 1, 'price' => 1.75]],
            ],
        ]));

        $this->expectException(PricingNotConfiguredException::class);
        $this->expectExceptionMessage('Full back is not available for Screen print.');

        $feeCalculator->assertOffered(
            new Selection(DecorationMethod::SCREEN_PRINT, [PrintLocation::SLEEVE, PrintLocation::FULL_BACK], '')
        );
    }

    public function testNonPositiveQuantityIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->feeCalculator->calculate(new Selection(DecorationMethod::EMBROIDERY, [PrintLocation::SLEEVE], ''), 0);
    }

    public function testToArrayContainsSnapshotData(): void
    {
        $result = $this->feeCalculator->calculate(
            new Selection(DecorationMethod::EMBROIDERY, [PrintLocation::SLEEVE], 'Logo v2'),
            5,
            12
        );

        $this->assertSame(
            [
                'method' => DecorationMethod::EMBROIDERY,
                'locations' => [PrintLocation::SLEEVE],
                'note' => 'Logo v2',
                'location_texts' => [],
                'artwork' => [],
                'location_prices' => [PrintLocation::SLEEVE => 2.75],
                'unit_fee' => 2.75,
                'line_qty' => 5.0,
                'tier_qty' => 12.0,
                'line_fee' => 13.75,
            ],
            $result->toArray()
        );
    }

    /**
     * @param PriceTableProviderInterface $priceTableProvider
     * @return FeeCalculator
     */
    private function createCalculator(PriceTableProviderInterface $priceTableProvider): FeeCalculator
    {
        return new FeeCalculator($priceTableProvider, new DecorationMethod(), new PrintLocation());
    }

    /**
     * @param array<string, array<string, list<array{min_qty: int, price: float}>>> $tables
     * @return PriceTableProviderInterface
     */
    private function createProvider(array $tables): PriceTableProviderInterface
    {
        return new class($tables) implements PriceTableProviderInterface {
            /**
             * @param array<string, array<string, list<array{min_qty: int, price: float}>>> $tables
             */
            public function __construct(private readonly array $tables)
            {
            }

            /**
             * @inheritdoc
             */
            public function getTiers(string $method, string $location): array
            {
                return $this->tables[$method][$location] ?? [];
            }
        };
    }
}
