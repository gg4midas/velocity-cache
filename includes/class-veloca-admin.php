<?php
/**
 * 后台配置模块：设置页、操作处理、状态与提示。
 *
 * @package VelocityCache
 */

defined( 'ABSPATH' ) || exit;

final class VELOCA_Admin {

	/** @var string */
	private $page_hook = '';

	/**
	 * 挂载后台钩子。
	 */
	public function init() {
		add_action( 'admin_menu', array( $this, 'add_page' ) );
		add_action( 'init', array( 'VELOCA_Settings', 'register' ) );
		add_action( 'admin_init', array( $this, 'register_fields' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( VELOCA_FILE ), array( $this, 'plugin_action_links' ) );
		add_action( 'admin_post_veloca_purge_cache', array( $this, 'handle_purge' ) );
		add_action( 'admin_post_veloca_bump_version', array( $this, 'handle_bump' ) );
		add_action( 'admin_post_veloca_preload', array( $this, 'handle_preload' ) );
		add_action( 'admin_notices', array( $this, 'notices' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * 添加设置菜单。
	 */
	public function add_page() {
		$this->page_hook = add_options_page(
			__( 'Velocity Cache', 'velocity-cache' ),
			__( 'Velocity Cache', 'velocity-cache' ),
			'manage_options',
			VELOCA_Settings::PAGE,
			array( $this, 'render_page' )
		);
	}

	/**
	 * 在插件列表的操作列增加设置入口。
	 *
	 * @param array $links 现有操作链接。
	 * @return array
	 */
	public function plugin_action_links( $links ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return $links;
		}

		$url = admin_url( 'options-general.php?page=' . VELOCA_Settings::PAGE );
		array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'velocity-cache' ) . '</a>' );

		return $links;
	}

	/**
	 * 渲染设置页。
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to access this page.', 'velocity-cache' ), '', array( 'response' => 403 ) );
		}

		VELOCA_Tools::ensure_dirs();

		$settings = VELOCA_Settings::get();
		$status   = $this->status_rows();

		include VELOCA_PATH . 'admin/views/settings-page.php';
	}

	/**
	 * 注册设置区块与字段。
	 */
	public function register_fields() {
		add_settings_section(
			'veloca_section_cache',
			__( 'Cache', 'velocity-cache' ),
			static function () {
				echo '<p class="description">' . esc_html__( 'Cache only GET requests from anonymous visitors; dynamic pages and addresses in exclusion rules are not cached.', 'velocity-cache' ) . '</p>';
			},
			VELOCA_Settings::PAGE
		);

		add_settings_field(
			'enable_cache',
			__( 'Page Cache', 'velocity-cache' ),
			array( $this, 'checkbox_field' ),
			VELOCA_Settings::PAGE,
			'veloca_section_cache',
			array( 'key' => 'enable_cache', 'desc' => __( 'Generate static HTML and serve cache hits directly without loading the theme.', 'velocity-cache' ) )
		);

		add_settings_field(
			'cache_ttl',
			__( 'Cache Lifetime (Seconds)', 'velocity-cache' ),
			array( $this, 'number_field' ),
			VELOCA_Settings::PAGE,
			'veloca_section_cache',
			array( 'key' => 'cache_ttl', 'desc' => __( '0 means never expire, refreshing only when content changes. Maximum 2592000 seconds.', 'velocity-cache' ) )
		);

		add_settings_field(
			'cache_logged_in',
			__( 'Cache Logged-In Users', 'velocity-cache' ),
			array( $this, 'checkbox_field' ),
			VELOCA_Settings::PAGE,
			'veloca_section_cache',
			array( 'key' => 'cache_logged_in', 'desc' => __( 'Isolate cache by user ID; also remove the login cookie exclusion rule. Use with care on personalized pages.', 'velocity-cache' ) )
		);

		add_settings_field(
			'cache_query',
			__( 'Cache URLs With Query Strings', 'velocity-cache' ),
			array( $this, 'checkbox_field' ),
			VELOCA_Settings::PAGE,
			'veloca_section_cache',
			array( 'key' => 'cache_query', 'desc' => __( 'Off by default to avoid duplicate caches from parameters such as ?utm_.', 'velocity-cache' ) )
		);

		foreach (
			array(
				'enable_selective_purge' => array( __( 'Selective Purge', 'velocity-cache' ), __( 'When editing a post, purge only the post, homepage and related archives; when off, purge all page cache.', 'velocity-cache' ) ),
				'enable_304'             => array( __( '304 Conditional Requests', 'velocity-cache' ), __( 'Support ETag and Last-Modified, returning 304 when content is unchanged.', 'velocity-cache' ) ),
				'enable_compression'     => array( __( 'Brotli / Gzip Pre-compression', 'velocity-cache' ), __( 'Generate .br/.gz cache variants served according to Accept-Encoding; when extensions are unavailable, only supported formats are generated.', 'velocity-cache' ) ),
			) as $key => $labels
		) {
			add_settings_field( $key, $labels[0], array( $this, 'checkbox_field' ), VELOCA_Settings::PAGE, 'veloca_section_cache', array( 'key' => $key, 'desc' => $labels[1] ) );
		}

		add_settings_section(
			'veloca_section_preload',
			__( 'Cache Preloading', 'velocity-cache' ),
			static function () {
				echo '<p class="description">' . esc_html__( 'Manual preload uses custom URLs or reads the sitemap; automatic preload queues URLs after a full purge.', 'velocity-cache' ) . '</p>';
			},
			VELOCA_Settings::PAGE
		);
		add_settings_field( 'enable_preload', __( 'Automatic Preload', 'velocity-cache' ), array( $this, 'checkbox_field' ), VELOCA_Settings::PAGE, 'veloca_section_preload', array( 'key' => 'enable_preload', 'desc' => __( 'Preload in batches via WP-Cron after a full purge, 5 URLs per batch, up to 200.', 'velocity-cache' ) ) );
		add_settings_field( 'preload_urls', __( 'Preload URLs', 'velocity-cache' ), array( $this, 'textarea_field' ), VELOCA_Settings::PAGE, 'veloca_section_preload', array( 'key' => 'preload_urls', 'desc' => __( 'One full internal URL per line. Leave blank to read wp-sitemap.xml or sitemap_index.xml; the homepage is always included.', 'velocity-cache' ) ) );

		add_settings_section(
			'veloca_section_minify',
			__( 'Asset Optimization', 'velocity-cache' ),
			static function () {
				echo '<p class="description">' . esc_html__( 'External assets, module scripts, async/defer scripts and stylesheets containing @import are skipped automatically.', 'velocity-cache' ) . '</p>';
			},
			VELOCA_Settings::PAGE
		);

		add_settings_field(
			'enable_html_minify',
			__( 'HTML Minification', 'velocity-cache' ),
			array( $this, 'checkbox_field' ),
			VELOCA_Settings::PAGE,
			'veloca_section_minify',
			array( 'key' => 'enable_html_minify', 'desc' => __( 'Remove comments and extra whitespace while preserving pre/textarea/script/style.', 'velocity-cache' ) )
		);

		add_settings_field(
			'enable_css_minify',
			__( 'CSS Minification', 'velocity-cache' ),
			array( $this, 'checkbox_field' ),
			VELOCA_Settings::PAGE,
			'veloca_section_minify',
			array( 'key' => 'enable_css_minify', 'desc' => __( 'Minify local stylesheets and inline styles.', 'velocity-cache' ) )
		);

		add_settings_field(
			'enable_css_combine',
			__( 'CSS Combination', 'velocity-cache' ),
			array( $this, 'checkbox_field' ),
			VELOCA_Settings::PAGE,
			'veloca_section_minify',
			array( 'key' => 'enable_css_combine', 'desc' => __( 'Combine local stylesheets into one versioned file per media attribute.', 'velocity-cache' ) )
		);

		add_settings_field(
			'enable_js_minify',
			__( 'JavaScript Minification', 'velocity-cache' ),
			array( $this, 'checkbox_field' ),
			VELOCA_Settings::PAGE,
			'veloca_section_minify',
			array( 'key' => 'enable_js_minify', 'desc' => __( 'Minify local scripts and inline scripts, skipping module/async/defer.', 'velocity-cache' ) )
		);

		add_settings_section(
			'veloca_section_loading',
			__( 'Loading Optimization', 'velocity-cache' ),
			static function () {
				echo '<p class="description">' . esc_html__( 'LazyLoad uses native browser support; Defer and Delay JS may change script timing. Test on each site.', 'velocity-cache' ) . '</p>';
			},
			VELOCA_Settings::PAGE
		);
		foreach (
			array(
				'enable_lazyload'  => array( __( 'Image LazyLoad', 'velocity-cache' ), __( 'Add loading=lazy and decoding=async to images; skip images already having loading or no-lazy/skip-lazy markers.', 'velocity-cache' ) ),
				'lazyload_iframes' => array( __( 'iframe LazyLoad', 'velocity-cache' ), __( 'Lazy load iframes such as videos and maps.', 'velocity-cache' ) ),
				'enable_js_defer'  => array( __( 'JavaScript Defer', 'velocity-cache' ), __( 'Add defer to external scripts; skip jQuery, scripts already async/defer/module, and scripts matching exclusion rules.', 'velocity-cache' ) ),
				'enable_js_delay'  => array( __( 'Delay JS by Rules', 'velocity-cache' ), __( 'Delay only scripts matching the rules below; resume on first interaction or after 5 seconds.', 'velocity-cache' ) ),
				'enable_delay_third_party' => array( __( 'Delay Common Third-Party Scripts', 'velocity-cache' ), __( 'Delay reCAPTCHA, GTag/GTM, Google Ads, Facebook Pixel and Cloudflare Beacon; load on first interaction or after 5 seconds.', 'velocity-cache' ) ),
				'enable_async_css' => array( __( 'Async Load Non-Critical CSS', 'velocity-cache' ), __( 'Load non-excluded stylesheets without blocking rendering, with a noscript fallback; core theme styles are excluded by default.', 'velocity-cache' ) ),
				'enable_conditional_css' => array( __( 'Conditional CSS Unloading', 'velocity-cache' ), __( 'Unload a whole stylesheet when the page lacks all listed markers. This is full-file unloading; check each page type after enabling.', 'velocity-cache' ) ),
				'enable_cdn'       => array( __( 'CDN URL Rewriting', 'velocity-cache' ), __( 'Rewrite only src/href of local static assets; page links, srcset and dynamic endpoints are untouched.', 'velocity-cache' ) ),
			) as $key => $labels
		) {
			add_settings_field( $key, $labels[0], array( $this, 'checkbox_field' ), VELOCA_Settings::PAGE, 'veloca_section_loading', array( 'key' => $key, 'desc' => $labels[1] ) );
		}
		add_settings_field( 'lazyload_skip', __( 'Above-the-Fold Images to Skip', 'velocity-cache' ), array( $this, 'number_field' ), VELOCA_Settings::PAGE, 'veloca_section_loading', array( 'key' => 'lazyload_skip', 'desc' => __( 'Skip lazy loading for the first N images; default 2, maximum 20.', 'velocity-cache' ) ) );
		add_settings_field( 'delay_js_handles', __( 'Delay JS Rules', 'velocity-cache' ), array( $this, 'textarea_field' ), VELOCA_Settings::PAGE, 'veloca_section_loading', array( 'key' => 'delay_js_handles', 'desc' => __( 'One handle or URL rule per line with * and ? wildcards; no script is delayed when empty.', 'velocity-cache' ) ) );
		add_settings_field( 'async_css_excludes', __( 'Async CSS Exclusions', 'velocity-cache' ), array( $this, 'textarea_field' ), VELOCA_Settings::PAGE, 'veloca_section_loading', array( 'key' => 'async_css_excludes', 'desc' => __( 'astra-theme-css is excluded by default; one handle or URL rule per line. Core layout styles must stay render-blocking.', 'velocity-cache' ) ) );
		add_settings_field( 'conditional_css_rules', __( 'Conditional CSS Unloading Rules', 'velocity-cache' ), array( $this, 'textarea_field' ), VELOCA_Settings::PAGE, 'veloca_section_loading', array( 'key' => 'conditional_css_rules', 'desc' => __( 'One per line: handle | marker1, marker2. Keep the stylesheet when the page DOM contains any marker; otherwise remove it. Markers are case-insensitive and script/style contents are ignored.', 'velocity-cache' ) ) );
		add_settings_field( 'critical_css', __( 'Critical CSS', 'velocity-cache' ), array( $this, 'textarea_field' ), VELOCA_Settings::PAGE, 'veloca_section_loading', array( 'key' => 'critical_css', 'desc' => __( 'Optional. Critical above-the-fold CSS is injected into the head; do not include style tags.', 'velocity-cache' ) ) );
		add_settings_field( 'cdn_url', __( 'CDN URL', 'velocity-cache' ), array( $this, 'text_field' ), VELOCA_Settings::PAGE, 'veloca_section_loading', array( 'key' => 'cdn_url', 'desc' => __( 'For example https://cdn.example.com, mapped to the origin root while keeping wp-content/wp-includes paths.', 'velocity-cache' ) ) );

		add_settings_section(
			'veloca_section_rules',
			__( 'Exclusion Rules', 'velocity-cache' ),
			static function () {
				echo '<p class="description">' . esc_html__( 'One rule per line with * and ? wildcards. CSS/JS rules accept a handle name or URL keyword.', 'velocity-cache' ) . '</p>';
			},
			VELOCA_Settings::PAGE
		);

		$textareas = array(
			'exclude_urls'       => array( __( 'Exclude URLs', 'velocity-cache' ), __( 'Match against the request path, for example /cart/* or /sitemap.xml.', 'velocity-cache' ) ),
			'exclude_cookies'    => array( __( 'Exclude Cookie Names', 'velocity-cache' ), __( 'Do not cache when any listed cookie name is present, for example wordpress_logged_in_*.', 'velocity-cache' ) ),
			'exclude_css_handles'=> array( __( 'Exclude CSS', 'velocity-cache' ), __( 'Stylesheet handles or URL keywords excluded from minification/combination.', 'velocity-cache' ) ),
			'exclude_js_handles' => array( __( 'Exclude JavaScript', 'velocity-cache' ), __( 'Script URL keywords excluded from minification.', 'velocity-cache' ) ),
		);

		foreach ( $textareas as $key => $labels ) {
			add_settings_field(
				$key,
				$labels[0],
				array( $this, 'textarea_field' ),
				VELOCA_Settings::PAGE,
				'veloca_section_rules',
				array( 'key' => $key, 'desc' => $labels[1] )
			);
		}

		add_settings_section(
			'veloca_section_log',
			__( 'Error Handling', 'velocity-cache' ),
			static function () {
				echo '<p class="description">' . esc_html__( 'Logs are written to wp-content/cache/velocity-cache/logs/errors.log and rotate automatically above 1 MB.', 'velocity-cache' ) . '</p>';
			},
			VELOCA_Settings::PAGE
		);

		add_settings_field(
			'log_errors',
			__( 'Log Errors', 'velocity-cache' ),
			array( $this, 'checkbox_field' ),
			VELOCA_Settings::PAGE,
			'veloca_section_log',
			array( 'key' => 'log_errors', 'desc' => __( 'Log cache write failures and optimization errors for troubleshooting.', 'velocity-cache' ) )
		);
	}

	/**
	 * 复选框字段。
	 *
	 * @param array $args 字段参数。
	 */
	public function checkbox_field( $args ) {
		$settings = VELOCA_Settings::get();
		$key      = $args['key'];
		printf(
			'<label><input type="checkbox" name="%1$s[%2$s]" value="1" %3$s> %4$s</label>',
			esc_attr( VELOCA_Settings::OPTION ),
			esc_attr( $key ),
			checked( 1, (int) $settings[ $key ], false ),
			esc_html( __( 'Enabled', 'velocity-cache' ) )
		);

		if ( ! empty( $args['desc'] ) ) {
			printf( '<p class="description">%s</p>', esc_html( $args['desc'] ) );
		}
	}

	/**
	 * 数字字段。
	 *
	 * @param array $args 字段参数。
	 */
	public function number_field( $args ) {
		$settings = VELOCA_Settings::get();
		$key      = $args['key'];
		$max      = 'lazyload_skip' === $key ? 20 : 2592000;
		printf(
			'<input type="number" min="0" max="%4$d" step="1" name="%1$s[%2$s]" value="%3$d" class="small-text">',
			esc_attr( VELOCA_Settings::OPTION ),
			esc_attr( $key ),
			(int) $settings[ $key ],
			(int) $max
		);

		if ( ! empty( $args['desc'] ) ) {
			printf( '<p class="description">%s</p>', esc_html( $args['desc'] ) );
		}
	}

	/**
	 * 单行文本字段。
	 *
	 * @param array $args 字段参数。
	 */
	public function text_field( $args ) {
		$settings = VELOCA_Settings::get();
		$key      = $args['key'];
		printf(
			'<input type="url" name="%1$s[%2$s]" value="%3$s" class="regular-text" placeholder="https://cdn.example.com">',
			esc_attr( VELOCA_Settings::OPTION ),
			esc_attr( $key ),
			esc_attr( $settings[ $key ] )
		);
		if ( ! empty( $args['desc'] ) ) {
			printf( '<p class="description">%s</p>', esc_html( $args['desc'] ) );
		}
	}

	/**
	 * 多行文本字段。
	 *
	 * @param array $args 字段参数。
	 */
	public function textarea_field( $args ) {
		$settings = VELOCA_Settings::get();
		$key      = $args['key'];
		printf(
			'<textarea name="%1$s[%2$s]" rows="4" cols="60" class="large-text code">%3$s</textarea>',
			esc_attr( VELOCA_Settings::OPTION ),
			esc_attr( $key ),
			esc_textarea( $settings[ $key ] )
		);

		if ( ! empty( $args['desc'] ) ) {
			printf( '<p class="description">%s</p>', esc_html( $args['desc'] ) );
		}
	}

	/**
	 * 处理清理缓存请求。
	 */
	public function handle_purge() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to perform this action.', 'velocity-cache' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( 'veloca_purge_cache' );

		$scope  = isset( $_REQUEST['scope'] ) ? sanitize_key( wp_unslash( $_REQUEST['scope'] ) ) : 'pages';
		$result = VELOCA_Cache::purge_all( 'assets' === $scope );

		$this->set_notice( $result, __( 'Cache purged.', 'velocity-cache' ) );
		$this->redirect_back();
	}

