=== Velocity Cache ===
Contributors: gg4mida0025
Tags: cache, page-cache, minify, performance, optimization
Requires at least: 5.8
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.5.2
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
* Loading optimization: native image and iframe lazy loading, configurable first-image skipping, optional script deferral, rule-based delayed JavaScript, and ready-made presets for common third-party scripts.
* CSS delivery: optional non-blocking CSS loading with a sensible default exclusion for the core theme stylesheet, noscript fallbacks, and a field for custom critical CSS.
* Conditional stylesheets: optionally remove a whole stylesheet on pages whose markup does not contain a given marker, with conservative default rules for WooCommerce and WPForms.
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

= Which scripts are covered by the third-party delay preset? =

The preset matches common reCAPTCHA, Google Tag Manager, Google Analytics, Google Ads, Facebook Pixel, and Cloudflare Beacon URLs. It is independent of the custom Delay JS rules and is disabled by default. Test forms and analytics after enabling it.

= How does asynchronous CSS loading work? =

Eligible stylesheets are changed to load without blocking rendering, with a noscript fallback. The default exclusion keeps the Astra core stylesheet blocking. Custom critical CSS can be injected into the document head. Disable the option if a theme depends on immediate stylesheet availability.

= How does conditional stylesheet unloading work? =

Each rule has the form `handle | marker1, marker2`. If none of the markers appear in the page markup, the matching stylesheet is removed from that page. Script and style contents are ignored when markers are checked, so inline CSS selectors do not count as markup. This removes whole files, not individual rules: enable it only after checking each page type, and disable it if a layout depends on a removed stylesheet.

= Does preloading contact external sites? =

No. Preloading only requests URLs on the current site host. Custom URLs on other hosts are rejected, and sitemap discovery uses the WordPress core sitemap API when available, with a fallback to the common SEO-plugin sitemap index.

= How do I clear all cached content? =

Use the cache tools on the plugin settings page. Page caches also expire according to the configured TTL and are purged when relevant content or settings change.

= Are form nonces affected by page caching? =

Cached HTML contains the nonce generated when the page was built. If a form fails because its nonce expired, purge the cache and reload the page.

== Changelog ==

= 1.5.2 =
* Made multilingual support generic: language discovery now covers WPML, Polylang and third-party plugins through a filter, with no fixed set or maximum number of languages.
* Cache keys, related purges and preloading now follow each language homepage for subdomain, directory and query-string language setups.
* Added a translatable POT file and Simplified Chinese translation; the settings screen now follows the WordPress admin locale instead of always showing one language.

= 1.5.1 =
* Fixed selective cache purge for WPML: translations sharing a trid now have their own language-domain URLs cleared when the source post changes.
* Page cache keys and the preloader now recognize all WPML language domains in multi-domain setups, and preloading covers every language homepage.
* Added a one-time notice on the plugins page when another plugin is activated or deactivated and the page cache is refreshed.

= 1.5.0 =
* Changed all internal prefixes to a unique 6-character prefix (veloca_) for functions, classes, constants, options, hooks, and stored data.
* Hardened cache storage: unguessable cache and log filenames, deny-all directory rules, and Nginx deny rules for private files.
* Closed the output buffer explicitly on shutdown instead of relying on PHP to flush it.
* Limited admin notices to the plugin settings page.
* Replaced the hardcoded core sitemap URL with get_sitemap_url() and fixed local asset path mapping for subdirectory installs.
* Updated the plugin description to English.
* Removed legacy predictably named log files on upgrade and hardened the cache root directory.
* Migrated settings and preload queue from legacy option names on upgrade so existing configurations are preserved.

= 1.4.0 =
* Added optional conditional stylesheet unloading based on page markup markers, with conservative default rules for WooCommerce and WPForms.
* Marker checks ignore script and style contents to avoid false matches from inline CSS selectors.
* The feature is disabled by default.

= 1.3.1 =
* Fixed rule matching across line breaks for multiline inline script loaders.
* Restricted inline delay to actual script loaders so configuration and event scripts are not delayed accidentally.

= 1.3.0 =
* Added a third-party delay preset for common analytics, advertising, and reCAPTCHA scripts.
* Added optional asynchronous CSS loading with noscript fallbacks and configurable exclusions.
* Added a custom critical CSS field injected into the document head.
* All new loading optimizations are disabled by default.

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

= 1.5.2 =
Recommended for all multilingual sites. Purge the cache once after upgrading so translated pages are rebuilt with the generic language handling.

= 1.5.1 =
Recommended for WPML sites using language subdomains. Purges the cache once after upgrading to rebuild translated page caches.

= 1.5.0 =
Maintenance and security hardening release. Cache files are stored under new unguessable names after the update; purge the cache once from the settings page.

= 1.4.0 =
Conditional stylesheet unloading is disabled by default. Review the default rules before enabling it, and test shop, product, and non-shop pages.
