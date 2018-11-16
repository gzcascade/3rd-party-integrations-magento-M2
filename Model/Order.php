<?php
/**
 * @category   Emarsys
 * @package    Emarsys_Emarsys
 * @copyright  Copyright (c) 2017 Emarsys. (http://www.emarsys.net/)
 */

namespace Emarsys\Emarsys\Model;

use Magento\Framework\Model\AbstractModel;
use Magento\Framework\Model\Context;
use Magento\Framework\Registry;
use Magento\Framework\Message\ManagerInterface as MessageManagerInterface;
use Magento\Framework\Model\ResourceModel\AbstractResource;
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Emarsys\Emarsys\Helper\Logs as EmarsysHelperLogs;
use Emarsys\Emarsys\Model\ResourceModel\Customer as EmarsysResourceModelCustomer;
use Magento\Store\Model\StoreManagerInterface;
use Emarsys\Emarsys\Helper\Data as EmarsysDataHelper;
use Emarsys\Emarsys\Model\ResourceModel\Order as OrderResourceModel;
use Magento\Sales\Model\Order\CreditmemoRepository;
use Magento\Sales\Model\OrderFactory as MagentoOrderFactory;
use Magento\Store\Model\ScopeInterface;
use Magento\Framework\Stdlib\DateTime\Timezone as TimeZone;
use Magento\Framework\App\Filesystem\DirectoryList;

/**
 * Class Order
 * @package Emarsys\Emarsys\Model
 */
class Order extends AbstractModel
{
    /**
     * @var StoreManagerInterface
     */
    protected $storeManager;

    /**
     * @var MessageManagerInterface
     */
    protected $messageManager;

    /**
     * @var Customer
     */
    protected $customerResourceModel;

    /**
     * @var Logs
     */
    protected $logsHelper;

    /**
     * @var DateTime
     */
    protected $date;

    /**
     * @var EmarsysDataHelper
     */
    protected $emarsysDataHelper;

    /**
     * @var OrderResourceModel
     */
    protected $orderResourceModel;

    /**
     * @var CreditmemoRepository
     */
    protected $creditmemoRepository;

    /**
     * @var MagentoOrderFactory
     */
    protected $salesOrderCollectionFactory;

    /**
     * @var OrderQueueFactory
     */
    protected $orderQueueFactory;

    /**
     * @var CreditmemoExportStatusFactory
     */
    protected $creditmemoExportStatusFactory;

    /**
     * @var OrderExportStatusFactory
     */
    protected $orderExportStatusFactory;

    /**
     * @var TimeZone
     */
    protected $timezone;

    /**
     * @var ApiExport
     */
    protected $apiExport;

    /**
     * @var DirectoryList
     */
    protected $directoryList;

    /**
     * @var array
     */
    protected $salesCsvHeader = [];

    /**
     * @var null | resource
     */
    protected $handle = null;

    /**
     * Order constructor.
     * @param Context $context
     * @param Registry $registry
     * @param StoreManagerInterface $storeManager
     * @param MessageManagerInterface $messageManager
     * @param EmarsysResourceModelCustomer $customerResourceModel
     * @param EmarsysHelperLogs $logsHelper
     * @param DateTime $date
     * @param EmarsysDataHelper $emarsysDataHelper
     * @param OrderResourceModel $orderResourceModel
     * @param CreditmemoRepository $creditmemoRepository
     * @param MagentoOrderFactory $salesOrderCollectionFactory
     * @param OrderQueueFactory $orderQueueFactory
     * @param CreditmemoExportStatusFactory $creditmemoExportStatusFactory
     * @param OrderExportStatusFactory $orderExportStatusFactory
     * @param TimeZone $timezone
     * @param ApiExport $apiExport
     * @param DirectoryList $directoryList
     * @param AbstractResource|null $resource
     * @param AbstractDb|null $resourceCollection
     * @param array $data
     */
    public function __construct(
        Context $context,
        Registry $registry,
        StoreManagerInterface $storeManager,
        MessageManagerInterface $messageManager,
        EmarsysResourceModelCustomer $customerResourceModel,
        EmarsysHelperLogs $logsHelper,
        DateTime $date,
        EmarsysDataHelper $emarsysDataHelper,
        OrderResourceModel $orderResourceModel,
        CreditmemoRepository $creditmemoRepository,
        MagentoOrderFactory $salesOrderCollectionFactory,
        OrderQueueFactory $orderQueueFactory,
        CreditmemoExportStatusFactory $creditmemoExportStatusFactory,
        OrderExportStatusFactory $orderExportStatusFactory,
        TimeZone $timezone,
        ApiExport $apiExport,
        DirectoryList $directoryList,
        AbstractResource $resource = null,
        AbstractDb $resourceCollection = null,
        array $data = []
    ) {
        $this->storeManager = $storeManager;
        $this->messageManager = $messageManager;
        $this->customerResourceModel = $customerResourceModel;
        $this->logsHelper = $logsHelper;
        $this->date = $date;
        $this->emarsysDataHelper =  $emarsysDataHelper;
        $this->orderResourceModel = $orderResourceModel;
        $this->creditmemoRepository = $creditmemoRepository;
        $this->salesOrderCollectionFactory = $salesOrderCollectionFactory;
        $this->orderQueueFactory = $orderQueueFactory;
        $this->creditmemoExportStatusFactory = $creditmemoExportStatusFactory;
        $this->orderExportStatusFactory = $orderExportStatusFactory;
        $this->timezone = $timezone;
        $this->apiExport = $apiExport;
        $this->directoryList = $directoryList;
        parent::__construct($context, $registry, $resource, $resourceCollection, $data);
    }

