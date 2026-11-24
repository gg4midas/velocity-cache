<?php
/**
 * 通用工具：路径、文件、日志、排除规则与压缩算法。
 *
 * @package VelocityCache
 */

defined( 'ABSPATH' ) || exit;

final class VELOCA_Tools {

	const CACHE_REL = '/cache/velocity-cache';

	/**
	 * 缓存与日志文件的不可预测前缀（每个站点固定，升级时复用）。
	 *
	 * @return string 24 位十六进制字符串。
	 */
	public static function cache_secret() {
		$secret = (string) get_option( 'veloca_cache_secret', '' );
		if ( preg_match( '/^[a-f0-9]{24}$/', $secret ) ) {
			return $secret;
		}

		$secret = function_exists( 'random_bytes' ) ? bin2hex( random_bytes( 12 ) ) : wp_generate_password( 24, false, false );
		$secret = substr( preg_replace( '/[^a-f0-9]/i', '', $secret ) . str_repeat( '0', 24 ), 0, 24 );

		if ( false === get_option( 'veloca_cache_secret', false ) ) {
			add_option( 'veloca_cache_secret', $secret, '', 'no' );
		} else {
			update_option( 'veloca_cache_secret', $secret, 'no' );
		}

		return $secret;
	}

	/**
	 * 插件激活：初始化选项与缓存目录。
	 */
	public static function activate() {
		if ( false === get_option( 'veloca_settings', false ) ) {
			add_option( 'veloca_settings', VELOCA_Settings::defaults(), '', 'yes' );
		}

		self::cache_secret();
		self::cleanup_legacy();
		self::ensure_dirs();
		self::log( 'plugin activated' );
	}

	/**
	 * 一次性清理旧版本留下的可预测日志文件名。
	 */
	public static function cleanup_legacy() {
		if ( get_option( 'veloca_legacy_cleaned' ) ) {
			return;
		}

		foreach ( array( 'errors.log', 'errors.log.old' ) as $name ) {
			wp_delete_file( self::sub_path( 'logs', $name ) );
		}

		// 强制刷新所有目录的拒绝规则，覆盖旧版本只拒绝 PHP 的 .htaccess。
		self::purge_dir( 'logs' );
		self::ensure_dirs();

		add_option( 'veloca_legacy_cleaned', 1, '', 'no' );
	}

	/**
	 * 插件停用：清空缓存，保留设置。
	 */
	public static function deactivate() {
		self::purge_dir( 'pages' );
		self::purge_dir( 'assets' );
		self::ensure_dirs();
		self::log( 'plugin deactivated, caches purged' );
	}

	/**
	 * 缓存根目录（绝对路径，无尾斜杠）。
	 *
	 * @return string
	 */
	public static function cache_root() {
		return rtrim( wp_normalize_path( WP_CONTENT_DIR . self::CACHE_REL ), '/' );
	}

	/**
	 * 缓存目录 URL（带尾斜杠）。
	 *
	 * @return string
	 */
	public static function cache_base_url() {
		return trailingslashit( content_url( self::CACHE_REL . '/' ) );
	}

	/**
	 * 构造缓存内的子路径，强制白名单字符与去尾斜杠。
	 *
	 * @param string $sub      一级子目录：pages / assets / logs。
	 * @param string $relative 相对路径。
	 * @return string
	 */
	public static function sub_path( $sub, $relative = '' ) {
		$sub      = strtolower( preg_replace( '/[^a-zA-Z0-9_-]/', '', $sub ) );
		$relative = preg_replace( '/[^a-zA-Z0-9_\/.-]/', '', (string) $relative );
		$relative = str_replace( '..', '', $relative );

		$path = self::cache_root() . '/' . $sub;
		if ( '' !== $relative ) {
			$path .= '/' . ltrim( $relative, '/' );
		}

		return wp_normalize_path( $path );
	}

