<?php
/**
 * Payplug - https://www.payplug.com/
 * Copyright © Payplug. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace Payplug\Ogloba\Api\Data;

interface ResponseChannelInterface
{
    public const NOTIFICATION = 'notification';
    public const PAYMENT_RETURN = 'payment return';
}