    /**
     * constructor
     */
    public function _construct()
    {
        parent::_construct();
        $this->_init('Emarsys\Emarsys\Model\ResourceModel\Order');
    }

    /**
     * @param $storeId
     * @param $mode
     * @param null $exportFromDate
     * @param null $exportTillDate
     */
    public function syncOrders($storeId, $mode, $exportFromDate = null, $exportTillDate = null)
    {
        $store = $this->storeManager->getStore($storeId);
        $websiteId = $store->getWebsiteId();

        //Loging functionality start
        $logsArray['job_code'] = 'order';
        $logsArray['status'] = 'started';
        $logsArray['messages'] = __('Bulk order export started');
        $logsArray['created_at'] = $this->date->date('Y-m-d H:i:s', time());
        $logsArray['run_mode'] = $mode;
        $logsArray['auto_log'] = 'Complete';
        $logsArray['store_id'] = $storeId;
        $logsArray['website_id'] = $websiteId;
        $logId = $this->logsHelper->manualLogs($logsArray, 1);
        $logsArray['id'] = $logId;
        $logsArray['log_action'] = 'sync';
        $logsArray['action'] = 'Smart Insight';
        $logsArray['executed_at'] = $this->date->date('Y-m-d H:i:s', time());
        $errorCount = false;

        //validate date range (Bulk export)
        if (isset($exportFromDate) && $exportFromDate != '') {
            $toTimezone = $this->timezone->getDefaultTimezone();
            $fromDate = $this->timezone->date($exportFromDate)
                ->setTimezone(new \DateTimeZone($toTimezone))
                ->format('Y-m-d H:i:s');
            $magentoTime = $this->date->date('Y-m-d H:i:s');
            $currentTime = new \DateTime($magentoTime);
            $currentTime->format('Y-m-d H:i:s');
            $datetime2 = new \DateTime($fromDate);
            $interval = $currentTime->diff($datetime2);
            if ($interval->y > 2 || ($interval->y == 2 && $interval->m >= 1) || ($interval->y == 2 && $interval->d >= 1)) {
                $logsArray['emarsys_info'] = __('The Time frame cannot be more than 2 years');
                $logsArray['description'] = __('The Time frame cannot be more than 2 years');
                $logsArray['message_type'] = 'Error';
                $this->logsHelper->logs($logsArray);
                $logsArray['status'] = 'error';
                $logsArray['messages'] = __('Smart Insight export have an error. Please check');
                $logsArray['finished_at'] = $this->date->date('Y-m-d H:i:s', time());
                $this->logsHelper->manualLogsUpdate($logsArray);
                return;
            }
        }

        //check emarsys enabled for the website
        if ($this->emarsysDataHelper->getEmarsysConnectionSetting($websiteId)) {
            //check smart insight enabled for the website
            if ($this->emarsysDataHelper->getCheckSmartInsight($websiteId)) {
                //get configuration of catalog export method
                $apiExportEnabled = $store->getConfig(EmarsysDataHelper::XPATH_EMARSYS_SIEXPORT_API_ENABLED);

                //check method of data exort from admin configuration
                if ($apiExportEnabled) {
                    //export data using api
                    $logsArray['action'] = 'synced to API';
                    $this->exportOrdersDataUsingApi($storeId, $mode, $exportFromDate, $exportTillDate, $logsArray);
                } else {
                    //export data using ftp
                    $logsArray['action'] = 'synced to FTP';
                    $this->exportOrdersDataUsingFtp($storeId, $mode, $exportFromDate, $exportTillDate, $logsArray);
                }
            } else {
                $errorCount = true;
                $logsArray['emarsys_info'] = __('Smart Insight is disabled');
                $logsArray['description'] = __('Smart Insight is disabled for the store %1.', $store->getName());
                $logsArray['message_type'] = 'Error';
                $this->logsHelper->logs($logsArray);
            }
        } else {
            $errorCount = true;
            $logsArray['emarsys_info'] = __('Emarsys is Disabled for this website %1', $websiteId);
            $logsArray['description'] = __('Emarsys is Disabled for this website %1', $websiteId);
            $logsArray['message_type'] = 'Error';
            $this->logsHelper->logs($logsArray);
        }

        if ($errorCount) {
            $logsArray['status'] = 'error';
            $logsArray['messages'] = __('Smart Insight export have an error. Please check');
            $logsArray['finished_at'] = $this->date->date('Y-m-d H:i:s', time());
            $this->logsHelper->manualLogsUpdate($logsArray);
        }

        return;
    }

