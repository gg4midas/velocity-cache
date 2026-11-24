<?php
/**
 * 页面缓存：命中输出、缓冲、存储、过期与失效。
 *
 * @package VelocityCache
 */

defined( 'ABSPATH' ) || exit;

final class VELOCA_Cache {

	/** @var VELOCA_Cache|null */
	private static $instance = null;

	/** @var bool */
	private $buffering = false;

	/** @var int */
	private $buffer_level = 0;

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
	 * @return VELOCA_Cache
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
		if ( ! VELOCA_Tools::is_frontend() ) {
			return;
		}

		$settings = VELOCA_Settings::get();

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

		if ( VELOCA_Tools::match_rules( $path, $settings['exclude_urls'] ) || VELOCA_Tools::match_rules( $uri, $settings['exclude_urls'] ) ) {
			return false;
		}

		if ( VELOCA_Tools::has_excluded_cookie( $settings['exclude_cookies'] ) ) {
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
		$key  = VELOCA_Tools::request_key( ! empty( $settings['cache_query'] ) );
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
			header( 'X-Veloca-Cache: HIT' );
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

		$this->buffering    = true;
		$this->buffer_level = ob_get_level();
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- The buffer is explicitly closed in flush_buffer() on shutdown.
		ob_start( array( $this, 'finalize' ) );
		add_action( 'shutdown', array( $this, 'flush_buffer' ), PHP_INT_MAX );
	}

	/**
	 * 在 shutdown 时显式关闭缓冲，避免留下未闭合的 buffer。
	 * 逐层刷新到本插件开启的层级，finalize 回调会处理完整 HTML。
	 */
	public function flush_buffer() {
		if ( ! $this->buffering ) {
			return;
		}

		$this->buffering = false;

		while ( ob_get_level() > $this->buffer_level ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Closing our own buffer; failures simply end the loop.
			if ( false === @ob_end_flush() ) {
				break;
			}
		}
	}

