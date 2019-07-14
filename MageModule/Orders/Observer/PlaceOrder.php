<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace MageModule\Orders\Observer;

use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Exception\AlreadyExistsException;
use Magento\Sales\Model\Order;
use Psr\Log\LoggerInterface;


class PlaceOrder implements ObserverInterface
{
    /**
     * @var LoggerInterface
     */
    private $logger;
	
	/**
     * @var \Magento\Framework\Mail\Template\TransportBuilder
     */
    protected $_transportBuilder;
	
    /**
     * @var \Magento\Store\Model\StoreManagerInterface
     */
    protected $_storeManager;
	
	/**
	* @var \Magento\Framework\App\Config\ScopeConfigInterface
	*/
	protected $scopeConfig;
	
	const XML_PATH_EMAIL_RECIPIENT = 'fraud/fraud_prevention/email_id';
	const IS_ACTIVE = 'fraud/fraud_prevention/active';

    public function __construct(
        LoggerInterface $logger,
		\Magento\Framework\Mail\Template\TransportBuilder $transportBuilder,
		\Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
		\Magento\Store\Model\StoreManagerInterface $storeManager
    ) {
		$this->_transportBuilder = $transportBuilder;
		$this->scopeConfig = $scopeConfig;
		$this->_storeManager = $storeManager;
        $this->logger = $logger;
    }
	
	const MAX_ORDER_PERCENT = 50;

    /**
     * {@inheritdoc}
     */
    public function execute(Observer $observer)
    
	
		$storeScope = \Magento\Store\Model\ScopeInterface::SCOPE_STORE;
		$isEnable = $this->scopeConfig->getValue(self::IS_ACTIVE, $storeScope);
		
		if(!$isEnable) {
			return;
		}
		
		$order = $event->getData('order');

        if (null === $order) {
            return;
        }
		
		$storeId = $order->getStoreId();
		$orderId = $order->getEntityId();
		
		/*** Return from this point if payment method is offline or payment pending ***/
        if (null === $orderId
            || $order->getPayment()->getMethodInstance()->isOffline()
            || $order->getState() === Order::STATE_PENDING_PAYMENT) {
            return;
        }
			
		$fraudOrder = false;

		/*** Check if order discount percentage is above than maximum ***/
		$discountPercent = $order->getDiscountPercent();
		if($discountPercent >= MAX_ORDER_PERCENT) {
			$fraudOrder = true;
		}
		
		/*** Check if order applied multiple coupon code ***/
		$couponCodes = $order->getCouponCode();
		$couponCodeArr = explode(',', $couponCodes);
		if(count($couponCodeArr) > 1) {
			$fraudOrder = true;
		}
		
		if(!$fraudOrder) {
			return;
		}
		
		try {
			$order->setState("holded")->setStatus("holded"); /** Change the order status to On Hold **/
			$this->sendMail($order);
        } catch (AlreadyExistsException $e) {
            $this->logger->error($e->getMessage());
        }
    }

    
    private function sendMail($order)
    {
		$storeScope = \Magento\Store\Model\ScopeInterface::SCOPE_STORE;
		$emailIds = $this->scopeConfig->getValue(self::XML_PATH_EMAIL_RECIPIENT, $storeScope);
		
        $store = $this->_storeManager->getStore()->getId();
        $transport = $this->_transportBuilder->setTemplateIdentifier('orders_fraud_email_template')
            ->setTemplateOptions(['area' => 'frontend', 'store' => $store])
            ->setTemplateVars(
                [
                    'store' => $this->_storeManager->getStore(),
					'order' => $order,
                ]
            )
            ->setFrom('general')
            ->addTo($emailIds)
            ->getTransport();
        $transport->sendMessage();
        return $this;
    }
}
