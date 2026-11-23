<?php
/**
 * 后台配置模块：设置页、操作处理、状态与提示。
 *
 * @package VelocityCache
 */

defined( 'ABSPATH' ) || exit;

final class VC_Admin {

	/** @var string */
	private $page_hook = '';

	/**
	 * 挂载后台钩子。
	 */
	public function init() {
		add_action( 'admin_menu', array( $this, 'add_page' ) );
		add_action( 'init', array( 'VC_Settings', 'register' ) );
		add_action( 'admin_init', array( $this, 'register_fields' ) );
		add_action( 'admin_post_vc_purge_cache', array( $this, 'handle_purge' ) );
		add_action( 'admin_post_vc_bump_version', array( $this, 'handle_bump' ) );
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
			VC_Settings::PAGE,
			array( $this, 'render_page' )
		);
	}

	/**
	 * 渲染设置页。
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( '你没有权限访问此页面。', 'velocity-cache' ), 403 );
		}

		VC_Tools::ensure_dirs();

		$settings = VC_Settings::get();
		$status   = $this->status_rows();

		include VC_PATH . 'admin/views/settings-page.php';
	}

	/**
	 * 注册设置区块与字段。
	 */
	public function register_fields() {
		add_settings_section(
			'vc_section_cache',
			__( '缓存', 'velocity-cache' ),
			static function () {
				echo '<p class="description">' . esc_html__( '仅缓存匿名访客的 GET 请求；动态页面与排除规则中的地址不会缓存。', 'velocity-cache' ) . '</p>';
			},
			VC_Settings::PAGE
		);

		add_settings_field(
			'enable_cache',
			__( '页面缓存', 'velocity-cache' ),
			array( $this, 'checkbox_field' ),
			VC_Settings::PAGE,
			'vc_section_cache',
			array( 'key' => 'enable_cache', 'desc' => __( '生成静态 HTML，命中后直接输出，不加载主题。', 'velocity-cache' ) )
		);

		add_settings_field(
			'cache_ttl',
			__( '缓存有效期（秒）', 'velocity-cache' ),
			array( $this, 'number_field' ),
			VC_Settings::PAGE,
			'vc_section_cache',
			array( 'key' => 'cache_ttl', 'desc' => __( '0 表示永不过期，仅在内容变化时刷新。最大 2592000 秒。', 'velocity-cache' ) )
		);

		add_settings_field(
			'cache_logged_in',
			__( '缓存已登录用户', 'velocity-cache' ),
			array( $this, 'checkbox_field' ),
			VC_Settings::PAGE,
			'vc_section_cache',
			array( 'key' => 'cache_logged_in', 'desc' => __( '不推荐开启：不同用户可能看到相同页面。', 'velocity-cache' ) )
		);

		add_settings_field(
			'cache_query',
			__( '缓存带查询参数的 URL', 'velocity-cache' ),
			array( $this, 'checkbox_field' ),
			VC_Settings::PAGE,
			'vc_section_cache',
			array( 'key' => 'cache_query', 'desc' => __( '默认关闭，避免 ?utm_ 等参数产生大量重复缓存。', 'velocity-cache' ) )
		);

		add_settings_section(
			'vc_section_minify',
			__( '资源优化', 'velocity-cache' ),
			static function () {
				echo '<p class="description">' . esc_html__( '站外资源、module 脚本、async/defer 脚本与包含 @import 的样式表会被自动跳过。', 'velocity-cache' ) . '</p>';
			},
			VC_Settings::PAGE
		);

		add_settings_field(
			'enable_html_minify',
			__( 'HTML 压缩', 'velocity-cache' ),
			array( $this, 'checkbox_field' ),
			VC_Settings::PAGE,
			'vc_section_minify',
			array( 'key' => 'enable_html_minify', 'desc' => __( '移除注释与多余空白，保留 pre/textarea/script/style。', 'velocity-cache' ) )
		);

		add_settings_field(
			'enable_css_minify',
			__( 'CSS 压缩', 'velocity-cache' ),
			array( $this, 'checkbox_field' ),
			VC_Settings::PAGE,
			'vc_section_minify',
			array( 'key' => 'enable_css_minify', 'desc' => __( '压缩本地样式表与内联 style。', 'velocity-cache' ) )
		);

		add_settings_field(
			'enable_css_combine',
			__( 'CSS 合并', 'velocity-cache' ),
			array( $this, 'checkbox_field' ),
			VC_Settings::PAGE,
			'vc_section_minify',
			array( 'key' => 'enable_css_combine', 'desc' => __( '按 media 属性把本地样式表合并为一个版本化文件。', 'velocity-cache' ) )
		);

		add_settings_field(
			'enable_js_minify',
			__( 'JavaScript 压缩', 'velocity-cache' ),
			array( $this, 'checkbox_field' ),
			VC_Settings::PAGE,
			'vc_section_minify',
			array( 'key' => 'enable_js_minify', 'desc' => __( '压缩本地脚本与内联脚本，跳过 module/async/defer。', 'velocity-cache' ) )
		);

		add_settings_section(
			'vc_section_rules',
			__( '排除规则', 'velocity-cache' ),
			static function () {
				echo '<p class="description">' . esc_html__( '每行一条，支持 * 与 ? 通配符。CSS/JS 规则可写 handle 名或 URL 关键词。', 'velocity-cache' ) . '</p>';
			},
			VC_Settings::PAGE
		);

		$textareas = array(
			'exclude_urls'       => array( __( '排除 URL', 'velocity-cache' ), __( '按请求路径匹配，例如 /cart/*、/sitemap.xml。', 'velocity-cache' ) ),
			'exclude_cookies'    => array( __( '排除 Cookie 名', 'velocity-cache' ), __( '命中任意 Cookie 名时不缓存，例如 wordpress_logged_in_*。', 'velocity-cache' ) ),
			'exclude_css_handles'=> array( __( '排除 CSS', 'velocity-cache' ), __( '不压缩/合并的样式表 handle 或 URL 关键词。', 'velocity-cache' ) ),
			'exclude_js_handles' => array( __( '排除 JavaScript', 'velocity-cache' ), __( '不压缩的脚本 URL 关键词。', 'velocity-cache' ) ),
		);

		foreach ( $textareas as $key => $labels ) {
			add_settings_field(
				$key,
				$labels[0],
				array( $this, 'textarea_field' ),
				VC_Settings::PAGE,
				'vc_section_rules',
				array( 'key' => $key, 'desc' => $labels[1] )
			);
		}

		add_settings_section(
			'vc_section_log',
			__( '错误处理', 'velocity-cache' ),
			static function () {
				echo '<p class="description">' . esc_html__( '日志写入 wp-content/cache/velocity-cache/logs/errors.log，超过 1 MB 自动轮转。', 'velocity-cache' ) . '</p>';
			},
			VC_Settings::PAGE
		);

		add_settings_field(
			'log_errors',
			__( '记录错误', 'velocity-cache' ),
			array( $this, 'checkbox_field' ),
			VC_Settings::PAGE,
			'vc_section_log',
			array( 'key' => 'log_errors', 'desc' => __( '记录缓存写入失败与优化异常，便于排查。', 'velocity-cache' ) )
		);
	}

	/**
	 * 复选框字段。
	 *
	 * @param array $args 字段参数。
	 */
	public function checkbox_field( $args ) {
		$settings = VC_Settings::get();
		$key      = $args['key'];
		printf(
			'<label><input type="checkbox" name="%1$s[%2$s]" value="1" %3$s> %4$s</label>',
			esc_attr( VC_Settings::OPTION ),
			esc_attr( $key ),
			checked( 1, (int) $settings[ $key ], false ),
			esc_html( __( '启用', 'velocity-cache' ) )
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
		$settings = VC_Settings::get();
		$key      = $args['key'];
		printf(
			'<input type="number" min="0" max="2592000" step="1" name="%1$s[%2$s]" value="%3$d" class="small-text">',
			esc_attr( VC_Settings::OPTION ),
			esc_attr( $key ),
			(int) $settings[ $key ]
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
		$settings = VC_Settings::get();
		$key      = $args['key'];
		printf(
			'<textarea name="%1$s[%2$s]" rows="4" cols="60" class="large-text code">%3$s</textarea>',
			esc_attr( VC_Settings::OPTION ),
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
			wp_die( esc_html__( '你没有权限执行此操作。', 'velocity-cache' ), 403 );
		}

		check_admin_referer( 'vc_purge_cache' );

		$scope  = isset( $_REQUEST['scope'] ) ? sanitize_key( wp_unslash( $_REQUEST['scope'] ) ) : 'pages';
		$result = VC_Cache::purge_all( 'assets' === $scope );

		$this->set_notice( $result, __( '缓存已清理。', 'velocity-cache' ) );
		$this->redirect_back();
	}

	/**
	 * 处理版本号递增请求。
	 */
	public function handle_bump() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( '你没有权限执行此操作。', 'velocity-cache' ), 403 );
		}

		check_admin_referer( 'vc_bump_version' );

		$result = VC_Cache::bump_version();
		$this->set_notice( $result, __( '资源版本号已递增，缓存已清理。', 'velocity-cache' ) );
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
			VC_Tools::log( 'admin action failed', array( 'error' => $result->get_error_message() ) );
			$notice = array( 'type' => 'error', 'message' => $result->get_error_message() );
		} else {
			$notice = array( 'type' => 'success', 'message' => $message );
		}

		set_transient( 'vc_admin_notice', $notice, 120 );
	}

	/**
	 * 安全跳回设置页。
	 */
	private function redirect_back() {
		$fallback = admin_url( 'options-general.php?page=' . VC_Settings::PAGE );
		$referer  = wp_get_referer();
		$target   = ( $referer && false !== strpos( $referer, 'page=' . VC_Settings::PAGE ) ) ? $referer : $fallback;

		wp_safe_redirect( $target );
		exit;
	}

	/**
	 * 后台通知。
	 */
	public function notices() {
		if ( isset( $_GET['settings-updated'] ) && isset( $_GET['page'] ) && VC_Settings::PAGE === sanitize_key( wp_unslash( $_GET['page'] ) ) ) {
			$version = (int) VC_Settings::get()['asset_version'];
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html( sprintf( __( '设置已保存，页面缓存已刷新。当前资源版本：v%d', 'velocity-cache' ), $version ) )
			);
		}

		$notice = get_transient( 'vc_admin_notice' );
		if ( ! is_array( $notice ) || empty( $notice['message'] ) ) {
			return;
		}

		delete_transient( 'vc_admin_notice' );
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
		if ( 'settings_page_' . VC_Settings::PAGE !== $hook_suffix ) {
			return;
		}

		wp_enqueue_style( 'vc-admin', VC_URL . 'admin/css/admin.css', array(), VC_VERSION );
		wp_enqueue_script( 'vc-admin', VC_URL . 'admin/js/admin.js', array( 'jquery' ), VC_VERSION, true );
	}

	/**
	 * 设置页状态数据。
	 *
	 * @return array
	 */
	private function status_rows() {
		$root     = VC_Tools::cache_root();
		$last     = (int) get_transient( 'vc_last_purge' );

		return array(
			__( '缓存目录', 'velocity-cache' ) => $root,
			__( '目录可写', 'velocity-cache' ) => is_writable( $root ) ? __( '是', 'velocity-cache' ) : __( '否', 'velocity-cache' ),
			__( '页面缓存文件', 'velocity-cache' ) => (string) VC_Tools::count_files( 'pages' ),
			__( '优化资源文件', 'velocity-cache' ) => (string) VC_Tools::count_files( 'assets' ),
			__( '资源版本号', 'velocity-cache' ) => 'v' . (int) VC_Settings::get()['asset_version'],
			__( '上次清理时间', 'velocity-cache' ) => $last ? gmdate( 'Y-m-d H:i:s', $last + (int) ( get_option( 'gmt_offset' ) * HOUR_IN_SECONDS ) ) : __( '暂无', 'velocity-cache' ),
		);
	}
}
