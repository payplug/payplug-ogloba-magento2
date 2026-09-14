/**
 * Payplug - https://www.payplug.com/
 * Copyright © Payplug. All rights reserved.
 * See LICENSE for license details.
 */
define([
    'Magento_Checkout/js/view/payment/default',
    'mage/url'
], function (Component, url) {
    'use strict';

    return Component.extend({
        defaults: {
            template: 'Payplug_Ogloba/payment/payplug-payments-ogloba'
        },

        /**
         * The customer goes to the payment page instead of the checkout success page.
         */
        redirectAfterPlaceOrder: false,

        /**
         * Get the payment method description set in the admin configuration.
         *
         * @returns {String}
         */
        getDescription: function () {
            let config = window.checkoutConfig.payment[this.getCode()];

            return config && config.description ? config.description : '';
        },

        /**
         * Send the customer to the controller in charge of logging the order and redirecting to the payment page.
         */
        afterPlaceOrder: function () {
            window.location.replace(url.build('payplug_ogloba/payment/redirect'));
        }
    });
});
