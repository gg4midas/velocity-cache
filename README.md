# Velocity Cache

轻量级 WordPress 性能优化插件：页面缓存、HTML 压缩、CSS 压缩与合并、JavaScript 压缩，带文件版本控制、排除规则、缓存清理和错误处理。

插件完全独立实现，不依赖、不复制任何商业缓存插件的代码、品牌或界面。

- GitHub: https://github.com/gg4midas/velocity-cache
- Gitee: https://gitee.com/operations-go_0/velocity-cache

## 功能

| 模块 | 说明 |
| --- | --- |
| 页面缓存 | 仅缓存匿名访客的 GET 请求，支持 TTL、过期重建、内容更新自动失效和手动清理 |
| HTML 压缩 | 移除注释与冗余空白，保留 `pre`、`textarea`、`script`、`style` 内容 |
| CSS 压缩 | 压缩本地样式表与内联 `style`，生成版本化资源文件 |
| CSS 合并 | 按 `media` 分组合并，`url()` 相对路径自动绝对化，含 `@import` 的样式表跳过 |
| JavaScript 压缩 | 压缩本地脚本与内联脚本，跳过站外资源、`module`、`async`、`defer` 脚本 |
| 排除规则 | 按 URL（支持 `*` 与 `?` 通配符）、Cookie 名、CSS/JS handle 或 URL 关键词排除 |
| 版本控制 | 配置变更自动递增版本号，资源 URL 带 `?ver=vcN`，可手动递增强制刷新 |
| 错误处理 | 异常捕获后回退原始 HTML，写盘失败记录到缓存目录日志 |

## 安装

1. 将本目录放到 `wp-content/plugins/velocity-cache/`。
2. 在 WordPress 后台启用插件。
3. 进入「设置 → Velocity Cache」开启所需功能。
4. 确认 `wp-content/` 可写；缓存写入 `wp-content/cache/velocity-cache/`，卸载时自动清理。

## 安全设计

- `ABSPATH` 守卫、后台 `manage_options` 权限检查、nonce 校验
- 设置项经 Settings API 过滤，URL/Cookie/Handle 规则逐行清洗
- 缓存路径白名单 + 原子写入，目录写入 `index.php` 与 `.htaccess`
- 站外资源一律跳过，不做远程抓取
- 未授权请求、路径穿越、异常写盘均有防护或降级

## 兼容说明

- 要求 WordPress 5.8+、PHP 7.4+
- 不使用 `advanced-cache.php` drop-in，与其他全页面缓存插件同时启用页面缓存可能冲突
- CSS/JS 上游已压缩时，二次压缩收益有限；插件对无收益的改写做了阈值保护

## 作者

gg4midas（与 `site_analytics` 网站流量统计项目保持一致）

## 许可证

GPLv2 or later
