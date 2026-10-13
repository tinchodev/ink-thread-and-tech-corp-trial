<?php
/**
 * InkThread Decoration Pricing
 */
declare(strict_types=1);

namespace InkThread\DecorationPricing\Test\Unit\Model\Config;

use InkThread\DecorationPricing\Model\Config\PriceTableParser;
use InkThread\DecorationPricing\Model\Config\PriceTableProvider;
use InkThread\DecorationPricing\Model\Source\PrintLocation;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Serialize\Serializer\Json;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class PriceTableProviderTest extends TestCase
{
    /**
     * @var ScopeConfigInterface&MockObject
     */
    private ScopeConfigInterface $scopeConfig;

    /**
     * @var LoggerInterface&MockObject
     */
    private LoggerInterface $logger;

    /**
     * @var PriceTableProvider
     */
    private PriceTableProvider $priceTableProvider;

    #[\Override]
    protected function setUp(): void
    {
        $this->scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->priceTableProvider = new PriceTableProvider(
            $this->scopeConfig,
            new Json(),
            new PriceTableParser(new PrintLocation()),
            $this->logger
        );
    }

    public function testReadsTiersFromConfigAndCachesPerMethod(): void
    {
        $this->scopeConfig->expects($this->once())
            ->method('getValue')
            ->with('inkthread_decoration/pricing/embroidery')
            ->willReturn(json_encode([
                '_1' => ['location' => 'sleeve', 'min_qty' => '12', 'price' => '2.75'],
                '_2' => ['location' => 'sleeve', 'min_qty' => '1', 'price' => '3.50'],
            ]));

        $this->assertSame(
            [['min_qty' => 1, 'price' => 3.5], ['min_qty' => 12, 'price' => 2.75]],
            $this->priceTableProvider->getTiers('embroidery', 'sleeve')
        );
        $this->assertSame([], $this->priceTableProvider->getTiers('embroidery', 'full_back'));
    }

    public function testMissingConfigGivesNoTiers(): void
    {
        $this->scopeConfig->method('getValue')->willReturn(null);
        $this->logger->expects($this->never())->method('error');

        $this->assertSame([], $this->priceTableProvider->getTiers('embroidery', 'sleeve'));
    }

    /**
     * @dataProvider corruptConfigProvider
     */
    public function testCorruptConfigGivesNoTiersAndIsLogged(string $value): void
    {
        $this->scopeConfig->method('getValue')->willReturn($value);
        $this->logger->expects($this->once())->method('error');

        $this->assertSame([], $this->priceTableProvider->getTiers('embroidery', 'sleeve'));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function corruptConfigProvider(): array
    {
        return [
            'invalid json' => ['{not json'],
            'invalid row' => [json_encode(['_1' => ['location' => 'sleeve', 'min_qty' => '0', 'price' => '1']])],
        ];
    }
}
