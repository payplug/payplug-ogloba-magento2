<?php
/**
 * Payplug - https://www.payplug.com/
 * Copyright © Payplug. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace Payplug\Ogloba\Service;

class FormatOglobaAmount
{
    /**
     * Format an amount the way Ogloba expects it, e.g. "10.00"
     *
     * @param float $amount
     * @return string
     */
    public function execute(float $amount): string
    {
        return number_format($amount, 2, '.', '');
    }
}
