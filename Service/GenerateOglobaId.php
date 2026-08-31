<?php
/**
 * Payplug - https://www.payplug.com/
 * Copyright © Payplug. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace Payplug\Ogloba\Service;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Math\Random;

class GenerateOglobaId
{
    private const ID_LENGTH = 24;

    /**
     * @param Random $random
     */
    public function __construct(
        private readonly Random $random
    ) {
    }

    /**
     * Generate an identifier in the shape Ogloba expects, for a payment order as for a refund
     *
     * @return string
     * @throws LocalizedException
     */
    public function execute(): string
    {
        return $this->random->getRandomString(self::ID_LENGTH, Random::CHARS_DIGITS . Random::CHARS_LOWERS);
    }
}
