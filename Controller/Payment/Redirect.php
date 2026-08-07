<?php
/**
 * Payplug - https://www.payplug.com/
 * Copyright © Payplug. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace Payplug\Ogloba\Controller\Payment;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\Redirect as RedirectResult;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Message\ManagerInterface as MessageManager;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order\Payment;
use Payplug\Ogloba\Api\Data\OrderPaymentInterface;
use Payplug\Ogloba\Gateway\Config\Ogloba;
use Payplug\Ogloba\Logger\Logger;
use Payplug\Ogloba\Service\IsOrderAwaitingPayment;
use Payplug\Ogloba\Service\IsOrderPaid;
use Throwable;

class Redirect implements HttpGetActionInterface
{
    private const SUCCESS_PATH = 'checkout/onepage/success';
    private const FAILURE_PATH = 'checkout/cart';

    /**
     * @param CheckoutSession $checkoutSession
     * @param RedirectFactory $resultRedirectFactory
     * @param MessageManager $messageManager
     * @param OrderRepositoryInterface $orderRepository
     * @param IsOrderAwaitingPayment $isOrderAwaitingPayment
     * @param IsOrderPaid $isOrderPaid
     * @param Logger $logger
     */
    public function __construct(
        private readonly CheckoutSession $checkoutSession,
        private readonly RedirectFactory $resultRedirectFactory,
        private readonly MessageManager $messageManager,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly IsOrderAwaitingPayment $isOrderAwaitingPayment,
        private readonly IsOrderPaid $isOrderPaid,
        private readonly Logger $logger
    ) {
    }

    /**
     * Send the customer to the payment page, but only while the order is still waiting for a payment
     *
     * @return RedirectResult
     */
    public function execute(): RedirectResult
    {
        $resultRedirect = $this->resultRedirectFactory->create();

        $order = $this->checkoutSession->getLastRealOrder();
        $orderPayment = $order->getPayment();

        if (!$order->getId() || $orderPayment instanceof Payment === false
            || $orderPayment->getMethod() !== Ogloba::METHOD_CODE
        ) {
            $this->logger->error('Ogloba redirect called without a matching order in session.');

            return $resultRedirect->setPath(self::FAILURE_PATH);
        }

        $incrementId = (string) $order->getIncrementId();

        if ($this->isOrderPaid->execute($order)) {
            $this->logger->info(sprintf(
                'Ogloba order %s is already in state %s, the customer is sent to the success page instead of '
                . 'the payment page.',
                $incrementId,
                (string) $order->getState()
            ));

            return $resultRedirect->setPath(self::SUCCESS_PATH);
        }

        if ($this->isOrderAwaitingPayment->execute($order) === false) {
            $this->logger->info(sprintf(
                'Ogloba order %s is in state %s and can no longer be paid, the cart is restored.',
                $incrementId,
                (string) $order->getState()
            ));

            $this->rollbackCart(
                (string) __('This order can no longer be paid. Your cart has been restored, please try again.')
            );

            return $resultRedirect->setPath(self::FAILURE_PATH);
        }

        $redirectUrl = (string) $orderPayment->getAdditionalInformation(OrderPaymentInterface::PAYMENT_URL_KEY);

        if ($redirectUrl === '') {
            $this->logger->error(sprintf(
                'No payment URL found for Ogloba order %s, the cart is restored and the order is left for the '
                . 'notification to resolve.',
                $incrementId
            ));

            $this->rollbackCart(
                (string) __('The payment page could not be reached. Your cart has been restored, please try again.')
            );

            return $resultRedirect->setPath(self::FAILURE_PATH);
        }

        $this->logger->info(sprintf(
            'Ogloba order %s has been placed, redirecting the customer to %s.',
            $incrementId,
            $redirectUrl
        ));

        $this->recordRedirection($order);

        return $resultRedirect->setUrl($redirectUrl);
    }

    /**
     * Note in the order history that the customer was sent to the payment page
     *
     * @param OrderInterface $order
     * @return void
     */
    private function recordRedirection(OrderInterface $order): void
    {
        try {
            $order->addCommentToStatusHistory(
                (string) __('Ogloba: the customer is redirected to the payment page.')
            );

            $this->orderRepository->save($order);
        } catch (Throwable $e) {
            $this->logger->error(sprintf(
                'Ogloba order %s - the redirection could not be noted in the history: %s',
                $order->getIncrementId(),
                $e->getMessage()
            ));
        }
    }

    /**
     * Give the customer their cart back, without ruling on the order
     *
     * @param string $message
     * @return void
     */
    private function rollbackCart(string $message): void
    {
        $this->checkoutSession->restoreQuote();

        $this->messageManager->addErrorMessage($message);
    }
}
