<?php
/**
 * Payplug - https://www.payplug.com/
 * Copyright © Payplug. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace Payplug\Ogloba\Api\Data;

use Magento\Sales\Api\Data\OrderPaymentInterface as BaseOrderPaymentInterface;

interface OrderPaymentInterface extends BaseOrderPaymentInterface
{
    public const PAYMENT_URL_KEY = 'payment_url';
    public const LIMONETIK_ORDER_ID_KEY = 'limonetik_order_id';
    public const LIMONETIK_OPERATION_ID_KEY = 'limonetik_operation_id';
    public const TEMP_ORDER_SEQNO_KEY = 'temp_order_seqno';
    public const VALIDATE_CODE_KEY = 'validate_code';
    public const OGLOBA_ORDER_STATUS_KEY = 'ogloba_order_status';
    public const RESOLVED_BY_KEY = 'resolved_by';
    public const PAYMENT_METHOD_ORDER_ID_KEY = 'payment_method_order_id';
    public const ENVIRONMENT_KEY = 'environment';
}
