# 纯 PHP 混淆（无需 Loader）

这套方式和你贴的“小旋风”文件属于同一类：**纯 PHP 混淆/压缩**，运行端不需要安装 ionCube/SourceGuardian Loader。

注意：这不是不可逆“加密”，强对抗下仍可能被还原；它的价值是提升阅读/直接改源码的门槛。

## 1) 混淆生成

在站点根目录（包含 `index/` 的目录）执行：

```bash
php index/tools/obfuscate.php --in index/lib/_lic/LicenseGuard.php --out index/lib/_lic/LicenseGuard.obf.php
php -l index/lib/_lic/LicenseGuard.obf.php
```

版本 1（同时混淆 `Wwppcms.php` + `LicenseGuard.php`）：

```bash
php index/tools/obfuscate.php \
	--in index/lib/Wwppcms.php --out index/lib/Wwppcms.php \
	--in index/lib/_lic/LicenseGuard.php --out index/lib/_lic/LicenseGuard.php \
	--force

php -l index/lib/Wwppcms.php
php -l index/lib/_lic/LicenseGuard.php
```

如果你希望直接覆盖原文件（上线时常见），用 `--force`：

```bash
php index/tools/obfuscate.php --in index/lib/_lic/LicenseGuard.php --out index/lib/_lic/LicenseGuard.php --force
php -l index/lib/_lic/LicenseGuard.php
```

建议上线前把原文件备份一份。

## 2) 建议加壳范围（最小集）

- 推荐混淆：`index/lib/_lic/LicenseGuard.php`
- 不建议混淆：`index/config/.license/*`（运行数据）
- 引导层（例如 `Wwppcms.php` 里两行 require+enforce）保持明文更稳。

## 2.1) 执行上下文限制（硬逻辑）

混淆产物会强制校验运行上下文：必须在 CMS 引导路径中被加载，否则直接 `403/exit`。

- Core 会在加载授权驱动前定义 `WWPPCMS_LICENSE_CTX=1`
- 如果你把混淆文件单独运行/直接 include（绕过 CMS），会被阻止执行

## 2.2) 混淆产物自完整性校验

混淆产物内部包含自校验（对当前文件内容做 sha256 校验）。

- 若文件被直接篡改，会定义 `WWPP_SELF_TAMPER=1`
- 授权逻辑会把该标记视为异常，触发既有的远端复核/受限流程

## 3) 怎么验证“篡改会复检”

1) 备份 `.license`：

```bash
cp -a index/config/.license index/config/.license.bak.$(date +%s)
```

2) 随便改 `index/config/.license/state.json` 里任意字段（会破坏签名/清单）：

- 访问 `/` 或任意页面
- 观察 `index/config/.license/history.jsonl` 会追加 `remote_revalidate` 事件
- 如果远端通过且属于缓存篡改场景，会看到 `.license` 被重建（历史变短/清单重写）

额外测试：篡改混淆产物本身

- 在混淆输出文件里随便改 1 个字符保存
- 访问任意页面
- 会触发 `WWPP_SELF_TAMPER=1` 并进入远端复核/受限流程

## 4) 已知限制

- 变量重命名对“变量变量/反射/动态 include 的代码”可能不安全；如果你将来在授权文件里使用这类技巧，需要调整脚本。
- 某些 WAF/杀软可能对高度混淆的 PHP 文件更敏感（误报风险）。

## 5) `obfuscate.php` 要不要随程序一起发布？

不需要。

- `index/tools/obfuscate.php` 是“构建工具”，运行期不依赖它
- 建议把 `index/tools/` 从最终交付包中移除（只交付混淆产物）
- 你也可以把该脚本放在另一个独立目录/独立仓库里，只要构建时传入正确的 `--in/--out` 路径即可
