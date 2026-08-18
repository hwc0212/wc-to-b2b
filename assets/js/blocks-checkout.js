(function () {
    'use strict';

    var settings = window.wc.wcSettings.getSetting('b2b_quote_data', {});
    var label = window.wp.htmlEntities.decodeEntities(settings.title || 'Offline quotation');
    var description = window.wp.htmlEntities.decodeEntities(
        settings.description || 'Submit a quote request for administrator review. The formal quote is sent manually.'
    );
    var buttonLabel = window.wp.htmlEntities.decodeEntities(settings.button_label || 'Submit B2B Quote Order');
    var element = window.wp.element.createElement;

    var Content = function () {
        return element('div', { className: 'wc-b2b-blocks-description' }, description);
    };

    window.wc.wcBlocksRegistry.registerPaymentMethod({
        name: 'b2b_quote',
        label: label,
        ariaLabel: label,
        content: element(Content, null),
        edit: element(Content, null),
        placeOrderButtonLabel: buttonLabel,
        canMakePayment: function () { return true; },
        supports: {
            features: settings.supports || ['products']
        }
    });
}());
