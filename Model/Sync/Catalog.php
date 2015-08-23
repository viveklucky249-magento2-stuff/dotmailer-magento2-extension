<?php

namespace Dotdigitalgroup\Email\Model\Sync;

class Catalog
{

	protected $_helper;
	protected $_resource;
	protected $_scopeConfig;
	protected $_objectManager;

	private $_start;
	private $_countProducts = 0;
	private $_productIds;

	public function __construct(
		\Magento\Framework\App\Resource $resource,
		\Dotdigitalgroup\Email\Helper\Data $helper,
		\Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
		\Magento\Framework\ObjectManagerInterface $objectManager
	)
	{
		$this->_helper = $helper;
		$this->_resource = $resource;
		$this->_scopeConfig = $scopeConfig;
		$this->_objectManager = $objectManager;
	}
	/**
	 *
	 * catalog sync
	 *
	 * @return array
	 */
	public function sync()
	{
		$response = array('success' => true, 'message' => '');
		$this->_start = microtime(true);
		$proccessorModel = $this->_objectManager->create('Dotdigitalgroup\Email\Model\Proccessor');


		//resource allocation
		$this->_helper->allowResourceFullExecution();
		$enabled = $this->_helper->isEnabled();
		$catalogSyncEnabled = $this->_helper->getCatalogSyncEnabled();
		//api and catalog sync enabled
		if ($enabled && $catalogSyncEnabled) {
			$this->_helper->log('---------- Start catalog sync ----------');

			//remove product with product id set and no product
			$write = $this->_resource->getConnection('core_write');
			$catalogTable = $this->_resource->getTableName('email_catalog');
			$select = $write->select();
			$select->reset()
			       ->from(
				       array('c' => $catalogTable),
				       array('c.product_id')
			       )
			       ->joinLeft(
				       array('e' => $this->_resource->getTableName('catalog_product_entity')),
				       "c.product_id = e.entity_id"
			       )
			       ->where('e.entity_id is NULL');
			//delete sql statement
			$deleteSql = $select->deleteFromSelect('c');
			//run query
			$write->query($deleteSql);
			$scope = $this->_scopeConfig->getValue(\Dotdigitalgroup\Email\Helper\Config::XML_PATH_CONNECTOR_SYNC_CATALOG_VALUES);

			//if only to pull default value
			if($scope == 1){
				$products = $this->_exportCatalog(\Magento\Store\Model\Store::DEFAULT_STORE_ID);
				if ($products) {
					//register in queue with importer
					$check = $proccessorModel->registerQueue(
						\Dotdigitalgroup\Email\Model\Proccessor::IMPORT_TYPE_CATALOG,
						$products,
						\Dotdigitalgroup\Email\Model\Proccessor::MODE_BULK,
						\Magento\Store\Model\Store::DEFAULT_STORE_ID
					);

					//set imported
					if ($check)
						$this->_setImported($this->_productIds);

					//set number of product imported
					$this->_countProducts += count($products);
				}
				//using single api
				$this->_exportInSingle(\Magento\Store\Model\Store::DEFAULT_STORE_ID, 'Catalog_Default', \Magento\Store\Model\Store::DEFAULT_STORE_ID);
				//if to pull store values. will be pulled for each store
			}elseif($scope == 2){
				$stores = $this->_helper->getStores();
				foreach($stores as $store){
					$websiteCode = $store->getWebsite()->getCode();
					$storeCode = $store->getCode();
					$products = $this->_exportCatalog($store);
					if($products){
						$proccessorModel = $this->_objectManager->create('Dotdigitalgroup\Email\Model\Proccessor');
						//register in queue with importer
						$check = $proccessorModel->registerQueue(
							'Catalog_' . $websiteCode . '_' . $storeCode,
							$products,
							\Dotdigitalgroup\Email\Model\Proccessor::MODE_BULK,
							$store->getWebsite()->getId()
						);
						//set imported
						if ($check)
							$this->_setImported($this->_productIds);

						//set number of product imported
						$this->_countProducts += count($products);
					}
					//using single api
					$this->_exportInSingle($store, 'Catalog_' . $websiteCode . '_' . $storeCode, $store->getWebsite()->getId());
				}
			}
		}

		if ($this->_countProducts) {
			$message = 'Total time for sync : ' . gmdate( "H:i:s", microtime( true ) - $this->_start ) . ', Total synced = ' . $this->_countProducts;
			$this->_helper->log( $message );
			$response['message'] = $message;
		}
		return $response;
	}


	/**
	 * export catalog
	 *
	 * @param $store
	 * @return array|bool
	 */
	private function _exportCatalog($store)
	{
		$products = $this->_getProductsToExport($store);
		if($products){
			$this->_productIds = $products->getColumnValues('entity_id');
			$connectorProducts = array();
			foreach($products as $product){

				$connectorProduct = $this->_objectManager->create('Dotdigitalgroup\Email\Model\Connector\Product', ['product' => $product]);
				$connectorProducts[] = $connectorProduct;
			}
			return $connectorProducts;
		}
		return false;
	}

