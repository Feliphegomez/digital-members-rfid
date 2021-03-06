<?php
/****************************************************************

IMPORTANTE. POR FAVOR LEE.

NO EDITE ESTE ARCHIVO ni ningún otro archivo en el directorio / wp-content / plugins / digital-members-rfid /.
Si lo hace, podría romper el complemento DmRFID y / o evitar que actualice este complemento en el futuro.
Publicamos actualizaciones periódicas del complemento, incluidas importantes correcciones de seguridad y nuevas funciones.
Quieres poder actualizar.

Si se le pidió que insertara código en "su archivo functions.php", significaba que editaba functions.php
en la carpeta raíz de su tema activo. p.ej. /wp-content/themes/twentytwelve/functions.php
También puede crear un complemento personalizado para colocar el código de personalización. Las instrucciones están aquí:
https://www.managertechnology.com.co/create-a-plugin-for-dmrfid-customizations/

Puede encontrar más documentación para personalizar RFID de miembros digitales aquí:
https://www.managertechnology.com.co/documentation/

****************************************************************/

/*
	Checks if DmRFID settings are complete or if there are any errors.
	
	Stripe currently does not support:
	* Billing Limits.
*/
function dmrfid_checkLevelForStripeCompatibility($level = NULL)
{
	$gateway = dmrfid_getOption("gateway");
	if($gateway == "stripe")
	{
		global $wpdb;

		//check ALL the levels
		if(empty($level))
		{
			$sqlQuery = "SELECT * FROM $wpdb->dmrfid_membership_levels ORDER BY id ASC";
			$levels = $wpdb->get_results($sqlQuery, OBJECT);
			if(!empty($levels))
			{
				foreach($levels as $level)
				{
					if(!dmrfid_checkLevelForStripeCompatibility($level))
						return false;
				}
			}
		}
		else
		{
			//need to look it up?
			if(is_numeric($level))
				$level = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $wpdb->dmrfid_membership_levels WHERE id = %d LIMIT 1" , $level ) );

			// Check if this level uses billing limits.
			if ( ( $level->billing_limit > 0 ) && ! function_exists( 'dmrfidsbl_plugin_row_meta' ) ) {
				return false;
			}

			// Check if this level has a billing period longer than 1 year.
			if ( 
				( $level->cycle_period === 'Year' && $level->cycle_number > 1 ) ||
				( $level->cycle_period === 'Month' && $level->cycle_number > 12 ) ||
				( $level->cycle_period === 'Week' && $level->cycle_number > 52 ) ||
				( $level->cycle_period === 'Day' && $level->cycle_number > 365 )
			) {
				return false;
			}
		}
	}

	return true;
}

/*
	Checks if DmRFID settings are complete or if there are any errors.
	
	Payflow currently does not support:
	* Trial Amounts > 0.
*/
function dmrfid_checkLevelForPayflowCompatibility($level = NULL)
{
	$gateway = dmrfid_getOption("gateway");
	if($gateway == "payflowpro")
	{
		global $wpdb;

		//check ALL the levels
		if(empty($level))
		{
			$sqlQuery = "SELECT * FROM $wpdb->dmrfid_membership_levels ORDER BY id ASC";
			$levels = $wpdb->get_results($sqlQuery, OBJECT);
			if(!empty($levels))
			{
				foreach($levels as $level)
				{					
					if(!dmrfid_checkLevelForPayflowCompatibility($level))
						return false;
				}
			}
		}
		else
		{
			//need to look it up?
			if(is_numeric($level))
				$level = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $wpdb->dmrfid_membership_levels WHERE id = %d LIMIT 1" , $level ) );

			//check this level
			if($level->trial_amount > 0)
			{
				return false;
			}
		}
	}

	return true;
}

/*
	Checks if DmRFID settings are complete or if there are any errors.
	
	Braintree currently does not support:
	* Trial Amounts > 0.
	* Daily or Weekly billing periods.
	* Also check that a plan has been created at Braintree
*/
function dmrfid_checkLevelForBraintreeCompatibility($level = NULL)
{
	$gateway = dmrfid_getOption("gateway");
	if($gateway == "braintree")
	{
		global $wpdb;

		//check ALL the levels
		if(empty($level))
		{
			$sqlQuery = "SELECT * FROM $wpdb->dmrfid_membership_levels ORDER BY id ASC";
			$levels = $wpdb->get_results($sqlQuery, OBJECT);
			if(!empty($levels))
			{
				foreach($levels as $level)
				{
					if(!dmrfid_checkLevelForBraintreeCompatibility($level))
						return false;
				}
			}
		}
		else
		{
			//need to look it up?
			if(is_numeric($level))
				$level = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $wpdb->dmrfid_membership_levels WHERE id = %d LIMIT 1" , $level ) );

			//check this level
			if($level->trial_amount > 0 ||
			   ($level->cycle_number > 0 && ($level->cycle_period == "Day" || $level->cycle_period == "Week")))
			{
				return false;
			}
			
			//check for plan
			if(dmrfid_isLevelRecurring($level)) {
				if(!DmRFIDGateway_braintree::checkLevelForPlan($level->id))
					return false;
			}
		}
	}

	return true;
}

