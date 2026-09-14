<?php
/**
 * Payplug - https://www.payplug.com/
 * Copyright © Payplug. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace Payplug\Ogloba\Block\Adminhtml;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\View\Element\Template\Context;
use Magento\Payment\Block\Info as BaseInfo;
use Magento\Payment\Model\InfoInterface;
use Magento\Sales\Model\Order\Payment;
use Payplug\Ogloba\Api\Data\OrderPaymentInterface;
use Payplug\Ogloba\Api\Data\PaymentResponseInterface;
use Payplug\Ogloba\Model\Config\Source\Environment;
use Payplug\Ogloba\Service\GetTranslatedOglobaStatus;
use Payplug\Ogloba\Service\GetTranslatedResponseChannel;

class Info extends BaseInfo
{
    private const MINOR_UNIT_SCALE = 100;

    /**
     * @param Context $context
     * @param GetTranslatedOglobaStatus $getTranslatedOglobaStatus
     * @param GetTranslatedResponseChannel $getTranslatedResponseChannel
     * @param array $data
     */
    public function __construct(
        Context $context,
        private readonly GetTranslatedOglobaStatus $getTranslatedOglobaStatus,
        private readonly GetTranslatedResponseChannel $getTranslatedResponseChannel,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * List the Ogloba references of the transaction
     *
     * @return array
     * @throws LocalizedException
     */
    public function getSpecificInformation(): array
    {
        if ($this->isSecureMode()) {
            return parent::getSpecificInformation();
        }

        $paymentInfo = $this->getInfo();

        return array_filter([
            (string) __('Ogloba payment status') => $this->getTranslatedOglobaStatus->execute(
                (string) $paymentInfo->getAdditionalInformation(OrderPaymentInterface::OGLOBA_ORDER_STATUS_KEY)
            ),
            (string) __('Ogloba refund status') => $this->getRefundStatusLabel($paymentInfo),
            (string) __('Ogloba environment') => $this->getEnvironment($paymentInfo->getAdditionalInformation(
                OrderPaymentInterface::ENVIRONMENT_KEY
            )),
            (string) __('Ogloba order ID') => (string) $paymentInfo->getAdditionalInformation(
                OrderPaymentInterface::PAYMENT_METHOD_ORDER_ID_KEY
            ),
            (string) __('Ogloba payment operation ID') => (string) $paymentInfo->getAdditionalInformation(
                OrderPaymentInterface::VALIDATE_CODE_KEY
            ),
            (string) __('Ogloba refund operation ID') => (string) $paymentInfo->getAdditionalInformation(
                OrderPaymentInterface::LAST_REFUND_OPERATION_ID_KEY
            ),
            (string) __('Ogloba order sequence') => (string) $paymentInfo->getAdditionalInformation(
                OrderPaymentInterface::TEMP_ORDER_SEQNO_KEY
            ),
            (string) __('Limonetik order ID') => (string) $paymentInfo->getAdditionalInformation(
                OrderPaymentInterface::LIMONETIK_ORDER_ID_KEY
            ),
            (string) __('Limonetik operation ID') => (string) $paymentInfo->getAdditionalInformation(
                OrderPaymentInterface::LIMONETIK_OPERATION_ID_KEY
            ),
            (string) __('Resolved by') => $this->getTranslatedResponseChannel->execute(
                (string) $paymentInfo->getAdditionalInformation(OrderPaymentInterface::RESOLVED_BY_KEY)
            ),
        ]);
    }

    /**
     * Word the refund status, naming a refund that only covers part of what was paid
     *
     * @param InfoInterface $paymentInfo
     * @return string
     */
    private function getRefundStatusLabel(InfoInterface $paymentInfo): string
    {
        $status = (string) $paymentInfo->getAdditionalInformation(OrderPaymentInterface::REFUND_STATUS_KEY);
        $label = $this->getTranslatedOglobaStatus->execute($status);

        if ($status !== PaymentResponseInterface::STATUS_REFUNDED || $paymentInfo instanceof Payment === false) {
            return $label;
        }

        $refunded = $this->toMinorUnit((float) $paymentInfo->getAmountRefunded());
        $paid = $this->toMinorUnit((float) $paymentInfo->getAmountPaid());

        if ($refunded <= 0 || $refunded >= $paid) {
            return $label;
        }

        return (string) __('Partially refunded');
    }

    /**
     * Bring an amount to its smallest unit, the scale at which two sums compare exactly
     *
     * @param float $amount
     * @return int
     */
    private function toMinorUnit(float $amount): int
    {
        return (int) round($amount * self::MINOR_UNIT_SCALE);
    }

    /**
     * Is the block rendered for something the customer receives, an order email or a PDF
     *
     * @return bool
     */
    private function isSecureMode(): bool
    {
        return (bool) $this->getData('is_secure_mode');
    }

    /**
     * Name the Ogloba host the order was sent to, as configured when it was placed
     *
     * @param mixed $environment
     * @return string
     */
    private function getEnvironment(mixed $environment): string
    {
        return (string) match ((string) $environment) {
            Environment::PRODUCTION => __('Production'),
            Environment::STAGING => __('Staging'),
            default => 'N/A',
        };
    }
}
