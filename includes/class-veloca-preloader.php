<?php
/**
 * 缓存预加载：本地 URL 队列、分批后台请求与状态记录。
 *
 * @package VelocityCache
 */

defined( 'ABSPATH' ) || exit;

final class VELOCA_Preloader {

	const HOOK  = 'veloca_preload_batch';
	const BUILD = 'veloca_preload_build';
	const QUEUE = 'veloca_preload_queue';
	const LOCK  = 'veloca_preload_lock';
	const LAST  = 'veloca_last_preload';
	const BATCH = 5;
	const LIMIT = 200;

	/**
	 * 注册后台批处理钩子。
	 */
	public static function init() {
		add_action( self::HOOK, array( __CLASS__, 'process_batch' ) );
		add_action( self::BUILD, array( __CLASS__, 'schedule' ) );
		add_action( 'init', array( __CLASS__, 'maybe_heal_queue' ) );
	}

	/**
	 * 重建下一批调度：先清空现存事件，再用唯一参数避免 WP 的 10 分钟去重把续期吞掉。
	 *
	 * @param int $delay 延迟秒数。
	 */
	private static function reschedule( $delay = 5 ) {
		wp_clear_scheduled_hook( self::HOOK );
		wp_schedule_single_event( time() + max( 1, (int) $delay ), self::HOOK, array( wp_generate_password( 8, false, false ) ) );
	}

	/**
	 * 队列有积压但调度丢失时自愈，不依赖管理员访问特定页面。
	 */
	public static function maybe_heal_queue() {
		if ( get_transient( 'veloca_preload_heal_check' ) ) {
			return;
		}
		set_transient( 'veloca_preload_heal_check', 1, 5 * MINUTE_IN_SECONDS );

		$queue = get_option( self::QUEUE, array() );
		if ( ! is_array( $queue ) || empty( $queue ) || get_transient( self::LOCK ) ) {
			return;
		}
		if ( ! self::has_scheduled( self::HOOK ) && ! self::has_scheduled( self::BUILD ) ) {
			self::reschedule( 10 );
		}
	}

