<?php
/**
 * InkThread Decoration Pricing
 */
declare(strict_types=1);

namespace InkThread\DecorationPricing\ViewModel\Adminhtml;

use InkThread\DecorationPricing\Model\Artwork\ArtworkFile;
use InkThread\DecorationPricing\Model\Artwork\ArtworkFileMapper;
use InkThread\DecorationPricing\Model\Source\PrintLocation;
use InkThread\DecorationPricing\Plugin\CopyDecorationToOrderItem;
use Magento\Backend\Model\UrlInterface;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Item as OrderItem;

/**
 * Artwork files of an order, per item and location, for the admin order view.
 */
class OrderArtwork implements ArgumentInterface
{
    /**
     * @param PrintLocation $printLocation
     * @param UrlInterface $backendUrl
     * @param ArtworkFileMapper $artworkFileMapper
     */
    public function __construct(
        private readonly PrintLocation $printLocation,
        private readonly UrlInterface $backendUrl,
        private readonly ArtworkFileMapper $artworkFileMapper
    ) {
    }

    /**
     * Rows: item name, SKU, location label, file name, size and download URL.
     *
     * @param Order $order
     * @return list<array{item: string, sku: string, location: string, name: string, size: int, url: string}>
     */
    public function getRows(Order $order): array
    {
        $rows = [];
        foreach ($order->getAllVisibleItems() as $item) {
            foreach ($this->getArtwork($item) as $location => $file) {
                $rows[] = [
                    'item' => (string)$item->getName(),
                    'sku' => (string)$item->getSku(),
                    'location' => $this->printLocation->getLabel($location),
                    'name' => $file->getName(),
                    'size' => $file->getSize(),
                    'url' => $this->backendUrl->getUrl(
                        'inkthread_decoration/artwork/download',
                        ['item_id' => $item->getItemId(), 'location' => $location]
                    ),
                ];
            }
        }

        return $rows;
    }

    /**
     * Artwork in an order item's decoration snapshot.
     *
     * @param OrderItem $orderItem
     * @return array<string,ArtworkFile>
     */
    public function getArtwork(OrderItem $orderItem): array
    {
        $snapshot = $orderItem->getProductOptions()[CopyDecorationToOrderItem::SNAPSHOT_KEY] ?? null;
        $stored = is_array($snapshot) && is_array($snapshot['artwork'] ?? null) ? $snapshot['artwork'] : [];
        $artwork = [];
        foreach ($stored as $loc => $data) {
            $file = $this->artworkFileMapper->fromArray($data);
            if ($file instanceof ArtworkFile) {
                $artwork[(string)$loc] = $file;
            }
        }

        return $artwork;
    }
}