    /**
     * @param $storeId
     * @param $mode
     * @param $exportFromDate
     * @param $exportTillDate
     * @param $logsArray
     */
    public function exportOrdersDataUsingApi($storeId, $mode, $exportFromDate, $exportTillDate, $logsArray)
    {
        $store = $this->storeManager->getStore($storeId);
        $errorCount = true;

        $merchantId = $store->getConfig(EmarsysDataHelper::XPATH_EMARSYS_SIEXPORT_MERCHANT_ID);
        $token = $store->getConfig(EmarsysDataHelper::XPATH_EMARSYS_SIEXPORT_TOKEN);

        if ($merchantId != '' && $token != '') {
            //test connection using merchant id and token
            $this->apiExport->assignApiCredentials($merchantId, $token);
            $response = $this->apiExport->testSIExportApi();

            if ($response['result'] == 1) {
                //get directory path bases on entity
                $fileDirectory = $this->emarsysDataHelper->getEmarsysMediaDirectoryPath(
                    \Magento\Sales\Model\Order::ENTITY
                );

                //check existence or create directory for csv generation
                $this->emarsysDataHelper->checkAndCreateFolder($fileDirectory);

                //prepare order collection
                $orderCollection = $this->getOrderCollection(
                    $mode,
                    $storeId,
                    $exportFromDate,
                    $exportTillDate
                );
                //prepare credit-memo collection
                $creditMemoCollection = $this->getCreditMemoCollection(
                    $mode,
                    $storeId,
                    $exportFromDate,
                    $exportTillDate
                );

                //check maximum record export is set
                $maxRecordExport = $store->getConfig(EmarsysDataHelper::XPATH_EMARSYS_SIEXPORT_MAX_RECORDS);

                if ($maxRecordExport) {
                    $orderSyncStatus = false;
                    $cmSyncStatus = false;
                    //export data in chunks based on max record set in admin configuration
                    if (!empty($orderCollection)) {
                        $orderSyncStatus = $this->generateBatchFilesAndSyncToEmarsys(
                            \Magento\Sales\Model\Order::ENTITY,
                            $orderCollection,
                            $mode,
                            $storeId,
                            $maxRecordExport,
                            $logsArray
                        );
                    }
                    if (!empty($creditMemoCollection)) {
                        $cmSyncStatus = $this->generateBatchFilesAndSyncToEmarsys(
                            \Magento\Sales\Model\Order::ACTION_FLAG_CREDITMEMO,
                            $creditMemoCollection,
                            $mode,
                            $storeId,
                            $maxRecordExport,
                            $logsArray
                        );
                    }
                    if ($orderSyncStatus && $cmSyncStatus) {
                        $errorCount = false;
                    }
                } else {
                    //export full data to emarsys
                    $outputFile = $this->getSalesCsvFileName($store->getCode());
                    $filePath =  $fileDirectory . "/" . $outputFile;
                    $this->generateOrderCsv($storeId, $filePath, $orderCollection, $creditMemoCollection);

                    //sync data to emarsys using API
                    $syncResponse = $this->sendRequestToEmarsys($filePath, $outputFile, $logsArray);
                    if ($syncResponse['status']) {
                        $errorCount = false;
                        if ($mode == EmarsysDataHelper::ENTITY_EXPORT_MODE_MANUAL) {
                            $this->messageManager->addSuccessMessage(
                                __("File uploaded to Emarsys successfully !!!")
                            );
                        }
                    } else {
                        if ($mode == EmarsysDataHelper::ENTITY_EXPORT_MODE_MANUAL) {
                            $this->messageManager->addErrorMessage(
                                __("Failed to upload file on Emarsys !!! %1", trim($syncResponse['message']))
                            );
                        }
                    }
                    //remove file after sync
                    if (file_exists($filePath)) {
                        unlink($filePath);
                    }
                }
            } else {
                //smart insight api test connection is failed
                $logsArray['status'] = 'error';
                $logsArray['messages'] = 'Smart Insight API test connection is failed. Please check credentials. ' . json_encode($response, JSON_PRETTY_PRINT);
                $this->logsHelper->manualLogsUpdate($logsArray);
                if ($mode == EmarsysDataHelper::ENTITY_EXPORT_MODE_MANUAL) {
                    $this->messageManager->addErrorMessage('Smart Insight API Test connection is failed. Please check credentials.');
                }
            }
        } else {
            //invalid api credentials
            $logsArray['emarsys_info'] = __('Invalid API credentials. Either Merchant Id or Token is not present.');
            $logsArray['description'] = __('Invalid API credentials. Either Merchant Id or Token is not present. Please check your settings and try again');
            $logsArray['message_type'] = 'Error';
            $this->logsHelper->logs($logsArray);
            if ($mode == EmarsysDataHelper::ENTITY_EXPORT_MODE_MANUAL) {
                $this->messageManager->addErrorMessage(
                    __("Invalid API credentials. Either Merchant Id or Token is not present. Please check your settings and try again !!!")
                );
            }
        }

        if ($errorCount) {
            $logsArray['status'] = 'error';
            $logsArray['messages'] = __('Order export have an error. Please check.');
        } else {
            if ($mode == EmarsysDataHelper::ENTITY_EXPORT_MODE_AUTOMATIC) {
                $this->cleanOrderQueueTable();
            }
            $logsArray['status'] = 'success';
            $logsArray['messages'] = __('Order export completed');
        }
        $logsArray['finished_at'] = $this->date->date('Y-m-d H:i:s', time());
        $this->logsHelper->manualLogsUpdate($logsArray);

        return;
    }

