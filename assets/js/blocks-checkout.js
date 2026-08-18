(function () {
    'use strict';

    var settings = window.wc.wcSettings.getSetting('b2b_quote_data', {});
    var label = window.wp.htmlEntities.decodeEntities(settings.title || 'Offline quotation');
    var description = window.wp.htmlEntities.decodeEntities(
        settings.description || 'Submit the order to receive a formal quotation and offline payment instructions.'
    );
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
        canMakePayment: function () { return true; },
        supports: {
            features: settings.supports || ['products']
        }
    });
}());
