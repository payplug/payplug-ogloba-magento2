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
use Magento\Sales\Model\Order\Email\Sender\OrderSender;
use Payplug\Ogloba\Logger\Logger;
use Throwable;

class SendOrderEmail
{
    /**
     * @param OrderSender $orderSender
     * @param Logger $logger
     */
    public function __construct(
        private readonly OrderSender $orderSender,
        private readonly Logger $logger
    ) {
    }

    /**
     * Send the order confirmation the placement held back, now that the payment is charged
     *
     * @param OrderInterface $order
     * @return void
     */
    public function execute(OrderInterface $order): void
    {
        $incrementId = (string) $order->getIncrementId();

        if ($order instanceof Order === false) {
            $this->logger->error(sprintf(
                'Ogloba order %s - the confirmation email was not sent. Order is not instance of core '
                . 'concrete class',
                $incrementId
            ));

            return;
        }

        if ($order->getEmailSent()) {
            $this->logger->info(sprintf(
                'Ogloba order %s - the confirmation email had already been sent.',
                $incrementId
            ));

            return;
        }

        try {
            $this->orderSender->send($order);
        } catch (Throwable $e) {
            $this->logger->error(sprintf(
                'Ogloba order %s - the confirmation email could not be sent: %s',
                $incrementId,
                $e->getMessage()
            ));

            return;
        }

        $this->logger->info(sprintf(
            'Ogloba order %s - the confirmation email has been handed over to Magento.',
            $incrementId
        ));
    }
}
