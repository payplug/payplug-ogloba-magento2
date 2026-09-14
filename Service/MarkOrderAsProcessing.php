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
use Magento\Sales\Model\Order\StatusResolver;

class MarkOrderAsProcessing
{
    /**
     * @param StatusResolver $statusResolver
     */
    public function __construct(
        private readonly StatusResolver $statusResolver
    ) {
    }

    /**
     * Move a paid order to processing
     *
     * @param OrderInterface $order
     * @param string $comment
     * @return void
     */
    public function execute(OrderInterface $order, string $comment): void
    {
        $status = $this->statusResolver->getOrderStatusByState($order, Order::STATE_PROCESSING);

        $order->setState(Order::STATE_PROCESSING);
        $order->setStatus($status);
        $order->addCommentToStatusHistory($comment, $status);
    }
}
