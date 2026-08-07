<?php
/**
 * Payplug - https://www.payplug.com/
 * Copyright © Payplug. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace Payplug\Ogloba\Gateway\Validator;

use Magento\Payment\Gateway\Validator\AbstractValidator;
use Magento\Payment\Gateway\Validator\ResultInterface;
use Payplug\Ogloba\Gateway\Config\Ogloba;

class CurrencyValidator extends AbstractValidator
{
    /**
     * Validate that the currency is supported by Ogloba
     *
     * @param array $validationSubject
     * @return ResultInterface
     */
    public function validate(array $validationSubject): ResultInterface
    {
        $currency = strtoupper((string) ($validationSubject['currency'] ?? ''));

        if ($currency === Ogloba::ALLOWED_CURRENCY) {
            return $this->createResult(true);
        }

        return $this->createResult(
            false,
            [__('The currency selected is not supported by Ogloba Gift Card.')]
        );
    }
}
