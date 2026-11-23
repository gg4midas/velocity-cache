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
		if ( ! is_string( $html ) || '' === $html || ( defined( 'DONOTMINIFY' ) && DONOTMINIFY ) ) {
			return $html;
		}

		if ( ! empty( $settings['enable_css_minify'] ) || ! empty( $settings['enable_css_combine'] ) ) {
			$html = self::optimize_css( $html, $settings );
		}

		if ( ! empty( $settings['enable_js_minify'] ) ) {
			$html = self::optimize_js( $html, $settings );
		}

		if ( ! empty( $settings['enable_js_defer'] ) || ! empty( $settings['enable_js_delay'] ) ) {
			$html = self::optimize_script_loading( $html, $settings );
		}

		if ( ! empty( $settings['enable_lazyload'] ) || ! empty( $settings['lazyload_iframes'] ) ) {
			$html = self::optimize_lazyload( $html, $settings );
		}

		if ( ! empty( $settings['enable_cdn'] ) && ! empty( $settings['cdn_url'] ) ) {
			$html = self::rewrite_cdn( $html, $settings['cdn_url'] );
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

		$replacements = array();
		$candidates   = array();

		foreach ( $matches[0] as $index => $found ) {
			$tag    = $found[0];
			$offset = $found[1];

			if ( ! preg_match( '/\brel\s*=\s*(["\'])stylesheet\1/i', $tag ) ) {
				continue;
			}

			if ( ! preg_match( '/\bhref\s*=\s*(["\'])(.*?)\1/i', $tag, $href_match, PREG_OFFSET_CAPTURE ) ) {
				continue;
			}

			$href = html_entity_decode( $href_match[2][0], ENT_QUOTES, get_option( 'blog_charset' ) );
			if ( '' === $href || preg_match( '/^(?:data:|javascript:)/i', $href ) ) {
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
				$candidates[] = array(
					'tag'           => $tag,
					'offset'        => $offset,
					'length'        => strlen( $tag ),
					'media'         => $media,
					'css'           => $css,
					'original_size' => $original_size,
					'fingerprint'   => $fingerprint,
					'href_offset'   => $href_match[2][1],
					'href_length'   => strlen( $href_match[2][0] ),
				);
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
			$newtag = substr_replace( $tag, esc_url( $url ), $href_match[2][1], strlen( $href_match[2][0] ) );
			$replacements[] = array( 'offset' => $offset, 'length' => strlen( $tag ), 'value' => $newtag );
		}

		if ( $combine && ! empty( $candidates ) ) {
			$runs     = array();
			$run      = array();
			$previous = null;

			// 只合并相邻且 media 相同的样式表，避免改变 CSS 级联顺序。
			foreach ( $candidates as $candidate ) {
				$adjacent = false;
				if ( null !== $previous && $previous['media'] === $candidate['media'] ) {
					$gap_start = $previous['offset'] + $previous['length'];
					$gap       = substr( $html, $gap_start, $candidate['offset'] - $gap_start );
					$adjacent  = '' === trim( $gap );
				}

				if ( ! $adjacent && ! empty( $run ) ) {
					$runs[] = $run;
					$run    = array();
				}

				$run[]    = $candidate;
				$previous = $candidate;
			}
			if ( ! empty( $run ) ) {
				$runs[] = $run;
			}

			foreach ( $runs as $group ) {
				if ( 1 === count( $group ) && ( ! $minify || strlen( $group[0]['css'] ) >= $group[0]['original_size'] * 0.98 ) ) {
					continue;
				}

				$parts        = wp_list_pluck( $group, 'css' );
				$fingerprints = wp_list_pluck( $group, 'fingerprint' );
				$hash         = substr( md5( implode( '|', $fingerprints ) . '|' . $version ), 0, 12 ) . '.css';
				$out          = VC_Tools::sub_path( 'assets', 'css/' . $hash );
				$body         = implode( "\n", $parts );

				if ( ! is_file( $out ) ) {
					$written = VC_Tools::write_file( $out, $body );
					if ( is_wp_error( $written ) ) {
						VC_Tools::log( 'combined css write failed', array( 'error' => $written->get_error_message() ) );
						continue;
					}
				}

				$first  = $group[0];
				$url    = VC_Tools::cache_base_url() . 'assets/css/' . $hash . '?ver=vc' . $version;
				$newtag = substr_replace( $first['tag'], esc_url( $url ), $first['href_offset'], $first['href_length'] );

				$replacements[] = array( 'offset' => $first['offset'], 'length' => $first['length'], 'value' => $newtag );
				for ( $i = 1, $total = count( $group ); $i < $total; $i++ ) {
					$replacements[] = array( 'offset' => $group[ $i ]['offset'], 'length' => $group[ $i ]['length'], 'value' => '' );
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
				$newfull = substr_replace( $full, esc_url( $url ), $src_match[2][1], strlen( $src_raw ) );
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

	/**
	 * 使用浏览器原生 LazyLoad，跳过首屏图片和显式排除元素。
	 *
	 * @param string $html     HTML。
	 * @param array  $settings 设置。
	 * @return string
	 */
	private static function optimize_lazyload( $html, $settings ) {
		if ( ! empty( $settings['enable_lazyload'] ) ) {
			$seen = 0;
			$skip = isset( $settings['lazyload_skip'] ) ? (int) $settings['lazyload_skip'] : 2;
			$html = preg_replace_callback(
				'/<img\b[^>]*>/i',
				static function ( $matches ) use ( &$seen, $skip ) {
					$seen++;
					$tag = $matches[0];
					if ( $seen <= $skip || preg_match( '/\b(?:loading|fetchpriority)\s*=/i', $tag ) || preg_match( '/(?:no-lazy|skip-lazy|data-no-lazy)/i', $tag ) ) {
						return $tag;
					}

					$attributes = ' loading="lazy"';
					if ( ! preg_match( '/\bdecoding\s*=/i', $tag ) ) {
						$attributes .= ' decoding="async"';
					}
					return '<img' . $attributes . substr( $tag, 4 );
				},
				$html
			);
		}

		if ( ! empty( $settings['lazyload_iframes'] ) ) {
			$html = preg_replace_callback(
				'/<iframe\b[^>]*>/i',
				static function ( $matches ) {
					$tag = $matches[0];
					if ( preg_match( '/\bloading\s*=/i', $tag ) || preg_match( '/(?:no-lazy|skip-lazy|data-no-lazy)/i', $tag ) ) {
						return $tag;
					}
					return '<iframe loading="lazy"' . substr( $tag, 7 );
				},
				$html
			);
		}

		return $html;
	}

	/**
	 * 为外链脚本增加 defer，或仅把明确匹配的脚本延迟到交互后执行。
	 *
	 * @param string $html     HTML。
	 * @param array  $settings 设置。
	 * @return string
	 */
	private static function optimize_script_loading( $html, $settings ) {
		$count = preg_match_all( '/<script\b[^>]*>.*?<\/script>/is', $html, $matches, PREG_OFFSET_CAPTURE );
		if ( ! $count ) {
			return $html;
		}

		$exclude      = isset( $settings['exclude_js_handles'] ) ? $settings['exclude_js_handles'] : '';
		$delay_rules  = isset( $settings['delay_js_handles'] ) ? $settings['delay_js_handles'] : '';
		$replacements = array();
		$delayed      = false;

		foreach ( $matches[0] as $found ) {
			$full   = $found[0];
			$offset = $found[1];
			if ( ! preg_match( '/^<script\b[^>]*>/i', $full, $open_match ) ) {
				continue;
			}
			$open = $open_match[0];

			if ( preg_match( '/\b(?:async|defer|nomodule)\b/i', $open ) || preg_match( '/\btype\s*=\s*(["\'])(?:module|application\/json|text\/template)\1/i', $open ) ) {
				continue;
			}
			if ( ! preg_match( '/\bsrc\s*=\s*(["\'])(.*?)\1/i', $open, $src_match, PREG_OFFSET_CAPTURE ) ) {
				continue;
			}

			$src      = html_entity_decode( $src_match[2][0], ENT_QUOTES, get_option( 'blog_charset' ) );
			$identity = $src;
			if ( preg_match( '/\bid\s*=\s*(["\'])([^"\']+)\1/i', $open, $id_match ) ) {
				$identity = $id_match[2];
			}

			$delay = ! empty( $settings['enable_js_delay'] ) && ( VC_Tools::match_rules( $src, $delay_rules ) || VC_Tools::match_rules( $identity, $delay_rules ) );
			if ( $delay ) {
				$without_src = substr_replace( $open, '', $src_match[0][1], strlen( $src_match[0][0] ) );
				$without_src = preg_replace( '/\s+type\s*=\s*(["\']).*?\1/i', '', $without_src );
				// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- Inline bootstrap is injected into final cached HTML after the enqueue phase.
				$new_open = '<script type="velocity-lazy" data-vc-delay="1" data-vc-src="' . esc_attr( $src ) . '"' . substr( $without_src, 7 );
				$newfull     = $new_open . substr( $full, strlen( $open ) );
				$delayed     = true;
			} elseif ( ! empty( $settings['enable_js_defer'] ) && false === stripos( $src . ' ' . $identity, 'jquery' ) && ! VC_Tools::match_rules( $src, $exclude ) && ! VC_Tools::match_rules( $identity, $exclude ) ) {
				$newfull = '<script defer="defer"' . substr( $full, 7 );
			} else {
				continue;
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

		if ( $delayed ) {
			$bootstrap = '<script id="vc-delay-bootstrap">(function(){var done=false;function load(){if(done)return;done=true;var nodes=document.querySelectorAll("script[data-vc-delay][data-vc-src]");Array.prototype.forEach.call(nodes,function(old){var s=document.createElement("script");Array.prototype.forEach.call(old.attributes,function(a){if(a.name!=="type"&&a.name!=="data-vc-delay"&&a.name!=="data-vc-src")s.setAttribute(a.name,a.value);});s.async=false;s.src=old.getAttribute("data-vc-src");old.parentNode.insertBefore(s,old);old.parentNode.removeChild(old);});}["mousemove","keydown","scroll","touchstart","click"].forEach(function(e){document.addEventListener(e,load,{once:true,passive:true});});window.setTimeout(load,5000);})();</script>';
			$pos       = stripos( $html, '</body>' );
			$html      = false === $pos ? $html . $bootstrap : substr_replace( $html, $bootstrap, $pos, 0 );
		}

		return $html;
	}

	/**
	 * 仅重写本地静态资源的 src/href，不修改页面链接和动态端点。
	 *
	 * @param string $html    HTML。
	 * @param string $cdn_url CDN 前缀。
	 * @return string
	 */
	private static function rewrite_cdn( $html, $cdn_url ) {
		$cdn_url = untrailingslashit( $cdn_url );
		$content = trailingslashit( content_url( '/' ) );
		$includes = trailingslashit( includes_url() );
		$content_path = wp_parse_url( $content, PHP_URL_PATH );
		$includes_path = wp_parse_url( $includes, PHP_URL_PATH );
		$content_path = is_string( $content_path ) ? trailingslashit( $content_path ) : '/wp-content/';
		$includes_path = is_string( $includes_path ) ? trailingslashit( $includes_path ) : '/wp-includes/';
		$map      = array(
			$content  => $cdn_url . $content_path,
			$includes => $cdn_url . $includes_path,
		);
		$map[ preg_replace( '~^https?:~', '', $content ) ]  = $cdn_url . $content_path;
		$map[ preg_replace( '~^https?:~', '', $includes ) ] = $cdn_url . $includes_path;

		$result = preg_replace_callback(
			'/\b(src|href)\s*=\s*(["\'])(.*?)\2/i',
			static function ( $matches ) use ( $map ) {
				$url = html_entity_decode( $matches[3], ENT_QUOTES, get_option( 'blog_charset' ) );
				$path = wp_parse_url( $url, PHP_URL_PATH );
				if ( ! is_string( $path ) || ! preg_match( '/\.(?:css|js|png|jpe?g|gif|webp|avif|svg|woff2?|ttf|eot)$/i', $path ) ) {
					return $matches[0];
				}

				foreach ( $map as $local => $cdn ) {
					if ( 0 === strpos( $url, $local ) ) {
						$url = $cdn . substr( $url, strlen( $local ) );
						break;
					}
				}
				return $matches[1] . '=' . $matches[2] . esc_url( $url ) . $matches[2];
			},
			$html
		);

		return null === $result ? $html : $result;
	}
}
