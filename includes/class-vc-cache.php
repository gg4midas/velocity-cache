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

		// 全部功能关闭时不启用输出缓冲，避免无意义的内存与回调开销。
		if ( empty( $settings['enable_cache'] ) && empty( $settings['enable_html_minify'] ) && empty( $settings['enable_css_minify'] ) && empty( $settings['enable_css_combine'] ) && empty( $settings['enable_js_minify'] ) ) {
			return;
		}

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
		if ( empty( $settings['enable_cache'] ) || ( defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE ) ) {
			return false;
		}

		$status = http_response_code();
		if ( false !== $status && 200 !== (int) $status ) {
			return false;
		}

		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';
		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );

		if ( VC_Tools::match_rules( $path, $settings['exclude_urls'] ) || VC_Tools::match_rules( $uri, $settings['exclude_urls'] ) ) {
			return false;
		}

		if ( VC_Tools::has_excluded_cookie( $settings['exclude_cookies'] ) ) {
			return false;
		}

		if ( is_user_logged_in() && empty( $settings['cache_logged_in'] ) ) {
			return false;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only check; query parameters are never processed as form data.
		if ( empty( $settings['cache_query'] ) && ! empty( $_GET ) ) {
			return false;
		}

		// WooCommerce 动态页面始终跳过，兼容本地化 URL 与账户端点。
		if ( function_exists( 'is_cart' ) && is_cart() ) {
			return false;
		}
		if ( function_exists( 'is_checkout' ) && is_checkout() ) {
			return false;
		}
		if ( function_exists( 'is_account_page' ) && is_account_page() ) {
			return false;
		}
		if ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url() ) {
			return false;
		}

		// 未安装 WooCommerce 时保留常见路径兜底。
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
			self::delete_variants( $file );
			return false;
		}

		$serve_file = $file;
		$encoding   = '';
		$accept     = isset( $_SERVER['HTTP_ACCEPT_ENCODING'] ) ? strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_ACCEPT_ENCODING'] ) ) ) : '';

		if ( ! empty( $settings['enable_compression'] ) ) {
			if ( false !== strpos( $accept, 'br' ) && is_file( $file . '.br' ) ) {
				$serve_file = $file . '.br';
				$encoding   = 'br';
			} elseif ( false !== strpos( $accept, 'gzip' ) && is_file( $file . '.gz' ) ) {
				$serve_file = $file . '.gz';
				$encoding   = 'gzip';
			}
		}

		if ( ! is_file( $serve_file ) || filesize( $serve_file ) < 1 ) {
			return false;
		}

		$mtime = (int) filemtime( $file );
		$etag  = ! empty( $settings['enable_304'] ) ? '"' . md5_file( $serve_file ) . '"' : '';

		if ( ! headers_sent() ) {
			$visibility = is_user_logged_in() ? 'private' : 'public';
			header( 'Content-Type: text/html; charset=' . get_option( 'blog_charset' ) );
			header( 'X-VC-Cache: HIT' );
			header( $ttl > 0 ? 'Cache-Control: ' . $visibility . ', max-age=' . $ttl : 'Cache-Control: ' . $visibility );
			if ( ! empty( $settings['enable_304'] ) ) {
				header( 'ETag: ' . $etag );
				header( 'Last-Modified: ' . gmdate( 'D, d M Y H:i:s', $mtime ) . ' GMT' );
			}
			if ( ! empty( $settings['enable_compression'] ) ) {
				header( 'Vary: Accept-Encoding' );
			}
			if ( $encoding ) {
				header( 'Content-Encoding: ' . $encoding );
			}
		}

		if ( ! empty( $settings['enable_304'] ) ) {
			$if_none_match = isset( $_SERVER['HTTP_IF_NONE_MATCH'] ) ? str_replace( '\\', '', trim( sanitize_text_field( wp_unslash( $_SERVER['HTTP_IF_NONE_MATCH'] ) ) ) ) : '';
			$not_modified  = $if_none_match && ( '*' === $if_none_match || false !== strpos( $if_none_match, $etag ) );

			if ( ! $if_none_match && isset( $_SERVER['HTTP_IF_MODIFIED_SINCE'] ) ) {
				$since        = strtotime( sanitize_text_field( wp_unslash( $_SERVER['HTTP_IF_MODIFIED_SINCE'] ) ) );
				$not_modified = false !== $since && $since >= $mtime;
			}

			if ( $not_modified ) {
				status_header( 304 );
				$this->served = true;
				exit;
			}
		}

		if ( ! headers_sent() ) {
			header( 'Content-Length: ' . filesize( $serve_file ) );
		}
		$this->served = true;
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- Streaming cached HTML avoids loading the whole page into memory.
		readfile( $serve_file );

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
		if ( $this->served || ! is_string( $html ) || '' === $html || ! VC_Tools::is_html_response() ) {
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
			return;
		}

		if ( ! empty( $settings['enable_compression'] ) ) {
			if ( function_exists( 'gzencode' ) ) {
				$compressed = gzencode( $html, 6 );
				if ( false !== $compressed ) {
					VC_Tools::write_file( $file . '.gz', $compressed );
				}
			}
			if ( function_exists( 'brotli_compress' ) ) {
				$compressed = brotli_compress( $html, 6 );
				if ( false !== $compressed ) {
					VC_Tools::write_file( $file . '.br', $compressed );
				}
			}
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
	 * 删除页面缓存及其压缩变体。
	 *
	 * @param string $file 原始 HTML 文件。
	 */
	private static function delete_variants( $file ) {
		foreach ( array( '', '.gz', '.br' ) as $suffix ) {
			if ( is_file( $file . $suffix ) ) {
				wp_delete_file( $file . $suffix );
			}
		}
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
		VC_Tools::ensure_dirs();
		VC_Tools::log( 'cache purge', array( 'assets' => $with_assets ? 1 : 0 ) );

		$settings = VC_Settings::get();
		if ( ! empty( $settings['enable_preload'] ) && class_exists( 'VC_Preloader' ) ) {
			VC_Preloader::schedule_async();
		}

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

		$settings = VC_Settings::get();
		if ( is_numeric( $arg ) && ! empty( $settings['enable_selective_purge'] ) && in_array( current_filter(), array( 'save_post', 'deleted_post' ), true ) ) {
			self::purge_post( (int) $arg );
			return;
		}

		self::purge_all( false );
	}

	/**
	 * 清理一个站内 URL 的匿名页面缓存及压缩变体。
	 *
	 * @param string $url 站内 URL。
	 * @return bool 是否找到并删除缓存。
	 */
	public static function purge_url( $url ) {
		if ( ! is_string( $url ) || '' === $url ) {
			return false;
		}

		$key  = VC_Tools::url_key( $url, false );
		$file = $key ? self::key_file( $key ) : '';
		if ( '' === $file || ! is_file( $file ) ) {
			return false;
		}

		self::delete_variants( $file );
		return true;
	}

	/**
	 * 清理文章内容变化直接影响的页面，避免每次编辑都全站清空。
	 *
	 * @param int $post_id 文章 ID。
	 */
	private static function purge_post( $post_id ) {
		$links = array( get_permalink( $post_id ), home_url( '/' ) );
		$post  = get_post( $post_id );

		if ( $post ) {
			$archive = get_post_type_archive_link( $post->post_type );
			if ( $archive ) {
				$links[] = $archive;
			}

			foreach ( get_object_taxonomies( $post ) as $taxonomy ) {
				$terms = get_the_terms( $post_id, $taxonomy );
				if ( ! is_array( $terms ) ) {
					continue;
				}
				foreach ( $terms as $term ) {
					$link = get_term_link( $term );
					if ( ! is_wp_error( $link ) ) {
						$links[] = $link;
					}
				}
			}

			$links[] = get_author_posts_url( (int) $post->post_author );
			$time    = strtotime( $post->post_date );
			$links[] = get_year_link( gmdate( 'Y', $time ) );
			$links[] = get_month_link( gmdate( 'Y', $time ), gmdate( 'm', $time ) );
			$links[] = get_day_link( gmdate( 'Y', $time ), gmdate( 'm', $time ), gmdate( 'd', $time ) );
		}

		$purged = 0;
		foreach ( array_unique( array_filter( $links ) ) as $url ) {
			if ( self::purge_url( $url ) ) {
				$purged++;
			}
		}

		set_transient( 'vc_last_purge', time(), DAY_IN_SECONDS );
		VC_Tools::log( 'selective cache purge', array( 'post_id' => $post_id, 'files' => $purged ) );
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
