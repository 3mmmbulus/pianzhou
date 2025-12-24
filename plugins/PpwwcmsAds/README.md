# PpwwcmsAds（广告管理插件）

> 合规优先：默认仅做“局部广告位 + sponsored 标记 + 广告标识”，侵入式模式需显式开启并且只对指定路径生效。

## 安装

1) 将目录放到：`plugins/PpwwcmsAds/`
2) 在站点配置 `config/config.yml` 增加 `PpwwcmsAds:` 配置块
3) 确认 `composer.json` 的 autoload 已包含 `plugins/`（本项目已包含）

## 架构与事件挂载点选择

本插件同时支持“内容内注入（shortcode/段落插入）”与“主题变量注入（header/footer/sidebar/slots）”，并在主题未接入时兜底把运行时脚本/侵入式 overlay 插入到 `</body>` 前。

- `onRequestUrl(&$url)`：识别并处理 `/ads/imp.gif`、`/ads/click`、`/ads/runtime.js`，将其标记为 endpoint 请求。
- `onRequestFile(&$file)`：把 endpoint 强制指向 `endpoint.md`，避免走 404/默认头部逻辑。
- `onContentParsed(&$content)`：替换 `[[ads:slot]]`、实现 `content_top/content_bottom/after_paragraph(n)` 注入（不依赖主题）。
- `onPageRendering(&$templateName, array &$twigVariables)`：
  - 注入 `pico_ads.*` 变量给主题渲染；
  - 侵入式模式下按路径生效并默认强制 `noindex,nofollow`（`meta.robots` + `X-Robots-Tag`）；
  - endpoint：直接切换到插件内 Twig 模板 `@ppww_ads/*` 输出（`imp.gif` 用 raw 模板输出二进制）。
- `onPageRendered(&$output)`：主题未输出 `pico_ads._runtime/_intrusive` 时，自动注入到 `</body>` 前。

## 配置 Schema（完整示例）

完整可用示例见：`plugins/PpwwcmsAds/config.example.yml`（把 `PpwwcmsAds:` 块合并到站点的 `config/config.yml`）。

## 主题接入（推荐）

本插件会注入 Twig 变量：

- `pico_ads.header`
- `pico_ads.footer`
- `pico_ads.sidebar`
- `pico_ads.slots.<slotName>`
- `pico_ads.<slotName>`（会把 `slot-name` 归一化成 `slot_name` 形式，便于 Twig 直接引用）
- `pico_ads._runtime`（需要时才有；若主题未输出，插件会在 `</body>` 前兜底注入；可用 `runtime.external.enabled: true` 改为外链 JS）
- `pico_ads._intrusive`（侵入式 overlay，若主题未输出，插件也会兜底注入）

在主题模板里插入（示例）：

```twig
{{ pico_ads._runtime|raw }}

<header>
  {{ pico_ads.header|raw }}
</header>

<aside>
  {{ pico_ads.sidebar|raw }}
</aside>

<footer>
  {{ pico_ads.footer|raw }}
</footer>
```

## 内容内短码（Shortcode）

在 Markdown 里插入：

```
[[ads:slot-name]]
```

插件会在 `onContentParsed()` 阶段把它替换成对应广告位。

## 合规（默认）

- 所有广告链接自动加 `rel="sponsored"`
- 可选叠加 `nofollow`
- 会包一层 `.ppww-ads`，并输出“广告”标识（可配置文案与 class）

## 侵入式模式（高风险，带护栏）

- 仅对 `intrusive.paths` 配置的路径生效（例如 `/landing/*`）
- 默认强制 noindex：设置 `meta.robots=noindex,nofollow` 并发送 `X-Robots-Tag`
- 严禁：UA/爬虫识别差异内容（cloaking）、301 强制跳转到广告页
- 允许：可关闭的全屏遮罩（带关闭按钮+倒计时），以及用户点击触发跳转
- 频次控制：默认按 `pathname` 进行 localStorage 频控（`intrusive.frequency.ttl_seconds`，默认 24h）

## 观测（本地 JSON 日志）

- 日志路径：`plugins/PpwwcmsAds/logs/YYYY-MM-DD.json`
- 统计项：按 `slot|creative|path` 聚合计数
- 点击：通过 `/ads/click` 中转实现（用户点击触发 302）

## 端点说明

- `/ads/imp.gif`：返回 1x1 透明 GIF（可用于埋点/调试；当前实现也会在服务端记录 impression 聚合）。
- `/ads/click`：对 `u` 做 base64 解码并校验为 `http/https` 后 302 跳转，并记录 click。

> 隐私：不记录个人敏感信息（不存 IP、UA、cookie 明文等）。

## 安全建议

- 默认 `security.html_mode=whitelist` 且禁止脚本。
- 若必须投放第三方脚本：建议启用 CSP，并结合 `security.csp_nonce.enabled`。

