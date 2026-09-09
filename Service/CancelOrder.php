<?php
/**
 * Payplug - https://www.payplug.com/
 * Copyright © Payplug. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace Payplug\Ogloba\Service;

use Magento\Sales\Api\Data\OrderInterface;
use Payplug\Ogloba\Logger\Logger;

class CancelOrder
{
    /**
     * @param Logger $logger
     */
    public function __construct(
        private readonly Logger $logger
    ) {
    }

    /**
     * Cancel an order that was not paid
     *
     * @param OrderInterface $order
     * @param string $comment
     * @return void
     */
    public function execute(OrderInterface $order, string $comment): void
    {
        if ($order->canCancel() === false) {
            $this->logger->error(sprintf(
                'Ogloba order %s cannot be cancelled, only the history is updated.',
                $order->getIncrementId()
            ));

            $order->addCommentToStatusHistory($comment);

            return;
        }

        $order->cancel();
        $order->addCommentToStatusHistory($comment);
    }
}
