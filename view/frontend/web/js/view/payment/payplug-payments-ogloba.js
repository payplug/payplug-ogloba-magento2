/**
 * Payplug - https://www.payplug.com/
 * Copyright © Payplug. All rights reserved.
 * See LICENSE for license details.
 */
define([
    'uiComponent',
    'Magento_Checkout/js/model/payment/renderer-list'
], function (Component, rendererList) {
    'use strict';

    rendererList.push({
        type: 'payplug_payments_ogloba',
        component: 'Payplug_Ogloba/js/view/payment/method-renderer/payplug-payments-ogloba'
    });

    return Component.extend({});
});
