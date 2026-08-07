<?php
/**
 * Payplug - https://www.payplug.com/
 * Copyright © Payplug. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace Payplug\Ogloba\Api\Data;

interface OrderLockInterface
{
    public const LOCK_PREFIX = 'payplug_ogloba_operation_';
    public const LOCK_TIMEOUT = 5;
}
