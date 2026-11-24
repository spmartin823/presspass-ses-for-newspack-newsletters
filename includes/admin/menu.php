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
		__( 'Subscribers', 'presspass-ses-for-newspack-newsletters' ),
		__( 'Subscribers', 'presspass-ses-for-newspack-newsletters' ),
		'edit_others_posts',
		'presspass-ses-subscribers',
		'presspass_ses_render_subscribers_page'
	);
	add_submenu_page(
		'edit.php?post_type=newspack_nl_cpt',
		__( 'Lists', 'presspass-ses-for-newspack-newsletters' ),
		__( 'Lists', 'presspass-ses-for-newspack-newsletters' ),
		'edit_others_posts',
		'presspass-ses-lists',
		'presspass_ses_render_lists_page'
	);
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
