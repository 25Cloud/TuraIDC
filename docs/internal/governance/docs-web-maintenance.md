# TuraIDC VitePress 文档官网维护指南

本文是 `docs-web/` 文档官网的统一维护入口，说明内容分区、日常维护、构建与发布方式。文档官网是独立的 VitePress 应用，直接读取 `docs/` 作为内容源，不复制文档，也不参与三个业务前端的构建与部署。

## 1. 目录职责

| 路径                                                  | 职责                                                                       |
| ----------------------------------------------------- | -------------------------------------------------------------------------- |
| `docs/guide/`                                         | 使用者文档（站长/运营）：部署、初始配置、日常运营、维护、迁移、排障。      |
| `docs/develop/`                                       | 开发者文档：架构、工程规范、数据库、接口、插件与本地开发。                 |
| `docs/internal/`                                      | 维护者内部记录：执行计划、设计、产品规格、修复记录、治理规则。**不上站**。 |
| `docs/en/`                                            | 英译镜像子树，URL 前缀 `/en/`；人工/AI 翻译，允许滞后。                    |
| `docs/zh-tw/` · `docs/zh-hk/`                         | 两种繁体镜像子树，URL 前缀 `/zh-tw/` `/zh-hk/`；**脚本生成，禁止手改**。   |
| `docs/internal/i18n/`                                 | 文档站翻译规则与术语表（维护者用，不上站）。                               |
| `docs-web/`                                           | VitePress 应用、主题、首页组件与静态品牌资源。                             |
| `docs-web/.vitepress/config.ts`                       | 站点元信息、四语言 locales、侧栏蓝图、覆盖校验与 hreflang。                |
| `docs-web/.vitepress/theme/Layout.vue`                | 各语言首页与默认文档布局的切换入口（挂载 Nolebase 增强阅读面板）。         |
| `docs-web/.vitepress/theme/components/HomePortal.vue` | 文档官网首页：使用者与开发者双入口分区，文案按语言取自 `theme/data/`。     |
| `docs-web/.vitepress/theme/data/siteChrome.*.json`    | 首页与部署选择器的四语言文案；简体是源，繁体由 `docs:i18n:trad` 生成。     |
| `backend/scripts/i18n/docs-i18n.mjs`                  | 译文维护脚本：status / trad / links / sync-code / stamp / verify。         |
| `docs-web/public/branding/`                           | Logo 与 favicon 等原样复制的静态资源。                                     |

不要在 `docs-web/` 中复制 Markdown 正文。正文始终直接维护在 `docs/`，VitePress 通过 `srcDir: "../docs"` 读取；`srcExclude: ["internal/**"]` 把内部记录挡在站点产物之外。

## 2. 本地启动

在项目根目录安装工作区依赖（`docs-web` 是 pnpm workspace 成员）：

```cmd
pnpm install
```

启动开发服务：

```cmd
cd docs-web
npx vitepress dev --host 127.0.0.1 --port 5176
```

浏览器访问 `http://127.0.0.1:5176/`。开发服务支持内容和主题热更新。

不要在根目录用 `pnpm run dev:docs -- --host ...` 传参：pnpm 会把 `--` 之后的参数按字面量转发，vitepress 收到错误的参数会忽略配置，并在 `docs-web/` 下留下名为 `--host/` 的缓存目录。

## 3. 内容维护规则

### 中文内容与英文 URL

- 简体中文（根路径）的文档正文、文章一级标题、导航文字和侧栏文字保持中文。
- `docs/` 下的目录名和文件名必须使用 ASCII 英文；Markdown 文件名使用 `kebab-case`。一次性修复记录用 `YYYY-MM-DD-英文词组.md`。
- Markdown 内部链接必须指向英文路径，不得重新引用迁移前的中文路径。
- 每篇文章必须包含一级标题；简体与繁体页面必须含汉字，英文页面不得含汉字（构建期硬门禁）。
- 页面标题与侧栏文章名称从文章一级标题读取，不从英文文件名生成。
- 不为旧 URL 增加重定向页或运行时兼容层。