    /**
     * @param $storeId
     * @param $mode
     * @param $exportFromDate
     * @param $exportTillDate
     * @param $logsArray
     */
    public function exportOrdersDataUsingFtp($storeId, $mode, $exportFromDate, $exportTillDate, $logsArray)
    {
        $store = $this->storeManager->getStore($storeId);
        $errorCount = true;

        $bulkDir = $store->getConfig(EmarsysDataHelper::XPATH_EMARSYS_FTP_BULK_EXPORT_DIR);

        if ($this->emarsysDataHelper->checkFtpConnectionByStore($store)) {
            //ftp connection established successfully
            $outputFile = $this->getSalesCsvFileName($store->getCode());
            $fileDirectory = $this->emarsysDataHelper->getEmarsysMediaDirectoryPath(
                \Magento\Sales\Model\Order::ENTITY
            );

            //Check and create directory for csv generation
            $this->emarsysDataHelper->checkAndCreateFolder($fileDirectory);
            $filePath =  $fileDirectory . "/" . $outputFile;

            //prepare order collection
            $orderCollection = $this->getOrderCollection(
                $mode,
                $storeId,
                $exportFromDate,
                $exportTillDate
            );

            //prepare credit-memo collection
            $creditMemoCollection = $this->getCreditMemoCollection(
                $mode,
                $storeId,
                $exportFromDate,
                $exportTillDate
            );

            $maxRecordExport = 1000;

            //Generate Sales CSV

            $orderCollection->setPageSize($maxRecordExport);
            $pages = $orderCollection->getLastPageNumber();

            for ($i = 1; $i <= $pages; $i++) {
                $orderCollection->setCurPage($i);
                $this->generateOrderCsv($storeId, $filePath, $orderCollection, false, true);
                $logsArray['emarsys_info'] = __('Order\'s iteration %1 of %2', $i, $pages);
                $logsArray['description'] = __('Order\'s iteration %1 of %2', $i, $pages);
                $logsArray['message_type'] = 'Success';
                $this->logsHelper->logs($logsArray);
            }

            $creditMemoCollection->setPageSize($maxRecordExport);
            $pages = $creditMemoCollection->getLastPageNumber();

            for ($i = 1; $i <= $pages; $i++) {
                $creditMemoCollection->setCurPage($i);
                $this->generateOrderCsv($storeId, $filePath, false, $creditMemoCollection, true);
                $creditMemoCollection->clear();
                $logsArray['emarsys_info'] = __('CreditMemo\'s iteration %1 of %2', $i, $pages);
                $logsArray['description'] = __('CreditMemo\'s iteration %1 of %2', $i, $pages);
                $logsArray['message_type'] = 'Success';
                $this->logsHelper->logs($logsArray);
            }

            //CSV upload to FTP process starts

            $remoteDirPath = $bulkDir;
            if ($remoteDirPath == '/') {
                $remoteFileName = $outputFile;
            } else {
                $remoteDirPath = rtrim($remoteDirPath, '/');
                $remoteFileName = $remoteDirPath . "/" . $outputFile;
            }

            //Upload CSV to FTP
            if ($this->emarsysDataHelper->moveFileToFtp($store, $filePath, $remoteFileName)) {
                //file uploaded to FTP server successfully
                $errorCount = false;
                $logsArray['emarsys_info'] = __('File uploaded to FTP server successfully');
                $logsArray['description'] = $remoteFileName;
                $logsArray['message_type'] = 'Success';
                $this->logsHelper->logs($logsArray);
                if ($mode == EmarsysDataHelper::ENTITY_EXPORT_MODE_MANUAL) {
                    $this->messageManager->addSuccessMessage(
                        __("File uploaded to FTP server successfully !!!")
                    );
                }
            } else {
                //Failed to upload file on FTP server
                $errorMessage = error_get_last();
                $msg = isset($errorMessage['message']) ? $errorMessage['message'] : '';
                $logsArray['emarsys_info'] = __('Failed to upload file on FTP server');
                $logsArray['description'] = __('Failed to upload file on FTP server. %1', $msg);
                $logsArray['message_type'] = 'Error';
                $this->logsHelper->logs($logsArray);
                if ($mode == EmarsysDataHelper::ENTITY_EXPORT_MODE_MANUAL) {
                    $this->messageManager->addErrorMessage(
                        __("Failed to upload file on FTP server !!! %1", $msg)
                    );
                }
            }
            //remove file after sync
            unlink($filePath);
        } else {
            //failed to connect with FTP server with given credentials
            $logsArray['emarsys_info'] = __('Failed to connect with FTP server.');
            $logsArray['description'] = __('Failed to connect with FTP server.');
            $logsArray['message_type'] = 'Error';
            $this->logsHelper->logs($logsArray);
            if ($mode == EmarsysDataHelper::ENTITY_EXPORT_MODE_MANUAL) {
                $this->messageManager->addErrorMessage(
                    __('"Failed to connect with FTP server. Please check your settings and try again !!!"')
                );
            }
        }

        if ($errorCount) {
            $logsArray['status'] = 'error';
            $logsArray['messages'] = __('Order export have an error. Please check');
        } else {
            if ($mode == EmarsysDataHelper::ENTITY_EXPORT_MODE_AUTOMATIC) {
                $this->cleanOrderQueueTable();
            }
            $logsArray['status'] = 'success';
            $logsArray['messages'] = __('Order export completed');
        }
        $logsArray['finished_at'] = $this->date->date('Y-m-d H:i:s', time());
        $this->logsHelper->manualLogsUpdate($logsArray);

        return;
    }

    /**
     * @param $entity
     * @param $entityCollection
     * @param $mode
     * @param $storeId
     * @param $limit
     * @param $logsArray
     * @return bool
     */
    public function generateBatchFilesAndSyncToEmarsys($entity, $entityCollection, $mode, $storeId, $limit, $logsArray)
    {
        $store = $this->storeManager->getStore($storeId);
        $messageCollector = [];
        $result = false;
        $fileDirectory = $this->emarsysDataHelper->getEmarsysMediaDirectoryPath(
            \Magento\Sales\Model\Order::ENTITY
        );

        //sales order operation
        $entityCollection->setPageSize($limit);
        $pages = $entityCollection->getLastPageNumber();

        for ($i = 1; $i <= $pages; $i++) {
            $entityCollection->setCurPage($i);

            //get sales csv file name
            $outputFile = $this->getSalesCsvFileName($store->getCode(), true);
            $filePath =  $fileDirectory . "/" . $outputFile;

            if ($entity == \Magento\Sales\Model\Order::ENTITY) {
                $this->generateOrderCsv($storeId, $filePath, $entityCollection, '');
            } else {
                $this->generateOrderCsv($storeId, $filePath, '', $entityCollection);
            }

            $syncResponse = $this->sendRequestToEmarsys($filePath, $outputFile, $logsArray, $entity);

            if ($mode == EmarsysDataHelper::ENTITY_EXPORT_MODE_MANUAL) {
                if ($syncResponse['status']) {
                    array_push($messageCollector, 1);
                } else {
                    array_push($messageCollector, 0);
                }
            }
            //remove file after sync
            unlink($filePath);

            //clear the current page collection
            $entityCollection->clear();
        }

        //display messages
        if (!empty($messageCollector)) {
            if (!in_array(0, $messageCollector)) {
                $result = true;
            }
        }

        return $result;
    }

