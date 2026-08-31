<?php
/**
 * Payplug - https://www.payplug.com/
 * Copyright © Payplug. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace Payplug\Ogloba\Service;

use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Model\Order;

class IsOrderAwaitingPayment
{
    private const AWAITING_STATES = [
        Order::STATE_PENDING_PAYMENT,
        Order::STATE_NEW,
        Order::STATE_PAYMENT_REVIEW,
    ];

    /**
     * Can the order still receive a verdict from Ogloba
     *
     * @param OrderInterface $order
     * @return bool
     */
    public function execute(OrderInterface $order): bool
    {
        return in_array($order->getState(), self::AWAITING_STATES, true);
    }
}
