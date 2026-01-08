jQuery(document).ready(function($) {
    'use strict';
    
    // Resend verification email
    $('#wc-b2b-resend-verification').on('click', function(e) {
        e.preventDefault();
        
        var $button = $(this);
        var orderId = $button.data('order-id');
        
        $button.prop('disabled', true).text(wc_b2b_admin.messages.processing || 'Processing...');
        
        $.ajax({
            url: wc_b2b_admin.ajax_url,
            type: 'POST',
            data: {
                action: 'wc_b2b_resend_verification',
                order_id: orderId,
                nonce: wc_b2b_admin.nonce
            },
            success: function(response) {
                if (response.success) {
                    showMessage(response.data.message, 'success');
                } else {
                    showMessage(response.data.message || wc_b2b_admin.messages.error, 'error');
                }
            },
            error: function() {
                showMessage(wc_b2b_admin.messages.error, 'error');
            },
            complete: function() {
                $button.prop('disabled', false).text('Resend Verification Email');
            }
        });
    });
    
    // Manual verify order
    $('#wc-b2b-manual-verify').on('click', function(e) {
        e.preventDefault();
        
        if (!confirm('Are you sure you want to manually verify this order?')) {
            return;
        }
        
        var $button = $(this);
        var orderId = $button.data('order-id');
        
        $button.prop('disabled', true).text('Processing...');
        
        $.ajax({
            url: wc_b2b_admin.ajax_url,
            type: 'POST',
            data: {
                action: 'wc_b2b_manual_verify',
                order_id: orderId,
                nonce: wc_b2b_admin.nonce
            },
            success: function(response) {
                if (response.success) {
                    showMessage(response.data.message, 'success');
                    // Reload page after 2 seconds to show updated status
                    setTimeout(function() {
                        location.reload();
                    }, 2000);
                } else {
                    showMessage(response.data.message || wc_b2b_admin.messages.error, 'error');
                }
            },
            error: function() {
                showMessage(wc_b2b_admin.messages.error, 'error');
            },
            complete: function() {
                $button.prop('disabled', false).text('Manual Verify');
            }
        });
    });
    
    // Update item price
    $(document).on('click', '.wc-b2b-update-price', function(e) {
        e.preventDefault();
        
        var $button = $(this);
        var $row = $button.closest('tr');
        var itemId = $button.data('item-id');
        var quantity = $button.data('quantity');
        var newPrice = $row.find('.wc-b2b-new-price').val();
        var orderId = $('#post_ID').val();
        
        if (!newPrice || newPrice < 0) {
            showPriceMessage('Please enter a valid price.', 'error');
            return;
        }
        
        $button.prop('disabled', true).text('Updating...');
        
        $.ajax({
            url: wc_b2b_admin.ajax_url,
            type: 'POST',
            data: {
                action: 'wc_b2b_update_item_price',
                order_id: orderId,
                item_id: itemId,
                new_price: newPrice,
                quantity: quantity,
                nonce: wc_b2b_admin.nonce
            },
            success: function(response) {
                if (response.success) {
                    showPriceMessage(response.data.message, 'success');
                    // Update displayed values
                    $row.find('.current-price').html(response.data.item_total);
                    $('.current-total').html(response.data.new_total);
                } else {
                    showPriceMessage(response.data.message || 'Error updating price.', 'error');
                }
            },
            error: function() {
                showPriceMessage('Error updating price. Please try again.', 'error');
            },
            complete: function() {
                $button.prop('disabled', false).text('Update');
            }
        });
    });
    
    // Update shipping cost
    $(document).on('click', '.wc-b2b-update-shipping', function(e) {
        e.preventDefault();
        
        var $button = $(this);
        var newShippingCost = $('#wc-b2b-new-shipping').val();
        var orderId = $('#post_ID').val();
        
        if (newShippingCost < 0) {
            showPriceMessage('Please enter a valid shipping cost.', 'error');
            return;
        }
        
        $button.prop('disabled', true).text('Updating...');
        
        $.ajax({
            url: wc_b2b_admin.ajax_url,
            type: 'POST',
            data: {
                action: 'wc_b2b_update_shipping_cost',
                order_id: orderId,
                shipping_cost: newShippingCost,
                nonce: wc_b2b_admin.nonce
            },
            success: function(response) {
                if (response.success) {
                    showPriceMessage(response.data.message, 'success');
                    // Update displayed values
                    $('.current-shipping').html(response.data.shipping_total);
                    $('.current-total').html(response.data.new_total);
                } else {
                    showPriceMessage(response.data.message || 'Error updating shipping cost.', 'error');
                }
            },
            error: function() {
                showPriceMessage('Error updating shipping cost. Please try again.', 'error');
            },
            complete: function() {
                $button.prop('disabled', false).text('Update Shipping');
            }
        });
    });
    
    // Show message function
    function showMessage(message, type) {
        var $messagesContainer = $('#wc-b2b-messages');
        var $message = $('<div class="wc-b2b-message ' + type + '">' + message + '</div>');
        
        $messagesContainer.empty().append($message);
        
        // Auto-hide success messages after 5 seconds
        if (type === 'success') {
            setTimeout(function() {
                $message.fadeOut();
            }, 5000);
        }
    }
    
    // Mark order as paid
    $(document).on('click', '#wc-b2b-mark-paid', function(e) {
        e.preventDefault();
        
        if (!confirm('Are you sure you want to mark this order as paid? This action indicates that payment was received offline.')) {
            return;
        }
        
        var $button = $(this);
        var orderId = $button.data('order-id');
        
        $button.prop('disabled', true).text('Processing...');
        
        $.ajax({
            url: wc_b2b_admin.ajax_url,
            type: 'POST',
            data: {
                action: 'wc_b2b_mark_paid',
                order_id: orderId,
                nonce: wc_b2b_admin.nonce
            },
            success: function(response) {
                if (response.success) {
                    showExpiryMessage(response.data.message, 'success');
                    // Reload page after 2 seconds to show updated status
                    setTimeout(function() {
                        location.reload();
                    }, 2000);
                } else {
                    showExpiryMessage(response.data.message || 'Error marking order as paid.', 'error');
                }
            },
            error: function() {
                showExpiryMessage('Error marking order as paid. Please try again.', 'error');
            },
            complete: function() {
                $button.prop('disabled', false).text('Mark as Paid');
            }
        });
    });
    
    // Send payment reminder
    $(document).on('click', '#wc-b2b-send-reminder', function(e) {
        e.preventDefault();
        
        var $button = $(this);
        var orderId = $button.data('order-id');
        
        $button.prop('disabled', true).text('Sending...');
        
        $.ajax({
            url: wc_b2b_admin.ajax_url,
            type: 'POST',
            data: {
                action: 'wc_b2b_send_payment_reminder',
                order_id: orderId,
                nonce: wc_b2b_admin.nonce
            },
            success: function(response) {
                if (response.success) {
                    showExpiryMessage(response.data.message, 'success');
                } else {
                    showExpiryMessage(response.data.message || 'Error sending payment reminder.', 'error');
                }
            },
            error: function() {
                showExpiryMessage('Error sending payment reminder. Please try again.', 'error');
            },
            complete: function() {
                $button.prop('disabled', false).text('Send Payment Reminder');
            }
        });
    });
    
    // Show price message function
    function showPriceMessage(message, type) {
        var $messagesContainer = $('#wc-b2b-price-messages');
        var $message = $('<div class="wc-b2b-message ' + type + '">' + message + '</div>');
        
        $messagesContainer.empty().append($message);
        
        // Auto-hide success messages after 5 seconds
        if (type === 'success') {
            setTimeout(function() {
                $message.fadeOut();
            }, 5000);
        }
    }
    
    // Show expiry message function
    function showExpiryMessage(message, type) {
        var $messagesContainer = $('#wc-b2b-expiry-messages');
        var $message = $('<div class="wc-b2b-message ' + type + '">' + message + '</div>');
        
        $messagesContainer.empty().append($message);
        
        // Auto-hide success messages after 5 seconds
        if (type === 'success') {
            setTimeout(function() {
                $message.fadeOut();
            }, 5000);
        }
    }
    
    // Cleanup tokens
    $(document).on('click', '#wc-b2b-cleanup-tokens', function(e) {
        e.preventDefault();
        
        if (!confirm('Are you sure you want to clean up expired and old verification tokens? This action cannot be undone.')) {
            return;
        }
        
        var $button = $(this);
        var $resultContainer = $('#wc-b2b-cleanup-result');
        
        $button.prop('disabled', true).text('Cleaning up...');
        $resultContainer.empty();
        
        $.ajax({
            url: wc_b2b_admin.ajax_url,
            type: 'POST',
            data: {
                action: 'wc_b2b_cleanup_tokens',
                nonce: wc_b2b_admin.nonce
            },
            success: function(response) {
                if (response.success) {
                    $resultContainer.html('<div class="notice notice-success inline"><p>' + response.data.message + '</p></div>');
                } else {
                    $resultContainer.html('<div class="notice notice-error inline"><p>' + (response.data.message || 'Error cleaning up tokens.') + '</p></div>');
                }
            },
            error: function() {
                $resultContainer.html('<div class="notice notice-error inline"><p>Error cleaning up tokens. Please try again.</p></div>');
            },
            complete: function() {
                $button.prop('disabled', false).text('Clean Up Expired Tokens Now');
            }
        });
    });
});