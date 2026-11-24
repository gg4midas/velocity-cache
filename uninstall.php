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
	delete_option( 'veloca_settings' );
	delete_option( 'veloca_cache_secret' );
	delete_option( 'veloca_legacy_cleaned' );
	delete_option( 'veloca_preload_queue' );
	delete_transient( 'veloca_admin_notice' );
	delete_transient( 'veloca_last_purge' );
	delete_transient( 'veloca_preload_lock' );
	delete_transient( 'veloca_last_preload' );

	foreach ( array( 'veloca_preload_batch', 'veloca_preload_build' ) as $hook ) {
		$timestamp = wp_next_scheduled( $hook );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, $hook );
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