	/**
	 * 判断某个钩子是否已有待执行事件（不论参数）。
	 *
	 * @param string $hook 钩子名。
	 * @return bool
	 */
	private static function has_scheduled( $hook ) {
		$cron = _get_cron_array();
		if ( ! is_array( $cron ) ) {
			return false;
		}
		foreach ( $cron as $event ) {
			if ( isset( $event[ $hook ] ) && is_array( $event[ $hook ] ) && ! empty( $event[ $hook ] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * 把站点地图发现放到后台，避免清理缓存的当前请求被远程请求阻塞。
	 */
	public static function schedule_async() {
		if ( ! self::has_scheduled( self::BUILD ) && ! self::has_scheduled( self::HOOK ) ) {
			wp_schedule_single_event( time() + 3, self::BUILD, array( wp_generate_password( 8, false, false ) ) );
		}
	}

	/**
	 * 建立预加载队列并安排后台执行。
	 *
	 * @param array $urls 可选的 URL 列表；为空时使用设置和站点地图。
	 * @return int|WP_Error 入队数量。
	 */
	public static function schedule( $urls = array() ) {
		$settings = VELOCA_Settings::get();
		$input    = ! empty( $urls ) && is_array( $urls ) ? $urls : VELOCA_Tools::rule_list( $settings['preload_urls'] );

		if ( empty( $input ) ) {
			$input = self::discover_urls();
		}

		$input[] = home_url( '/' );
		$queue   = array();

		foreach ( $input as $url ) {
			if ( ! is_scalar( $url ) ) {
				continue;
			}
			$url = esc_url_raw( trim( (string) $url ) );
			if ( self::is_local_url( $url ) ) {
				$queue[ $url ] = $url;
			}
			if ( count( $queue ) >= self::LIMIT ) {
				break;
			}
		}

		$queue = array_values( $queue );
		update_option( self::QUEUE, $queue, false );

		if ( empty( $queue ) ) {
			return new WP_Error( 'veloca_preload_empty', '没有可预加载的站内 URL。' );
		}

		if ( ! self::has_scheduled( self::HOOK ) ) {
			self::reschedule( 3 );
		}

		return count( $queue );
	}

	/**
	 * 后台每批请求少量 URL，避免长时间占用 PHP 进程。
	 */
	public static function process_batch() {
		if ( get_transient( self::LOCK ) ) {
			return;
		}

		$queue = get_option( self::QUEUE, array() );
		if ( ! is_array( $queue ) || empty( $queue ) ) {
			delete_option( self::QUEUE );
			return;
		}

		set_transient( self::LOCK, 1, 2 * MINUTE_IN_SECONDS );
		$batch   = array_slice( $queue, 0, self::BATCH );
		$remain  = array_slice( $queue, self::BATCH );
		$success = 0;
		$failed  = 0;

		foreach ( $batch as $url ) {
			if ( ! self::is_local_url( $url ) ) {
				$failed++;
				continue;
			}

			$response = wp_remote_get(
				$url,
				array(
					'timeout'     => 20,
					'redirection' => 3,
					'sslverify'   => true,
					'headers'     => array( 'User-Agent' => 'Velocity-Cache-Preloader/' . VELOCA_VERSION ),
				)
			);
			$code = is_wp_error( $response ) ? 0 : wp_remote_retrieve_response_code( $response );

			if ( $code >= 200 && $code < 400 ) {
				$success++;
			} else {
				$failed++;
				VELOCA_Tools::log( 'preload request failed', array( 'url' => $url, 'error' => is_wp_error( $response ) ? $response->get_error_message() : $code ) );
			}
		}

		if ( empty( $remain ) ) {
			delete_option( self::QUEUE );
			set_transient(
				self::LAST,
				array( 'time' => time(), 'success' => $success, 'failed' => $failed ),
				DAY_IN_SECONDS
			);
		} else {
			update_option( self::QUEUE, array_values( $remain ), false );
			self::reschedule( 5 );
		}

		delete_transient( self::LOCK );
	}

	/**
	 * 当前待处理 URL 数量。
	 *
	 * @return int
	 */
	public static function queue_count() {
		$queue = get_option( self::QUEUE, array() );
		return is_array( $queue ) ? count( $queue ) : 0;
	}

	/**
	 * 停用时移除队列和计划任务。
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( self::HOOK );
		wp_clear_scheduled_hook( self::BUILD );
		delete_option( self::QUEUE );
		delete_transient( self::LOCK );
		delete_transient( 'veloca_preload_heal_check' );
	}

	/**
	 * 从常见站点地图发现 URL，失败时仍至少预加载首页。
	 *
	 * @return array
	 */
	private static function discover_urls() {
		$candidates = array();

		// 核心 sitemap 由 WordPress 解析，不在代码中硬编码其路径。
		if ( function_exists( 'get_sitemap_url' ) ) {
			$core_sitemap = get_sitemap_url( 'index' );
			if ( is_string( $core_sitemap ) && '' !== $core_sitemap ) {
				$candidates[] = $core_sitemap;
			}
		}

		// 兼容常见 SEO 插件的 sitemap 索引。
		$candidates[] = home_url( '/sitemap_index.xml' );
		$urls         = array();

		foreach ( $candidates as $sitemap ) {
			$urls = array_merge( $urls, self::read_sitemap( $sitemap, 0 ) );
			if ( ! empty( $urls ) ) {
				break;
			}
		}

		// 各语言首页（无论子域/目录/参数模式）不在主站 sitemap 里，显式加入保证预加载覆盖。
		foreach ( VELOCA_Tools::language_urls() as $home_url ) {
			$urls[] = rtrim( $home_url, '/' ) . '/';
		}

		return $urls;
	}

	/**
	 * 读取站点地图或站点地图索引，限制层级和数量。
	 *
	 * @param string $url   地图 URL。
	 * @param int    $depth 当前递归深度。
	 * @return array
	 */
	private static function read_sitemap( $url, $depth ) {
		if ( $depth > 1 || ! self::is_local_url( $url ) || ! function_exists( 'simplexml_load_string' ) ) {
			return array();
		}

		$response = wp_remote_get( $url, array( 'timeout' => 12, 'redirection' => 2, 'sslverify' => true ) );
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return array();
		}

		$body = wp_remote_retrieve_body( $response );
		if ( '' === $body || strlen( $body ) > 5242880 ) {
			return array();
		}

		$xml = simplexml_load_string( $body, 'SimpleXMLElement', LIBXML_NONET );
		if ( false === $xml ) {
			return array();
		}

		$urls = array();
		if ( isset( $xml->sitemap ) ) {
			$count = 0;
			foreach ( $xml->sitemap as $item ) {
				$child = (string) $item->loc;
				$urls  = array_merge( $urls, self::read_sitemap( $child, $depth + 1 ) );
				$count++;
				if ( $count >= 5 || count( $urls ) >= self::LIMIT ) {
					break;
				}
			}
		} elseif ( isset( $xml->url ) ) {
			foreach ( $xml->url as $item ) {
				$loc = (string) $item->loc;
				if ( self::is_local_url( $loc ) ) {
					$urls[] = $loc;
				}
				if ( count( $urls ) >= self::LIMIT ) {
					break;
				}
			}
		}

		return $urls;
	}

	/**
	 * 仅允许当前站点 host，防止预加载变成任意外部请求。
	 *
	 * @param string $url URL。
	 * @return bool
	 */
	private static function is_local_url( $url ) {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return false;
		}

		return in_array( strtolower( $parts['host'] ), VELOCA_Tools::allowed_hosts(), true ) && in_array( isset( $parts['scheme'] ) ? $parts['scheme'] : '', array( 'http', 'https' ), true );
	}
}