    /**
     * @param $filePath
     * @param $csvFileName
     * @param $logsArray
     * @return array
     */
    public function sendRequestToEmarsys($filePath, $csvFileName, $logsArray, $entityName = null)
    {
        $syncResult = [];

        //get sales api Url
        $apiUrl = $this->apiExport->getApiUrl(\Magento\Sales\Model\Order::ENTITY);

        //export csv to emarsys using api
        $apiExportResult = $this->apiExport->apiExport($apiUrl, $filePath);

        if ($apiExportResult['result'] == 1) {
            //successfully uploaded file to emarsys
            if (!is_null($entityName)) {
                $logsArray['emarsys_info'] = __('%1 File uploaded to Emarsys', ucfirst($entityName));
            } else {
                $logsArray['emarsys_info'] = __('File uploaded to Emarsys');
            }
            $logsArray['description'] = __('File: "%1" uploaded to Emarsys. Emarasys response: "%2"', $csvFileName, $apiExportResult['resultBody']);
            $logsArray['action'] = 'synced to emarsys';
            $logsArray['message_type'] = 'Success';
            $this->logsHelper->logs($logsArray);
            $syncResult['status'] = true;
        } else {
            //failed to upload file on emarsys
            $logsArray['emarsys_info'] = __('Failed to upload file on Emarsys');
            $logsArray['description'] = __('Failed to upload file: "%1" on Emarsys. Emarasys response: "%2"', $csvFileName, $apiExportResult['resultBody']);
            $logsArray['action'] = 'synced to emarsys';
            $logsArray['message_type'] = 'Error';
            $this->logsHelper->logs($logsArray);
            $syncResult['status'] = false;
        }
        $syncResult['message'] = $apiExportResult['resultBody'];

        return $syncResult;
    }

