<?php
/**
 * InkThread Decoration Pricing
 */
declare(strict_types=1);

namespace InkThread\DecorationPricing\Block\Adminhtml\Form\Field;

use InkThread\DecorationPricing\Model\Source\PrintLocation;
use Magento\Framework\View\Element\Context;
use Magento\Framework\View\Element\Html\Select;

/**
 * Print location select used inside the price table dynamic rows.
 */
class LocationColumn extends Select
{
    /**
     * @param Context $context
     * @param PrintLocation $printLocation
     * @param array<string,mixed> $data
     */
    public function __construct(
        Context $context,
        private readonly PrintLocation $printLocation,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * Set the input name (called by AbstractFieldArray).
     *
     * @param string $value
     * @return $this
     */
    public function setInputName(string $value): self
    {
        return $this->setData('name', $value);
    }

    /**
     * Set the input id (called by AbstractFieldArray).
     *
     * @param string $value
     * @return $this
     */
    public function setInputId(string $value): self
    {
        return $this->setId($value);
    }

    /**
     * @inheritdoc
     */
    #[\Override]
    protected function _toHtml(): string
    {
        if (!$this->getOptions()) {
            $this->setOptions($this->printLocation->toOptionArray());
        }

        return parent::_toHtml();
    }
}
