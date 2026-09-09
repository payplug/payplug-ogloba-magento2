<?php
/**
 * Payplug - https://www.payplug.com/
 * Copyright © Payplug. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace Payplug\Ogloba\Model\Ui;

use Magento\Checkout\Model\ConfigProviderInterface;
use Payplug\Ogloba\Gateway\Config\Ogloba;

class ConfigProvider implements ConfigProviderInterface
{
    /**
     * @param Ogloba $config
     */
    public function __construct(
        private readonly Ogloba $config
    ) {
    }

    /**
     * Get config
     *
     * @return array
     */
    public function getConfig(): array
    {
        if ($this->config->isActive() === false) {
            return [];
        }

        return [
            'payment' => [
                Ogloba::METHOD_CODE => [
                    'description' => $this->config->getDescription(),
                ],
            ],
        ];
    }
}
