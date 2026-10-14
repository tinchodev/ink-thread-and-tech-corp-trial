<?php
/**
 * InkThread Decoration Pricing
 */
declare(strict_types=1);

namespace InkThread\DecorationPricing\Test\Unit\ViewModel;

use InkThread\DecorationPricing\Api\PriceTableProviderInterface;
use InkThread\DecorationPricing\Model\Artwork\ArtworkToken;
use InkThread\DecorationPricing\Model\SelectionFactory;
use InkThread\DecorationPricing\Model\Source\DecorationMethod;
use InkThread\DecorationPricing\Model\Source\PrintLocation;
use InkThread\DecorationPricing\Test\Unit\Fixture\ShippedPriceTableProvider;
use InkThread\DecorationPricing\ViewModel\DecorationOptions;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Framework\Serialize\Serializer\Json;
use PHPUnit\Framework\TestCase;

class DecorationOptionsTest extends TestCase
{
    public function testJsConfigCarriesShippedTiersForThePriceBox(): void
    {
        $config = $this->getJsConfig(new ShippedPriceTableProvider(), 1.0);

        // JSON drops the ".0" of whole prices, so compare loosely.
        $this->assertEquals(
            [['min_qty' => 1, 'price' => 4.0], ['min_qty' => 12, 'price' => 3.25], ['min_qty' => 48, 'price' => 2.5]],
            $config['priceTables'][DecorationMethod::EMBROIDERY][PrintLocation::LEFT_CHEST]
        );
        $this->assertEquals(
            [['min_qty' => 1, 'price' => 4.0], ['min_qty' => 24, 'price' => 3.25], ['min_qty' => 96, 'price' => 2.5]],
            $config['priceTables'][DecorationMethod::SCREEN_PRINT][PrintLocation::FULL_BACK]
        );
        $this->assertArrayNotHasKey(DecorationMethod::NONE, $config['priceTables']);
    }

    public function testTierPricesAreConvertedToTheDisplayCurrency(): void
    {
        $config = $this->getJsConfig(new ShippedPriceTableProvider(), 2.0);

        // JSON drops the ".0" of whole prices, so compare loosely.
        $this->assertEquals(
            [['min_qty' => 1, 'price' => 7.0], ['min_qty' => 12, 'price' => 5.5], ['min_qty' => 48, 'price' => 4.0]],
            $config['priceTables'][DecorationMethod::EMBROIDERY][PrintLocation::SLEEVE]
        );
    }

    public function testUnpricedLocationsAreNeitherOfferedNorPriced(): void
    {
        $provider = $this->createMock(PriceTableProviderInterface::class);
        $provider->method('getTiers')->willReturnCallback(
            fn (string $method, string $location): array => $method === DecorationMethod::EMBROIDERY
                && $location === PrintLocation::SLEEVE ? [['min_qty' => 12, 'price' => 2.75]] : []
        );

        $config = $this->getJsConfig($provider, 1.0);

        $this->assertSame([DecorationMethod::EMBROIDERY => [PrintLocation::SLEEVE]], $config['offeredLocations']);
        $this->assertEquals(
            [DecorationMethod::EMBROIDERY => [PrintLocation::SLEEVE => [['min_qty' => 12, 'price' => 2.75]]]],
            $config['priceTables']
        );
    }

    /**
     * Decoded widget config, with a currency rate applied by the price currency mock.
     *
     * @param PriceTableProviderInterface $priceTableProvider
     * @param float $rate
     * @return array<string, mixed>
     */
    private function getJsConfig(PriceTableProviderInterface $priceTableProvider, float $rate): array
    {
        $priceCurrency = $this->createMock(PriceCurrencyInterface::class);
        $priceCurrency->method('convert')->willReturnCallback(fn ($amount): float => (float)$amount * $rate);

        $decorationOptions = new DecorationOptions(
            new DecorationMethod(),
            new PrintLocation(),
            $priceTableProvider,
            $this->createMock(SelectionFactory::class),
            $this->createMock(ArtworkToken::class),
            new Json(),
            $priceCurrency
        );

        return json_decode($decorationOptions->getJsConfig(), true, 512, JSON_THROW_ON_ERROR);
    }
}
