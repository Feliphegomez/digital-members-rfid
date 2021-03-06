<?php
	//include dmrfidgateway
	require_once(dirname(__FILE__) . "/class.dmrfidgateway.php");
	
	//load classes init method
	add_action('init', array('DmRFIDGateway_check', 'init'));
	
	class DmRFIDGateway_check extends DmRFIDGateway
	{
		function __construct($gateway = NULL)
		{
			$this->gateway = $gateway;
			return $this->gateway;
		}										
		
		/**
		 * Run on WP init
		 *		 
		 * @since 1.8
		 */
		static function init()
		{			
			//make sure Pay by Check is a gateway option
			add_filter('dmrfid_gateways', array('DmRFIDGateway_check', 'dmrfid_gateways'));
			
			//add fields to payment settings
			add_filter('dmrfid_payment_options', array('DmRFIDGateway_check', 'dmrfid_payment_options'));
			add_filter('dmrfid_payment_option_fields', array('DmRFIDGateway_check', 'dmrfid_payment_option_fields'), 10, 2);
			add_filter('dmrfid_checkout_after_payment_information_fields', array('DmRFIDGateway_check', 'dmrfid_checkout_after_payment_information_fields'));

			//code to add at checkout
			$gateway = dmrfid_getGateway();
			if($gateway == "check")
			{
				add_filter('dmrfid_include_billing_address_fields', '__return_false');
				add_filter('dmrfid_include_payment_information_fields', '__return_false');
				add_filter('dmrfid_required_billing_fields', array('DmRFIDGateway_check', 'dmrfid_required_billing_fields'));
			}
		}
		
		/**
		 * Make sure Check is in the gateways list
		 *		 
		 * @since 1.8
		 */
		static function dmrfid_gateways($gateways)
		{
			if(empty($gateways['check']))
				$gateways['check'] = __('Pay by Check', 'digital-members-rfid' );
		
			return $gateways;
		}
		
		/**
		 * Get a list of payment options that the Check gateway needs/supports.
		 *		 
		 * @since 1.8
		 */
		static function getGatewayOptions()
		{			
			$options = array(
				'sslseal',
				'nuclear_HTTPS',
				'gateway_environment',
				'instructions',
				'currency',
				'use_ssl',
				'tax_state',
				'tax_rate'
			);
			
			return $options;
		}
		
		/**
		 * Set payment options for payment settings page.
		 *		 
		 * @since 1.8
		 */
		static function dmrfid_payment_options($options)
		{			
			//get stripe options
			$check_options = DmRFIDGateway_check::getGatewayOptions();
			
			//merge with others.
			$options = array_merge($check_options, $options);
			
			return $options;
		}
		
		/**
		 * Display fields for Check options.
		 *		 
		 * @since 1.8
		 */
		static function dmrfid_payment_option_fields($values, $gateway)
		{
		?>
		<tr class="dmrfid_settings_divider gateway gateway_check" <?php if($gateway != "check") { ?>style="display: none;"<?php } ?>>
			<td colspan="2">
				<hr />
				<h3><?php _e('Pay by Check Settings', 'digital-members-rfid' ); ?></h3>
			</td>
		</tr>
		<tr class="gateway gateway_check" <?php if($gateway != "check") { ?>style="display: none;"<?php } ?>>
			<th scope="row" valign="top">
				<label for="instructions"><?php _e('Instructions', 'digital-members-rfid' );?></label>					
			</th>
			<td>
				<textarea id="instructions" name="instructions" rows="3" cols="50" class="large-text"><?php echo wpautop(  wp_unslash( $values['instructions'] ) ); ?></textarea>
				<p class="description"><?php _e('Who to write the check out to. Where to mail it. Shown on checkout, confirmation, and invoice pages.', 'digital-members-rfid' );?></p>
			</td>
		</tr>	
		<?php
		}
		
		/**
		 * Remove required billing fields
		 *		 
		 * @since 1.8
		 */
		static function dmrfid_required_billing_fields($fields)
		{
			unset($fields['bfirstname']);
			unset($fields['blastname']);
			unset($fields['baddress1']);
			unset($fields['bcity']);
			unset($fields['bstate']);
			unset($fields['bzipcode']);
			unset($fields['bphone']);
			unset($fields['bemail']);
			unset($fields['bcountry']);
			unset($fields['CardType']);
			unset($fields['AccountNumber']);
			unset($fields['ExpirationMonth']);
			unset($fields['ExpirationYear']);
			unset($fields['CVV']);
			
			return $fields;
		}

		/**
		 * Show instructions on checkout page
		 * Moved here from pages/checkout.php
		 * @since 1.8.9.3
		 */
		static function dmrfid_checkout_after_payment_information_fields() {
			global $gateway;
			global $dmrfid_level;

			if($gateway == "check" && !dmrfid_isLevelFree($dmrfid_level)) {
				$instructions = dmrfid_getOption("instructions");
				echo '<div class="' . dmrfid_get_element_class( 'dmrfid_check_instructions' ) . '">' . wpautop(wp_unslash( $instructions )) . '</div>';
			}
		}

		
		/**
		 * Process checkout.
		 *
		 */
		function process(&$order)
		{
			//clean up a couple values
			$order->payment_type = "Check";
			$order->CardType = "";
			$order->cardtype = "";
			
			//check for initial payment
			if(floatval($order->InitialPayment) == 0)
			{
				//auth first, then process
				if($this->authorize($order))
				{						
					$this->void($order);										
					if(!dmrfid_isLevelTrial($order->membership_level))
					{
						//subscription will start today with a 1 period trial
						$order->ProfileStartDate = date_i18n("Y-m-d") . "T0:0:0";
						$order->TrialBillingPeriod = $order->BillingPeriod;
						$order->TrialBillingFrequency = $order->BillingFrequency;													
						$order->TrialBillingCycles = 1;
						$order->TrialAmount = 0;
						
						//add a billing cycle to make up for the trial, if applicable
						if(!empty($order->TotalBillingCycles))
							$order->TotalBillingCycles++;
					}
					elseif($order->InitialPayment == 0 && $order->TrialAmount == 0)
					{
						//it has a trial, but the amount is the same as the initial payment, so we can squeeze it in there
						$order->ProfileStartDate = date_i18n("Y-m-d") . "T0:0:0";														
						$order->TrialBillingCycles++;
						
						//add a billing cycle to make up for the trial, if applicable
						if($order->TotalBillingCycles)
							$order->TotalBillingCycles++;
					}
					else
					{
						//add a period to the start date to account for the initial payment
						$order->ProfileStartDate = date_i18n("Y-m-d", strtotime("+ " . $order->BillingFrequency . " " . $order->BillingPeriod, current_time("timestamp"))) . "T0:0:0";
					}
					
					$order->ProfileStartDate = apply_filters("dmrfid_profile_start_date", $order->ProfileStartDate, $order);
					return $this->subscribe($order);
				}
				else
				{
					if(empty($order->error))
						$order->error = __("Unknown error: Authorization failed.", 'digital-members-rfid' );
					return false;
				}
			}
			else
			{
				//charge first payment
				if($this->charge($order))
				{							
					//set up recurring billing					
					if(dmrfid_isLevelRecurring($order->membership_level))
					{						
						if(!dmrfid_isLevelTrial($order->membership_level))
						{
							//subscription will start today with a 1 period trial
							$order->ProfileStartDate = date_i18n("Y-m-d") . "T0:0:0";
							$order->TrialBillingPeriod = $order->BillingPeriod;
							$order->TrialBillingFrequency = $order->BillingFrequency;													
							$order->TrialBillingCycles = 1;
							$order->TrialAmount = 0;
							
							//add a billing cycle to make up for the trial, if applicable
							if(!empty($order->TotalBillingCycles))
								$order->TotalBillingCycles++;
						}
						elseif($order->InitialPayment == 0 && $order->TrialAmount == 0)
						{
							//it has a trial, but the amount is the same as the initial payment, so we can squeeze it in there
							$order->ProfileStartDate = date_i18n("Y-m-d") . "T0:0:0";														
							$order->TrialBillingCycles++;
							
							//add a billing cycle to make up for the trial, if applicable
							if(!empty($order->TotalBillingCycles))
								$order->TotalBillingCycles++;
						}
						else
						{
							//add a period to the start date to account for the initial payment
							$order->ProfileStartDate = date_i18n("Y-m-d", strtotime("+ " . $order->BillingFrequency . " " . $order->BillingPeriod, current_time("timestamp"))) . "T0:0:0";
						}
						
						$order->ProfileStartDate = apply_filters("dmrfid_profile_start_date", $order->ProfileStartDate, $order);
						if($this->subscribe($order))
						{
							$order->status = apply_filters("dmrfid_check_status_after_checkout", "success");	//saved on checkout page	
							return true;
						}
						else
						{
							if($this->void($order))
							{
								if(!$order->error)
									$order->error = __("Unknown error: Payment failed.", 'digital-members-rfid' );
							}
							else
							{
								if(!$order->error)
									$order->error = __("Unknown error: Payment failed.", 'digital-members-rfid' );
								
								$order->error .= " " . __("A partial payment was made that we could not void. Please contact the site owner immediately to correct this.", 'digital-members-rfid' );
							}
							
							return false;								
						}
					}
					else
					{
						//only a one time charge
						$order->status = apply_filters("dmrfid_check_status_after_checkout", "success");	//saved on checkout page											
						return true;
					}
				}
				else
				{
					if(empty($order->error))
						$order->error = __("Unknown error: Payment failed.", 'digital-members-rfid' );
					
					return false;
				}	
			}	
		}
		
		function authorize(&$order)
		{
			//create a code for the order
			if(empty($order->code))
				$order->code = $order->getRandomCode();
			
			//simulate a successful authorization
			$order->payment_transaction_id = "CHECK" . $order->code;
			$order->updateStatus("authorized");													
			return true;					
		}
		
		function void(&$order)
		{
			//need a transaction id
			if(empty($order->payment_transaction_id))
				return false;
				
			//simulate a successful void
			$order->payment_transaction_id = "CHECK" . $order->code;
			$order->updateStatus("voided");					
			return true;
		}	
		
		function charge(&$order)
		{
			//create a code for the order
			if(empty($order->code))
				$order->code = $order->getRandomCode();
			
			//simulate a successful charge
			$order->payment_transaction_id = "CHECK" . $order->code;
			$order->updateStatus("success");					
			return true;						
		}
		
		function subscribe(&$order)
		{
			//create a code for the order
			if(empty($order->code))
				$order->code = $order->getRandomCode();
			
			//filter order before subscription. use with care.
			$order = apply_filters("dmrfid_subscribe_order", $order, $this);
			
			//simulate a successful subscription processing
			$order->status = "success";		
			$order->subscription_transaction_id = "CHECK" . $order->code;				
			return true;
		}	
		
		function update(&$order)
		{
			//simulate a successful billing update
			return true;
		}
		
		function cancel(&$order)
		{
			//require a subscription id
			if(empty($order->subscription_transaction_id))
				return false;
			
			//simulate a successful cancel			
			$order->updateStatus("cancelled");					
			return true;
		}	
	}
