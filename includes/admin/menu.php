<?php
/**
 * Admin menu.
 *
 * @package PressPass_SES
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function presspass_ses_register_menu(): void {
	add_submenu_page(
		'edit.php?post_type=newspack_nl_cpt',
		__( 'SES Settings', 'presspass-ses-for-newspack-newsletters' ),
		__( 'SES Settings', 'presspass-ses-for-newspack-newsletters' ),
		'manage_options',
		'presspass-ses-settings',
		'presspass_ses_render_settings_page'
	);
}
add_action( 'admin_menu', 'presspass_ses_register_menu', 20 );
