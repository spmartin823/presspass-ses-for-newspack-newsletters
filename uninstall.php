<?php
/**
 * Uninstall. The subscriber, list and queue tables and the link secret are kept on purpose.
 *
 * @package PressPass_SES
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'presspass_ses_settings' );
delete_option( 'presspass_ses_last_error' );
wp_clear_scheduled_hook( 'presspass_ses_run_queue' );
