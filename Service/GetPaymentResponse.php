<?php
/**
 * Payplug - https://www.payplug.com/
 * Copyright © Payplug. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace Payplug\Ogloba\Service;

use Magento\Framework\App\HttpRequestInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Payplug\Ogloba\Api\Data\PaymentResponseInterface;
use Payplug\Ogloba\Logger\Logger;
use Payplug\Ogloba\Model\Payment\PaymentResponse;
use Throwable;

class GetPaymentResponse
{
    private const MAGENTO_PARAMS = [
        'module',
        'controller',
        'action',
        'form_key',
        'SID',
        '___store',
        '___from_store',
    ];

    /**
     * @param Json $json
     * @param Logger $logger
     */
    public function __construct(
        private readonly Json $json,
        private readonly Logger $logger
    ) {
    }

    /**
     * Read the incoming return request
     *
     * @param HttpRequestInterface $request
     * @return PaymentResponse
     */
    public function execute(HttpRequestInterface $request): PaymentResponse
    {
        $magentoParams = array_flip(self::MAGENTO_PARAMS);
        $query = array_diff_key($this->toArray($request->getQueryValue()), $magentoParams);
        $post = array_diff_key($this->toArray($request->getPostValue()), $magentoParams);
        $routeParams = array_diff_key($this->toArray($request->getParams()), $magentoParams, $query, $post);
        $body = (string) $request->getContent();

        $this->log($request, $query, $post, $routeParams, $body);

        $received = array_merge($routeParams, $query, $post);
        $decodedBody = $this->decode($body);

        if ($decodedBody !== []) {
            $received = array_merge($received, $decodedBody);
        }

        return $this->build($received);
    }

    /**
     * Extract the response object and its signature out of everything that was received
     *
     * @param array $received
     * @return PaymentResponse
     */
    private function build(array $received): PaymentResponse
    {
        $signature = $this->readSignature($received);
        $response = $received[PaymentResponseInterface::KEY_RESPONSE] ?? null;

        if (is_string($response) && $response !== '') {
            return new PaymentResponse($this->decode($response), $response, $signature);
        }

        if (is_array($response)) {
            return new PaymentResponse($response, '', $signature);
        }

        unset($received[PaymentResponseInterface::KEY_SIGN]);

        return new PaymentResponse($received, '', $signature);
    }

    /**
     * Get the signature received alongside the response
     *
     * @param array $received
     * @return string
     */
    private function readSignature(array $received): string
    {
        $signature = $received[PaymentResponseInterface::KEY_SIGN] ?? '';

        return is_scalar($signature) ? (string) $signature : '';
    }

    /**
     * Write down everything the return request carried, so the exchange can be replayed from the log
     *
     * @param HttpRequestInterface $request
     * @param array $query
     * @param array $post
     * @param array $routeParams
     * @param string $body
     * @return void
     */
    private function log(
        HttpRequestInterface $request,
        array $query,
        array $post,
        array $routeParams,
        string $body
    ): void {
        $this->logger->info(sprintf(
            'Ogloba payment return received from %s.',
            $request->getServer('REMOTE_ADDR')
        ));

        $this->logger->debug(sprintf(
            'Ogloba payment return - method: %s, uri: %s, referer: %s, '
            . 'query: %s, post: %s, route params: %s, body: %s',
            $request->getMethod(),
            $request->getUriString(),
            $request->getServer('HTTP_REFERER'),
            $this->json->serialize($query),
            $this->json->serialize($post),
            $this->json->serialize($routeParams),
            $body
        ));
    }

    /**
     * Decode a JSON string, without ever failing on a request we do not control
     *
     * @param string $value
     * @return array
     */
    private function decode(string $value): array
    {
        if (trim($value) === '') {
            return [];
        }

        try {
            $decoded = $this->json->unserialize($value);
        } catch (Throwable $e) {
            $this->logger->error(sprintf('Unable to decode the Ogloba payment return payload: %s', $e->getMessage()));

            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Normalize a request accessor that may return null
     *
     * @param mixed $value
     * @return array
     */
    private function toArray(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }
}
