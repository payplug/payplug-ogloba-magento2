<?php
/**
 * Payplug - https://www.payplug.com/
 * Copyright © Payplug. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace Payplug\Ogloba\Controller\Payment;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Request\Http;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Redirect as RedirectResult;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Message\ManagerInterface as MessageManager;
use Payplug\Ogloba\Api\Data\CheckoutPathInterface;
use Payplug\Ogloba\Api\Data\OutcomeInterface;
use Payplug\Ogloba\Logger\Logger;
use Payplug\Ogloba\Service\GetOglobaPaymentFromSession;
use Payplug\Ogloba\Service\GetPaymentResponse;
use Payplug\Ogloba\Service\ProcessReturn;
use Payplug\Ogloba\Service\RestoreCart;
use Throwable;

class PaymentReturn implements HttpGetActionInterface
{
    /**
     * @param Http $request
     * @param RedirectFactory $resultRedirectFactory
     * @param MessageManager $messageManager
     * @param GetOglobaPaymentFromSession $getOglobaPaymentFromSession
     * @param GetPaymentResponse $getPaymentResponse
     * @param ProcessReturn $processReturn
     * @param RestoreCart $restoreCart
     * @param Logger $logger
     */
    public function __construct(
        private readonly RequestInterface $request,
        private readonly RedirectFactory $resultRedirectFactory,
        private readonly MessageManager $messageManager,
        private readonly GetOglobaPaymentFromSession $getOglobaPaymentFromSession,
        private readonly GetPaymentResponse $getPaymentResponse,
        private readonly ProcessReturn $processReturn,
        private readonly RestoreCart $restoreCart,
        private readonly Logger $logger
    ) {
    }

    /**
     * Send the customer back to the checkout, to the success page or to the cart
     *
     * @return RedirectResult
     */
    public function execute(): RedirectResult
    {
        $resultRedirect = $this->resultRedirectFactory->create();
        $paymentResponse = $this->getPaymentResponse->execute($this->request);

        $orderPayment = $this->getOglobaPaymentFromSession->execute();

        if ($orderPayment === null) {
            $this->logger->error('Ogloba payment return called without a matching order in session.');

            return $resultRedirect->setPath(CheckoutPathInterface::FAILURE_PATH);
        }

        $order = $orderPayment->getOrder();

        $this->logger->info(sprintf(
            'Ogloba order %s - customer is back from the payment page.',
            $order->getIncrementId()
        ));

        try {
            $outcome = $this->processReturn->execute($order, $paymentResponse);
        } catch (Throwable $e) {
            $this->logger->error(sprintf(
                'Ogloba order %s - unable to process the payment return: %s',
                $order->getIncrementId(),
                $e->getMessage()
            ));

            return $resultRedirect->setPath(CheckoutPathInterface::FAILURE_PATH);
        }

        if ($outcome === OutcomeInterface::FAILURE) {
            $this->restoreCart->execute(
                (string) $order->getIncrementId(),
                (string) __('The payment was not completed and your gift card has not been charged.')
            );

            return $resultRedirect->setPath(CheckoutPathInterface::FAILURE_PATH);
        }

        if ($outcome === OutcomeInterface::UNCONFIRMED) {
            $this->messageManager->addNoticeMessage((string) __(
                'Your order has been placed and the Ogloba payment is still being confirmed. Your order will '
                . 'be updated as soon as the confirmation arrives.'
            ));
        }

        return $resultRedirect->setPath(CheckoutPathInterface::SUCCESS_PATH);
    }
}
