<?php
/**
 * Payplug - https://www.payplug.com/
 * Copyright © Payplug. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace Payplug\Ogloba\Gateway\Command;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Payment\Gateway\CommandInterface;
use Magento\Payment\Gateway\Helper\SubjectReader;
use Magento\Sales\Model\Order\Payment;
use Payplug\Ogloba\Api\Data\OrderPaymentInterface;
use Payplug\Ogloba\Gateway\Config\Ogloba;
use Payplug\Ogloba\Logger\Logger;
use Payplug\Ogloba\Model\Api\Client;
use Payplug\Ogloba\Model\Api\PaymentOrderRequestBuilder;

class CreatePaymentCommand implements CommandInterface
{
    /**
     * @param PaymentOrderRequestBuilder $requestBuilder
     * @param Client $client
     * @param Ogloba $config
     * @param Logger $logger
     */
    public function __construct(
        private readonly PaymentOrderRequestBuilder $requestBuilder,
        private readonly Client $client,
        private readonly Ogloba $config,
        private readonly Logger $logger
    ) {
    }

    /**
     * Create the payment order on the Ogloba side and keep its references on the order payment
     *
     * @param array $commandSubject
     * @return void
     * @throws LocalizedException
     * @throws NoSuchEntityException
     */
    public function execute(array $commandSubject): void
    {
        $paymentDO = SubjectReader::readPayment($commandSubject);
        $payment = $paymentDO->getPayment();

        if ($payment instanceof Payment === false) {
            return;
        }

        $order = $payment->getOrder();
        $order->setCanSendNewEmailFlag(false);

        $this->logger->info(sprintf('Retrieving the payment URL for order %s.', $order->getIncrementId()));

        $request = $this->requestBuilder->build($order);
        $result = $this->client->createPaymentOrder($order, $request);

        $payment->setAdditionalInformation(OrderPaymentInterface::PAYMENT_URL_KEY, $result[Client::REDIRECT_URL]);
        $payment->setAdditionalInformation(OrderPaymentInterface::VALIDATE_CODE_KEY, $result[Client::VALIDATE_CODE]);
        $payment->setAdditionalInformation(
            OrderPaymentInterface::ENVIRONMENT_KEY,
            $this->config->getEnvironment((int) $order->getStoreId())
        );
        $payment->setAdditionalInformation(
            OrderPaymentInterface::LIMONETIK_ORDER_ID_KEY,
            $request[PaymentOrderRequestBuilder::LIMONETIK_ORDER_ID]
        );
        $payment->setAdditionalInformation(
            OrderPaymentInterface::LIMONETIK_OPERATION_ID_KEY,
            $request[PaymentOrderRequestBuilder::LIMONETIK_OPERATION_ID]
        );
        $payment->setAdditionalInformation(
            OrderPaymentInterface::TEMP_ORDER_SEQNO_KEY,
            $result[Client::TEMP_ORDER_SEQNO]
        );
    }
}