/**
 * Checks if a discount code's settings are compatible with the active gateway.
 *
 */
function dmrfid_check_discount_code_for_gateway_compatibility( $discount_code = NULL ) {
	// Return if no gateway is set.
	$gateway = dmrfid_getOption( 'gateway' );
	if ( empty( $gateway ) ) {
		return true;
	}

	global $wpdb;
	
	// Check ALL the discount codes if none specified.
	if ( empty( $discount_code ) ) {
		$discount_codes = $wpdb->get_results( "SELECT * FROM $wpdb->dmrfid_discount_codes" );
		if ( ! empty( $discount_codes ) ) {
			foreach ( $discount_codes as $discount_code ) {
				if ( ! dmrfid_check_discount_code_for_gateway_compatibility( $discount_code ) ) {
					return false;
				}
			}
		}
	} else {
		if ( ! is_numeric( $discount_code ) ) {
			// Convert the code array into a single id.
			$discount_code = $discount_code->id;
		}
		// Check ALL the discount code levels for this code.
		$discount_codes_levels = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $wpdb->dmrfid_discount_codes_levels WHERE code_id = %d", $discount_code ) );
		if ( ! empty( $discount_codes_levels ) ) {
			foreach ( $discount_codes_levels as $discount_code_level ) {
				if ( ! dmrfid_check_discount_code_level_for_gateway_compatibility( $discount_code_level ) ) {
					return false;
				}
			}
		}
	}
	return true;
}

/**
 * Checks if a discount code's settings are compatible with the active gateway.
 *
 */
function dmrfid_check_discount_code_level_for_gateway_compatibility( $discount_code_level = NULL ) {
	// Return if no gateway is set.
	$gateway = dmrfid_getOption( 'gateway' );
	if ( empty( $gateway ) ) {
		return true;
	}

	global $wpdb;

	// Check ALL the discount code levels if none specified.
	if ( empty( $discount_code_level ) ) {
		$sqlQuery = "SELECT * FROM $wpdb->dmrfid_discount_codes_levels ORDER BY id ASC";
		$discount_codes_levels = $wpdb->get_results($sqlQuery, OBJECT);
		if ( ! empty( $discount_codes_levels ) ) {
			foreach ( $discount_codes_levels as $discount_code_level ) {
				if ( ! dmrfid_check_discount_code_level_for_gateway_compatibility( $discount_code_level ) ) {
					return false;
				}
			}
		}
	} else {
		// Need to look it up?
		if ( is_numeric( $discount_code_level ) ) {
			$discount_code_level = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $wpdb->dmrfid_discount_codes_levels WHERE id = %d LIMIT 1" , $discount_code_level ) );
		}

		// Check this discount code level for gateway compatibility
		if ( $gateway == 'stripe' ) {
			// Check if this code level has a billing limit.
			if ( ( intval( $discount_code_level->billing_limit ) > 0 ) && ! function_exists( 'dmrfidsbl_plugin_row_meta' ) ) {
				global $dmrfid_stripe_error;
				$dmrfid_stripe_error = true;
				return false;
			}
			// Check if this code level has a billing period longer than 1 year.
			if ( 
				( $discount_code_level->cycle_period === 'Year' && intval( $discount_code_level->cycle_number ) > 1 ) ||
				( $discount_code_level->cycle_period === 'Month' && intval( $discount_code_level->cycle_number ) > 12 ) ||
				( $discount_code_level->cycle_period === 'Week' && intval( $discount_code_level->cycle_number ) > 52 ) ||
				( $discount_code_level->cycle_period === 'Day' && intval( $discount_code_level->cycle_number ) > 365 )
			) {
				global $dmrfid_stripe_error;
				$dmrfid_stripe_error = true;
				return false;
			}
		} elseif ( $gateway == 'payflowpro' ) {
			if ( $discount_code_level->trial_amount > 0 ) {
				global $dmrfid_payflow_error;
				$dmrfid_payflow_error = true;
				return false;
			}
		} elseif ( $gateway == 'braintree' ) {
			if ( $discount_code_level->trial_amount > 0 ||
			   ( $discount_code_level->cycle_number > 0 && ( $discount_code_level->cycle_period == "Day" || $discount_code_level->cycle_period == "Week" ) ) ) {
			   	global $dmrfid_braintree_error;
				$dmrfid_braintree_error = true;
				return false;
			}
		} elseif ( $gateway == 'twocheckout' ) {
			if ( $discount_code_level->trial_amount > $discount_code_level->billing_amount ) {
				global $dmrfid_twocheckout_error;
				$dmrfid_twocheckout_error = true;
				return false;
			}
		}
	}

	return true;
}

