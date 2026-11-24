<?php
/**
 * Signup with email confirmation, and the public confirm and unsubscribe pages.
 *
 * @package PressPass_SES
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function presspass_ses_subscribe( string $email, string $name, array $list_ids ): object {
	$subscriber = presspass_ses_get_subscriber_by_email( $email );
	if ( null === $subscriber ) {
		list( $first_name, $last_name ) = array_pad( explode( ' ', $name, 2 ), 2, '' );
		$subscriber_id                  = presspass_ses_insert_subscriber( $email, $first_name, $last_name, PressPass_SES_Status::UNCONFIRMED, 'form' );
	} else {
		$subscriber_id = (int) $subscriber->id;
		if ( PressPass_SES_Status::SUBSCRIBED !== $subscriber->status && PressPass_SES_Status::UNCONFIRMED !== $subscriber->status ) {
			presspass_ses_set_status( $subscriber_id, PressPass_SES_Status::UNCONFIRMED );
		}
	}
	foreach ( $list_ids as $list_id ) {
		presspass_ses_add_to_list( $subscriber_id, $list_id );
	}

	$subscriber = presspass_ses_get_subscriber( $subscriber_id );
	if ( PressPass_SES_Status::UNCONFIRMED === $subscriber->status && false === get_transient( 'presspass_ses_confirm_' . $subscriber_id ) ) {
		presspass_ses_send_confirmation( $subscriber );
		set_transient( 'presspass_ses_confirm_' . $subscriber_id, 1, 10 * MINUTE_IN_SECONDS );
	}
	return $subscriber;
}

function presspass_ses_send_confirmation( object $subscriber ): void {
	$site   = presspass_ses_site_name();
	$mailer = presspass_ses_build_mailer();
	presspass_ses_send_email(
		$mailer,
		$subscriber->email,
		/* translators: %s: the site name. */
		sprintf( __( 'Confirm your subscription to %s', 'presspass-ses-for-newspack-newsletters' ), $site ),
		sprintf(
			'<p>%s <a href="%s">%s</a></p>',
			/* translators: %s: the site name. */
			esc_html( sprintf( __( 'Please confirm that you want to receive emails from %s.', 'presspass-ses-for-newspack-newsletters' ), $site ) ),
			esc_url( presspass_ses_public_url( $subscriber, 'confirm' ) ),
			esc_html__( 'Confirm subscription', 'presspass-ses-for-newspack-newsletters' )
		),
		array()
	);
	$mailer->smtpClose();
}

function presspass_ses_site_name(): string {
	return wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
}

// phpcs:disable WordPress.Security.NonceVerification -- these links are opened from emails; the HMAC token in the URL is the credential.
function presspass_ses_handle_public_request(): void {
	if ( ! isset( $_GET['presspass_ses'], $_GET['presspass_ses_id'], $_GET['presspass_ses_token'] ) ) {
		return;
	}
	$action     = sanitize_key( wp_unslash( $_GET['presspass_ses'] ) );
	$subscriber = presspass_ses_get_subscriber( absint( $_GET['presspass_ses_id'] ) );
	if ( null === $subscriber || ! in_array( $action, array( 'confirm', 'unsubscribe' ), true ) || ! hash_equals( presspass_ses_token( $subscriber, $action ), sanitize_text_field( wp_unslash( $_GET['presspass_ses_token'] ) ) ) ) {
		presspass_ses_render_page( 404, __( 'This link is no longer valid.', 'presspass-ses-for-newspack-newsletters' ), '', '' );
	}
	$site = presspass_ses_site_name();
	// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- PHP always sets REQUEST_METHOD for a web request.
	$is_post = 'POST' === $_SERVER['REQUEST_METHOD'];

	if ( 'confirm' === $action ) {
		if ( PressPass_SES_Status::UNCONFIRMED === $subscriber->status ) {
			// Mail scanners follow links, so a GET only shows the button.
			if ( ! $is_post ) {
				/* translators: %s: the site name. */
				presspass_ses_render_page( 200, sprintf( __( 'Confirm your subscription to %s.', 'presspass-ses-for-newspack-newsletters' ), $site ), presspass_ses_public_url( $subscriber, 'confirm' ), __( 'Confirm subscription', 'presspass-ses-for-newspack-newsletters' ) );
			}
			presspass_ses_set_status( (int) $subscriber->id, PressPass_SES_Status::SUBSCRIBED );
		} elseif ( PressPass_SES_Status::SUBSCRIBED !== $subscriber->status ) {
			// An old confirmation link must not re-subscribe someone who has since unsubscribed or bounced.
			presspass_ses_render_page( 404, __( 'This link is no longer valid.', 'presspass-ses-for-newspack-newsletters' ), '', '' );
		}
		/* translators: %s: the site name. */
		presspass_ses_render_page( 200, sprintf( __( "You're subscribed to %s.", 'presspass-ses-for-newspack-newsletters' ), $site ), '', '' );
	}

	if ( ! $is_post ) {
		/* translators: %s: the site name. */
		presspass_ses_render_page( 200, sprintf( __( 'Stop receiving emails from %s?', 'presspass-ses-for-newspack-newsletters' ), $site ), presspass_ses_public_url( $subscriber, 'unsubscribe' ), __( 'Unsubscribe', 'presspass-ses-for-newspack-newsletters' ) );
	}
	if ( PressPass_SES_Status::UNSUBSCRIBED !== $subscriber->status ) {
		presspass_ses_set_status( (int) $subscriber->id, PressPass_SES_Status::UNSUBSCRIBED );
	}
	// RFC 8058 one-click unsubscribe: mail clients POST this body and expect a bare 200.
	if ( isset( $_POST['List-Unsubscribe'] ) && 'One-Click' === $_POST['List-Unsubscribe'] ) {
		status_header( 200 );
		nocache_headers();
		exit;
	}
	/* translators: %s: the site name. */
	presspass_ses_render_page( 200, sprintf( __( "You've been unsubscribed from %s.", 'presspass-ses-for-newspack-newsletters' ), $site ), '', '' );
}
// phpcs:enable WordPress.Security.NonceVerification
add_action( 'template_redirect', 'presspass_ses_handle_public_request' );

function presspass_ses_render_page( int $status, string $message, string $form_url, string $button_label ): void {
	status_header( $status );
	nocache_headers();
	header( 'Content-Type: text/html; charset=utf-8' );
	$site = presspass_ses_site_name();
	?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title><?php echo esc_html( $site ); ?></title>
<style>
body{margin:0;padding:64px 16px;background:#f6f7f7;color:#1e1e1e;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif;text-align:center}
main{max-width:480px;margin:0 auto;padding:32px;background:#fff;border-radius:8px}
button{padding:10px 24px;border:0;border-radius:4px;background:#1e1e1e;color:#fff;font-size:16px;cursor:pointer}
</style>
</head>
<body>
<main>
<h1><?php echo esc_html( $site ); ?></h1>
<p><?php echo esc_html( $message ); ?></p>
	<?php if ( '' !== $form_url ) : ?>
<form method="post" action="<?php echo esc_url( $form_url ); ?>"><button type="submit"><?php echo esc_html( $button_label ); ?></button></form>
	<?php endif; ?>
</main>
</body>
</html>
	<?php
	exit;
}
