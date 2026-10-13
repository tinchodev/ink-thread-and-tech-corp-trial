<?php
/**
 * InkThread Decoration Pricing
 */
declare(strict_types=1);

namespace InkThread\DecorationPricing\Test\Unit\Model;

use InkThread\DecorationPricing\Model\FeeCalculator;
use InkThread\DecorationPricing\Model\Selection;
use InkThread\DecorationPricing\Model\Source\DecorationMethod;
use InkThread\DecorationPricing\Model\Source\PrintLocation;
use InkThread\DecorationPricing\Model\TierLine;
use InkThread\DecorationPricing\Model\TierQuantityResolver;
use InkThread\DecorationPricing\Test\Unit\Fixture\ShippedPriceTableProvider;
use PHPUnit\Framework\TestCase;

class TierQuantityResolverTest extends TestCase
{
    private const int TEE = 100;

    private const int HOODIE = 200;

    /**
     * @var TierQuantityResolver
     */
    private TierQuantityResolver $tierQuantityResolver;

    #[\Override]
    protected function setUp(): void
    {
        $this->tierQuantityResolver = new TierQuantityResolver();
    }

    public function testClientExampleSixSmallPlusSixMediumIsTwelve(): void
    {
        $selection = $this->embroidery([PrintLocation::LEFT_CHEST, PrintLocation::SLEEVE]);

        $result = $this->tierQuantityResolver->resolve([
            new TierLine('small', self::TEE, $selection, 6),
            new TierLine('medium', self::TEE, $selection, 6),
        ]);

        $this->assertSame(['small' => 12.0, 'medium' => 12.0], $result);
    }

    public function testSingleLineUsesItsOwnQuantity(): void
    {
        $result = $this->tierQuantityResolver->resolve([
            new TierLine('a', self::TEE, $this->embroidery([PrintLocation::SLEEVE]), 7),
        ]);

        $this->assertSame(['a' => 7.0], $result);
    }

    public function testDifferentMethodIsNotPooled(): void
    {
        $result = $this->tierQuantityResolver->resolve([
            new TierLine('a', self::TEE, $this->embroidery([PrintLocation::SLEEVE]), 6),
            new TierLine('b', self::TEE, new Selection(DecorationMethod::SCREEN_PRINT, [PrintLocation::SLEEVE], ''), 6),
        ]);

        $this->assertSame(['a' => 6.0, 'b' => 6.0], $result);
    }

    public function testDifferentLocationsAreNotPooled(): void
    {
        $result = $this->tierQuantityResolver->resolve([
            new TierLine('a', self::TEE, $this->embroidery([PrintLocation::SLEEVE]), 6),
            new TierLine('b', self::TEE, $this->embroidery([PrintLocation::SLEEVE, PrintLocation::LEFT_CHEST]), 6),
        ]);

        $this->assertSame(['a' => 6.0, 'b' => 6.0], $result);
    }

    public function testDifferentProductsAreNotPooled(): void
    {
        $selection = $this->embroidery([PrintLocation::SLEEVE]);

        $result = $this->tierQuantityResolver->resolve([
            new TierLine('tee', self::TEE, $selection, 6),
            new TierLine('hoodie', self::HOODIE, $selection, 6),
        ]);

        $this->assertSame(['tee' => 6.0, 'hoodie' => 6.0], $result);
    }

    public function testLocationOrderAndNoteDoNotSplitThePool(): void
    {
        $result = $this->tierQuantityResolver->resolve([
            new TierLine('a', self::TEE, $this->embroidery([PrintLocation::SLEEVE, PrintLocation::LEFT_CHEST], 'A'), 5),
            new TierLine('b', self::TEE, $this->embroidery([PrintLocation::LEFT_CHEST, PrintLocation::SLEEVE], 'B'), 7),
        ]);

        $this->assertSame(['a' => 12.0, 'b' => 12.0], $result);
    }

