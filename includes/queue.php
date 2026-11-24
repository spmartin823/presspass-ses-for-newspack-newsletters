<?php
/**
 * Send queue and the background worker that delivers it.
 *
 * @package PressPass_SES
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- these are our own tables, there is no WordPress API for them.

function presspass_ses_queue_newsletter( WP_Post $post, int $list_id ): int {
	global $wpdb;
	$queued = $wpdb->query(
		$wpdb->prepare(
			'INSERT IGNORE INTO %i (newsletter_id, subscriber_id)
			SELECT %d, s.id FROM %i s
			INNER JOIN %i ls ON ls.subscriber_id = s.id
			WHERE ls.list_id = %d AND s.status = %s',
			presspass_ses_table( 'queue' ),
			$post->ID,
			presspass_ses_table( 'subscribers' ),
			presspass_ses_table( 'list_subscribers' ),
			$list_id,
			PressPass_SES_Status::SUBSCRIBED
		)
	);
	update_post_meta( $post->ID, 'presspass_ses_list_id', $list_id );
	update_post_meta( $post->ID, 'presspass_ses_queued_at', current_time( 'mysql', true ) );
	wp_schedule_single_event( time(), 'presspass_ses_run_queue' );
	return $queued;
}

function presspass_ses_drop_unsent( int $post_id ): void {
	global $wpdb;
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE newsletter_id = %d AND sent_at IS NULL', presspass_ses_table( 'queue' ), $post_id ) );
}
add_action( 'trashed_post', 'presspass_ses_drop_unsent' );
add_action( 'deleted_post', 'presspass_ses_drop_unsent' );

function presspass_ses_has_pending_sends(): bool {
	global $wpdb;
	return null !== $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM %i WHERE sent_at IS NULL LIMIT 1', presspass_ses_table( 'queue' ) ) );
}

function presspass_ses_send_progress( int $newsletter_id ): array {
	global $wpdb;
	$progress = $wpdb->get_row(
		$wpdb->prepare(
			'SELECT COUNT(*) AS total,
			SUM( sent_at IS NOT NULL AND error IS NULL ) AS sent,
			SUM( error IS NOT NULL ) AS failed,
			SUM( sent_at IS NULL ) AS pending
			FROM %i WHERE newsletter_id = %d',
			presspass_ses_table( 'queue' ),
			$newsletter_id
		),
		ARRAY_A
	);
	return array_map( 'intval', $progress );
}

function presspass_ses_record_last_error( string $message ): void {
	update_option(
		'presspass_ses_last_error',
		array(
			'message' => $message,
			'time'    => current_time( 'mysql', true ),
		),
		false
	);
}

function presspass_ses_set_queue_row( object $row, array $fields ): void {
	global $wpdb;
	$wpdb->update(
		presspass_ses_table( 'queue' ),
		$fields,
		array(
			'newsletter_id' => $row->newsletter_id,
			'subscriber_id' => $row->id,
		)
	);
}

function presspass_ses_run_queue(): void {
	global $wpdb;

	if ( ! presspass_ses_is_sending_allowed() ) {
		return;
	}
	if ( ! presspass_ses_has_pending_sends() ) {
		delete_option( 'presspass_ses_last_error' );
		return;
	}

	// Scheduled before any work so a run killed mid-send still resumes.
	wp_schedule_single_event( time() + 60, 'presspass_ses_run_queue' );

	$mailer = presspass_ses_build_mailer();
	try {
		$mailer->smtpConnect();
	} catch ( PHPMailer\PHPMailer\Exception $exception ) {
		presspass_ses_record_last_error( $exception->getMessage() );
		return;
	}

	$deadline = microtime( true ) + 20;
	while ( microtime( true ) < $deadline ) {
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT q.newsletter_id, s.id, s.email, s.status FROM %i q
				INNER JOIN %i s ON s.id = q.subscriber_id
				WHERE q.sent_at IS NULL LIMIT 50',
				presspass_ses_table( 'queue' ),
				presspass_ses_table( 'subscribers' )
			)
		);
		if ( empty( $rows ) ) {
			break;
		}

		foreach ( $rows as $row ) {
			if ( microtime( true ) >= $deadline ) {
				break;
			}

			$claimed = $wpdb->query(
				$wpdb->prepare(
					'UPDATE %i SET sent_at = UTC_TIMESTAMP() WHERE newsletter_id = %d AND subscriber_id = %d AND sent_at IS NULL',
					presspass_ses_table( 'queue' ),
					$row->newsletter_id,
					$row->id
				)
			);
			if ( 1 !== $claimed ) {
				continue;
			}

			if ( PressPass_SES_Status::SUBSCRIBED !== $row->status ) {
				presspass_ses_set_queue_row( $row, array( 'error' => 'skipped: ' . $row->status ) );
				continue;
			}

			$post            = get_post( (int) $row->newsletter_id );
			$unsubscribe_url = presspass_ses_public_url( $row, 'unsubscribe' );
			$mailer->setFrom( get_post_meta( $post->ID, 'senderEmail', true ), get_post_meta( $post->ID, 'senderName', true ) );
			try {
				presspass_ses_send_email(
					$mailer,
					$row->email,
					$post->post_title,
					presspass_ses_render_email( get_post_meta( $post->ID, 'newspack_email_html', true ), $row->email, $unsubscribe_url ),
					presspass_ses_unsubscribe_headers( $unsubscribe_url )
				);
				delete_option( 'presspass_ses_last_error' );
				usleep( 100000 );
			} catch ( PHPMailer\PHPMailer\Exception $exception ) {
				// ErrorInfo, unlike the exception message, carries the SMTP server's reply (e.g. SES's "454 Throttling failure").
				$error = $mailer->ErrorInfo;
				if ( str_contains( $error, 'Throttling' ) || str_contains( $error, 'Message rejected' ) ) {
					presspass_ses_set_queue_row( $row, array( 'sent_at' => null ) );
					presspass_ses_record_last_error( $error );
					$mailer->smtpClose();
					return;
				}
				presspass_ses_set_queue_row( $row, array( 'error' => $error ) );
			}
		}
	}

	$mailer->smtpClose();
}
add_action( 'presspass_ses_run_queue', 'presspass_ses_run_queue' );
