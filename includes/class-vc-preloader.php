<?php
/**
 * 缓存预加载：本地 URL 队列、分批后台请求与状态记录。
 *
 * @package VelocityCache
 */

defined( 'ABSPATH' ) || exit;

final class VC_Preloader {

	const HOOK  = 'vc_preload_batch';
	const BUILD = 'vc_preload_build';
	const QUEUE = 'vc_preload_queue';
	const LOCK  = 'vc_preload_lock';
	const LAST  = 'vc_last_preload';
	const BATCH = 5;
	const LIMIT = 200;

	/**
	 * 注册后台批处理钩子。
	 */
	public static function init() {
		add_action( self::HOOK, array( __CLASS__, 'process_batch' ) );
		add_action( self::BUILD, array( __CLASS__, 'schedule' ) );
	}

	/**
	 * 把站点地图发现放到后台，避免清理缓存的当前请求被远程请求阻塞。
	 */
	public static function schedule_async() {
		if ( ! wp_next_scheduled( self::BUILD ) && ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_single_event( time() + 3, self::BUILD );
		}
	}

	/**
	 * 建立预加载队列并安排后台执行。
	 *
	 * @param array $urls 可选的 URL 列表；为空时使用设置和站点地图。
	 * @return int|WP_Error 入队数量。
	 */
	public static function schedule( $urls = array() ) {
		$settings = VC_Settings::get();
		$input    = ! empty( $urls ) && is_array( $urls ) ? $urls : VC_Tools::rule_list( $settings['preload_urls'] );

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
			return new WP_Error( 'vc_preload_empty', '没有可预加载的站内 URL。' );
		}

		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_single_event( time() + 3, self::HOOK );
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
					'headers'     => array( 'User-Agent' => 'Velocity-Cache-Preloader/' . VC_VERSION ),
				)
			);
			$code = is_wp_error( $response ) ? 0 : wp_remote_retrieve_response_code( $response );

			if ( $code >= 200 && $code < 400 ) {
				$success++;
			} else {
				$failed++;
				VC_Tools::log( 'preload request failed', array( 'url' => $url, 'error' => is_wp_error( $response ) ? $response->get_error_message() : $code ) );
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
			wp_schedule_single_event( time() + 5, self::HOOK );
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
		$timestamp = wp_next_scheduled( self::HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::HOOK );
		}
		$timestamp = wp_next_scheduled( self::BUILD );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::BUILD );
		}
		delete_option( self::QUEUE );
		delete_transient( self::LOCK );
	}

	/**
	 * 从常见站点地图发现 URL，失败时仍至少预加载首页。
	 *
	 * @return array
	 */
	private static function discover_urls() {
		$candidates = array( home_url( '/wp-sitemap.xml' ), home_url( '/sitemap_index.xml' ) );
		$urls       = array();

		foreach ( $candidates as $sitemap ) {
			$urls = array_merge( $urls, self::read_sitemap( $sitemap, 0 ) );
			if ( ! empty( $urls ) ) {
				break;
			}
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
		$home  = wp_parse_url( home_url( '/' ) );
		if ( ! is_array( $parts ) || ! is_array( $home ) || empty( $parts['host'] ) || empty( $home['host'] ) ) {
			return false;
		}

		return strtolower( $parts['host'] ) === strtolower( $home['host'] ) && in_array( isset( $parts['scheme'] ) ? $parts['scheme'] : '', array( 'http', 'https' ), true );
	}
}
