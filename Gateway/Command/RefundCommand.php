<?php
/**
 * Payplug - https://www.payplug.com/
 * Copyright © Payplug. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace Payplug\Ogloba\Gateway\Command;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Payment\Gateway\CommandInterface;
use Magento\Payment\Gateway\Helper\SubjectReader;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Model\Order\Payment;
use Payplug\Ogloba\Api\Data\OrderPaymentInterface;
use Payplug\Ogloba\Api\Data\PaymentRequestInterface;
use Payplug\Ogloba\Api\Data\PaymentResponseInterface;
use Payplug\Ogloba\Gateway\Config\Ogloba;
use Payplug\Ogloba\Logger\Logger;
use Payplug\Ogloba\Model\Api\Client;
use Payplug\Ogloba\Model\Api\RefundRequestBuilder;

class RefundCommand implements CommandInterface
{
    /**
     * @param RefundRequestBuilder $requestBuilder
     * @param Client $client
     * @param Ogloba $config
     * @param PriceCurrencyInterface $priceCurrency
     * @param Logger $logger
     */
    public function __construct(
        private readonly RefundRequestBuilder $requestBuilder,
        private readonly Client $client,
        private readonly Ogloba $config,
        private readonly PriceCurrencyInterface $priceCurrency,
        private readonly Logger $logger
    ) {
    }

    /**
     * Send a total or partial refund to Ogloba, which restores the gift card and the card shares itself
     *
     * @param array $commandSubject
     * @return void
     * @throws LocalizedException
     */
    public function execute(array $commandSubject): void
    {
        $paymentDO = SubjectReader::readPayment($commandSubject);
        $payment = $paymentDO->getPayment();

        if ($payment instanceof Payment === false) {
            throw new LocalizedException(
                __('The Ogloba refund could not be processed. Check the Ogloba log for the details.')
            );
        }

        $order = $payment->getOrder();
        $incrementId = (string) $order->getIncrementId();
        $storeId = (int) $order->getStoreId();

        $limonetikOrderId = (string) $payment->getAdditionalInformation(
            OrderPaymentInterface::LIMONETIK_ORDER_ID_KEY
        );

        if ($limonetikOrderId === '') {
            $this->logger->error(sprintf(
                'Order %s - no Limonetik order id on the payment, the refund cannot be sent.',
                $incrementId
            ));

            throw new LocalizedException(
                __('Order %1 carries no Ogloba order reference, it cannot be refunded online.', $incrementId)
            );
        }

        $this->assertSameEnvironment($payment, $incrementId, $storeId);
        $this->assertSameCurrency($order, $incrementId);

        $amount = (float) SubjectReader::readAmount($commandSubject);
        $currency = (string) $order->getOrderCurrencyCode();

        $this->logger->info(sprintf(
            'Order %s - requesting an Ogloba refund of %.2F %s.',
            $incrementId,
            $amount,
            $currency
        ));

        $request = $this->requestBuilder->build($limonetikOrderId, $amount, $currency);
        $operationId = (string) $request[PaymentRequestInterface::KEY_LIMONETIK_OPERATION_ID];

        $response = $this->client->refund($order, $request);
        $status = $this->readOrderStatus($response);

        if ($status !== PaymentResponseInterface::STATUS_REFUNDED
            && $status !== PaymentResponseInterface::STATUS_REFUNDING
        ) {
            $this->logger->error(sprintf(
                'Order %s - Ogloba reported the refund of operation %s as "%s", the credit memo is aborted.',
                $incrementId,
                $operationId,
                $status
            ));

            throw new LocalizedException(__(
                'Ogloba reported the refund of order %1 as %2, the credit memo has not been created.',
                $incrementId,
                $status === '' ? 'N/A' : $status
            ));
        }

        $payment->setTransactionId($operationId);
        $payment->setIsTransactionClosed($status === PaymentResponseInterface::STATUS_REFUNDED);
        $payment->setAdditionalInformation(OrderPaymentInterface::REFUND_STATUS_KEY, $status);
        $payment->setAdditionalInformation(OrderPaymentInterface::LAST_REFUND_OPERATION_ID_KEY, $operationId);

        $order->addCommentToStatusHistory($this->buildComment($status, $amount, $currency, $storeId, $operationId));

        $this->logger->info(sprintf(
            'Order %s - Ogloba accepted the refund of operation %s with status %s.',
            $incrementId,
            $operationId,
            $status
        ));
    }

    /**
     * Refuse to send the refund to another Ogloba environment than the one the order was paid on
     *
     * @param Payment $payment
     * @param string $incrementId
     * @param int $storeId
     * @return void
     * @throws LocalizedException
     */
    private function assertSameEnvironment(Payment $payment, string $incrementId, int $storeId): void
    {
        $paidOn = (string) $payment->getAdditionalInformation(OrderPaymentInterface::ENVIRONMENT_KEY);
        $current = $this->config->getEnvironment($storeId);

        if ($paidOn === '' || $paidOn === $current) {
            return;
        }

        $this->logger->error(sprintf(
            'Order %s - paid on the %s environment while the module now runs on %s, the refund is not sent.',
            $incrementId,
            $paidOn,
            $current
        ));

        throw new LocalizedException(__(
            'Order %1 was paid on the %2 environment but the module is now configured on %3, '
            . 'the online refund cannot be sent.',
            $incrementId,
            $paidOn,
            $current
        ));
    }

    /**
     * Refuse a refund whose amount Magento would not book in the currency it is sent in
     *
     * @param OrderInterface $order
     * @param string $incrementId
     * @return void
     * @throws LocalizedException
     */
    private function assertSameCurrency(OrderInterface $order, string $incrementId): void
    {
        $orderCurrency = (string) $order->getOrderCurrencyCode();
        $baseCurrency = (string) $order->getBaseCurrencyCode();

        if ($orderCurrency === $baseCurrency) {
            return;
        }

        $this->logger->error(sprintf(
            'Order %s is in %s while the shop books in %s, the refund is not sent.',
            $incrementId,
            $orderCurrency,
            $baseCurrency
        ));

        throw new LocalizedException(__(
            'Order %1 is in %2 while the store currency is %3, the online refund cannot be sent.',
            $incrementId,
            $orderCurrency,
            $baseCurrency
        ));
    }

    /**
     * Get the status Ogloba reports on the refund
     *
     * @param array $response
     * @return string
     */
    private function readOrderStatus(array $response): string
    {
        $status = $response[PaymentResponseInterface::KEY_ORDER_STATUS] ?? '';

        return is_scalar($status) ? (string) $status : '';
    }

    /**
     * Build the order history comment naming the refund amount, its operation and where it stands
     *
     * @param string $status
     * @param float $amount
     * @param string $currency
     * @param int $storeId
     * @param string $operationId
     * @return string
     */
    private function buildComment(
        string $status,
        float $amount,
        string $currency,
        int $storeId,
        string $operationId
    ): string {
        $formattedAmount = $this->priceCurrency->format(
            $amount,
            false,
            PriceCurrencyInterface::DEFAULT_PRECISION,
            $storeId,
            $currency
        );

        if ($status === PaymentResponseInterface::STATUS_REFUNDED) {
            return (string) __('Ogloba refund of %1 completed (operation %2).', $formattedAmount, $operationId);
        }

        return (string) __(
            'Ogloba refund of %1 in progress (operation %2), waiting for the Ogloba notification.',
            $formattedAmount,
            $operationId
        );
    }
}
