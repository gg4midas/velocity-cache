<?php
/**
 * 插件启动器。
 *
 * @package VelocityCache
 */

defined( 'ABSPATH' ) || exit;

final class VELOCA_Plugin {

	/** @var VELOCA_Plugin|null */
	private static $instance = null;

	/**
	 * 单例入口，由 plugins_loaded 触发。
	 *
	 * @return VELOCA_Plugin
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
		// WordPress.org loads translations for the plugin text domain automatically.
		VELOCA_Tools::cleanup_legacy();
		VELOCA_Cache::init();
		VELOCA_Preloader::init();

		$admin = new VELOCA_Admin();
		$admin->init();
	}
}
