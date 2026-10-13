<?php
/**
 * InkThread Decoration Pricing
 */
declare(strict_types=1);

namespace InkThread\DecorationPricing\Model\Config;

use InkThread\DecorationPricing\Api\PriceTableProviderInterface;
use InkThread\DecorationPricing\Exception\InvalidPriceTableException;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Psr\Log\LoggerInterface;

/**
 * Reads price tables from system configuration (inkthread_decoration/pricing/<method>).
 *
 * A missing or corrupt table yields no tiers, so the calculator refuses the decoration instead of charging $0.
 */
class PriceTableProvider implements PriceTableProviderInterface
{
    private const string XML_PATH_PREFIX = 'inkthread_decoration/pricing/';

    /**
     * @var array<string, array<string, list<array{min_qty: int, price: float}>>>
     */
    private array $tables = [];

    /**
     * @param ScopeConfigInterface $scopeConfig
     * @param Json $json
     * @param PriceTableParser $priceTableParser
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly Json $json,
        private readonly PriceTableParser $priceTableParser,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @inheritdoc
     */
    #[\Override]
    public function getTiers(string $method, string $location): array
    {
        if (!isset($this->tables[$method])) {
            $this->tables[$method] = $this->loadTable($method);
        }

        return $this->tables[$method][$location] ?? [];
    }

    /**
     * Load and parse one method's table.
     *
     * @param string $method
     * @return array<string, list<array{min_qty: int, price: float}>>
     */
    private function loadTable(string $method): array
    {
        $value = $this->scopeConfig->getValue(self::XML_PATH_PREFIX . $method);
        if (!is_string($value) || $value === '') {
            return [];
        }

        try {
            $rows = $this->json->unserialize($value);
            return is_array($rows) ? $this->priceTableParser->parse($rows) : [];
        } catch (InvalidPriceTableException | \InvalidArgumentException $e) {
            $this->logger->error(
                'InkThread_DecorationPricing: invalid price table for "' . $method . '": ' . $e->getMessage()
            );
            return [];
        }
    }
}
