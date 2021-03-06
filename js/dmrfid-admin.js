/**
 * Show a system prompt before redirecting to a URL.
 * Used for delete links/etc.
 * @param	text	The prompt, i.e. are you sure?
 * @param	url		The url to redirect to.
 */
function dmrfid_askfirst( text, url ) {
	var answer = window.confirm( text );

	if ( answer ) {
		window.location = url;
	}
}

/**
 * Deprecated in v2.1
 * In case add-ons/etc are expecting the non-prefixed version.
 */
if ( typeof askfirst !== 'function' ) {
    function askfirst( text, url ) {
        return dmrfid_askfirst( text, url );
    }
}

/*
 * Toggle elements with a specific CSS class selector.
 * Used to hide/show sub settings when a main setting is enabled.
 * @since v2.1
 */
function dmrfid_toggle_elements_by_selector( selector, checked ) {
	if( checked === undefined ) {
		jQuery( selector ).toggle();
	} else if ( checked ) {
		jQuery( selector ).show();
	} else {
		jQuery( selector ).hide();
	}
}

/*
 * Find inputs with a custom attribute dmrfid_toggle_trigger_for,
 * and bind change to toggle the specified elements.
 * @since v2.1
 */
jQuery(document).ready(function() {
	jQuery( 'input[dmrfid_toggle_trigger_for]' ).change( function() {		
		dmrfid_toggle_elements_by_selector( jQuery( this ).attr( 'dmrfid_toggle_trigger_for' ), jQuery( this ).prop( 'checked' ) );
	});
});

/** JQuery to hide the notifications. */
jQuery(document).ready(function(){
	jQuery(document).on( 'click', '.dmrfid-notice-button.notice-dismiss', function() {
		var notification_id = jQuery( this ).val();

		var postData = {
			action: 'dmrfid_hide_notice',
			notification_id: notification_id
		}

		jQuery.ajax({
			type: "POST",
			data: postData,
			url: ajaxurl,
			success: function( response ) {
				///console.log( notification_id );
				jQuery('#'+notification_id).hide();
			}
		})
	
	});
});

/*
 * Create Webhook button for Stripe on the payment settings page.
 */
jQuery(document).ready(function() {
	// Check that we are on payment settings page.
	if ( ! jQuery( '#stripe_publishablekey' ).length || ! jQuery( '#stripe_secretkey' ).length || ! jQuery( '#dmrfid_stripe_create_webhook' ).length ) {
		return;
	}

    // Disable the webhook buttons if the API keys aren't complete yet.
    jQuery('#stripe_publishablekey,#stripe_secretkey').bind('change keyup', function() {
        dmrfid_stripe_check_api_keys();
    });    
    dmrfid_stripe_check_api_keys();
    
    // AJAX call to create webhook.
    jQuery('#dmrfid_stripe_create_webhook').click(function(event){
        event.preventDefault();
                
		var postData = {
			action: 'dmrfid_stripe_create_webhook',
            secretkey: jQuery('#stripe_secretkey').val(),
		}

		jQuery.ajax({
			type: "POST",
			data: postData,
			url: ajaxurl,
			success: function( response ) {
				response = jQuery.parseJSON( response );
                ///console.log( response );
                
                jQuery( '#dmrfid_stripe_webhook_notice' ).parent('div').removeClass('error')
                jQuery( '#dmrfid_stripe_webhook_notice' ).parent('div').removeClass('notice-success')
                
                if ( response.notice ) {
                    jQuery('#dmrfid_stripe_webhook_notice').parent('div').addClass(response.notice);
                }
                if ( response.message ) {
                    jQuery('#dmrfid_stripe_webhook_notice').html(response.message);
                }
                if ( response.success ) {
                    jQuery('#dmrfid_stripe_create_webhook').hide();
                }
			}
		})
    });
    
    // AJAX call to delete webhook.
    jQuery('#dmrfid_stripe_delete_webhook').click(function(event){
        event.preventDefault();
                
		var postData = {
			action: 'dmrfid_stripe_delete_webhook',
            secretkey: jQuery('#stripe_secretkey').val(),
		}

		jQuery.ajax({
			type: "POST",
			data: postData,
			url: ajaxurl,
			success: function( response ) {
				response = jQuery.parseJSON( response );
                ///console.log( response );
                
                jQuery( '#dmrfid_stripe_webhook_notice' ).parent('div').removeClass('error')
                jQuery( '#dmrfid_stripe_webhook_notice' ).parent('div').removeClass('notice-success')
                
                if ( response.notice ) {
                    jQuery('#dmrfid_stripe_webhook_notice').parent('div').addClass(response.notice);
                }
                if ( response.message ) {
                    jQuery('#dmrfid_stripe_webhook_notice').html(response.message);
                }
                if ( response.success ) {
                    jQuery('#dmrfid_stripe_create_webhook').show();
                }				
			}
		})
	});

	// AJAX call to rebuild webhook.
    jQuery('#dmrfid_stripe_rebuild_webhook').click(function(event){
        event.preventDefault();
                
		var postData = {
			action: 'dmrfid_stripe_rebuild_webhook',
            secretkey: jQuery('#stripe_secretkey').val(),
		}

		jQuery.ajax({
			type: "POST",
			data: postData,
			url: ajaxurl,
			success: function( response ) {
				response = jQuery.parseJSON( response );
                ///console.log( response );
                
                jQuery( '#dmrfid_stripe_webhook_notice' ).parent('div').removeClass('error')
                jQuery( '#dmrfid_stripe_webhook_notice' ).parent('div').removeClass('notice-success')
                
                if ( response.notice ) {
                    jQuery('#dmrfid_stripe_webhook_notice').parent('div').addClass(response.notice);
                }
                if ( response.message ) {
                    jQuery('#dmrfid_stripe_webhook_notice').html(response.message);
                }
                if ( response.success ) {
                    jQuery('#dmrfid_stripe_create_webhook').hide();
                }				
			}
		})
    });
});

// Disable the webhook buttons if the API keys aren't complete yet.
function dmrfid_stripe_check_api_keys() {    
    if( jQuery('#stripe_publishablekey').val().length > 0 && jQuery('#stripe_secretkey').val().length > 0 ) {
        jQuery('#dmrfid_stripe_create_webhook').removeClass('disabled');
        jQuery('#dmrfid_stripe_create_webhook').addClass('button-secondary');
    } else {            
        jQuery('#dmrfid_stripe_create_webhook').removeClass('button-secondary');
        jQuery('#dmrfid_stripe_create_webhook').addClass('disabled');
    }
}