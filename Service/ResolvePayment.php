<?php
/**
 * Payplug - https://www.payplug.com/
 * Copyright © Payplug. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace Payplug\Ogloba\Service;

use Exception;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\MessageQueue\PublisherInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment;
use Magento\Sales\Model\ResourceModel\Order\Payment\CollectionFactory as PaymentCollectionFactory;
use Payplug\Ogloba\Api\Data\OrderPaymentInterface;
use Payplug\Ogloba\Api\Data\OutcomeInterface;
use Payplug\Ogloba\Api\Data\PaymentResponseInterface;
use Payplug\Ogloba\Exception\InvalidSignatureException;
use Payplug\Ogloba\Exception\OrderLockException;
use Payplug\Ogloba\Gateway\Config\Ogloba;
use Payplug\Ogloba\Logger\Logger;
use Payplug\Ogloba\Model\Payment\PaymentResponse;
use Throwable;

class ResolvePayment
{
    /**
     * @param PaymentCollectionFactory $paymentCollectionFactory
     * @param OrderRepositoryInterface $orderRepository
     * @param IsOrderAwaitingPayment $isOrderAwaitingPayment
     * @param IsOrderPaid $isOrderPaid
     * @param MarkOrderAsProcessing $markOrderAsProcessing
     * @param PublisherInterface $messageQueuePublisher
     * @param CancelOrder $cancelOrder
     * @param OrderLockAcquire $orderLockAcquire
     * @param OrderLockRelease $orderLockRelease
     * @param BuildHistoryComment $buildHistoryComment
     * @param IsPaymentResponseSigned $isPaymentResponseSigned
     * @param SendOrderEmail $sendOrderEmail
     * @param Logger $logger
     */
    public function __construct(
        private readonly PaymentCollectionFactory $paymentCollectionFactory,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly IsOrderAwaitingPayment $isOrderAwaitingPayment,
        private readonly IsOrderPaid $isOrderPaid,
        private readonly MarkOrderAsProcessing $markOrderAsProcessing,
        private readonly PublisherInterface $messageQueuePublisher,
        private readonly CancelOrder $cancelOrder,
        private readonly OrderLockAcquire $orderLockAcquire,
        private readonly OrderLockRelease $orderLockRelease,
        private readonly BuildHistoryComment $buildHistoryComment,
        private readonly IsPaymentResponseSigned $isPaymentResponseSigned,
        private readonly SendOrderEmail $sendOrderEmail,
        private readonly Logger $logger
    ) {
    }

    /**
     * Verify a payment response then resolve the order it belongs to
     *
     * @param PaymentResponse $response
     * @param string $channel
     * @return string
     * @throws InvalidSignatureException
     * @throws OrderLockException
     * @throws LocalizedException
     * @throws Exception
     */
    public function execute(PaymentResponse $response, string $channel): string
    {
        $operationId = $response->getOperationId();

        if ($operationId === '') {
            throw new LocalizedException(
                __('The Ogloba %1 carries no %2.', $channel, PaymentResponseInterface::KEY_OPERATION_ID)
            );
        }

        if ($this->orderLockAcquire->execute($operationId) === false) {
            throw new OrderLockException(
                __('The Ogloba operation %1 is already being resolved elsewhere.', $operationId)
            );
        }

        try {
            return $this->resolve($operationId, $response, $channel);
        } finally {
            $this->orderLockRelease->execute($operationId);
        }
    }

    /**
     * Resolve an order whose transaction nothing else is allowed to touch anymore
     *
     * @param string $operationId
     * @param PaymentResponse $response
     * @param string $channel
     * @return string
     * @throws InvalidSignatureException
     * @throws LocalizedException
     * @throws Exception
     */
    private function resolve(string $operationId, PaymentResponse $response, string $channel): string
    {
        $order = $this->getOrderByOperationId($operationId);
        $payment = $order->getPayment();

        if ($payment === null) {
            throw new Exception(sprintf('Ogloba order %s has no payment object', $order->getIncrementId()));
        }

        if ($this->isPaymentResponseSigned->execute($response, (int) $order->getStoreId()) === false) {
            throw new InvalidSignatureException(
                __('Invalid signature on the %1 of order %2.', $channel, $order->getIncrementId())
            );
        }

        if ($payment->getMethod() !== Ogloba::METHOD_CODE) {
            throw new Exception(sprintf('Order %s is not paid with Ogloba.', $order->getIncrementId()));
        }

        $comment = $this->buildHistoryComment->execute($response, $channel);

        if ($this->isOrderAwaitingPayment->execute($order) === false) {
            return $this->reportResolvedOrder($order, $response, $comment, $channel);
        }

        if ($response->isAuthorized()) {
            return $this->reportUnsupportedAuthorized($order, $response, $comment, $channel);
        }

        if ($response->isInProgress()) {
            return $this->reportInProgress($order, $response, $comment, $channel);
        }

        if ($response->isCharged()) {
            $this->markOrderAsProcessing->execute($order, $comment);
            $outcome = OutcomeInterface::SUCCESS;
        } else {
            $this->cancelOrder->execute($order, $comment);
            $outcome = OutcomeInterface::FAILURE;
        }

        $payment->setAdditionalInformation(
            OrderPaymentInterface::OGLOBA_ORDER_STATUS_KEY,
            $response->getOrderStatus()
        );
        $payment->setAdditionalInformation(OrderPaymentInterface::RESOLVED_BY_KEY, $channel);
        $payment->setAdditionalInformation(
            OrderPaymentInterface::PAYMENT_METHOD_ORDER_ID_KEY,
            $response->getValue(PaymentResponseInterface::KEY_PAYMENT_METHOD_ORDER_ID)
        );

        $this->orderRepository->save($order);

        $this->logger->info(sprintf(
            'Ogloba order %s moved to %s further to the %s.',
            $order->getIncrementId(),
            $order->getState(),
            $channel
        ));

        if ($outcome === OutcomeInterface::SUCCESS) {
            $this->sendOrderEmail->execute($order);
            $this->publishInvoicing($order);
        }

        return $outcome;
    }

    /**
     * Report unsupported Authorized payment
     *
     * @param OrderInterface $order
     * @param PaymentResponse $response
     * @param string $comment
     * @param string $channel
     * @return string
     */
    private function reportUnsupportedAuthorized(
        OrderInterface $order,
        PaymentResponse $response,
        string $comment,
        string $channel
    ): string {
        $this->logger->error(sprintf(
            'Ogloba order %s - the %s reports %s, which this version does not support. The order is left '
            . 'in %s until a final status resolves it, and needs a manual review if none comes.',
            $order->getIncrementId(),
            $channel,
            $response->getOrderStatus(),
            $order->getState()
        ));

        return $this->reportInProgress($order, $response, $comment, $channel);
    }

    /**
     * Record a status that says nothing yet, without touching the order itself
     *
     * @param OrderInterface $order
     * @param PaymentResponse $response
     * @param string $comment
     * @param string $channel
     * @return string
     */
    private function reportInProgress(
        OrderInterface $order,
        PaymentResponse $response,
        string $comment,
        string $channel
    ): string {
        $order->addCommentToStatusHistory($comment);

        $payment = $order->getPayment();

        if ($payment !== null) {
            $payment->setAdditionalInformation(
                OrderPaymentInterface::OGLOBA_ORDER_STATUS_KEY,
                $response->getOrderStatus()
            );
        }

        $this->orderRepository->save($order);

        $this->logger->info(sprintf(
            'Ogloba order %s - the %s reports %s, which is not final, the order is left as it is.',
            $order->getIncrementId(),
            $channel,
            $response->getOrderStatus()
        ));

        return OutcomeInterface::PENDING;
    }

    /**
     * Handle a response landing on an order that is not waiting for it anymore
     *
     * @param OrderInterface $order
     * @param PaymentResponse $response
     * @param string $comment
     * @param string $channel
     * @return string
     */
    private function reportResolvedOrder(
        OrderInterface $order,
        PaymentResponse $response,
        string $comment,
        string $channel
    ): string {
        $outcome = $this->isOrderPaid->execute($order) ? OutcomeInterface::SUCCESS : OutcomeInterface::FAILURE;

        if ($response->isCharged() === false || $order->getState() !== Order::STATE_CANCELED) {
            $this->logger->info(sprintf(
                'Ogloba order %s is already in state %s, the %s is ignored.',
                $order->getIncrementId(),
                $order->getState(),
                $channel
            ));

            return $outcome;
        }

        $this->logger->error(sprintf(
            'Ogloba order %s was cancelled but the %s reports the payment as %s, it needs a manual review.',
            $order->getIncrementId(),
            $channel,
            PaymentResponseInterface::STATUS_CHARGED
        ));

        $order->addCommentToStatusHistory(
            (string) __('%1 The order was already cancelled, a manual review is required.', $comment)
        );

        $this->orderRepository->save($order);

        return $outcome;
    }

    /**
     * Hand the invoicing over to the queue, once the resolution is committed
     *
     * @param OrderInterface $order
     * @return void
     */
    private function publishInvoicing(OrderInterface $order): void
    {
        try {
            $this->messageQueuePublisher->publish(
                CreateInvoice::MESSAGE_QUEUE_TOPIC,
                (int) $order->getEntityId()
            );
        } catch (Throwable $e) {
            $this->logger->error(sprintf(
                'Ogloba order %s could not be queued for invoicing: %s',
                $order->getIncrementId(),
                $e->getMessage()
            ));

            return;
        }

        $this->logger->info(sprintf(
            'Ogloba order %s queued for invoicing on %s.',
            $order->getIncrementId(),
            CreateInvoice::MESSAGE_QUEUE_TOPIC
        ));
    }

    /**
     * Find the order an operation belongs to, the most recently created one if the operation was reused
     *
     * @param string $operationId
     * @return OrderInterface
     * @throws LocalizedException
     */
    private function getOrderByOperationId(string $operationId): OrderInterface
    {
        $collection = $this->paymentCollectionFactory->create();
        $collection->addFieldToFilter('main_table.method', Ogloba::METHOD_CODE);
        $collection->join('sales_order', 'sales_order.entity_id = main_table.parent_id', []);
        $collection->setOrder('sales_order.created_at');
        $collection->setPageSize(1);
        $collection->addFieldToFilter(
            'main_table.additional_information',
            ['like' => '%"' . OrderPaymentInterface::VALIDATE_CODE_KEY . '":"' . $operationId . '"%']
        );

        /** @var Payment $payment */
        $payment = $collection->getFirstItem();
        $validateCode = (string) $payment->getAdditionalInformation(OrderPaymentInterface::VALIDATE_CODE_KEY);

        if ($validateCode !== $operationId) {
            throw new LocalizedException(
                __('No order matching the Ogloba operation %1.', $operationId)
            );
        }

        return $this->orderRepository->get($payment->getParentId());
    }
}