    public function testUndecoratedLinesKeepTheirOwnQuantityAndDoNotPool(): void
    {
        $selection = $this->embroidery([PrintLocation::SLEEVE]);

        $result = $this->tierQuantityResolver->resolve([
            new TierLine('plain', self::TEE, null, 20),
            new TierLine('none', self::TEE, new Selection(DecorationMethod::NONE, [], ''), 30),
            new TierLine('decorated', self::TEE, $selection, 5),
        ]);

        $this->assertSame(['plain' => 20.0, 'none' => 30.0, 'decorated' => 5.0], $result);
    }

    public function testSeveralPoolsInOneCart(): void
    {
        $sleeve = $this->embroidery([PrintLocation::SLEEVE]);
        $selection = new Selection(DecorationMethod::SCREEN_PRINT, [PrintLocation::FULL_BACK], '');

        $result = $this->tierQuantityResolver->resolve([
            new TierLine('t1', self::TEE, $sleeve, 4),
            new TierLine('h1', self::HOODIE, $selection, 50),
            new TierLine('t2', self::TEE, $sleeve, 4),
            new TierLine('h2', self::HOODIE, $selection, 46),
            new TierLine('t3', self::TEE, $sleeve, 4),
        ]);

        $this->assertSame(['t1' => 12.0, 'h1' => 96.0, 't2' => 12.0, 'h2' => 96.0, 't3' => 12.0], $result);
    }

    /**
     * Pooled quantities priced by the calculator across each tier boundary.
     *
     * @dataProvider pooledBoundaryProvider
     */
    public function testPooledBoundariesArePricedAtThePooledTier(
        string $method,
        string $location,
        float $firstQty,
        float $secondQty,
        float $expectedUnitFee
    ): void {
        $selection = new Selection($method, [$location], '');
        $lines = [
            new TierLine('first', self::TEE, $selection, $firstQty),
            new TierLine('second', self::TEE, $selection, $secondQty),
        ];
        $feeCalculator = new FeeCalculator(
            new ShippedPriceTableProvider(),
            new DecorationMethod(),
            new PrintLocation()
        );

        $tierQty = $this->tierQuantityResolver->resolve($lines);

        foreach ($lines as $line) {
            $fee = $feeCalculator->calculate($selection, $line->getQty(), $tierQty[$line->getLineId()]);
            $this->assertEqualsWithDelta($expectedUnitFee, $fee->getUnitFee(), 0.00001);
            $this->assertEqualsWithDelta($expectedUnitFee * $line->getQty(), $fee->getLineFee(), 0.00001);
        }
    }

    /**
     * @return array<string, array{string, string, float, float, float}>
     */
    public static function pooledBoundaryProvider(): array
    {
        $emb = DecorationMethod::EMBROIDERY;
        $scr = DecorationMethod::SCREEN_PRINT;

        return [
            'embroidery 6+5=11' => [$emb, PrintLocation::LEFT_CHEST, 6, 5, 4.00],
            'embroidery 6+6=12' => [$emb, PrintLocation::LEFT_CHEST, 6, 6, 3.25],
            'embroidery 40+7=47' => [$emb, PrintLocation::LEFT_CHEST, 40, 7, 3.25],
            'embroidery 40+8=48' => [$emb, PrintLocation::LEFT_CHEST, 40, 8, 2.50],
            'screen 12+11=23' => [$scr, PrintLocation::FULL_BACK, 12, 11, 4.00],
            'screen 12+12=24' => [$scr, PrintLocation::FULL_BACK, 12, 12, 3.25],
            'screen 48+47=95' => [$scr, PrintLocation::FULL_BACK, 48, 47, 3.25],
            'screen 48+48=96' => [$scr, PrintLocation::FULL_BACK, 48, 48, 2.50],
        ];
    }

    /**
     * @param string[] $locations
     * @param string $note
     * @return Selection
     */
    private function embroidery(array $locations, string $note = ''): Selection
    {
        return new Selection(DecorationMethod::EMBROIDERY, $locations, $note);
    }
}
