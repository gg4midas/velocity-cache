=== Velocity Cache ===
Contributors: gg4mida0025
Tags: cache, page-cache, minify, performance, optimization
Requires at least: 5.8
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.2.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Lightweight WordPress caching and optimization: page cache, minification, preloading, lazy loading, and cache versioning.

== Description ==

Velocity Cache is an independent WordPress performance plugin. It generates static HTML for anonymous visitors, optimizes local assets, and gives site owners explicit control over cache invalidation and exclusions. It does not require a commercial service, cloud account, or API key.

= Key features =

* Page caching: static HTML for anonymous GET requests, with TTL, exclusion rules, conditional requests, and manual purge.
* Cache preloading: build a queue from custom URLs or common sitemaps and process it in small WP-Cron batches. Only URLs on the current site host are accepted.
* Selective purge: when enabled, editing a post clears its permalink, the home page, and related archives instead of every cached page.
* Asset optimization: conservative HTML, CSS, and JavaScript minification. Local CSS files may be combined only when they are adjacent and share the same media attribute, preserving cascade order.
* Loading optimization: native image and iframe lazy loading, configurable first-image skipping, optional script deferral, and rule-based delayed JavaScript.
* CDN rewriting: optionally rewrite local static asset URLs while leaving page links and dynamic endpoints unchanged.
* Versioning: each settings change increments the asset version and refreshes cached pages.
* Safety: capability checks, nonces, input sanitization, output escaping, path whitelists, atomic cache writes, and an error log.

All advanced features are optional and disabled by default. Preloading performs server-side requests only to the same site host and is enabled only by the site administrator. The plugin does not track visitors, phone home, or download executable code.

== Installation ==

1. Upload the plugin folder to `wp-content/plugins/velocity-cache/`, or install it from the WordPress Plugins screen.
2. Activate Velocity Cache.
3. Go to Settings > Velocity Cache and enable the features you need.
4. Make sure `wp-content/` is writable. If it is not, cache writes are skipped and failures are logged when logging is enabled.

== Frequently Asked Questions ==

= Does the plugin cache logged-in users? =

By default, no. If enabled, cache keys are isolated by user ID and cached responses use `Cache-Control: private`. Remove the login cookie exclusion rule before using this option, and avoid it on pages with personalized content.

= Why are my stylesheets not combined? =

Stylesheets are combined only when they are adjacent in the HTML and share the same media attribute. This prevents changes to CSS cascade order. Stylesheets separated by inline styles, scripts, or other elements are intentionally left in place.

= What does Delay JS do? =

Delay JS only affects scripts matching the configured rules. Matching external scripts are loaded after the first user interaction or after five seconds. Leave the rules empty to disable delayed execution.

= Does preloading contact external sites? =

No. Preloading only requests URLs on the current site host. Custom URLs on other hosts are rejected, and sitemap discovery is limited to two common local sitemap paths.

= How do I clear all cached content? =

Use the cache tools on the plugin settings page. Page caches also expire according to the configured TTL and are purged when relevant content or settings change.

= Are form nonces affected by page caching? =

Cached HTML contains the nonce generated when the page was built. If a form fails because its nonce expired, purge the cache and reload the page.

== Changelog ==

= 1.2.1 =
* Prepared the plugin for submission to the WordPress.org Plugin Directory.
* Replaced direct file deletion and URL parsing with WordPress APIs where appropriate.
* Improved output escaping, input sanitization, and translator comments.
* Removed the unnecessary textdomain loader and added a languages directory.
* Added the GPLv2 license file and an English readme.

= 1.2.0 =
* Added sitemap and custom URL preloading with WP-Cron batches.
* Added selective purge, conditional requests, and Brotli/Gzip precompression.
* Added native image and iframe lazy loading, script deferral, rule-based delayed scripts, and CDN URL rewriting.
* Fixed malformed script URLs, CDN path mapping, and WordPress 304 status handling.

= 1.1.1 =
* Preserved JavaScript ASI newlines during minification.
* Fixed CSS descendant pseudo-class and HTML inline whitespace handling.
* Preserved CSS cascade order and stylesheet tag attributes.
* Isolated logged-in cache keys and improved cache directory safeguards.

== Upgrade Notice ==

= 1.2.1 =
Submission readiness update. No new features or configuration changes are required.
