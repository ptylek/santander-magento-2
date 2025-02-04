<?php

namespace SantanderPaymentSolutions\SantanderPayments\Controller\Callback;

use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\View\Result\PageFactory;
use SantanderPaymentSolutions\SantanderPayments\Helper\IntegrationHelper;
use SantanderPaymentSolutions\SantanderPayments\Helper\TransactionHelper;
use SantanderPaymentSolutions\SantanderPayments\Library\Struct\Transaction;

class Index extends Action implements CsrfAwareActionInterface
{
    protected $_pageFactory;
    private $integrationHelper;
    private $transactionHelper;
    private $context;

    public function __construct(
        Context $context,
        PageFactory $pageFactory,
        IntegrationHelper $integrationHelper,
        TransactionHelper $transactionHelper
    ) {
        $this->_pageFactory      = $pageFactory;
        $this->context           = $context;
        $this->integrationHelper = $integrationHelper;
        $this->transactionHelper = $transactionHelper;

        return parent::__construct($context);
    }

    public function execute()
    {
        $vars = $this->context->getRequest()->getPostValue();
        if (!empty($vars["action"]) && $vars["action"] == 'reauthorize_invoice') {
            $response = $this->transactionHelper->authorize('invoice');
            $return   = ['success' => 0];
            if ($response->isSuccess) {
                $return["success"]      = 1;
                $return["redirect_url"] = $response->responseArray["frontend"]["redirect_url"];
                $this->integrationHelper->setLastReference($response->responseArray["identification"]["transactionid"]);
            }
            /** @var \Magento\Framework\Controller\Result\Json $response */
            $response = $this->resultFactory->create(ResultFactory::TYPE_JSON);
            $response->setData($return);

            return $response;
        } elseif (!empty($vars["action"]) && $vars["action"] == 'initialize_hire') {
            $response = $this->transactionHelper->initialize('hire', $vars["NAME_BIRTHDATE"]);
            $return   = ['success' => 0];
            if ($response->isSuccess) {
                $return["success"]      = 1;
                $return["redirect_url"] = $response->responseArray["frontend"]["redirect_url"];
                $this->integrationHelper->setLastReference($response->responseArray["identification"]["transactionid"]);
            }
            /** @var \Magento\Framework\Controller\Result\Json $response */
            $response = $this->resultFactory->create(ResultFactory::TYPE_JSON);
            $response->setData($return);

            return $response;
        } elseif (!empty($vars["action"]) && $vars["action"] == 'authorize_on_registration') {
            $return = ['success' => 0];
            if ($cReference = $this->integrationHelper->getLastReference()) {
                $transaction = $this->transactionHelper->getByReference($cReference, 'initialize_2');
                $response    = $this->transactionHelper->authorizeOnRegistration($transaction);

                if ($response->isSuccess) {
                    $return["success"]      = 1;
                    $return["redirect_url"] = $response->responseArray["frontend"]["redirect_url"];
                    $this->integrationHelper->setLastReference($response->responseArray["identification"]["transactionid"]);
                }
            }
            /** @var \Magento\Framework\Controller\Result\Json $response */
            $response = $this->resultFactory->create(ResultFactory::TYPE_JSON);
            $response->setData($return);

            return $response;
        } elseif (!empty($vars["IDENTIFICATION_TRANSACTIONID"])) {
            return $this->ipnAction($vars);
        } else {
            return $this->customerFrontendAction($vars);
        }
    }

