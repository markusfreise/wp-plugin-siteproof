<?php
/**
 * Plugin Name:       Siteproof – AI Content Audit
 * Plugin URI:        https://siteproof.markus-freise.de
 * Description:       Audits every page in the admin: SEO, load time, content (heading structure, AI proofreading), images, links and accessibility, with a site-wide assessment, traffic-light ratings and a comparison with the previous run.
 * Version:           1.0.0
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Author:            Markus Freise
 * Author URI:        https://markus-freise.de
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       siteproof
 * Domain Path:       /languages
 *
 * @package Siteproof
 */

defined( 'ABSPATH' ) || exit;

const SITEPROOF_VERSION = '1.0.0';
const SITEPROOF_FILE    = __FILE__;
const SITEPROOF_CAP     = 'manage_options';
const SITEPROOF_DB      = 1;

define( 'SITEPROOF_DIR', plugin_dir_path( __FILE__ ) );
define( 'SITEPROOF_URL', plugin_dir_url( __FILE__ ) );

require_once SITEPROOF_DIR . 'includes/class-siteproof-db.php';
require_once SITEPROOF_DIR . 'includes/class-siteproof-crypto.php';
require_once SITEPROOF_DIR . 'includes/class-siteproof-settings.php';
require_once SITEPROOF_DIR . 'includes/class-siteproof-collector.php';
require_once SITEPROOF_DIR . 'includes/class-siteproof-links.php';
require_once SITEPROOF_DIR . 'includes/class-siteproof-pagespeed.php';
require_once SITEPROOF_DIR . 'includes/llm/interface-siteproof-llm.php';
require_once SITEPROOF_DIR . 'includes/llm/class-siteproof-llm-base.php';
require_once SITEPROOF_DIR . 'includes/llm/class-siteproof-llm-anthropic.php';
require_once SITEPROOF_DIR . 'includes/llm/class-siteproof-llm-openai.php';
require_once SITEPROOF_DIR . 'includes/llm/class-siteproof-llm-openrouter.php';
require_once SITEPROOF_DIR . 'includes/llm/class-siteproof-llm-wordpress.php';
require_once SITEPROOF_DIR . 'includes/class-siteproof-review.php';
require_once SITEPROOF_DIR . 'includes/class-siteproof-report.php';
require_once SITEPROOF_DIR . 'includes/class-siteproof-fixer.php';
require_once SITEPROOF_DIR . 'includes/class-siteproof-rest.php';
require_once SITEPROOF_DIR . 'includes/class-siteproof-admin.php';

register_activation_hook( __FILE__, array( 'Siteproof_DB', 'install' ) );

add_action( 'plugins_loaded', array( 'Siteproof_DB', 'maybe_upgrade' ) );
add_action( 'init', 'siteproof_load_textdomain' );
add_action( 'init', 'siteproof_frame_mode' );
add_action( 'rest_api_init', array( 'Siteproof_REST', 'register' ) );

if ( is_admin() ) {
	Siteproof_Admin::init();
}

/**
 * Bundled translations (German); translate.wordpress.org language packs take precedence.
 */
function siteproof_load_textdomain() {
	load_plugin_textdomain( 'siteproof', false, dirname( plugin_basename( SITEPROOF_FILE ) ) . '/languages' );
}

/**
 * Page loaded inside the measuring iframe (?siteproof_frame=1): hide the admin bar so that
 * layout and "above the fold" match what a visitor sees. Only for users who may run audits.
 */
function siteproof_frame_mode() {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display toggle only, no data is changed.
	if ( isset( $_GET['siteproof_frame'] ) && current_user_can( SITEPROOF_CAP ) ) {
		add_filter( 'show_admin_bar', '__return_false' );
	}
}
