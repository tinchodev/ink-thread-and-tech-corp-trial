<?php
/**
 * InkThread Decoration Pricing
 */
declare(strict_types=1);

namespace InkThread\DecorationPricing\Test\Unit\Model\Config;

use InkThread\DecorationPricing\Exception\InvalidPriceTableException;
use InkThread\DecorationPricing\Model\Config\PriceTableParser;
use InkThread\DecorationPricing\Model\Source\PrintLocation;
use PHPUnit\Framework\TestCase;

class PriceTableParserTest extends TestCase
{
    /**
     * @var PriceTableParser
     */
    private PriceTableParser $parser;

    protected function setUp(): void
    {
        $this->parser = new PriceTableParser(new PrintLocation());
    }

    public function testRowsAreCastAndSortedByMinQty(): void
    {
        $result = $this->parser->parse([
            '_c' => ['location' => 'sleeve', 'min_qty' => '48', 'price' => '2.00'],
            '_a' => ['location' => 'sleeve', 'min_qty' => '1', 'price' => '3.50'],
            '_b' => ['location' => 'sleeve', 'min_qty' => ' 12 ', 'price' => '2.75'],
            '_d' => ['location' => 'full_back', 'min_qty' => '1', 'price' => '0'],
        ]);

        $this->assertSame(
            [
                'sleeve' => [
                    ['min_qty' => 1, 'price' => 3.5],
                    ['min_qty' => 12, 'price' => 2.75],
                    ['min_qty' => 48, 'price' => 2.0],
                ],
                'full_back' => [['min_qty' => 1, 'price' => 0.0]],
            ],
            $result
        );
    }

    public function testEmptyAndNonArrayRowsAreSkipped(): void
    {
        $result = $this->parser->parse([
            '__empty' => '',
            '_a' => ['location' => '', 'min_qty' => '', 'price' => ''],
            '_b' => ['location' => 'sleeve', 'min_qty' => '1', 'price' => '1'],
        ]);

        $this->assertSame(['sleeve' => [['min_qty' => 1, 'price' => 1.0]]], $result);
    }

    public function testEmptyInputGivesEmptyTable(): void
    {
        $this->assertSame([], $this->parser->parse([]));
    }

    public function testAllInvalidRowsAreReportedWithRowNumbers(): void
    {
        try {
            $this->parser->parse([
                ['location' => 'pocket', 'min_qty' => '1', 'price' => '1'],
                ['location' => 'sleeve', 'min_qty' => '0', 'price' => '1'],
                ['location' => 'sleeve', 'min_qty' => '1.5', 'price' => '1'],
                ['location' => 'sleeve', 'min_qty' => '1', 'price' => '-0.01'],
                ['location' => 'sleeve', 'min_qty' => '2', 'price' => 'abc'],
                ['location' => 'sleeve', 'min_qty' => '3', 'price' => '1'],
                ['location' => 'sleeve', 'min_qty' => '3', 'price' => '2'],
            ]);
            $this->fail('Expected InvalidPriceTableException');
        } catch (InvalidPriceTableException $e) {
            $this->assertSame(
                [
                    'Row 1: choose a valid print location.',
                    'Row 2: minimum quantity must be a whole number of 1 or more.',
                    'Row 3: minimum quantity must be a whole number of 1 or more.',
                    'Row 4: unit price must be a number of 0 or more.',
                    'Row 5: unit price must be a number of 0 or more.',
                    'Row 7: Sleeve already has a price for minimum quantity 3.',
                ],
                array_map('strval', $e->getErrors())
            );
        }
    }
}
