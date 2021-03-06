<?php
/*
	Upgrade to 1.8.8
	* Running the cron job cleanup again.
	* Fixing old Authorize.net orders with empty status.
	* Fixing old $0 Stripe orders.	
*/
function dmrfid_upgrade_1_8_8() {
	global $wpdb;
	
	//Running the cron job cleanup again.
	require_once(DMRFID_DIR . "/includes/updates/upgrade_1_8_7.php");
	dmrfid_upgrade_1_8_7();
	
	//Fixing old Authorize.net orders with empty status.
	$sqlQuery = "UPDATE $wpdb->dmrfid_membership_orders SET status = 'success' WHERE gateway = 'authorizenet' AND status = ''";
	$wpdb->query($sqlQuery);
	
	//Fixing old $0 Stripe orders. (Sets up update via AJAX)
	$orders = $wpdb->get_col("SELECT id FROM $wpdb->dmrfid_membership_orders WHERE gateway = 'stripe' AND total = 0");			
	if(!empty($orders))
		dmrfid_addUpdate('dmrfid_upgrade_1_8_8_ajax');

	dmrfid_setOption("db_version", "1.88");
	return 1.88;
}

/*
	If a site has > 100 orders then we run this pasrt of the update via AJAX from the updates page.
*/
function dmrfid_upgrade_1_8_8_ajax() {
	global $wpdb;

	//keeping track of which order we're working on
	$last_order_id = get_option('dmrfid_upgrade_1_8_8_last_order_id', 0);
	
	//Fixing old $0 Stripe orders.	
	$orders = $wpdb->get_col("SELECT id FROM $wpdb->dmrfid_membership_orders WHERE id > $last_order_id AND gateway = 'stripe' AND total = 0 ORDER BY id LIMIT 2");

	//track progress
	$first_load = get_transient('dmrfid_updates_first_load');
	if($first_load) {
		$total_orders = $wpdb->get_var("SELECT COUNT(id) FROM $wpdb->dmrfid_membership_orders WHERE id > $last_order_id AND gateway = 'stripe' AND total = 0");
		update_option('dmrfid_upgrade_1_8_8_total', $total_orders, 'no');
		$progress = 0;
	} else {
		$total_orders = get_option('dmrfid_upgrade_1_8_8_total', 0);
		$progress = get_option('dmrfid_upgrade_1_8_8_progress', 0);
	}
	update_option('dmrfid_upgrade_1_8_8_progress', $progress + count($orders), 'no');
	global $dmrfid_updates_progress;
	if($total_orders > 0)
		$dmrfid_updates_progress = "[" . $progress . "/" . $total_orders . "]";
	else
		$dmrfid_updates_progress = "";
	
	if(empty($orders)) {
		//done with this update			
		dmrfid_removeUpdate('dmrfid_upgrade_1_8_8_ajax');
		delete_option('dmrfid_upgrade_1_8_8_last_order_id');
		delete_option('dmrfid_upgrade_1_8_8_total');
		delete_option('dmrfid_upgrade_1_8_8_progress');
	} else {
		//need to keep working
		foreach($orders as $order_id) {				
			$last_order_id = $order_id;	//keeping track of the last order we processed
			
			//get order
			$order = new MemberOrder($order_id);
			
			//get customer
			$order->Gateway->getCustomer($order);
			
			//get all invoices
			if(!empty($order->Gateway->customer)) {
				try {
					$invoices = $order->Gateway->customer->invoices();
				} catch(Exception $e) {
					//probably no invoices, stay quiet
				}
				
				//get our invoice
				if(!empty($invoices)) {
					try {
						$invoice = $invoices->retrieve($order->payment_transaction_id);
					} catch(Exception $e) {
						//probably no invoice, stay quiet
					}
					
					//get total
					if(!empty($invoice)) {
						if($invoice->total > 0) {
							//invoice we accidentally saved $0 for. update the real total.
							$order->subtotal = (! empty( $invoice->subtotal ) ? $invoice->subtotal / 100 : 0);
							$order->tax = (! empty($invoice->tax) ? $invoice->tax / 100 : null);
							$order->total = (! empty($invoice->total) ? $invoice->total / 100 : 0);
							$order->saveOrder();
						} else {
							//we don't want to track $0 invoices. delete it.
							$order->deleteMe();
						}
					}
				}
			}				
		}
		
		update_option('dmrfid_upgrade_1_8_8_last_order_id', $last_order_id, 'no');
	}	
}
