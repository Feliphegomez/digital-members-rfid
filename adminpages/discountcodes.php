<?php
	//only admins can get this
	if(!function_exists("current_user_can") || (!current_user_can("manage_options") && !current_user_can("dmrfid_discountcodes")))
	{
		die(__("No tienes permisos para realizar esta acción.", 'digital-members-rfid' ));
	}

	//vars
	global $wpdb, $dmrfid_currency_symbol, $dmrfid_stripe_error, $dmrfid_braintree_error, $dmrfid_payflow_error, $dmrfid_twocheckout_error;

	$now = current_time( 'timestamp' );

	if(isset($_REQUEST['edit']))
		$edit = intval($_REQUEST['edit']);
	else
		$edit = false;

	if(isset($_REQUEST['copy']))
		$copy = intval($_REQUEST['copy']);

	if(isset($_REQUEST['delete']))
		$delete = intval($_REQUEST['delete']);
	else
		$delete = false;

	if(isset($_REQUEST['saveid']))
		$saveid = intval($_POST['saveid']);
	else
		$saveid = false;

	if(isset($_REQUEST['s']))
		$s = sanitize_text_field($_REQUEST['s']);
	else
		$s = "";

	//some vars for the search
	if ( isset( $_REQUEST['pn'] ) ) {
		$pn = intval( $_REQUEST['pn'] );
	} else {
		$pn = 1;
	}

	if ( isset( $_REQUEST['limit'] ) ) {
		$limit = intval( $_REQUEST['limit'] );
	} else {
		/**
		 * Filter to set the default number of items to show per page
		 * on the Discount Codes page in the admin.
		 *
		 * @since 1.9.4
		 *
		 * @param int $limit The number of items to show per page.
		 */
		$limit = apply_filters( 'dmrfid_discount_codes_per_page', 15 );
	}

	$end   = $pn * $limit;
	$start = $end - $limit;

	//check nonce for saving codes
	if (!empty($_REQUEST['saveid']) && (empty($_REQUEST['dmrfid_discountcodes_nonce']) || !check_admin_referer('save', 'dmrfid_discountcodes_nonce'))) {
		$dmrfid_msgt = 'error';
		$dmrfid_msg = __("¿Seguro que quieres hacer eso? Inténtalo de nuevo.", 'digital-members-rfid' );
		$saveid = false;
	}

	if($saveid)
	{
		//get vars
		//disallow/strip all non-alphanumeric characters except -
		$code = preg_replace("/[^A-Za-z0-9\-]/", "", sanitize_text_field($_POST['code']));
		$starts_month = intval($_POST['starts_month']);
		$starts_day = intval($_POST['starts_day']);
		$starts_year = intval($_POST['starts_year']);
		$expires_month = intval($_POST['expires_month']);
		$expires_day = intval($_POST['expires_day']);
		$expires_year = intval($_POST['expires_year']);
		$uses = intval($_POST['uses']);

		//fix up dates
		$starts = date("Y-m-d", strtotime($starts_month . "/" . $starts_day . "/" . $starts_year, $now ));
		$expires = date("Y-m-d", strtotime($expires_month . "/" . $expires_day . "/" . $expires_year, $now ));

		//insert/update/replace discount code
		dmrfid_insert_or_replace(
			$wpdb->dmrfid_discount_codes,
			array(
				'id'=>max($saveid, 0),
				'code' => $code,
				'starts' => $starts,
				'expires' => $expires,
				'uses' => $uses
			),
			array(
				'%d',
				'%s',
				'%s',
				'%s',
				'%d'
			)
		);

		//check for errors and show appropriate message if inserted or updated
		if(empty($wpdb->last_error)) {
			if($saveid < 1) {
				//insert
				$dmrfid_msg = __("El código de descuento se agregó correctamente.", 'digital-members-rfid' );
				$dmrfid_msgt = "success";
				$saved = true;
				$edit = $wpdb->insert_id;
			} else {
				//updated
				$dmrfid_msg = __("Código de descuento actualizado correctamente.", 'digital-members-rfid' );
				$dmrfid_msgt = "success";
				$saved = true;
				$edit = $saveid;
			}
		} else {
			if($saveid < 1) {
				//error inserting
				$dmrfid_msg = __("Error al agregar el código de descuento. Es posible que ese código ya esté en uso.", 'digital-members-rfid' ) . $wpdb->last_error;
				$dmrfid_msgt = "error";
			} else {
				//error updating
				$dmrfid_msg = __("Error al actualizar el código de descuento. Es posible que ese código ya esté en uso.", 'digital-members-rfid' );
				$dmrfid_msgt = "error";
			}
		}

		//now add the membership level rows
		if($saved && $edit > 0)
		{
			//get the submitted values
			$all_levels_a = $_REQUEST['all_levels'];
			if(!empty($_REQUEST['levels']))
				$levels_a = $_REQUEST['levels'];
			else
				$levels_a = array();
			$initial_payment_a = $_REQUEST['initial_payment'];

			if(!empty($_REQUEST['recurring']))
				$recurring_a = $_REQUEST['recurring'];
			$billing_amount_a = $_REQUEST['billing_amount'];
			$cycle_number_a = $_REQUEST['cycle_number'];
			$cycle_period_a = $_REQUEST['cycle_period'];
			$billing_limit_a = $_REQUEST['billing_limit'];

			if(!empty($_REQUEST['custom_trial']))
				$custom_trial_a = $_REQUEST['custom_trial'];
			$trial_amount_a = $_REQUEST['trial_amount'];
			$trial_limit_a = $_REQUEST['trial_limit'];

			if(!empty($_REQUEST['expiration']))
				$expiration_a = $_REQUEST['expiration'];
			$expiration_number_a = $_REQUEST['expiration_number'];
			$expiration_period_a = $_REQUEST['expiration_period'];

			//clear the old rows
			$wpdb->delete($wpdb->dmrfid_discount_codes_levels, array('code_id' => $edit), array('%d'));

			//add a row for each checked level
			if(!empty($levels_a))
			{
				foreach($levels_a as $level_id)
				{
					$level_id = intval($level_id);	//sanitized

					//get the values ready
					$n = array_search($level_id, $all_levels_a); 	//this is the key location of this level's values
					$initial_payment = sanitize_text_field($initial_payment_a[$n]);

					//is this recurring?
					if(!empty($recurring_a))
					{
						if(in_array($level_id, $recurring_a))
							$recurring = 1;
						else
							$recurring = 0;
					}
					else
						$recurring = 0;

					if(!empty($recurring))
					{
						$billing_amount = sanitize_text_field($billing_amount_a[$n]);
						$cycle_number = intval($cycle_number_a[$n]);
						$cycle_period = sanitize_text_field($cycle_period_a[$n]);
						$billing_limit = intval($billing_limit_a[$n]);

						//custom trial
						if(!empty($custom_trial_a))
						{
							if(in_array($level_id, $custom_trial_a))
								$custom_trial = 1;
							else
								$custom_trial = 0;
						}
						else
							$custom_trial = 0;

						if(!empty($custom_trial))
						{
							$trial_amount = sanitize_text_field($trial_amount_a[$n]);
							$trial_limit = intval($trial_limit_a[$n]);
						}
						else
						{
							$trial_amount = '';
							$trial_limit = '';
						}
					}
					else
					{
						$billing_amount = '';
						$cycle_number = '';
						$cycle_period = 'Month';
						$billing_limit = '';
						$custom_trial = 0;
						$trial_amount = '';
						$trial_limit = '';
					}

					if(!empty($expiration_a))
					{
						if(in_array($level_id, $expiration_a))
							$expiration = 1;
						else
							$expiration = 0;
					}
					else
						$expiration = 0;

					if(!empty($expiration))
					{
						$expiration_number = intval($expiration_number_a[$n]);
						$expiration_period = sanitize_text_field($expiration_period_a[$n]);
					}
					else
					{
						$expiration_number = '';
						$expiration_period = 'Month';
					}

					if ( ! empty( $expiration ) && ! empty( $recurring ) ) {
						$expiration_warning_flag = true;
					}

					//okay, do the insert
					$wpdb->insert(
						$wpdb->dmrfid_discount_codes_levels,
						array(
							'code_id' => $edit,
							'level_id' => $level_id,
							'initial_payment' => $initial_payment,
							'billing_amount' => $billing_amount,
							'cycle_number' => $cycle_number,
							'cycle_period' => $cycle_period,
							'billing_limit' => $billing_limit,
							'trial_amount' => $trial_amount,
							'trial_limit' => $trial_limit,
							'expiration_number' => $expiration_number,
							'expiration_period' => $expiration_period
						),
						array(
							'%d',
							'%d',
							'%f',
							'%f',
							'%d',
							'%s',
							'%d',
							'%f',
							'%d',
							'%d',
							'%s'
						)
					);

					if(empty($wpdb->last_error))
					{
						//okay
						do_action("dmrfid_save_discount_code_level", $edit, $level_id);
					}
					else
					{
						$level = dmrfid_getLevel($level_id);
						$level_errors[] = sprintf(__("Error al guardar valores para el nivel %s.", 'digital-members-rfid' ), $level->name);
					}
				}
			}

			//errors?
			if(!empty($level_errors))
			{
				$dmrfid_msg = __("Hubo errores al actualizar los valores de nivel:", 'digital-members-rfid' ) . implode(" ", $level_errors);
				$dmrfid_msgt = "error";
			}
			else
			{
				do_action("dmrfid_save_discount_code", $edit);

				//all good. set edit = false so we go back to the overview page
				$edit = false;
			}
		}
	}

	//check nonce for deleting codes
	if (!empty($_REQUEST['delete']) && (empty($_REQUEST['dmrfid_discountcodes_nonce']) || !check_admin_referer('delete', 'dmrfid_discountcodes_nonce'))) {
		$dmrfid_msgt = 'error';
		$dmrfid_msg = __("", 'digital-members-rfid' );
		$delete = false;
	}

	//are we deleting?
	if(!empty($delete))
	{
		//is this a code?
		$code = $wpdb->get_var( $wpdb->prepare( "SELECT code FROM $wpdb->dmrfid_discount_codes WHERE id = %d LIMIT 1", $delete ) );
		if(!empty($code))
		{
			//action
			do_action("dmrfid_delete_discount_code", $delete);

			//delete the code levels
			$r1 = $wpdb->delete($wpdb->dmrfid_discount_codes_levels, array('code_id'=>$delete), array('%d'));

			if($r1 !== false)
			{
				//delete the code
				$r2 = $wpdb->delete($wpdb->dmrfid_discount_codes, array('id'=>$delete), array('%d'));

				if($r2 !== false)
				{
					$dmrfid_msg = sprintf(__("El código %s se eliminó correctamente.", 'digital-members-rfid' ), $code);
					$dmrfid_msgt = "success";
				}
				else
				{
					$dmrfid_msg = __("Error al eliminar el código de descuento. El código solo se eliminó parcialmente. Inténtalo de nuevo.", 'digital-members-rfid' );
					$dmrfid_msgt = "error";
				}
			}
			else
			{
				$dmrfid_msg = __("Error al eliminar el código. Inténtalo de nuevo.", 'digital-members-rfid' );
				$dmrfid_msgt = "error";
			}
		}
		else
		{
			$dmrfid_msg = __("Código no encontrado.", 'digital-members-rfid' );
			$dmrfid_msgt = "error";
		}
	}
	
	if( ! empty( $dmrfid_msg ) && ! empty( $expiration_warning_flag ) ) {
		$dmrfid_msg .= ' <strong>' . sprintf( __( 'ADVERTENCIA: Se estableció un nivel con un monto de facturación recurrente y una fecha de vencimiento. Solo necesita configurar uno de estos a menos que realmente desee que esta membresía caduque después de un período de tiempo específico. Para obtener más información, <a target="_blank" href="%s"> consulte nuestra publicación aquí </a>.', 'digital-members-rfid' ), 'https://www.managertechnology.com.co/important-notes-on-recurring-billing-and-expiration-dates-for-membership-levels/?utm_source=plugin&utm_medium=dmrfid-discountcodes&utm_campaign=blog&utm_content=important-notes-on-recurring-billing-and-expiration-dates-for-membership-levels' ) . '</strong>';
		
		if( $dmrfid_msgt == 'success' ) {
			$dmrfid_msgt = 'warning';
		}
	}

	require_once(dirname(__FILE__) . "/admin_header.php");
