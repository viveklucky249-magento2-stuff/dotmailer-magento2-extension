<?php

namespace Dotdigitalgroup\Email\Model\Config\Configuration;

class Catalogtype
{
    /**
     * @var \Magento\Catalog\Model\Product\Type
     */
    protected $_productType;

    /**
     * Catalogtype constructor.
     *
     * @param \Magento\Catalog\Model\Product\Type $productType
     */
    public function __construct(
        \Magento\Catalog\Model\Product\Type $productType
    ) {
        $this->_productType = $productType;
    }

    /**
     * Return options.
     *
     * @return mixed
     */
    public function toOptionArray()
    {
        $options
            = $this->_productType->getAllOptions();

        if (is_array($options)) {
            $options[0]['value'] = '0';
            $options[0]['label'] = '---- Default Option ----';
        }
        
        return $options;
    }
}
