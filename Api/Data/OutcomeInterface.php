<?php
/**
 * Payplug - https://www.payplug.com/
 * Copyright © Payplug. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace Payplug\Ogloba\Api\Data;

interface OutcomeInterface
{
    public const SUCCESS = 'success';
    public const PENDING = 'pending';
    public const FAILURE = 'failure';
    public const UNCONFIRMED = 'unconfirmed';
}
