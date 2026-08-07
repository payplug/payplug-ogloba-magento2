<?php
/**
 * Payplug - https://www.payplug.com/
 * Copyright © Payplug. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace Payplug\Ogloba\Gateway\Config;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Payment\Gateway\Config\Config as GatewayConfig;
use Payplug\Ogloba\Model\Config\Source\Environment;

class Ogloba extends GatewayConfig
{
    public const METHOD_CODE = 'payplug_payments_ogloba';
    public const ALLOWED_CURRENCY = 'EUR';
    public const KEY_ACTIVE = 'active';
    public const KEY_TITLE = 'title';
    public const KEY_DESCRIPTION = 'description';
    public const KEY_ORDER_STATUS = 'order_status';
    public const KEY_DEFAULT_COUNTRY = 'default_country';
    public const KEY_ENVIRONMENT = 'environment';
    public const KEY_HOST_STAGING = 'host_staging';
    public const KEY_HOST_PRODUCTION = 'host_production';
    public const KEY_MERCHANT_ID = 'merchant_id';
    public const KEY_PAYMENT_PAGE_ID = 'payment_page_id';
    public const KEY_HMAC_KEY = 'hmac_key';
    public const KEY_DEBUG = 'debug';
    public const KEY_NOTIFY_SUCCESS = 'notify_success';
    public const KEY_NOTIFY_FAILURE = 'notify_failure';

    /**
     * @param ScopeConfigInterface $scopeConfig
     * @param EncryptorInterface $encryptor
     * @param string|null $methodCode
     * @param string $pathPattern
     */
    public function __construct(
        ScopeConfigInterface $scopeConfig,
        private readonly EncryptorInterface $encryptor,
        ?string $methodCode = null,
        string $pathPattern = self::DEFAULT_PATH_PATTERN
    ) {
        parent::__construct($scopeConfig, $methodCode, $pathPattern);
    }

    /**
     * Is Active
     *
     * @param int|null $storeId
     * @return bool
     */
    public function isActive(?int $storeId = null): bool
    {
        return (bool) $this->getValue(self::KEY_ACTIVE, $storeId);
    }

    /**
     * Get title
     *
     * @param int|null $storeId
     * @return string
     */
    public function getTitle(?int $storeId = null): string
    {
        return (string) $this->getValue(self::KEY_TITLE, $storeId);
    }

    /**
     * Get description
     *
     * @param int|null $storeId
     * @return string
     */
    public function getDescription(?int $storeId = null): string
    {
        return (string) $this->getValue(self::KEY_DESCRIPTION, $storeId);
    }

    /**
     * Get order status
     *
     * @param int|null $storeId
     * @return string
     */
    public function getOrderStatus(?int $storeId = null): string
    {
        return (string) $this->getValue(self::KEY_ORDER_STATUS, $storeId);
    }

    /**
     * Get default country
     *
     * @param int|null $storeId
     * @return string
     */
    public function getDefaultCountry(?int $storeId = null): string
    {
        return (string) $this->getValue(self::KEY_DEFAULT_COUNTRY, $storeId);
    }

    /**
     * Get the selected Ogloba environment
     *
     * @param int|null $storeId
     * @return string
     */
    public function getEnvironment(?int $storeId = null): string
    {
        return (string) ($this->getValue(self::KEY_ENVIRONMENT, $storeId) ?: Environment::STAGING);
    }

    /**
     * Is the module running against the production environment
     *
     * @param int|null $storeId
     * @return bool
     */
    public function isProduction(?int $storeId = null): bool
    {
        return $this->getEnvironment($storeId) === Environment::PRODUCTION;
    }

    /**
     * Get the API host of the selected environment, without trailing slash
     *
     * @param int|null $storeId
     * @return string
     */
    public function getApiHost(?int $storeId = null): string
    {
        $key = $this->isProduction($storeId) ? self::KEY_HOST_PRODUCTION : self::KEY_HOST_STAGING;

        return rtrim((string) $this->getValue($key, $storeId), '/');
    }

    /**
     * Get merchant id
     *
     * @param int|null $storeId
     * @return string
     */
    public function getMerchantId(?int $storeId = null): string
    {
        return (string) $this->getValue(self::KEY_MERCHANT_ID, $storeId);
    }

    /**
     * Get payment page id
     *
     * @param int|null $storeId
     * @return string
     */
    public function getPaymentPageId(?int $storeId = null): string
    {
        return (string) $this->getValue(self::KEY_PAYMENT_PAGE_ID, $storeId);
    }

    /**
     * Get the decrypted private shared secret key used to sign requests and verify notifications
     *
     * @param int|null $storeId
     * @return string
     */
    public function getHmacKey(?int $storeId = null): string
    {
        return $this->encryptor->decrypt((string) $this->getValue(self::KEY_HMAC_KEY, $storeId));
    }

    /**
     * Is debug mode enabled
     *
     * @param int|null $storeId
     * @return bool
     */
    public function isDebugEnabled(?int $storeId = null): bool
    {
        return (bool) $this->getValue(self::KEY_DEBUG, $storeId);
    }

    /**
     * Is the operator notified on successful transaction
     *
     * @param int|null $storeId
     * @return bool
     */
    public function isSuccessNotificationEnabled(?int $storeId = null): bool
    {
        return (bool) $this->getValue(self::KEY_NOTIFY_SUCCESS, $storeId);
    }

    /**
     * Is the operator notified on failed transaction
     *
     * @param int|null $storeId
     * @return bool
     */
    public function isFailureNotificationEnabled(?int $storeId = null): bool
    {
        return (bool) $this->getValue(self::KEY_NOTIFY_FAILURE, $storeId);
    }
}
