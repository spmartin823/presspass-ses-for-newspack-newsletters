<?php
/**
 * Sends screen: per-newsletter delivery progress.
 *
 * @package PressPass_SES
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- these are our own tables, there is no WordPress API for them.

function presspass_ses_sends_url(): string {
	return admin_url( 'edit.php?post_type=newspack_nl_cpt&page=presspass-ses-sends' );
}

function presspass_ses_get_sent_newsletters(): array {
	global $wpdb;
	$ids = $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT newsletter_id FROM %i', presspass_ses_table( 'queue' ) ) );
	if ( empty( $ids ) ) {
		return array();
	}
	return get_posts(
		array(
			'post_type'   => 'newspack_nl_cpt',
			'post_status' => 'any',
			'post__in'    => array_map( 'intval', $ids ),
			'numberposts' => -1,
			'meta_key'    => 'presspass_ses_queued_at', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- limited to the few newsletters that have queue rows.
			'orderby'     => 'meta_value',
			'order'       => 'DESC',
		)
	);
}

function presspass_ses_get_send_errors( int $newsletter_id ): array {
	global $wpdb;
	return $wpdb->get_results(
		$wpdb->prepare(
			'SELECT s.email, q.sent_at, q.error FROM %i q
			INNER JOIN %i s ON s.id = q.subscriber_id
			WHERE q.newsletter_id = %d AND q.error IS NOT NULL
			ORDER BY q.sent_at DESC LIMIT 20',
			presspass_ses_table( 'queue' ),
			presspass_ses_table( 'subscribers' ),
			$newsletter_id
		)
	);
}

function presspass_ses_render_sends_page(): void {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only selection.
	$chosen_id = isset( $_GET['newsletter'] ) ? (int) $_GET['newsletter'] : 0;
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'SES Sends', 'presspass-ses-for-newspack-newsletters' ); ?></h1>
		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Title', 'presspass-ses-for-newspack-newsletters' ); ?></th>
					<th><?php esc_html_e( 'Queued at', 'presspass-ses-for-newspack-newsletters' ); ?></th>
					<th><?php esc_html_e( 'Sent', 'presspass-ses-for-newspack-newsletters' ); ?></th>
					<th><?php esc_html_e( 'Failed or skipped', 'presspass-ses-for-newspack-newsletters' ); ?></th>
					<th><?php esc_html_e( 'Pending', 'presspass-ses-for-newspack-newsletters' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( presspass_ses_get_sent_newsletters() as $newsletter ) : ?>
					<?php $progress = presspass_ses_send_progress( $newsletter->ID ); ?>
					<tr>
						<td><a href="<?php echo esc_url( get_edit_post_link( $newsletter->ID ) ); ?>"><?php echo esc_html( $newsletter->post_title ); ?></a></td>
						<td><?php echo esc_html( presspass_ses_local_date( get_post_meta( $newsletter->ID, 'presspass_ses_queued_at', true ) ) ); ?></td>
						<td><?php echo esc_html( (string) $progress['sent'] ); ?></td>
						<td>
							<?php if ( 0 === $progress['failed'] ) : ?>
								0
							<?php else : ?>
								<a href="<?php echo esc_url( add_query_arg( 'newsletter', $newsletter->ID, presspass_ses_sends_url() ) ); ?>"><?php echo esc_html( (string) $progress['failed'] ); ?></a>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( (string) $progress['pending'] ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php if ( 0 !== $chosen_id ) : ?>
			<h2>
				<?php
				echo esc_html(
					sprintf(
						/* translators: %s: newsletter title. */
						__( 'Last 20 errors for %s', 'presspass-ses-for-newspack-newsletters' ),
						get_the_title( $chosen_id )
					)
				);
				?>
			</h2>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Email', 'presspass-ses-for-newspack-newsletters' ); ?></th>
						<th><?php esc_html_e( 'Time', 'presspass-ses-for-newspack-newsletters' ); ?></th>
						<th><?php esc_html_e( 'Error', 'presspass-ses-for-newspack-newsletters' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( presspass_ses_get_send_errors( $chosen_id ) as $send_error ) : ?>
						<tr>
							<td><?php echo esc_html( $send_error->email ); ?></td>
							<td><?php echo esc_html( presspass_ses_local_date( $send_error->sent_at ) ); ?></td>
							<td><?php echo esc_html( $send_error->error ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</div>
	<?php
}