### 四语言镜像与翻译

语言集合、目录镜像、front matter 契约与翻译硬规则见 [文档站多语言维护规则](../i18n/README.md)，术语见 [术语表](../i18n/glossary.md)。这里只保留最常用的一条链路：

```cmd
:: 改完中文页后重生成两种繁体（不重跑构建会失败）
pnpm run docs:i18n:trad

:: 看哪些英文页还没翻 / 已过期
pnpm run docs:i18n

:: 翻译完成后写入源哈希，再自检
node backend/scripts/i18n/docs-i18n.mjs stamp en
node backend/scripts/i18n/docs-i18n.mjs verify en
```

英文译文允许滞后于中文源，`docs:check` 只报非阻断 finding；`pnpm run docs:i18n:check` 才把滞后当失败。繁体是机器生成物，中文一改就必须重跑，否则 `docs:check` 直接失败。

`internal/` 与两份生成物（`develop/database.md`、`develop/api/backend-api-catalog.md`）没有译文镜像：繁体/英文页面里的对应链接会被脚本改写成 GitHub 链接或中文页的站点绝对路径，不要手工维护这些链接。

新增页面示例：

```text
docs/guide/deploy/reverse-proxy.md
```

```markdown
# 反向代理配置指南

正文内容……
```

对应页面 URL 为 `/guide/deploy/reverse-proxy`，页面显示标题仍为“反向代理配置指南”。

### 分区归属

- 读者是站长 → `docs/guide/`；读者是开发者 → `docs/develop/`；只有维护者关心 → `docs/internal/`。
- `guide/` 与 `develop/` 的正文不要链接 `internal/` 的文档，站点上会变成 404；需要引用治理规则时给 GitHub URL。
- 新增目录前先对照 `docs/internal/governance/DOCUMENTATION_POLICY.md` 的放置规则，避免为同一主题开第二个分区。

### 索引页规则

目录索引使用 `README.md` 或 `index.md`。`config.ts` 会把 `README.md` 重写为 `index.md`，因此：

- `docs/guide/index.md` 对应 `/guide/`；
- `docs/internal/designs/index.md` 对应 `/internal/designs/`（仅仓库内可见）；
- 普通 Markdown 文件去掉 `.md` 后形成页面 URL。

索引页不需要登记 `docs/catalog.json`；非索引文档必须登记，否则 `docs:check` 直接失败。

### 侧栏与阅读顺序

侧栏顺序在 `config.ts` 的 `guideBlueprint` 与 `developBlueprint` 中**显式维护**，不按文件名排序——文档的阅读顺序是产品决策。四种语言共用同一份页面清单与顺序，只把节标题按语言替换；新增页面时改一处，四种语言的侧栏同步生效，否则页面只能靠直链访问。

侧栏与导航的显示文字来自各语言页面的正文一级标题；缺标题、或用简体标题冒充译文，构建都会直接失败，这是有意的硬门禁。

### 自动生成文档

两份生成物在所属分区内，只能通过脚本刷新，不能手工修改：

```cmd
php backend/scripts/export_database_structure.php   # 刷新 docs/develop/database.md
php backend/scripts/export_api_inventory.php        # 刷新 docs/develop/api/backend-api-catalog.md
```

刷新后执行文档检查和官网构建。

## 4. 导航与首页维护

主导航与两套侧栏结构在 `config.ts` 中维护。当前一级架构为：

1. 我是使用者（`/guide/`）：部署安装、初始配置、日常运营、维护与升级、数据迁移、故障排查
2. 我是开发者（`/develop/`）：系统概览、工程规范、数据库、API、插件与集成、开发与测试、参与贡献

新增导航入口时使用英文链接，显示文字保持中文。不要通过硬编码英文文件名生成读者可见标题。

首页内容在 `HomePortal.vue` 中维护，分「我是使用者」与「我是开发者」两个分区，每区四张卡片。修改首页时同步检查：

