<?php
/**
 * Payplug - https://www.payplug.com/
 * Copyright © Payplug. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace Payplug\Ogloba\Service;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Sales\Api\Data\OrderPaymentInterface;
use Magento\Sales\Model\Order\Payment;
use Payplug\Ogloba\Gateway\Config\Ogloba;

class GetOglobaPaymentFromSession
{
    /**
     * @param CheckoutSession $checkoutSession
     */
    public function __construct(
        private readonly CheckoutSession $checkoutSession
    ) {
    }

    /**
     * Get the payment of the order the customer is coming back from, when it is an Ogloba one
     *
     * @return OrderPaymentInterface|null
     */
    public function execute(): ?OrderPaymentInterface
    {
        $order = $this->checkoutSession->getLastRealOrder();
        $payment = $order->getPayment();

        if (!$order->getId() || $payment instanceof Payment === false
            || $payment->getMethod() !== Ogloba::METHOD_CODE
        ) {
            return null;
        }

        return $payment;
    }
}
