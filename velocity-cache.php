<?php
/**
 * Plugin Name:       Velocity Cache
 * Plugin URI:        https://github.com/gg4midas/velocity-cache
 * Description:       轻量级 WordPress 性能优化插件：页面缓存、HTML 压缩、CSS 压缩与合并、JavaScript 压缩，带版本控制、排除规则与缓存清理。
 * Version:           1.3.0
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

define( 'VC_VERSION', '1.3.0' );
define( 'VC_FILE', __FILE__ );
define( 'VC_PATH', plugin_dir_path( __FILE__ ) );
define( 'VC_URL', plugin_dir_url( __FILE__ ) );

if ( PHP_VERSION_ID < 70400 ) {
	add_action(
		'admin_notices',
		static function () {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Velocity Cache 需要 PHP 7.4 或更高版本，插件已停止加载。', 'velocity-cache' ) . '</p></div>';
		}
	);
	return;
}

require_once VC_PATH . 'includes/class-vc-tools.php';
require_once VC_PATH . 'includes/class-vc-settings.php';
require_once VC_PATH . 'includes/class-vc-cache.php';
require_once VC_PATH . 'includes/class-vc-preloader.php';
require_once VC_PATH . 'includes/class-vc-optimizer.php';
require_once VC_PATH . 'includes/class-vc-admin.php';
require_once VC_PATH . 'includes/class-vc-plugin.php';

register_activation_hook( __FILE__, array( 'VC_Tools', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'VC_Tools', 'deactivate' ) );
register_deactivation_hook( __FILE__, array( 'VC_Preloader', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'VC_Plugin', 'instance' ) );