	/**
	 * 处理版本号递增请求。
	 */
	public function handle_bump() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to perform this action.', 'velocity-cache' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( 'veloca_bump_version' );

		$result = VELOCA_Cache::bump_version();
		$this->set_notice( $result, __( 'Asset version incremented and cache purged.', 'velocity-cache' ) );
		$this->redirect_back();
	}

	/**
	 * 建立预加载队列并尽快触发 WP-Cron。
	 */
	public function handle_preload() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to perform this action.', 'velocity-cache' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'veloca_preload' );

		$result = VELOCA_Preloader::schedule();
		if ( is_wp_error( $result ) ) {
			$this->set_notice( $result, '' );
		} else {
			/* translators: %d: number of URLs added to the preload queue. */
			$this->set_notice( true, sprintf( __( 'Added %d URLs to the preload queue; WP-Cron will process them in batches.', 'velocity-cache' ), $result ) );
			if ( function_exists( 'spawn_cron' ) ) {
				spawn_cron();
			}
		}
		$this->redirect_back();
	}

	/**
	 * 保存操作结果到一次性通知。
	 *
	 * @param true|WP_Error $result  操作结果。
	 * @param string        $message 成功消息。
	 */
	private function set_notice( $result, $message ) {
		if ( is_wp_error( $result ) ) {
			VELOCA_Tools::log( 'admin action failed', array( 'error' => $result->get_error_message() ) );
			$notice = array( 'type' => 'error', 'message' => $result->get_error_message() );
		} else {
			$notice = array( 'type' => 'success', 'message' => $message );
		}

		set_transient( 'veloca_admin_notice', $notice, 120 );
	}

	/**
	 * 安全跳回设置页。
	 */
	private function redirect_back() {
		$fallback = admin_url( 'options-general.php?page=' . VELOCA_Settings::PAGE );
		$referer  = wp_get_referer();
		$target   = ( $referer && false !== strpos( $referer, 'page=' . VELOCA_Settings::PAGE ) ) ? $referer : $fallback;

		wp_safe_redirect( $target );
		exit;
	}

	/**
	 * 后台通知。
	 */
	public function notices() {
		// 通知只出现在插件设置页和插件列表页，避免占用仪表盘和其他后台页面（Guideline 11）。
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only page identifier.
		global $pagenow;
		$is_settings = ( VELOCA_Settings::PAGE === $page );
		$is_plugins  = ( isset( $pagenow ) && 'plugins.php' === $pagenow );

		if ( ! $is_settings && ! $is_plugins ) {
			return;
		}

		if ( $is_plugins ) {
			$plugin_notice = get_transient( 'veloca_plugin_notice' );
			if ( is_string( $plugin_notice ) && '' !== $plugin_notice ) {
				delete_transient( 'veloca_plugin_notice' );
				printf(
					'<div class="notice notice-info is-dismissible"><p>%s</p></div>',
					esc_html( $plugin_notice )
				);
			}
		}

		if ( ! $is_settings ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Settings API verified the nonce before this redirect.
		if ( isset( $_GET['settings-updated'] ) ) {
			$version = (int) VELOCA_Settings::get()['asset_version'];
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: %d: asset version number. */
						__( 'Settings saved and page cache refreshed. Current asset version: v%d', 'velocity-cache' ),
						$version
					)
				)
			);
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$notice = get_transient( 'veloca_admin_notice' );
		if ( ! is_array( $notice ) || empty( $notice['message'] ) ) {
			return;
		}

		delete_transient( 'veloca_admin_notice' );
		$type = ( isset( $notice['type'] ) && 'error' === $notice['type'] ) ? 'error' : 'success';

		printf(
			'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
			esc_attr( $type ),
			esc_html( $notice['message'] )
		);
	}

	/**
	 * 加载后台资源（仅设置页）。
	 *
	 * @param string $hook_suffix 当前后台页面钩子。
	 */
	public function enqueue( $hook_suffix ) {
		if ( 'settings_page_' . VELOCA_Settings::PAGE !== $hook_suffix ) {
			return;
		}

		wp_enqueue_style( 'veloca-admin', VELOCA_URL . 'admin/css/admin.css', array(), VELOCA_VERSION );
		wp_enqueue_script( 'veloca-admin', VELOCA_URL . 'admin/js/admin.js', array( 'jquery' ), VELOCA_VERSION, true );
	}

	/**
	 * 设置页状态数据。
	 *
	 * @return array
	 */
	private function status_rows() {
		$root     = VELOCA_Tools::cache_root();
		$last     = (int) get_transient( 'veloca_last_purge' );
		$preload  = get_transient( 'veloca_last_preload' );
		$preload_text = __( 'None', 'velocity-cache' );
		if ( is_array( $preload ) && ! empty( $preload['time'] ) ) {
			$preload_text = sprintf(
				/* translators: 1: date and time, 2: successful URL count, 3: failed URL count. */
				__( '%1$s (%2$d succeeded, %3$d failed)', 'velocity-cache' ),
				wp_date( 'Y-m-d H:i:s', (int) $preload['time'] ),
				(int) $preload['success'],
				(int) $preload['failed']
			);
		}

		return array(
			__( 'Cache Directory', 'velocity-cache' ) => $root,
			__( 'Directory Writable', 'velocity-cache' ) => wp_is_writable( $root ) ? __( 'Yes', 'velocity-cache' ) : __( 'No', 'velocity-cache' ),
			__( 'Page Cache Files', 'velocity-cache' ) => (string) VELOCA_Tools::count_files( 'pages' ),
			__( 'Optimized Asset Files', 'velocity-cache' ) => (string) VELOCA_Tools::count_files( 'assets' ),
			__( 'Asset Version', 'velocity-cache' ) => 'v' . (int) VELOCA_Settings::get()['asset_version'],
			__( 'Last Purge', 'velocity-cache' ) => $last ? wp_date( 'Y-m-d H:i:s', $last ) : __( 'None', 'velocity-cache' ),
			__( 'Preload Queue', 'velocity-cache' ) => (string) VELOCA_Preloader::queue_count(),
			__( 'Last Preload', 'velocity-cache' ) => $preload_text,
		);
	}
}
