<?php
/**
 * Payplug - https://www.payplug.com/
 * Copyright © Payplug. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace Payplug\Ogloba\Service;

use Magento\Framework\Phrase;
use Payplug\Ogloba\Api\Data\PaymentResponseInterface;

class GetResponseCodeLabel
{
    /**
     * Give a response code the meaning the Thunes specification states for it, none when it states none
     *
     * @param string $code
     * @return Phrase|null
     */
    public function execute(string $code): ?Phrase
    {
        return match ($code) {
            PaymentResponseInterface::RESPONSE_CODE_OK => __('OK'),
            PaymentResponseInterface::RESPONSE_CODE_NOT_OK => __('Not Ok'),
            PaymentResponseInterface::RESPONSE_CODE_TECHNICAL_ERROR => __('Technical Error'),
            PaymentResponseInterface::RESPONSE_CODE_BAD_CREDENTIALS => __('Bad Credentials'),
            PaymentResponseInterface::RESPONSE_CODE_INSUFFICIENT_BALANCE => __('Insufficient Balance'),
            PaymentResponseInterface::RESPONSE_CODE_TIMEOUT => __('Timeout'),
            PaymentResponseInterface::RESPONSE_CODE_BAD_REQUEST => __('Bad Request'),
            PaymentResponseInterface::RESPONSE_CODE_NOT_AVAILABLE => __('Not Available'),
            default => null,
        };
    }
}
