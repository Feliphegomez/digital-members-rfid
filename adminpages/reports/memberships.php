<?php
/*
	DmRFID Report
	Title: Membership Stats
	Slug: memberships

	For each report, add a line like:
	global $dmrfid_reports;
	$dmrfid_reports['slug'] = 'Title';

	For each report, also write two functions:
	* dmrfid_report_{slug}_widget()   to show up on the report homepage.
	* dmrfid_report_{slug}_page()     to show up when users click on the report page widget.
*/

global $dmrfid_reports;

$dmrfid_reports['memberships'] = __('Membership Stats', 'digital-members-rfid' );

//queue Google Visualization JS on report page
function dmrfid_report_memberships_init() {
	if(is_admin() && isset($_REQUEST['report']) && $_REQUEST['report'] == "memberships" && isset($_REQUEST['page']) && $_REQUEST['page'] == "dmrfid-reports") {
		wp_enqueue_script( 'corechart', plugins_url( 'js/corechart.js',  plugin_dir_path( __DIR__ ) ) );
	}
}
add_action( 'init', 'dmrfid_report_memberships_init' );


//widget
function dmrfid_report_memberships_widget() {
	global $wpdb;

	//get levels to show stats on first 3
	$dmrfid_levels = dmrfid_getAllLevels(true, true);

	$dmrfid_level_order = dmrfid_getOption('level_order');

	if(!empty($dmrfid_level_order))
	{
		$order = explode(',',$dmrfid_level_order);

		//reorder array
		$reordered_levels = array();
		foreach($order as $level_id) {
			foreach($dmrfid_levels as $key=>$level) {
				if($level_id == $level->id)
					$reordered_levels[$key] = $dmrfid_levels[$key];
			}
		}

		$dmrfid_levels = $reordered_levels;
	}

	$dmrfid_levels = apply_filters( 'dmrfid_report_levels', $dmrfid_levels );
?>
<span id="dmrfid_report_memberships" class="dmrfid_report-holder">
	<table class="wp-list-table widefat fixed striped">
	<thead>
		<tr>
			<th scope="col">&nbsp;</th>
			<th scope="col"><?php _e('Signups', 'digital-members-rfid' ); ?></th>
			<th scope="col"><?php _e('All Cancellations', 'digital-members-rfid' ); ?></th>
		</tr>
	</thead>
	<?php
		$reports = array(
			'today'=> __('Today', 'digital-members-rfid' ),
			'this month'=> __('This Month', 'digital-members-rfid' ),
			'this year'=> __('This Year', 'digital-members-rfid' ),
			'all time'=> __('All Time', 'digital-members-rfid' ),
		);

		foreach( $reports as $report_type => $report_name ) {
			$signups = number_format_i18n( dmrfid_getSignups( $report_type ) );
			$cancellations = number_format_i18n( dmrfid_getCancellations( $report_type) );
		?>
		<tbody>
			<tr class="dmrfid_report_tr">
				<th scope="row">
					<?php if ( empty( $signups ) && empty( $cancellations) ) { ?>
						<?php echo esc_html($report_name); ?>
					<?php } else { ?>
						<button class="dmrfid_report_th dmrfid_report_th_closed">
							<?php echo esc_html($report_name); ?>
						</button>
					<?php } ?>
				</th>
				<td><?php echo esc_html($signups); ?></td>
				<td><?php echo esc_html($cancellations); ?></td>
			</tr>
			<?php
				//level stats
				$count = 0;
				$max_level_count = apply_filters( 'dmrfid_admin_reports_included_levels', 3 );

				foreach($dmrfid_levels as $level) {
					if($count++ >= $max_level_count) break;
			?>
				<tr class="dmrfid_report_tr_sub" style="display: none;">
					<th scope="row">- <?php echo esc_html($level->name);?></th>
					<td><?php echo esc_html(number_format_i18n(dmrfid_getSignups($report_type, $level->id))); ?></td>
					<td><?php echo esc_html(number_format_i18n(dmrfid_getCancellations($report_type, $level->id))); ?></td>
				</tr>
			<?php
				}
			?>
		</tbody>
		<?php
		}
	?>
	</table>
	<?php if ( function_exists( 'dmrfid_report_memberships_page' ) ) { ?>
		<p class="dmrfid_report-button">
			<a class="button button-primary" href="<?php echo esc_url(admin_url( 'admin.php?page=dmrfid-reports&report=memberships')); ?>"><?php _e('Details', 'digital-members-rfid' );?></a>
		</p>
	<?php } ?>
</span>
<script>
	jQuery(document).ready(function() {
		jQuery('.dmrfid_report_th ').click(function(event) {
			//prevent form submit onclick
			event.preventDefault();

			//toggle sub rows
			jQuery(this).closest('tbody').find('.dmrfid_report_tr_sub').toggle();

			//change arrow
			if(jQuery(this).hasClass('dmrfid_report_th_closed')) {
				jQuery(this).removeClass('dmrfid_report_th_closed');
				jQuery(this).addClass('dmrfid_report_th_opened');
			} else {
				jQuery(this).removeClass('dmrfid_report_th_opened');
				jQuery(this).addClass('dmrfid_report_th_closed');
			}
		});
	});
</script>
<?php
}

