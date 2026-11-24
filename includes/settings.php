<?php
/**
 * Settings storage, site lock and settings screen.
 *
 * @package PressPass_SES
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function presspass_ses_get_settings(): array {
	return get_option( 'presspass_ses_settings' );
}

function presspass_ses_has_credentials(): bool {
	$settings = presspass_ses_get_settings();
	return '' !== $settings['smtp_username'] && '' !== $settings['smtp_password'];
}

function presspass_ses_current_address(): string {
	return preg_replace( '#^https?://#', '', untrailingslashit( home_url() ) );
}

function presspass_ses_is_sending_allowed(): bool {
	return presspass_ses_has_credentials() && presspass_ses_current_address() === presspass_ses_get_settings()['site_address'];
}

function presspass_ses_render_paused_notice(): void {
	if ( ! presspass_ses_has_credentials() || presspass_ses_is_sending_allowed() ) {
		return;
	}
	printf(
		'<div class="notice notice-error"><p>%s</p></div>',
		esc_html(
			sprintf(
				/* translators: %s: the site address the SES settings were saved on. */
				__( 'PressPass SES: sending is paused because this looks like a copy of %s. Re-save the SES settings on this site to send from it.', 'presspass-ses-for-newspack-newsletters' ),
				presspass_ses_get_settings()['site_address']
			)
		)
	);
}
add_action( 'admin_notices', 'presspass_ses_render_paused_notice' );

