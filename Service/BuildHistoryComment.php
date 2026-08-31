<?php
/**
 * Payplug - https://www.payplug.com/
 * Copyright © Payplug. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace Payplug\Ogloba\Service;

use Payplug\Ogloba\Api\Data\PaymentResponseInterface;
use Payplug\Ogloba\Api\Data\ResponseChannelInterface;
use Payplug\Ogloba\Model\Payment\PaymentResponse;

class BuildHistoryComment
{
    /**
     * @param GetOglobaStatusWithCode $getOglobaStatusWithCode
     */
    public function __construct(
        private readonly GetOglobaStatusWithCode $getOglobaStatusWithCode
    ) {
    }

    /**
     * Build the comment holding a payment response, naming the channel it came through
     *
     * @param PaymentResponse $response
     * @param string $channel
     * @return string
     */
    public function execute(PaymentResponse $response, string $channel): string
    {
        $status = $response->getOrderStatus();

        if ($status === '') {
            return $channel === ResponseChannelInterface::NOTIFICATION
                ? (string) __('Ogloba notification: No payment status was received.')
                : (string) __('Ogloba payment return: The customer came back without a payment status.');
        }

        $summary = $this->summarise($response, $status);

        if ($channel === ResponseChannelInterface::NOTIFICATION) {
            return (string) __('Ogloba notification - %1', $summary);
        }

        return (string) __('Ogloba payment return - %1', $summary);
    }

    /**
     * Word the status, the code and the description Ogloba sends with it
     *
     * @param PaymentResponse $response
     * @param string $status
     * @return string
     */
    private function summarise(PaymentResponse $response, string $status): string
    {
        $head = $this->getOglobaStatusWithCode->execute($response, $status);

        $note = $this->getHandlingNote($status);
        $sentence = implode(' ', array_filter([
            $this->terminate($response->getValue(PaymentResponseInterface::KEY_RESPONSE_LABEL), $note !== ''),
            $note,
        ]));

        return $sentence === '' ? $head : sprintf('%s: %s', $head, $sentence);
    }

    /**
     * Close what Ogloba wrote so our own sentence does not run into it, as its labels are not punctuated
     *
     * @param string $label
     * @param bool $isFollowed
     * @return string
     */
    private function terminate(string $label, bool $isFollowed): string
    {
        if ($label === '' || $isFollowed === false) {
            return $label;
        }

        return str_ends_with($label, '.') || str_ends_with($label, '!') || str_ends_with($label, '?')
            ? $label
            : $label . '.';
    }

    /**
     * Say what became of the order, but only where the status alone does not make it obvious
     *
     * @param string $status
     * @return string
     */
    private function getHandlingNote(string $status): string
    {
        return (string) match ($status) {
            PaymentResponseInterface::STATUS_CHARGED,
            PaymentResponseInterface::STATUS_ABORTED,
            PaymentResponseInterface::STATUS_REFUSED,
            PaymentResponseInterface::STATUS_ERROR,
            PaymentResponseInterface::STATUS_CANCELLED,
            PaymentResponseInterface::STATUS_REFUNDED,
            PaymentResponseInterface::STATUS_REFUNDING => '',
            PaymentResponseInterface::STATUS_CREATED,
            PaymentResponseInterface::STATUS_PAYMENT_IN_PROGRESS,
            PaymentResponseInterface::STATUS_AUTHORIZING => __('Not a final status, the order is left as it is.'),
            PaymentResponseInterface::STATUS_AUTHORIZED => __(
                'Not supported by this version, the order awaits a final status.'
            ),
            default => __('Unknown status, handled as a failed transaction.'),
        };
    }
}