	/**
	 * 创建并加固目录，写入索引文件与禁止列表的 .htaccess。
	 *
	 * @param string $dir 绝对路径。
	 * @return bool
	 */
	public static function ensure_dir( $dir ) {
		$dir  = wp_normalize_path( $dir );
		$root = self::cache_root();

		if ( '' === $dir || ( $dir !== $root && strpos( $dir, $root . '/' ) !== 0 ) ) {
			return false;
		}

		if ( ! wp_mkdir_p( $dir ) || ! is_dir( $dir ) ) {
			return false;
		}

		if ( ! file_exists( $dir . '/index.php' ) ) {
			@file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" );
		}

		// 默认拒绝目录内的一切直接访问；仅放行合并后的 CSS/JS（浏览器需要按 URL 加载）。
		$htaccess = "Options -Indexes\n<IfModule mod_authz_core.c>\nRequire all denied\n<FilesMatch \"\\.(?:css|js)$\">\nRequire all granted\n</FilesMatch>\n</IfModule>\n<IfModule !mod_authz_core.c>\nOrder allow,deny\nDeny from all\n<FilesMatch \"\\.(?:css|js)$\">\nOrder allow,deny\nAllow from all\n</FilesMatch>\n</IfModule>\n";
		if ( ! file_exists( $dir . '/.htaccess' ) || file_get_contents( $dir . '/.htaccess' ) !== $htaccess ) {
			@file_put_contents( $dir . '/.htaccess', $htaccess );
		}

		// Nginx 用户：提供一份可直接 include 的拒绝规则，避免缓存 HTML/日志被下载。
		if ( ! file_exists( $dir . '/nginx.conf' ) ) {
			@file_put_contents( $dir . '/nginx.conf', "location ~* \\.(?:html|log|old|tmp|php)$ { deny all; }\n" );
		}

		return true;
	}

	/**
	 * 初始化全部缓存子目录。
	 */
	public static function ensure_dirs() {
		$dirs = array(
			self::cache_root(),
			self::sub_path( 'pages' ),
			self::sub_path( 'assets' ),
			self::sub_path( 'assets', 'css' ),
			self::sub_path( 'assets', 'js' ),
			self::sub_path( 'logs' ),
		);

		foreach ( $dirs as $dir ) {
			self::ensure_dir( $dir );
		}
	}

	/**
	 * 写入缓存文件（临时文件 + 原子重命名）。
	 *
	 * @param string $file 绝对路径。
	 * @param string $data 内容。
	 * @return true|WP_Error
	 */
	public static function write_file( $file, $data ) {
		$dir = dirname( $file );
		if ( ! self::ensure_dir( $dir ) ) {
			return new WP_Error( 'veloca_dir_not_writable', '缓存目录不可写：' . $dir );
		}

		$suffix = function_exists( 'random_bytes' ) ? bin2hex( random_bytes( 4 ) ) : wp_generate_password( 8, false, false );
		$tmp    = $file . '.tmp.' . $suffix;

		if ( false === @file_put_contents( $tmp, $data, LOCK_EX ) ) {
			wp_delete_file( $tmp );
			return new WP_Error( 'veloca_write_failed', '缓存文件写入失败：' . $file );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Atomic rename is required for the cache write.
		if ( ! @rename( $tmp, $file ) ) {
			wp_delete_file( $tmp );
			return new WP_Error( 'veloca_rename_failed', '缓存文件替换失败：' . $file );
		}

		return true;
	}

	/**
	 * 安全清空某个缓存子目录（只删除文件，不删除目录本身）。
	 *
	 * @param string $sub 子目录名。
	 * @return true|WP_Error
	 */
	public static function purge_dir( $sub ) {
		$dir = self::sub_path( $sub );
		if ( ! is_dir( $dir ) ) {
			return true;
		}

		$root = self::cache_root();
		$dir  = rtrim( wp_normalize_path( $dir ), '/' );
		if ( '' === $sub || strpos( $dir, $root . '/' ) !== 0 ) {
			return new WP_Error( 'veloca_unsafe_path', '拒绝清理非插件缓存目录。' );
		}

		$items = @scandir( $dir );
		if ( ! is_array( $items ) ) {
			return new WP_Error( 'veloca_scan_failed', '无法读取缓存目录：' . $dir );
		}

		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}

			$path = wp_normalize_path( $dir . '/' . $item );
			if ( is_dir( $path ) && ! is_link( $path ) ) {
				self::remove_nested( $path, $dir );
			} else {
				wp_delete_file( $path );
			}
		}

