<?php
/**
 * Newsletter editor shim for the Amazon SES provider.
 *
 * @package PressPass_SES
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function presspass_ses_enqueue_editor_shim(): void {
	if ( 'newspack_nl_cpt' !== get_current_screen()->post_type ) {
		return;
	}
	wp_enqueue_script( 'presspass-ses-editor-shim', PRESSPASS_SES_URL . 'assets/editor-shim.js', array( 'wp-element' ), PRESSPASS_SES_VERSION, false );
	wp_add_inline_script(
		'presspass-ses-editor-shim',
		'var presspass_ses_editor = ' . wp_json_encode(
			array(
				'displayName' => __( 'Amazon SES', 'presspass-ses-for-newspack-newsletters' ),
				/* translators: 1: list name, 2: number of subscribers. */
				'preSendInfo' => __( 'Sending via Amazon SES to %1$s (%2$s subscribers).', 'presspass-ses-for-newspack-newsletters' ),
			)
		) . ';',
		'before'
	);
}
add_action( 'enqueue_block_editor_assets', 'presspass_ses_enqueue_editor_shim' );
