# Velocity Cache

轻量级 WordPress 性能优化插件。提供页面缓存、HTML 压缩、CSS 压缩与合并、JavaScript 压缩，以及文件版本控制、排除规则和缓存清理。

插件为独立实现，不依赖任何商业缓存插件的代码、品牌或界面。

- GitHub: https://github.com/gg4midas/velocity-cache
- Gitee: https://gitee.com/operations-go_0/velocity-cache

## 功能

| 模块 | 说明 |
| --- | --- |
| 页面缓存 | 仅为匿名访客的 GET 请求生成静态 HTML；支持 TTL、过期重建、内容更新自动失效与手动清理 |
| HTML 压缩 | 移除注释与冗余空白，保留 `pre`、`textarea`、`script`、`style` 内容 |
| CSS 压缩 | 压缩本地样式表与内联 `style`，生成带版本号的缓存文件 |
| CSS 合并 | 按 `media` 分组合并；`url()` 相对路径自动绝对化；含 `@import` 的样式表跳过 |
| JavaScript 压缩 | 压缩本地脚本与内联脚本；跳过站外资源及 `module`、`async`、`defer` 脚本 |
| 排除规则 | 按 URL、Cookie 名、CSS/JS handle 或 URL 关键词排除，支持 `*` 与 `?` 通配符 |
| 版本控制 | 配置变更自动递增版本号，资源 URL 追加 `?ver=vcN`；可手动递增强制客户端刷新 |
| 错误处理 | 异常捕获后回退原始 HTML；写盘失败记录到缓存目录日志 |

## 工作原理

页面缓存在 `wp` 动作阶段检查请求：命中缓存时直接输出已生成的静态 HTML 并结束请求，跳过主题模板渲染。首次访问或缓存过期后仍会正常执行主题并生成缓存文件。插件不使用 `advanced-cache.php` drop-in，WordPress 核心与插件照常加载。

资源优化在输出缓冲阶段处理最终 HTML。只有本地文件会被改写，站外资源一律跳过。若压缩后体积没有明显下降，插件保留原始标签，避免负收益。任何优化异常都会被捕获，页面回退到未经改写的内容。

## 安装

1. 将本目录放到 `wp-content/plugins/velocity-cache/`。
2. 在 WordPress 后台启用插件。
3. 进入「设置 → Velocity Cache」开启所需功能并保存。
4. 确认 `wp-content/` 可写。缓存写入 `wp-content/cache/velocity-cache/`，卸载插件时自动清理。

要求：WordPress 5.8+、PHP 7.4+。

## 后台设置

- **缓存**：页面缓存开关、TTL（默认 3600 秒）、是否缓存已登录用户、是否缓存带查询参数的 URL。
- **资源优化**：HTML 压缩、CSS 压缩、CSS 合并、JavaScript 压缩，四项独立开关。
- **排除规则**：排除 URL、排除 Cookie 名、排除 CSS、排除 JavaScript。
- **错误处理**：错误日志开关，日志写入 `wp-content/cache/velocity-cache/logs/errors.log`。
- **缓存工具**：清理页面缓存、清理全部缓存、递增资源版本号。
- **运行状态**：缓存目录、目录可写状态、缓存文件数量、当前版本号、上次清理时间。

### 排除规则语法

每行一条规则，支持 `*` 匹配任意字符、`?` 匹配单个字符。

```text
URL 排除示例：
/cart/*
/sitemap.xml

Cookie 排除示例：
wordpress_logged_in_*
wp_woocommerce_session_*

CSS/JS 排除示例：
astra-theme-css
jquery.blockUI
```

URL 规则按请求路径匹配；Cookie 规则按 Cookie 名匹配；CSS/JS 规则按 handle 名或资源 URL 关键词匹配。

## 缓存目录

```text
wp-content/cache/velocity-cache/
├── pages/     页面缓存 HTML
├── assets/    优化后的 CSS / JS
└── logs/      错误日志
```

各目录写入 `index.php` 和 `.htaccess`，禁止目录列表和 PHP 执行，但允许静态资源直接访问。

## 安全设计

- `ABSPATH` 守卫、后台 `manage_options` 权限检查、nonce 校验
- 设置项经 Settings API 过滤，规则逐行清洗与长度限制
- 缓存路径白名单、临时文件原子写入、隔离的缓存根目录
- 站外资源不做远程抓取，避免 SSRF
- 登录用户、后台、REST、AJAX、feed、404、预览页默认不缓存

## 兼容性

- 不要与其他全页面缓存插件同时开启页面缓存，否则可能出现重复缓冲
- 上游已压缩的 CSS/JS 二次压缩收益有限，插件会跳过无收益的改写
- 含 `@import` 的样式表不参与合并
- 页面里的表单 nonce 会随缓存一起被冻结；TTL 默认 1 小时，提交失败时清理缓存即可

## 项目结构

```text
velocity-cache.php              核心加载文件
uninstall.php                   卸载清理
readme.txt                      WordPress 插件说明
includes/
  class-vc-plugin.php           启动器
  class-vc-settings.php         设置与版本控制
  class-vc-admin.php            后台配置页面
  class-vc-cache.php            页面缓存
  class-vc-optimizer.php        前端资源优化
  class-vc-tools.php            路径、文件、日志与压缩工具
admin/                          设置页视图与静态资源
```

## 更新记录

### 1.1.0

- 插件列表页增加「设置」快捷入口。
- 设置注册提前到 `init`，程序化更新也会触发过滤与缓存刷新。
- 修正登录 Cookie 排除规则缺少通配符的问题。
- CSS 改写无收益时保留原标签，避免体积变大。
- 完善文档与仓库描述、标签。

### 1.0.0

- 首个版本：页面缓存、HTML/CSS/JS 优化、排除规则、版本控制与后台设置页。

## 许可证

GPLv2 or later
