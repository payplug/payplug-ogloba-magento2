<?php
/**
 * Payplug - https://www.payplug.com/
 * Copyright © Payplug. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace Payplug\Ogloba\Service;

use Magento\Framework\App\Area;
use Magento\Framework\DB\TransactionFactory;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\TransactionInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Email\Sender\InvoiceSender;
use Magento\Sales\Model\Order\Invoice;
use Magento\Sales\Model\Order\Payment;
use Magento\Sales\Model\Order\Payment\Transaction;
use Magento\Sales\Model\Service\InvoiceService;
use Magento\Store\Model\App\Emulation;
use Payplug\Ogloba\Api\Data\OrderPaymentInterface;
use Payplug\Ogloba\Api\Data\PaymentResponseInterface;
use Payplug\Ogloba\Logger\Logger;
use Throwable;

class CreateInvoice
{
    public const MESSAGE_QUEUE_TOPIC = 'payplug.ogloba.order.invoicing';

    /**
     * @param OrderRepositoryInterface $orderRepository
     * @param InvoiceService $invoiceService
     * @param TransactionFactory $transactionFactory
     * @param InvoiceSender $invoiceSender
     * @param Emulation $emulation
     * @param Logger $logger
     */
    public function __construct(
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly InvoiceService $invoiceService,
        private readonly TransactionFactory $transactionFactory,
        private readonly InvoiceSender $invoiceSender,
        private readonly Emulation $emulation,
        private readonly Logger $logger
    ) {
    }

    /**
     * Create the invoice of a charged order, once
     *
     * @param int $orderId
     * @return void
     */
    public function execute(int $orderId): void
    {
        try {
            $order = $this->orderRepository->get($orderId);
        } catch (Throwable $e) {
            $this->logger->error(sprintf(
                '%s: order %s could not be loaded: %s',
                self::MESSAGE_QUEUE_TOPIC,
                $orderId,
                $e->getMessage()
            ));

            return;
        }

        $incrementId = (string) $order->getIncrementId();

        if ($order->hasInvoices()) {
            $this->logger->info(sprintf(
                '%s: Ogloba order %s is already invoiced.',
                self::MESSAGE_QUEUE_TOPIC,
                $incrementId
            ));

            return;
        }

        if ($order->canInvoice() === false) {
            $this->logger->error(sprintf(
                '%s: Ogloba order %s cannot be invoiced in state %s.',
                self::MESSAGE_QUEUE_TOPIC,
                $incrementId,
                (string) $order->getState()
            ));

            return;
        }

        $invoice = $this->create($order, $incrementId);

        if ($invoice === null) {
            return;
        }

        $this->logger->info(sprintf(
            '%s: Ogloba order %s invoiced for %.2F %s, total paid is now %.2F.',
            self::MESSAGE_QUEUE_TOPIC,
            $incrementId,
            (float) $invoice->getGrandTotal(),
            (string) $order->getOrderCurrencyCode(),
            (float) $order->getTotalPaid()
        ));

        $this->sendEmail($invoice, $incrementId);
    }

    /**
     * Register then persist the invoice alongside the order it pays
     *
     * @param OrderInterface $order
     * @param string $incrementId
     * @return Invoice|null
     */
    private function create(OrderInterface $order, string $incrementId): ?Invoice
    {
        if ($order instanceof Order === false) {
            $this->logger->error(sprintf(
                '%s: Ogloba order %s could not be invoiced. Order is not instance of core concrete class',
                self::MESSAGE_QUEUE_TOPIC,
                $incrementId
            ));

            return null;
        }

        try {
            $invoice = $this->invoiceService->prepareInvoice($order);
            $invoice->setRequestedCaptureCase(Invoice::CAPTURE_OFFLINE);
            $invoice->register();

            $this->recordTransaction($order, $invoice);

            $order->addCommentToStatusHistory($this->getHistoryComment($order));

            $this->transactionFactory->create()->addObject($invoice)->addObject($order)->save();
        } catch (Throwable $e) {
            $this->logger->error(sprintf(
                '%s: Ogloba order %s could not be invoiced: %s',
                self::MESSAGE_QUEUE_TOPIC,
                $incrementId,
                $e->getMessage()
            ));

            return null;
        }

        return $invoice;
    }

    /**
     * Translate the history comment in the locale of the store the order was placed in
     *
     * @param OrderInterface $order
     * @return string
     */
    private function getHistoryComment(OrderInterface $order): string
    {
        $this->emulation->startEnvironmentEmulation((int) $order->getStoreId(), Area::AREA_FRONTEND, true);

        try {
            return (string) __('Ogloba: the invoice has been created automatically further to the charged payment.');
        } finally {
            $this->emulation->stopEnvironmentEmulation();
        }
    }

    /**
     * Record the capture as a Magento transaction, so the order has one to show and to refund from
     *
     * @param OrderInterface $order
     * @param Invoice $invoice
     * @return void
     */
    private function recordTransaction(OrderInterface $order, Invoice $invoice): void
    {
        $payment = $order->getPayment();
        $operationId = $this->getTransactionId($order);

        if ($payment instanceof Payment === false) {
            $this->logger->error(sprintf(
                '%s: Order payment object is not instance of core concrete class',
                self::MESSAGE_QUEUE_TOPIC
            ));

            return;
        }

        if ($operationId === '') {
            $this->logger->error(sprintf(
                '%s: Ogloba order %s has no operation to record a transaction against.',
                self::MESSAGE_QUEUE_TOPIC,
                $order->getIncrementId()
            ));

            return;
        }

        $payment->setTransactionId($operationId);
        $payment->setIsTransactionClosed(true);
        /** @noinspection PhpParamsInspection */
        $payment->setTransactionAdditionalInfo(Transaction::RAW_DETAILS, [
            PaymentResponseInterface::KEY_ORDER_STATUS => (string) $payment->getAdditionalInformation(
                OrderPaymentInterface::OGLOBA_ORDER_STATUS_KEY
            ),
            OrderPaymentInterface::RESOLVED_BY_KEY => (string) $payment->getAdditionalInformation(
                OrderPaymentInterface::RESOLVED_BY_KEY
            ),
        ]);

        $payment->addTransaction(TransactionInterface::TYPE_CAPTURE, $invoice);
    }

    /**
     * Send the invoice email, which Magento only does when the merchant enabled it
     *
     * @param Invoice $invoice
     * @param string $incrementId
     * @return void
     */
    private function sendEmail(Invoice $invoice, string $incrementId): void
    {
        try {
            $this->invoiceSender->send($invoice);
        } catch (Throwable $e) {
            $this->logger->error(sprintf(
                '%s: Ogloba order %s - the invoice email could not be sent: %s',
                self::MESSAGE_QUEUE_TOPIC,
                $incrementId,
                $e->getMessage()
            ));
        }
    }

    /**
     * Carry the Ogloba operation onto the invoice, so the two can be reconciled
     *
     * @param OrderInterface $order
     * @return string
     */
    private function getTransactionId(OrderInterface $order): string
    {
        $payment = $order->getPayment();

        if ($payment instanceof Payment === false) {
            $this->logger->error(sprintf(
                '%s: Order payment object is not instance of core concrete class',
                self::MESSAGE_QUEUE_TOPIC
            ));

            return '';
        }

        return (string) $payment->getAdditionalInformation(OrderPaymentInterface::VALIDATE_CODE_KEY);
    }
}