?>

	<?php if($edit) { ?>

		<h1>
			<?php
				if($edit > 0)
					echo __("Editar código de descuento", 'digital-members-rfid' );
				else
					echo __("Agregar nuevo código de descuento", 'digital-members-rfid' );
			?>
		</h1>

		<?php if(!empty($dmrfid_msg)) { ?>
			<div id="message" class="<?php if($dmrfid_msgt == "success") echo "updated fade"; else echo "error"; ?>"><p><?php echo $dmrfid_msg?></p></div>
		<?php } ?>

		<div>
			<?php
				// get the code...
				if($edit > 0)
				{
					$code = $wpdb->get_row(
						$wpdb->prepare("
						SELECT *, UNIX_TIMESTAMP(CONVERT_TZ(starts, '+00:00', @@global.time_zone)) as starts, UNIX_TIMESTAMP(CONVERT_TZ(expires, '+00:00', @@global.time_zone)) as expires
						FROM $wpdb->dmrfid_discount_codes
						WHERE id = %d LIMIT 1",
						$edit ),
						OBJECT
					);

					$uses = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $wpdb->dmrfid_discount_codes_uses WHERE code_id = %d", $code->id ) );
					$levels = $wpdb->get_results( $wpdb->prepare("
					SELECT l.id, l.name, cl.initial_payment, cl.billing_amount, cl.cycle_number, cl.cycle_period, cl.billing_limit, cl.trial_amount, cl.trial_limit
					FROM $wpdb->dmrfid_membership_levels l
					LEFT JOIN $wpdb->dmrfid_discount_codes_levels cl
					ON l.id = cl.level_id
					WHERE cl.code_id = %s",
					$code->code
					) );
					$temp_code = $code;
				}
				elseif(!empty($copy) && $copy > 0)
				{
					$code = $wpdb->get_row(
						$wpdb->prepare("
						SELECT *, UNIX_TIMESTAMP(CONVERT_TZ(starts, '+00:00', @@global.time_zone)) as starts, UNIX_TIMESTAMP(CONVERT_TZ(expires, '+00:00', @@global.time_zone)) as expires
						FROM $wpdb->dmrfid_discount_codes
						WHERE id = %d LIMIT 1",
						$copy ),
						OBJECT
					);
					
					$temp_code = $code;
				}

				// didn't find a discount code, let's add a new one...
				if(empty($code->id)) $edit = -1;

				//defaults for new codes
				if ( $edit == -1 )
				{
					$code = new stdClass();
					$code->code = dmrfid_getDiscountCode();
					
					if( ! empty( $copy ) && $copy > 0 ) {
						$code->starts = $temp_code->starts;
						$code->expires = $temp_code->expires;
						$code->uses = $temp_code->uses;
					}
				}
			?>
			<form action="" method="post">
				<input name="saveid" type="hidden" value="<?php echo $edit?>" />
				<?php wp_nonce_field('save', 'dmrfid_discountcodes_nonce');?>
				<table class="form-table">
                <tbody>
                    <tr>
                        <th scope="row" valign="top"><label><?php _e('ID', 'digital-members-rfid' );?>:</label></th>
                        <td><p class="description"><?php if(!empty($code->id)) echo $code->id; else echo __("Esto se generará cuando guarde.", 'digital-members-rfid' );?></p></td>
                    </tr>

                    <tr>
                        <th scope="row" valign="top"><label for="code"><?php _e('Codigo', 'digital-members-rfid' );?>:</label></th>
                        <td><input name="code" type="text" size="20" value="<?php echo str_replace("\"", "&quot;", stripslashes($code->code))?>" /></td>
                    </tr>

					<?php
						//some vars for the dates
						$current_day = date("j");
						if(!empty($code->starts))
							$selected_starts_day = date("j", $code->starts);
						else
							$selected_starts_day = $current_day;
						if(!empty($code->expires))
							$selected_expires_day = date("j", $code->expires);
						else
							$selected_expires_day = $current_day;

						$current_month = date("M");
						if(!empty($code->starts))
							$selected_starts_month = date("m", $code->starts);
						else
							$selected_starts_month = date("m");
						if(!empty($code->expires))
							$selected_expires_month = date("m", $code->expires);
						else
							$selected_expires_month = date("m");

						$current_year = date("Y");
						if(!empty($code->starts))
							$selected_starts_year = date("Y", $code->starts);
						else
							$selected_starts_year = $current_year;
						if(!empty($code->expires))
							$selected_expires_year = date("Y", $code->expires);
						else
							$selected_expires_year = (int)$current_year + 1;
					?>

					<tr>
                        <th scope="row" valign="top"><label for="starts"><?php _e('Start Date', 'digital-members-rfid' );?>:</label></th>
                        <td>
							<select name="starts_month">
								<?php
									for($i = 1; $i < 13; $i++)
									{
									?>
									<option value="<?php echo esc_attr( $i )?>" <?php if($i == $selected_starts_month) { ?>selected="selected"<?php } ?>><?php echo esc_html( date_i18n( 'F', mktime( 0, 0, 0, $i, 2 ) ) ); ?></option>
									<?php
									}
								?>
							</select>
							<input name="starts_day" type="text" size="2" value="<?php echo $selected_starts_day?>" />
							<input name="starts_year" type="text" size="4" value="<?php echo $selected_starts_year?>" />
						</td>
                    </tr>

					<tr>
                        <th scope="row" valign="top"><label for="expires"><?php _e('Fecha de caducidad', 'digital-members-rfid' );?>:</label></th>
                        <td>
							<select name="expires_month">
								<?php
									for($i = 1; $i < 13; $i++)
									{
									?>
									<option value="<?php echo esc_attr( $i );?>" <?php if($i == $selected_expires_month) { ?>selected="selected"<?php } ?>><?php echo esc_html( date_i18n( 'F', mktime( 0, 0, 0, $i, 2 ) ) ); ?></option>
									<?php
									}
								?>
							</select>
							<input name="expires_day" type="text" size="2" value="<?php echo $selected_expires_day?>" />
							<input name="expires_year" type="text" size="4" value="<?php echo $selected_expires_year?>" />
						</td>
                    </tr>

					<tr>
                        <th scope="row" valign="top"><label for="uses"><?php _e('Usos', 'digital-members-rfid' );?>:</label></th>
                        <td>
							<input name="uses" type="text" size="10" value="<?php if(!empty($code->uses)) echo str_replace("\"", "&quot;", stripslashes($code->uses));?>" />
							<p class="description"><?php _e('Déjelo en blanco para usos ilimitados.', 'digital-members-rfid' );?></p>
						</td>
                    </tr>

				</tbody>
			</table>

			<?php do_action("dmrfid_discount_code_after_settings", $edit); ?>

			<h3><?php _e('¿A qué niveles se aplicará este código?', 'digital-members-rfid' ); ?></h3>

			<div class="dmrfid_discount_levels">
			<?php
				$levels = $wpdb->get_results("SELECT * FROM $wpdb->dmrfid_membership_levels");
				foreach($levels as $level)
				{
					//if this level is already managed for this discount code, use the code values
					if($edit > 0 || ! empty( $copy ) )
					{
						$code_level = $wpdb->get_row( $wpdb->prepare("
						SELECT l.id, cl.*, l.name, l.description, l.allow_signups
						FROM $wpdb->dmrfid_discount_codes_levels cl
						LEFT JOIN $wpdb->dmrfid_membership_levels l
						ON cl.level_id = l.id
						WHERE cl.code_id = %d AND cl.level_id = %d LIMIT 1",
						$temp_code->id,
						$level->id )
					);
						if($code_level)
						{
							$level = $code_level;
							$level->checked = true;
						}
						else
							$level_checked = false;
					}
					else
						$level_checked = false;
				?>
				<div class="dmrfid_discount_level <?php if ( ! dmrfid_check_discount_code_level_for_gateway_compatibility( $level ) ) { ?>dmrfid_error<?php } ?>">
					<input type="hidden" name="all_levels[]" value="<?php echo $level->id?>" />
					<input type="checkbox" id="levels_<?php echo $level->id;?>" name="levels[]" value="<?php echo $level->id?>" <?php if(!empty($level->checked)) { ?>checked="checked"<?php } ?> onclick="if(jQuery(this).is(':checked')) jQuery(this).next().next().show();	else jQuery(this).next().next().hide();" />
					<label for="levels_<?php echo $level->id;?>"><?php echo $level->name?></label>
					<div class="dmrfid_discount_levels_pricing level_<?php echo $level->id?>" <?php if(empty($level->checked)) { ?>style="display: none;"<?php } ?>>
						<table class="form-table">
						<tbody>
							<tr>
								<th scope="row" valign="top"><label for="initial_payment"><?php _e('Initial Payment', 'digital-members-rfid' );?>:</label></th>
								<td>
									<?php
									if(dmrfid_getCurrencyPosition() == "left")
										echo $dmrfid_currency_symbol;
									?>
									<input name="initial_payment[]" type="text" size="20" value="<?php echo esc_attr( dmrfid_filter_price_for_text_field( $level->initial_payment ) ); ?>" />
									<?php
									if(dmrfid_getCurrencyPosition() == "right")
										echo $dmrfid_currency_symbol;
									?>
									<p class="description"><?php _e('The initial amount collected at registration.', 'digital-members-rfid' );?></p>
								</td>
							</tr>

							<tr>
								<th scope="row" valign="top"><label><?php _e('Recurring Subscription', 'digital-members-rfid' );?>:</label></th>
								<td><input class="recurring_checkbox" id="recurring_<?php echo $level->id;?>" name="recurring[]" type="checkbox" value="<?php echo $level->id?>" <?php if(dmrfid_isLevelRecurring($level)) { echo "checked='checked'"; } ?> onclick="if(jQuery(this).prop('checked')) {					jQuery(this).parent().parent().siblings('.recurring_info').show(); if(!jQuery('#custom_trial_<?php echo $level->id?>').is(':checked')) jQuery(this).parent().parent().siblings('.trial_info').hide();} else					jQuery(this).parent().parent().siblings('.recurring_info').hide();" /> <label for="recurring_<?php echo $level->id;?>"><?php _e('Check if this level has a recurring subscription payment.', 'digital-members-rfid' );?></label></td>
							</tr>

							<tr class="recurring_info" <?php if(!dmrfid_isLevelRecurring($level)) {?>style="display: none;"<?php } ?>>
								<th scope="row" valign="top"><label for="billing_amount"><?php _e('Billing Amount', 'digital-members-rfid' );?>:</label></th>
								<td>
									<?php
									if(dmrfid_getCurrencyPosition() == "left")
										echo $dmrfid_currency_symbol;
									?>
									<input name="billing_amount[]" type="text" size="20" value="<?php echo esc_attr( dmrfid_filter_price_for_text_field( $level->billing_amount ) );?>" />
									<?php
									if(dmrfid_getCurrencyPosition() == "right")
										echo $dmrfid_currency_symbol;
									?>
									<?php _e('per', 'digital-members-rfid' ); ?>
									<input name="cycle_number[]" type="text" size="10" value="<?php echo str_replace("\"", "&quot;", stripslashes($level->cycle_number))?>" />
									<select name="cycle_period[]">
									  <?php
										$cycles = array( __('Day(s)', 'digital-members-rfid' ) => 'Day', __('Week(s)', 'digital-members-rfid' ) => 'Week', __('Month(s)', 'digital-members-rfid' ) => 'Month', __('Year(s)', 'digital-members-rfid' ) => 'Year' );
										foreach ( $cycles as $name => $value ) {
										  echo "<option value='$value'";
										  if ( $level->cycle_period == $value ) echo " selected='selected'";
										  echo ">$name</option>";
										}
									  ?>
									</select>
									<p class="description"><?php _e('La cantidad que se facturará un ciclo después del pago inicial.', 'digital-members-rfid' );?></p>
									<?php if($gateway == "braintree") { ?>
										<strong <?php if(!empty($dmrfid_braintree_error)) { ?>class="dmrfid_red"<?php } ?>><?php _e('La integración de Braintree actualmente solo admite períodos de facturación de "Mes" o "Año".', 'digital-members-rfid' );?></strong>
									<?php } elseif($gateway == "stripe") { ?>
										<p class="description"><strong <?php if(!empty($dmrfid_stripe_error)) { ?>class="dmrfid_red"<?php } ?>><?php _e('La integración de Stripe no permite períodos de facturación superiores a 1 año.', 'digital-members-rfid' );?></strong></p>
									<?php }?>
								</td>
							</tr>

							<tr class="recurring_info" <?php if(!dmrfid_isLevelRecurring($level)) {?>style="display: none;"<?php } ?>>
								<th scope="row" valign="top"><label for="billing_limit"><?php _e('Límite del ciclo de facturación', 'digital-members-rfid' );?>:</label></th>
								<td>
									<input name="billing_limit[]" type="text" size="20" value="<?php echo $level->billing_limit?>" />
									<p class="description">
										<?php _e('La cantidad <strong> total </strong> de ciclos de facturación recurrentes para este nivel, incluido el período de prueba (si corresponde), pero sin incluir el pago inicial. Establecer en cero si la membresía es indefinida.', 'digital-members-rfid' );?>
										<?php if ( ( $gateway == "stripe" ) && ! function_exists( 'dmrfidsbl_plugin_row_meta' ) ) { ?>
											<br /><strong <?php if(!empty($dmrfid_stripe_error)) { ?>class="dmrfid_red"<?php } ?>><?php _e('Actualmente, la integración de Stripe no admite límites de facturación. Aún puede establecer una fecha de vencimiento a continuación.', 'digital-members-rfid' );?></strong>
											<?php if ( ! function_exists( 'dmrfidsd_dmrfid_membership_level_after_other_settings' ) ) {
													$allowed_sbl_html = array (
														'a' => array (
															'href' => array(),
															'target' => array(),
															'title' => array(),
														),
													);
													echo '<br />' . sprintf( wp_kses( __( 'Opcional: Permita límites de facturación con Stripe mediante el <a href="%s" title="Agregar límites de facturación de Stripe RFID para miembros digitales" target="_blank"> Complemento de límites de facturación de Stripe </a>.', 'digital-members-rfid' ), $allowed_sbl_html ), 'https://www.managertechnology.com.co/add-ons/dmrfid-stripe-billing-limits/?utm_source=plugin&utm_medium=dmrfid-membershiplevels&utm_campaign=add-ons&utm_content=stripe-billing-limits' ) . '</em></td></tr>';
											} ?>
									<?php } ?>
								</p>
								</td>
							</tr>

							<tr class="recurring_info" <?php if (!dmrfid_isLevelRecurring($level)) echo "style='display:none;'";?>>
								<th scope="row" valign="top"><label><?php _e('Prueba personalizada', 'digital-members-rfid' );?>:</label></th>
								<td>
									<input id="custom_trial_<?php echo $level->id?>" id="custom_trial_<?php echo $level->id;?>" name="custom_trial[]" type="checkbox" value="<?php echo $level->id?>" <?php if ( dmrfid_isLevelTrial($level) ) { echo "checked='checked'"; } ?> onclick="if(jQuery(this).prop('checked')) jQuery(this).parent().parent().siblings('.trial_info').show();	else jQuery(this).parent().parent().siblings('.trial_info').hide();" /> <label for="custom_trial_<?php echo $level->id;?>"><?php _e('Marque para agregar un período de prueba personalizado.', 'digital-members-rfid' );?></label>
									<?php if($gateway == "twocheckout") { ?>
										<p class="description"><strong <?php if(!empty($dmrfid_twocheckout_error)) { ?>class="dmrfid_red"<?php } ?>><?php _e('La integración de 2Checkout no admite pruebas personalizadas. Puede realizar pruebas de un período estableciendo un pago inicial diferente del monto de facturación.', 'digital-members-rfid' );?></strong></p>
									<?php } ?>
								</td>
							</tr>

							<tr class="trial_info recurring_info" <?php if (!dmrfid_isLevelTrial($level)) echo "style='display:none;'";?>>
								<th scope="row" valign="top"><label for="trial_amount"><?php _e('Monto de facturación de prueba', 'digital-members-rfid' );?>:</label></th>
								<td>
									<?php
									if(dmrfid_getCurrencyPosition() == "left")
										echo $dmrfid_currency_symbol;
									?>
									<input name="trial_amount[]" type="text" size="20" value="<?php echo esc_attr( dmrfid_filter_price_for_text_field( $level->trial_amount ) );?>" />
									<?php
									if(dmrfid_getCurrencyPosition() == "right")
										echo $dmrfid_currency_symbol;
									?>
									<?php _e('for the first', 'digital-members-rfid' );?>
									<input name="trial_limit[]" type="text" size="10" value="<?php echo str_replace("\"", "&quot;", stripslashes($level->trial_limit))?>" />
									<?php _e('subscription payments', 'digital-members-rfid' );?>.
									<?php if($gateway == "stripe") { ?>
										<p class="description"><strong <?php if(!empty($dmrfid_stripe_error)) { ?>class="dmrfid_red"<?php } ?>><?php _e('Actualmente, la integración de Stripe no admite montos de prueba superiores a $0.', 'digital-members-rfid' );?></strong></p>
									<?php } elseif($gateway == "braintree") { ?>
										<p class="description"><strong <?php if(!empty($dmrfid_braintree_error)) { ?>class="dmrfid_red"<?php } ?>><?php _e('Actualmente, la integración de Braintree no admite montos de prueba superiores a $0.', 'digital-members-rfid' );?></strong></p>
									<?php } elseif($gateway == "payflowpro") { ?>
										<p class="description"><strong <?php if(!empty($dmrfid_payflow_error)) { ?>class="dmrfid_red"<?php } ?>><?php _e('Actualmente, la integración de flujo de pago no admite montos de prueba superiores a $0.', 'digital-members-rfid' );?></strong></p>
									<?php } ?>
								</td>
							</tr>

							<tr>
								<th scope="row" valign="top"><label><?php _e('Caducidad de la membresía', 'digital-members-rfid' );?>:</label></th>
								<td><input id="expiration_<?php echo $level->id;?>" name="expiration[]" type="checkbox" value="<?php echo $level->id?>" <?php if(dmrfid_isLevelExpiring($level)) { echo "checked='checked'"; } ?> onclick="if(jQuery(this).is(':checked')) { jQuery(this).parent().parent().siblings('.expiration_info').show(); } else { jQuery(this).parent().parent().siblings('.expiration_info').hide();}" /> <label for="expiration_<?php echo $level->id;?>"><?php _e('Check this to set when membership access expires.', 'digital-members-rfid' );?></label></td>
							</tr>

							<tr class="expiration_info" <?php if(!dmrfid_isLevelExpiring($level)) {?>style="display: none;"<?php } ?>>
								<th scope="row" valign="top"><label for="billing_amount"><?php _e('Expira en', 'digital-members-rfid' );?>:</label></th>
								<td>
									<input id="expiration_number" name="expiration_number[]" type="text" size="10" value="<?php echo str_replace("\"", "&quot;", stripslashes($level->expiration_number))?>" />
									<select id="expiration_period" name="expiration_period[]">
									  <?php
										$cycles = array( __('Día(s)', 'digital-members-rfid' ) => 'Day', __('Semana(s)', 'digital-members-rfid' ) => 'Week', __('Mes(es)', 'digital-members-rfid' ) => 'Month', __('Año(s)', 'digital-members-rfid' ) => 'Year' );
										foreach ( $cycles as $name => $value ) {
										  echo "<option value='$value'";
										  if ( $level->expiration_period == $value ) echo " selected='selected'";
										  echo ">$name</option>";
										}
									  ?>

									</select>
									<p class="description"><?php _e('Establezca la duración del acceso a la membresía. Tenga en cuenta que los pagos futuros (suscripción recurrente, si corresponde) se cancelarán cuando expire la membresía.', 'digital-members-rfid' );?></p>
								</td>
							</tr>
						</tbody>
					</table>

					<?php do_action("dmrfid_discount_code_after_level_settings", $edit, $level); ?>

					</div>
				</div>
				<script>

				</script>
				<?php
				}
			?>
			</div>

			<p class="submit topborder">
				<input name="save" type="submit" class="button button-primary" value="Save Code" />
				<input name="cancel" type="button" class="button" value="Cancel" onclick="location.href='<?php echo get_admin_url(NULL, '/admin.php?page=dmrfid-discountcodes')?>';" />
			</p>
			</form>
		</div>

	<?php } else { ?>

		<h1 class="wp-heading-inline"><?php esc_html_e( 'Códigos de descuento de membresías', 'digital-members-rfid' ); ?></h1>
		<a href="admin.php?page=dmrfid-discountcodes&edit=-1" class="page-title-action"><?php esc_html_e( 'Agregar nuevo código de descuento', 'digital-members-rfid' ); ?></a>
		<hr class="wp-header-end">

		<?php
			$sqlQuery = "SELECT SQL_CALC_FOUND_ROWS *, UNIX_TIMESTAMP(CONVERT_TZ(starts, '+00:00', @@global.time_zone)) as starts, UNIX_TIMESTAMP(CONVERT_TZ(expires, '+00:00', @@global.time_zone)) as expires FROM $wpdb->dmrfid_discount_codes ";
			if( ! empty( $s ) ) {
				$sqlQuery .= "WHERE code LIKE '%$s%' ";
			}

			$sqlQuery .= "ORDER BY id DESC ";

			$sqlQuery .= "LIMIT $start, $limit ";

			$codes = $wpdb->get_results($sqlQuery, OBJECT);

			$totalrows = $wpdb->get_var( "SELECT FOUND_ROWS() as found_rows" );

			if( empty( $s ) && empty( $codes ) ) { ?>
				<div class="dmrfid-new-install">
					<h2><?php echo esc_attr_e( 'No se encontraron códigos de descuento', 'digital-members-rfid' ); ?></h2>
					<h4><?php _e( 'Los códigos de descuento le permiten anular el precio predeterminado de su nivel de membresía.', 'digital-members-rfid' ); ?></h4>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=dmrfid-discountcodes&edit=-1' ) ) ; ?>" class="button-primary"><?php esc_attr_e( 'Crea un código de descuento', 'digital-members-rfid' );?></a>
					<a href="<?php echo esc_url( 'https://www.managertechnology.com.co/documentation/discount-codes/?utm_source=plugin&utm_medium=dmrfid-discountcodes&utm_campaign=documentation&utm_content=discount-codes' ); ?>" target="_blank" class="button"><?php echo esc_attr_e( 'Documentación: Códigos de descuento', 'digital-members-rfid' ); ?></a>
				</div> <!-- end dmrfid-new-install -->
			<?php } else { ?>

				<?php if(!empty($dmrfid_msg)) { ?>
					<div id="message" class="<?php if($dmrfid_msgt == "success") echo "updated fade"; else echo "error"; ?>"><p><?php echo $dmrfid_msg?></p></div>
				<?php } ?>

				<?php if ( ! empty( $codes ) ) { ?>
					<p class="subsubsub"><?php printf( __( "%d códigos de descuento encontrados.", 'digital-members-rfid' ), $totalrows ); ?></span></p>
				<?php } ?>

				<form id="posts-filter" method="get" action="">
					<p class="search-box">
						<label class="screen-reader-text" for="post-search-input"><?php _e('Buscar códigos de descuento', 'digital-members-rfid' );?>:</label>
						<input type="hidden" name="page" value="dmrfid-discountcodes" />
						<input id="post-search-input" type="text" value="<?php if(!empty($s)) echo $s;?>" name="s" size="30" />
						<input class="button" type="submit" value="<?php _e('Buscar', 'digital-members-rfid' );?>" id="search-submit "/>
					</p>
				</form>

				<br class="clear" />

				<table class="widefat">
				<thead>
					<tr>
						<th><?php _e('ID', 'digital-members-rfid' );?></th>
						<th><?php _e('Codigo', 'digital-members-rfid' );?></th>
						<th><?php _e('Empieza', 'digital-members-rfid' );?></th>
						<th><?php _e('Expira', 'digital-members-rfid' );?></th>
						<th><?php _e('Usuarios', 'digital-members-rfid' );?></th>
						<th><?php _e('Roles', 'digital-members-rfid' );?></th>
						<?php do_action("dmrfid_discountcodes_extra_cols_header", $codes);?>
					</tr>
				</thead>
				<tbody>
					<?php if ( !empty( $s ) && empty( $codes ) ) { ?>
					<tr>
						<td colspan="6">
							<?php echo esc_attr_e( 'Código no encontrado.', 'digital-members-rfid' ); ?>
						</td>
					</tr> 
					<?php } ?>
					<?php
						foreach($codes as $code) {
							$uses = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $wpdb->dmrfid_discount_codes_uses WHERE code_id = %d", $code->id ) );
							?>
						<tr<?php if ( ! dmrfid_check_discount_code_for_gateway_compatibility( $code->id ) ) { ?> class="dmrfid_error"<?php } ?>>
							<td><?php echo $code->id?></td>
							<td class="has-row-actions">
								<a title="<?php echo sprintf( 'Modificar código: %s', $code->code ); ?>" href="<?php echo add_query_arg( array( 'page' => 'dmrfid-discountcodes', 'edit' => $code->id ), admin_url('admin.php' ) ); ?>"><?php echo $code->code?></a>
								<div class="row-actions">
									<span class="edit">
										<a title="<?php _e( 'Editar', 'digital-members-rfid' ); ?>" href="<?php echo add_query_arg( array( 'page' => 'dmrfid-discountcodes', 'edit' => $code->id ), admin_url('admin.php' ) ); ?>"><?php _e( 'Editar', 'digital-members-rfid' ); ?></a>
									</span> |
									<span class="copy">
										<a title="<?php _e( 'Copiar', 'digital-members-rfid' ); ?>" href="<?php echo add_query_arg( array( 'page' => 'dmrfid-discountcodes', 'edit' => -1, 'copy' => $code->id ), admin_url('admin.php' ) ); ?>"><?php _e( 'Copiar', 'digital-members-rfid' ); ?></a>
									</span> |
									<span class="delete">
										<a title="<?php _e( 'Eliminar', 'digital-members-rfid' ); ?>" href="javascript:dmrfid_askfirst('<?php echo str_replace("'", "\'", sprintf(__('¿Está seguro de que desea eliminar el código de descuento% s? Las suscripciones para los usuarios existentes no cambiarán, pero los nuevos usuarios ya no podrán usar este código.', 'digital-members-rfid' ), $code->code));?>', '<?php echo wp_nonce_url(add_query_arg( array( 'page' => 'dmrfid-discountcodes', 'delete' => $code->id), admin_url( 'admin.php' ) ), 'delete', 'dmrfid_discountcodes_nonce'); ?>'); void(0);"><?php _e('Delete', 'digital-members-rfid' ); ?></a>
									</span>
									<?php if ( (int)$uses > 0 ) { ?>
										| <span class="orders">
											<a title="<?php _e(' Ver Ordenes', 'digital-members-rfid' ); ?>" href="<?php echo add_query_arg( array( 'page' => 'dmrfid-orders', 'discount_code' => $code->id, 'filter' => 'with-discount-code' ), admin_url('admin.php' ) ); ?>"><?php _e( 'Orders', 'digital-members-rfid' ); ?></a>
										</span>
									<?php } ?>
								</div>
							</td>
							<td>
								<?php echo date_i18n(get_option('date_format'), $code->starts)?>
							</td>
							<td>
								<?php echo date_i18n(get_option('date_format'), $code->expires)?>
							</td>
							<td>
								<?php
									if($code->uses > 0)
										echo "<strong>" . (int)$uses . "</strong>/" . $code->uses;
									else
										echo "<strong>" . (int)$uses . "</strong>/unlimited";
								?>
							</td>
							<td>
								<?php
									$sqlQuery = $wpdb->prepare("
										SELECT l.id, l.name
										FROM $wpdb->dmrfid_membership_levels l
										LEFT JOIN $wpdb->dmrfid_discount_codes_levels cl
										ON l.id = cl.level_id
										WHERE cl.code_id = %d",
										$code->id
									);
									$levels = $wpdb->get_results($sqlQuery);

									$level_names = array();
									foreach( $levels as $level ) {
										$level_names[] = '<a title="' . dmrfid_url( 'checkout', '?level=' . $level->id . '&discount_code=' . $code->code) . '" target="_blank" href="' . dmrfid_url( 'checkout', '?level=' . $level->id . '&discount_code=' . $code->code) . '">' . $level->name . '</a>';
									}
									if( $level_names ) {
										echo implode( ', ', $level_names );
									} else {
										echo 'None';
									}
								?>
							</td>
							<?php do_action("dmrfid_discountcodes_extra_cols_body", $code);?>
						</tr>
					<?php
					}
				}
				?>
		</tbody>
		</table>

		<?php
			$pagination_url = get_admin_url( null, "/admin.php?page=dmrfid-discountcodes&s=" . $s );
			echo dmrfid_getPaginationString( $pn, $totalrows, $limit, 1, $pagination_url, "&limit=$limit&pn=" );
		?>

	<?php } ?>

<?php
	require_once(dirname(__FILE__) . "/admin_footer.php");
?>