		return true;
	}

	/**
	 * 递归删除嵌套目录，始终限定在给定父目录之内。
	 *
	 * @param string $dir  当前目录。
	 * @param string $root 允许的父目录。
	 * @return void
	 */
	private static function remove_nested( $dir, $root ) {
		$dir = rtrim( wp_normalize_path( $dir ), '/' );
		$root = rtrim( wp_normalize_path( $root ), '/' );

		if ( strpos( $dir, $root . '/' ) !== 0 || ! is_dir( $dir ) ) {
			return;
		}

		$items = @scandir( $dir );
		if ( is_array( $items ) ) {
			foreach ( $items as $item ) {
				if ( '.' === $item || '..' === $item ) {
					continue;
				}
				$path = wp_normalize_path( $dir . '/' . $item );
				if ( is_dir( $path ) && ! is_link( $path ) ) {
					self::remove_nested( $path, $root );
				} else {
					wp_delete_file( $path );
				}
			}
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Removing an empty directory inside the plugin-owned cache root.
		@rmdir( $dir );
	}

	/**
	 * 记录错误日志（仅设置开启时写入）。
	 *
	 * @param string $message 日志内容。
	 * @param array  $context 附加上下文。
	 */
	public static function log( $message, $context = array() ) {
		$settings = VELOCA_Settings::get();
		if ( empty( $settings['log_errors'] ) ) {
			return;
		}

		$file = self::sub_path( 'logs', 'events-' . self::cache_secret() . '.log' );
		if ( ! self::ensure_dir( dirname( $file ) ) ) {
			return;
		}

		if ( is_file( $file ) && filesize( $file ) > 1048576 ) {
			// 轮转文件同样使用不可预测名称，避免被猜到直接访问。
			$rotated = 'events-' . self::cache_secret() . '-' . gmdate( 'YmdHis' ) . '-' . substr( md5( uniqid( '', true ) ), 0, 12 ) . '.log';
			// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Log rotation inside the plugin-owned cache root.
			@rename( $file, self::sub_path( 'logs', $rotated ) );
		}

		$extra = empty( $context ) ? '' : ' | ' . (string) wp_json_encode( $context );
		$line  = gmdate( 'Y-m-d H:i:s' ) . ' ' . sanitize_text_field( (string) $message ) . $extra . "\n";
		@file_put_contents( $file, $line, FILE_APPEND | LOCK_EX );
	}

	/**
	 * 判断当前请求是否为可优化的前端 HTML 请求。
	 *
	 * @return bool
	 */
	public static function is_frontend() {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return false;
		}

		if ( ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return false;
		}

		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : '';
		if ( 'GET' !== $method ) {
			return false;
		}

		$path = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_parse_url( esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ), PHP_URL_PATH ) : '';
		$path = strtolower( $path );

		if ( '' === $path || preg_match( '#/(?:wp-admin|wp-login\.php|xmlrpc\.php)(?:/|$)#', $path ) ) {
			return false;
		}

		if ( is_feed() || is_trackback() || is_preview() || is_404() ) {
			return false;
		}

		return true;
	}

	/**
	 * 当前响应是否声明为 HTML。XML、JSON、纯文本等响应不做前端优化。
	 *
	 * @return bool
	 */
	public static function is_html_response() {
		foreach ( headers_list() as $header ) {
			if ( 0 === stripos( $header, 'Content-Type:' ) ) {
				return false !== stripos( $header, 'text/html' ) || false !== stripos( $header, 'application/xhtml+xml' );
			}
		}

		return true;
	}

	/**
	 * 生成当前请求的缓存键。
	 *
	 * @param bool $with_query 是否把查询串计入缓存键。
	 * @return string
	 */
	public static function request_key( $with_query ) {
		$scheme = is_ssl() ? 'https' : 'http';
		$host   = isset( $_SERVER['HTTP_HOST'] ) ? strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) ) : (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$uri    = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';
		$path   = (string) wp_parse_url( $uri, PHP_URL_PATH );
		$query  = $with_query ? (string) wp_parse_url( $uri, PHP_URL_QUERY ) : '';
		$user   = is_user_logged_in() ? get_current_user_id() : 0;

		// 混入站点私钥，避免缓存文件名被外部按 URL 推算后直接下载。
		return hash( 'sha256', self::cache_secret() . '|' . $scheme . '|' . $host . '|' . $path . '|' . $query . '|user:' . $user );
	}

	/**
	 * 根据站内 URL 生成匿名缓存键，用于精确清理。
	 *
	 * @param string $url        完整站内 URL。
	 * @param bool   $with_query 是否计入查询串。
	 * @return string 非法或站外 URL 返回空串。
	 */
	public static function url_key( $url, $with_query = false ) {
		$parts = wp_parse_url( $url );
		$home  = wp_parse_url( home_url( '/' ) );
		if ( ! is_array( $parts ) || ! is_array( $home ) || empty( $parts['host'] ) || empty( $home['host'] ) || strtolower( $parts['host'] ) !== strtolower( $home['host'] ) ) {
			return '';
		}

		$scheme = isset( $parts['scheme'] ) ? strtolower( $parts['scheme'] ) : ( is_ssl() ? 'https' : 'http' );
		$host   = strtolower( $parts['host'] );
		$path   = isset( $parts['path'] ) ? $parts['path'] : '/';
		$query  = $with_query && isset( $parts['query'] ) ? $parts['query'] : '';

		return hash( 'sha256', self::cache_secret() . '|' . $scheme . '|' . $host . '|' . $path . '|' . $query . '|user:0' );
	}

	/**
	 * 解析规则列表（每行一条，最多 100 条）。
	 *
	 * @param string $raw 原始文本。
	 * @return array
	 */
	public static function rule_list( $raw ) {
		$lines = explode( "\n", (string) $raw );
		$rules = array();

		foreach ( $lines as $line ) {
			$line = trim( sanitize_text_field( $line ) );
			if ( '' === $line ) {
				continue;
			}
			$rules[] = substr( $line, 0, 255 );
			if ( count( $rules ) >= 100 ) {
				break;
			}
		}

		return $rules;
	}

	/**
	 * 通配符匹配：支持 * 与 ?。
	 *
	 * @param string $value   待匹配值。
	 * @param string $raw     规则文本。
	 * @return bool
	 */
	public static function match_rules( $value, $raw ) {
		$value = (string) $value;
		foreach ( self::rule_list( $raw ) as $rule ) {
			$pattern = str_replace( array( '\*', '\?' ), array( '.*', '.' ), preg_quote( $rule, '~' ) );
			if ( @preg_match( '~^' . $pattern . '$~is', $value ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * 检查当前请求的 Cookie 是否命中排除规则。
	 *
	 * @param string $raw 规则文本。
	 * @return bool
	 */
	public static function has_excluded_cookie( $raw ) {
		$rules = self::rule_list( $raw );
		if ( empty( $rules ) ) {
			return false;
		}

		foreach ( array_keys( $_COOKIE ) as $name ) {
			if ( self::match_rules( (string) $name, $raw ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * 把站内资源 URL 解析为本地文件路径，拒绝站外与越界路径。
	 *
	 * @param string $url 资源 URL。
	 * @return string 本地绝对路径，失败返回空串。
	 */
	public static function local_file( $url ) {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['path'] ) ) {
			return '';
		}

		$home = wp_parse_url( home_url( '/' ) );
		$host = isset( $parts['host'] ) ? strtolower( $parts['host'] ) : ( isset( $home['host'] ) ? strtolower( $home['host'] ) : '' );
		if ( ! $host || ! isset( $home['host'] ) || $host !== strtolower( $home['host'] ) ) {
			return '';
		}

		$path = rawurldecode( $parts['path'] );
		if ( preg_match( '#(?:^|/)\.\.(?:/|$)#', $path ) || false !== strpos( $path, "\0" ) ) {
			return '';
		}

		$candidates   = array();
		$content_path = wp_parse_url( content_url( '/' ), PHP_URL_PATH );
		$plugin_path  = wp_parse_url( plugins_url( '/' ), PHP_URL_PATH );

		if ( is_string( $content_path ) && '' !== $content_path && 0 === strpos( $path, rtrim( $content_path, '/' ) ) ) {
			$candidates[] = WP_CONTENT_DIR . substr( $path, strlen( rtrim( $content_path, '/' ) ) );
		}

		if ( is_string( $plugin_path ) && '' !== $plugin_path && 0 === strpos( $path, rtrim( $plugin_path, '/' ) ) ) {
			$candidates[] = WP_PLUGIN_DIR . substr( $path, strlen( rtrim( $plugin_path, '/' ) ) );
		}

		// 子目录安装时 site_url() 自带路径前缀，必须去掉后再映射，避免拼出重复目录。
		$site_path = wp_parse_url( site_url( '/' ), PHP_URL_PATH );
		$relative  = $path;
		if ( is_string( $site_path ) && '' !== $site_path && '/' !== $site_path ) {
			$site_prefix = rtrim( $site_path, '/' );
			if ( 0 === strpos( $relative, $site_prefix . '/' ) ) {
				$relative = substr( $relative, strlen( $site_prefix ) );
			}
		}
		$candidates[] = ABSPATH . ltrim( $relative, '/' );

		$allowed = array_filter( array( realpath( WP_CONTENT_DIR ), realpath( WP_PLUGIN_DIR ), realpath( ABSPATH ) ) );
		foreach ( $candidates as $candidate ) {
			$real = realpath( $candidate );
			if ( ! $real || ! is_file( $real ) || ! is_readable( $real ) ) {
				continue;
			}

			$normalized_real = wp_normalize_path( $real );
			foreach ( $allowed as $base ) {
				$base = rtrim( wp_normalize_path( $base ), '/' );
				if ( 0 === strpos( $normalized_real, $base . '/' ) ) {
					return $real;
				}
			}
		}

		return '';
	}

	/**
	 * 把 CSS 中的相对 URL 改写为绝对路径。
	 *
	 * @param string $css        CSS 源码。
	 * @param string $source_url 原始 CSS 文件 URL。
	 * @return string
	 */
	public static function absolutize_css( $css, $source_url ) {
		return preg_replace_callback(
			'/url\(\s*(["\']?)([^"\')]+)\1\s*\)/i',
			static function ( $matches ) use ( $source_url ) {
				$value = trim( $matches[2] );
				if ( '' === $value || '#' === $value[0] || 0 === stripos( $value, 'data:' ) || preg_match( '~^(?:https?:)?//~i', $value ) ) {
					return $matches[0];
				}

				return 'url(' . $matches[1] . self::resolve_url( $source_url, $value ) . $matches[1] . ')';
			},
			$css
		);
	}

	/**
	 * 基于基准 URL 解析相对地址。
	 *
	 * @param string $base 基准 URL。
	 * @param string $rel  相对地址。
	 * @return string
	 */
	public static function resolve_url( $base, $rel ) {
		$parts = wp_parse_url( $base );
		if ( ! is_array( $parts ) ) {
			return $rel;
		}

		$authority = ( isset( $parts['host'] ) ? $parts['host'] : '' ) . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );

		if ( 0 === strpos( $rel, '/' ) ) {
			return ( isset( $parts['scheme'] ) ? $parts['scheme'] : 'https' ) . '://' . $authority . $rel;
		}

		$dir = isset( $parts['path'] ) ? preg_replace( '#/[^/]*$#', '', $parts['path'] ) : '';
		$all = $dir . '/' . $rel;
		$out = array();

		foreach ( explode( '/', $all ) as $segment ) {
			if ( '' === $segment || '.' === $segment ) {
				continue;
			}
			if ( '..' === $segment ) {
				array_pop( $out );
				continue;
			}
			$out[] = $segment;
		}

		$scheme = isset( $parts['scheme'] ) ? $parts['scheme'] : 'https';

		return $scheme . '://' . $authority . '/' . implode( '/', $out );
	}

	/**
	 * 把源码切成代码段与字符串段，压缩时保护字符串与正则字面量。
	 *
	 * @param string $input 源码。
	 * @param string $mode  css 或 js。
	 * @return array
	 */
	private static function scan_tokens( $input, $mode ) {
		$tokens = array();
		$code   = '';
		$len    = strlen( $input );
		$i      = 0;

		$flush = static function () use ( &$code, &$tokens ) {
			if ( '' !== $code ) {
				$tokens[] = array( 'c', $code );
				$code     = '';
			}
		};

		while ( $i < $len ) {
			$ch   = $input[ $i ];
			$next = $i + 1 < $len ? $input[ $i + 1 ] : '';

			if ( '/' === $ch && '*' === $next ) {
				$end = strpos( $input, '*/', $i + 2 );
				$i   = false === $end ? $len : $end + 2;
				$code .= ' ';
				continue;
			}

			if ( 'js' === $mode && '/' === $ch && '/' === $next ) {
				$end = strpos( $input, "\n", $i + 2 );
				$i   = false === $end ? $len : $end;
				$code .= ' ';
				continue;
			}

			if ( '"' === $ch || "'" === $ch || ( 'js' === $mode && '`' === $ch ) ) {
				$flush();
				$quote = $ch;
				$str   = $ch;
				$i++;
				while ( $i < $len ) {
					$c = $input[ $i ];
					$str .= $c;
					if ( '\\' === $c && $i + 1 < $len ) {
						$str .= $input[ $i + 1 ];
						$i += 2;
						continue;
					}
					$i++;
					if ( $c === $quote ) {
						break;
					}
				}
				$tokens[] = array( 's', $str );
				continue;
			}

			if ( 'js' === $mode && '/' === $ch && self::regex_can_start( $code ) ) {
				$flush();
				$str      = '/';
				$i++;
				$in_class = false;
				while ( $i < $len ) {
					$c = $input[ $i ];
					$str .= $c;
					if ( '\\' === $c && $i + 1 < $len ) {
						$str .= $input[ $i + 1 ];
						$i += 2;
						continue;
					}
					if ( '[' === $c ) {
						$in_class = true;
					}
					if ( ']' === $c ) {
						$in_class = false;
					}
					if ( '/' === $c && ! $in_class ) {
						$i++;
						while ( $i < $len && ctype_alpha( $input[ $i ] ) ) {
							$str .= $input[ $i ];
							$i++;
						}
						break;
					}
					$i++;
				}
				$tokens[] = array( 's', $str );
				continue;
			}

			$code .= $ch;
			$i++;
		}

		$flush();

		return $tokens;
	}

	/**
	 * 判断当前位置是否可以开始 JS 正则字面量。
	 *
	 * @param string $code 已累积代码。
	 * @return bool
	 */
	private static function regex_can_start( $code ) {
		$trimmed = rtrim( $code );
		if ( '' === $trimmed ) {
			return true;
		}

		$last = substr( $trimmed, -1 );
		if ( false !== strpos( '=([{,:;!&|?+-*%~^<>', $last ) ) {
			return true;
		}

		$keywords = array( 'return', 'case', 'throw', 'typeof', 'instanceof', 'in', 'of', 'new', 'delete', 'void', 'yield', 'await', 'else', 'do' );
		foreach ( $keywords as $keyword ) {
			if ( preg_match( '~\b' . preg_quote( $keyword, '~' ) . '$~i', $trimmed ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * CSS 压缩。
	 *
	 * @param string $css 源码。
	 * @return string
	 */
	public static function minify_css( $css ) {
		if ( ! is_string( $css ) || '' === $css ) {
			return $css;
		}

		$out = '';
		foreach ( self::scan_tokens( $css, 'css' ) as $token ) {
			if ( 's' === $token[0] ) {
				$out .= $token[1];
				continue;
			}
			$code = preg_replace( '/\s+/', ' ', $token[1] );
			// 不压缩冒号两侧空白，避免把 .parent :hover 改成 .parent:hover。
			$code = preg_replace( '/\s*([{};,>])\s*/', '$1', $code );
			$code = preg_replace( '/;\s*}/', '}', $code );
			$out .= $code;
		}

		return trim( $out );
	}

	/**
	 * JavaScript 压缩（保守策略，保护字符串、模板字符串、正则与注释中的 URL）。
	 *
	 * @param string $js 源码。
	 * @return string
	 */
	public static function minify_js( $js ) {
		if ( ! is_string( $js ) || '' === $js ) {
			return $js;
		}

		$out = '';
		foreach ( self::scan_tokens( $js, 'js' ) as $token ) {
			if ( 's' === $token[0] ) {
				$out .= $token[1];
				continue;
			}
			// 只压缩水平空白，保留换行以维持 JavaScript 自动分号插入（ASI）语义。
			$code = preg_replace( '/[^\S\r\n]+/', ' ', $token[1] );
			$code = preg_replace( '/\r\n?/', "\n", $code );
			$code = preg_replace( '/[ \t]*([{}();,:=])[ \t]*/', '$1', $code );
			$code = preg_replace( '/;[ \t]*}/', '}', $code );
			$out .= $code;
		}

		return trim( $out );
	}

	/**
	 * HTML 压缩，保留预格式化标签与脚本/样式内容。
	 *
	 * @param string $html HTML 源码。
	 * @return string
	 */
	public static function minify_html( $html ) {
		if ( ! is_string( $html ) || '' === $html ) {
			return $html;
		}

		$parts = preg_split(
			'/(<(?:pre|textarea|script|style)\b[^>]*>.*?<\/(?:pre|textarea|script|style)>)/is',
			$html,
			-1,
			PREG_SPLIT_DELIM_CAPTURE
		);

		if ( ! is_array( $parts ) ) {
			return $html;
		}

		foreach ( $parts as $index => $part ) {
			if ( 1 === $index % 2 ) {
				continue;
			}

			$part = preg_replace( '/<!--(?!\[if\b).*?-->/s', '', $part );
			// 标签间空白可能影响行内元素的可见间距，最多压缩为一个空格。
			$part = preg_replace( '/>\s+</', '> <', $part );
			$part = preg_replace( '/[ \t]+/', ' ', $part );
			$part = preg_replace( '/\s*\n\s*/', "\n", $part );
			$part = preg_replace( '/\n{2,}/', "\n", $part );

			$parts[ $index ] = $part;
		}

		return trim( implode( '', $parts ) );
	}

	/**
	 * 统计某缓存目录下的文件数量。
	 *
	 * @param string $sub 子目录。
	 * @return int
	 */
	public static function count_files( $sub ) {
		$dir = self::sub_path( $sub );
		if ( ! is_dir( $dir ) ) {
			return 0;
		}

		$count = 0;

		try {
			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS )
			);

			foreach ( $iterator as $file ) {
				if ( ! $file->isFile() ) {
					continue;
				}

				$name = $file->getFilename();
				if ( 'index.php' === $name || '.htaccess' === $name ) {
					continue;
				}
				if ( 'pages' === $sub && '.html' !== substr( $name, -5 ) ) {
					continue;
				}
				$count++;
			}
		} catch ( \Exception $e ) {
			return 0;
		}

		return $count;
	}
}
