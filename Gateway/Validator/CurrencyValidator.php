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
use Magento\Payment\Gateway\Validator\ResultInterfaceFactory;
use Payplug\Ogloba\Service\IsCurrencySupported;

class CurrencyValidator extends AbstractValidator
{
    /**
     * @param ResultInterfaceFactory $resultFactory
     * @param IsCurrencySupported $isCurrencySupported
     */
    public function __construct(
        ResultInterfaceFactory $resultFactory,
        private readonly IsCurrencySupported $isCurrencySupported
    ) {
        parent::__construct($resultFactory);
    }

    /**
     * Validate that the base currency the shop books in is supported by Ogloba
     *
     * @param array $validationSubject
     * @return ResultInterface
     */
    public function validate(array $validationSubject): ResultInterface
    {
        $currency = (string) ($validationSubject['currency'] ?? '');

        if ($this->isCurrencySupported->execute($currency)) {
            return $this->createResult(true);
        }

        return $this->createResult(
            false,
            [__('The currency selected is not supported by Ogloba Gift Card.')]
        );
    }
}
