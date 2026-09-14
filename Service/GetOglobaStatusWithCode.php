<?php
/**
 * Payplug - https://www.payplug.com/
 * Copyright © Payplug. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace Payplug\Ogloba\Service;

use Payplug\Ogloba\Api\Data\PaymentResponseInterface;
use Payplug\Ogloba\Model\Payment\PaymentResponse;

class GetOglobaStatusWithCode
{
    /**
     * @param GetTranslatedOglobaStatus $getTranslatedOglobaStatus
     */
    public function __construct(
        private readonly GetTranslatedOglobaStatus $getTranslatedOglobaStatus
    ) {
    }

    /**
     * Word an Ogloba status the one way both the order history and the operator email state it
     *
     * @param PaymentResponse $response
     * @param string $status
     * @return string
     */
    public function execute(PaymentResponse $response, string $status): string
    {
        $label = $this->getTranslatedOglobaStatus->execute($status);
        $code = $response->getValue(PaymentResponseInterface::KEY_RESPONSE_CODE);

        return $code === '' ? $label : (string) __('%1 (code %2)', $label, $code);
    }
}
