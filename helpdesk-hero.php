<?php
/**
 * Plugin Name:       Helpdesk Hero
 * Plugin URI:        https://wordpress.org/plugins/helpdesk-hero/
 * Description:       Get help from your WordPress support team: open tickets with your site's diagnostics attached, give support a login that works once and expires, and see everything they changed. Connects to your support team's Helpdesk Hero Hub.
 * Version:           2.1.0
 * Requires at least: 6.5
 * Requires PHP:      7.4
 * Author:            George Stathopoulos
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       helpdesk-hero
 *
 * @package Helpdesk_Hero
 */

defined( 'ABSPATH' ) || exit;

define( 'HELPDESK_HERO_VERSION', '2.1.0' );
define( 'HELPDESK_HERO_FILE', __FILE__ );
define( 'HELPDESK_HERO_DIR', plugin_dir_path( __FILE__ ) );
define( 'HELPDESK_HERO_URL', plugin_dir_url( __FILE__ ) );

require_once HELPDESK_HERO_DIR . 'includes/class-db.php';
require_once HELPDESK_HERO_DIR . 'includes/class-settings.php';
require_once HELPDESK_HERO_DIR . 'includes/class-policy.php';
require_once HELPDESK_HERO_DIR . 'includes/class-redactor.php';
require_once HELPDESK_HERO_DIR . 'includes/class-monitor.php';
require_once HELPDESK_HERO_DIR . 'includes/class-diagnostics.php';
require_once HELPDESK_HERO_DIR . 'includes/class-health-flags.php';
require_once HELPDESK_HERO_DIR . 'includes/class-access.php';
require_once HELPDESK_HERO_DIR . 'includes/class-activity.php';
require_once HELPDESK_HERO_DIR . 'includes/class-safe-mode.php';
require_once HELPDESK_HERO_DIR . 'includes/class-crypto.php';
require_once HELPDESK_HERO_DIR . 'includes/class-signer.php';
require_once HELPDESK_HERO_DIR . 'includes/class-tickets.php';
require_once HELPDESK_HERO_DIR . 'includes/class-attachments.php';
require_once HELPDESK_HERO_DIR . 'includes/class-connection.php';
require_once HELPDESK_HERO_DIR . 'includes/class-ai.php';
require_once HELPDESK_HERO_DIR . 'includes/class-client-rest.php';
require_once HELPDESK_HERO_DIR . 'includes/class-notices.php';
require_once HELPDESK_HERO_DIR . 'includes/class-privacy.php';
require_once HELPDESK_HERO_DIR . 'includes/class-admin-rest.php';
require_once HELPDESK_HERO_DIR . 'includes/class-admin.php';
require_once HELPDESK_HERO_DIR . 'includes/class-pinpoint.php';
require_once HELPDESK_HERO_DIR . 'includes/class-plugin.php';

register_activation_hook( __FILE__, array( 'Helpdesk_Hero_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Helpdesk_Hero_Plugin', 'deactivate' ) );

Helpdesk_Hero_Plugin::instance();
