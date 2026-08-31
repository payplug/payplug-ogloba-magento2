<?php
/**
 * Payplug - https://www.payplug.com/
 * Copyright © Payplug. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace Payplug\Ogloba\Api\Data;

interface PaymentResponseInterface
{
    public const STATUS_CREATED = 'Created';
    public const STATUS_PAYMENT_IN_PROGRESS = 'Payment_In_Progress';
    public const STATUS_AUTHORIZING = 'Authorizing';
    public const STATUS_AUTHORIZED = 'Authorized';
    public const STATUS_CHARGED = 'Charged';
    public const STATUS_ABORTED = 'Aborted';
    public const STATUS_REFUSED = 'Refused';
    public const STATUS_ERROR = 'Error';
    public const STATUS_CANCELLED = 'Cancelled';
    public const STATUS_REFUNDED = 'Refunded';
    public const STATUS_REFUNDING = 'Refunding';
    public const KEY_RESPONSE = 'response';
    public const KEY_SIGN = 'sign';
    public const KEY_ORDER_STATUS = 'orderStatus';
    public const KEY_RESPONSE_CODE = 'responseCode';
    public const KEY_RESPONSE_LABEL = 'responseLabel';
    public const KEY_OPERATION_ID = 'paymentMethodOperationId';
    public const KEY_PAYMENT_METHOD_ORDER_ID = 'paymentMethodOrderId';
}