- 桌面端与移动端首屏没有内容重叠或文字溢出；
- Hero 仍以 TuraIDC 为核心，品牌图片从 `/branding/` 读取；
- 首页、导航和快捷入口使用英文 URL，且指向真实存在的页面；
- 主题色流光保持静态、柔和，并在四周平滑淡出；
- 无障碍名称、键盘焦点和外部链接属性保持完整。

部署安装页的部署方式入口由 `DeploymentSelector.vue` 提供。新增部署方式时，应先在 `docs/guide/deploy/` 建立文档、加入侧栏，再增加入口。

## 5. 构建与验证

文档内容变更至少执行：

```cmd
pnpm run docs:check
```

改过中文正文的，还要重生成繁体并复验：

```cmd
pnpm run docs:i18n:trad
pnpm run docs:check
```

官网配置、主题、组件、静态资源或路径变更还必须执行：

```cmd
pnpm run build:docs
```

生产构建输出位于：

```text
docs-web/.vitepress/dist/
```

需要本地检查生产产物时执行：

```cmd
cd docs-web
npx vitepress preview --host 127.0.0.1 --port 4173
```

提交前至少人工抽查首页、`/guide/`、`/develop/`、API 清单和一篇深层目录文章（四种语言各抽一次），并确认：

- 页面 URL 全部为英文，语言前缀为 `/en/`、`/zh-tw/`、`/zh-hk/`；
- 页面标题与侧栏跟随所选语言：简体/繁体显示汉字标题，英文页不出现汉字标题；
- 抬头语言菜单能落到同一页面的其他语言版本，首页页脚也有一组语言入口；
- `<html lang>` 分别为 `zh-CN` / `en` / `zh-TW` / `zh-HK`，`<head>` 里的 `hreflang` 成对指向四种语言；
- 站内链接没有 `404`，且 `/internal/` 下的页面确实不存在（英文站点同样不应出现）；
- 站内搜索在每种语言下只返回该语言的结果；
- Logo、favicon 和首页入口正常加载；
- 移动端没有横向滚动或元素遮挡。

## 6. 独立构建与部署

文档官网与业务前端独立存在：

- `pnpm run build:frontends` 只构建 `frontend-admin-v3/`、`frontend-user-v3-www/` 和 `frontend-user-v4-console/`；
- `pnpm run build:docs` 只构建 `docs-web/`；
- 文档产物不会写入三个业务前端的 `dist/`，也不会写入 `backend/public/`；
- 发布系统把 `docs-web/.vitepress/dist/` 作为独立静态站点产物部署。

EdgeOne Pages 的构建配置在仓库根的 `edgeone.json`，每次提交到默认分支自动部署到 `docs.turaidc.com`：

```json
{
  "installCommand": "cd docs-web && npm ci",
  "buildCommand": "node backend/scripts/check_docs.mjs && cd docs-web && npm run build",
  "outputDirectory": "docs-web/.vitepress/dist",
  "nodeVersion": "22.11.0"
}
```

两点必须保持：

- **安装用 `npm ci`，不用 `npm install`**。`docs-web/package-lock.json` 必须入库；无锁安装曾导致线上产物缺失 chunk 引用（`framework.*.js` 404 → 页面水合报 `shallowRef` 错误）。修改 `docs-web` 依赖后需在 `docs-web/` 下重新 `npm install` 刷新该 lock 并一并提交。
- **构建前先跑 `check_docs.mjs`**：坏链、漏登记 `catalog.json`、缺少必需分区都会让构建失败，从而拦住这次自动部署。文档校验不是可选项。

当前站点链接和品牌资源按域名根路径配置。若部署到 `/docs/` 等子路径，必须先在 VitePress 配置中设置 `base`，并同步验证首页组件中的站内链接和静态资源路径。

静态服务器应支持无扩展名 URL。使用 Nginx 时，需要让 `/guide/deploy/bt-panel` 这类请求命中对应 HTML 页面，或按静态托管平台的 clean URL 规则处理。

## 7. 常见问题

