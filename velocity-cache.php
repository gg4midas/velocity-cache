<?php
/**
 * Plugin Name:       Velocity Cache
 * Plugin URI:        https://github.com/gg4midas/velocity-cache
 * Description:       Lightweight caching and performance plugin: page cache, HTML/CSS/JS minification, CSS combination, preloading, and cache purging.
 * Version:           1.5.4
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            gg4midas
 * Author URI:        https://github.com/gg4midas
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       velocity-cache
 * Domain Path:       /languages
 *
 * @package VelocityCache
 */

defined( 'ABSPATH' ) || exit;

define( 'VELOCA_VERSION', '1.5.4' );
define( 'VELOCA_FILE', __FILE__ );
define( 'VELOCA_PATH', plugin_dir_path( __FILE__ ) );
define( 'VELOCA_URL', plugin_dir_url( __FILE__ ) );

if ( PHP_VERSION_ID < 70400 ) {
	add_action(
		'admin_notices',
		static function () {
			global $pagenow;
			if ( 'plugins.php' !== $pagenow ) {
				return;
			}
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Velocity Cache requires PHP 7.4 or higher; the plugin has stopped loading.', 'velocity-cache' ) . '</p></div>';
		}
	);
	return;
}

require_once VELOCA_PATH . 'includes/class-veloca-tools.php';
require_once VELOCA_PATH . 'includes/class-veloca-settings.php';
require_once VELOCA_PATH . 'includes/class-veloca-cache.php';
require_once VELOCA_PATH . 'includes/class-veloca-preloader.php';
require_once VELOCA_PATH . 'includes/class-veloca-optimizer.php';
require_once VELOCA_PATH . 'includes/class-veloca-admin.php';
require_once VELOCA_PATH . 'includes/class-veloca-plugin.php';

register_activation_hook( __FILE__, array( 'VELOCA_Tools', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'VELOCA_Tools', 'deactivate' ) );
register_deactivation_hook( __FILE__, array( 'VELOCA_Preloader', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'VELOCA_Plugin', 'instance' ) );
