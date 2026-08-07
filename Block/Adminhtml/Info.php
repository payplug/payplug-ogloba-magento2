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
use Payplug\Ogloba\Api\Data\OrderPaymentInterface;
use Payplug\Ogloba\Api\Data\ResponseChannelInterface;
use Payplug\Ogloba\Model\Config\Source\Environment;
use Payplug\Ogloba\Service\GetTranslatedOglobaStatus;

class Info extends BaseInfo
{
    /**
     * @param Context $context
     * @param GetTranslatedOglobaStatus $getTranslatedOglobaStatus
     * @param array $data
     */
    public function __construct(
        Context $context,
        private readonly GetTranslatedOglobaStatus $getTranslatedOglobaStatus,
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
            (string) __('Ogloba environment') => $this->getEnvironment($paymentInfo->getAdditionalInformation(
                OrderPaymentInterface::ENVIRONMENT_KEY
            )),
            (string) __('Ogloba order ID') => (string) $paymentInfo->getAdditionalInformation(
                OrderPaymentInterface::PAYMENT_METHOD_ORDER_ID_KEY
            ),
            (string) __('Ogloba operation ID') => (string) $paymentInfo->getAdditionalInformation(
                OrderPaymentInterface::VALIDATE_CODE_KEY
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
            (string) __('Resolved by') => $this->getResolvedBy($paymentInfo->getAdditionalInformation(
                OrderPaymentInterface::RESOLVED_BY_KEY
            )),
        ]);
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

    /**
     * Say which channel resolved the transaction, notification or customer return
     *
     * @param mixed $resolvedBy
     * @return string
     */
    private function getResolvedBy(mixed $resolvedBy): string
    {
        return (string) match ((string) $resolvedBy) {
            ResponseChannelInterface::NOTIFICATION => __('Ogloba notification'),
            ResponseChannelInterface::PAYMENT_RETURN => __('Customer return'),
            default => 'N/A',
        };
    }
}
