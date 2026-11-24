<?php
/**
 * Per-recipient links, unsubscribe headers and email footer.
 *
 * @package PressPass_SES
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function presspass_ses_token( object $subscriber, string $action ): string {
	return hash_hmac( 'sha256', $subscriber->id . '|' . $subscriber->email . '|' . $action, wp_salt( 'auth' ) );
}

function presspass_ses_public_url( object $subscriber, string $action ): string {
	return home_url( '/?presspass_ses=' . $action . '&presspass_ses_id=' . $subscriber->id . '&presspass_ses_token=' . presspass_ses_token( $subscriber, $action ) );
}

function presspass_ses_render_email( string $html, string $email, string $unsubscribe_url ): string {
	$footer = sprintf(
		'<p style="margin:24px 0;padding:0 16px;text-align:center;font-family:Arial,Helvetica,sans-serif;font-size:12px;line-height:18px;color:#666666;">%s <a href="%s" style="color:#666666;text-decoration:underline;">%s</a> &middot; %s</p>',
		esc_html(
			sprintf(
				/* translators: %s: the site name. */
				__( "You're receiving this because you subscribed to %s.", 'presspass-ses-for-newspack-newsletters' ),
				get_bloginfo( 'name' )
			)
		),
		esc_url( $unsubscribe_url ),
		esc_html__( 'Unsubscribe', 'presspass-ses-for-newspack-newsletters' ),
		esc_html( preg_replace( '/\R+/', ', ', trim( presspass_ses_get_settings()['postal_address'] ) ) )
	);

	$html = str_replace( 'PRESSPASS_SES_EMAIL', rawurlencode( $email ), $html );
	if ( str_contains( $html, '</body>' ) ) {
		return str_replace( '</body>', $footer . '</body>', $html );
	}
	return $html . $footer;
}

function presspass_ses_unsubscribe_headers( string $unsubscribe_url ): array {
	return array(
		'List-Unsubscribe'      => '<' . $unsubscribe_url . '>',
		'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
	);
}

function presspass_ses_tracking_pixel_url( string $url ): string {
	return add_query_arg( 'em', 'PRESSPASS_SES_EMAIL', $url );
}
// Priority 20: Newspack's own priority-10 filter first rewrites the URL to np-newsletters-pixel.php.
add_filter( 'newspack_newsletters_tracking_pixel_url', 'presspass_ses_tracking_pixel_url', 20 );