	/**
	 * export in single
	 *
	 * @param $store
	 * @param $collectionName
	 * @param $websiteId
	 */
	private function _exportInSingle($store, $collectionName, $websiteId)
	{
		$helper = Mage::helper('ddg');
		$this->_productIds = array();

		$products = $this->_getProductsToExport($store, true);
		if($products){
			foreach($products as $product){
				$connectorProduct = Mage::getModel('ddg_automation/connector_product', $product);
				$helper->log('---------- Start catalog single sync ----------');

				//register in queue with importer
				$check = Mage::getModel('ddg_automation/importer')->registerQueue(
					$collectionName,
					$connectorProduct,
					Dotdigitalgroup_Email_Model_Importer::MODE_SINGLE,
					$websiteId
				);

				if ($check) {
					$this->_productIds[] = $product->getId();
				}
			}

			if(!empty($this->_productIds)){
				$this->_setImported($this->_productIds, true);
				$this->_countProducts += count($this->_productIds);
			}
		}
	}

	/**
	 * get product collection
	 *
	 * @param $store
	 * @param $modified
	 * @return bool|Mage_Catalog_Model_Resource_Product_Collection
	 */
	private function _getProductsToExport($store, $modified = false)
	{
		$limit = Mage::getStoreConfig(Dotdigitalgroup_Email_Helper_Config::XML_PATH_CONNECTOR_TRANSACTIONAL_DATA_SYNC_LIMIT);
		$connectorCollection = $this->getCollection();

		if($modified)
			$connectorCollection->addFieldToFilter('modified', array('eq' => '1'));
		else
			$connectorCollection->addFieldToFilter('imported', array('null' => 'true'));

		$connectorCollection->setPageSize($limit);

		if($connectorCollection->getSize()) {
			$product_ids = $connectorCollection->getColumnValues('product_id');
			$productCollection = Mage::getModel('catalog/product')->getCollection();
			$productCollection
				->addAttributeToSelect('*')
				->addStoreFilter($store)
				->addAttributeToFilter('entity_id', array('in' => $product_ids));

			//visibility filter
			if($visibility = Mage::getStoreConfig(
				Dotdigitalgroup_Email_Helper_Config::XML_PATH_CONNECTOR_SYNC_CATALOG_VISIBILITY)){
				$visibility = explode(',', $visibility);
				$productCollection->addAttributeToFilter('visibility', array('in' => $visibility));
			}

			//type filter
			if($type = Mage::getStoreConfig(
				Dotdigitalgroup_Email_Helper_Config::XML_PATH_CONNECTOR_SYNC_CATALOG_TYPE)){
				$type = explode(',', $type);
				$productCollection->addAttributeToFilter('type_id', array('in' => $type));
			}

			$productCollection
				->addWebsiteNamesToResult()
				->addFinalPrice()
				->addCategoryIds()
				->addOptionsToResult();

			return $productCollection;
		}
		return false;
	}

	/**
	 * Reset for re-import.
	 *
	 * @return int
	 */
	public function reset()
	{
		/** @var $coreResource Mage_Core_Model_Resource */
		$coreResource = Mage::getSingleton('core/resource');

		/** @var $conn Varien_Db_Adapter_Pdo_Mysql */
		$conn = $coreResource->getConnection('core_write');
		try{
			$num = $conn->update($coreResource->getTableName('ddg_automation/catalog'),
				array('imported' => new Zend_Db_Expr('null'), 'modified' => new Zend_Db_Expr('null'))
			);
		}catch (Exception $e){
			Mage::logException($e);
		}
		return $num;
	}

	/**
	 * product save after event processor
	 *
	 * @param Varien_Event_Observer $observer
	 */
	public function handleProductSaveAfter(Varien_Event_Observer $observer)
	{
		try{
			$object = $observer->getEvent()->getDataObject();
			$productId = $object->getId();
			if($item = $this->_loadProduct($productId)){
				if($item->getImported())
					$item->setModified(1)->save();
			}
		}catch (Exception $e){
			Mage::logException($e);
		}
	}

	/**
	 * product delete after event processor
	 *
	 * @param Varien_Event_Observer $observer
	 */
	public function handleProductDeleteAfter(Varien_Event_Observer $observer)
	{
		try{
			/** @var $object Mage_Catalog_Model_Product */
			$object = $observer->getEvent()->getDataObject();
			$productId = $object->getId();
			if($item = $this->_loadProduct($productId)){
				//if imported delete from account
				if($item->getImported()){
					$this->_deleteFromAccount($productId);
				}
				//delete from table
				$item->delete();

			}
		}catch (Exception $e){
			Mage::logException($e);
		}
	}

