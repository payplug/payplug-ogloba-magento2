<?php
/**
 * Payplug - https://www.payplug.com/
 * Copyright © Payplug. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace Payplug\Ogloba\Service;

use Payplug\Ogloba\Api\Data\PaymentResponseInterface;

class GetTranslatedOglobaStatus
{
    /**
     * Put an Ogloba status into the back office language
     *
     * @param string $status
     * @return string
     */
    public function execute(string $status): string
    {
        return (string) match ($status) {
            PaymentResponseInterface::STATUS_CREATED => __('Created'),
            PaymentResponseInterface::STATUS_PAYMENT_IN_PROGRESS => __('Payment in progress'),
            PaymentResponseInterface::STATUS_AUTHORIZING => __('Authorising'),
            PaymentResponseInterface::STATUS_AUTHORIZED => __('Authorised'),
            PaymentResponseInterface::STATUS_CHARGED => __('Charged'),
            PaymentResponseInterface::STATUS_ABORTED => __('Aborted'),
            PaymentResponseInterface::STATUS_REFUSED => __('Refused'),
            PaymentResponseInterface::STATUS_ERROR => __('Error'),
            PaymentResponseInterface::STATUS_CANCELLED => __('Cancelled'),
            PaymentResponseInterface::STATUS_REFUNDED => __('Refunded'),
            PaymentResponseInterface::STATUS_REFUNDING => __('Refunding'),
            default => $status,
        };
    }
}
