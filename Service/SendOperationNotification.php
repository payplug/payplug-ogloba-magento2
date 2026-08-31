<?php
/**
 * Payplug - https://www.payplug.com/
 * Copyright © Payplug. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace Payplug\Ogloba\Service;

use Magento\Framework\App\Area;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Store\Model\StoreManagerInterface;
use Payplug\Ogloba\Api\Data\OutcomeInterface;
use Payplug\Ogloba\Api\Data\PaymentResponseInterface;
use Payplug\Ogloba\Gateway\Config\Ogloba;
use Payplug\Ogloba\Logger\Logger;
use Payplug\Ogloba\Model\Payment\PaymentResponse;
use Throwable;

class SendOperationNotification
{
    private const TEMPLATES = [
        OutcomeInterface::SUCCESS => 'payplug_ogloba_operation_success',
        OutcomeInterface::FAILURE => 'payplug_ogloba_operation_failure',
    ];
    private const EMAIL_IDENTITY_SCOPE = 'general';

    /**
     * @param Ogloba $config
     * @param TransportBuilder $transportBuilder
     * @param StoreManagerInterface $storeManager
     * @param PriceCurrencyInterface $priceCurrency
     * @param GetOglobaStatusWithCode $getOglobaStatusWithCode
     * @param GetTranslatedResponseChannel $getTranslatedResponseChannel
     * @param Logger $logger
     */
    public function __construct(
        private readonly Ogloba $config,
        private readonly TransportBuilder $transportBuilder,
        private readonly StoreManagerInterface $storeManager,
        private readonly PriceCurrencyInterface $priceCurrency,
        private readonly GetOglobaStatusWithCode $getOglobaStatusWithCode,
        private readonly GetTranslatedResponseChannel $getTranslatedResponseChannel,
        private readonly Logger $logger
    ) {
    }

    /**
     * Tell the operator what became of a transaction the module has just resolved
     *
     * @param OrderInterface $order
     * @param PaymentResponse $response
     * @param string $outcome
     * @param string $channel
     * @return void
     */
    public function execute(OrderInterface $order, PaymentResponse $response, string $outcome, string $channel): void
    {
        $incrementId = (string) $order->getIncrementId();
        $storeId = (int) $order->getStoreId();
        $template = self::TEMPLATES[$outcome] ?? '';

        if ($template === '' || $this->isEnabled($outcome, $storeId) === false) {
            return;
        }

        $recipients = $this->config->getNotificationRecipients($storeId);

        if ($recipients === []) {
            $this->logger->error(sprintf(
                'Ogloba order %s - the %s notification is enabled but no operator email is configured.',
                $incrementId,
                $outcome
            ));

            return;
        }

        try {
            $transport = $this->transportBuilder
                ->setTemplateIdentifier($template)
                ->setTemplateOptions(['area' => Area::AREA_FRONTEND, 'store' => $storeId])
                ->setTemplateVars(['data' => $this->buildVariables($order, $response, $outcome, $channel)])
                ->setFromByScope(self::EMAIL_IDENTITY_SCOPE, $storeId)
                ->addTo($recipients)
                ->getTransport();

            $transport->sendMessage();
        } catch (Throwable $e) {
            $this->logger->error(sprintf(
                'Ogloba order %s - the %s notification could not be sent to the operator: %s',
                $incrementId,
                $outcome,
                $e->getMessage()
            ));

            return;
        }

        $this->logger->info(sprintf(
            'Ogloba order %s - the %s notification has been sent to %s.',
            $incrementId,
            $outcome,
            implode(', ', $recipients)
        ));
    }

    /**
     * Is the operator waiting to hear about that outcome
     *
     * @param string $outcome
     * @param int $storeId
     * @return bool
     */
    private function isEnabled(string $outcome, int $storeId): bool
    {
        return match ($outcome) {
            OutcomeInterface::SUCCESS => $this->config->isSuccessNotificationEnabled($storeId),
            OutcomeInterface::FAILURE => $this->config->isFailureNotificationEnabled($storeId),
            default => false,
        };
    }

    /**
     * Gather what the operator needs to read the transaction without opening the back office
     *
     * @param OrderInterface $order
     * @param PaymentResponse $response
     * @param string $outcome
     * @param string $channel
     * @return array
     */
    private function buildVariables(
        OrderInterface $order,
        PaymentResponse $response,
        string $outcome,
        string $channel
    ): array {
        $storeId = (int) $order->getStoreId();

        return [
            'message' => $this->buildMessage($order, $outcome),
            'increment_id' => (string) $order->getIncrementId(),
            'store_name' => $this->getStoreName($storeId),
            'customer_email' => $order->getCustomerEmail(),
            'grand_total' => $this->priceCurrency->format(
                $order->getGrandTotal(),
                false,
                PriceCurrencyInterface::DEFAULT_PRECISION,
                $storeId,
                $order->getOrderCurrencyCode()
            ),
            'status' => $this->getStatusLabel($response),
            'operation_id' => $response->getOperationId(),
            'payment_method_order_id' => $response->getValue(
                PaymentResponseInterface::KEY_PAYMENT_METHOD_ORDER_ID
            ),
            'channel' => $this->getTranslatedResponseChannel->execute($channel),
            'response_label' => $response->getValue(PaymentResponseInterface::KEY_RESPONSE_LABEL),
        ];
    }

    /**
     * Word the outcome the module has settled on
     *
     * @param OrderInterface $order
     * @param string $outcome
     * @return string
     */
    private function buildMessage(OrderInterface $order, string $outcome): string
    {
        return (string) match ($outcome) {
            OutcomeInterface::SUCCESS => __(
                'The Ogloba payment of order %1 has been charged, the order is being processed.',
                $order->getIncrementId()
            ),
            OutcomeInterface::FAILURE => __(
                'The Ogloba payment of order %1 did not go through, the order has been cancelled.',
                $order->getIncrementId()
            ),
            default => __('The Ogloba payment of order %1 has been resolved.', $order->getIncrementId()),
        };
    }

    /**
     * Put the Ogloba status into the operator language, with the code it came with
     *
     * @param PaymentResponse $response
     * @return string
     */
    private function getStatusLabel(PaymentResponse $response): string
    {
        $status = $response->getOrderStatus();

        if ($status === '') {
            return 'N/A';
        }

        return $this->getOglobaStatusWithCode->execute($response, $status);
    }

    /**
     * Name the store the order was placed on
     *
     * @param int $storeId
     * @return string
     */
    private function getStoreName(int $storeId): string
    {
        try {
            return $this->storeManager->getStore($storeId)->getName();
        } catch (Throwable) {
            return 'N/A';
        }
    }
}
