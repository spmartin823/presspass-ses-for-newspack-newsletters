<?php
/**
 * Import from MailPoet and write-back of removals to MailPoet.
 *
 * @package PressPass_SES
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- MailPoet's tables and our own; there is no WordPress API for either.

function presspass_ses_mailpoet_table( string $name ): string {
	global $wpdb;
	return $wpdb->prefix . 'mailpoet_' . $name;
}

function presspass_ses_is_mailpoet_present(): bool {
	global $wpdb;
	$table = presspass_ses_mailpoet_table( 'subscribers' );
	return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) === $table;
}

function presspass_ses_mailpoet_status( string $mailpoet_status ): string {
	if ( 'inactive' === $mailpoet_status ) {
		return PressPass_SES_Status::UNSUBSCRIBED;
	}
	return $mailpoet_status;
}

function presspass_ses_import_mailpoet_lists(): array {
	global $wpdb;
	$segments = $wpdb->get_results( $wpdb->prepare( "SELECT id, name, description FROM %i WHERE type = 'default' AND deleted_at IS NULL", presspass_ses_mailpoet_table( 'segments' ) ) );
	if ( '' !== $wpdb->last_error ) {
		wp_die( esc_html( $wpdb->last_error ) );
	}
	$list_ids = array();
	foreach ( $segments as $segment ) {
		$list_id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE mailpoet_segment_id = %d', presspass_ses_table( 'lists' ), $segment->id ) );
		if ( 0 === $list_id ) {
			$list_id = presspass_ses_create_list( $segment->name, $segment->description );
			$wpdb->update( presspass_ses_table( 'lists' ), array( 'mailpoet_segment_id' => (int) $segment->id ), array( 'id' => $list_id ) );
		} else {
			presspass_ses_update_list( $list_id, $segment->name, $segment->description );
		}
		$list_ids[ (int) $segment->id ] = $list_id;
	}
	return $list_ids;
}

function presspass_ses_import_from_mailpoet(): array {
	global $wpdb;

	remove_action( 'presspass_ses_subscriber_status_changed', 'presspass_ses_mailpoet_write_back_status', 10 );
	remove_action( 'presspass_ses_subscriber_deleted', 'presspass_ses_mailpoet_write_back_delete', 10 );
	remove_action( 'presspass_ses_list_membership_changed', 'presspass_ses_mailpoet_write_back_membership', 10 );

	$counts = array(
		'added'     => 0,
		'updated'   => 0,
		'deleted'   => 0,
		'unchanged' => 0,
		'skipped'   => 0,
	);

	$list_ids = presspass_ses_import_mailpoet_lists();

	// UNIX_TIMESTAMP() reads MailPoet's TIMESTAMP columns as absolute instants, independent of this connection's time_zone.
	$mailpoet_subscribers = $wpdb->get_results( $wpdb->prepare( 'SELECT id, email, first_name, last_name, status, UNIX_TIMESTAMP(created_at) AS created_ts, UNIX_TIMESTAMP(updated_at) AS updated_ts FROM %i WHERE deleted_at IS NULL ORDER BY id', presspass_ses_mailpoet_table( 'subscribers' ) ) );
	// A failed read returns no rows, which the passes below would take as everyone being gone or off every list.
	if ( '' !== $wpdb->last_error ) {
		wp_die( esc_html( $wpdb->last_error ) );
	}
	$memberships = $wpdb->get_results( $wpdb->prepare( "SELECT subscriber_id, segment_id FROM %i WHERE status = 'subscribed'", presspass_ses_mailpoet_table( 'subscriber_segment' ) ) );
	if ( '' !== $wpdb->last_error ) {
		wp_die( esc_html( $wpdb->last_error ) );
	}

	$subscribed_segments = array();
	foreach ( $memberships as $membership ) {
		$subscribed_segments[ (int) $membership->subscriber_id ][ (int) $membership->segment_id ] = true;
	}

	$ours = array();
	foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i', presspass_ses_table( 'subscribers' ) ) ) as $subscriber ) {
		$ours[ $subscriber->email ] = $subscriber;
	}

	$mailpoet_emails = array();
	foreach ( $mailpoet_subscribers as $mailpoet_subscriber ) {
		$mailpoet_emails[ (int) $mailpoet_subscriber->id ] = presspass_ses_normalize_email( $mailpoet_subscriber->email );
	}

	// An address changed in MailPoet makes our old row gone, so the new address imports as a fresh row with that mailpoet_id.
	foreach ( $ours as $email => $subscriber ) {
		if ( null !== $subscriber->mailpoet_id && ( ! isset( $mailpoet_emails[ (int) $subscriber->mailpoet_id ] ) || $mailpoet_emails[ (int) $subscriber->mailpoet_id ] !== $email ) ) {
			presspass_ses_delete_subscriber( (int) $subscriber->id );
			unset( $ours[ $email ] );
			++$counts['deleted'];
		}
	}

	$seen_emails = array();
	foreach ( $mailpoet_subscribers as $mailpoet_subscriber ) {
		$mailpoet_id = (int) $mailpoet_subscriber->id;
		$email       = $mailpoet_emails[ $mailpoet_id ];
		if ( ! is_email( $email ) || isset( $seen_emails[ $email ] ) ) {
			++$counts['skipped'];
			continue;
		}
		$seen_emails[ $email ] = true;

		$status     = presspass_ses_mailpoet_status( $mailpoet_subscriber->status );
		$updated_at = gmdate( 'Y-m-d H:i:s', (int) $mailpoet_subscriber->updated_ts );

		$is_existing = isset( $ours[ $email ] );
		if ( $is_existing ) {
			$subscriber    = $ours[ $email ];
			$subscriber_id = (int) $subscriber->id;
			$fields        = array();
			// Write-back mirrors every status change of ours into MailPoet, so any difference here originated in MailPoet.
			$is_status_changed = $status !== $subscriber->status;
			$are_names_changed = $updated_at > $subscriber->updated_at
				&& ( $mailpoet_subscriber->first_name !== $subscriber->first_name || $mailpoet_subscriber->last_name !== $subscriber->last_name );
			if ( $is_status_changed ) {
				presspass_ses_set_status( $subscriber_id, $status );
			}
			if ( $are_names_changed ) {
				$fields['first_name'] = $mailpoet_subscriber->first_name;
				$fields['last_name']  = $mailpoet_subscriber->last_name;
			}
			if ( $is_status_changed || $are_names_changed ) {
				$fields['updated_at'] = $updated_at;
			}
			if ( null === $subscriber->mailpoet_id ) {
				$fields['mailpoet_id'] = $mailpoet_id;
			}
			if ( array() !== $fields ) {
				presspass_ses_update_subscriber( $subscriber_id, $fields );
			}
		} else {
			$subscriber_id = presspass_ses_insert_subscriber( $email, $mailpoet_subscriber->first_name, $mailpoet_subscriber->last_name, $status, 'mailpoet' );
			presspass_ses_update_subscriber(
				$subscriber_id,
				array(
					'mailpoet_id' => $mailpoet_id,
					'created_at'  => gmdate( 'Y-m-d H:i:s', (int) $mailpoet_subscriber->created_ts ),
					'updated_at'  => $updated_at,
				)
			);
			++$counts['added'];
		}

		$is_membership_changed = false;
		foreach ( $list_ids as $segment_id => $list_id ) {
			if ( isset( $subscribed_segments[ $mailpoet_id ][ $segment_id ] ) ) {
				$is_membership_changed = presspass_ses_add_to_list( $subscriber_id, $list_id ) || $is_membership_changed;
			} else {
				$is_membership_changed = presspass_ses_remove_from_list( $subscriber_id, $list_id ) || $is_membership_changed;
			}
		}

		if ( $is_existing ) {
			if ( $is_status_changed || $are_names_changed || $is_membership_changed ) {
				++$counts['updated'];
			} else {
				++$counts['unchanged'];
			}
		}
	}

	add_action( 'presspass_ses_subscriber_status_changed', 'presspass_ses_mailpoet_write_back_status', 10, 2 );
	add_action( 'presspass_ses_subscriber_deleted', 'presspass_ses_mailpoet_write_back_delete' );
	add_action( 'presspass_ses_list_membership_changed', 'presspass_ses_mailpoet_write_back_membership', 10, 3 );

	return $counts;
}

// Someone added to MailPoet after the last import has no mailpoet_id yet, so they are matched on email.
function presspass_ses_mailpoet_ids( object $subscriber ): array {
	global $wpdb;
	if ( null !== $subscriber->mailpoet_id ) {
		return array( (int) $subscriber->mailpoet_id );
	}
	return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM %i WHERE LOWER(TRIM(email)) = %s', presspass_ses_mailpoet_table( 'subscribers' ), $subscriber->email ) ) );
}

// MailPoet's timestamp columns are TIMESTAMP, so NOW() stores the right instant whatever this connection's time_zone is.
function presspass_ses_mailpoet_write_back_status( object $subscriber, string $status ): void {
	global $wpdb;
	if ( ! presspass_ses_is_mailpoet_present() ) {
		return;
	}
	foreach ( presspass_ses_mailpoet_ids( $subscriber ) as $mailpoet_id ) {
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET status = %s, updated_at = NOW() WHERE id = %d', presspass_ses_mailpoet_table( 'subscribers' ), $status, $mailpoet_id ) );
	}
}
add_action( 'presspass_ses_subscriber_status_changed', 'presspass_ses_mailpoet_write_back_status', 10, 2 );

function presspass_ses_mailpoet_write_back_delete( object $subscriber ): void {
	global $wpdb;
	if ( ! presspass_ses_is_mailpoet_present() ) {
		return;
	}
	foreach ( presspass_ses_mailpoet_ids( $subscriber ) as $mailpoet_id ) {
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET deleted_at = NOW() WHERE id = %d AND deleted_at IS NULL', presspass_ses_mailpoet_table( 'subscribers' ), $mailpoet_id ) );
	}
}
add_action( 'presspass_ses_subscriber_deleted', 'presspass_ses_mailpoet_write_back_delete' );

function presspass_ses_mailpoet_write_back_membership( int $subscriber_id, int $list_id, bool $is_member ): void {
	global $wpdb;
	if ( ! presspass_ses_is_mailpoet_present() ) {
		return;
	}
	$list = presspass_ses_get_list( $list_id );
	if ( null === $list->mailpoet_segment_id ) {
		return;
	}
	foreach ( presspass_ses_mailpoet_ids( presspass_ses_get_subscriber( $subscriber_id ) ) as $mailpoet_id ) {
		if ( $is_member ) {
			$wpdb->query( $wpdb->prepare( "INSERT INTO %i (subscriber_id, segment_id, status, created_at, updated_at) VALUES (%d, %d, 'subscribed', NOW(), NOW()) ON DUPLICATE KEY UPDATE status = 'subscribed', updated_at = NOW()", presspass_ses_mailpoet_table( 'subscriber_segment' ), $mailpoet_id, $list->mailpoet_segment_id ) );
		} else {
			$wpdb->query( $wpdb->prepare( "UPDATE %i SET status = 'unsubscribed', updated_at = NOW() WHERE subscriber_id = %d AND segment_id = %d", presspass_ses_mailpoet_table( 'subscriber_segment' ), $mailpoet_id, $list->mailpoet_segment_id ) );
		}
	}
}
add_action( 'presspass_ses_list_membership_changed', 'presspass_ses_mailpoet_write_back_membership', 10, 3 );

function presspass_ses_render_mailpoet_import(): void {
	if ( ! presspass_ses_is_mailpoet_present() ) {
		return;
	}
	// phpcs:disable WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- display-only counts, always set together by our own redirect.
	if ( isset( $_GET['presspass_ses_imported'] ) ) {
		$imported = wp_unslash( $_GET['presspass_ses_imported'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each value is cast to int below.
		printf(
			'<div class="notice notice-success"><p>%s</p></div>',
			esc_html(
				sprintf(
					/* translators: 1: added, 2: updated, 3: deleted, 4: unchanged, 5: skipped subscriber counts. */
					__( 'Imported from MailPoet: %1$d added, %2$d updated, %3$d deleted, %4$d unchanged, %5$d skipped.', 'presspass-ses-for-newspack-newsletters' ),
					(int) $imported['added'],
					(int) $imported['updated'],
					(int) $imported['deleted'],
					(int) $imported['unchanged'],
					(int) $imported['skipped']
				)
			)
		);
	}
	// phpcs:enable WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotValidated
	?>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="presspass_ses_import_mailpoet">
		<?php wp_nonce_field( 'presspass_ses_import_mailpoet' ); ?>
		<?php submit_button( __( 'Import from MailPoet', 'presspass-ses-for-newspack-newsletters' ), 'secondary', 'submit', false ); ?>
		<p class="description"><?php esc_html_e( 'Import mirrors MailPoet: people who were trashed there are deleted here.', 'presspass-ses-for-newspack-newsletters' ); ?></p>
	</form>
	<?php
}
add_action( 'presspass_ses_subscribers_page_top', 'presspass_ses_render_mailpoet_import' );

function presspass_ses_handle_import_mailpoet(): void {
	presspass_ses_authorize_staff( 'presspass_ses_import_mailpoet' );
	if ( ! presspass_ses_is_mailpoet_present() ) {
		wp_die( esc_html__( "MailPoet's tables were not found.", 'presspass-ses-for-newspack-newsletters' ) );
	}
	wp_safe_redirect( add_query_arg( 'presspass_ses_imported', presspass_ses_import_from_mailpoet(), presspass_ses_subscribers_url() ) );
	exit;
}
add_action( 'admin_post_presspass_ses_import_mailpoet', 'presspass_ses_handle_import_mailpoet' );