    private function ipnAction($vars)
    {
        $this->integrationHelper->log('info', __CLASS__ . '..' . __METHOD__ . '::' . __LINE__, 'IPN received', $vars);
        $reference = $vars["IDENTIFICATION_TRANSACTIONID"];
        if ($initialTransaction = $this->transactionHelper->getByReference($reference)) {
            if (round($initialTransaction->amount, 2) == round($vars["PRESENTATION_AMOUNT"], 2)) {
                if ($vars["PAYMENT_CODE"] === 'HP.PA') {
                    $openReservation = $this->transactionHelper->getByReference($reference, 'reservation', 'open');
                    if ($openReservation) {
                        $openReservation->status   = ($vars["PROCESSING_RESULT"] === 'ACK' ? 'success' : 'error');
                        $openReservation->response = json_encode($vars);
                        if ($vars["IDENTIFICATION_UNIQUEID"]) {
                            $openReservation->uniqueId = $vars["IDENTIFICATION_UNIQUEID"];
                        }
                        $this->integrationHelper->log('info', __CLASS__ . '..' . __METHOD__ . '::' . __LINE__, 'update transaction', $vars);
                        $this->transactionHelper->saveTransaction($openReservation);
                    }
                } else {
                    $rawResponse                 = $vars;
                    $transaction                 = new Transaction();
                    $transaction->amount         = $initialTransaction->amount;
                    $transaction->method         = $initialTransaction->method;
                    $transaction->type           = ($vars["PAYMENT_CODE"] === 'HP.IN' ? 'initialize_2' : 'reservation');
                    $transaction->reference      = $reference;
                    $transaction->createDatetime = date('Y-m-d H:i:s');
                    $transaction->status         = $vars["PROCESSING_RESULT"] === 'ACK' ? 'success' : 'error';
                    $transaction->response       = json_encode($rawResponse);
                    $transaction->customerId     = $initialTransaction->customerId;
                    $transaction->currency       = $initialTransaction->currency;
                    if ($vars["IDENTIFICATION_UNIQUEID"]) {
                        $transaction->uniqueId = $vars["IDENTIFICATION_UNIQUEID"];
                    }
                    if ($vars["PROCESSING_RETURN"]) {
                        $transaction->transactionComment = $vars["PROCESSING_RETURN"] . ' (' . $vars["PROCESSING_REASON"] . ')';
                    }
                    $this->transactionHelper->saveTransaction($transaction);
                }
            }
        }
        if ($vars["PROCESSING_RESULT"] !== 'ACK') {
            $this->integrationHelper->log('error', __CLASS__ . '..' . __METHOD__ . '::' . __LINE__, 'IPN error', $vars);
        }
        /** @var \Magento\Framework\Controller\Result\Raw $_response */
        $_response = $this->resultFactory->create(ResultFactory::TYPE_RAW);
        $_response->setContents('IPN DONE');

        return $_response;
    }

    private function customerFrontendAction($vars)
    {
        $return = ['success' => 0];
        $this->integrationHelper->log('info', __CLASS__ . '..' . __METHOD__ . '::' . __LINE__, 'customer frontend action', $vars);
        if ($cReference = $this->integrationHelper->getLastReference()) {
            $return['reference']    = $cReference;
            $transaction            = $this->transactionHelper->getByReference($cReference, null, null);
            $method                 = $transaction->method;
            $reservationTransaction = $this->transactionHelper->getByReference($cReference, 'reservation', null);
            if ($reservationTransaction && $reservationTransaction->status === 'success') {
                $return["success"] = 1;
            } else {
                if ($method === 'hire') {
                    if ($initialize2Transaction = $this->transactionHelper->getByReference($cReference, 'initialize_2')) {
                        $this->integrationHelper->setLastReference($initialize2Transaction->reference);
                        $response = json_decode($initialize2Transaction->response, true);
                        /** @var \Magento\Framework\Controller\Result\Raw $_response */
                        $_response = $this->resultFactory->create(ResultFactory::TYPE_RAW);
                        $_response->setContents('<script>setInterval(function(){try{window.santanderHireFinishedPaymentPlan(true, "' . $response["CRITERION_SANTANDER_HP_PDF_URL"] . '");}catch(err){}}, 10);</script>');

                        return $_response;
                    }
                    /** @var \Magento\Framework\Controller\Result\Raw $_response */
                    $_response = $this->resultFactory->create(ResultFactory::TYPE_RAW);
                    $_response->setContents('<script>window.close()</script>');

                    return $_response;
                }
            }
        }
        /** @var \Magento\Framework\Controller\Result\Json $response */
        $response = $this->resultFactory->create(ResultFactory::TYPE_JSON);
        $response->setData($return);

        return $response;
    }

    public function createCsrfValidationException(
        RequestInterface $request
    ): ?InvalidRequestException {
        return null;
    }

    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }
}