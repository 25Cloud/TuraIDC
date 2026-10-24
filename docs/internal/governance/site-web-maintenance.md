---
status: current
updated: 2026-10-24
owner: frontend
---

# 项目官网首页维护（site-web）

`site-web/` 是 TuraIDC 项目本身的产品首页，部署在 `https://www.turaidc.com/`。它与下面两个东西**不是**一回事：

- `docs-web/`：文档站，部署在 `https://docs.turaidc.com/`，内容源是 `docs/`。
- `frontend-user-v3-www/`：每个站长自己那套官网门户，跟着业务系统一起部署。

设计取舍见[设计文档](../designs/frontend/site-web-landing-design.md)，实施步骤见[执行计划](../execution-plans/active/site-web-landing-2026-10-24.md)。

## 1. 本地开发

```cmd
cd site-web
npm install
npm run dev
```

依赖走 npm（有 `package-lock.json`），**不加入 pnpm workspace**：`docs-web` 已经是这套双包管理器结构，再加一层只会让锁文件互相打架。

构建与自检：

```cmd
cd site-web
npm run build
npm run verify
```

## 2. 部署（EdgeOne Pages）

独立项目，仓库 `25Cloud/TuraIDC`，根目录设为 `site-web/`。

`site-web/edgeone.json` 是项目自己的构建配置：

```json
{
  "installCommand": "npm ci",
  "buildCommand": "npm run build && npm run verify",
  "outputDirectory": "dist",
  "nodeVersion": "22.11.0"
}
```

两点必须保持：

- **安装用 `npm ci`**：`site-web/package-lock.json` 必须入库，否则线上会出现依赖版本漂移。
- **构建后跑 `npm run verify`**：它校验正文是否真进了 HTML、title/description/canonical 是否齐全、JSON-LD 能否解析、sitemap 与 robots 是否生成、站内链接是否还有落点。校验失败会拦住这次自动部署。

### 必须确认的一件事

EdgeOne 的文档说 `edgeone.json` 放在"项目根目录"。仓库根也存在一份（属于文档站）。把项目根目录设成 `site-web/` 后，要先确认平台读的是 `site-web/edgeone.json`。

判断方法：看构建日志里执行的安装/构建命令。出现 `check_docs.mjs` 就是在读仓库根那份。此时回退方案是删掉仓库根 `edgeone.json`，把文档站的构建参数搬进它自己的控制台配置，两个项目都走控制台。

## 3. 域名与加速

- 主域 `www.turaidc.com`，`turaidc.com` 301 到 `www`，`docs.turaidc.com` 保持不变。
- 站点只走境外节点（不做 ICP 备案）：大陆访客首屏会慢一些，百度抓取频次也低。对外传播以 GitHub、Bing、Google 与社区渠道为主。

## 4. 改文案与改结构

- 所有访客可见文案在 `site-web/src/i18n/*.json`，键按模块嵌套：`hero`、`features`、`flow`、`security`、`deploy`、`migrate`、`pricing`、`faq`、`partners`、`footer`、`theme`。组件里不写硬编码文案。
- 地址、锚点、图标 key、素材路径在 `site-web/src/data/site.ts`；文案与常量不互相包含。
- FAQ 数据同时喂给页面和 `FAQPage` 结构化数据，改一处即可，不要在两处各写一份。
- 事实口径以仓库根 `README.md` 与 `docs/` 为准。改能力清单、价格、许可描述时，**先改 README，再同步 `zh_CN.json`**；反过来不行。
- 组件一律不加 `client:*` 指令。加了就相当于给这页装上 Vue 运行时，首屏立刻多出 70 KB 以上的 JS。
- 图标只用 `tdesign-icons-vue-next`，需要新图标时在 `site-web/src/icons.ts` 里加语义 key，不在组件里直接引图标组件。

## 5. 多语言

语言代码沿用 `shared/locale` 的 `zh_CN` / `zh_TW` / `zh_HK` / `en_US`，URL 用短路径：

| 代码    | 路径      | `<html lang>` |
| ------- | --------- | ------------- |
| `zh_CN` | `/`       | `zh-CN`       |
| `zh_TW` | `/zh-tw/` | `zh-TW`       |
| `zh_HK` | `/zh-hk/` | `zh-HK`       |
| `en_US` | `/en/`    | `en`          |