function dmrfid_report_memberships_page()
{
	global $wpdb, $dmrfid_currency_symbol;

	//get values from form
	if(isset($_REQUEST['type']))
		$type = sanitize_text_field($_REQUEST['type']);
	else
		$type = "signup_v_all";

	if(isset($_REQUEST['period']))
		$period = sanitize_text_field($_REQUEST['period']);
	else
		$period = "monthly";

	if(isset($_REQUEST['month']))
		$month = intval($_REQUEST['month']);
	else
		$month = date_i18n("n");

	$thisyear = date_i18n("Y");
	if(isset($_REQUEST['year']))
		$year = intval($_REQUEST['year']);
	else
		$year = date_i18n("Y");

	if(isset($_REQUEST['level'])) {
		if( $_REQUEST['level'] == 'paid-levels' ) {
			$l = dmrfid_report_get_levels( 'paid' );
		}elseif( $_REQUEST['level'] == 'free-levels' ) {
			$l = dmrfid_report_get_levels( 'free' );
		}else{
			$l = intval($_REQUEST['level']);
		}
	} else {
		$l = "";
	}

	if ( isset( $_REQUEST[ 'discount_code' ] ) ) {
		$discount_code = intval( $_REQUEST[ 'discount_code' ] );
	} else {
		$discount_code = '';
	}

	//calculate start date and how to group dates returned from DB
	if($period == "daily")
	{
		$startdate = $year . '-' . substr("0" . $month, strlen($month) - 1, 2) . '-01';
		$enddate = $year . '-' . substr("0" . $month, strlen($month) - 1, 2) . '-31';
		$date_function = 'DAY';
	}
	elseif($period == "monthly")
	{
		$startdate = $year . '-01-01';
		$enddate = strval(intval($year)+1) . '-01-01';
		$date_function = 'MONTH';
	}
	elseif($period == "annual")
	{
		$startdate = '1970-01-01';	//all time
		$enddate = strval(intval($year)+1) . '-01-01';
		$date_function = 'YEAR';
	}

	//testing or live data
	$gateway_environment = dmrfid_getOption("gateway_environment");

	//get data
	if (
		$type === "signup_v_cancel" ||
		$type === "signup_v_expiration" ||
		$type === "signup_v_all"
	) {
		$sqlQuery = "SELECT $date_function(mu.startdate) as date, COUNT(DISTINCT mu.user_id) as signups
		FROM $wpdb->dmrfid_memberships_users mu ";

		if ( ! empty( $discount_code ) ) {
			$sqlQuery .= "LEFT JOIN $wpdb->dmrfid_discount_codes_uses dc ON mu.user_id = dc.user_id ";
		}

		$sqlQuery .= "WHERE mu.startdate >= '" . esc_sql( $startdate ) . "' ";

		if ( ! empty( $enddate ) ) {
			$sqlQuery .= "AND mu.startdate <= '" . esc_sql( $enddate ) . "' ";
		}
	}

	if ( ! empty( $l ) ) {
		$sqlQuery .= "AND mu.membership_id IN(" . esc_sql( $l ) . ") ";
	}

	if ( ! empty( $discount_code ) ) {
		$sqlQuery .= "AND dc.code_id = '" . esc_sql( $discount_code ) . "' ";
	}

	$sqlQuery .= " GROUP BY date ORDER BY date ";

	$dates = $wpdb->get_results($sqlQuery);

	//fill in blanks in dates
	$cols = array();
	if($period == "daily")
	{
		$lastday = date_i18n("t", strtotime($startdate, current_time("timestamp")));

		for($i = 1; $i <= $lastday; $i++)
		{
			// Signups vs. Cancellations, Expirations, or All
			if ( $type === "signup_v_cancel" || $type === "signup_v_expiration" || $type === "signup_v_all" ) {
				$cols[$i] = new stdClass();
				$cols[$i]->signups = 0;
				foreach($dates as $day => $date)
				{
					if( $date->date == $i ) {
						$cols[$i]->signups = $date->signups;
					}
				}
			}
		}
	}
	elseif($period == "monthly")
	{
		for($i = 1; $i < 13; $i++)
		{
			// Signups vs. Cancellations, Expirations, or All
			if ( $type === "signup_v_cancel" || $type === "signup_v_expiration" || $type === "signup_v_all" ) {
				$cols[$i] = new stdClass();
				$cols[$i]->date = $i;
				$cols[$i]->signups = 0;
				foreach($dates as $date)
				{
					if( $date->date == $i ) {
						$cols[$i]->date = $date->date;
						$cols[$i]->signups = $date->signups;
					}
				}
			}
		}
	}
	elseif($period == "annual") //annual
	{
	}

	$dates = ( ! empty( $cols ) ) ? $cols : $dates;

	// Signups vs. all
	if ( $type === "signup_v_cancel" || $type === "signup_v_expiration" || $type === "signup_v_all" )
	{
		$sqlQuery = "SELECT $date_function(mu1.modified) as date, COUNT(DISTINCT mu1.user_id) as cancellations
		FROM $wpdb->dmrfid_memberships_users mu1 ";

		//restrict by discount code
		if ( ! empty( $discount_code ) ) {
			$sqlQuery .= "LEFT JOIN $wpdb->dmrfid_discount_codes_uses dc ON mu1.user_id = dc.user_id ";
		}

		if ( $type === "signup_v_cancel")
			$sqlQuery .= "WHERE mu1.status IN('inactive','cancelled','admin_cancelled') ";
		elseif($type === "signup_v_expiration")
			$sqlQuery .= "WHERE mu1.status IN('expired') ";
		else
			$sqlQuery .= "WHERE mu1.status IN('inactive','expired','cancelled','admin_cancelled') ";

		$sqlQuery .= "AND mu1.enddate >= '" . esc_sql( $startdate ) . "'
		AND mu1.enddate < '" . esc_sql( $enddate ) . "' ";

		//restrict by level
		if ( ! empty( $l ) ) {
			$sqlQuery .= "AND mu1.membership_id IN(" . esc_sql( $l ) . ") ";
		}

		if ( ! empty( $discount_code ) ) {
			$sqlQuery .= "AND dc.code_id = '" . esc_sql( $discount_code ) . "' ";
		}

		$sqlQuery .= " GROUP BY date ORDER BY date ";

		/**
		 * Filter query to get cancellation numbers in signups vs cancellations detailed report.
		 *
		 * @since 1.8.8
		 *
		 * @param string $sqlQuery The current SQL
		 * @param string $type report type
		 * @param string $startdate Start Date in YYYY-MM-DD format
		 * @param string $enddate End Date in YYYY-MM-DD format
		 * @param int $l Level ID
		 */
		$sqlQuery = apply_filters('dmrfid_reports_signups_sql', $sqlQuery, $type, $startdate, $enddate, $l);

		$cdates = $wpdb->get_results($sqlQuery, OBJECT_K);

		foreach( $dates as $day => &$date )
		{
			if(!empty($cdates) && !empty($cdates[$day]))
				$date->cancellations = $cdates[$day]->cancellations;
			else
				$date->cancellations = 0;
		}
	}

	?>
	<form id="posts-filter" method="get" action="">
	<h1>
		<?php _e('Membership Stats', 'digital-members-rfid' );?>
	</h1>
	<ul class="subsubsub">
		<li>
			<?php _e('Show', 'digital-members-rfid' )?>
			<select id="period" name="period">
				<option value="daily" <?php selected($period, "daily");?>><?php _e('Daily', 'digital-members-rfid' );?></option>
				<option value="monthly" <?php selected($period, "monthly");?>><?php _e('Monthly', 'digital-members-rfid' );?></option>
				<option value="annual" <?php selected($period, "annual");?>><?php _e('Annual', 'digital-members-rfid' );?></option>
			</select>
			<select id="type" name="type">
				<option value="signup_v_all" <?php selected($type, "signup_v_all");?>><?php _e('Signups vs. All Cancellations', 'digital-members-rfid' );?></option>
				<option value="signup_v_cancel" <?php selected($type, "signup_v_cancel");?>><?php _e('Signups vs. Cancellations', 'digital-members-rfid' );?></option>
				<option value="signup_v_expiration" <?php selected($type, "signup_v_expiration");?>><?php _e('Signups vs. Expirations', 'digital-members-rfid' );?></option>
			</select>
			<span id="for"><?php _e('for', 'digital-members-rfid' )?></span>
			<select id="month" name="month">
				<?php for($i = 1; $i < 13; $i++) { ?>
					<option value="<?php echo esc_attr($i);?>" <?php selected($month, $i);?>><?php echo esc_html(date_i18n("F", mktime(0, 0, 0, $i, 2)));?></option>
				<?php } ?>
			</select>
			<select id="year" name="year">
				<?php for($i = $thisyear; $i > 2007; $i--) { ?>
					<option value="<?php echo esc_attr($i);?>" <?php selected($year, $i);?>><?php echo esc_html($i);?></option>
				<?php } ?>
			</select>
			<span id="for"><?php _e('for', 'digital-members-rfid' )?></span>
			<select name="level">
				<option value="" <?php if(!$l) { ?>selected="selected"<?php } ?>><?php _e('All Levels', 'digital-members-rfid' );?></option>
				<option value="paid-levels" <?php if(isset($_REQUEST['level']) && $_REQUEST['level'] == "paid-levels"){?> selected="selected" <?php }?>><?php _e( 'All Paid Levels', 'digital-members-rfid' ); ?></option>
				<option value="free-levels" <?php if(isset($_REQUEST['level']) && $_REQUEST['level'] == "free-levels"){?> selected="selected" <?php }?>><?php _e( 'All Free Levels', 'digital-members-rfid' ); ?></option>
				<?php
					$levels = $wpdb->get_results("SELECT id, name FROM $wpdb->dmrfid_membership_levels ORDER BY name");
					foreach($levels as $level)
					{
				?>
					<option value="<?php echo esc_attr($level->id)?>" <?php if($l == $level->id) { ?>selected="selected"<?php } ?>><?php echo esc_html($level->name);?></option>
				<?php
					}

				?>

			</select>
			<?php
			$sqlQuery = "SELECT SQL_CALC_FOUND_ROWS * FROM $wpdb->dmrfid_discount_codes ";
			$sqlQuery .= "ORDER BY id DESC ";
			$codes = $wpdb->get_results($sqlQuery, OBJECT);
			if ( ! empty( $codes ) ) { ?>
			<select id="discount_code" name="discount_code">
				<option value="" <?php if ( empty( $discount_code ) ) { ?>selected="selected"<?php } ?>><?php _e('All Codes', 'digital-members-rfid' );?></option>
				<?php foreach ( $codes as $code ) { ?>
					<option value="<?php echo esc_attr($code->id); ?>" <?php selected( $discount_code, $code->id ); ?>><?php echo esc_html($code->code); ?></option>
				<?php } ?>
			</select>
			<?php } ?>
			<input type="hidden" name="page" value="dmrfid-reports" />
			<input type="hidden" name="report" value="memberships" />
			<input type="submit" class="button" value="<?php esc_attr_e('Generate Report', 'digital-members-rfid' );?>" />
		</li>
	</ul>

	<div id="chart_div" style="clear: both; width: 100%; height: 500px;"></div>

	<script>
		//update month/year when period dropdown is changed
		jQuery(document).ready(function() {
			jQuery('#period').change(function() {
				dmrfid_ShowMonthOrYear();
			});
		});

		function dmrfid_ShowMonthOrYear()
		{
			var period = jQuery('#period').val();
			if(period == 'daily')
			{
				jQuery('#for').show();
				jQuery('#month').show();
				jQuery('#year').show();
			}
			else if(period == 'monthly')
			{
				jQuery('#for').show();
				jQuery('#month').hide();
				jQuery('#year').show();
			}
			else
			{
				jQuery('#for').hide();
				jQuery('#month').hide();
				jQuery('#year').hide();
			}
		}

		dmrfid_ShowMonthOrYear();

		//draw the chart
		google.charts.load('current', {'packages':['corechart']});
		google.charts.setOnLoadCallback(drawVisualization);
		function drawVisualization() {

			var data = google.visualization.arrayToDataTable([
			<?php if ( $type === "signup_v_all" ) : // Signups vs. all cancellations ?>
			  ['<?php echo esc_html($date_function);?>', 'Signups', 'All Cancellations'],
			  <?php foreach($dates as $key => $value) { ?>
				['<?php if($period == "monthly") echo esc_html(date_i18n("M", mktime(0,0,0,$value->date,2))); else if($period == "daily") echo esc_html($key); else echo esc_html($value->date);?>', <?php echo esc_html($value->signups); ?>, <?php echo esc_html($value->cancellations); ?>],
			  <?php } ?>
			<?php endif; ?>

			<?php if ( $type === "signup_v_cancel" ) : // Signups vs. cancellations ?>
			  ['<?php echo esc_html($date_function);?>', 'Signups', 'Cancellations'],
			  <?php foreach($dates as $key => $value) { ?>
				['<?php if($period == "monthly") echo esc_html(date_i18n("M", mktime(0,0,0,$value->date,2))); else if($period == "daily") echo esc_html($key); else echo esc_html($value->date);?>', <?php echo esc_html($value->signups); ?>, <?php echo esc_html($value->cancellations); ?>],
			  <?php } ?>
			<?php endif; ?>

			<?php if ( $type === "signup_v_expiration" ) : // Signups vs. expirations ?>
			  ['<?php echo esc_html($date_function);?>', 'Signups', 'Expirations'],
			  <?php foreach($dates as $key => $value) { ?>
				['<?php if($period == "monthly") echo esc_html(date_i18n("M", mktime(0,0,0,$value->date,2))); else if($period == "daily") echo esc_html($key); else echo esc_html($value->date);?>', <?php echo esc_html($value->signups); ?>, <?php echo esc_html($value->cancellations); ?>],
			  <?php } ?>
			<?php endif; ?>

			]);

			var options = {
			  colors: ['#0099c6', '#dc3912'],
			  chartArea: {width: '90%'},
			  legend: {
				  alignment: 'center',
				  position: 'top',
				  textStyle: {color: '#555555', fontSize: '12', italic: false}
			  },
			  hAxis: {
				  title: '<?php echo esc_html($date_function);?>',
				  textStyle: {color: '#555555', fontSize: '12', italic: false},
				  titleTextStyle: {color: '#555555', fontSize: '20', bold: true, italic: false},
				  maxAlternation: 1
			  },
			  vAxis: {
				  format: '0',
				  textStyle: {color: '#555555', fontSize: '12', italic: false},
			  },
			  seriesType: 'bars',
			};

			<?php if ( $type === "signup_v_cancel" || $type === "signup_v_expiration" || $type === "signup_v_all" ) : // Signups vs. cancellations ?>
				var chart = new google.visualization.ColumnChart(document.getElementById('chart_div'));
			<?php endif; ?>
			chart.draw(data, options);
		}
	</script>

	</form>
	<?php
}



