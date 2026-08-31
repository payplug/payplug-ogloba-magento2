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
use Magento\Sales\Api\Data\OrderInterface;
use Payplug\Ogloba\Api\Data\PaymentResponseInterface;
use Payplug\Ogloba\Gateway\Config\Ogloba;
use Payplug\Ogloba\Logger\Logger;
use Throwable;

class Client
{
    private const PAYMENT_ORDERS_PATH = '/gc-thunes-rgw/transaction/V1/payment-orders';
    private const REFUNDS_PATH = '/gc-thunes-rgw/transaction/V1/refunds';
    private const JSON_CONTENT_TYPE = 'application/json; charset=UTF-8';
    private const DEFAULT_TIMEOUT = 30;
    private const PAYMENT_TIMEOUT = 10;
    private const CONNECT_TIMEOUT = 5;
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
     * @param OrderInterface $order
     * @param array $request
     * @return array the redirect URL and the Ogloba side references, keyed by the REDIRECT_URL constants
     * @throws LocalizedException
     */
    public function createPaymentOrder(OrderInterface $order, array $request): array
    {
        $incrementId = (string) $order->getIncrementId();

        $response = $this->call(
            $order,
            self::PAYMENT_ORDERS_PATH,
            $request,
            $this->getCustomerMessage(),
            self::PAYMENT_TIMEOUT,
            asPost: false
        );

        return $this->readResult($response, $incrementId);
    }

    /**
     * Refund a payment order on the Ogloba side, totally or partially
     *
     * @param OrderInterface $order
     * @param array $request
     * @return array the decoded Ogloba response
     * @throws LocalizedException
     */
    public function refund(OrderInterface $order, array $request): array
    {
        $response = $this->call(
            $order,
            self::REFUNDS_PATH,
            $request,
            $this->getOperatorMessage(),
            self::DEFAULT_TIMEOUT,
            asPost: true
        );

        $nested = $response[PaymentResponseInterface::KEY_RESPONSE] ?? null;

        return is_array($nested) ? $nested : $response;
    }

    /**
     * Run one Ogloba call, from the signed request to the decoded response
     *
     * @param OrderInterface $order
     * @param string $path
     * @param array $request
     * @param Phrase $errorMessage
     * @param int $timeout
     * @param bool $asPost
     * @return array the decoded response, once Ogloba has answered without an error
     * @throws LocalizedException
     */
    private function call(
        OrderInterface $order,
        string $path,
        array $request,
        Phrase $errorMessage,
        int $timeout,
        bool $asPost
    ): array {
        $storeId = (int) $order->getStoreId();
        $incrementId = (string) $order->getIncrementId();

        $endpoint = $this->getEndpoint($storeId, $path, $errorMessage);
        $encodedRequest = $this->signature->encode($request);
        $signature = $this->getSignature($encodedRequest, $storeId, $incrementId, $errorMessage);

        $requestUrl = $endpoint;
        $requestBody = null;

        if ($asPost) {
            $requestBody = sprintf('{"request":%s,"sign":"%s"}', $encodedRequest, $signature);
        } else {
            $requestUrl = sprintf('%s?request=%s&sign=%s', $endpoint, rawurlencode($encodedRequest), $signature);
        }

        $this->logger->info(sprintf('Order %s - calling %s.', $incrementId, $endpoint));

        if ($asPost) {
            $this->logger->debug(sprintf('Order %s - refund payload %s', $incrementId, $requestBody));
        } else {
            // The signature is a hash, never the shared secret itself, so it is safe to log
            $this->logger->debug(sprintf(
                'Order %s - request payload %s (sign: %s)',
                $incrementId,
                $encodedRequest,
                $signature
            ));
        }

        $curl = $this->curlFactory->create();
        $curl->setTimeout($timeout);
        $curl->setOption((string)CURLOPT_CONNECTTIMEOUT, self::CONNECT_TIMEOUT);

        if ($asPost) {
            $curl->addHeader('Content-Type', self::JSON_CONTENT_TYPE);
        }

        try {
            if ($asPost) {
                $curl->post($requestUrl, (string) $requestBody);
            } else {
                $curl->get($requestUrl);
            }
        } catch (Throwable $e) {
            $this->logger->error(sprintf(
                'Order %s - network error while calling %s: %s',
                $incrementId,
                $endpoint,
                $e->getMessage()
            ));

            throw new LocalizedException($errorMessage, $e);
        }

        $status = $curl->getStatus();
        $responseBody = $curl->getBody();

        $this->logger->info(sprintf('Order %s - response %d from Ogloba.', $incrementId, $status));
        $this->logger->debug(sprintf('Order %s - response body %s', $incrementId, $responseBody));

        if ($status !== self::HTTP_OK) {
            $this->logger->error(sprintf(
                'Order %s - unexpected HTTP status %d from %s, body: %s',
                $incrementId,
                $status,
                $endpoint,
                $responseBody
            ));

            throw new LocalizedException($errorMessage);
        }

        $response = $this->decode($responseBody, $incrementId, $errorMessage);

        if (!empty($response[self::RESPONSE_ERROR_KEY])) {
            $this->logger->error(sprintf(
                'Order %s - Ogloba returned an error: %s',
                $incrementId,
                $this->json->serialize($response[self::RESPONSE_ERROR_KEY])
            ));

            throw new LocalizedException($errorMessage);
        }

        return $response;
    }

    /**
     * Extract the redirection and the Ogloba references out of an answered payment order
     *
     * @param array $response
     * @param string $incrementId
     * @return array
     * @throws LocalizedException
     */
    private function readResult(array $response, string $incrementId): array
    {
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
     * Build the endpoint of a path on the configured environment
     *
     * @param int $storeId
     * @param string $path
     * @param Phrase $errorMessage
     * @return string
     * @throws LocalizedException
     */
    private function getEndpoint(int $storeId, string $path, Phrase $errorMessage): string
    {
        $host = $this->config->getApiHost($storeId);

        if ($host === '') {
            $this->logger->error(sprintf(
                'No Ogloba host configured for the %s environment.',
                $this->config->getEnvironment($storeId)
            ));

            throw new LocalizedException($errorMessage);
        }

        return $host . $path;
    }

    /**
     * Sign a request, refusing to call Ogloba at all when the scope has no usable shared secret
     *
     * @param string $encodedRequest
     * @param int $storeId
     * @param string $incrementId
     * @param Phrase $errorMessage
     * @return string
     * @throws LocalizedException
     */
    private function getSignature(
        string $encodedRequest,
        int $storeId,
        string $incrementId,
        Phrase $errorMessage
    ): string {
        if ($this->signature->hasKey($storeId) === false) {
            $this->logger->error(sprintf(
                'Order %s - no usable Ogloba HMAC key for store %d, the request is not sent. Check that the key '
                . 'is configured for this scope and that it can still be decrypted.',
                $incrementId,
                $storeId
            ));

            throw new LocalizedException($errorMessage);
        }

        return $this->signature->sign($encodedRequest, $storeId);
    }

    /**
     * Decode the Ogloba response body
     *
     * @param string $body
     * @param string $incrementId
     * @param Phrase $errorMessage
     * @return array
     * @throws LocalizedException
     */
    private function decode(string $body, string $incrementId, Phrase $errorMessage): array
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

            throw new LocalizedException($errorMessage, $e);
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

    /**
     * Get the message shown to the operator when the refund cannot be processed
     *
     * @return Phrase
     */
    private function getOperatorMessage(): Phrase
    {
        return __('The Ogloba refund could not be processed. Check the Ogloba log for the details.');
    }
}
