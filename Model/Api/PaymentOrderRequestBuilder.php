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
use Magento\Framework\Math\Random;
use Magento\Sales\Model\Order;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Payplug\Ogloba\Gateway\Config\Ogloba;

class PaymentOrderRequestBuilder
{
    private const ID_LENGTH = 24;
    private const CUSTOMER_TYPE = 'Individual';
    private const LOCALE_CONFIG_PATH = 'general/locale/code';
    public const LIMONETIK_ORDER_ID = 'limonetikOrderId';
    public const LIMONETIK_OPERATION_ID = 'limonetikOperationId';

    /**
     * @param Ogloba $config
     * @param StoreManagerInterface $storeManager
     * @param ScopeConfigInterface $scopeConfig
     * @param Random $random
     */
    public function __construct(
        private readonly Ogloba $config,
        private readonly StoreManagerInterface $storeManager,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly Random $random
    ) {
    }

    /**
     * Build the request payload for an order
     *
     * @param Order $order
     * @return array
     * @throws LocalizedException
     * @throws NoSuchEntityException
     */
    public function build(Order $order): array
    {
        $storeId = (int) $order->getStoreId();
        $amount = $this->formatAmount((float) $order->getGrandTotal());
        $currency = (string) $order->getOrderCurrencyCode();
        $incrementId = (string) $order->getIncrementId();

        return [
            'merchantId' => $this->config->getMerchantId($storeId),
            'paymentPageId' => $this->config->getPaymentPageId($storeId),
            self::LIMONETIK_ORDER_ID => $this->generateId(),
            self::LIMONETIK_OPERATION_ID => $this->generateId(),
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
                    'email' => (string) $order->getCustomerEmail(),
                    'culture' => $this->getLocale($storeId),
                ],
                'rawCustom1' => $incrementId,
                'searchableCustom1' => $incrementId,
            ],
        ];
    }

    /**
     * Generate a unique identifier for the Ogloba transaction
     *
     * @return string
     * @throws LocalizedException
     */
    private function generateId(): string
    {
        return $this->random->getRandomString(self::ID_LENGTH, Random::CHARS_DIGITS . Random::CHARS_LOWERS);
    }

    /**
     * Format an amount the way Ogloba expects it, e.g. "10.00"
     *
     * @param float $amount
     * @return string
     */
    private function formatAmount(float $amount): string
    {
        return number_format($amount, 2, '.', '');
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
