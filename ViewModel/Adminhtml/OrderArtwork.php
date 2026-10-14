<?php
/**
 * InkThread Decoration Pricing
 */
declare(strict_types=1);

namespace InkThread\DecorationPricing\ViewModel\Adminhtml;

use InkThread\DecorationPricing\Model\Artwork\ArtworkFile;
use InkThread\DecorationPricing\Model\Artwork\ArtworkFileMapper;
use InkThread\DecorationPricing\Model\Artwork\ArtworkThumbnail;
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
    private const array TYPE_LABELS = ['image/png' => 'PNG', 'image/jpeg' => 'JPG', 'application/pdf' => 'PDF'];

    /**
     * @param PrintLocation $printLocation
     * @param UrlInterface $backendUrl
     * @param ArtworkFileMapper $artworkFileMapper
     * @param ArtworkThumbnail $artworkThumbnail
     */
    public function __construct(
        private readonly PrintLocation $printLocation,
        private readonly UrlInterface $backendUrl,
        private readonly ArtworkFileMapper $artworkFileMapper,
        private readonly ArtworkThumbnail $artworkThumbnail
    ) {
    }

    /**
     * Rows: item, SKU, location, file name, size, type label, download URL and preview URL (null for PDFs).
     *
     * @param Order $order
     * @return list<array{
     *     item: string, sku: string, location: string, name: string, size: int, url: string, type: string,
     *     thumbnail: ?string
     * }>
     */
    public function getRows(Order $order): array
    {
        $rows = [];
        foreach ($order->getAllVisibleItems() as $item) {
            foreach ($this->getArtwork($item) as $location => $file) {
                $params = ['item_id' => $item->getItemId(), 'location' => $location];
                $rows[] = [
                    'item' => (string)$item->getName(),
                    'sku' => (string)$item->getSku(),
                    'location' => $this->printLocation->getLabel($location),
                    'name' => $file->getName(),
                    'size' => $file->getSize(),
                    'url' => $this->backendUrl->getUrl('inkthread_decoration/artwork/download', $params),
                    'type' => self::TYPE_LABELS[$file->getMimeType()] ?? '',
                    'thumbnail' => $this->artworkThumbnail->supports($file)
                        ? $this->backendUrl->getUrl('inkthread_decoration/artwork/thumbnail', $params)
                        : null,
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
