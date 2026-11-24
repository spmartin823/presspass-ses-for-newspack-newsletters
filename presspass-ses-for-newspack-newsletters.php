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
define( 'PRESSPASS_SES_DB_VERSION', '1' );
