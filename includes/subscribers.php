<?php
/**
 * Subscriber storage.
 *
 * @package PressPass_SES
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- these are our own tables, there is no WordPress API for them.

class PressPass_SES_Status {
	const SUBSCRIBED   = 'subscribed';
	const UNCONFIRMED  = 'unconfirmed';
	const UNSUBSCRIBED = 'unsubscribed';
	const BOUNCED      = 'bounced';
}

function presspass_ses_normalize_email( string $email ): string {
	return strtolower( trim( $email ) );
}

function presspass_ses_get_subscriber( int $id ): ?object {
	global $wpdb;
	return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', presspass_ses_table( 'subscribers' ), $id ) );
}

function presspass_ses_get_subscriber_by_email( string $email ): ?object {
	global $wpdb;
	return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE email = %s', presspass_ses_table( 'subscribers' ), presspass_ses_normalize_email( $email ) ) );
}

function presspass_ses_insert_subscriber( string $email, string $first_name, string $last_name, string $status, string $source ): int {
	global $wpdb;
	$now = current_time( 'mysql', true );
	$wpdb->insert(
		presspass_ses_table( 'subscribers' ),
		array(
			'email'      => presspass_ses_normalize_email( $email ),
			'first_name' => $first_name,
			'last_name'  => $last_name,
			'status'     => $status,
			'source'     => $source,
			'created_at' => $now,
			'updated_at' => $now,
		)
	);
	return $wpdb->insert_id;
}

function presspass_ses_update_subscriber( int $id, array $fields ): void {
	global $wpdb;
	if ( ! isset( $fields['updated_at'] ) ) {
		$fields['updated_at'] = current_time( 'mysql', true );
	}
	$wpdb->update( presspass_ses_table( 'subscribers' ), $fields, array( 'id' => $id ) );
}

function presspass_ses_set_status( int $id, string $status ): void {
	global $wpdb;
	$wpdb->update(
		presspass_ses_table( 'subscribers' ),
		array(
			'status'     => $status,
			'updated_at' => current_time( 'mysql', true ),
		),
		array( 'id' => $id )
	);
	do_action( 'presspass_ses_subscriber_status_changed', presspass_ses_get_subscriber( $id ), $status );
}

function presspass_ses_delete_subscriber( int $id ): void {
	global $wpdb;
	do_action( 'presspass_ses_subscriber_deleted', presspass_ses_get_subscriber( $id ) );
	$wpdb->delete( presspass_ses_table( 'list_subscribers' ), array( 'subscriber_id' => $id ) );
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE subscriber_id = %d AND sent_at IS NULL', presspass_ses_table( 'queue' ), $id ) );
	$wpdb->delete( presspass_ses_table( 'subscribers' ), array( 'id' => $id ) );
}

function presspass_ses_query_subscribers( string $status, int $list_id, string $search, int $per_page, int $page ): array {
	global $wpdb;
	$from  = $wpdb->prepare( 'FROM %i s', presspass_ses_table( 'subscribers' ) );
	$where = array( '1=1' );
	if ( '' !== $status ) {
		$where[] = $wpdb->prepare( 's.status = %s', $status );
	}
	if ( 0 !== $list_id ) {
		$where[] = $wpdb->prepare( 's.id IN ( SELECT subscriber_id FROM %i WHERE list_id = %d )', presspass_ses_table( 'list_subscribers' ), $list_id );
	}
	if ( '' !== $search ) {
		$like    = '%' . $wpdb->esc_like( $search ) . '%';
		$where[] = $wpdb->prepare( '( s.email LIKE %s OR s.first_name LIKE %s OR s.last_name LIKE %s )', $like, $like, $like );
	}
	$where_sql = implode( ' AND ', $where );
	$offset    = ( $page - 1 ) * $per_page;

	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $from and $where_sql are built from prepared fragments above.
	$total       = (int) $wpdb->get_var( "SELECT COUNT(*) {$from} WHERE {$where_sql}" );
	$subscribers = $wpdb->get_results( $wpdb->prepare( "SELECT s.* {$from} WHERE {$where_sql} ORDER BY s.created_at DESC, s.email ASC LIMIT %d OFFSET %d", $per_page, $offset ) );
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	return array(
		'subscribers' => $subscribers,
		'total'       => $total,
	);
}

function presspass_ses_count_by_status( int $list_id ): array {
	global $wpdb;
	$counts = array(
		PressPass_SES_Status::SUBSCRIBED   => 0,
		PressPass_SES_Status::UNCONFIRMED  => 0,
		PressPass_SES_Status::UNSUBSCRIBED => 0,
		PressPass_SES_Status::BOUNCED      => 0,
	);
	if ( 0 === $list_id ) {
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT status, COUNT(*) AS total FROM %i GROUP BY status', presspass_ses_table( 'subscribers' ) ) );
	} else {
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT s.status, COUNT(*) AS total FROM %i s INNER JOIN %i ls ON ls.subscriber_id = s.id WHERE ls.list_id = %d GROUP BY s.status', presspass_ses_table( 'subscribers' ), presspass_ses_table( 'list_subscribers' ), $list_id ) );
	}
	foreach ( $rows as $row ) {
		$counts[ $row->status ] = (int) $row->total;
	}
	$counts['all'] = array_sum( $counts );
	return $counts;
}

function presspass_ses_add_to_list( int $subscriber_id, int $list_id ): bool {
	global $wpdb;
	$is_added = 1 === $wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO %i (list_id, subscriber_id) VALUES (%d, %d)', presspass_ses_table( 'list_subscribers' ), $list_id, $subscriber_id ) );
	if ( $is_added ) {
		do_action( 'presspass_ses_list_membership_changed', $subscriber_id, $list_id, true );
	}
	return $is_added;
}

function presspass_ses_remove_from_list( int $subscriber_id, int $list_id ): bool {
	global $wpdb;
	$is_removed = 1 === $wpdb->delete(
		presspass_ses_table( 'list_subscribers' ),
		array(
			'list_id'       => $list_id,
			'subscriber_id' => $subscriber_id,
		)
	);
	if ( $is_removed ) {
		do_action( 'presspass_ses_list_membership_changed', $subscriber_id, $list_id, false );
	}
	return $is_removed;
}

function presspass_ses_get_subscriber_list_ids( int $subscriber_id ): array {
	global $wpdb;
	return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( 'SELECT list_id FROM %i WHERE subscriber_id = %d ORDER BY list_id', presspass_ses_table( 'list_subscribers' ), $subscriber_id ) ) );
}