/*
	Other code required for your reports. This file is loaded every time WP loads with DmRFID enabled.
*/

//get signups
function dmrfid_getSignups($period = false, $levels = 'all')
{
	//check for a transient
	$cache = get_transient( 'dmrfid_report_memberships_signups' );
	if( ! empty( $cache ) && ! empty( $cache[$period] ) && ! empty( $cache[$period][$levels] ) )
		return $cache[$period][$levels];

	//a sale is an order with status = success
	if( $period == 'today' )
		$startdate = date_i18n(' Y-m-d' );
	elseif( $period == 'this month')
		$startdate = date_i18n( 'Y-m' ) . '-01';
	elseif( $period == 'this year')
		$startdate = date_i18n( 'Y' ) . '-01-01';
	else
		$startdate = '1970-01-01';


	//build query
	global $wpdb;

	$sqlQuery = "SELECT COUNT(DISTINCT mu.user_id) FROM $wpdb->dmrfid_memberships_users mu WHERE mu.startdate >= '" . esc_sql( $startdate ) . "' ";

	//restrict by level
	if(!empty($levels) && $levels != 'all')
		$sqlQuery .= "AND mu.membership_id IN(" . esc_sql( $levels ) . ") ";

	$signups = $wpdb->get_var($sqlQuery);

	//save in cache
	if(!empty($cache) && !empty($cache[$period]))
		$cache[$period][$levels] = $signups;
	elseif(!empty($cache))
		$cache[$period] = array($levels => $signups);
	else
		$cache = array($period => array($levels => $signups));

	set_transient("dmrfid_report_memberships_signups", $cache, 3600*24);

	return $signups;
}

