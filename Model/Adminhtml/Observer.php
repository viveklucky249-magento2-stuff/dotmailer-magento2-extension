<?php

namespace Dotdigitalgroup\Email\Model\Adminhtml;


class Observer
{

	protected $_helper;
	protected $_context;
	protected $_storeManager;
	protected $messageManager;
	protected $_objectManager;

	public function __construct(
		\Dotdigitalgroup\Email\Helper\Data $data,
		\Magento\Backend\App\Action\Context $context,
		\Magento\Store\Model\StoreManagerInterface $storeManagerInterface,
		\Magento\Framework\ObjectManagerInterface $objectManagerInterface
	)
	{
		$this->_helper = $data;
		$this->_context = $context;
		$this->_storeManager = $storeManagerInterface;
		$this->messageManager = $context->getMessageManager();
		$this->_objectManager = $objectManagerInterface;
	}
    /**
     * API Sync and Data Mapping.
     * Reset contacts for reimport.
     * @return $this
     */
    public function actionConfigResetContacts()
    {
	    $contactModel = $this->_objectManager->create('Dotdigitalgroup\Email\Model\Resource\Contact');
        $numImported = $this->_objectManager->create('Dotdigitalgroup\Email\Model\Contact')->getNumberOfImportedContacs();
        $updated = $contactModel->resetAllContacts();

        $this->_helper->log('-- Imported contacts: ' . $numImported  . ' reseted :  ' . $updated . ' --');

        return $this;
    }

    /**
     * Check if the transactional data feature is enabled
     * To use the wishlist and order sync this needs to be enabled.
     */
    public function checkFeatureActive()
    {
        //scope to retrieve the website id
        $scopeId = 0;
	    $request = $this->_context->getRequest();
        if ($website = $request->getParam('website')) {
            //use webiste
            $scope = 'websites';
            $scopeId = $this->_storeManager->getWebsite($website)->getId();
        } else {
            //set to default
            $scope = "default";
        }
        //webiste by id
        $website = $this->_storeManager->getWebsite($scopeId);

        //configuration saved for the wishlist and order sync
        $wishlistEnabled = $website->getConfig(\Dotdigitalgroup\Email\Helper\Config::XML_PATH_CONNECTOR_SYNC_WISHLIST_ENABLED, $scope, $scopeId);
        $orderEnabled = $website->getConfig(\Dotdigitalgroup\Email\Helper\Config::XML_PATH_CONNECTOR_SYNC_ORDER_ENABLED);

        //only for modification for order and wishlist
        if ($orderEnabled || $wishlistEnabled) {
            //client by website id
            $client = $this->_helper->getWebsiteApiClient($scopeId);

            //call request for account info
            $response = $client->getAccountInfo();

            //properties must be checked
            if (isset($response->properties)) {
                $accountInfo = $response->properties;
                $result = $this->_checkForOption(\Dotdigitalgroup\Email\Model\Apiconnector\Client::API_ERROR_TRANS_ALLOWANCE, $accountInfo);

                //account is disabled to use transactional data
                if (! $result) {
                    $message = 'Transactional Data For This Account Is Disabled. Call Support To Enable.';
                    //send admin message
                    $this->messageManager->addError($message);
                    //disable the config for wishlist and order sync
	                $this->_helper->disableTransactionalDataConfig($scope, $scopeId);
                }
            }
        }

        return $this;

    }

    /**
     * API Credentials.
     * Installation and validation confirmation.
     * @return $this
     */
    public function actionConfigSaveApi(\Magento\Framework\Event\Observer $observer)
    {
	    return $this;

	    //@todo fix get the request
	    $groups = $this->_context->getRequest()->getPost('groups');

        if (isset($groups['api']['fields']['username']['inherit']) || isset($groups['api']['fields']['password']['inherit']))
            return $this;

        $apiUsername =  isset($groups['api']['fields']['username']['value'])? $groups['api']['fields']['username']['value'] : false;
        $apiPassword =  isset($groups['api']['fields']['password']['value'])? $groups['api']['fields']['password']['value'] : false;

        //skip if the inherit option is selected
        if ($apiUsername && $apiPassword) {
            $this->_helper->log('----VALIDATING ACCOUNT---');
            $testModel = $this->_objectManager->create('Dotdigitalgroup\Email\Model\Apiconnector\Test');
            $isValid = $testModel->validate($apiUsername, $apiPassword);
            if ($isValid) {
                /**
                 * Send install info
                 */
                //$testModel->sendInstallConfirmation();
            } else {
                /**
                 * Disable invalid Api credentials
                 */
                $scopeId = 0;
                if ($website = Mage::app()->getRequest()->getParam('website')) {
                    $scope = 'websites';
                    $scopeId = Mage::app()->getWebsite($website)->getId();
                } else {
                    $scope = "default";
                }
                $config = Mage::getConfig();
                $config->saveConfig(Dotdigitalgroup_Email_Helper_Config::XML_PATH_CONNECTOR_API_ENABLED, 0, $scope, $scopeId);
                $config->cleanCache();
            }
            Mage::getSingleton('adminhtml/session')->addSuccess(Mage::helper('ddg')->__('API Credentials Valid.'));
        }
        return $this;
    }

    /**
     * Check for name option in array.
     *
     * @param $name
     * @param $data
     *
     * @return bool
     */
    private function _checkForOption($name, $data) {
        //loop for all options
        foreach ( $data as $one ) {

            if ($one->name == $name) {
                return true;
            }
        }

        return false;
    }

    /**
     * Update Feed for latest releases.
     *
     */
    public function updateFeed()
    {
        Mage::getModel('ddg_automation/feed')->checkForUpgrade();
    }


	/**
	 * Add modified segment for contact.
	 * @param $observer
	 *
	 * @return $this
	 */
	public function connectorCustomerSegmentChanged($observer)
	{
		$segmentsIds = $observer->getEvent()->getSegmentIds();
		$customerId = Mage::getSingleton('customer/session')->getCustomerId();
		$websiteId = Mage::app()->getStore()->getWebsiteId();

		if (!empty($segmentsIds) && $customerId) {
			$this->addContactsFromWebsiteSegments($customerId, $segmentsIds, $websiteId);
		}

		return $this;
	}


	/**
	 * Add segment ids.
	 * @param $customerId
	 * @param $segmentIds
	 * @param $websiteId
	 *
	 * @return $this
	 */
	protected function addContactsFromWebsiteSegments($customerId, $segmentIds, $websiteId){

		if (empty($segmentIds))
			return;
		$segmentIds = implode(',', $segmentIds);

		$contact = Mage::getModel('ddg_automation/contact')->getCollection()
			->addFieldToFilter('customer_id', $customerId)
			->addFieldToFilter('website_id', $websiteId)
			->getFirstItem();
		try {

			$contact->setSegmentIds($segmentIds)
			        ->setEmailImported()
			        ->save();

		}catch (Exception $e){
			Mage::logException($e);
		}

		return $this;
	}

	protected function getCustomerSegmentIdsForWebsite($customerId, $websiteId){
		$segmentIds = Mage::getModel('ddg_automation/contact')->getCollection()
			->addFieldToFilter('website_id', $websiteId)
			->addFieldToFilter('customer_id', $customerId)
			->getFirstItem()
			->getSegmentIds();

		return $segmentIds;
	}
}