### 构建提示文档缺少中文一级标题 / 英文页面标题未翻译

简体与繁体页面必须有以 `# ` 开头的汉字一级标题，英文页面的一级标题不能含汉字。文件名为英文是正常的，不要把文章标题改成英文文件名来匹配。四种语言的 H1 都由 `pageTitle()` 在配置加载阶段读取，缺哪个语言就补哪个语言的镜像页。

### `docs:check` 提示译文已过期

改了中文源但没重生成繁体。执行 `pnpm run docs:i18n:trad` 后重新检查；英文页过期只报非阻断 finding，按节奏补译即可。

### `docs:check` 提示代码块/链接/标题结构与中文源不一致

英文译文动了不该动的东西。按顺序跑：

```cmd
node backend/scripts/i18n/docs-i18n.mjs sync-code en
node backend/scripts/i18n/docs-i18n.mjs links en
node backend/scripts/i18n/docs-i18n.mjs verify en
```

标题层级不一致只能手工对着中文源改回来。

### 构建提示找不到页面文件

`config.ts` 的 `pageItem()` 会在配置加载阶段读取每个侧栏页面的正文标题；文件被移动或改名而侧栏未同步时，构建会在这里报错。按提示补齐路径或从侧栏移除。

### `docs:check` 提示缺少目录覆盖记录

`backend/scripts/check_docs.mjs` 要求 `docs/` 根路径下每个非索引 Markdown 都在 `catalog.json` 里有记录，且记录按 `path` 升序排列。补记录时按路径插入正确位置，不要追加到末尾。译文目录（`en/`、`zh-tw/`、`zh-hk/`）由 front matter 契约治理，不进 `catalog.json`。

### 新页面返回 404

依次检查文件是否位于 `docs/`（不是 `docs-web/`）、是否已加入侧栏蓝图、链接是否去掉 `.md`、路径大小写是否一致，以及静态服务器是否支持 clean URL。目录首页还需检查 `README.md` 或 `index.md` 的重写规则。翻译页面还要确认镜像路径一致。

### 站内搜索搜不到东西

本地搜索索引只在顶层 `themeConfig.search` 存在时才生成。搜索配置必须留在 `defineConfig` 顶层的 `themeConfig` 里，各语言只能覆盖 `locales[*].themeConfig.search` 的文案；把它只写进语言级配置会出现「构建成功但零结果」。

### 文档检查通过但页面入口不可见

`docs:check` 只负责文档结构与链接校验，不会自动决定导航信息架构。需要在 `config.ts` 的 `guideBlueprint`／`developBlueprint`、`theme/data/siteChrome.*.json` 或对应页面的组件里增加入口。

### 修改没有出现在生产站点

确认发布流程执行的是 `npm run build:docs`，并部署了最新的 `docs-web/.vitepress/dist/`。`pnpm run build:frontends` 不会构建或发布文档官网。

## 8. 发布检查清单

1. 更新 `docs/` 正文、索引和相关路径引用，确认分区归属正确。
2. 新页面加入侧栏蓝图；必要时更新首页入口与 `theme/data/siteChrome.*.json`。
3. 必要时更新 `catalog.json` 记录并保持 `path` 升序。
4. 改过中文正文的，执行 `pnpm run docs:i18n:trad` 重生成两种繁体。
5. 新增或修改英文页后，执行 `docs-i18n.mjs stamp en` 与 `verify en`。
6. 执行 `pnpm run docs:check`。
7. 执行 `pnpm run build:docs`。
8. 预览生产产物，逐个语言抽查 URL 前缀、标题语言、语言菜单、`hreflang`、站内搜索与移动端布局。
9. 确认站点产物里没有 `internal/` 页面，英文与繁体目录下也没有（`dist/en/internal`、`dist/zh-tw/internal`）。
10. 独立发布 `docs-web/.vitepress/dist/`，不要混入业务前端产物。

维护过程中不得把密码、Token、私钥、生产数据或测试账号凭据写入正文、配置、构建日志或静态资源。
