<?php
/**
 * Payplug - https://www.payplug.com/
 * Copyright © Payplug. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace Payplug\Ogloba\Observer;

use Magento\Framework\DataObject;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Payment\Model\MethodInterface;
use Magento\Quote\Api\Data\CartInterface;
use Payplug\Ogloba\Gateway\Config\Ogloba;
use Payplug\Ogloba\Service\IsCurrencySupported;

class DisableOnUnsupportedCurrency implements ObserverInterface
{
    /**
     * @param IsCurrencySupported $isCurrencySupported
     */
    public function __construct(
        private readonly IsCurrencySupported $isCurrencySupported
    ) {
    }

    /**
     * Make Ogloba unavailable when the quote is not in the currency supported by Ogloba
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer): void
    {
        /** @var MethodInterface|null $methodInstance */
        $methodInstance = $observer->getData('method_instance');

        if ($methodInstance === null || $methodInstance->getCode() !== Ogloba::METHOD_CODE) {
            return;
        }

        /** @var CartInterface|null $quote */
        $quote = $observer->getData('quote');

        if ($quote === null) {
            return;
        }

        if ($this->isCurrencySupported->execute((string) $quote->getCurrency()->getQuoteCurrencyCode())) {
            return;
        }

        /** @var DataObject $checkResult */
        $checkResult = $observer->getData('result');
        $checkResult->setData('is_available', false);
    }
}
