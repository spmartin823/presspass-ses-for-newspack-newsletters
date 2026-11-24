<?php
/**
 * Plugin Name:       PressPass SES for Newspack Newsletters
 * Description:       Send Newspack Newsletters through your own Amazon SES account, with a MailPoet-style subscriber screen.
 * Version:           0.1.0
 * Requires at least: 6.9
 * Requires PHP:      8.1
 * Requires Plugins:  newspack-newsletters
 * Author:            spmartin823
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       presspass-ses-for-newspack-newsletters
 *
 * @package PressPass_SES
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'PRESSPASS_SES_VERSION', '0.1.0' );
define( 'PRESSPASS_SES_DIR', plugin_dir_path( __FILE__ ) );
define( 'PRESSPASS_SES_URL', plugin_dir_url( __FILE__ ) );
define( 'PRESSPASS_SES_DB_VERSION', '3' );

require_once PRESSPASS_SES_DIR . 'includes/schema.php';
require_once PRESSPASS_SES_DIR . 'includes/subscribers.php';
require_once PRESSPASS_SES_DIR . 'includes/lists.php';
require_once PRESSPASS_SES_DIR . 'includes/settings.php';
require_once PRESSPASS_SES_DIR . 'includes/mailer.php';
require_once PRESSPASS_SES_DIR . 'includes/links.php';
require_once PRESSPASS_SES_DIR . 'includes/queue.php';
require_once PRESSPASS_SES_DIR . 'includes/mailpoet.php';
require_once PRESSPASS_SES_DIR . 'includes/editor.php';
require_once PRESSPASS_SES_DIR . 'includes/public-pages.php';
require_once PRESSPASS_SES_DIR . 'includes/admin/menu.php';

function presspass_ses_register_provider( array $providers ): array {
	$providers['presspass_ses'] = array(
		'name'       => __( 'Amazon SES (PressPass)', 'presspass-ses-for-newspack-newsletters' ),
		'class'      => 'PressPass_SES_Provider',
		'class_file' => PRESSPASS_SES_DIR . 'includes/provider/class-presspass-ses-provider.php',
	);
	return $providers;
}
add_filter( 'newspack_newsletters_registered_providers', 'presspass_ses_register_provider' );

if ( is_admin() ) {
	require_once PRESSPASS_SES_DIR . 'includes/admin/subscribers-page.php';
	require_once PRESSPASS_SES_DIR . 'includes/admin/lists-page.php';
	require_once PRESSPASS_SES_DIR . 'includes/admin/sends-page.php';
}

register_activation_hook( __FILE__, 'presspass_ses_install_schema' );
