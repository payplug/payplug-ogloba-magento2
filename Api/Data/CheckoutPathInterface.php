<?php
/**
 * Payplug - https://www.payplug.com/
 * Copyright © Payplug. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace Payplug\Ogloba\Api\Data;

interface CheckoutPathInterface
{
    public const SUCCESS_PATH = 'checkout/onepage/success';
    public const FAILURE_PATH = 'checkout/cart';
}
