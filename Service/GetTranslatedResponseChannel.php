<?php
/**
 * Payplug - https://www.payplug.com/
 * Copyright © Payplug. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace Payplug\Ogloba\Service;

use Payplug\Ogloba\Api\Data\ResponseChannelInterface;

class GetTranslatedResponseChannel
{
    /**
     * Say which channel resolved the transaction, notification or customer return
     *
     * @param string $channel
     * @return string
     */
    public function execute(string $channel): string
    {
        return (string) match ($channel) {
            ResponseChannelInterface::NOTIFICATION => __('Ogloba notification'),
            ResponseChannelInterface::PAYMENT_RETURN => __('Customer return'),
            default => 'N/A',
        };
    }
}
