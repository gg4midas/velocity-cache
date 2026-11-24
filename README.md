# Velocity Cache

轻量级 WordPress 性能优化插件。提供页面缓存、HTML 压缩、CSS 压缩与合并、JavaScript 压缩，以及文件版本控制、排除规则和缓存清理。

插件为独立实现，不依赖任何商业缓存插件的代码、品牌或界面。

- GitHub: https://github.com/gg4midas/velocity-cache
- Gitee: https://gitee.com/operations-go_0/velocity-cache

## 功能

| 模块 | 说明 |
| --- | --- |
| 页面缓存 | 仅为匿名访客的 GET 请求生成静态 HTML；支持 TTL、过期重建、内容更新自动失效与手动清理 |
| 缓存预加载 | 自定义站内 URL 或站点地图发现，WP-Cron 每批 5 个、最多 200 个，记录成功/失败 |
| 关联清除 | 可选只清文章页、首页及关联归档，避免每次编辑都全站清空 |
| 304 / 预压缩 | 可选 ETag、Last-Modified、Brotli/Gzip 变体，按客户端编码能力投递 |
| HTML 压缩 | 移除注释与冗余空白，保留 `pre`、`textarea`、`script`、`style` 内容 |
| CSS 压缩 | 压缩本地样式表与内联 `style`，生成带版本号的缓存文件 |
| CSS 合并 | 只合并相邻且 `media` 相同的样式表，保持级联顺序；`url()` 相对路径自动绝对化；含 `@import` 的样式表跳过 |
| JavaScript 压缩 | 压缩本地脚本与内联脚本；跳过站外资源及 `module`、`async`、`defer` 脚本 |
| LazyLoad | 原生图片/iframe `loading=lazy`，首屏图片默认跳过 2 张，支持 no-lazy/skip-lazy 标记 |
| JS 加载策略 | 可选外链脚本 Defer；按明确规则 Delay JS，首次交互或 5 秒后恢复 |
| CDN 重写 | 可选把本地 CSS/JS/图片/字体的 src/href 重写到 CDN，不改动态端点 |
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

- **缓存**：页面缓存开关、TTL（默认 3600 秒）、是否缓存已登录用户、是否缓存带查询参数的 URL、关联清除、304 和预压缩。
- **缓存预加载**：自动预加载开关、自定义 URL；工具区可手动建立队列，状态区显示队列与执行结果。
- **资源优化**：HTML 压缩、CSS 压缩、CSS 合并、JavaScript 压缩，四项独立开关。
- **加载优化**：图片/iframe LazyLoad、首屏跳过数、JS Defer、按规则 Delay JS、CDN 地址重写。
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
- 含 `@import` 的样式表不参与合并；跨越内联样式、脚本或其他标签的样式表也不强行合并，避免改变级联顺序
- 页面里的表单 nonce 会随缓存一起被冻结；TTL 默认 1 小时，提交失败时清理缓存即可
- Delay JS 仅适合明确可延迟的第三方脚本；Defer 开启后需检查依赖 jQuery 的内联脚本
- 当前不包含云端 Critical CSS、真实用户 CWV 分析或 WebP/AVIF 批量转换

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
  class-vc-preloader.php        预加载队列与后台批处理
  class-vc-optimizer.php        前端资源优化
  class-vc-tools.php            路径、文件、日志与压缩工具
admin/                          设置页视图与静态资源
```

## 更新记录

### 1.4.0

- 新增 CSS 条件卸载：按 `handle | 标记` 规则，在页面 DOM 不含标记时移除整个样式表。
- 判断标记时忽略 script/style 内容，避免内联 CSS 选择器被误认为 DOM 标记。
- 内置保守预设：WooCommerce 三张样式表与 WPForms 样式表；功能默认关闭。

### 1.3.1

- 修复规则匹配不跨换行的问题，真实多行 GTM/tracker 内联加载器可以正确延迟。
- 限制内联延迟只处理动态创建并加载外部脚本的加载器，避免 WPForms 配置和事件脚本被误延迟。

### 1.3.0

- 根据 PageSpeed 桌面报告新增常见第三方脚本延迟预设：reCAPTCHA、GTag/GTM、Google Ads、Facebook Pixel、Cloudflare Beacon。
- 新增可选 CSS 异步加载，默认保留 Astra 主样式阻塞加载，并提供 noscript 回退。
- 支持填写首屏关键 CSS，注入到 head。
- 所有新功能默认关闭，避免影响表单、统计和主题布局。

### 1.2.1

- 准备 WordPress.org 官方提交版本。
- 按官方检查修复输出转义、输入净化、翻译注释和文件 API 使用。
- 新增 GPLv2 许可证文件、英文 readme 和 languages 目录。
- 移除 WordPress.org 托管环境不需要的 textdomain 手动加载。

### 1.2.0

- 新增站点地图/自定义 URL 预加载队列与后台批处理。
- 新增关联清除、304、Brotli/Gzip 预压缩。
- 新增图片/iframe LazyLoad、JS Defer、按规则 Delay JS、CDN 静态地址重写。
- 修正 JS 压缩后 script 标签重复 `src`、CDN 目录映射及 WordPress 304 状态头。
- 新功能均为独立开关，默认关闭，保持既有配置行为。

### 1.1.1

- 保留 JavaScript 自动分号插入（ASI）所需换行，避免压缩改变语义。
- 修正 CSS 后代伪类选择器和 HTML 行内元素空白被误改的问题。
- CSS 只合并相邻且 `media` 相同的样式表，在原位置替换并保留标签属性。
- 非 HTML 响应不优化；登录用户缓存按用户 ID 隔离，命中时使用 `private` 缓存头。
- 修正深层缓存计数、Windows 路径兼容、权限拒绝响应码与目录防护恢复。
- 全部功能关闭时不再启用输出缓冲。

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