	/**
	 * delete piece of transactional data by key
	 *
	 * @param $key
	 */
	private function _deleteFromAccount($key)
	{
		$enabled = Mage::getStoreConfig(Dotdigitalgroup_Email_Helper_Config::XML_PATH_CONNECTOR_API_ENABLED);
		$sync = Mage::getStoreConfig(Dotdigitalgroup_Email_Helper_Config::XML_PATH_CONNECTOR_SYNC_CATALOG_ENABLED);
		if($enabled && $sync){
			$scope = Mage::getStoreConfig(Dotdigitalgroup_Email_Helper_Config::XML_PATH_CONNECTOR_SYNC_CATALOG_VALUES);
			if($scope == 1){
				//register in queue with importer
				Mage::getModel('ddg_automation/importer')->registerQueue(
					Dotdigitalgroup_Email_Model_Importer::IMPORT_TYPE_CATALOG,
					array($key),
					Dotdigitalgroup_Email_Model_Importer::MODE_SINGLE_DELETE,
					Mage_Catalog_Model_Abstract::DEFAULT_STORE_ID
				);
			}
			if($scope == 2){
				$stores = Mage::app()->getStores();
				/** @var $store Mage_Core_Model_Store */
				foreach($stores as $store){
					$websiteCode = $store->getWebsite()->getCode();
					$storeCode = $store->getCode();

					//register in queue with importer
					Mage::getModel('ddg_automation/importer')->registerQueue(
						'Catalog_' . $websiteCode . '_' . $storeCode,
						array($key),
						Dotdigitalgroup_Email_Model_Importer::MODE_SINGLE_DELETE,
						$store->getWebsite()->getId()
					);
				}
			}
		}
	}

	/**
	 * load product. return item otherwise create item
	 *
	 * @param $productId
	 * @return bool|Varien_Object
	 */
	private function _loadProduct($productId)
	{
		$collection = $this->getCollection()
		                   ->addFieldToFilter('product_id', $productId)
		                   ->setPageSize(1);

		if ($collection->getSize()) {
			return $collection->getFirstItem();
		} else {
			$this->setProductId($productId)->save();
		}
		return false;
	}

	/**
	 * set imported in bulk query. if modified true then set modified to null in bulk query.
	 *
	 * @param $ids
	 * @param $modified
	 */
	private function _setImported($ids, $modified = false)
	{
		try{
			$coreResource = Mage::getSingleton('core/resource');
			$write = $coreResource->getConnection('core_write');
			$tableName = $coreResource->getTableName('email_catalog');
			$ids = implode(', ', $ids);
			$now = Mage::getSingleton('core/date')->gmtDate();
			if($modified)
				$write->update($tableName, array('modified' => new Zend_Db_Expr('null'), 'updated_at' => $now), "product_id IN ($ids)");
			else
				$write->update($tableName, array('imported' => 1, 'updated_at' => $now), "product_id IN ($ids)");
		}catch (Exception $e){
			Mage::logException($e);
		}
	}

	/**
	 * core config data save before event
	 *
	 * @param Varien_Event_Observer $observer
	 * @return $this
	 */
	public function handleConfigSaveBefore(Varien_Event_Observer $observer)
	{
		if (!Mage::registry('core_config_data_save_before')){
			if($groups = $observer->getEvent()->getConfigData()->getGroups()){
				if(isset($groups['catalog_sync']['fields']['catalog_values']['value'])){
					$value = $groups['catalog_sync']['fields']['catalog_values']['value'];
					Mage::register('core_config_data_save_before', $value);
				}
			}
		}


		if (!Mage::registry('core_config_data_save_before_status')) {
			if ($groups = $observer->getEvent()->getConfigData()->getGroups()) {
				if (isset($groups['data_fields']['fields']['order_statuses']['value'])) {
					$value = $groups['data_fields']['fields']['order_statuses']['value'];
					Mage::register('core_config_data_save_before_status', $value);
				}
			}
		}


		return $this;
	}

	/**
	 * core config data save after event
	 *
	 * @param Varien_Event_Observer $observer
	 * @return $this
	 */
	public function handleConfigSaveAfter(Varien_Event_Observer $observer)
	{
		try{
			if(!Mage::registry('core_config_data_save_after_done')){
				if($groups = $observer->getEvent()->getConfigData()->getGroups()){
					if(isset($groups['catalog_sync']['fields']['catalog_values']['value'])){
						$configAfter = $groups['catalog_sync']['fields']['catalog_values']['value'];
						$configBefore = Mage::registry('core_config_data_save_before');
						if($configAfter != $configBefore){
							//reset catalog to re-import
							$this->reset();
						}
						Mage::register('core_config_data_save_after_done', true);
					}
				}
			}

			if (!Mage::registry('core_config_data_save_after_done_status')) {
				if ($groups = $observer->getEvent()->getConfigData()->getGroups()) {
					if (isset($groups['data_fields']['fields']['order_statuses']['value'])) {
						$configAfter = $groups['data_fields']['fields']['order_statuses']['value'];
						$configBefore = Mage::registry('core_config_data_save_before_status');
						if ($configAfter != $configBefore) {
							//reset all contacts
							Mage::getModel('ddg_automation/contact')->resetAllContacts();
						}
						Mage::register('core_config_data_save_after_done_status', true);
					}
				}
			}
		}catch (Exception $e){
			Mage::logException($e);
		}
		return $this;
	}
}