- 每种语言一份预渲染 HTML，带四条 `hreflang` 加一条 `x-default`；`verify-build.mjs` 会断言这个数量，少一条就构建失败。
- 语言切换是头部的原生 `<details>` 下拉，直接跳目标语言的根路径，不依赖 JS，也不保留锚点。
- 改文案顺序：改 `zh_CN.json` → 重跑 OpenCC 脚本生成 `zh_TW.json` / `zh_HK.json` → 手动改 `en_US.json` → 抽查繁体用词。
- 加一门语言：新增 `<code>.json` → `src/i18n/index.ts` 的 `LOCALES` 加一条（`path` / `label` / `htmlLang` / `ogLocale`）→ 新增 `src/pages/<path>/index.astro`（只有三行，传 `code`）→ `npm run verify`。

## 6. 主题与素材

- 三态主题：浅色 / 深色 / 跟随系统，默认跟随系统，偏好存 `localStorage["turaidc-site-theme"]`。
- 首帧由 `<head>` 里的内联脚本写入 `<html data-theme-pref>` 与 `data-theme`，避免闪白；三个切换按钮是 `[data-theme-set]`，由同一段脚本事件委托处理。
- 颜色只在 `src/styles/tokens.css`：浅色定义在 `.site`，深色在 `html[data-theme="dark"] .site`。组件里禁止写死颜色，新增颜色必须同时给深色镜像。
- 品牌素材：`public/turaidc-logo.webp`（页面用，420px 宽）、`public/turaidc-logo.png`（结构化数据用）、`public/favicon.png`（64px）、`public/apple-touch-icon.png`（180px）。
- 伙伴墙素材在 `public/partners/`，来源是仓库 README 的 Trusted Partners，按 2 倍显示高度导出。新增伙伴时三处必须同时改：README、`zh_CN.json` 的 `partners.items`、`src/data/site.ts` 的 `PARTNER_LOGOS`（顺序一致）。

## 7. 发布检查清单

1. `cd site-web && npm run build && npm run verify` 通过（当前 79 项）。
2. 四个语言页的 `index.html` 都能看到对应语言的正文，`title` 与 `description` 不是占位符。
3. `sitemap-index.xml` 收录四个语言页；`robots.txt` 指向 sitemap 索引。
4. 明暗两种主题各看一遍：没有低对比度文字，也没有硬编码浅色块露出来。
5. 桌面 1440 与移动 390 各看一遍：没有横向滚动、没有元素重叠、首屏 CTA 可点。
6. 页脚的 AGPL 声明、仓库链接、`contact@turaidc.com` 都在。
7. 改了 `docs/` 或 `AGENTS.md` 时，额外跑 `pnpm run docs:check`。

## 8. 排错

| 现象                        | 原因                                                               | 处理                                                                     |
| --------------------------- | ------------------------------------------------------------------ | ------------------------------------------------------------------------ |
| 线上页面是空的/只有骨架     | 构建产物里的正文没了，或部署的是旧产物                             | 本地 `npm run verify` 复现；确认 EdgeOne 部署记录对应最新提交            |
| 首屏以下内容滚动才出现      | 这是刻意的进场动效；`prefers-reduced-motion` 下应当全部直接显示    | 若在无动效环境下仍然空白，说明 `is-pending` 没被移除，检查 JS 报错       |
| 构建日志里出现 `check_docs` | 平台读的是仓库根 `edgeone.json`，不是 `site-web/edgeone.json`      | 按第 2 节回退：删仓库根配置，两个项目都改用控制台配置                    |
| 结构化数据报错              | `src/i18n/*.json` 或 `schema.ts` 的结构被改坏                      | `npm run verify` 会直接报"结构化数据可解析"失败                          |
| 切换主题时闪一下白底        | 主题脚本被挪到 `<body>` 或改成外链                                 | 必须留在 `<head>` 内联执行，见第 6 节                                    |
| 某个语言页 404              | `src/pages/<path>/index.astro` 没建，或 `LOCALES` 路径与目录不一致 | 两处路径要一致；`verify-build.mjs` 会报缺页                              |
| 社交分享没有预览图          | 目前没有生成 `og:image`，只有文字卡片                              | 补一张 1200×630 的封面放进 `public/`，再加 `og:image` 与 `twitter:image` |
| 页面能开但百度不收录        | 境外节点 + 新域名的抓取周期长，且未提交 sitemap                    | 到百度搜索资源平台提交 sitemap，补齐外链                                 |
| 产物里多出一个未引用的 JS   | `@astrojs/vue` 的水合运行时会留在 `dist/_astro/`，页面不引用它     | 属正常；`npm run verify` 会断言它没被 HTML 引用，加载体积不受影响        |
