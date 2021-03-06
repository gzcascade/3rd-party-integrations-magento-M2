<?php
/**
 * @category   Emarsys
 * @package    Emarsys_Emarsys
 * @copyright  Copyright (c) 2018 Emarsys. (http://www.emarsys.net/)
 */
namespace Emarsys\Emarsys\Controller\Adminhtml\Customerexport;

use Emarsys\Emarsys\{
    Helper\Data as EmarsysHelperData,
    Model\ResourceModel\Customer,
    Model\EmarsysCronDetails,
    Helper\Cron as EmarsysCronHelper,
    Model\Logs
};
use Magento\{
    Backend\App\Action,
    Framework\Stdlib\DateTime\DateTime,
    Backend\App\Action\Context,
    Store\Model\StoreManagerInterface,
    Framework\Stdlib\DateTime\Timezone,
    Framework\Stdlib\DateTime\TimezoneInterface,
    Framework\App\Request\Http
};

/**
 * Class CustomerExport
 * @package Emarsys\Emarsys\Controller\Adminhtml\Customerexport
 */
class CustomerExport extends Action
{
    const MAX_CUSTOMER_RECORDS = 100000;

    /**
     * @var StoreManagerInterface
     */
    protected $storeManager;

    /**
     * @var Customer
     */
    protected $customerResourceModel;

    /**
     * @var DateTime
     */
    protected $date;

    /**
     * @var Http
     */
    protected $request;

    /**
     * @var Timezone
     */
    protected $timezone;

    /**
     * @var TimezoneInterface
     */
    protected $timezoneInterface;

    /**
     * @var EmarsysHelperData
     */
    protected $emarsysDataHelper;

    /**
     * @var
     */
    protected $messageManager;

    /**
     * @var Logs
     */
    protected $emarsysLogs;

    /**
     * CustomerExport constructor.
     * @param Context $context
     * @param DateTime $date
     * @param StoreManagerInterface $storeManager
     * @param Customer $customerResourceModel
     * @param Timezone $timezone
     * @param TimezoneInterface $timezoneInterface
     * @param Http $request
     * @param EmarsysHelperData $emarsysHelper
     * @param EmarsysCronDetails $emarsysCronDetails
     * @param EmarsysCronHelper $cronHelper
     * @param Logs $emarsysLogs
     */
    public function __construct(
        Context $context,
        DateTime $date,
        StoreManagerInterface $storeManager,
        Customer $customerResourceModel,
        Timezone $timezone,
        TimezoneInterface $timezoneInterface,
        Http $request,
        EmarsysHelperData $emarsysHelper,
        EmarsysCronDetails $emarsysCronDetails,
        EmarsysCronHelper $cronHelper,
        Logs $emarsysLogs
    ) {
        $this->storeManager = $storeManager;
        $this->customerResourceModel = $customerResourceModel;
        $this->date = $date;
        $this->request = $request;
        $this->timezone = $timezone;
        $this->timezoneInterface = $timezoneInterface;
        $this->emarsysDataHelper = $emarsysHelper;
        $this->emarsysCronDetails = $emarsysCronDetails;
        $this->cronHelper = $cronHelper;
        $this->emarsysLogs = $emarsysLogs;
        parent::__construct($context);
    }

    /**
     * @return \Magento\Framework\Controller\Result\Redirect
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function execute()
    {
        try {
            $data = $this->request->getParams();
            $storeId = $data['storeId'];
            $store = $this->storeManager->getStore($storeId);
            $websiteId = $store->getWebsiteId();
            $data['website'] = $websiteId;
            $resultRedirect = $this->resultRedirectFactory->create();
            $returnUrl = $this->getUrl("emarsys_emarsys/customerexport/index", ["store" => $storeId]);

            //check emarsys enable for website
            if ($this->emarsysDataHelper->getEmarsysConnectionSetting($websiteId)) {
                if (isset($data['fromDate']) && $data['fromDate'] != '') {
                    $data['fromDate'] = $this->date->date('Y-m-d', strtotime($data['fromDate'])) . ' 00:00:01';
                }

                if (isset($data['toDate']) && $data['toDate'] != '') {
                    $data['toDate'] = $this->date->date('Y-m-d', strtotime($data['toDate'])) . ' 23:59:59';
                }

                //get customer collection
                $customerCollection = $this->customerResourceModel->getCustomerCollection($data, $storeId);
                if (!empty($customerCollection)) {
                    $cronJobScheduled = false;
                    $cronJobName = '';

                    if ($customerCollection->getSize() <= self::MAX_CUSTOMER_RECORDS) {
                        //export customers through API
                        $isCronjobScheduled = $this->cronHelper->checkCronjobScheduled(EmarsysCronHelper::CRON_JOB_CUSTOMER_BULK_EXPORT_API, $storeId);
                        if (!$isCronjobScheduled) {
                            //no cron job scheduled yet, schedule a new cron job
                            $cron = $this->cronHelper->scheduleCronjob(EmarsysCronHelper::CRON_JOB_CUSTOMER_BULK_EXPORT_API, $storeId);
                            $cronJobScheduled = true;
                            $cronJobName = EmarsysCronHelper::CRON_JOB_CUSTOMER_BULK_EXPORT_API;
                        }
                    } else {
                        //export customers through WebDav
                        $isCronjobScheduled = $this->cronHelper->checkCronjobScheduled(EmarsysCronHelper::CRON_JOB_CUSTOMER_BULK_EXPORT_WEBDAV, $storeId);
                        if (!$isCronjobScheduled) {
                            $cron = $this->cronHelper->scheduleCronjob(EmarsysCronHelper::CRON_JOB_CUSTOMER_BULK_EXPORT_WEBDAV, $storeId);
                            $cronJobScheduled = true;
                            $cronJobName = EmarsysCronHelper::CRON_JOB_CUSTOMER_BULK_EXPORT_WEBDAV;
                        }
                    }

                    if ($cronJobScheduled) {
                        //format and encode data in json to be saved in the table
                        $params = $this->cronHelper->getFormattedParams($data);

                        //save details in cron details table
                        $this->emarsysCronDetails->addEmarsysCronDetails($cron->getScheduleId(), $params);

                        $this->messageManager->addSuccessMessage(
                            __(
                                'A cron named "%1" have been scheduled for customers export for the store %2.',
                                $cronJobName,
                                $store->getName()
                            ));
                    } else {
                        //cron job already scheduled
                        $this->messageManager->addErrorMessage(__('A cron is already scheduled to export customers for the store %1 ', $store->getName()));
                    }
                } else {
                    //no customer found for the store
                    $this->messageManager->addErrorMessage(__('No Customers Found for the Store %1.', $store->getName()));
                }
            } else {
                //emarsys is disabled for this website
                $this->messageManager->addErrorMessage(__('Emarsys is disabled for the website %1', $websiteId));
            }
        } catch (\Exception $e) {
            //add exception to logs
            $this->emarsysLogs->addErrorLog(
                $e->getMessage(),
                $this->storeManager->getStore()->getId(),
                'CustomerExport::execute()'
            );
            //report error
            $this->messageManager->addErrorMessage(__('There was a problem while customer export. %1', $e->getMessage()));
        }

        return $resultRedirect->setPath($returnUrl);
    }
}
