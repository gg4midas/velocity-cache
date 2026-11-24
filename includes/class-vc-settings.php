<?php
/**
 * 设置项：默认值、读取、过滤与版本递增。
 *
 * @package VelocityCache
 */

defined( 'ABSPATH' ) || exit;

final class VC_Settings {

	const OPTION = 'vc_settings';
	const GROUP  = 'vc_settings_group';
	const PAGE   = 'velocity-cache';

	/**
	 * 默认配置。
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'enable_cache'      => 1,
			'enable_html_minify'=> 1,
			'enable_css_minify' => 1,
			'enable_css_combine'=> 0,
			'enable_js_minify'  => 1,
			'enable_preload'    => 0,
			'enable_selective_purge' => 0,
			'enable_304'        => 0,
			'enable_compression'=> 0,
			'enable_lazyload'   => 0,
			'lazyload_iframes'  => 0,
			'lazyload_skip'     => 2,
			'enable_js_defer'   => 0,
			'enable_js_delay'   => 0,
			'enable_delay_third_party' => 0,
			'enable_async_css'  => 0,
			'async_css_excludes' => '*astra-theme-css*',
		'critical_css'      => '',
		'enable_conditional_css' => 0,
		'conditional_css_rules' => "woocommerce-general | class=\"woocommerce, woocommerce-page, class=\"wc-\nwoocommerce-layout | class=\"woocommerce, woocommerce-page, class=\"wc-\nwoocommerce-smallscreen | class=\"woocommerce, woocommerce-page, class=\"wc-\nwpforms-classic-full | wpforms-container",
		'enable_cdn'        => 0,
			'cdn_url'           => '',
			'preload_urls'      => '',
			'delay_js_handles'  => '',
			'cache_ttl'         => 3600,
			'cache_logged_in'   => 0,
			'cache_query'       => 0,
			'log_errors'        => 1,
			'exclude_urls'      => "/wp-admin/\n/wp-login.php\n/wp-json/\n/xmlrpc.php\n/feed/",
			'exclude_cookies'   => "wordpress_logged_in_*\nwp_woocommerce_session_*",
			'exclude_css_handles' => '',
			'exclude_js_handles'  => '',
			'asset_version'     => 1,
		);
	}

	/**
	 * 读取并规范化设置。
	 *
	 * @return array
	 */
	public static function get() {
		$stored = get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		return wp_parse_args( $stored, self::defaults() );
	}

	/**
	 * 注册设置（Settings API），并挂载变更后的缓存刷新。
	 */
	public static function register() {
		register_setting(
			self::GROUP,
			self::OPTION,
			array(
				'type'              => 'array',
				'group'             => self::GROUP,
				'description'       => 'Velocity Cache 配置',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'show_in_rest'      => false,
				'default'           => self::defaults(),
			)
		);

		add_action( 'update_option_' . self::OPTION, array( __CLASS__, 'after_update' ), 10, 2 );
	}

	/**
	 * 过滤与校验输入。配置发生变化时自动递增资源版本号。
	 *
	 * @param mixed $input 原始输入。
	 * @return array
	 */
	public static function sanitize( $input ) {
		$input     = is_array( $input ) ? $input : array();
		$defaults  = self::defaults();
		$current   = self::get();
		$clean     = array();

		$switches = array(
			'enable_cache',
			'enable_html_minify',
			'enable_css_minify',
			'enable_css_combine',
			'enable_js_minify',
			'enable_preload',
			'enable_selective_purge',
			'enable_304',
			'enable_compression',
			'enable_lazyload',
			'lazyload_iframes',
			'enable_js_defer',
			'enable_js_delay',
			'enable_delay_third_party',
			'enable_async_css',
			'enable_conditional_css',
			'enable_cdn',
			'cache_logged_in',
			'cache_query',
			'log_errors',
		);

		foreach ( $switches as $key ) {
			$clean[ $key ] = empty( $input[ $key ] ) ? 0 : 1;
		}

		$ttl           = isset( $input['cache_ttl'] ) ? absint( $input['cache_ttl'] ) : $defaults['cache_ttl'];
		$clean['cache_ttl'] = min( 2592000, max( 0, $ttl ) );

		$clean['lazyload_skip'] = isset( $input['lazyload_skip'] ) ? min( 20, absint( $input['lazyload_skip'] ) ) : $defaults['lazyload_skip'];
		$cdn_url = isset( $input['cdn_url'] ) && is_scalar( $input['cdn_url'] ) ? esc_url_raw( trim( (string) $input['cdn_url'] ) ) : '';
		$clean['cdn_url'] = $cdn_url ? untrailingslashit( $cdn_url ) : '';

		$critical_css = isset( $input['critical_css'] ) && is_scalar( $input['critical_css'] ) ? (string) $input['critical_css'] : '';
		$critical_css = preg_replace( '/<\/?style\b[^>]*>/i', '', $critical_css );
		$clean['critical_css'] = trim( substr( $critical_css, 0, 20480 ) );

		foreach ( array( 'exclude_urls', 'exclude_cookies', 'exclude_css_handles', 'exclude_js_handles', 'preload_urls', 'delay_js_handles', 'async_css_excludes', 'conditional_css_rules' ) as $key ) {
			$raw = isset( $input[ $key ] ) ? $input[ $key ] : '';
			$clean[ $key ] = implode( "\n", VC_Tools::rule_list( is_scalar( $raw ) ? $raw : '' ) );
		}

		// 配置变化时递增版本号；仅版本号变化时不重复递增。
		$incoming_version = isset( $input['asset_version'] ) ? absint( $input['asset_version'] ) : 0;
		unset( $current['asset_version'] );
		$compare = $clean;
		ksort( $compare );
		ksort( $current );

		$stored     = get_option( self::OPTION, array() );
		$old_version = is_array( $stored ) && isset( $stored['asset_version'] ) ? absint( $stored['asset_version'] ) : 1;

		if ( $compare === $current ) {
			$clean['asset_version'] = max( 1, $incoming_version ? $incoming_version : $old_version );
		} else {
			$clean['asset_version'] = $old_version + 1;
		}

		return $clean;
	}

	/**
	 * 配置变更后刷新缓存；不在此处再次写选项，避免递归。
	 *
	 * @param mixed $old_value 旧值。
	 * @param mixed $new_value 新值。
	 */
	public static function after_update( $old_value, $new_value ) {
		VC_Tools::ensure_dirs();
		VC_Cache::purge_all( false );
		VC_Tools::log( 'settings updated, caches purged' );
	}
}
