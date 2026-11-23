<?php
/**
 * 页面缓存：命中输出、缓冲、存储、过期与失效。
 *
 * @package VelocityCache
 */

defined( 'ABSPATH' ) || exit;

final class VC_Cache {

	/** @var VC_Cache|null */
	private static $instance = null;

	/** @var bool */
	private $buffering = false;

	/** @var bool */
	private $served = false;

	/**
	 * 初始化缓存钩子。
	 */
	public static function init() {
		$self = self::instance();

		add_action( 'wp', array( $self, 'handle_request' ), 1 );
		add_action( 'admin_bar_menu', array( $self, 'admin_bar_node' ), 100 );

		$content_hooks = array(
			'save_post',
			'deleted_post',
			'comment_post',
			'transition_comment_status',
		 'switch_theme',
			'wp_update_nav_menu',
			'customize_save_after',
			'activated_plugin',
			'deactivated_plugin',
			'update_option_permalink_structure',
		);

		foreach ( $content_hooks as $hook ) {
			add_action( $hook, array( $self, 'on_content_change' ), 20, 1 );
		}
	}

	/**
	 * 单例。
	 *
	 * @return VC_Cache
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * 每个前端请求入口：先尝试命中缓存，再开启输出缓冲。
	 */
	public function handle_request() {
		if ( ! VC_Tools::is_frontend() ) {
			return;
		}

		$settings = VC_Settings::get();

		if ( $this->should_cache( $settings ) && $this->serve( $settings ) ) {
			return;
		}

		$this->start_buffer();
	}

