<?php
/**
 * 插件启动器。
 *
 * @package VelocityCache
 */

defined( 'ABSPATH' ) || exit;

final class VC_Plugin {

	/** @var VC_Plugin|null */
	private static $instance = null;

	/**
	 * 单例入口，由 plugins_loaded 触发。
	 *
	 * @return VC_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->boot();
		}

		return self::$instance;
	}

	/**
	 * 初始化各模块。
	 */
	private function boot() {
		load_plugin_textdomain( 'velocity-cache', false, dirname( plugin_basename( VC_FILE ) ) . '/languages' );

		VC_Cache::init();
		VC_Preloader::init();

		$admin = new VC_Admin();
		$admin->init();
	}
}
