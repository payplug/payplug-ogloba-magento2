<?php
/**
 * Payplug - https://www.payplug.com/
 * Copyright © Payplug. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace Payplug\Ogloba\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Quote\Api\Data\PaymentInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment as OrderPayment;
use Magento\Sales\Model\Order\StatusResolver;
use Payplug\Ogloba\Gateway\Config\Ogloba;

class SetOrderStateToPendingPayment implements ObserverInterface
{
    /**
     * @param StatusResolver $statusResolver
     */
    public function __construct(
        private readonly StatusResolver $statusResolver
    ) {
    }

    /**
     * Set order state to pending payment if payment method is Ogloba
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer): void
    {
        /** @var PaymentInterface $orderPayment */
        $orderPayment = $observer->getEvent()->getData('payment');

        if ($orderPayment instanceof OrderPayment === false || $orderPayment->getMethod() !== Ogloba::METHOD_CODE
        ) {
            return;
        }

        /** @var OrderInterface $order */
        $order = $orderPayment->getOrder();
        $status = $this->statusResolver->getOrderStatusByState($order, Order::STATE_PENDING_PAYMENT);

        $order->setStatus($status);
        $order->setState(Order::STATE_PENDING_PAYMENT);
    }
}
