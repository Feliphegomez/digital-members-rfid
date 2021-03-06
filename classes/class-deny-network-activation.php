<?php

defined( 'ABSPATH' ) || die( 'File cannot be accessed directly' );

class DmRFID_Deny_Network_Activation {

	public function init() {
		register_activation_hook( DMRFID_BASE_FILE, array( $this, 'dmrfid_check_network_activation' ) );
		add_action( 'wp_print_footer_scripts', array( $this, 'wp_admin_style' ) );
		add_action( 'network_admin_notices', array( $this, 'display_message_after_network_activation_attempt' ) );	
	}

	public function wp_admin_style() {
		global $current_screen;
		if ( is_admin() && ( 'sites-network' === $current_screen->id || 'plugins-network' === $current_screen->id ) ) {
	?>
		<style type="text/css">
			.notice.notice-info {
				background-color: #ffd;
			}
		</style>
	<?php
		}
	}

	public function display_message_after_network_activation_attempt() {
		global $current_screen;
		if ( !empty($_REQUEST['dmrfid_deny_network_activation']) && ( 'sites-network' === $current_screen->id || 'plugins-network' === $current_screen->id ) ) {
				//get plugin data
				$plugin = isset($_REQUEST['dmrfid_deny_network_activation']) ? sanitize_file_name($_REQUEST['dmrfid_deny_network_activation']) : '';
				$plugin_path = WP_PLUGIN_DIR . '/' . urldecode($plugin);
				$plugin_data = get_plugin_data($plugin_path);

				if(!empty($plugin_data))
					$plugin_name = $plugin_data['Name'];
				else
					$plugin_name = '';

				//show notice
				echo '<div class="notice notice-info is-dismissible"><p>';
				$text = sprintf( __("The %s plugin should not be network activated. Activate on each individual site's plugin page.", 'digital-members-rfid'), $plugin_name);
				echo $text;
				echo '</p></div>';
		}
	}

	public function dmrfid_check_network_activation( $network_wide ) {
		if ( !is_multisite() || !$network_wide ) {
			return;
		}

		$plugin = isset($_REQUEST['plugin']) ? sanitize_file_name($_REQUEST['plugin']) : '';

		deactivate_plugins( $plugin, true, true );
		if ( ! isset( $_REQUEST['dmrfid_deny_network_activation']) ) {
			wp_redirect( add_query_arg( 'dmrfid_deny_network_activation', $plugin, network_admin_url( 'plugins.php' ) ) );
			exit;
		}
	}
}

// Init the check if the plugin is active.
if ( class_exists( '\DmRFID_Deny_Network_Activation' ) ) {
	$pmp_wpmu_deny = new DmRFID_Deny_Network_Activation();
	$pmp_wpmu_deny->init();
}
