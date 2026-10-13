<?php
/**
 * InkThread Decoration Pricing
 */
declare(strict_types=1);

namespace InkThread\DecorationPricing\Test\Unit\Fixture;

use InkThread\DecorationPricing\Api\PriceTableProviderInterface;
use InkThread\DecorationPricing\Model\Config\PriceTableParser;
use InkThread\DecorationPricing\Model\Source\PrintLocation;

/**
 * Price tables read from the module's own etc/config.xml defaults, so tests also prove the shipped defaults
 * match the brief.
 */
class ShippedPriceTableProvider implements PriceTableProviderInterface
{
    /**
     * @var array<string, array<string, list<array{min_qty: int, price: float}>>>
     */
    private array $tables = [];

    /**
     * Load etc/config.xml defaults.
     */
    public function __construct()
    {
        // phpcs:ignore Magento2.Functions.DiscouragedFunction
        $config = simplexml_load_file(dirname(__DIR__, 3) . '/etc/config.xml');
        $parser = new PriceTableParser(new PrintLocation());
        foreach ($config->default->inkthread_decoration->pricing->children() as $method => $value) {
            $this->tables[$method] = $parser->parse(json_decode((string)$value, true, 512, JSON_THROW_ON_ERROR));
        }
    }

    /**
     * @inheritdoc
     */
    public function getTiers(string $method, string $location): array
    {
        return $this->tables[$method][$location] ?? [];
    }
}
