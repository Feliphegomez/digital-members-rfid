<?php
/**
 * Enqueues blocks in editor and dynamic blocks
 *
 * @package blocks
 */
defined( 'ABSPATH' ) || die( 'File cannot be accessed directly' );

/**
 * Dynamic Block Requires
 */
require_once( 'checkout-button/block.php' );
require_once( 'account-page/block.php' );
require_once( 'account-membership-section/block.php' );
require_once( 'account-profile-section/block.php' );
require_once( 'account-invoices-section/block.php' );
require_once( 'account-links-section/block.php' );
require_once( 'billing-page/block.php' );
require_once( 'cancel-page/block.php' );
require_once( 'checkout-page/block.php' );
require_once( 'confirmation-page/block.php' );
require_once( 'invoice-page/block.php' );
require_once( 'levels-page/block.php' );
require_once( 'membership/block.php' );
require_once( 'member-profile-edit/block.php' );
require_once( 'login/block.php' );

/**
 * Add DmRFID block category
 */
function dmrfid_place_blocks_in_panel( $categories, $post ) {
	return array_merge(
		$categories,
		array(
			array(
				'slug'  => 'dmrfid',
				'title' => __( 'Digital Members RFID', 'digital-members-rfid' ),
			),
		)
	);
}
add_filter( 'block_categories', 'dmrfid_place_blocks_in_panel', 10, 2 );

/**
 * Enqueue block editor only JavaScript and CSS
 */
function dmrfid_block_editor_scripts() {
	// Enqueue the bundled block JS file.
	wp_enqueue_script(
		'dmrfid-blocks-editor-js',
		plugins_url( 'js/blocks.build.js', DMRFID_BASE_FILE ),
		array('wp-i18n', 'wp-element', 'wp-blocks', 'wp-components', 'wp-api', 'wp-editor', 'dmrfid_admin'),
		DMRFID_VERSION
	);

	// Enqueue optional editor only styles.
	wp_enqueue_style(
		'dmrfid-blocks-editor-css',
		plugins_url( 'css/blocks.editor.css', DMRFID_BASE_FILE ),
		array(),
		DMRFID_VERSION
	);

	// Adding translation functionality to Gutenberg blocks/JS.
	if ( function_exists( 'wp_set_script_translations' ) ) {
		wp_set_script_translations( 'dmrfid-blocks-editor-js', 'digital-members-rfid' );
	}
}
add_action( 'enqueue_block_editor_assets', 'dmrfid_block_editor_scripts' );