//
/**
 * get cancellations by status
 *
 * @param string $period - Either a string description ('today', 'this month', 'this year')
 * @param array(int)|string $levels - Either an array of level IDs or the string 'all'
 * @param array(string) $status - Array of statuses to fetch data for
 * @return null|int - The # of cancellations for the period specified
 */
function dmrfid_getCancellations($period = null, $levels = 'all', $status = array('inactive','expired','cancelled','admin_cancelled') )
{
	//make sure status is an array
	if(!is_array($status))
		$status = array($status);

	//check for a transient
	$cache = get_transient( 'dmrfid_report_memberships_cancellations' );
	$hash = md5($period . $levels . implode(',', $status));
	if( ! empty( $cache ) && ! empty( $cache[$hash] ) )
		return $cache[$hash];

	//figure out start date
	$now = current_time('timestamp');
	$year = date("Y", $now);

	if( $period == 'today' )
	{
		$startdate = date('Y-m-d', $now) . " 00:00:00";
		$enddate = "'" . date('Y-m-d', $now) . " 23:59:59'";
	}
	elseif( $period == 'this month')
	{
		$startdate = date( 'Y-m', $now ) . '-01 00:00:00';
		$enddate = "CONCAT(LAST_DAY('" . date_i18n( 'Y-m', $now ) . '-01' ."'), ' 23:59:59')";
	}
	elseif( $period == 'this year')
	{
		$startdate = date( 'Y', $now ) . '-01-01 00:00:00';
		$enddate = "'" . date( 'Y', $now ) . "-12-31 23:59:59'";
	}
	else
	{
		//all time
		$startdate = '1970-01-01';	//all time (no point in using a value prior to the start of the UNIX epoch)
		$enddate = "'".strval(intval($year)+1) . "-01-01'";
	}

	/*
		build query.
		cancellations are marked in the memberships users table with status 'inactive', 'expired', 'cancelled', 'admin_cancelled'
		we try to ignore cancellations when the user gets a new level with 24 hours (probably an upgrade or downgrade)
	*/
	global $wpdb;

	// Note here that we no longer esc_sql the $startdate and $enddate
	// Escaping broke the MYSQL we passed in.
	// We generated these vars and can trust them.
    $sqlQuery = "
		SELECT COUNT( DISTINCT mu1.user_id )
		FROM {$wpdb->dmrfid_memberships_users} AS mu1
		WHERE mu1.status IN('" . implode( "','", array_map( 'esc_sql', $status ) ) . "')
			AND mu1.enddate >= '" . $startdate . "'
			AND mu1.enddate <= " . $enddate  . "
		";

	//restrict by level
	if(!empty($levels) && $levels != 'all') {

		// the levels provided wasn't in array form
		if ( ! is_array($levels) ) {

			$levels = array($levels);
		}

		$sqlQuery .= "AND mu1.membership_id IN(" . implode( ',', array_map( 'esc_sql', $levels ) ) . ") ";
	}

	/**
	 * Filter query to get cancellation numbers in signups vs cancellations detailed report.
	 *
	 * @since 1.8.8
	 *
	 * @param string $sqlQuery The current SQL
	 * @param string $period Period for report. today, this month, this year, empty string for all time.
	 * @param array(int) $levels Level IDs to include in report.
	 * @param array(string) $status Statuses to include as cancelled.
	 */
	$sqlQuery = apply_filters('dmrfid_reports_get_cancellations_sql', $sqlQuery, $period, $levels, $status);

	$cancellations = $wpdb->get_var($sqlQuery);

	//save in cache
	if(!empty($cache) && !empty($cache[$hash]))
		$cache[$hash] = $cancellations;
	elseif(!empty($cache))
		$cache[$hash] = $cancellations;
	else
		$cache = array($hash => $cancellations);

	set_transient("dmrfid_report_memberships_cancellations", $cache, 3600*24);

	return $cancellations;
}

