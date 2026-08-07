<?php
/**
 * Payplug - https://www.payplug.com/
 * Copyright © Payplug. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace Payplug\Ogloba\Model\Api;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\HTTP\Client\CurlFactory;
use Magento\Framework\Phrase;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Sales\Model\Order;
use Payplug\Ogloba\Gateway\Config\Ogloba;
use Payplug\Ogloba\Logger\Logger;
use Throwable;

class Client
{
    private const PAYMENT_ORDERS_PATH = '/gc-thunes-rgw/transaction/V1/payment-orders';
    private const TIMEOUT = 30;
    private const HTTP_OK = 200;
    private const RESPONSE_ERROR_KEY = 'error';
    private const RESPONSE_REDIRECT_URL_KEY = 'redirectURL';
    private const RESPONSE_TEMP_ORDER_SEQNO_KEY = 'tempOrderSeqno';
    private const RESPONSE_VALIDATE_CODE_KEY = 'validateCode';
    public const REDIRECT_URL = 'redirectUrl';
    public const TEMP_ORDER_SEQNO = 'tempOrderSeqno';
    public const VALIDATE_CODE = 'validateCode';

    /**
     * @param CurlFactory $curlFactory
     * @param Signature $signature
     * @param Ogloba $config
     * @param Json $json
     * @param Logger $logger
     */
    public function __construct(
        private readonly CurlFactory $curlFactory,
        private readonly Signature $signature,
        private readonly Ogloba $config,
        private readonly Json $json,
        private readonly Logger $logger
    ) {
    }

    /**
     * Create a payment order on the Ogloba side
     *
     * @param Order $order
     * @param array $request
     * @return array the redirect URL and the Ogloba side references, keyed by the REDIRECT_URL constants
     * @throws LocalizedException
     */
    public function createPaymentOrder(Order $order, array $request): array
    {
        $storeId = (int) $order->getStoreId();
        $incrementId = (string) $order->getIncrementId();

        $endpoint = $this->getEndpoint($storeId);
        $encodedRequest = $this->signature->encode($request);
        $signature = $this->signature->sign($encodedRequest, $storeId);
        $url = sprintf('%s?request=%s&sign=%s', $endpoint, rawurlencode($encodedRequest), $signature);

        $this->logger->info(sprintf('Order %s - calling %s.', $incrementId, $endpoint));

        // The signature is a hash, never the shared secret itself, so it is safe to log
        $this->logger->debug(sprintf(
            'Order %s - request payload %s (sign: %s)',
            $incrementId,
            $encodedRequest,
            $signature
        ));

        $curl = $this->curlFactory->create();
        $curl->setTimeout(self::TIMEOUT);

        try {
            $curl->get($url);
        } catch (Throwable $e) {
            $this->logger->error(sprintf(
                'Order %s - network error while calling %s: %s',
                $incrementId,
                $endpoint,
                $e->getMessage()
            ));

            throw new LocalizedException($this->getCustomerMessage(), $e);
        }

        $status = $curl->getStatus();
        $body = $curl->getBody();

        $this->logger->info(sprintf('Order %s - response %d from Ogloba.', $incrementId, $status));
        $this->logger->debug(sprintf('Order %s - response body %s', $incrementId, $body));

        if ($status !== self::HTTP_OK) {
            $this->logger->error(sprintf(
                'Order %s - unexpected HTTP status %d from %s, body: %s',
                $incrementId,
                $status,
                $endpoint,
                $body
            ));

            throw new LocalizedException($this->getCustomerMessage());
        }

        return $this->readResult($this->decode($body, $incrementId), $incrementId);
    }

    /**
     * Extract the usable data from a successful response, rejecting the payloads carrying an error
     *
     * @param array $response
     * @param string $incrementId
     * @return array
     * @throws LocalizedException
     */
    private function readResult(array $response, string $incrementId): array
    {
        if (!empty($response[self::RESPONSE_ERROR_KEY])) {
            $this->logger->error(sprintf(
                'Order %s - Ogloba returned an error: %s',
                $incrementId,
                $this->json->serialize($response[self::RESPONSE_ERROR_KEY])
            ));

            throw new LocalizedException($this->getCustomerMessage());
        }

        $redirectUrl = (string) ($response[self::RESPONSE_REDIRECT_URL_KEY] ?? '');

        if ($redirectUrl === '') {
            $this->logger->error(sprintf(
                'Order %s - no %s in the Ogloba response.',
                $incrementId,
                self::RESPONSE_REDIRECT_URL_KEY
            ));

            throw new LocalizedException($this->getCustomerMessage());
        }

        $validateCode = (string) ($response[self::RESPONSE_VALIDATE_CODE_KEY] ?? '');

        if ($validateCode === '') {
            $this->logger->error(sprintf(
                'Order %s - no %s in the Ogloba response.',
                $incrementId,
                self::RESPONSE_VALIDATE_CODE_KEY
            ));

            throw new LocalizedException($this->getCustomerMessage());
        }

        return [
            self::REDIRECT_URL => $redirectUrl,
            self::TEMP_ORDER_SEQNO => (string) ($response[self::RESPONSE_TEMP_ORDER_SEQNO_KEY] ?? ''),
            self::VALIDATE_CODE => $validateCode,
        ];
    }

    /**
     * Build the payment orders endpoint of the configured environment
     *
     * @param int $storeId
     * @return string
     * @throws LocalizedException
     */
    private function getEndpoint(int $storeId): string
    {
        $host = $this->config->getApiHost($storeId);

        if ($host === '') {
            $this->logger->error(sprintf(
                'No Ogloba host configured for the %s environment.',
                $this->config->getEnvironment($storeId)
            ));

            throw new LocalizedException($this->getCustomerMessage());
        }

        return $host . self::PAYMENT_ORDERS_PATH;
    }

    /**
     * Decode the Ogloba response body
     *
     * @param string $body
     * @param string $incrementId
     * @return array
     * @throws LocalizedException
     */
    private function decode(string $body, string $incrementId): array
    {
        try {
            $response = $this->json->unserialize($body);
        } catch (Throwable $e) {
            $this->logger->error(sprintf(
                'Order %s - unable to decode the Ogloba response: %s, body: %s',
                $incrementId,
                $e->getMessage(),
                $body
            ));

            throw new LocalizedException($this->getCustomerMessage(), $e);
        }

        return is_array($response) ? $response : [];
    }

    /**
     * Get the message shown to the customer when the payment order cannot be created
     *
     * @return Phrase
     */
    private function getCustomerMessage(): Phrase
    {
        return __('We are unable to reach the payment provider. Please try again or choose another payment method.');
    }
}
