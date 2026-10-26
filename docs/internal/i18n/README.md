# 文档站多语言维护规则

`docs/` 现在有四种语言，中文是唯一真源，其余三种各有自己的治理方式。本文只讲「怎么加、怎么改、什么时候必须重跑脚本」，文档分层与目录归属仍看 [docs/README.md](../../README.md) 与 [DOCUMENTATION_POLICY.md](../governance/DOCUMENTATION_POLICY.md)。

## 1. 语言集合与 URL

| 语言             | locale 目录       | HTML lang | URL 前缀  | 维护方式                              |
| ---------------- | ----------------- | --------- | --------- | ------------------------------------- |
| 简体中文         | 根路径（`docs/`） | `zh-CN`   | `/`       | **真源**，人工维护                    |
| English          | `docs/en/`        | `en`      | `/en/`    | 人工/AI 翻译，front matter 记录源哈希 |
| 繁體中文（台灣） | `docs/zh-tw/`     | `zh-TW`   | `/zh-tw/` | 脚本生成，**禁止手改**                |
| 繁體中文（香港） | `docs/zh-hk/`     | `zh-HK`   | `/zh-hk/` | 脚本生成，**禁止手改**                |

locale 代码沿用三端前端与官网的既有约定（`zh_CN` / `zh_TW` / `zh_HK` / `en_US`），URL 前缀用短写法避免下划线。繁体必须是两版：差异在词汇（資料/數據、網路/網絡、使用者/用戶），一份繁体覆盖两地会有一半用词是错的。

## 2. 目录镜像

除 `internal/` 与两份脚本生成物外，中文页面在三种语言下各有一份同路径镜像：

```text
docs/guide/deploy/bt-panel.md      → /guide/deploy/bt-panel
docs/en/guide/deploy/bt-panel.md   → /en/guide/deploy/bt-panel
docs/zh-tw/guide/deploy/bt-panel.md → /zh-tw/guide/deploy/bt-panel
```

镜像不是形式主义：VitePress 的语言菜单靠路径前缀配对同页，站内相对链接也靠它解析到对应语言。少了任何一页，`docs:check` 会在两个地方同时报错（侧栏覆盖 + 坏链）。

**不进镜像的页面**：`docs/internal/**`（不上站，译文里改成 GitHub 链接）、`develop/database.md`、`develop/api/backend-api-catalog.md`（脚本生成，译文侧栏直接指向中文页）。

## 3. front matter 契约

译文页面必须在文件开头写清来源：

```yaml
---
source: guide/deploy/bt-panel.md # 中文源页面（相对 docs/）
sourceHash: 1f3c… # 翻译时中文源文件的 sha256
translatedAt: 2026-10-26 # 英文页写成日期
allowCjk: true # 可选：正文确实需要中文（如 i18n 规范里的简繁对照表）
---
```

- 中文源一改，`sourceHash` 就对不上。繁体两版会**直接构建失败**并提示重跑 `trad`；英文版只报非阻断 finding（允许翻译滞后），`pnpm run docs:i18n:check` 下才阻断。
- `source` 必须与镜像路径一致，等于页面身份校验。
- 不要手写 `sourceHash`，用 `stamp` 写入。

## 4. 命令

在仓库根执行：

```cmd
pnpm run docs:i18n                                    :: 覆盖率与过期状态
node backend/scripts/i18n/docs-i18n.mjs trad zh-tw    :: 由中文重生成繁体（台湾）
node backend/scripts/i18n/docs-i18n.mjs trad zh-hk    :: 同上（香港）
node backend/scripts/i18n/docs-i18n.mjs links en      :: 改写跨语言链接
node backend/scripts/i18n/docs-i18n.mjs sync-code en  :: 用中文源覆盖译文代码块
node backend/scripts/i18n/docs-i18n.mjs stamp en      :: 翻译完成后写入 sourceHash
node backend/scripts/i18n/docs-i18n.mjs verify en     :: 结构比对（代码块/标题/链接/残留中文）
pnpm run docs:check                                   :: 构建前的全量门禁
```

`pnpm run docs:i18n:trad` 一次生成两种繁体（含首页壳文案 JSON）。

## 5. 翻译硬规则

1. 完整翻译，不许省略、概括、写「（略）」。表格、列表、提示块都要翻。
2. 代码块逐字复制，一个字符都不改（含注释与语言标记）；`sync-code` 会强制回填，但别依赖它。
3. 行内代码、命令、路径、配置键、环境变量、字段名、占位符 `{{var}}` 一律不翻。
4. 链接目标保持原样，只翻链接文字。跨语言链接由 `links` 命令统一改写，不要手写。
5. 标题层级、表格分隔行、VitePress 容器标记（`::: tip`）、HTML 标签（`<DeploymentSelector />`、`<code v-pre>`）保持原样。
6. 一级标题必须翻成目标语言；英文页标题里不能有汉字（构建期硬门禁）。
7. 正文不残留中文（代码块与行内代码除外）；确需中文的页面加 `allowCjk: true` 并在评审里说明原因。
8. 术语统一查 [术语表](glossary.md)，不要一页一个译法。

## 6. 首页与站点壳文案

首页门牌（`HomePortal.vue`）与部署方式选择器（`DeploymentSelector.vue`）的文案不在 Markdown 里，而在：

```text
docs-web/.vitepress/theme/data/siteChrome.zh_CN.json   # 真源
docs-web/.vitepress/theme/data/siteChrome.en_US.json   # 人工翻译
docs-web/.vitepress/theme/data/siteChrome.zh_TW.json   # trad 生成
docs-web/.vitepress/theme/data/siteChrome.zh_HK.json   # trad 生成
```

改简体首页文案后必须重跑 `trad`，否则繁体首页还是旧文案。英文首页改 `siteChrome.en_US.json`，站内链接要带 `/en` 前缀。

## 7. 常见失败与处理

| 现象                              | 原因                    | 处理                                |
| --------------------------------- | ----------------------- | ----------------------------------- |
| `译文已过期（中文源 … 有改动）`   | 改了中文页没重跑 `trad` | 跑 `pnpm run docs:i18n:trad`        |
| `第 N 个代码块内容与中文源不一致` | 翻译时动了代码          | 跑 `sync-code`，再 `verify`         |
| `链接目标与中文源不一致（缺 …）`  | 手改了链接或漏了改写    | 跑 `links`，再 `verify`             |
| `正文残留中文 N 字`               | 漏译或加了中文括注      | 补译；确需中文则加 `allowCjk: true` |
| `英文页面标题未翻译`              | H1 还是中文             | 改 H1                               |
| 构建报「文档缺少一级标题」        | 镜像页缺 H1             | 补 H1                               |
| 构建报「以下文档未登记到侧栏」    | 新增页没进侧栏          | 在 `config.ts` 的侧栏蓝图里登记     |

## 8. 节奏

- 中文页改动不要求同一个 PR 补英文；英文滞后由 `pnpm run docs:i18n` 的过期列表跟踪，按发版节奏清理。
- 繁体是机器生成，任何中文改动都必须当次重跑，不能欠账。
- 新增中文页时，三种语言的镜像页要么同一 PR 补齐，要么明确登记为待翻译（`docs:i18n` 会列出缺失）。