    /**
     * @param $storeId
     * @param $filePath
     * @param $orderCollection
     * @param $creditMemoCollection
     * @param bool $sameFile
     */
    public function generateOrderCsv($storeId, $filePath, $orderCollection, $creditMemoCollection, $sameFile = false)
    {
        $store = $this->storeManager->getStore($storeId);
        $emasysFields = $this->orderResourceModel->getEmarsysOrderFields($storeId);

        $guestOrderExportStatus = $store->getConfig(EmarsysDataHelper::XPATH_SMARTINSIGHT_EXPORTGUEST_CHECKOUTORDERS);
        $emailAsIdentifierStatus = $store->getConfig(EmarsysDataHelper::XPATH_SMARTINSIGHT_EXPORTUSING_EMAILIDENTIFIER);

        $taxIncluded = $this->emarsysDataHelper->isIncludeTax();
        $useBaseCurrency = $this->emarsysDataHelper->isUseBaseCurrency();


        if ($sameFile && !$this->handle) {
            $this->handle = fopen($filePath, 'w');

            //Get Header for sales csv
            $header = $this->getSalesCsvHeader($storeId);

            //put headers in sales csv
            fputcsv($this->handle, $header);
        } elseif (!$sameFile) {
            $this->handle = fopen($filePath, 'w');

            //Get Header for sales csv
            $header = $this->getSalesCsvHeader($storeId);

            //put headers in sales csv
            fputcsv($this->handle, $header);
        }

        //write data for orders into csv
        if ($orderCollection && (is_object($orderCollection)) && ($orderCollection->getSize())) {
            foreach ($orderCollection as $order) {
                $orderId = $order->getRealOrderId();
                $createdDate = $order->getCreatedAt();
                $customerEmail = $order->getCustomerEmail();
                $customerId = $order->getCustomerId();

                $parentId = null;
                foreach ($order->getItems() as $item) {
                    if ($item->getProductType() == \Magento\ConfigurableProduct\Model\Product\Type\Configurable::TYPE_CODE) {
                        $parentId == $item->getId();
                    }
                    if ($parentId && $item->getParentItemId() == $parentId) {
                        $parentId = null;
                        continue;
                    }
                    $values = [];
                    //set order id
                    $values[] = $orderId;
                    $date = new \DateTime($createdDate);
                    $createdDate = $date->format('Y-m-d');
                    //set timestamp
                    $values[] = $createdDate;

                    //set customer
                    if ($emailAsIdentifierStatus) {
                        $values[] = $customerEmail;
                    } else {
                        $values[] = $customerId;
                    }
                    //set product sku/id
                    $values[] = $item->getSku();

                    if (($item->getProductType() == \Magento\Bundle\Model\Product\Type::TYPE_CODE)) {
                        $parentId = null;
                        $productOptions = $item->getProductOptions();
                        if (isset($productOptions['product_calculations']) && $productOptions['product_calculations'] == 1) {
                            if ($taxIncluded) {
                                $price = $useBaseCurrency ? ($item->getBaseRowTotal()  + $item->getBaseTaxAmount()) - $item->getBaseDiscountAmount() : ($item->getRowTotal() + $item->getTaxAmount()) - $item->getDiscountAmount();
                            } else {
                                $price = $useBaseCurrency ? $item->getBaseRowTotal() - $item->getBaseDiscountAmount() : $item->getRowTotal() - $item->getDiscountAmount();
                            }
                        } elseif (isset($productOptions['product_calculations']) && $productOptions['product_calculations'] == 0) {
                            $price = 0;
                        }
                    } else {
                        if ($taxIncluded) {
                            $price = $useBaseCurrency ? ($item->getBaseRowTotal()  + $item->getBaseTaxAmount()) - $item->getBaseDiscountAmount() : ($item->getRowTotal() + $item->getTaxAmount()) - $item->getDiscountAmount();
                        } else {
                            $price = $useBaseCurrency ? $item->getBaseRowTotal() - $item->getBaseDiscountAmount() : $item->getRowTotal() - $item->getDiscountAmount();
                        }
                    }

                    $qty = (int)$item->getQtyInvoiced();
                    $rowTotal = 0;
                    if ($qty > 0) {
                        $rowTotal = $price;
                    }

                    if ($rowTotal != '') {
                        $values[] = number_format($rowTotal, 2, '.', '');
                    } else {
                        $values[] = 0;
                    }

                    //set quantity
                    $values[] = (int)$item->getQtyInvoiced();

                    foreach ($emasysFields as $field) {
                        $emarsysOrderFieldValueOrder = trim($field['emarsys_order_field']);
                        $magentoColumnName = trim($field['magento_column_name']);
                        if (!empty($emarsysOrderFieldValueOrder) && !in_array($emarsysOrderFieldValueOrder, array("'", '"')) && !empty($magentoColumnName)) {
                            $values[] = $order->getData($magentoColumnName);
                        }
                    }
                    if (!(($guestOrderExportStatus == 0 || $emailAsIdentifierStatus == 0) && $order->getCustomerIsGuest() == 1)) {
                        fputcsv($this->handle, $values);
                    }
                }
            }
        }

        //write data for credit-memo into csv
        if ($creditMemoCollection && (is_object($creditMemoCollection)) && ($creditMemoCollection->getSize())) {
            foreach ($creditMemoCollection as $creditMemo) {
                $creditMemoOrder = $this->salesOrderCollectionFactory->create()->load($creditMemo['order_id']);
                $orderId = $creditMemoOrder->getIncrementId();
                $orderEntityId = $creditMemoOrder->getId();
                $createdDate = $creditMemoOrder->getCreatedAt();
                $customerEmail = $creditMemoOrder->getCustomerEmail();
                $customerId = $creditMemoOrder->getCustomerId();

                $parentId = null;
                foreach ($creditMemo->getAllItems() as $item) {
                    if ($item->getProductType() == \Magento\ConfigurableProduct\Model\Product\Type\Configurable::TYPE_CODE) {
                        $parentId == $item->getId();
                    }
                    if ($parentId && $item->getParentItemId() == $parentId) {
                        $parentId = 0;
                        continue;
                    }
                    $values = [];
                    //set order id
                    $values[] = $orderId;
                    $date = new \DateTime($createdDate);
                    $createdDate = $date->format('Y-m-d');
                    //set timestamp
                    $values[] = $createdDate;

                    //set customer
                    if ($emailAsIdentifierStatus) {
                        $values[] = $customerEmail;
                    } else {
                        $values[] = $customerId;
                    }
                    //set product sku/id
                    $values[] = $item->getSku();

                    if (($item->getProductType() == \Magento\Bundle\Model\Product\Type::TYPE_CODE)) {
                        $parentId = null;
                        $productOptions = $item->getProductOptions();
                        if (isset($productOptions['product_calculations']) && $productOptions['product_calculations'] == 1) {
                            if ($taxIncluded) {
                                $price = $useBaseCurrency ? ($item->getBaseRowTotal()  + $item->getBaseTaxAmount()) - $item->getBaseDiscountAmount() : ($item->getRowTotal() + $item->getTaxAmount()) - $item->getDiscountAmount();
                            } else {
                                $price = $useBaseCurrency ? $item->getBaseRowTotal() - $item->getBaseDiscountAmount() : $item->getRowTotal() - $item->getDiscountAmount();
                            }
                        } elseif (isset($productOptions['product_calculations']) && $productOptions['product_calculations'] == 0) {
                            $price = 0;
                        }
                    } else {
                        if ($taxIncluded) {
                            $price = $useBaseCurrency ? ($item->getBaseRowTotal()  + $item->getBaseTaxAmount()) - $item->getBaseDiscountAmount() : ($item->getRowTotal() + $item->getTaxAmount()) - $item->getDiscountAmount();
                        } else {
                            $price = $useBaseCurrency ? $item->getBaseRowTotal() - $item->getBaseDiscountAmount() : $item->getRowTotal() - $item->getDiscountAmount();
                        }
                    }

                    $qty = (int)$item->getQty();
                    $rowTotal = 0;
                    if ($qty > 0) {
                        $rowTotal = $price;
                    }

                    if ($rowTotal != '') {
                        $values[] = '-' . number_format($rowTotal, 2, '.', '');
                    } else {
                        $values[] = 0;
                    }

                    //set quantity
                    $values[] = $qty;

                    foreach ($emasysFields as $field) {
                        $emarsysOrderFieldValueOrder = trim($field['emarsys_order_field']);
                        $magentoColumnName = trim($field['magento_column_name']);
                        if (!empty($emarsysOrderFieldValueOrder) && !in_array($emarsysOrderFieldValueOrder, array("'", '"')) && !empty($magentoColumnName)) {
                            $values[] = $creditMemo->getData($magentoColumnName);
                        }
                    }
                    if (!(($guestOrderExportStatus == 0 || $emailAsIdentifierStatus == 0) && $creditMemo->getCustomerIsGuest() == 1)) {
                        fputcsv($this->handle, $values);
                    }
                }

                //if creditmemo have adjustments
                if ($creditMemo->getAdjustment() != 0) {
                    $values = [];
                    //set order id
                    $values[] = $orderId;
                    $date = new \DateTime($createdDate);
                    $createdDate = $date->format('Y-m-d');
                    //set timestamp
                    $values[] = $createdDate;

                    //set customer
                    if ($emailAsIdentifierStatus) {
                        $values[] = $customerEmail;
                    } else {
                        $values[] = $customerId;
                    }

                    //set item id/sku
                    $values[] = 0;

                    //set Unit Prices
                    $values[] = $creditMemo->getAdjustment();

                    //set quantity
                    $values[] = 1;

                    foreach ($emasysFields as $field) {
                        $emarsysOrderFieldValueAdjustment = trim($field['emarsys_order_field']);
                        if ($emarsysOrderFieldValueAdjustment != '' && $emarsysOrderFieldValueAdjustment != "'") {
                            $orderExpValues = $this->orderResourceModel->getOrderColValue(
                                $emarsysOrderFieldValueAdjustment,
                                $orderEntityId,
                                $storeId
                            );

                            if (isset($orderExpValues['created_at'])) {
                                $createdAt = $this->emarsysDataHelper->getDateTimeInLocalTimezone($orderExpValues['created_at']);
                                $values[] = $createdAt;
                            } elseif (isset($orderExpValues['updated_at'])) {
                                $updatedAt = $this->emarsysDataHelper->getDateTimeInLocalTimezone($orderExpValues['updated_at']);
                                $values[] = $updatedAt;
                            } else {
                                $values[] = $orderExpValues['magento_column_value'];
                            }
                        }
                    }
                    if (!(($guestOrderExportStatus == 0 || $emailAsIdentifierStatus == 0) && $creditMemoOrder->getCustomerIsGuest() == 1)) {
                        fputcsv($this->handle, $values);
                    }
                }
            }
        }
        unset($orderCollection);
        unset($creditMemoCollection);
        return;
    }