	/**
	 * 是否允许缓存当前请求。
	 *
	 * @param array $settings 设置。
	 * @return bool
	 */
	private function should_cache( $settings ) {
		if ( empty( $settings['enable_cache'] ) ) {
			return false;
		}

		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '/';
		$path = (string) parse_url( $uri, PHP_URL_PATH );

		if ( VC_Tools::match_rules( $path, $settings['exclude_urls'] ) || VC_Tools::match_rules( $uri, $settings['exclude_urls'] ) ) {
			return false;
		}

		if ( VC_Tools::has_excluded_cookie( $settings['exclude_cookies'] ) ) {
			return false;
		}

		if ( is_user_logged_in() && empty( $settings['cache_logged_in'] ) ) {
			return false;
		}

		if ( empty( $settings['cache_query'] ) && ! empty( $_GET ) ) {
			return false;
		}

		// WooCommerce 等动态页面始终跳过。
		foreach ( array( '/cart', '/checkout', '/my-account' ) as $fragment ) {
			if ( false !== strpos( $path, $fragment ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * 输出缓存命中内容。成功时结束请求。
	 *
	 * @param array $settings 设置。
	 * @return bool
	 */
	private function serve( $settings ) {
		$key  = VC_Tools::request_key( ! empty( $settings['cache_query'] ) );
		$file = self::key_file( $key );

		if ( ! is_file( $file ) ) {
			return false;
		}

		$ttl = (int) $settings['cache_ttl'];
		if ( $ttl > 0 && time() - (int) filemtime( $file ) > $ttl ) {
			@unlink( $file );
			return false;
		}

		$html = @file_get_contents( $file );
		if ( false === $html || '' === $html ) {
			return false;
		}

		if ( ! headers_sent() ) {
			header( 'Content-Type: text/html; charset=' . get_option( 'blog_charset' ) );
			header( 'X-VC-Cache: HIT' );
			header( $ttl > 0 ? 'Cache-Control: public, max-age=' . $ttl : 'Cache-Control: public' );
		}

		$this->served = true;
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 已缓存的本机 HTML。

		if ( function_exists( 'fastcgi_finish_request' ) ) {
			@fastcgi_finish_request();
		}

		exit;
	}

	/**
	 * 开启输出缓冲，缓冲回调负责优化与写缓存。
	 */
	private function start_buffer() {
		if ( $this->buffering ) {
			return;
		}

		$this->buffering = true;
		ob_start( array( $this, 'finalize' ) );
	}

	/**
	 * 输出缓冲回调：优化 HTML 并写缓存。
	 *
	 * @param string $html 原始 HTML。
	 * @return string
	 */
	public function finalize( $html ) {
		if ( $this->served || ! is_string( $html ) || '' === $html ) {
			return $html;
		}

		try {
			$settings = VC_Settings::get();
			$output   = VC_Optimizer::process( $html, $settings );

			if ( '' !== $output && $this->should_cache( $settings ) ) {
				self::store( $output, $settings );
			}

			return '' === $output ? $html : $output;
		} catch ( \Throwable $e ) {
			VC_Tools::log( 'frontend optimization failed: ' . $e->getMessage() );
			return $html;
		}
	}

	/**
	 * 写入页面缓存。
	 *
	 * @param string $html     HTML 内容。
	 * @param array  $settings 设置。
	 */
	private static function store( $html, $settings ) {
		$key  = VC_Tools::request_key( ! empty( $settings['cache_query'] ) );
		$file = self::key_file( $key );

		if ( is_file( $file ) ) {
			return;
		}

		$result = VC_Tools::write_file( $file, $html );
		if ( is_wp_error( $result ) ) {
			VC_Tools::log( 'page cache write failed', array( 'error' => $result->get_error_message() ) );
		}
	}

	/**
	 * 根据缓存键生成分层文件路径。
	 *
	 * @param string $key 缓存键。
	 * @return string
	 */
	private static function key_file( $key ) {
		$key = preg_replace( '/[^a-f0-9]/', '', $key );

		return VC_Tools::sub_path( 'pages', substr( $key, 0, 2 ) . '/' . substr( $key, 2, 2 ) . '/' . $key . '.html' );
	}

	/**
	 * 清理缓存。
	 *
	 * @param bool $with_assets 是否同时清理优化后的资源文件。
	 * @return true|WP_Error
	 */
	public static function purge_all( $with_assets = true ) {
		$result = VC_Tools::purge_dir( 'pages' );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( $with_assets ) {
			$result = VC_Tools::purge_dir( 'assets' );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		set_transient( 'vc_last_purge', time(), DAY_IN_SECONDS );
		VC_Tools::log( 'cache purge', array( 'assets' => $with_assets ? 1 : 0 ) );

		return true;
	}

	/**
	 * 递增资源版本号并清理全部缓存。
	 *
	 * @return true|WP_Error
	 */
	public static function bump_version() {
		$settings = VC_Settings::get();
		$settings['asset_version'] = (int) $settings['asset_version'] + 1;

		update_option( VC_Settings::OPTION, $settings );

		$result = self::purge_all( true );
		if ( ! is_wp_error( $result ) ) {
			VC_Tools::log( 'asset version bumped', array( 'version' => $settings['asset_version'] ) );
		}

		return $result;
	}

	/**
	 * 内容变化时让页面缓存失效。
	 *
	 * @param mixed $arg 钩子参数。
	 */
	public function on_content_change( $arg = null ) {
		if ( is_numeric( $arg ) ) {
			$post_id = (int) $arg;
			if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
				return;
			}
		}

		self::purge_all( false );
	}

	/**
	 * 前台管理栏增加清理按钮。
	 *
	 * @param WP_Admin_Bar $bar 管理栏对象。
	 */
	public function admin_bar_node( $bar ) {
		if ( ! current_user_can( 'manage_options' ) || is_admin() ) {
			return;
		}

		$url = wp_nonce_url( admin_url( 'admin-post.php?action=vc_purge_cache&scope=pages' ), 'vc_purge_cache' );

		$bar->add_node(
			array(
				'id'    => 'vc-purge-cache',
				'title' => esc_html__( '清理页面缓存', 'velocity-cache' ),
				'href'  => $url,
			)
		);
	}
}
