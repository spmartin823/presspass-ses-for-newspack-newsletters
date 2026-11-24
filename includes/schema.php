<?php
/**
 * Database schema.
 *
 * @package PressPass_SES
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function presspass_ses_table( string $name ): string {
	global $wpdb;
	return $wpdb->prefix . 'presspass_ses_' . $name;
}

function presspass_ses_install_schema(): void {
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	$charset_collate  = $wpdb->get_charset_collate();
	$subscribers      = presspass_ses_table( 'subscribers' );
	$lists            = presspass_ses_table( 'lists' );
	$list_subscribers = presspass_ses_table( 'list_subscribers' );
	$queue            = presspass_ses_table( 'queue' );

	dbDelta(
		"CREATE TABLE {$subscribers} (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		email varchar(255) NOT NULL,
		first_name varchar(255) NOT NULL,
		last_name varchar(255) NOT NULL,
		status varchar(20) NOT NULL,
		source varchar(20) NOT NULL,
		mailpoet_id bigint(20) unsigned NULL,
		created_at datetime NOT NULL,
		updated_at datetime NOT NULL,
		PRIMARY KEY  (id),
		UNIQUE KEY email (email),
		UNIQUE KEY mailpoet_id (mailpoet_id),
		KEY status (status)
		) {$charset_collate};"
	);

	dbDelta(
		"CREATE TABLE {$lists} (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		name varchar(255) NOT NULL,
		description text NOT NULL,
		mailpoet_segment_id bigint(20) unsigned NULL,
		created_at datetime NOT NULL,
		PRIMARY KEY  (id),
		UNIQUE KEY mailpoet_segment_id (mailpoet_segment_id)
		) {$charset_collate};"
	);

	dbDelta(
		"CREATE TABLE {$list_subscribers} (
		list_id bigint(20) unsigned NOT NULL,
		subscriber_id bigint(20) unsigned NOT NULL,
		PRIMARY KEY  (list_id,subscriber_id),
		KEY subscriber_id (subscriber_id)
		) {$charset_collate};"
	);

	dbDelta(
		"CREATE TABLE {$queue} (
		newsletter_id bigint(20) unsigned NOT NULL,
		subscriber_id bigint(20) unsigned NOT NULL,
		sent_at datetime NULL,
		error text NULL,
		PRIMARY KEY  (newsletter_id,subscriber_id),
		KEY sent_at (sent_at)
		) {$charset_collate};"
	);

	update_option( 'presspass_ses_db_version', PRESSPASS_SES_DB_VERSION );
}

function presspass_ses_maybe_upgrade_schema(): void {
	if ( get_option( 'presspass_ses_db_version' ) !== PRESSPASS_SES_DB_VERSION ) {
		presspass_ses_install_schema();
	}
}
add_action( 'plugins_loaded', 'presspass_ses_maybe_upgrade_schema' );
