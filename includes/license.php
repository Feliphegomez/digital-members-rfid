<?php
/*
	This file handles the support licensing control for Digital Members RFID
	and DmRFID addons.
	
	How it works:
	- All source code and resource files bundled with this plugin are licensed under the GPLv2 license unless otherwise noted (e.g. included third-party libraries).
	- An additional "support license" can be purchased at https://www.managertechnology.com.co/pricing/
	  which will simultaneous support the development of this plugin and also give you access to support forums and documentation.
	- Once your license has been purchased, visit Settings --> DmRFID License in your WP dashboard to enter your license.
	- Once the license is activated all "nags" will be disabled in the dashboard and member links will be added where appropriate.
    - This plugin will function 100% even if the support license is not installed.
    - If no support license is detected on this site, prompts will show in the admin to encourage you to purchase one.
	- You can override these prompts by setting the DMRFID_LICENSE_NAG constant to false.
*/

/*
	Developers, add this line to your wp-config.php to remove DmRFID license nags even if no license has been purchased.
	
	define('DMRFID_LICENSE_NAG', false);	//consider purchasing a license at https://www.managertechnology.com.co/pricing/
*/

/*
	Constants
*/
# define('DMRFID_LICENSE_SERVER', 'https://license.paidmembe r s h i pspro.com/');
define('DMRFID_LICENSE_SERVER', 'none');
define('DMRFID_LICENSE_NAG', false);	//consider purchasing a license at https://www.managertechnology.com.co/pricing/

/*
	Check license.
*/
function dmrfid_license_isValid($key = NULL, $type = NULL, $force = false) {		
	//check cache first
	$dmrfid_license_check = get_option('dmrfid_license_check', false);
	if(empty($force) && $dmrfid_license_check !== false && $dmrfid_license_check['enddate'] > current_time('timestamp'))
	{
		if(empty($type))
			return true;
		elseif($type == $dmrfid_license_check['license'])
			return true;
		else
			return false;
	}
	
	//get key and site url
	if(empty($key))
		$key = get_option("dmrfid_license_key", "");
	
	//no key
	if(!empty($key)) 
	{
		return dmrfid_license_check_key($key);
	}
	else
	{
		//no key
		delete_option('dmrfid_license_check');
		add_option('dmrfid_license_check', array('license'=>false, 'enddate'=>0), NULL, 'no');
	
		return false;
	}
}

/*
	Activation/Deactivation. Check keys once a month.
*/
//activation
function dmrfid_license_activation() {
	dmrfid_maybe_schedule_event(current_time('timestamp'), 'monthly', 'dmrfid_license_check_key');
}
register_activation_hook(__FILE__, 'dmrfid_activation');

//deactivation
function dmrfid_license_deactivation() {
	wp_clear_scheduled_hook('dmrfid_license_check_key');
}
register_deactivation_hook(__FILE__, 'dmrfid_deactivation');

//check keys with DmRFID once a month
function dmrfid_license_check_key($key = NULL) {
	//get key
	if(empty($key))
		$key = get_option('dmrfid_license_key');
	
	//key? check with server
	if(!empty($key))
	{
		//check license server
		$url = add_query_arg(array('license'=>$key, 'domain'=>site_url()), DMRFID_LICENSE_SERVER);

        /**
         * Filter to change the timeout for this wp_remote_get() request.
         *
         * @since 1.8.5.1
         *
         * @param int $timeout The number of seconds before the request times out
         */
        $timeout = apply_filters("dmrfid_license_check_key_timeout", 5);

        $r = wp_remote_get($url, array("timeout" => $timeout));

        //test response
        if(is_wp_error($r)) {
            //error
            dmrfid_setMessage("Could not connect to the DmRFID License Server to check key Try again later.", "error");
        }
        elseif(!empty($r) && $r['response']['code'] == 200)
		{
			$r = json_decode($r['body']);
						
			if($r->active == 1)
			{
				//valid key save enddate
				if(!empty($r->enddate))
					$enddate = strtotime($r->enddate, current_time('timestamp'));
				else
					$enddate = strtotime("+1 Year", current_time("timestamp"));
					
				delete_option('dmrfid_license_check');
				add_option('dmrfid_license_check', array('license'=>$r->license, 'enddate'=>$enddate), NULL, 'no');		
				return true;
			}
			elseif(!empty($r->error))
			{
				//invalid key
				global $dmrfid_license_error;
				$dmrfid_license_error = $r->error;
				
				delete_option('dmrfid_license_check');
				add_option('dmrfid_license_check', array('license'=>false, 'enddate'=>0), NULL, 'no');
                
			}
		}	
	}

    //no key or there was an error
    return false;
}
add_action('dmrfid_license_check_key', 'dmrfid_license_check_key');
