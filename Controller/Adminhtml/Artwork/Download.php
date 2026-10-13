<?php
/**
 * InkThread Decoration Pricing
 */
declare(strict_types=1);

namespace InkThread\DecorationPricing\Controller\Adminhtml\Artwork;

use InkThread\DecorationPricing\Model\Artwork\ArtworkStorage;
use InkThread\DecorationPricing\ViewModel\Adminhtml\OrderArtwork;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\Response\Http\FileFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Api\OrderItemRepositoryInterface;
use Magento\Sales\Model\Order\Item as OrderItem;

/**
 * Download an order item's artwork file (always as an attachment, never rendered inline).
 */
class Download extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'Magento_Sales::actions_view';

    /**
     * @param Context $context
     * @param OrderItemRepositoryInterface $orderItemRepository
     * @param OrderArtwork $orderArtwork
     * @param ArtworkStorage $artworkStorage
     * @param FileFactory $fileFactory
     */
    public function __construct(
        Context $context,
        private readonly OrderItemRepositoryInterface $orderItemRepository,
        private readonly OrderArtwork $orderArtwork,
        private readonly ArtworkStorage $artworkStorage,
        private readonly FileFactory $fileFactory
    ) {
        parent::__construct($context);
    }

    /**
     * @inheritdoc
     */
    #[\Override]
    public function execute()
    {
        $location = (string)$this->getRequest()->getParam('location');
        try {
            $item = $this->orderItemRepository->get((int)$this->getRequest()->getParam('item_id'));
        } catch (NoSuchEntityException) {
            return $this->fail((string)__('This order item no longer exists.'));
        }

        if (!$item instanceof OrderItem) {
            return $this->fail((string)__('This order item no longer exists.'));
        }

        $file = $this->orderArtwork->getArtwork($item)[$location] ?? null;
        if ($file === null || !$this->artworkStorage->exists($file)) {
            return $this->fail((string)__('The artwork file is not available.'), (int)$item->getOrderId());
        }

        return $this->fileFactory->create(
            sprintf('%s_%s_%s', $item->getOrder()->getIncrementId(), $location, $file->getName()),
            ['type' => 'filename', 'value' => $file->getPath(), 'rm' => false],
            DirectoryList::VAR_DIR,
            'application/octet-stream'
        );
    }

    /**
     * Redirect to the order (or the order grid) with an error.
     *
     * @param string $message
     * @param int $orderId
     * @return ResultInterface
     */
    private function fail(string $message, int $orderId = 0): ResultInterface
    {
        $this->messageManager->addErrorMessage($message);
        $redirect = $this->resultRedirectFactory->create();

        return $orderId !== 0
            ? $redirect->setPath('sales/order/view', ['order_id' => $orderId])
            : $redirect->setPath('sales/order/index');
    }
}
