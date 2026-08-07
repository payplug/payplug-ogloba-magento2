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
use Magento\Framework\App\Request\Http;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Redirect as RedirectResult;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Message\ManagerInterface as MessageManager;
use Magento\Sales\Model\Order\Payment;
use Payplug\Ogloba\Api\Data\OutcomeInterface;
use Payplug\Ogloba\Gateway\Config\Ogloba;
use Payplug\Ogloba\Logger\Logger;
use Payplug\Ogloba\Service\GetPaymentResponse;
use Payplug\Ogloba\Service\ProcessReturn;
use Throwable;

class PaymentReturn implements HttpGetActionInterface
{
    private const SUCCESS_PATH = 'checkout/onepage/success';
    private const FAILURE_PATH = 'checkout/cart';

    /**
     * @param Http $request
     * @param CheckoutSession $checkoutSession
     * @param RedirectFactory $resultRedirectFactory
     * @param MessageManager $messageManager
     * @param GetPaymentResponse $getPaymentResponse
     * @param ProcessReturn $processReturn
     * @param Logger $logger
     */
    public function __construct(
        private readonly RequestInterface $request,
        private readonly CheckoutSession $checkoutSession,
        private readonly RedirectFactory $resultRedirectFactory,
        private readonly MessageManager $messageManager,
        private readonly GetPaymentResponse $getPaymentResponse,
        private readonly ProcessReturn $processReturn,
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

        $order = $this->checkoutSession->getLastRealOrder();
        $orderPayment = $order->getPayment();

        if (!$order->getId() || $orderPayment instanceof Payment === false
            || $orderPayment->getMethod() !== Ogloba::METHOD_CODE
        ) {
            $this->logger->error('Ogloba payment return called without a matching order in session.');

            return $resultRedirect->setPath(self::FAILURE_PATH);
        }

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

            return $resultRedirect->setPath(self::FAILURE_PATH);
        }

        $message = $this->getFailureMessage($outcome);

        if ($message !== '') {
            $this->rollbackCart((string) $order->getIncrementId(), $message);

            return $resultRedirect->setPath(self::FAILURE_PATH);
        }

        return $resultRedirect->setPath(self::SUCCESS_PATH);
    }

    /**
     * Tell the customer why they are not on the success page, claiming only what is known
     *
     * A resolved transaction is stated plainly, as the notification has verified it. An unproven return
     * is only a claim, so the wording promises nothing and points at the resolution to come.
     *
     * @param string $outcome
     * @return string
     */
    private function getFailureMessage(string $outcome): string
    {
        return (string) match ($outcome) {
            OutcomeInterface::FAILURE => __('The payment was not completed and your gift card has not been charged.'),
            OutcomeInterface::UNCONFIRMED => __(
                'Your payment could not be confirmed. Should your gift card have been charged, your order '
                . 'will be validated automatically.'
            ),
            default => '',
        };
    }

    /**
     * Give the customer their cart back
     *
     * @param string $incrementId
     * @param string $message
     * @return void
     */
    private function rollbackCart(string $incrementId, string $message): void
    {
        $this->checkoutSession->setLastRealOrderId($incrementId);
        $this->checkoutSession->restoreQuote();

        $this->messageManager->addErrorMessage($message);
    }
}
