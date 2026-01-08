jQuery(document).ready(function($) {
    'use strict';
    
    // Handle WhatsApp button clicks
    $('.wc-b2b-whatsapp-button').on('click', function(e) {
        var $button = $(this);
        var productId = $button.data('product-id');
        
        // If we have a direct href, just follow it
        if ($button.attr('href') && $button.attr('href') !== '#') {
            return true;
        }
        
        // Otherwise, generate message via AJAX
        e.preventDefault();
        
        if (!productId) {
            alert(wc_b2b_whatsapp.messages.error);
            return;
        }
        
        $.ajax({
            url: wc_b2b_whatsapp.ajax_url,
            type: 'POST',
            data: {
                action: 'wc_b2b_generate_whatsapp_message',
                product_id: productId,
                nonce: wc_b2b_whatsapp.nonce
            },
            success: function(response) {
                if (response.success && response.data.url) {
                    window.open(response.data.url, '_blank');
                } else {
                    alert(wc_b2b_whatsapp.messages.error);
                }
            },
            error: function() {
                alert(wc_b2b_whatsapp.messages.error);
            }
        });
    });
    
    // Handle variable product changes
    $('form.variations_form').on('found_variation', function(event, variation) {
        var $form = $(this);
        var $button = $form.find('.wc-b2b-whatsapp-button');
        
        if ($button.length && variation.price_html) {
            // Update button with variation info
            updateWhatsAppMessage($button, variation);
        }
    });
    
    function updateWhatsAppMessage($button, variation) {
        var productName = $('.product_title').text();
        var productPrice = variation.price_html;
        var productUrl = window.location.href;
        var siteName = wc_b2b_whatsapp.site_name || 'Our Store';
        
        var message = "Hello! I'm interested in this product:\n\n*" + productName + "*\n";
        if (productPrice) {
            message += "Price: " + productPrice.replace(/<[^>]*>/g, '') + "\n";
        }
        message += "Link: " + productUrl + "\n\nCould you please provide more information?";
        
        var whatsappUrl = 'https://wa.me/' + wc_b2b_whatsapp.phone + '?text=' + encodeURIComponent(message);
        $button.attr('href', whatsappUrl);
    }
});