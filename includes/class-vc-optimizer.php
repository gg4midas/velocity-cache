<?php
/**
 * 前端优化：HTML 压缩、CSS 压缩/合并、JavaScript 压缩。
 *
 * @package VelocityCache
 */

defined( 'ABSPATH' ) || exit;

final class VC_Optimizer {

	/**
	 * 按配置依次执行优化。
	 *
	 * @param string $html     HTML 源码。
	 * @param array  $settings 设置。
	 * @return string
	 */
	public static function process( $html, $settings ) {
		if ( ! is_string( $html ) || '' === $html ) {
			return $html;
		}

		if ( ! empty( $settings['enable_css_minify'] ) || ! empty( $settings['enable_css_combine'] ) ) {
			$html = self::optimize_css( $html, $settings );
		}

		if ( ! empty( $settings['enable_js_minify'] ) ) {
			$html = self::optimize_js( $html, $settings );
		}

		if ( ! empty( $settings['enable_html_minify'] ) ) {
			$html = VC_Tools::minify_html( $html );
		}

		return $html;
	}

	/**
	 * CSS 压缩与合并。
	 *
	 * @param string $html     HTML 源码。
	 * @param array  $settings 设置。
	 * @return string
	 */
	private static function optimize_css( $html, $settings ) {
		$count = preg_match_all( '/<link\b[^>]*>/i', $html, $matches, PREG_OFFSET_CAPTURE );
		if ( ! $count ) {
			return self::optimize_inline_styles( $html, $settings );
		}

		$combine = ! empty( $settings['enable_css_combine'] );
		$minify  = ! empty( $settings['enable_css_minify'] );
		$exclude = isset( $settings['exclude_css_handles'] ) ? $settings['exclude_css_handles'] : '';
		$version = (int) ( isset( $settings['asset_version'] ) ? $settings['asset_version'] : 1 );

		$replacements        = array();
		$groups              = array();
		$combined_candidates = array();

		foreach ( $matches[0] as $index => $found ) {
			$tag    = $found[0];
			$offset = $found[1];

			if ( ! preg_match( '/\brel\s*=\s*(["\'])stylesheet\1/i', $tag ) ) {
				continue;
			}

			if ( ! preg_match( '/\bhref\s*=\s*(["\'])(.*?)\1/i', $tag, $href_match ) ) {
				continue;
			}

			$href = html_entity_decode( $href_match[2], ENT_QUOTES, get_option( 'blog_charset' ) );
			if ( '' === $href ||preg_match( '/^(?:data:|javascript:)/i', $href ) ) {
				continue;
			}

			$identity = $href;
			if ( preg_match( '/\bid\s*=\s*(["\'])([^"\']*?)-css\1/i', $tag, $id_match ) ) {
				$identity = $id_match[2];
			}

			if ( VC_Tools::match_rules( $identity, $exclude ) || VC_Tools::match_rules( $href, $exclude ) ) {
				continue;
			}

			$local = VC_Tools::local_file( $href );
			if ( '' === $local ) {
				continue;
			}

			$css = @file_get_contents( $local );
			if ( false === $css || '' === $css ) {
				continue;
			}

			$original_size = strlen( $css );

			// @import 依赖原始文件位置，合并会改变语义，保持原样。
			if ( false !== stripos( $css, '@import' ) ) {
				continue;
			}

			if ( $minify ) {
				$css = VC_Tools::minify_css( $css );
			}

			$css = VC_Tools::absolutize_css( $css, $href );

			// 非合并模式需要把文件搬到缓存目录，必须改写相对 URL；
			// 若改写后没有明显变小，就保留原始标签，避免负收益。
			if ( ! $combine && ( ! $minify || strlen( $css ) >= $original_size * 0.98 ) ) {
				continue;
			}

			$media = 'all';
			if ( preg_match( '/\bmedia\s*=\s*(["\'])([^"\']*)\1/i', $tag, $media_match ) && '' !== trim( $media_match[2] ) ) {
				$media = trim( $media_match[2] );
			}

			$fingerprint = md5( $href . '|' . (string) filesize( $local ) . '|' . (string) filemtime( $local ) . '|' . md5( $css ) );

			if ( $combine ) {
				$groups[ $media ]['parts'][]        = $css;
				$groups[ $media ]['fingerprints'][] = $fingerprint;
				$combined_candidates[]              = array( 'offset' => $offset, 'length' => strlen( $tag ), 'media' => $media );
				continue;
			}

			if ( ! $minify ) {
				continue;
			}

			$hash = substr( md5( $href . '|' . $version . '|' . $fingerprint ), 0, 12 ) . '.css';
			$out  = VC_Tools::sub_path( 'assets', 'css/' . $hash );

			if ( ! is_file( $out ) ) {
				$written = VC_Tools::write_file( $out, $css );
				if ( is_wp_error( $written ) ) {
					VC_Tools::log( 'css asset write failed', array( 'error' => $written->get_error_message() ) );
					continue;
				}
			}

			$url    = VC_Tools::cache_base_url() . 'assets/css/' . $hash . '?ver=vc' . $version;
			$newtag = '<link rel="stylesheet" href="' . esc_url( $url ) . '" media="' . esc_attr( $media ) . '">';
			$replacements[] = array( 'offset' => $offset, 'length' => strlen( $tag ), 'value' => $newtag );
		}

		$written_media = array();
		$links         = '';

		if ( $combine && ! empty( $groups ) ) {
			foreach ( $groups as $media => $group ) {
				$hash  = substr( md5( implode( '|', $group['fingerprints'] ) . '|' . $version ), 0, 12 ) . '.css';
				$out   = VC_Tools::sub_path( 'assets', 'css/' . $hash );
				$body  = implode( "\n", $group['parts'] );

				if ( ! is_file( $out ) ) {
					$written = VC_Tools::write_file( $out, $body );
					if ( is_wp_error( $written ) ) {
						VC_Tools::log( 'combined css write failed', array( 'error' => $written->get_error_message() ) );
						continue;
					}
				}

				$url   = VC_Tools::cache_base_url() . 'assets/css/' . $hash . '?ver=vc' . $version;
				$links .= '<link rel="stylesheet" id="vc-combined-' . esc_attr( md5( $media ) ) . '-css" href="' . esc_url( $url ) . '" media="' . esc_attr( $media ) . '">';
				$written_media[ $media ] = true;
			}

			foreach ( $combined_candidates as $candidate ) {
				if ( ! empty( $written_media[ $candidate['media'] ] ) ) {
					$replacements[] = array( 'offset' => $candidate['offset'], 'length' => $candidate['length'], 'value' => '' );
				}
			}

		}

		// 从后往前替换，避免偏移失效。
		usort(
			$replacements,
			static function ( $a, $b ) {
				return $b['offset'] <=> $a['offset'];
			}
		);

		foreach ( $replacements as $replacement ) {
			$html = substr_replace( $html, $replacement['value'], $replacement['offset'], $replacement['length'] );
		}

		if ( '' !== $links ) {
			$pos = stripos( $html, '</head>' );
			if ( false !== $pos ) {
				$html = substr_replace( $html, $links, $pos, 0 );
			} else {
				$html = $links . $html;
			}
		}

		return self::optimize_inline_styles( $html, $settings );
	}

