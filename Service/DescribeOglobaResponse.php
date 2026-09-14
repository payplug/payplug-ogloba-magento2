<?php
/**
 * Payplug - https://www.payplug.com/
 * Copyright © Payplug. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace Payplug\Ogloba\Service;

use Payplug\Ogloba\Api\Data\PaymentResponseInterface;
use Payplug\Ogloba\Model\Payment\PaymentResponse;

class DescribeOglobaResponse
{
    /**
     * @param GetTranslatedOglobaStatus $getTranslatedOglobaStatus
     * @param GetResponseCodeLabel $getResponseCodeLabel
     */
    public function __construct(
        private readonly GetTranslatedOglobaStatus $getTranslatedOglobaStatus,
        private readonly GetResponseCodeLabel $getResponseCodeLabel
    ) {
    }

    /**
     * Word an Ogloba answer for a person: the status, then the response code, its meaning and Ogloba's own label
     *
     * @param PaymentResponse $response
     * @return string
     */
    public function execute(PaymentResponse $response): string
    {
        $status = $response->getOrderStatus();
        $status = $status === '' ? 'N/A' : $this->getTranslatedOglobaStatus->execute($status);
        $details = array_filter([$this->describeCode($response), $this->describeLabel($response)]);

        return $details === [] ? $status : sprintf('%s (%s)', $status, implode(', ', $details));
    }

    /**
     * Word the response code with the meaning the Thunes specification gives it
     *
     * @param PaymentResponse $response
     * @return string
     */
    private function describeCode(PaymentResponse $response): string
    {
        $code = $response->getValue(PaymentResponseInterface::KEY_RESPONSE_CODE);

        if ($code === '') {
            return '';
        }

        $meaning = $this->getResponseCodeLabel->execute($code);

        return $meaning === null ? (string) __('code %1', $code) : (string) __('code %1 – %2', $code, $meaning);
    }

    /**
     * Quote the label Ogloba sent along with the code
     *
     * @param PaymentResponse $response
     * @return string
     */
    private function describeLabel(PaymentResponse $response): string
    {
        $label = $response->getValue(PaymentResponseInterface::KEY_RESPONSE_LABEL);

        return $label === '' ? '' : (string) __('label "%1"', $label);
    }
}
