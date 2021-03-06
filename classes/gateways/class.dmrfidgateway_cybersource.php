<?php
	//include dmrfidgateway
	require_once(dirname(__FILE__) . "/class.dmrfidgateway.php");
	//load classes init method
	add_action('init', array('DmRFIDGateway_cybersource', 'init'));
	class DmRFIDGateway_cybersource extends DmRFIDGateway
	{
		function __construct($gateway = NULL)
		{
			if(!class_exists("CyberSourceSoapClient"))
				require_once(dirname(__FILE__) . "/../../includes/lib/CyberSource/cyber_source_soap_client.php");
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
			//make sure CyberSource is a gateway option
			add_filter('dmrfid_gateways', array('DmRFIDGateway_cybersource', 'dmrfid_gateways'));
			//add fields to payment settings
			add_filter('dmrfid_payment_options', array('DmRFIDGateway_cybersource', 'dmrfid_payment_options'));
			add_filter('dmrfid_payment_option_fields', array('DmRFIDGateway_cybersource', 'dmrfid_payment_option_fields'), 10, 2);
		}
		/**
		 * Make sure this gateway is in the gateways list
		 *
		 * @since 1.8
		 */
		static function dmrfid_gateways($gateways)
		{
			if(empty($gateways['cybersource']))
				$gateways['cybersource'] = __('CyberSource', 'digital-members-rfid' );
			return $gateways;
		}
		/**
		 * Get a list of payment options that the this gateway needs/supports.
		 *
		 * @since 1.8
		 */
		static function getGatewayOptions()
		{
			$options = array(
				'sslseal',
				'nuclear_HTTPS',
				'gateway_environment',
				'cybersource_merchantid',
				'cybersource_securitykey',
				'currency',
				'use_ssl',
				'tax_state',
				'tax_rate',
				'accepted_credit_cards',
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
			$cybersource_options = DmRFIDGateway_cybersource::getGatewayOptions();
			//merge with others.
			$options = array_merge($cybersource_options, $options);
			return $options;
		}
		/**
		 * Display fields for this gateway's options.
		 *
		 * @since 1.8
		 */
		static function dmrfid_payment_option_fields($values, $gateway)
		{
		?>
		<tr class="dmrfid_settings_divider gateway gateway_cybersource" <?php if($gateway != "cybersource") { ?>style="display: none;"<?php } ?>>
			<td colspan="2">
				<hr />
				<h2 class="title"><?php esc_html_e( 'CyberSource Settings', 'digital-members-rfid' ); ?></h2>
			</td>
		</tr>
		<tr class="gateway gateway_cybersource" <?php if($gateway != "cybersource") { ?>style="display: none;"<?php } ?>>
			<td colspan="2" style="padding: 0px;">
				<p class="dmrfid_message"><?php _e('Note', 'digital-members-rfid' );?>:</strong> <?php _e('This gateway option is in beta. Some functionality may not be available. Please contact Digital Members RFID with any issues you run into. <strong>Please be sure to upgrade Digital Members RFID to the latest versions when available.</strong>', 'digital-members-rfid' );?></p>
			</td>
		</tr>
		<tr class="gateway gateway_cybersource" <?php if($gateway != "cybersource") { ?>style="display: none;"<?php } ?>>
			<th scope="row" valign="top">
				<label for="cybersource_merchantid"><?php _e('Merchant ID', 'digital-members-rfid' );?>:</label>
			</th>
			<td>
				<input type="text" id="cybersource_merchantid" name="cybersource_merchantid" value="<?php echo esc_attr($values['cybersource_merchantid'])?>" class="regular-text code" />
			</td>
		</tr>
		<tr class="gateway gateway_cybersource" <?php if($gateway != "cybersource") { ?>style="display: none;"<?php } ?>>
			<th scope="row" valign="top">
				<label for="cybersource_securitykey"><?php _e('Transaction Security Key', 'digital-members-rfid' );?>:</label>
			</th>
			<td>
				<textarea id="cybersource_securitykey" name="cybersource_securitykey" rows="3" cols="50" class="large-text code"><?php echo esc_textarea($values['cybersource_securitykey']);?></textarea>
			</td>
		</tr>
		<?php
		}
		/**
		 * Process checkout.
		 *
		 */
		function process(&$order)
		{
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
						$order->status = "success";	//saved on checkout page
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
		function getCardType($name)
		{
			$card_types = array(
				'Visa' => '001',
				'MasterCard' => '002',
				'Mastercard' => '002',
				'Master Card' => '002',
				'AMEX' => '003',
				'American Express' => '003',
				'Discover' => '004',
				'Diners Club' => '005',
				'Carte Blanche' => '006',
				'JCB' => '007'
			);
			if(isset($card_types[$name]))
				return $card_types[$name];
			else
				return false;
		}
		function getWSDL($order)
		{
			//which gateway environment?
			if(empty($order->gateway_environment))
				$gateway_environment = dmrfid_getOption("gateway_environment");
			else
				$gateway_environment = $order->gateway_environment;
			//which host?
			if($gateway_environment == "live")
					$host = "ics2ws.ic3.com";
				else
					$host = "ics2wstest.ic3.com";
			//path
			$path = "/commerce/1.x/transactionProcessor/CyberSourceTransaction_1.159.wsdl";
			//build url
			$wsdl_url = "https://" . $host . $path;
			//filter
			$wsdl_url = apply_filters("dmrfid_cybersource_wsdl_url", $wsdl_url, $gateway_environment);
			return $wsdl_url;
		}
		function authorize(&$order)
		{
			global $dmrfid_currency;
			if(empty($order->code))
				$order->code = $order->getRandomCode();
			$wsdl_url = $this->getWSDL($order);
			//what amount to authorize? just $1 to test
			$amount = "1.00";
			//combine address
			$address = $order->Address1;
			if(!empty($order->Address2))
				$address .= "\n" . $order->Address2;
			//customer stuff
			$customer_email = $order->Email;
			$customer_phone = $order->billing->phone;
			if(!isset($order->membership_level->name))
				$order->membership_level->name = "";
			//to store our request
			$request = new stdClass();
			//which service?
			$ccAuthService = new stdClass();
			$ccAuthService->run = "true";
			$request->ccAuthService = $ccAuthService;
			//merchant id and order code
			$request->merchantID = dmrfid_getOption("cybersource_merchantid");
			$request->merchantReferenceCode = $order->code;
			//bill to
			$billTo = new stdClass();
			$billTo->firstName = $order->FirstName;
			$billTo->lastName = $order->LastName;
			$billTo->street1 = $address;
			$billTo->city = $order->billing->city;
			$billTo->state = $order->billing->state;
			$billTo->postalCode = $order->billing->zip;
			$billTo->country = $order->billing->country;
			$billTo->email = $order->Email;
			$billTo->ipAddress = $_SERVER['REMOTE_ADDR'];
			$request->billTo = $billTo;
			//card
			$card = new stdClass();
			$card->cardType = $this->getCardType($order->cardtype);
			$card->accountNumber = $order->accountnumber;
			$card->expirationMonth = $order->expirationmonth;
			$card->expirationYear = $order->expirationyear;
			$card->cvNumber = $order->CVV2;
			$request->card = $card;

			if( empty($request->card->cardType) )
			{
				$order->error = __( "Error validating credit card type. Make sure your credit card number is correct and try again.", "digital-members-rfid" );
				$order->shorterror = __( "Error validating credit card type. Make sure your credit card number is correct and try again.", "digital-members-rfid" );
				return false;
			}
			
			//currency
			$purchaseTotals = new stdClass();
			$purchaseTotals->currency = $dmrfid_currency;
			$request->purchaseTotals = $purchaseTotals;

			//item/price
			$item0 = new stdClass();
			$item0->unitPrice = $amount;
			$item0->quantity = "1";
			$item0->productName = $order->membership_level->name . " Membership";
			$item0->productSKU = $order->membership_level->id;
			$item0->id = $order->membership_id;
			$request->item = array($item0);


			try
			{
				$soapClient = new CyberSourceSoapClient($wsdl_url, array("merchantID"=>$request->merchantID, "transactionKey"=>dmrfid_getOption("cybersource_securitykey")));
				$reply = $soapClient->runTransaction($request);
			}
			catch(Throwable $t)
			{
				$order->error = sprintf( __( 'Error communicating with Cybersource: %', 'digital-members-rfid' ), $t->getMessage() );
				$order->shorterror = __( 'Error communicating with Cybersource.', 'digital-members-rfid' );
				return false;			
			}
			catch(Exception $e)
			{
				$order->error = sprintf( __( 'Error communicating with Cybersource: %', 'digital-members-rfid' ), $e->getMessage() );
				$order->shorterror = __( 'Error communicating with Cybersource.', 'digital-members-rfid' );
				return false;
			}

			if($reply->reasonCode == "100")
			{
				//success
				$order->payment_transaction_id = $reply->requestID;
				$order->updateStatus("authorized");
				return true;
			}
			else
			{
				//error
				$order->errorcode = $reply->reasonCode;
				$order->error = $this->getErrorFromCode($reply);
				$order->shorterror = $this->getErrorFromCode($reply);
				return false;
			}
		}
		function void(&$order)
		{
			//need a transaction id
			if(empty($order->payment_transaction_id))
				return false;
			//get wsdl
			$wsdl_url = $this->getWSDL($order);
			//to store our request
			$request = new stdClass();
			//which service?
			$voidService = new stdClass();
			$voidService->run = "true";
			$voidService->voidRequestID = $order->payment_transaction_id;
			$request->voidService = $voidService;
			//merchant id and order code
			$request->merchantID = dmrfid_getOption("cybersource_merchantid");
			$request->merchantReferenceCode = $order->code;

			try
			{
				$soapClient = new CyberSourceSoapClient($wsdl_url, array("merchantID"=>$request->merchantID, "transactionKey"=>dmrfid_getOption("cybersource_securitykey")));
				$reply = $soapClient->runTransaction($request);
			}
			catch(Throwable $t)
			{
				$order->error = sprintf( __( 'Error communicating with Cybersource: %', 'digital-members-rfid' ), $t->getMessage() );
				$order->shorterror = __( 'Error communicating with Cybersource.', 'digital-members-rfid' );
				return false;			
			}
			catch(Exception $e)
			{
				$order->error = sprintf( __( 'Error communicating with Cybersource: %', 'digital-members-rfid' ), $e->getMessage() );
				$order->shorterror = __( 'Error communicating with Cybersource.', 'digital-members-rfid' );
				return false;
			}

			if($reply->reasonCode == "100")
			{
				//success
				$order->payment_transaction_id = $reply->requestID;
				$order->updateStatus("voided");
				return true;
			}
			else
			{
				//error
				$order->errorcode = $reply->reasonCode;
				$order->error = $this->getErrorFromCode($reply);
				$order->shorterror = $this->getErrorFromCode($reply);
				return false;
			}
		}
		function charge(&$order)
		{
			global $dmrfid_currency;
			//get a code
			if(empty($order->code))
				$order->code = $order->getRandomCode();
			//get wsdl
			$wsdl_url = $this->getWSDL($order);
			//what amount to charge?
			$amount = $order->InitialPayment;
			//tax
			$order->subtotal = $amount;
			$tax = $order->getTax(true);
			$amount = dmrfid_round_price((float)$order->subtotal + (float)$tax);
			//combine address
			$address = $order->Address1;
			if(!empty($order->Address2))
				$address .= "\n" . $order->Address2;
			//customer stuff
			$customer_email = $order->Email;
			$customer_phone = $order->billing->phone;
			if(!isset($order->membership_level->name))
				$order->membership_level->name = "";
			//to store our request
			$request = new stdClass();
			//authorize and capture
			$ccAuthService = new stdClass();
			$ccAuthService->run = "true";
			$request->ccAuthService = $ccAuthService;
			$ccCaptureService = new stdClass();
			$ccCaptureService->run = "true";
			$request->ccCaptureService = $ccCaptureService;
			//merchant id and order code
			$request->merchantID = dmrfid_getOption("cybersource_merchantid");
			$request->merchantReferenceCode = $order->code;
			//bill to
			$billTo = new stdClass();
			$billTo->firstName = $order->FirstName;
			$billTo->lastName = $order->LastName;
			$billTo->street1 = $address;
			$billTo->city = $order->billing->city;
			$billTo->state = $order->billing->state;
			$billTo->postalCode = $order->billing->zip;
			$billTo->country = $order->billing->country;
			$billTo->email = $order->Email;
			$billTo->ipAddress = $_SERVER['REMOTE_ADDR'];
			$request->billTo = $billTo;
			//card
			$card = new stdClass();
			$card->cardType = $this->getCardType($order->cardtype);
			$card->accountNumber = $order->accountnumber;
			$card->expirationMonth = $order->expirationmonth;
			$card->expirationYear = $order->expirationyear;
			$card->cvNumber = $order->CVV2;
			$request->card = $card;

			if( empty($request->card->cardType) )
			{
				$order->error = __( "Error validating credit card type. Make sure your credit card number is correct and try again.", "digital-members-rfid" );
				$order->shorterror = __( "Error validating credit card type. Make sure your credit card number is correct and try again.", "digital-members-rfid" );
				return false;
			}

			//currency
			$purchaseTotals = new stdClass();
			$purchaseTotals->currency = $dmrfid_currency;
			$request->purchaseTotals = $purchaseTotals;
			//item/price
			$item0 = new stdClass();
			$item0->unitPrice = $amount;
			$item0->quantity = "1";
			$item0->productName = $order->membership_level->name . " Membership";
			$item0->productSKU = $order->membership_level->id;
			$item0->id = $order->membership_id;
			$request->item = array($item0);

			try
			{
				$soapClient = new CyberSourceSoapClient($wsdl_url, array("merchantID"=>$request->merchantID, "transactionKey"=>dmrfid_getOption("cybersource_securitykey")));
				$reply = $soapClient->runTransaction($request);
			}
			catch(Throwable $t)
			{
				$order->error = sprintf( __( 'Error communicating with Cybersource: %', 'digital-members-rfid' ), $t->getMessage() );
				$order->shorterror = __( 'Error communicating with Cybersource.', 'digital-members-rfid' );
				return false;			
			}
			catch(Exception $e)
			{
				$order->error = sprintf( __( 'Error communicating with Cybersource: %', 'digital-members-rfid' ), $e->getMessage() );
				$order->shorterror = __( 'Error communicating with Cybersource.', 'digital-members-rfid' );
				return false;
			}

			if($reply->reasonCode == "100")
			{
				//success
				$order->payment_transaction_id = $reply->requestID;
				$order->updateStatus("success");
				return true;
			}
			else
			{
				//error
				$order->errorcode = $reply->reasonCode;
				$order->error = $this->getErrorFromCode($reply);
				$order->shorterror = $this->getErrorFromCode($reply);
				return false;
			}
		}
		function subscribe(&$order)
		{
			global $dmrfid_currency;
			//create a code for the order
			if(empty($order->code))
				$order->code = $order->getRandomCode();
			//filter order before subscription. use with care.
			$order = apply_filters("dmrfid_subscribe_order", $order, $this);
			//get wsdl
			$wsdl_url = $this->getWSDL($order);
			//to store our request
			$request = new stdClass();
			//set service type
			$paySubscriptionCreateService = new stdClass();
			$paySubscriptionCreateService->run = 'true';
			$paySubscriptionCreateService->disableAutoAuth = 'true';	//we do our own auth check
			$request->paySubscriptionCreateService  = $paySubscriptionCreateService;
			//merchant id and order code
			$request->merchantID = dmrfid_getOption("cybersource_merchantid");
			$request->merchantReferenceCode = $order->code;
			/*
				set up billing amount/etc
			*/
			//figure out the amounts
			$amount = $order->PaymentAmount;
			$amount_tax = $order->getTaxForPrice($amount);
			$amount = dmrfid_round_price((float)$amount + (float)$amount_tax);
			/*
				There are two parts to the trial. Part 1 is simply the delay until the first payment
				since we are doing the first payment as a separate transaction.
				The second part is the actual "trial" set by the admin.
			*/
			//figure out the trial length (first payment handled by initial charge)
			if($order->BillingPeriod == "Year")
				$trial_period_days = $order->BillingFrequency * 365;	//annual
			elseif($order->BillingPeriod == "Day")
				$trial_period_days = $order->BillingFrequency * 1;		//daily
			elseif($order->BillingPeriod == "Week")
				$trial_period_days = $order->BillingFrequency * 7;		//weekly
			else
				$trial_period_days = $order->BillingFrequency * 30;	//assume monthly
			//convert to a profile start date
			$order->ProfileStartDate = date_i18n("Y-m-d", strtotime("+ " . $trial_period_days . " Day", current_time("timestamp"))) . "T0:0:0";
			//filter the start date
			$order->ProfileStartDate = apply_filters("dmrfid_profile_start_date", $order->ProfileStartDate, $order);
			//convert back to days
			$trial_period_days = ceil(abs(strtotime(date_i18n("Y-m-d"), current_time('timestamp')) - strtotime($order->ProfileStartDate, current_time("timestamp"))) / 86400);
			//now add the actual trial set by the site
			if(!empty($order->TrialBillingCycles))
			{
				$trialOccurrences = (int)$order->TrialBillingCycles;
				if($order->BillingPeriod == "Year")
					$trial_period_days = $trial_period_days + (365 * $order->BillingFrequency * $trialOccurrences);	//annual
				elseif($order->BillingPeriod == "Day")
					$trial_period_days = $trial_period_days + (1 * $order->BillingFrequency * $trialOccurrences);		//daily
				elseif($order->BillingPeriod == "Week")
					$trial_period_days = $trial_period_days + (7 * $order->BillingFrequency * $trialOccurrences);	//weekly
				else
					$trial_period_days = $trial_period_days + (30 * $order->BillingFrequency * $trialOccurrences);	//assume monthly
			}
			//convert back into a date
			$profile_start_date = date_i18n("Ymd", strtotime("+ " . $trial_period_days . " Days"));
			//figure out the frequency
			if($order->BillingPeriod == "Year")
			{
				$frequency = "annually";	//ignoring BillingFrequency set on level.
			}
			elseif($order->BillingPeriod == "Month")
			{
				if($order->BillingFrequency == 6)
					$frequency = "semi annually";
				elseif($order->BillingFrequency == 3)
					$frequency = "quarterly";
				else
					$frequency = "monthly";
			}
			elseif($order->BillingPeriod == "Week")
			{
				if($order->BillingFrequency == 4)
					$frequency = "quad-weekly";
				elseif($order->BillingFrequency == 2)
					$frequency = "bi-weekly";
				else
					$frequency = "weekly";
			}
			elseif($order->BillingPeriod == "Day")
			{
				if($order->BillingFrequency == 365)
					$frequency = "annually";
				elseif($order->BillingFrequency == 182)
					$frequency = "semi annually";
				elseif($order->BillingFrequency == 183)
					$frequency = "semi annually";
				elseif($order->BillingFrequency == 90)
					$frequency = "quarterly";
				elseif($order->BillingFrequency == 30)
					$frequency = "monthly";
				elseif($order->BillingFrequency == 15)
					$frequency = "semi-monthly";
				elseif($order->BillingFrequency == 28)
					$frequency = "quad-weekly";
				elseif($order->BillingFrequency == 14)
					$frequency = "bi-weekly";
				elseif($order->BillingFrequency == 7)
					$frequency = "weekly";
			}
			//set subscription info for API
			$subscription = new stdClass();
			$subscription->title = $order->membership_level->name;
			$subscription->paymentMethod = "credit card";
			$request->subscription = $subscription;
			//recurring info
			$recurringSubscriptionInfo = new stdClass();
			$recurringSubscriptionInfo->amount = number_format($amount, 2);
			$recurringSubscriptionInfo->startDate = $profile_start_date;
			$recurringSubscriptionInfo->frequency = $frequency;
			if(!empty($order->TotalBillingCycles))
				$recurringSubscriptionInfo->numberOfPayments = $order->TotalBillingCycles;
			$request->recurringSubscriptionInfo = $recurringSubscriptionInfo;
			//combine address
			$address = $order->Address1;
			if(!empty($order->Address2))
				$address .= "\n" . $order->Address2;
			//bill to
			$billTo = new stdClass();
			$billTo->firstName = $order->FirstName;
			$billTo->lastName = $order->LastName;
			$billTo->street1 = $address;
			$billTo->city = $order->billing->city;
			$billTo->state = $order->billing->state;
			$billTo->postalCode = $order->billing->zip;
			$billTo->country = $order->billing->country;
			$billTo->email = $order->Email;
			$billTo->ipAddress = $_SERVER['REMOTE_ADDR'];
			$request->billTo = $billTo;
			//card
			$card = new stdClass();
			$card->cardType = $this->getCardType($order->cardtype);
			$card->accountNumber = $order->accountnumber;
			$card->expirationMonth = $order->expirationmonth;
			$card->expirationYear = $order->expirationyear;
			$card->cvNumber = $order->CVV2;
			$request->card = $card;

			if( empty($request->card->cardType) )
			{
				$order->error = __( "The payment gateway doesn't support this credit/debit card type.", "digital-members-rfid" );
				$order->updateStatus("error");
				return false;
			}

			//currency
			$purchaseTotals = new stdClass();
			$purchaseTotals->currency = $dmrfid_currency;
			$request->purchaseTotals = $purchaseTotals;

			try
			{
				$soapClient = new CyberSourceSoapClient($wsdl_url, array("merchantID"=>$request->merchantID, "transactionKey"=>dmrfid_getOption("cybersource_securitykey")));
				$reply = $soapClient->runTransaction($request);
			}
			catch(Throwable $t)
			{
				$order->error = sprintf( __( 'Error communicating with Cybersource: %', 'digital-members-rfid' ), $t->getMessage() );
				$order->shorterror = __( 'Error communicating with Cybersource.', 'digital-members-rfid' );
				return false;			
			}
			catch(Exception $e)
			{
				$order->error = sprintf( __( 'Error communicating with Cybersource: %', 'digital-members-rfid' ), $e->getMessage() );
				$order->shorterror = __( 'Error communicating with Cybersource.', 'digital-members-rfid' );
				return false;
			}

			if($reply->reasonCode == "100")
			{
				//success
				$order->subscription_transaction_id = $reply->requestID;
				$order->status = "success";
				return true;
			}
			else
			{
				//error
				$order->status = "error";
				$order->errorcode = $reply->reasonCode;
				$order->error = $this->getErrorFromCode($reply);
				$order->shorterror = $this->getErrorFromCode($reply);
				return false;
			}
		}
		function update(&$order)
		{
			//get wsdl
			$wsdl_url = $this->getWSDL($order);
			//to store our request
			$request = new stdClass();
			//set service type
			$paySubscriptionUpdateService  = new stdClass();
			$paySubscriptionUpdateService ->run = "true";
			$request->paySubscriptionUpdateService   = $paySubscriptionUpdateService ;
			//merchant id and order code
			$request->merchantID = dmrfid_getOption("cybersource_merchantid");
			$request->merchantReferenceCode = $order->code;
			//set subscription info for API
			$recurringSubscriptionInfo = new stdClass();
			$recurringSubscriptionInfo->subscriptionID  = $order->subscription_transaction_id;
			$request->recurringSubscriptionInfo = $recurringSubscriptionInfo;
			//combine address
			$address = $order->Address1;
			if(!empty($order->Address2))
				$address .= "\n" . $order->Address2;
			//bill to
			$billTo = new stdClass();
			$billTo->firstName = $order->FirstName;
			$billTo->lastName = $order->LastName;
			$billTo->street1 = $address;
			$billTo->city = $order->billing->city;
			$billTo->state = $order->billing->state;
			$billTo->postalCode = $order->billing->zip;
			$billTo->country = $order->billing->country;
			$billTo->email = $order->Email;
			$billTo->ipAddress = $_SERVER['REMOTE_ADDR'];
			$request->billTo = $billTo;
			//card
			$card = new stdClass();
			$card->cardType = $this->getCardType($order->cardtype);
			$card->accountNumber = $order->accountnumber;
			$card->expirationMonth = $order->expirationmonth;
			$card->expirationYear = $order->expirationyear;
			$card->cvNumber = $order->CVV2;
			$request->card = $card;

			if( empty($request->card->cardType) )
			{
				$order->error = __( "Error validating credit card type. Make sure your credit card number is correct and try again.", "digital-members-rfid", "digital-members-rfid" );
				$order->shorterror = __( "Error validating credit card type. Make sure your credit card number is correct and try again.", "digital-members-rfid", "digital-members-rfid" );
				return false;
			}

			try
			{
				$soapClient = new CyberSourceSoapClient($wsdl_url, array("merchantID"=>$request->merchantID, "transactionKey"=>dmrfid_getOption("cybersource_securitykey")));
				$reply = $soapClient->runTransaction($request);
			}
			catch(Throwable $t)
			{
				$order->error = sprintf( __( 'Error communicating with Cybersource: %', 'digital-members-rfid' ), $t->getMessage() );
				$order->shorterror = __( 'Error communicating with Cybersource.', 'digital-members-rfid' );
				return false;			
			}
			catch(Exception $e)
			{
				$order->error = sprintf( __( 'Error communicating with Cybersource: %', 'digital-members-rfid' ), $e->getMessage() );
				$order->shorterror = __( 'Error communicating with Cybersource.', 'digital-members-rfid' );
				return false;
			}

			if($reply->reasonCode == "100")
			{
				//success
				return true;
			}
			else
			{
				//error
				$order->errorcode = $reply->reasonCode;
				$order->error = $this->getErrorFromCode($reply);
				$order->shorterror = $this->getErrorFromCode($reply);
				return false;
			}
		}

		function cancel(&$order)
		{
			//require a subscription id
			if(empty($order->subscription_transaction_id))
				return false;
			//get wsdl
			$wsdl_url = $this->getWSDL($order);
			//to store our request
			$request = new stdClass();
			//which service?
			$paySubscriptionDeleteService  = new stdClass();
			$paySubscriptionDeleteService ->run = "true";
			$request->paySubscriptionDeleteService  = $paySubscriptionDeleteService ;
			//which order
			$recurringSubscriptionInfo  = new stdClass();
			$recurringSubscriptionInfo->subscriptionID = $order->subscription_transaction_id;
			$request->recurringSubscriptionInfo = $recurringSubscriptionInfo;
			//merchant id and order code
			$request->merchantID = dmrfid_getOption("cybersource_merchantid");
			$request->merchantReferenceCode = $order->code;

			try
			{
				$soapClient = new CyberSourceSoapClient($wsdl_url, array("merchantID"=>$request->merchantID, "transactionKey"=>dmrfid_getOption("cybersource_securitykey")));
				$reply = $soapClient->runTransaction($request);
			}
			catch(Throwable $t)
			{
				$order->error = sprintf( __( 'Error communicating with Cybersource: %', 'digital-members-rfid' ), $t->getMessage() );
				$order->shorterror = __( 'Error communicating with Cybersource.', 'digital-members-rfid' );
				return false;			
			}
			catch(Exception $e)
			{
				$order->error = sprintf( __( 'Error communicating with Cybersource: %', 'digital-members-rfid' ), $e->getMessage() );
				$order->shorterror = __( 'Error communicating with Cybersource.', 'digital-members-rfid' );
				return false;
			}

			if($reply->reasonCode == "100")
			{
				//success
				$order->updateStatus("cancelled");
				return true;
			}
			else
			{
				//error
				$order->errorcode = $reply->reasonCode;
				$order->error = $this->getErrorFromCode($reply);
				$order->shorterror = $this->getErrorFromCode($reply);
				return false;
			}
		}

		function getErrorFromCode($reply)
		{
			$error_messages = array(
				"100" => __( "Successful transaction.", "digital-members-rfid" ),
				"101" => __( "The request is missing one or more required fields.", "digital-members-rfid" ),
				"102" => __( "One or more fields in the request contains invalid data. Check that your billing address is valid.", "digital-members-rfid" ),
				"104" => __( "Duplicate order detected.", "digital-members-rfid" ),
				"110" => __( "Only partial amount was approved.", "digital-members-rfid" ),
				"150" => __( "Error: General system failure.", "digital-members-rfid" ),
				"151" => __( "Error: The request was received but there was a server timeout.", "digital-members-rfid" ),
				"152" => __( "Error: The request was received, but a service did not finish running in time. ", "digital-members-rfid" ),
				"200" => __( "Address Verification Service (AVS) failure.", "digital-members-rfid" ),
				"201" => __( "Authorization failed.", "digital-members-rfid" ),
				"202" => __( "Expired card or invalid expiration date.", "digital-members-rfid" ),
				"203" => __( "The card was declined.", "digital-members-rfid" ),
				"204" => __( "Insufficient funds in the account.", "digital-members-rfid" ),
				"205" => __( "Stolen or lost card.", "digital-members-rfid" ),
				"207" => __( "Issuing bank unavailable.", "digital-members-rfid" ),
				"208" => __( "Inactive card or card not authorized for card-not-present transactions.", "digital-members-rfid" ),
				"209" => __( "American Express Card Identification Digits (CID) did not match.", "digital-members-rfid" ),
				"210" => __( "The card has reached the credit limit. ", "digital-members-rfid" ),
				"211" => __( "Invalid card verification number.", "digital-members-rfid" ),
				"221" => __( "The customer matched an entry on the processors negative file. ", "digital-members-rfid" ),
				"230" => __( "Card verification (CV) check failed.", "digital-members-rfid" ),
				"231" => __( "Invalid account number.", "digital-members-rfid" ),
				"232" => __( "The card type is not accepted by the payment processor.", "digital-members-rfid" ),
				"233" => __( "General decline by the processor.", "digital-members-rfid" ),
				"234" => __( "There is a problem with your CyberSource merchant configuration.", "digital-members-rfid" ),
				"235" => __( "The requested amount exceeds the originally authorized amount.", "digital-members-rfid" ),
				"236" => __( "Processor failure.", "digital-members-rfid" ),
				"237" => __( "The authorization has already been reversed.", "digital-members-rfid" ),
				"238" => __( "The authorization has already been captured.", "digital-members-rfid" ),
				"239" => __( "The requested transaction amount must match the previous transaction amount.", "digital-members-rfid" ),
				"240" => __( "The card type sent is invalid or does not correlate with the credit card number.", "digital-members-rfid" ),
				"241" => __( "The referenced request id is invalid for all follow-on transactions.", "digital-members-rfid" ),
				"242" => __( "The request ID is invalid.", "digital-members-rfid" ),
				"243" => __( "The transaction has already been settled or reversed.", "digital-members-rfid" ),
				"246" => __( "The capture or credit is not voidable because the capture or credit information has already been submitted to your processor. Or, you requested a void for a type of transaction that cannot be voided.", "digital-members-rfid" ),
				"247" => __( "You requested a credit for a capture that was previously voided.", "digital-members-rfid" ),
				"250" => __( "Error: The request was received, but there was a timeout at the payment processor.", "digital-members-rfid" ),
				"254" => __( "Stand-alone credits are not allowed with this processor.", "digital-members-rfid" ),
				"450" => __( "Apartment number missing or not found. Check that your billing address is valid.", "digital-members-rfid" ),
				"451" => __( "Insufficient address information. Check that your billing address is valid.", "digital-members-rfid" ),
				"452" => __( "House/Box number not found on street. Check that your billing address is valid.", "digital-members-rfid" ),
				"453" => __( "Multiple address matches were found. Check that your billing address is valid.", "digital-members-rfid" ),
				"454" => __( "P.O. Box identifier not found or out of range.. Check that your billing address is valid.", "digital-members-rfid" ),
				"455" => __( "Route service identifier not found or out of range. Check that your billing address is valid.", "digital-members-rfid" ),
				"456" => __( "Street name not found in Postal code. Check that your billing address is valid.", "digital-members-rfid" ),
				"457" => __( "Postal code not found in database. Check that your billing address is valid.", "digital-members-rfid" ),
				"458" => __( "Unable to verify or correct address. Check that your billing address is valid.", "digital-members-rfid" ),
				"459" => __( "Multiple address matches were found (international). Check that your billing address is valid.", "digital-members-rfid" ),
				"460" => __( "Address match not found. Check that your billing address is valid.", "digital-members-rfid" ),
				"461" => __( "Unsupported character set. Verify the character set that you are using to process transactions.", "digital-members-rfid" ),
				"481" => __( "Order has been rejected by Decision Manager.", "digital-members-rfid" ),
				"520" => __( "Smart Authorization failed.", "digital-members-rfid" ),
				"700" => __( "Your order has been refused.", "digital-members-rfid" ),
			);

			if(isset($error_messages[$reply->reasonCode]))
				$error = $error_messages[$reply->reasonCode];
			else
				return __( "Unknown error.", "digital-members-rfid" );
			
			// list invalid fields from reply
			if( isset($reply->invalidField) && !empty($reply->invalidField) )
			{
				$error .= __( " Invalid fields:", "digital-members-rfid" );
				$invalidFields = $reply->invalidField;
				$invalidFields = str_replace("/", ",", $invalidFields);
				$invalidFields = str_replace("c:", " ", $invalidFields);
				$error .= $invalidFields;
			}

			return $error;
		}
	}
