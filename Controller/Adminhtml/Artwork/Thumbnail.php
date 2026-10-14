<?php
/**
 * InkThread Decoration Pricing
 */
declare(strict_types=1);

namespace InkThread\DecorationPricing\Controller\Adminhtml\Artwork;

use InkThread\DecorationPricing\Model\Artwork\ArtworkThumbnail;
use InkThread\DecorationPricing\ViewModel\Adminhtml\OrderArtwork;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\Raw;
use Magento\Framework\Controller\Result\RawFactory;
use Magento\Framework\Exception\InputException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Api\OrderItemRepositoryInterface;
use Magento\Sales\Model\Order\Item as OrderItem;

/**
 * Preview of an order item's PNG/JPEG artwork (re-encoded by ArtworkThumbnail); 404 when there is none.
 */
class Thumbnail extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'Magento_Sales::actions_view';

    /**
     * @param Context $context
     * @param OrderItemRepositoryInterface $orderItemRepository
     * @param OrderArtwork $orderArtwork
     * @param ArtworkThumbnail $artworkThumbnail
     * @param RawFactory $rawFactory
     */
    public function __construct(
        Context $context,
        private readonly OrderItemRepositoryInterface $orderItemRepository,
        private readonly OrderArtwork $orderArtwork,
        private readonly ArtworkThumbnail $artworkThumbnail,
        private readonly RawFactory $rawFactory
    ) {
        parent::__construct($context);
    }

    /**
     * @inheritdoc
     */
    #[\Override]
    public function execute(): Raw
    {
        $result = $this->rawFactory->create();
        $result->setHeader('X-Content-Type-Options', 'nosniff', true);

        try {
            $item = $this->orderItemRepository->get((int)$this->getRequest()->getParam('item_id'));
        } catch (NoSuchEntityException | InputException) {
            $item = null;
        }

        $file = $item instanceof OrderItem
            ? $this->orderArtwork->getArtwork($item)[(string)$this->getRequest()->getParam('location')] ?? null
            : null;
        $thumbnail = $file !== null ? $this->artworkThumbnail->get($file) : null;
        if ($file === null || $thumbnail === null) {
            return $result->setHttpResponseCode(404)->setHeader('Content-Type', 'text/plain', true)->setContents('');
        }

        return $result->setHeader('Content-Type', $file->getMimeType(), true)
            ->setHeader('Cache-Control', 'private, max-age=86400', true)
            ->setContents($thumbnail);
    }
}
