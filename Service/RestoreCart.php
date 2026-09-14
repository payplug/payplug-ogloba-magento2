<?php
/**
 * Payplug - https://www.payplug.com/
 * Copyright © Payplug. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace Payplug\Ogloba\Service;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\Message\ManagerInterface as MessageManager;

class RestoreCart
{
    /**
     * @param CheckoutSession $checkoutSession
     * @param MessageManager $messageManager
     */
    public function __construct(
        private readonly CheckoutSession $checkoutSession,
        private readonly MessageManager $messageManager
    ) {
    }

    /**
     * Give the customer their cart back and tell them why, without ruling on the order
     *
     * @param string $incrementId
     * @param string $message
     * @return void
     */
    public function execute(string $incrementId, string $message): void
    {
        $this->checkoutSession->setLastRealOrderId($incrementId);
        $this->checkoutSession->restoreQuote();

        $this->messageManager->addErrorMessage($message);
    }
}