    /**
     * @param $suffix
     * @return string
     */
    public function getSalesCsvFileName($suffix, $unique = false)
    {
        $uniqueId = '';
        if ($unique) {
            $uniqueId = str_replace(' ', '', microtime(true));
            $uniqueId = str_replace('.', '', $uniqueId);
            $uniqueId = '_' . $uniqueId;
        }

        return "sales_items_" . $this->date->date('YmdHis', time()) . $uniqueId . "_" . $suffix . ".csv";
    }

    /**
     * Get Sales CSV Header
     * @return array
     */
    public function getSalesCsvHeader($storeId = 0)
    {
        if (!isset($this->salesCsvHeader[$storeId])) {
            //default header
            $header = $this->emarsysDataHelper->getSalesOrderCsvDefaultHeader($storeId);

            //header collected from mapped order attributes
            $emasysFields = $this->orderResourceModel->getEmarsysOrderFields($storeId);
            foreach ($emasysFields as $field) {
                $emarsysOrderFieldValue = trim($field['emarsys_order_field']);
                if ($emarsysOrderFieldValue != '' && $emarsysOrderFieldValue != "'") {
                    $header[] = $emarsysOrderFieldValue;
                }
            }
            $this->salesCsvHeader[$storeId] = $header;
        }

        return $this->salesCsvHeader[$storeId];
    }

    /**
     * @param $mode
     * @param $storeId
     * @param $exportFromDate
     * @param $exportTillDate
     * @return $this|array
     */
    public function getOrderCollection($mode, $storeId, $exportFromDate, $exportTillDate)
    {
        $store = $this->storeManager->getStore($storeId);
        $orderStatuses = $store->getConfig(EmarsysDataHelper::XPATH_SMARTINSIGHT_EXPORT_ORDER_STATUS);
        $orderStatuses = explode(',', $orderStatuses);
        $orderCollection = [];

        if ($mode == EmarsysDataHelper::ENTITY_EXPORT_MODE_AUTOMATIC) {
            $queueCollection = $this->orderQueueFactory->create();
            $queueDataAll = $queueCollection->getCollection()
                ->addFieldToFilter('entity_type_id', 1);

            if ($queueDataAll && $queueDataAll->getSize()) {
                $orderIds = [];
                foreach ($queueDataAll as $queueData) {
                    $orderIds[] = $queueData->getEntityId();
                }
                $orderCollection = $this->salesOrderCollectionFactory->create()->getCollection()
                    ->addFieldToFilter('store_id', ['eq' => $storeId])
                    ->addFieldToFilter('entity_id', ['in' => $orderIds]);
            }
        } else {
            if (isset($exportFromDate) && isset($exportTillDate) && $exportFromDate != '' && $exportTillDate != '') {
                $toTimezone = $this->timezone->getDefaultTimezone();
                $fromDate = $this->timezone->date($exportFromDate)
                    ->setTimezone(new \DateTimeZone($toTimezone))
                    ->format('Y-m-d H:i:s');

                $toDate = $this->timezone->date($exportTillDate)
                    ->setTimezone(new \DateTimeZone($toTimezone))
                    ->format('Y-m-d H:i:s');

                if ($orderStatuses != '') {
                    $orderCollection = $this->salesOrderCollectionFactory->create()->getCollection()
                        ->addFieldToFilter(
                            'created_at',
                            [
                                'from' => $fromDate,
                                'to' => $toDate,
                                'date' => true,
                            ]
                        )
                        ->addFieldToFilter('store_id', ['eq' => $storeId])
                        ->addFieldToFilter('status', ['in' => $orderStatuses]);
                }
            } else {
                if ($orderStatuses != '') {
                    $orderCollection = $this->salesOrderCollectionFactory->create()
                        ->getCollection()
                        ->addFieldToFilter('status', ['in' => $orderStatuses])
                        ->addFieldToFilter('store_id', ['eq' => $storeId]);
                }
            }
        }

        return $orderCollection;
    }

