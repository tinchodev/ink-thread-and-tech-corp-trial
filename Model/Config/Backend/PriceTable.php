<?php
/**
 * InkThread Decoration Pricing
 */
declare(strict_types=1);

namespace InkThread\DecorationPricing\Model\Config\Backend;

use InkThread\DecorationPricing\Exception\InvalidPriceTableException;
use InkThread\DecorationPricing\Model\Config\PriceTableParser;
use Magento\Config\Model\Config\Backend\Serialized\ArraySerialized;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Model\Context;
use Magento\Framework\Model\ResourceModel\AbstractResource;
use Magento\Framework\Registry;
use Magento\Framework\Serialize\Serializer\Json;

/**
 * Validates a decoration price table before it is saved.
 */
class PriceTable extends ArraySerialized
{
    /**
     * @param Context $context
     * @param Registry $registry
     * @param ScopeConfigInterface $config
     * @param TypeListInterface $cacheTypeList
     * @param PriceTableParser $priceTableParser
     * @param AbstractResource|null $resource
     * @param AbstractDb|null $resourceCollection
     * @param array<string,mixed> $data
     * @param Json|null $serializer
     */
    public function __construct(
        Context $context,
        Registry $registry,
        ScopeConfigInterface $config,
        TypeListInterface $cacheTypeList,
        private readonly PriceTableParser $priceTableParser,
        ?AbstractResource $resource = null,
        ?AbstractDb $resourceCollection = null,
        array $data = [],
        ?Json $serializer = null
    ) {
        parent::__construct(
            $context,
            $registry,
            $config,
            $cacheTypeList,
            $resource,
            $resourceCollection,
            $data,
            $serializer
        );
    }

    /**
     * Reject the save when any row is invalid.
     *
     * @return $this
     * @throws LocalizedException
     */
    public function beforeSave()
    {
        // getValue() is documented as string, but the admin form posts an array of rows.
        $value = $this->getData('value');
        if (is_array($value)) {
            unset($value['__empty']);
            try {
                $this->priceTableParser->parse($value);
            } catch (InvalidPriceTableException $e) {
                throw new LocalizedException(
                    __('%1 price table: %2', $this->getData('field_config/label'), $e->getMessage())
                );
            }
        }

        return parent::beforeSave();
    }

    /**
     * Flag active carts for recollection when prices changed.
     *
     * Core does the same for catalog price changes: open carts then show the new unit price instead of a stale one
     * the next time they are loaded.
     *
     * @return $this
     */
    public function afterSave()
    {
        if ($this->isValueChanged()) {
            $this->markActiveQuotesForRecollect();
        }

        return parent::afterSave();
    }

    /**
     * Same when the table is reset to its default ("Use system value" deletes the stored value).
     *
     * @return $this
     */
    public function afterDelete()
    {
        $this->markActiveQuotesForRecollect();

        return parent::afterDelete();
    }

    /**
     * Set trigger_recollect on all active quotes.
     *
     * @return void
     */
    private function markActiveQuotesForRecollect(): void
    {
        $this->getResource()->getConnection()->update(
            $this->getResource()->getTable('quote'),
            ['trigger_recollect' => 1],
            ['is_active = ?' => 1]
        );
    }
}
