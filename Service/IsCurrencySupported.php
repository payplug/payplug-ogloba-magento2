<?php
/**
 * Payplug - https://www.payplug.com/
 * Copyright © Payplug. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace Payplug\Ogloba\Service;

use Payplug\Ogloba\Gateway\Config\Ogloba;

class IsCurrencySupported
{
    /**
     * Can Ogloba charge a gift card in that currency
     *
     * @param string $currency
     * @return bool
     */
    public function execute(string $currency): bool
    {
        return strtoupper($currency) === Ogloba::ALLOWED_CURRENCY;
    }
}