    /**
     * @param $mode
     * @param $storeId
     * @param $exportFromDate
     * @param $exportTillDate
     * @return array
     */
    public function getCreditMemoCollection($mode, $storeId, $exportFromDate, $exportTillDate)
    {
        $store = $this->storeManager->getStore($storeId);
        $websiteId = $store->getWebsiteId();
        $scope = ScopeInterface::SCOPE_WEBSITE;
        $creditMemoCollection = [];

        $orderStatuse = $this->customerResourceModel->getDataFromCoreConfig(
            EmarsysDataHelper::XPATH_SMARTINSIGHT_EXPORT_ORDER_STATUS,
            $scope,
            $websiteId
        );
        $orderStatuses = explode(',', $orderStatuse);

        if ($mode == EmarsysDataHelper::ENTITY_EXPORT_MODE_AUTOMATIC) {
            $queueCollection = $this->orderQueueFactory->create();
            $queueDataAll = $queueCollection->getCollection()
                ->addFieldToFilter('entity_type_id', 2);
            if ($queueDataAll && $queueDataAll->getSize()) {
                $creditMemoIds = [];
                foreach ($queueDataAll as $queueData) {
                    $creditMemoIds[] = $queueData->getEntityId();
                }
                $creditMemoCollection = $this->creditmemoRepository->create()->getCollection()
                    ->addFieldToFilter('store_id', ['eq' => $storeId])
                    ->addFieldToFilter('entity_id', ['in' => $creditMemoIds]);
            }
        } else {
            if (isset($exportFromDate) && isset($exportTillDate) && $exportFromDate != '' && $exportTillDate != '') {
                $toTimezone = $this->timezone->getDefaultTimezone();
                $fromDate = $this->timezone->date($exportFromDate)
                    ->setTimezone(new \DateTimeZone($toTimezone))
                    ->format('Y-m-d H:i:s');

                $toDate = $this->timezone->date($exportTillDate)
                    ->setTimezone(new \DateTimeZone($toTimezone))
                    ->format('Y-m-d H:i:s');

                if ($orderStatuses != '') {
                    $creditMemoCollection = $this->creditmemoRepository->create()->getCollection()
                        ->addFieldToFilter(
                            'created_at',
                            [
                                'from' => $fromDate,
                                'to' => $toDate,
                                'date' => true,
                            ]
                        )
                        ->addFieldToFilter('store_id', ['eq' => $storeId]);
                }
            } else {
                $creditMemoCollection = $this->creditmemoRepository->create()->getCollection()
                    ->addFieldToFilter('store_id', ['eq' => $storeId]);
            }
        }

        return $creditMemoCollection;
    }

    /**
     * clean Order Queue Table
     */
    public function cleanOrderQueueTable()
    {
        $orderExportStatus = $this->orderExportStatusFactory->create();
        $queueCollection = $this->orderQueueFactory->create();

        $orderIds = [];
        $queueDataAll = $queueCollection->getCollection()
            ->addFieldToFilter('entity_type_id', 1)
            ->getData();

        foreach ($queueDataAll as $queueData) {
            $orderIds[] = $queueData['entity_id'];
        }

        $creditmemoOrderIds = [];
        $creditMemoCollection = $queueCollection->getCollection()
            ->addFieldToFilter('entity_type_id', 2)->getData();
        foreach ($creditMemoCollection as $queueData) {
            $creditmemoOrderIds[] = $queueData['entity_id'];
        }
        $orderExportStatusCollection = $orderExportStatus->getCollection()
            ->addFieldToFilter('order_id', ['in' => $orderIds]);
        $allDataExport = $orderExportStatusCollection->getData();

        foreach ($allDataExport as $orderExportStat) {
            $eachOrderStat = $this->orderExportStatusFactory->create()->load($orderExportStat['id']);
            $eachOrderStat->setExported(1);
            $eachOrderStat->save();
        }
        //Remove the exported records from the queue table
        foreach ($queueDataAll as $queueData) {
            $queueDataEach = $this->orderQueueFactory->create()->load($queueData['id']);
            $queueDataEach->delete();
        }

        $creditmemoExportStatus = $this->creditmemoExportStatusFactory->create();
        $creditmemoExportStatusCollection = $creditmemoExportStatus->getCollection()
            ->addFieldToFilter('order_id', ['in' => $creditmemoOrderIds]);
        $allDataExport = $creditmemoExportStatusCollection->getData();
        foreach ($allDataExport as $orderExportStat) {
            $eachOrderStat = $this->creditmemoExportStatusFactory->create()->load($orderExportStat['id']);
            $eachOrderStat->setExported(1);
            $eachOrderStat->save();
        }
        //Remove the exported records from the queue table
        foreach ($creditMemoCollection as $queueData) {
            $queueDataEach = $this->orderQueueFactory->create()->load($queueData['id']);
            $queueDataEach->delete();
        }

        return;
    }
}
