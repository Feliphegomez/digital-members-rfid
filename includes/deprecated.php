<?php
/**
 * Deprecated hooks, filters and functions
 *
 * @since  2.0
 */

/**
 * Check for deprecated filters.
 */
function dmrfid_init_check_for_deprecated_filters() {
	global $wp_filter;
	
	$dmrfid_map_deprecated_filters = array(
		'dmrfid_getfile_extension_blocklist'    => 'dmrfid_getfile_extension_blacklist',
	);
	
	foreach ( $dmrfid_map_deprecated_filters as $new => $old ) {
		if ( has_filter( $old ) ) {
			/* translators: 1: the old hook name, 2: the new or replacement hook name */
			trigger_error( sprintf( esc_html__( 'El gancho %1$s ha quedado obsoleto en RFID para miembros digitales. En su lugar, utilice el gancho %2$s.', 'digital-members-rfid' ), $old, $new ) );
			
			// Add filters back using the new tag.
			foreach( $wp_filter[$old]->callbacks as $priority => $callbacks ) {
				foreach( $callbacks as $callback ) {
					add_filter( $new, $callback['function'], $priority, $callback['accepted_args'] ); 
				}
			}
		}
	}
}
add_action( 'init', 'dmrfid_init_check_for_deprecated_filters', 99 );

/**
 * Previously used function for class definitions for input fields to see if there was an error.
 *
 * To filter field values, we now recommend using the `dmrfid_element_class` filter.
 *
 */
function dmrfid_getClassForField( $field ) {
	dmrfid_get_element_class( '', $field );
}

/**
 * Redirect some old menu items to their new location
 */
function dmrfid_admin_init_redirect_old_menu_items() {	
	if ( is_admin()
		&& ! empty( $_REQUEST['page'] ) && $_REQUEST['page'] == 'dmrfid_license_settings'
		&& basename( $_SERVER['SCRIPT_NAME'] ) == 'options-general.php' ) {
		wp_safe_redirect( admin_url( 'admin.php?page=dmrfid-license' ) );
		exit;
	}
}
add_action( 'init', 'dmrfid_admin_init_redirect_old_menu_items' );