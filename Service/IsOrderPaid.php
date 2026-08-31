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

class IsOrderPaid
{
    private const PAID_STATES = [
        Order::STATE_PROCESSING,
        Order::STATE_COMPLETE,
        Order::STATE_HOLDED,
        Order::STATE_PAYMENT_REVIEW,
    ];

    /**
     * Has the order already been acknowledged as paid
     *
     * @param OrderInterface $order
     * @return bool
     */
    public function execute(OrderInterface $order): bool
    {
        return in_array($order->getState(), self::PAID_STATES, true);
    }
}
