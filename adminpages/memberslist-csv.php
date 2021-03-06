<?php

	if(!function_exists("current_user_can") || (!current_user_can("manage_options") && !current_user_can("dmrfid_memberslistcsv")))
	{
		die(__("You do not have permissions to perform this action.", 'digital-members-rfid' ));
	}

	if (!defined('DMRFID_BENCHMARK'))
		define('DMRFID_BENCHMARK', false);

	if (DMRFID_BENCHMARK)
	{
		error_log(str_repeat('-', 10) . date_i18n('Y-m-d H:i:s') . str_repeat('-', 10));
		$start_time = microtime(true);
		$start_memory = memory_get_usage(true);
	}


	/**
	 * Filtrar para establecer el número máximo de registros a procesar a la vez para la exportación 
	 * (ayuda a administrar la huella de memoria)
	 *
	 * Regla de oro: 2000 registros: ~50-60 MB de adicional. memoria (memory_limit debe estar entre 128 MB y 256 MB)
	 *               4000 registros: ~70-100 MB de adicional. memoria (el límite de memoria debe ser> = 256 MB)
	 *               6000 registros: ~100-140 MB de adicional. memoria (el límite de memoria debe ser> = 256 MB)
	 *
	 * NOTA: Utilice el gancho dmrfid_before_members_list_csv_export para aumentar la memoria "sobre la marcha". 
	 *       Se puede restablecer con el gancho dmrfid_after_members_list_csv_export
	 *
	 * @since 1.8.7
	 */
	//set the number of users we'll load to try and protect ourselves from OOM errors
	$max_users_per_loop = apply_filters('dmrfid_set_max_user_per_export_loop', 2000);

	global $wpdb;

	//get users (search input field)
	if(isset($_REQUEST['s']))
		$s = sanitize_text_field($_REQUEST['s']);
	else
		$s = "";

	// requested a level id
	if(isset($_REQUEST['l']))
		$l = sanitize_text_field($_REQUEST['l']);
	else
		$l = false;

	//some vars for the search
	if(!empty($_REQUEST['pn']))
		$pn = intval($_REQUEST['pn']);
	else
		$pn = 1;

	if(!empty($_REQUEST['limit']))
		$limit = intval($_REQUEST['limit']);
	else
		$limit = false;

	if($limit)
	{
		$end = $pn * $limit;
		$start = $end - $limit;
	}
	else
	{
		$end = NULL;
		$start = NULL;
	}

	$headers = array();
	$headers[] = "Content-Type: text/csv";
	$headers[] = "Cache-Control: max-age=0, no-cache, no-store";
	$headers[] = "Pragma: no-cache";
	$headers[] = "Connection: close";

	if($s && $l == "oldmembers")
		$headers[] = 'Content-Disposition: attachment; filename="members_list_expired_' . sanitize_file_name($s) . '.csv"';
	elseif($s && $l)
		$headers[] = 'Content-Disposition: attachment; filename="members_list_' . intval($l) . '_level_' . sanitize_file_name($s) . '.csv"';
	elseif($s)
		$headers[] = 'Content-Disposition: attachment; filename="members_list_' . sanitize_file_name($s) . '.csv"';
	elseif($l == "oldmembers")
		$headers[] = 'Content-Disposition: attachment; filename="members_list_expired.csv"';
	else
		$headers[] = 'Content-Disposition: attachment; filename="members_list.csv"';

	//set default CSV file headers, using comma as delimiter
	$csv_file_header = "id,username,firstname,lastname,email,billing firstname,billing lastname,address1,address2,city,state,zipcode,country,phone,membership,initial payment,fee,term,discount_code_id,discount_code,joined";

	if($l == "oldmembers")
		$csv_file_header .= ",ended";
	else
		$csv_file_header .= ",expires";

	//these are the meta_keys for the fields (arrays are object, property. so e.g. $theuser->ID)
	$default_columns = array(
		array("theuser", "ID"),
		array("theuser", "user_login"),
		array("metavalues", "first_name"),
		array("metavalues", "last_name"),
		array("theuser", "user_email"),
		array("metavalues", "dmrfid_bfirstname"),
		array("metavalues", "dmrfid_blastname"),
		array("metavalues", "dmrfid_baddress1"),
		array("metavalues", "dmrfid_baddress2"),
		array("metavalues", "dmrfid_bcity"),
		array("metavalues", "dmrfid_bstate"),
		array("metavalues", "dmrfid_bzipcode"),
		array("metavalues", "dmrfid_bcountry"),
		array("metavalues", "dmrfid_bphone"),
		array("theuser", "membership"),
		array("theuser", "initial_payment"),
		array("theuser", "billing_amount"),
		array("theuser", "cycle_period"),
		array("discount_code", "id"),
		array("discount_code", "code")
		//joindate and enddate are handled specifically below
	);

	//filter
	$default_columns = apply_filters("dmrfid_members_list_csv_default_columns", $default_columns);

	//set the preferred date format:
	$dateformat = apply_filters("dmrfid_memberslist_csv_dateformat","Y-m-d");

	//any extra columns
	$extra_columns = apply_filters("dmrfid_members_list_csv_extra_columns", array());
	if(!empty($extra_columns))
	{
		foreach($extra_columns as $heading => $callback)
		{
			$csv_file_header .= "," . $heading;
		}
	}

	$csv_file_header = apply_filters("dmrfid_members_list_csv_heading", $csv_file_header);
	$csv_file_header .= "\n";

	//generate SQL for list of users to process
	$sqlQuery = "
		SELECT
			DISTINCT u.ID
		FROM $wpdb->users u ";

	if ($s)
		$sqlQuery .= "LEFT JOIN {$wpdb->usermeta} um ON u.ID = um.user_id ";

	$sqlQuery .= "LEFT JOIN {$wpdb->dmrfid_memberships_users} mu ON u.ID = mu.user_id ";
	$sqlQuery .= "LEFT JOIN {$wpdb->dmrfid_membership_levels} m ON mu.membership_id = m.id ";

	$former_members = in_array($l, array( "oldmembers", "expired", "cancelled"));
	$former_member_join = null;

	if($former_members)
	{
		$former_member_join = "LEFT JOIN {$wpdb->dmrfid_memberships_users} mu2 ON u.ID = mu2.user_id AND mu2.status = 'active' ";
		$sqlQuery .= $former_member_join;
	}

	$sqlQuery .= "WHERE mu.membership_id > 0 ";

	// looking for a specific user
	$search = "";
	
	if($s)
	{
		$search = "AND (u.display_name LIKE '%" . esc_sql($s) . "%' OR u.user_login LIKE '%". esc_sql($s) ."%' OR u.user_email LIKE '%". esc_sql($s) ."%' OR um.meta_value LIKE '%". esc_sql($s) ."%') ";
		$sqlQuery .= $search;
	}

	// if ($former_members)
		// $sqlQuery .= "AND mu2.status = 'active' ";

	$filter = null;

	//records where the user is NOT an active member
	//if $l == "oldmembers"
	$filter = ($l == "oldmembers" ? " AND mu.status <> 'active' AND mu2.status IS NULL " : $filter);

	// prepare the status to use in the filter
	//           elseif ($l == "expired")                elseif ($l == "cancelled")
	$f_status = ($l == "expired" ? array( 'expired' ) : ( $l == "cancelled" ? array('cancelled', 'admin_cancelled') : null));

	//records where the user is expired or cancelled
	$filter = ( ($l == "expired" || $l == "cancelled") && is_null($filter)) ? "AND mu.status IN ('" . implode("','", $f_status) . "') AND mu2.status IS NULL " : $filter;

	//records for active users with the requested membership level
	// elseif($l)
	$filter = ( (is_null($filter) && is_numeric($l)) ? " AND mu.status = 'active' AND mu.membership_id = " . esc_sql($l) . " " : $filter);

	//any active users
	// else
	$filter = (is_null($filter) ? " AND mu.status = 'active' " : $filter);

	//add the filter
	$sqlQuery .= $filter;

	//process based on limit value(s).
	$sqlQuery .= "ORDER BY u.ID ";

	if(!empty($limit))
		$sqlQuery .= "LIMIT {$start}, {$limit}";

	/**
	* Filter to change/manipulate the SQL for the list of members export
	* @since v1.9.0    Re-introduced
	*/
	$sqlQuery = apply_filters('dmrfid_members_list_sql', $sqlQuery);

	// Generate a temporary file to store the data in.
	$tmp_dir = sys_get_temp_dir();
	$filename = tempnam( $tmp_dir, 'dmrfid_ml_');

	// open in append mode
	$csv_fh = fopen($filename, 'a');

	//write the CSV header to the file
	fprintf($csv_fh, '%s', $csv_file_header );

	//get users
	$theusers = $wpdb->get_col($sqlQuery);

	//if no records just transmit file with only CSV header as content
	if (empty($theusers)) {

		// send the data to the remote browser
		dmrfid_transmit_content($csv_fh, $filename, $headers);
	}

	$users_found = count($theusers);

	if (DMRFID_BENCHMARK)
	{
		$pre_action_time = microtime(true);
		$pre_action_memory = memory_get_usage(true);
	}

	do_action('dmrfid_before_members_list_csv_export', $theusers);

	$i_start = 0;
	$i_limit = 0;
	$iterations = 1;

	$csvoutput = array();

	if($users_found >= $max_users_per_loop)
	{
		$iterations = ceil($users_found / $max_users_per_loop);
		$i_limit = $max_users_per_loop;
	}

	$end = 0;
	$time_limit = ini_get('max_execution_time');

	if (DMRFID_BENCHMARK)
	{
		error_log("DMRFID_BENCHMARK - Total records to process: {$users_found}");
		error_log("DMRFID_BENCHMARK - Will process {$iterations} iterations of max {$max_users_per_loop} records per iteration.");
		$pre_iteration_time = microtime(true);
		$pre_iteration_memory = memory_get_usage(true);
	}

	//to manage memory footprint, we'll iterate through the membership list multiple times
	for ( $ic = 1 ; $ic <= $iterations ; $ic++ ) {

		if (DMRFID_BENCHMARK)
		{
			$start_iteration_time = microtime(true);
			$start_iteration_memory = memory_get_usage(true);
		}

		//make sure we don't timeout
		if ($end != 0) {

			$iteration_diff = $end - $start;
			$new_time_limit = ceil($iteration_diff*$iterations * 1.2);

			if ($time_limit < $new_time_limit )
			{
				$time_limit = $new_time_limit;
				set_time_limit( $time_limit );
			}
		}

		$start = current_time('timestamp');

		// get first and last user ID to use
		$first_uid = $theusers[$i_start];

		//get last UID, will depend on which iteration we're on.
		if ( $ic != $iterations )
			$last_uid = $theusers[($i_start + ( $max_users_per_loop - 1))];
		else
			// Final iteration, so last UID is the last record in the users array
			$last_uid = $theusers[($users_found - 1)];

		//increment starting position
		$i_start += $max_users_per_loop;
		
		//escape the % for LIKE comparison with $wpdb
		if(!empty($search))
			$search = str_replace('%', '%%', $search);

		$userSql = $wpdb->prepare("
	        SELECT
				DISTINCT u.ID,
				u.user_login,
				u.user_email,
				UNIX_TIMESTAMP(CONVERT_TZ(u.user_registered, '+00:00', @@global.time_zone)) as joindate,
				u.user_login,
				u.user_nicename,
				u.user_url,
				u.user_registered,
				u.user_status,
				u.display_name,
				mu.membership_id,
				mu.initial_payment,
				mu.billing_amount,
				mu.cycle_period,
				UNIX_TIMESTAMP(CONVERT_TZ(max(mu.enddate), '+00:00', @@global.time_zone)) as enddate,
				m.name as membership
			FROM {$wpdb->users} u
			LEFT JOIN {$wpdb->usermeta} um ON u.ID = um.user_id
			LEFT JOIN {$wpdb->dmrfid_memberships_users} mu ON u.ID = mu.user_id
			LEFT JOIN {$wpdb->dmrfid_membership_levels} m ON mu.membership_id = m.id
			{$former_member_join}
			WHERE u.ID BETWEEN %d AND %d AND mu.membership_id > 0 {$filter} {$search}
			GROUP BY u.ID
			ORDER BY u.ID",
				$first_uid,
				$last_uid
		);

		// TODO: Only return the latest record for the user(s) current (and prior) levels IDs?

		$usr_data = $wpdb->get_results($userSql);
		$userSql = null;

		if (DMRFID_BENCHMARK)
		{
			$pre_userdata_time = microtime(true);
			$pre_userdata_memory = memory_get_usage(true);
		}

		// process the actual data we want to export
		foreach($usr_data as $theuser) {

			$csvoutput = array();

			//process usermeta
			$metavalues = new stdClass();

			 // Returns array of meta keys containing array(s) of metavalues.
			$um_values = get_user_meta($theuser->ID);

			foreach( $um_values as $key => $value ) {

				$metavalues->{$key} = isset( $value[0] ) ? $value[0] : null;
			}

			$theuser->metavalues = $metavalues;

			$um_values = null;

			//grab discount code info
			$disSql = $wpdb->prepare("
				SELECT
					c.id,
					c.code
				FROM {$wpdb->dmrfid_discount_codes_uses} cu
				LEFT JOIN $wpdb->dmrfid_discount_codes c ON cu.code_id = c.id
				WHERE cu.user_id = %d
				ORDER BY c.id DESC
				LIMIT 1",
				$theuser->ID
			);

			$discount_code = $wpdb->get_row($disSql);

			//make sure there's data for the discount code info
			if (empty($discount_code))
			{
				$empty_dc = new stdClass();
				$empty_dc->id = '';
				$empty_dc->code = '';
				$discount_code = $empty_dc;
			}

			unset($disSql);

			//default columns
			if(!empty($default_columns))
			{
				$count = 0;
				foreach($default_columns as $col)
				{
					//checking $object->property. note the double $$
					$val = isset(${$col[0]}->{$col[1]}) ? ${$col[0]}->{$col[1]} : null;
					array_push($csvoutput, dmrfid_enclose($val));	//output the value
				}
			}

			//joindate and enddate
			array_push($csvoutput, dmrfid_enclose(date($dateformat, $theuser->joindate)));

			if($theuser->membership_id)
			{
				if($theuser->enddate)
					array_push($csvoutput, dmrfid_enclose(apply_filters("dmrfid_memberslist_expires_column", date_i18n($dateformat, $theuser->enddate), $theuser)));
				else
					array_push($csvoutput, dmrfid_enclose(apply_filters("dmrfid_memberslist_expires_column", "Never", $theuser)));
			}
			elseif($l == "oldmembers" && $theuser->enddate)
			{
				array_push($csvoutput, dmrfid_enclose(date($dateformat, $theuser->enddate)));
			}
			else
				array_push($csvoutput, "N/A");

			//any extra columns
			if(!empty($extra_columns))
			{
				foreach($extra_columns as $heading => $callback)
				{
					$val = call_user_func($callback, $theuser, $heading);
					$val = !empty($val) ? $val : null;
					array_push( $csvoutput, dmrfid_enclose($val) );
				}
			}

			//free memory for user records
			$metavalues = null;
			$discount_code = null;
			$theuser = null;

			// $csvoutput .= "\n";
			$line = implode(',', $csvoutput) . "\n";

			fprintf($csv_fh, "%s", $line);

			//reset
			$line = null;
			$csvoutput = null;
		} // end of foreach usr_data

		if (DMRFID_BENCHMARK)
		{
			$end_of_iteration_time = microtime(true);
			$end_of_iteration_memory = memory_get_usage(true);
		}

		//keep memory consumption low(ish)
		wp_cache_flush();

		if (DMRFID_BENCHMARK)
		{
			$after_flush_time = microtime(true);
			$after_flush_memory = memory_get_usage(true);

			$time_in_iteration = $end_of_iteration_time - $start_iteration_time;
			$time_flushing = $after_flush_time - $end_of_iteration_time;
			$userdata_time = $end_of_iteration_time - $pre_userdata_time;

			list($iteration_sec, $iteration_usec) = explode('.', $time_in_iteration);
			list($udata_sec, $udata_usec) = explode('.', $userdata_time);
			list($flush_sec, $flush_usec) = explode('.', $time_flushing);

			$memory_used = $end_of_iteration_memory - $start_iteration_memory;

			error_log("DMRFID_BENCHMARK - For iteration #{$ic} of {$iterations} - Records processed: " . count($usr_data));
			error_log("DMRFID_BENCHMARK - \tTime processing whole iteration: " . date_i18n("H:i:s", $iteration_sec) . ".{$iteration_sec}");
			error_log("DMRFID_BENCHMARK - \tTime processing user data for iteration: " . date_i18n("H:i:s", $udata_sec) . ".{$udata_sec}");
			error_log("DMRFID_BENCHMARK - \tTime flushing cache: " . date_i18n("H:i:s", $flush_sec) . ".{$flush_usec}");
			error_log("DMRFID_BENCHMARK - \tAdditional memory used during iteration: ".number_format($memory_used, 2, '.', ',') . " bytes");
		}

		//need to increase max running time?
		$end = current_time('timestamp');

	} // end of foreach iteration

	if (DMRFID_BENCHMARK)
	{
		$after_data_time = microtime(true);
		$after_data_memory = memory_get_peak_usage(true);

		$time_processing_data = $after_data_time - $start_time;
		$memory_processing_data = $after_data_memory - $start_memory;

		list($sec, $usec) = explode('.', $time_processing_data);

		error_log("DMRFID_BENCHMARK - Time processing data: {$sec}.{$usec} seconds");
		error_log("DMRFID_BENCHMARK - Peak memory usage: " . number_format($memory_processing_data, false, '.', ',') . " bytes");
	}

	// free memory
	$usr_data = null;

	// send the data to the remote browser
	dmrfid_transmit_content($csv_fh, $filename, $headers);

	exit;

	function dmrfid_enclose($s)
	{
		return "\"" . str_replace("\"", "\\\"", $s) . "\"";
	}

	// responsible for trasnmitting content of file to remote browser
	function dmrfid_transmit_content( $csv_fh, $filename, $headers = array() ) {

		//close the temp file
		fclose($csv_fh);

		if (version_compare(phpversion(), '5.3.0', '>')) {

			//make sure we get the right file size
			clearstatcache( true, $filename );
		} else {
			// for any PHP version prior to v5.3.0
			clearstatcache();
		}

		//did we accidentally send errors/warnings to browser?
		if (headers_sent())
		{
			echo str_repeat('-', 75) . "<br/>\n";
			echo 'Abra un caso de soporte y pegue las advertencias / errores que ve encima de este texto para\n ';
			echo 'el <a href="http://managertechnology.com.co/support/?utm_source=plugin&utm_medium=banner&utm_campaign=memberslist_csv" target="_blank">Foro de soporte RFID para miembros digitales</a><br/>\n';
			echo str_repeat("=", 75) . "<br/>\n";
			echo file_get_contents($filename);
			echo str_repeat("=", 75) . "<br/>\n";
		}

		//transmission
		if (! empty($headers) )
		{
			//set the download size
			$headers[] = "Content-Length: " . filesize($filename);

			//set headers
			foreach($headers as $header)
			{
				header($header . "\r\n");
			}

			// disable compression for the duration of file download
			if(ini_get('zlib.output_compression')){
				ini_set('zlib.output_compression', 'Off');
			}

			if( function_exists( 'fpassthru' ) ) {
				// use fpassthru to output the csv
				$csv_fh = fopen( $filename, 'rb' );
				fpassthru( $csv_fh );
				fclose( $csv_fh );
			} else {
				// use readfile() if fpassthru() is disabled (like on Flywheel Hosted)
				readfile( $filename );
			}

			// remove the temp file
			unlink( $filename );
		}

		//allow user to clean up after themselves
		do_action('dmrfid_after_members_list_csv_export');
		exit;
	}