	/**
	 * 压缩内联 style 标签内容。
	 *
	 * @param string $html     HTML 源码。
	 * @param array  $settings 设置。
	 * @return string
	 */
	private static function optimize_inline_styles( $html, $settings ) {
		if ( empty( $settings['enable_css_minify'] ) ) {
			return $html;
		}

		$result = preg_replace_callback(
			'/(<style\b[^>]*>)(.*?)(<\/style>)/is',
			static function ( $matches ) {
				$css = VC_Tools::minify_css( $matches[2] );
				return $matches[1] . $css . $matches[3];
			},
			$html
		);

		return null === $result ? $html : $result;
	}

	/**
	 * JavaScript 压缩：本地外链脚本与内联脚本。
	 *
	 * @param string $html     HTML 源码。
	 * @param array  $settings 设置。
	 * @return string
	 */
	private static function optimize_js( $html, $settings ) {
		$count = preg_match_all( '/<script\b[^>]*>.*?<\/script>/is', $html, $matches, PREG_OFFSET_CAPTURE );
		if ( ! $count ) {
			return $html;
		}

		$exclude = isset( $settings['exclude_js_handles'] ) ? $settings['exclude_js_handles'] : '';
		$version = (int) ( isset( $settings['asset_version'] ) ? $settings['asset_version'] : 1 );
		$replacements = array();

		foreach ( $matches[0] as $index => $found ) {
			$full   = $found[0];
			$offset = $found[1];

			if ( ! preg_match( '/^<script\b[^>]*>/i', $full, $open_match ) ) {
				continue;
			}

			$open = $open_match[0];

			if ( preg_match( '/\btype\s*=\s*(["\'])(.*?)\1/i', $open, $type_match ) ) {
				$type = strtolower( trim( $type_match[2] ) );
				if ( 'text/javascript' !== $type && 'application/javascript' !== $type && '' !== $type ) {
					continue;
				}
			}

			if ( preg_match( '/\b(?:async|defer|nomodule)\b/i', $open ) || false !== stripos( $open, 'module' ) ) {
				continue;
			}

			if ( preg_match( '/\bsrc\s*=\s*(["\'])(.*?)\1/i', $open, $src_match, PREG_OFFSET_CAPTURE ) ) {
				$src_raw = $src_match[2][0];
				$src     = html_entity_decode( $src_raw, ENT_QUOTES, get_option( 'blog_charset' ) );
				if ( '' === $src || VC_Tools::match_rules( $src, $exclude ) || preg_match( '/^(?:data:|javascript:)/i', $src ) ) {
					continue;
				}

				$local = VC_Tools::local_file( $src );
				if ( '' === $local ) {
					continue;
				}

				$js = @file_get_contents( $local );
				if ( false === $js || '' === $js ) {
					continue;
				}

				$min = VC_Tools::minify_js( $js );
				if ( strlen( $min ) >= strlen( $js ) * 0.98 ) {
					continue;
				}

				$hash = substr( md5( $src . '|' . $version . '|' . md5( $min ) ), 0, 12 ) . '.js';
				$out  = VC_Tools::sub_path( 'assets', 'js/' . $hash );

				if ( ! is_file( $out ) ) {
					$written = VC_Tools::write_file( $out, $min );
					if ( is_wp_error( $written ) ) {
						VC_Tools::log( 'js asset write failed', array( 'error' => $written->get_error_message() ) );
						continue;
					}
				}

				$url     = VC_Tools::cache_base_url() . 'assets/js/' . $hash . '?ver=vc' . $version;
				$newfull = substr_replace( $full, 'src="' . esc_url( $url ) . '"', $src_match[2][1], strlen( $src_raw ) );
			} else {
				$inline = substr( $full, strlen( $open ), -9 );
				if ( false !== strpos( $inline, '<!--' ) || false !== strpos( $inline, '<![CDATA[' ) ) {
					continue;
				}

				$min = VC_Tools::minify_js( $inline );
				if ( '' === $min || strlen( $min ) >= strlen( $inline ) * 0.98 ) {
					continue;
				}

				$newfull = $open . $min . '</script>';
			}

			$replacements[] = array( 'offset' => $offset, 'length' => strlen( $full ), 'value' => $newfull );
		}

		usort(
			$replacements,
			static function ( $a, $b ) {
				return $b['offset'] <=> $a['offset'];
			}
		);

		foreach ( $replacements as $replacement ) {
			$html = substr_replace( $html, $replacement['value'], $replacement['offset'], $replacement['length'] );
		}

		return $html;
	}
}
