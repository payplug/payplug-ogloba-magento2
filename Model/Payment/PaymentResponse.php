<?php
/**
 * Payplug - https://www.payplug.com/
 * Copyright © Payplug. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace Payplug\Ogloba\Model\Payment;

use Payplug\Ogloba\Api\Data\PaymentResponseInterface;

class PaymentResponse
{
    private const IN_PROGRESS_STATUSES = [
        PaymentResponseInterface::STATUS_CREATED,
        PaymentResponseInterface::STATUS_PAYMENT_IN_PROGRESS,
        PaymentResponseInterface::STATUS_AUTHORIZING,
    ];

    /**
     * @param array $response
     * @param string $encodedResponse
     * @param string $signature
     */
    public function __construct(
        private readonly array $response,
        private readonly string $encodedResponse,
        private readonly string $signature
    ) {
    }

    /**
     * Get the response object the verdict is read from
     *
     * @return array
     */
    public function getResponse(): array
    {
        return $this->response;
    }

    /**
     * Get the response exactly as received, empty when it did not come as a JSON string
     *
     * @return string
     */
    public function getEncodedResponse(): string
    {
        return $this->encodedResponse;
    }

    /**
     * Get the signature received alongside the response
     *
     * @return string
     */
    public function getSignature(): string
    {
        return $this->signature;
    }

    /**
     * Does the response carry anything usable at all
     *
     * @return bool
     */
    public function isEmpty(): bool
    {
        return $this->response === [];
    }

    /**
     * Does Ogloba report the payment as charged
     *
     * @return bool
     */
    public function isCharged(): bool
    {
        return $this->getOrderStatus() === PaymentResponseInterface::STATUS_CHARGED;
    }

    /**
     * Is the payment authorized but not charged yet
     *
     * @return bool
     */
    public function isAuthorized(): bool
    {
        return $this->getOrderStatus() === PaymentResponseInterface::STATUS_AUTHORIZED;
    }

    /**
     * Is the transaction still under way, with no verdict to act upon yet
     *
     * @return bool
     */
    public function isInProgress(): bool
    {
        return in_array($this->getOrderStatus(), self::IN_PROGRESS_STATUSES, true);
    }

    /**
     * Get the transaction status Ogloba reports, e.g. Charged
     *
     * @return string
     */
    public function getOrderStatus(): string
    {
        return $this->getValue(PaymentResponseInterface::KEY_ORDER_STATUS);
    }

    /**
     * Get the Ogloba operation the response belongs to
     *
     * @return string
     */
    public function getOperationId(): string
    {
        return $this->getValue(PaymentResponseInterface::KEY_OPERATION_ID);
    }

    /**
     * Get a value of the response
     *
     * @param string $key
     * @return string
     */
    public function getValue(string $key): string
    {
        $value = $this->response[$key] ?? '';

        return is_scalar($value) ? (string) $value : '';
    }
}
