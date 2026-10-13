<?php
/**
 * InkThread Decoration Pricing
 */
declare(strict_types=1);

namespace InkThread\DecorationPricing\Block\Adminhtml\Form\Field;

use Magento\Config\Block\System\Config\Form\Field\FieldArray\AbstractFieldArray;
use Magento\Framework\DataObject;
use Magento\Framework\Exception\LocalizedException;

/**
 * Dynamic rows: print location / minimum quantity / unit price.
 */
class PriceTable extends AbstractFieldArray
{
    /**
     * @var LocationColumn|null
     */
    private ?LocationColumn $locationColumn = null;

    /**
     * @inheritdoc
     */
    #[\Override]
    protected function _prepareToRender()
    {
        $this->addColumn('location', [
            'label' => __('Print location'),
            'renderer' => $this->getLocationRenderer(),
        ]);
        $this->addColumn('min_qty', [
            'label' => __('From quantity'),
            'class' => 'required-entry validate-digits validate-greater-than-zero',
        ]);
        $this->addColumn('price', [
            'label' => __('Unit price'),
            'class' => 'required-entry validate-number validate-zero-or-greater',
        ]);
        $this->_addAfter = false;
        $this->_addButtonLabel = (string)__('Add tier');
    }

    /**
     * Pre-select the stored location in each row.
     *
     * @param DataObject $dataObject
     * @return void
     * @throws LocalizedException
     */
    #[\Override]
    protected function _prepareArrayRow(DataObject $dataObject): void
    {
        $options = [];
        $location = $dataObject->getData('location');
        if ($location !== null) {
            $options['option_' . $this->getLocationRenderer()->calcOptionHash($location)] = 'selected="selected"';
        }

        $dataObject->setData('option_extra_attrs', $options);
    }

    /**
     * Lazily create the location select renderer.
     *
     * @return LocationColumn
     * @throws LocalizedException
     */
    private function getLocationRenderer(): LocationColumn
    {
        if ($this->locationColumn === null) {
            /** @var LocationColumn $renderer */
            $renderer = $this->getLayout()->createBlock(
                LocationColumn::class,
                '',
                ['data' => ['is_render_to_js_template' => true]]
            );
            $this->locationColumn = $renderer;
        }

        return $this->locationColumn;
    }
}