/*
	Checks if DmRFID settings are complete or if there are any errors.
	
	2Checkout currently does not support:
	* Trial amounts less than or greater than the absolute value of amonthly recurring amount.
*/
function dmrfid_checkLevelForTwoCheckoutCompatibility($level = NULL)
{
	$gateway = dmrfid_getOption("gateway");
	if($gateway == "twocheckout")
	{
		global $wpdb;

		//check ALL the levels
		if(empty($level))
		{
			$sqlQuery = "SELECT * FROM $wpdb->dmrfid_membership_levels ORDER BY id ASC";
			$levels = $wpdb->get_results($sqlQuery, OBJECT);
			if(!empty($levels))
			{
				foreach($levels as $level)
				{					
					if(!dmrfid_checkLevelForTwoCheckoutCompatibility($level))
						return false;
				}
			}
		}
		else
		{
			//need to look it up?
			if(is_numeric($level))
				$level = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $wpdb->dmrfid_membership_levels WHERE id = %d LIMIT 1" , $level ) );

			//check this level
			if(dmrfid_isLevelTrial($level))
			{
				return false;
			}
		}
	}

	return true;
}

/**
 * Get the gateway-related classes for fields on the payment settings page.
 *
 * @param string $field The name of the field to check.
 * @param bool $force If true, it will rebuild the cached results.
 *
 * @since  1.8
 */
function dmrfid_getClassesForPaymentSettingsField($field, $force = false)
{
	global $dmrfid_gateway_options;
	$dmrfid_gateways = dmrfid_gateways();

	//build array of gateways and options
	if(!isset($dmrfid_gateway_options) || $force)
	{
		$dmrfid_gateway_options = array();

		foreach($dmrfid_gateways as $gateway => $label)
		{
			//get options
			if(class_exists('DmRFIDGateway_' . $gateway) && method_exists('DmRFIDGateway_' . $gateway, 'getGatewayOptions'))
			{
				$dmrfid_gateway_options[$gateway] = call_user_func(array('DmRFIDGateway_' . $gateway, 'getGatewayOptions'));
			}
		}
	}

	//now check where this field shows up
	$rgateways = array();
	foreach($dmrfid_gateway_options as $gateway => $options)
	{
		if(in_array($field, $options))
			$rgateways[] = "gateway_" . $gateway;
	}

	//return space separated string
	return implode(" ", $rgateways);
}


/**
 * Code to handle emailing billable invoices.
 *
 * @since 1.8.6
 */

/**
 * Get the gateway-related classes for fields on the payment settings page.
 *
 * @param string $field The name of the field to check.
 * @param bool $force If true, it will rebuild the cached results.
 *
 * @since  1.8
 */
function dmrfid_add_email_order_modal() {

	// emailing?
	if ( ! empty( $_REQUEST['email'] ) && ! empty( $_REQUEST['order'] ) ) {
		$email = new DmRFIDEmail();
		$user  = get_user_by( 'email', sanitize_email( $_REQUEST['email'] ) );
		$order = new MemberOrder( $_REQUEST['order'] );
		if ( $email->sendBillableInvoiceEmail( $user, $order ) ) { ?>
			<div class="notice notice-success is-dismissible">
				<p><?php _e( 'Factura enviada correctamente por correo electrónico.', 'digital-members-rfid' ); ?></p>
			</div>
		<?php } else { ?>
			<div class="notice notice-error is-dismissible">
				<p><?php _e( 'Error al enviar la factura por correo electrónico.', 'digital-members-rfid' ); ?></p>
			</div>
		<?php }
	}

	?>
	<script>
		// Update fields in email modal.
		jQuery(document).ready(function ($) {
			var order, order_id;
			$('.email_link').click(function () {
				order_id = $(this).data('order');
				$('input[name=order]').val(order_id);
				// Get email address from order ID
				data = {
					action: 'dmrfid_get_order_json',
					order_id: order_id
				};
				$.post(ajaxurl, data, function (response) {
					order = JSON.parse(response);
					$('input[name=email]').val(order.Email);
				});
			});
		});
	</script>
	<?php add_thickbox(); ?>
	<div id="email_invoice" style="display:none;">
		<h3><?php _e( 'Factura por correo electrónico', 'digital-members-rfid' ); ?></h3>
		<form method="post" action="">
			<input type="hidden" name="order" value=""/>
			<?php _e( 'Envíe una factura de este pedido a: ', 'digital-members-rfid' ); ?>
			<input type="text" value="" name="email"/>
			<button class="button button-primary alignright"><?php _e( 'Send Email', 'digital-members-rfid' ); ?></button>
		</form>
	</div>
	<?php
}

