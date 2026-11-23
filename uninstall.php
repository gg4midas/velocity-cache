<?php
/**
 * 卸载清理：仅在 WordPress 正式卸载插件时执行。
 *
 * @package VelocityCache
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'vc_settings' );
delete_option( 'vc_preload_queue' );
delete_transient( 'vc_admin_notice' );
delete_transient( 'vc_last_purge' );
delete_transient( 'vc_preload_lock' );
delete_transient( 'vc_last_preload' );

foreach ( array( 'vc_preload_batch', 'vc_preload_build' ) as $hook ) {
	$timestamp = wp_next_scheduled( $hook );
	if ( $timestamp ) {
		wp_unschedule_event( $timestamp, $hook );
	}
}

$upload_dir = wp_upload_dir();
$cache_root = WP_CONTENT_DIR . '/cache/velocity-cache';
$cache_root = wp_normalize_path( $cache_root );

/**
 * 只删除本插件缓存根目录下的内容，防止路径逃逸。
 *
 * @param string $dir   待删除目录。
 * @param string $root  允许的根目录。
 * @return void
 */
function vc_uninstall_remove_dir( $dir, $root ) {
	$dir = wp_normalize_path( $dir );
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
			vc_uninstall_remove_dir( $path, $root );
		} else {
			wp_delete_file( $path );
		}
	}

	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Removing an empty directory inside the plugin-owned cache root.
	@rmdir( $dir );
}

vc_uninstall_remove_dir( $cache_root, $cache_root );

// 兼容旧版本可能留下的上传目录缓存。
$legacy_root = wp_normalize_path( $upload_dir['basedir'] . '/velocity-cache' );
vc_uninstall_remove_dir( $legacy_root, $legacy_root );
