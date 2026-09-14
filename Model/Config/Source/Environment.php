<?php
/**
 * Payplug - https://www.payplug.com/
 * Copyright © Payplug. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace Payplug\Ogloba\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class Environment implements OptionSourceInterface
{
    public const STAGING = 'staging';
    public const PRODUCTION = 'production';

    /**
     * Get available Ogloba environments
     *
     * @return array
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => self::STAGING, 'label' => __('Staging')],
            ['value' => self::PRODUCTION, 'label' => __('Production')],
        ];
    }
}