function presspass_ses_handle_save_settings(): void {
	check_admin_referer( 'presspass_ses_save_settings' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to do this.', 'presspass-ses-for-newspack-newsletters' ), '', 403 );
	}

	global $wpdb;
	// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- the settings form always posts every field.
	$previous = presspass_ses_get_settings();
	$address  = presspass_ses_current_address();

	if ( '' !== $previous['site_address'] && $previous['site_address'] !== $address ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our own table, there is no WordPress API for it.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE sent_at IS NULL', presspass_ses_table( 'queue' ) ) );
	}

	$password = wp_unslash( $_POST['smtp_password'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- passwords must be stored verbatim.

	update_option(
		'presspass_ses_settings',
		array(
			'region'         => sanitize_text_field( wp_unslash( $_POST['region'] ) ),
			'smtp_username'  => sanitize_text_field( wp_unslash( $_POST['smtp_username'] ) ),
			'smtp_password'  => '' === $password ? $previous['smtp_password'] : $password,
			'sender_name'    => sanitize_text_field( wp_unslash( $_POST['sender_name'] ) ),
			'sender_email'   => sanitize_email( wp_unslash( $_POST['sender_email'] ) ),
			'reply_to'       => sanitize_email( wp_unslash( $_POST['reply_to'] ) ),
			'postal_address' => sanitize_textarea_field( wp_unslash( $_POST['postal_address'] ) ),
			'site_address'   => $address,
		),
		false
	);
	// phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotValidated

	if ( presspass_ses_has_pending_sends() ) {
		wp_schedule_single_event( time(), 'presspass_ses_run_queue' );
	}

	wp_safe_redirect( add_query_arg( 'presspass_ses_notice', 'saved', presspass_ses_settings_url() ) );
	exit;
}
add_action( 'admin_post_presspass_ses_save_settings', 'presspass_ses_handle_save_settings' );

function presspass_ses_handle_send_test_email(): void {
	check_admin_referer( 'presspass_ses_send_test_email' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to do this.', 'presspass-ses-for-newspack-newsletters' ), '', 403 );
	}

	try {
		presspass_ses_send_email(
			presspass_ses_build_mailer(),
			wp_get_current_user()->user_email,
			'PressPass SES test email',
			'<p>' . esc_html__( 'This is a test email from PressPass SES for Newspack Newsletters.', 'presspass-ses-for-newspack-newsletters' ) . '</p>',
			array()
		);
	} catch ( Exception $exception ) {
		wp_safe_redirect( add_query_arg( 'presspass_ses_error', rawurlencode( $exception->getMessage() ), presspass_ses_settings_url() ) );
		exit;
	}

	wp_safe_redirect( add_query_arg( 'presspass_ses_notice', 'test_sent', presspass_ses_settings_url() ) );
	exit;
}
add_action( 'admin_post_presspass_ses_send_test_email', 'presspass_ses_handle_send_test_email' );

function presspass_ses_handle_activate_provider(): void {
	check_admin_referer( 'presspass_ses_activate_provider' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to do this.', 'presspass-ses-for-newspack-newsletters' ), '', 403 );
	}

	// Newspack's own Settings screen only saves credentials for the providers it ships, so it can't switch to ours.
	Newspack_Newsletters::set_service_provider( 'presspass_ses' );

	wp_safe_redirect( add_query_arg( 'presspass_ses_notice', 'provider_activated', presspass_ses_settings_url() ) );
	exit;
}
add_action( 'admin_post_presspass_ses_activate_provider', 'presspass_ses_handle_activate_provider' );

function presspass_ses_render_provider_section(): void {
	$current = Newspack_Newsletters::service_provider();
	$labels  = wp_list_pluck( Newspack_Newsletters::get_registered_providers(), 'name' ) + array( 'manual' => __( 'Manual / Other', 'presspass-ses-for-newspack-newsletters' ) );
	?>
	<h2><?php esc_html_e( 'Newspack Newsletters', 'presspass-ses-for-newspack-newsletters' ); ?></h2>
	<?php
	printf(
		'<p>%s</p>',
		esc_html(
			sprintf(
				/* translators: %s: name of the email service provider Newspack Newsletters is set to use. */
				__( 'Newspack Newsletters is sending with: %s', 'presspass-ses-for-newspack-newsletters' ),
				$current ? $labels[ $current ] : __( 'none', 'presspass-ses-for-newspack-newsletters' )
			)
		)
	);
	?>
	<?php if ( presspass_ses_has_credentials() && 'presspass_ses' !== $current ) : ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="presspass_ses_activate_provider">
			<?php wp_nonce_field( 'presspass_ses_activate_provider' ); ?>
			<?php submit_button( __( 'Send Newspack Newsletters with Amazon SES', 'presspass-ses-for-newspack-newsletters' ), 'primary' ); ?>
		</form>
	<?php endif; ?>
	<?php
}

function presspass_ses_settings_url(): string {
	return admin_url( 'edit.php?post_type=newspack_nl_cpt&page=presspass-ses-settings' );
}

function presspass_ses_render_settings_page(): void {
	$settings = presspass_ses_get_settings();
	$fields   = array(
		'region'        => array( __( 'Region', 'presspass-ses-for-newspack-newsletters' ), 'text' ),
		'smtp_username' => array( __( 'SMTP username', 'presspass-ses-for-newspack-newsletters' ), 'text' ),
		'smtp_password' => array( __( 'SMTP password', 'presspass-ses-for-newspack-newsletters' ), 'password' ),
		'sender_name'   => array( __( 'Sender name', 'presspass-ses-for-newspack-newsletters' ), 'text' ),
		'sender_email'  => array( __( 'Sender email', 'presspass-ses-for-newspack-newsletters' ), 'email' ),
		'reply_to'      => array( __( 'Reply-to', 'presspass-ses-for-newspack-newsletters' ), 'email' ),
	);
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'SES Settings', 'presspass-ses-for-newspack-newsletters' ); ?></h1>
		<?php // phpcs:disable WordPress.Security.NonceVerification.Recommended -- display-only flags set by our own redirects. ?>
		<?php if ( isset( $_GET['presspass_ses_notice'] ) && 'saved' === $_GET['presspass_ses_notice'] ) : ?>
			<div class="notice notice-success"><p><?php esc_html_e( 'Settings saved.', 'presspass-ses-for-newspack-newsletters' ); ?></p></div>
		<?php elseif ( isset( $_GET['presspass_ses_notice'] ) && 'test_sent' === $_GET['presspass_ses_notice'] ) : ?>
			<div class="notice notice-success"><p><?php esc_html_e( 'Test email sent.', 'presspass-ses-for-newspack-newsletters' ); ?></p></div>
		<?php elseif ( isset( $_GET['presspass_ses_notice'] ) && 'provider_activated' === $_GET['presspass_ses_notice'] ) : ?>
			<div class="notice notice-success"><p><?php esc_html_e( 'Newspack Newsletters now sends with Amazon SES.', 'presspass-ses-for-newspack-newsletters' ); ?></p></div>
		<?php endif; ?>
		<?php if ( isset( $_GET['presspass_ses_error'] ) ) : ?>
			<div class="notice notice-error"><p><?php echo esc_html( sanitize_text_field( wp_unslash( $_GET['presspass_ses_error'] ) ) ); ?></p></div>
		<?php endif; ?>
		<?php // phpcs:enable WordPress.Security.NonceVerification.Recommended ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="presspass_ses_save_settings">
			<?php wp_nonce_field( 'presspass_ses_save_settings' ); ?>
			<table class="form-table" role="presentation">
				<?php foreach ( $fields as $key => $field ) : ?>
					<tr>
						<th scope="row"><label for="presspass-ses-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $field[0] ); ?></label></th>
						<td><input class="regular-text" id="presspass-ses-<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( $key ); ?>" type="<?php echo esc_attr( $field[1] ); ?>" value="<?php echo 'smtp_password' === $key ? '' : esc_attr( $settings[ $key ] ); ?>"></td>
					</tr>
				<?php endforeach; ?>
				<tr>
					<th scope="row"><label for="presspass-ses-postal_address"><?php esc_html_e( 'Postal address', 'presspass-ses-for-newspack-newsletters' ); ?></label></th>
					<td><textarea class="large-text" rows="3" id="presspass-ses-postal_address" name="postal_address"><?php echo esc_textarea( $settings['postal_address'] ); ?></textarea></td>
				</tr>
			</table>
			<?php submit_button( __( 'Save settings', 'presspass-ses-for-newspack-newsletters' ) ); ?>
		</form>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="presspass_ses_send_test_email">
			<?php wp_nonce_field( 'presspass_ses_send_test_email' ); ?>
			<?php submit_button( __( 'Send test email', 'presspass-ses-for-newspack-newsletters' ), 'secondary' ); ?>
		</form>
		<?php presspass_ses_render_provider_section(); ?>
	</div>
	<?php
}
