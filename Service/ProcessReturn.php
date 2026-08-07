<?php
/**
 * Payplug - https://www.payplug.com/
 * Copyright © Payplug. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace Payplug\Ogloba\Service;

use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order\Payment as OrderPayment;
use Payplug\Ogloba\Api\Data\OrderPaymentInterface;
use Payplug\Ogloba\Api\Data\OutcomeInterface;
use Payplug\Ogloba\Api\Data\ResponseChannelInterface;
use Payplug\Ogloba\Logger\Logger;
use Payplug\Ogloba\Model\Payment\PaymentResponse;
use Throwable;

class ProcessReturn
{
    /**
     * @param ResolvePayment $resolvePayment
     * @param OrderRepositoryInterface $orderRepository
     * @param IsOrderAwaitingPayment $isOrderAwaitingPayment
     * @param IsOrderPaid $isOrderPaid
     * @param IsPaymentResponseSigned $isPaymentResponseSigned
     * @param Logger $logger
     */
    public function __construct(
        private readonly ResolvePayment $resolvePayment,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly IsOrderAwaitingPayment $isOrderAwaitingPayment,
        private readonly IsOrderPaid $isOrderPaid,
        private readonly IsPaymentResponseSigned $isPaymentResponseSigned,
        private readonly Logger $logger
    ) {
    }

    /**
     * Decide what the return means for the customer, and resolve the order only when it is proven
     *
     * @param OrderInterface $order
     * @param PaymentResponse $response
     * @return string
     */
    public function execute(OrderInterface $order, PaymentResponse $response): string
    {
        $this->recordCustomerReturn($order);

        $isProven = $this->isProven($order, $response);

        if ($isProven) {
            try {
                return $this->resolvePayment->execute($response, ResponseChannelInterface::PAYMENT_RETURN);
            } catch (Throwable $e) {
                $this->logger->warning(sprintf(
                    'Ogloba order %s - the payment return is proven but could not resolve the order (%s). The '
                    . 'customer is routed from the verdict it carries, which the notification will apply.',
                    $order->getIncrementId(),
                    $e->getMessage()
                ));
            }
        }

        return $this->route($order, $response, $isProven);
    }

    /**
     * Note in the order history that the customer came back, whatever the return then turns out to say
     *
     * @param OrderInterface $order
     * @return void
     */
    private function recordCustomerReturn(OrderInterface $order): void
    {
        try {
            $order->addCommentToStatusHistory(
                (string) __('Ogloba: the customer came back to the shop from the payment page.')
            );

            $this->orderRepository->save($order);
        } catch (Throwable $e) {
            $this->logger->error(sprintf(
                'Ogloba order %s - the customer return could not be noted in the history: %s',
                $order->getIncrementId(),
                $e->getMessage()
            ));
        }
    }

    /**
     * Send the customer on their way, without writing on the order
     *
     * @param OrderInterface $order
     * @param PaymentResponse $response
     * @param bool $isProven
     * @return string
     */
    private function route(OrderInterface $order, PaymentResponse $response, bool $isProven): string
    {
        $incrementId = (string) $order->getIncrementId();

        if ($this->isOrderPaid->execute($order)) {
            $this->logger->info(sprintf(
                'Ogloba order %s is already in state %s, the customer is sent to the success page.',
                $incrementId,
                (string) $order->getState()
            ));

            return OutcomeInterface::SUCCESS;
        }

        if ($this->isOrderAwaitingPayment->execute($order) === false) {
            $this->logger->info(sprintf(
                'Ogloba order %s is in state %s, the customer is sent back to the cart.',
                $incrementId,
                (string) $order->getState()
            ));

            return OutcomeInterface::FAILURE;
        }

        if ($response->isCharged() || $response->isAuthorized() || $response->isInProgress()) {
            $this->logger->info(sprintf(
                'Ogloba order %s - the payment return reports %s, the customer is sent to the success page '
                . 'and the order is left for the notification to resolve.',
                $incrementId,
                $response->getOrderStatus()
            ));

            return OutcomeInterface::PENDING;
        }

        $this->logger->info(sprintf(
            'Ogloba order %s - the payment return reports %s%s, the cart is restored and the order is left '
            . 'for the notification to resolve.',
            $incrementId,
            $response->getOrderStatus(),
            $isProven ? '' : ' without proving it'
        ));

        return $isProven ? OutcomeInterface::FAILURE : OutcomeInterface::UNCONFIRMED;
    }

    /**
     * Does the return prove both that Ogloba signed it and that it concerns this very order
     *
     * @param OrderInterface $order
     * @param PaymentResponse $response
     * @return bool
     */
    private function isProven(OrderInterface $order, PaymentResponse $response): bool
    {
        $incrementId = (string) $order->getIncrementId();

        if ($response->isEmpty()) {
            $this->logger->warning(sprintf('Ogloba order %s - the payment return carries no payload.', $incrementId));

            return false;
        }

        $operationId = $this->getOperationId($order);

        if ($operationId === '' || $operationId !== $response->getOperationId()) {
            $this->logger->error(sprintf(
                'Ogloba order %s - the payment return is about operation %s, not this order.',
                $incrementId,
                $response->getOperationId()
            ));

            return false;
        }

        if ($this->isPaymentResponseSigned->execute($response, (int) $order->getStoreId()) === false) {
            $this->logger->error(sprintf('Ogloba order %s - invalid signature on the payment return.', $incrementId));

            return false;
        }

        return true;
    }

    /**
     * Get the Ogloba operation the order was created against
     *
     * @param OrderInterface $order
     * @return string
     */
    private function getOperationId(OrderInterface $order): string
    {
        $payment = $order->getPayment();

        if ($payment instanceof OrderPayment === false) {
            return '';
        }

        return (string) $payment->getAdditionalInformation(OrderPaymentInterface::VALIDATE_CODE_KEY);
    }
}