	/**
	 * 输出缓冲回调：优化 HTML 并写缓存。
	 *
	 * @param string $html 原始 HTML。
	 * @return string
	 */
	public function finalize( $html ) {
		if ( $this->served || ! is_string( $html ) || '' === $html || ! VELOCA_Tools::is_html_response() ) {
			return $html;
		}

		try {
			$settings = VELOCA_Settings::get();
			$output   = VELOCA_Optimizer::process( $html, $settings );

			if ( '' !== $output && $this->should_cache( $settings ) ) {
				self::store( $output, $settings );
			}

			return '' === $output ? $html : $output;
		} catch ( \Throwable $e ) {
			VELOCA_Tools::log( 'frontend optimization failed: ' . $e->getMessage() );
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
		$key  = VELOCA_Tools::request_key( ! empty( $settings['cache_query'] ) );
		$file = self::key_file( $key );

		if ( is_file( $file ) ) {
			return;
		}

		$result = VELOCA_Tools::write_file( $file, $html );
		if ( is_wp_error( $result ) ) {
			VELOCA_Tools::log( 'page cache write failed', array( 'error' => $result->get_error_message() ) );
			return;
		}

		if ( ! empty( $settings['enable_compression'] ) ) {
			if ( function_exists( 'gzencode' ) ) {
				$compressed = gzencode( $html, 6 );
				if ( false !== $compressed ) {
					VELOCA_Tools::write_file( $file . '.gz', $compressed );
				}
			}
			if ( function_exists( 'brotli_compress' ) ) {
				$compressed = brotli_compress( $html, 6 );
				if ( false !== $compressed ) {
					VELOCA_Tools::write_file( $file . '.br', $compressed );
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

		return VELOCA_Tools::sub_path( 'pages', substr( $key, 0, 2 ) . '/' . substr( $key, 2, 2 ) . '/' . $key . '.html' );
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
		$result = VELOCA_Tools::purge_dir( 'pages' );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( $with_assets ) {
			$result = VELOCA_Tools::purge_dir( 'assets' );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		set_transient( 'veloca_last_purge', time(), DAY_IN_SECONDS );
		VELOCA_Tools::ensure_dirs();
		VELOCA_Tools::log( 'cache purge', array( 'assets' => $with_assets ? 1 : 0 ) );

		$settings = VELOCA_Settings::get();
		if ( ! empty( $settings['enable_preload'] ) && class_exists( 'VELOCA_Preloader' ) ) {
			VELOCA_Preloader::schedule_async();
		}

		return true;
	}

	/**
	 * 递增资源版本号并清理全部缓存。
	 *
	 * @return true|WP_Error
	 */
	public static function bump_version() {
		$settings = VELOCA_Settings::get();
		$settings['asset_version'] = (int) $settings['asset_version'] + 1;

		update_option( VELOCA_Settings::OPTION, $settings );

		$result = self::purge_all( true );
		if ( ! is_wp_error( $result ) ) {
			VELOCA_Tools::log( 'asset version bumped', array( 'version' => $settings['asset_version'] ) );
		}

		return $result;
	}

	/**
	 * 内容变化时让页面缓存失效。
	 *
	 * @param mixed $arg 钩子参数。
	 */
	public function on_content_change( $arg = null ) {
		// 本插件自身停用时，跳过清理回调，避免重建目录和重新调度计划任务。
		if ( VELOCA_Tools::is_deactivating() ) {
			return;
		}

		if ( is_numeric( $arg ) ) {
			$post_id = (int) $arg;
			if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
				return;
			}
		}

		$settings = VELOCA_Settings::get();
		$hook     = current_filter();
		if ( is_numeric( $arg ) && ! empty( $settings['enable_selective_purge'] ) && in_array( $hook, array( 'save_post', 'deleted_post' ), true ) ) {
			self::purge_post( (int) $arg );
			return;
		}

		// 其他插件启用或停用也会清缓存，在插件列表页留一次性提示。
		if ( in_array( $hook, array( 'activated_plugin', 'deactivated_plugin' ), true ) ) {
			$result = self::purge_all( false );
			if ( ! is_wp_error( $result ) ) {
				$action = 'activated_plugin' === $hook ? __( 'activation', 'velocity-cache' ) : __( 'deactivation', 'velocity-cache' );
				/* translators: %s: activation or deactivation. */
				set_transient( 'veloca_plugin_notice', sprintf( __( 'Velocity Cache refreshed the page cache after a plugin %s.', 'velocity-cache' ), $action ), 300 );
			}
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

		$key  = VELOCA_Tools::url_key( $url, false );
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

			// 多语言：每种语言域名下的译文与归档都持有独立缓存，必须一并清理。
			$links = array_merge( $links, self::multilingual_sibling_links( $post ) );
		}

		$purged = 0;
		foreach ( array_unique( array_filter( $links ) ) as $url ) {
			if ( self::purge_url( $url ) ) {
				$purged++;
			}
		}

		set_transient( 'veloca_last_purge', time(), DAY_IN_SECONDS );
		VELOCA_Tools::log( 'selective cache purge', array( 'post_id' => $post_id, 'files' => $purged ) );
	}

	/**
	 * 找出每种语言下该文章的译文与归档 URL。
	 *
	 * 语言列表来自 VELOCA_Tools::language_urls()（WPML / Polylang / 第三方过滤），
	 * 译文 ID 通过 veloca_post_translations 过滤或 WPML trid / Polylang 官方函数取得，
	 * 不预设语言代码，也不限制语言数量。
	 *
	 * @param WP_Post $post 当前文章。
	 * @return array 待清理的 URL 列表。
	 */
	private static function multilingual_sibling_links( $post ) {
		$links     = array();
		$languages = VELOCA_Tools::language_urls();
		if ( ! $post || empty( $languages ) ) {
			return $links;
		}

		// 语言代码 => 译文文章 ID。第三方可用过滤器接入自定义多语言插件。
		$translations = array();
		if ( function_exists( 'pll_get_post_translations' ) ) {
			foreach ( (array) pll_get_post_translations( $post->ID ) as $code => $id ) {
				$translations[ sanitize_key( (string) $code ) ] = (int) $id;
			}
		}

		global $wpdb;
		$table = $wpdb->prefix . 'icl_translations';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table ) {
			$trid = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT trid FROM {$table} WHERE element_id = %d AND element_type = %s LIMIT 1",
					$post->ID,
					'post_' . $post->post_type
				)
			);
			if ( $trid ) {
				$rows = $wpdb->get_results( $wpdb->prepare( "SELECT element_id, language_code FROM {$table} WHERE trid = %d", $trid ) );
				foreach ( (array) $rows as $item ) {
					$translations[ sanitize_key( (string) $item->language_code ) ] = (int) $item->element_id;
				}
			}
		}

		/**
		 * 过滤译文映射：语言代码 => 文章 ID。
		 *
		 * @param array   $translations 已识别的译文。
		 * @param WP_Post $post         当前文章。
		 */
		$translations = apply_filters( 'veloca_post_translations', $translations, $post );
		if ( ! is_array( $translations ) ) {
			$translations = array();
		}

		// 经验证的文章 ID，避免第三方过滤器返回无效值。
		$valid_ids = array();
		foreach ( $translations as $code => $id ) {
			$id = (int) $id;
			if ( $id > 0 && get_post_type( $id ) === $post->post_type ) {
				$valid_ids[ sanitize_key( (string) $code ) ] = $id;
			}
		}

		$archive = get_post_type_archive_link( $post->post_type );
		$source  = get_permalink( $post->ID );

		foreach ( $languages as $code => $home_url ) {
			$links[] = rtrim( $home_url, '/' ) . '/';
			if ( $source ) {
				$links[] = VELOCA_Tools::localize_url( $source, $code, $languages );
			}
			if ( isset( $valid_ids[ $code ] ) ) {
				$translated_url = get_permalink( $valid_ids[ $code ] );
				if ( $translated_url ) {
					$links[] = VELOCA_Tools::localize_url( $translated_url, $code, $languages );
				}
			}
			if ( $archive ) {
				$links[] = VELOCA_Tools::localize_url( $archive, $code, $languages );
			}

			// 分类归档：取当前文章与译文文章上的全部 term，按语言定位。
			$term_ids = array();
			foreach ( array_unique( array_filter( array( $post->ID, isset( $valid_ids[ $code ] ) ? $valid_ids[ $code ] : 0 ) ) ) as $pid ) {
				foreach ( get_object_taxonomies( $post ) as $taxonomy ) {
					$terms = get_the_terms( $pid, $taxonomy );
					if ( ! is_array( $terms ) ) {
						continue;
					}
					foreach ( $terms as $term ) {
						$term_ids[ (int) $term->term_id ] = $term;
					}
				}
			}
			foreach ( $term_ids as $term ) {
				$term_link = get_term_link( $term );
				if ( ! is_wp_error( $term_link ) && $term_link ) {
					$links[] = VELOCA_Tools::localize_url( $term_link, $code, $languages );
				}
			}
		}

		return $links;
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

		$url = wp_nonce_url( admin_url( 'admin-post.php?action=veloca_purge_cache&scope=pages' ), 'veloca_purge_cache' );

		$bar->add_node(
			array(
				'id'    => 'veloca-purge-cache',
				'title' => esc_html__( 'Purge Page Cache', 'velocity-cache' ),
				'href'  => $url,
			)
		);
	}
}
