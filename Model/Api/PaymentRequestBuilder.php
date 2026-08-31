<?php
/**
 * Payplug - https://www.payplug.com/
 * Copyright © Payplug. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace Payplug\Ogloba\Model\Api;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Payplug\Ogloba\Api\Data\PaymentRequestInterface;
use Payplug\Ogloba\Gateway\Config\Ogloba;
use Payplug\Ogloba\Service\FormatOglobaAmount;
use Payplug\Ogloba\Service\GenerateOglobaId;

class PaymentRequestBuilder
{
    private const CUSTOMER_TYPE = 'Individual';
    private const LOCALE_CONFIG_PATH = 'general/locale/code';

    /**
     * @param Ogloba $config
     * @param StoreManagerInterface $storeManager
     * @param ScopeConfigInterface $scopeConfig
     * @param GenerateOglobaId $generateOglobaId
     * @param FormatOglobaAmount $formatOglobaAmount
     */
    public function __construct(
        private readonly Ogloba $config,
        private readonly StoreManagerInterface $storeManager,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly GenerateOglobaId $generateOglobaId,
        private readonly FormatOglobaAmount $formatOglobaAmount
    ) {
    }

    /**
     * Build the request payload for an order
     *
     * @param OrderInterface $order
     * @return array
     * @throws LocalizedException
     * @throws NoSuchEntityException
     */
    public function build(OrderInterface $order): array
    {
        $storeId = (int) $order->getStoreId();
        $amount = $this->formatOglobaAmount->execute($order->getGrandTotal());
        $currency = (string) $order->getOrderCurrencyCode();
        $incrementId = (string) $order->getIncrementId();

        return [
            'merchantId' => $this->config->getMerchantId($storeId),
            'paymentPageId' => $this->config->getPaymentPageId($storeId),
            PaymentRequestInterface::KEY_LIMONETIK_ORDER_ID => $this->generateOglobaId->execute(),
            PaymentRequestInterface::KEY_LIMONETIK_OPERATION_ID => $this->generateOglobaId->execute(),
            'amount' => [
                'value' => $amount,
                'currency' => $currency,
            ],
            'merchantUrls' => [
                'redirectionUrl' => $this->getUrl($storeId, 'payplug_ogloba/payment/paymentReturn'),
                'notificationUrl' => $this->getUrl($storeId, 'payplug_ogloba/payment/ipn'),
            ],
            'merchantOrder' => [
                'id' => $incrementId,
                'amountInfo' => [
                    'totalAmount' => [
                        'value' => $amount,
                        'currency' => $currency,
                    ],
                ],
                'customer' => [
                    'type' => self::CUSTOMER_TYPE,
                    'id' => (string) $order->getCustomerId(),
                    'email' => $order->getCustomerEmail(),
                    'culture' => $this->getLocale($storeId),
                ],
                'rawCustom1' => $incrementId,
                'searchableCustom1' => $incrementId,
            ],
        ];
    }

    /**
     * Build an absolute store front URL
     *
     * @param int $storeId
     * @param string $route
     * @return string
     * @throws NoSuchEntityException
     */
    private function getUrl(int $storeId, string $route): string
    {
        return $this->storeManager->getStore($storeId)->getUrl($route, ['_secure' => true, '_nosid' => true]);
    }

    /**
     * Get the locale, e.g. fr-FR
     *
     * @param int $storeId
     * @return string
     */
    private function getLocale(int $storeId): string
    {
        $locale = (string) $this->scopeConfig->getValue(
            self::LOCALE_CONFIG_PATH,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );

        return str_replace('_', '-', $locale);
    }
}