//get Cancellation Rate
function dmrfid_getCancellationRate($period, $levels = 'all', $status = NULL)
{
	//make sure status is an array
	if(!is_array($status))
		$status = array($status);

	//check for a transient
	$cache = get_transient("dmrfid_report_cancellation_rate");
	$hash = md5($period . $levels . implode('',$status));
	if(!empty($cache) && !empty($cache[$hash]))
		return $cache[$hash];

	$signups = dmrfid_getSignups($period, $levels);
	$cancellations = dmrfid_getCancellations($period, $levels, $status);

	if(empty($signups))
		return false;

	$rate = number_format(($cancellations / $signups)*100, 2);

	//save in cache
	if(!empty($cache) && !empty($cache[$period]))
		$cache[$period][$levels] = $rate;
	elseif(!empty($cache))
		$cache[$period] = array($levels => $rate);
	else
		$cache = array($period => array($levels => $rate));

	set_transient("dmrfid_report_cancellation_rate", $cache, 3600*24);

	return $rate;
}

//delete transients when an order goes through
function dmrfid_report_memberships_delete_transients()
{
	delete_transient("dmrfid_report_cancellation_rate");
	delete_transient("dmrfid_report_memberships_cancellations");
	delete_transient("dmrfid_report_memberships_signups");
}
add_action("dmrfid_updated_order", "dmrfid_report_memberships_delete_transients");
add_action("dmrfid_after_checkout", "dmrfid_report_memberships_delete_transients");
add_action("dmrfid_after_change_membership_level", "dmrfid_report_memberships_delete_transients");


/**
 * Creates an array of membership level ID's for querying.
 * @param $type string type of membership level you want to retrieve "free" or "paid".
 * @since 2.0
 */
function dmrfid_report_get_levels( $type = NULL ) {

	if ( empty( $type ) ) {
		return;
	}

	$level_data = dmrfid_getAllLevels( true, true );
	$r = array();


	foreach( $level_data as $key => $value ) {
		if ( $type === 'free' && dmrfid_isLevelFree( $value ) ) {
			$r[] = intval( $value->id);
		} elseif( $type === 'paid' && !dmrfid_isLevelFree( $value ) ) {
			$r[] = intval( $value->id );
		}
	}

	// implode it before returning it.
	$r = implode( ',', $r );

	return $r;
}
