# 主题切换说明（fzxingluo.com / Wwppcms）

## 1) 当前站点主题配置位置

编辑：`config/config.yml`

关键字段：

```yaml
theme: default
```

把 `default` 改成主题目录名即可，例如：`a`、`f`、`o`。

本项目已存在主题目录：`themes/a` 到 `themes/o`（以及 `themes/default`）。

## 2) 示例：切换到主题 f

```yaml
theme: f
```

## 3) 主题切换后的注意事项

- 如果启用了 Twig 缓存（`twig_config.cache` 指向可写目录），切换主题后建议清空缓存目录。
- 主题层不输出 OG/Twitter/JSON-LD（这些由插件统一生成），属于预期行为。
