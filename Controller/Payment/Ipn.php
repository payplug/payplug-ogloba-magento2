<?php
/**
 * Payplug - https://www.payplug.com/
 * Copyright © Payplug. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace Payplug\Ogloba\Controller\Payment;

use Laminas\Http\Response as HttpResponse;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\Http;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Raw;
use Magento\Framework\Controller\Result\RawFactory;
use Magento\Framework\Serialize\Serializer\Json;
use Payplug\Ogloba\Api\Data\PaymentResponseInterface;
use Payplug\Ogloba\Api\Data\ResponseChannelInterface;
use Payplug\Ogloba\Exception\InvalidSignatureException;
use Payplug\Ogloba\Logger\Logger;
use Payplug\Ogloba\Model\Payment\PaymentResponse;
use Payplug\Ogloba\Service\ResolvePayment;
use Throwable;

class Ipn implements HttpPostActionInterface, CsrfAwareActionInterface
{
    private const RESPONSE_SUCCESS = 'OK';
    private const RESPONSE_FAILURE = 'KO';

    /**
     * @param Http $request
     * @param RawFactory $resultRawFactory
     * @param ResolvePayment $resolvePayment
     * @param Json $json
     * @param Logger $logger
     */
    public function __construct(
        private readonly RequestInterface $request,
        private readonly RawFactory $resultRawFactory,
        private readonly ResolvePayment $resolvePayment,
        private readonly Json $json,
        private readonly Logger $logger
    ) {
    }

    /**
     * Handle an incoming Ogloba notification
     *
     * @return Raw
     */
    public function execute(): Raw
    {
        $body = (string) $this->request->getContent();

        $this->logger->info(sprintf('Ogloba notification received from %s.', $this->getSourceIp()));
        $this->logger->debug(sprintf('Ogloba notification payload: %s', $body));

        try {
            $payload = $this->json->unserialize($body);
        } catch (Throwable $e) {
            $this->logger->error(sprintf('Unable to decode the Ogloba notification: %s', $e->getMessage()));

            return $this->getResponse(HttpResponse::STATUS_CODE_400, self::RESPONSE_FAILURE);
        }

        $response = is_array($payload) ? ($payload[PaymentResponseInterface::KEY_RESPONSE] ?? null) : null;

        if (!is_array($response)) {
            $this->logger->error('Ogloba notification does not hold a response payload.');

            return $this->getResponse(HttpResponse::STATUS_CODE_400, self::RESPONSE_FAILURE);
        }

        $signature = (string) ($payload[PaymentResponseInterface::KEY_SIGN] ?? '');

        try {
            $this->resolvePayment->execute(
                new PaymentResponse($response, '', $signature),
                ResponseChannelInterface::NOTIFICATION
            );
        } catch (InvalidSignatureException) {
            $this->logger->error(sprintf(
                'Invalid signature on the Ogloba notification received from %s: %s',
                $this->getSourceIp(),
                $body
            ));

            return $this->getResponse(HttpResponse::STATUS_CODE_401, self::RESPONSE_FAILURE);
        } catch (Throwable $e) {
            $this->logger->error(sprintf('Unable to process the Ogloba notification: %s', $e->getMessage()));

            return $this->getResponse(HttpResponse::STATUS_CODE_500, self::RESPONSE_FAILURE);
        }

        return $this->getResponse(HttpResponse::STATUS_CODE_200, self::RESPONSE_SUCCESS);
    }

    /**
     * Required by the interface but never reached, as validateForCsrf() never refuses a request
     *
     * @param RequestInterface $request
     * @return InvalidRequestException|null
     */
    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    /**
     * Ogloba is a server to server caller and cannot carry a form key
     *
     * @param RequestInterface $request
     * @return bool|null
     */
    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }

    /**
     * Get the IP the notification comes from
     *
     * @return string
     */
    private function getSourceIp(): string
    {
        return (string) $this->request->getServer('REMOTE_ADDR');
    }

    /**
     * Build the raw response returned to Ogloba
     *
     * @param int $httpCode
     * @param string $content
     * @return Raw
     */
    private function getResponse(int $httpCode, string $content): Raw
    {
        $resultRaw = $this->resultRawFactory->create();
        $resultRaw->setHttpResponseCode($httpCode);
        $resultRaw->setContents($content);

        return $resultRaw;
    }
}
