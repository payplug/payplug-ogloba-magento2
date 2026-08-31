<?php
/**
 * Payplug - https://www.payplug.com/
 * Copyright © Payplug. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace Payplug\Ogloba\Model\Api;

use Magento\Framework\Exception\LocalizedException;
use Payplug\Ogloba\Api\Data\PaymentRequestInterface;
use Payplug\Ogloba\Service\FormatOglobaAmount;
use Payplug\Ogloba\Service\GenerateOglobaId;

class RefundRequestBuilder
{
    /**
     * @param GenerateOglobaId $generateOglobaId
     * @param FormatOglobaAmount $formatOglobaAmount
     */
    public function __construct(
        private readonly GenerateOglobaId $generateOglobaId,
        private readonly FormatOglobaAmount $formatOglobaAmount
    ) {
    }

    /**
     * Build the request payload of a total or partial refund
     *
     * @param string $limonetikOrderId
     * @param float $amount
     * @param string $currency
     * @return array
     * @throws LocalizedException
     */
    public function build(string $limonetikOrderId, float $amount, string $currency): array
    {
        return [
            PaymentRequestInterface::KEY_LIMONETIK_ORDER_ID => $limonetikOrderId,
            PaymentRequestInterface::KEY_LIMONETIK_OPERATION_ID => $this->generateOglobaId->execute(),
            'amount' => [
                'value' => $this->formatOglobaAmount->execute($amount),
                'currency' => $currency,
            ],
        ];
    }
}
