<?php
/**
 * List storage.
 *
 * @package PressPass_SES
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- these are our own tables, there is no WordPress API for them.

function presspass_ses_get_lists(): array {
	global $wpdb;
	return $wpdb->get_results(
		$wpdb->prepare(
			'SELECT l.id, l.name, l.description, l.mailpoet_segment_id, COUNT(s.id) AS subscribed_count
			FROM %i l
			LEFT JOIN %i ls ON ls.list_id = l.id
			LEFT JOIN %i s ON s.id = ls.subscriber_id AND s.status = %s
			GROUP BY l.id
			ORDER BY l.name ASC',
			presspass_ses_table( 'lists' ),
			presspass_ses_table( 'list_subscribers' ),
			presspass_ses_table( 'subscribers' ),
			PressPass_SES_Status::SUBSCRIBED
		)
	);
}

function presspass_ses_get_list( int $id ): ?object {
	global $wpdb;
	return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', presspass_ses_table( 'lists' ), $id ) );
}

function presspass_ses_create_list( string $name, string $description ): int {
	global $wpdb;
	$wpdb->insert(
		presspass_ses_table( 'lists' ),
		array(
			'name'        => $name,
			'description' => $description,
			'created_at'  => current_time( 'mysql', true ),
		)
	);
	$list_id = $wpdb->insert_id;
	Newspack_Newsletters_Subscription::clear_lists_cache();
	return $list_id;
}

function presspass_ses_update_list( int $id, string $name, string $description ): void {
	global $wpdb;
	$wpdb->update(
		presspass_ses_table( 'lists' ),
		array(
			'name'        => $name,
			'description' => $description,
		),
		array( 'id' => $id )
	);
	Newspack_Newsletters_Subscription::clear_lists_cache();
}

function presspass_ses_delete_list( int $id ): void {
	global $wpdb;
	$wpdb->delete( presspass_ses_table( 'list_subscribers' ), array( 'list_id' => $id ) );
	$wpdb->delete( presspass_ses_table( 'lists' ), array( 'id' => $id ) );
	Newspack_Newsletters_Subscription::clear_lists_cache();
}
