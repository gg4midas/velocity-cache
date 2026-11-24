<?php
/**
 * 卸载清理：仅在 WordPress 正式卸载插件时执行。
 *
 * @package VelocityCache
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * 只删除本插件缓存根目录下的内容，防止路径逃逸。
 *
 * @param string $dir   待删除目录。
 * @param string $root  允许的根目录。
 * @return void
 */
function veloca_uninstall_remove_dir( $dir, $root ) {
	$dir  = wp_normalize_path( $dir );
	$root = rtrim( $root, '/' );

	if ( ( $dir !== $root && strpos( $dir, $root . '/' ) !== 0 ) || ! is_dir( $dir ) ) {
		return;
	}

	$items = @scandir( $dir );
	if ( ! is_array( $items ) ) {
		return;
	}

	foreach ( $items as $item ) {
		if ( '.' === $item || '..' === $item ) {
			continue;
		}

		$path = wp_normalize_path( $dir . '/' . $item );
		if ( is_dir( $path ) && ! is_link( $path ) ) {
			veloca_uninstall_remove_dir( $path, $root );
		} else {
			wp_delete_file( $path );
		}
	}

	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Removing an empty directory inside the plugin-owned cache root.
	@rmdir( $dir );
}

/**
 * 执行卸载清理，所有局部变量限定在函数作用域内。
 *
 * @return void
 */
function veloca_uninstall_cleanup() {
	foreach ( array( 'veloca_settings', 'veloca_cache_secret', 'veloca_legacy_cleaned', 'veloca_legacy_migrated', 'veloca_cookie_rules_migrated', 'veloca_preload_queue' ) as $option ) {
		delete_option( $option );
	}

	foreach ( array( 'veloca_admin_notice', 'veloca_plugin_notice', 'veloca_last_purge', 'veloca_preload_lock', 'veloca_last_preload', 'veloca_preload_heal_check' ) as $transient ) {
		delete_transient( $transient );
	}

	// 1.5.3 起的事件带随机参数，必须按事件实际参数逐个删除。
	$cron = _get_cron_array();
	if ( is_array( $cron ) ) {
		foreach ( $cron as $timestamp => $events ) {
			foreach ( array( 'veloca_preload_batch', 'veloca_preload_build' ) as $hook ) {
				if ( ! isset( $events[ $hook ] ) || ! is_array( $events[ $hook ] ) ) {
					continue;
				}
				foreach ( $events[ $hook ] as $event ) {
					$args = isset( $event['args'] ) && is_array( $event['args'] ) ? $event['args'] : array();
					wp_unschedule_event( $timestamp, $hook, $args );
				}
			}
		}
	}

	$upload_dir = wp_upload_dir();
	$cache_root = wp_normalize_path( WP_CONTENT_DIR . '/cache/velocity-cache' );
	veloca_uninstall_remove_dir( $cache_root, $cache_root );

	// 兼容旧版本可能留下的上传目录缓存。
	$legacy_root = wp_normalize_path( $upload_dir['basedir'] . '/velocity-cache' );
	veloca_uninstall_remove_dir( $legacy_root, $legacy_root );
}

veloca_uninstall_cleanup();
