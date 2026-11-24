<?php
/**
 * SMTP mailer for Amazon SES.
 *
 * @package PressPass_SES
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once ABSPATH . WPINC . '/PHPMailer/PHPMailer.php';
require_once ABSPATH . WPINC . '/PHPMailer/SMTP.php';
require_once ABSPATH . WPINC . '/PHPMailer/Exception.php';

function presspass_ses_build_mailer(): PHPMailer\PHPMailer\PHPMailer {
	$settings = presspass_ses_get_settings();

	// Same validator wp_mail() uses, so an address that passed is_email() when stored is not rejected by PHPMailer's stricter default.
	PHPMailer\PHPMailer\PHPMailer::$validator = static function ( $email ) {
		return (bool) is_email( $email );
	};

	$mailer = new PHPMailer\PHPMailer\PHPMailer( true );
	$mailer->isSMTP();
	// phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent -- this is the SES SMTP host, not offloaded assets.
	$mailer->Host          = 'email-smtp.' . $settings['region'] . '.amazonaws.com';
	$mailer->Port          = 587;
	$mailer->SMTPSecure    = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
	$mailer->SMTPAuth      = true;
	$mailer->Username      = $settings['smtp_username'];
	$mailer->Password      = $settings['smtp_password'];
	$mailer->SMTPKeepAlive = true;
	$mailer->CharSet       = 'UTF-8';
	$mailer->setFrom( $settings['sender_email'], $settings['sender_name'] );
	if ( '' !== $settings['reply_to'] ) {
		$mailer->addReplyTo( $settings['reply_to'] );
	}

	return apply_filters( 'presspass_ses_phpmailer', $mailer );
}

function presspass_ses_send_email( PHPMailer\PHPMailer\PHPMailer $mailer, string $to, string $subject, string $html, array $headers ): void {
	if ( ! presspass_ses_is_sending_allowed() ) {
		throw new RuntimeException( 'Sending is paused on this copy of the site.' );
	}

	$mailer->clearAllRecipients();
	$mailer->clearCustomHeaders();
	$mailer->addAddress( $to );
	$mailer->Subject = $subject;
	$mailer->isHTML( true );
	$mailer->Body    = $html;
	$mailer->AltBody = wp_strip_all_tags( $html );
	foreach ( $headers as $name => $value ) {
		$mailer->addCustomHeader( $name, $value );
	}
	$mailer->send();
}
