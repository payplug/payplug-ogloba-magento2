<?php
/**
 * Payplug - https://www.payplug.com/
 * Copyright © Payplug. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace Payplug\Ogloba\Service;

use Payplug\Ogloba\Model\Api\Signature;
use Payplug\Ogloba\Model\Payment\PaymentResponse;

class IsPaymentResponseSigned
{
    /**
     * @param Signature $signature
     */
    public function __construct(
        private readonly Signature $signature
    ) {
    }

    /**
     * Is the response really the one Ogloba signed
     *
     * @param PaymentResponse $response
     * @param int $storeId
     * @return bool
     */
    public function execute(PaymentResponse $response, int $storeId): bool
    {
        $encoded = $response->getEncodedResponse();

        if ($encoded !== '') {
            return $this->signature->verifyEncoded($encoded, $response->getSignature(), $storeId);
        }

        return $this->signature->verify($response->getResponse(), $response->getSignature(), $storeId);
    }
}
