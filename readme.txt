=== Velocity Cache ===
Contributors: gg4midas
Tags: cache, performance, minify, optimization, pagespeed
Requires at least: 5.8
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

轻量级 WordPress 性能优化插件：页面缓存、HTML 压缩、CSS 压缩与合并、JavaScript 压缩。

== Description ==

Velocity Cache 是一套独立的 WordPress 性能优化实现，不依赖、不复制任何商业缓存插件的代码、品牌或界面。

功能：

* 页面缓存：仅对匿名访客的 GET 请求生成静态 HTML，支持 TTL 与手动清理。
* HTML 压缩：移除注释与冗余空白，保留 `<pre>`、`<textarea>`、`<script>`、`<style>` 内容。
* CSS 优化：可单独开启压缩、合并；合并时自动把相对 URL 绝对化，避免路径漂移。
* JavaScript 优化：本地脚本与内联脚本压缩，跳过 `async` / `defer` / `module` 及外部域名脚本。
* 文件版本控制：设置变更或手动操作会递增资源版本号，并写入资源 URL 与缓存文件名。
* 排除规则：按 URL（支持 `*` 通配符）、Cookie 名、CSS/JS 关键词排除。
* 安全与健壮性：能力检查、nonce、输入过滤、路径白名单、异常捕获与错误日志。

缓存文件写入 `wp-content/cache/velocity-cache/`，卸载插件时自动清理。

== Installation ==

1. 将插件目录上传至 `wp-content/plugins/velocity-cache/`。
2. 在「插件」页面启用 Velocity Cache。
3. 进入「设置 → Velocity Cache」按需开启功能并保存。
4. 确认 `wp-content/` 可写；若不可写，设置页会给出提示，页面缓存与资源优化会自动降级为不写入。

== Frequently Asked Questions ==

= 会和已有缓存插件冲突吗？ =

插件不使用 `advanced-cache.php` drop-in，也不劫持其他插件的缓存目录。如果两个插件都开启页面缓存，建议只保留一个，否则可能出现重复缓冲。

= 为什么登录用户没有被缓存？ =

默认只缓存匿名访客。如确需缓存登录用户，请开启「缓存已登录用户」，但注意这可能导致不同用户看到相同页面。

= 合并 CSS 后图片路径失效怎么办？ =

插件在合并时会把 `url()` 中的相对路径改写为基于原始 CSS 文件 URL 的绝对路径。若样式表使用了 `@import`，该文件会跳过合并以保持原有语义。

= 如何完全清理缓存？ =

在设置页点击「清理缓存」；内容更新、主题切换、菜单更新时页面缓存也会自动失效。

== Changelog ==

= 1.0.0 =
* 初始版本：页面缓存、HTML/CSS/JS 优化、排除规则、版本控制与管理页面。
