jQuery(document).ready(function($) {
    'use strict';
    
    // Confirm order button
    $('.wc-b2b-confirm-btn').on('click', function(e) {
        e.preventDefault();
        
        if (!confirm(wc_b2b_frontend.messages.confirm_order)) {
            return;
        }
        
        var $button = $(this);
        var orderId = $button.data('order-id');
        
        $button.prop('disabled', true).text(wc_b2b_frontend.messages.processing);
        
        $.ajax({
            url: wc_b2b_frontend.ajax_url,
            type: 'POST',
            data: {
                action: 'wc_b2b_customer_action',
                customer_action: 'confirm',
                order_id: orderId,
                nonce: wc_b2b_frontend.confirm_nonce
            },
            success: function(response) {
                if (response.success) {
                    showMessage(response.data.message, 'success');
                    // Return to the signed quote view after accepting.
                    setTimeout(function() {
                        window.location.href = response.data.redirect_url;
                    }, 2000);
                } else {
                    showMessage(response.data.message || 'Error confirming order.', 'error');
                    $button.prop('disabled', false).text('Accept Quote & Pay Offline');
                }
            },
            error: function() {
                showMessage('Error confirming order. Please try again.', 'error');
                $button.prop('disabled', false).text('Accept Quote & Pay Offline');
            }
        });
    });
    
    // Cancel order button
    $('.wc-b2b-cancel-btn').on('click', function(e) {
        e.preventDefault();
        
        if (!confirm(wc_b2b_frontend.messages.cancel_order)) {
            return;
        }
        
        var $button = $(this);
        var orderId = $button.data('order-id');
        
        $button.prop('disabled', true).text(wc_b2b_frontend.messages.processing);
        
        $.ajax({
            url: wc_b2b_frontend.ajax_url,
            type: 'POST',
            data: {
                action: 'wc_b2b_customer_action',
                customer_action: 'cancel',
                order_id: orderId,
                nonce: wc_b2b_frontend.cancel_nonce
            },
            success: function(response) {
                if (response.success) {
                    showMessage(response.data.message, 'success');
                    // Hide action buttons
                    $('.order-actions').fadeOut();
                } else {
                    showMessage(response.data.message || 'Error cancelling order.', 'error');
                    $button.prop('disabled', false).text('Cancel Order');
                }
            },
            error: function() {
                showMessage('Error cancelling order. Please try again.', 'error');
                $button.prop('disabled', false).text('Cancel Order');
            }
        });
    });
    
    // Show message function
    function showMessage(message, type) {
        var $messagesContainer = $('#wc-b2b-messages');
        var $message = $('<div class="wc-b2b-message ' + type + '">' + message + '</div>');
        
        $messagesContainer.empty().append($message);
        
        // Scroll to message
        $('html, body').animate({
            scrollTop: $messagesContainer.offset().top - 100
        }, 500);
        
        // Auto-hide success messages after 5 seconds
        if (type === 'success') {
            setTimeout(function() {
                $message.fadeOut();
            }, 5000);
        }
    }
});
