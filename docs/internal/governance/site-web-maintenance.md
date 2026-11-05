---
status: current
updated: 2026-11-04
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

- 所有访客可见文案在 `site-web/src/i18n/*.json`，键按模块嵌套：`hero`、`shot`、`features`、`flow`、`security`、`deploy`、`migrate`、`pricing`、`faq`、`partners`、`live`、`community`、`footer`、`theme`。组件里不写硬编码文案。
- 地址、锚点、图标 key、素材路径在 `site-web/src/data/site.ts`；文案与常量不互相包含。
- FAQ 数据同时喂给页面和 `FAQPage` 结构化数据，改一处即可，不要在两处各写一份。
- 事实口径以仓库根 `README.md` 与 `docs/` 为准。改能力清单、价格、许可描述时，**先改 README，再同步 `zh_CN.json`**；反过来不行。
- 组件一律不加 `client:*` 指令。加了就相当于给这页装上 Vue 运行时，首屏立刻多出 70 KB 以上的 JS。

### 4.1 文案只写给访客，不写「这页是怎么做的」

首页是给潜在用户看的：他们要判断「这套系统能不能干我的活」，不关心版本号是构建时抓的还是实时渲染的。所以下面这类句子一律不写，写不出用户价值就整条删掉：

| 不写（实现细节）                                                         | 为什么                               |
| ------------------------------------------------------------------------ | ------------------------------------ |
| 「版本号取自构建时的仓库快照」「徽章由 shields.io 实时渲染，缓存几分钟」 | 说的是我们怎么取数，不是产品能力     |
| 「本站不加载它们的脚本」                                                 | 只有我们自己才关心，访客读到只会疑惑 |
| 「图表由第三方按访问实时出图」                                           | 同上；图片能显示就行                 |

改法：换成访客能用的信息（「每次发布都带完整变更记录，版本号点进去就是那次改了什么」），或者直接删掉那一行。`npm run verify` 有一条断言（正文不出现 `shields.io`／`构建时`／`出图`／`不加载` 等词）专门盯这类回归——它是在真被写进页面一次之后加的，不要为了让构建过而把它删掉。

同一个道理适用于所有分区副标题与图注：**先问「用户看这句能得到什么」，答不上来就别写**。

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
- 伙伴墙素材在 `public/partners/`，来源是仓库 README 的 Trusted Partners，按 2 倍显示高度导出。**这些是生态伙伴，不代表都在使用 TuraIDC**；墙下方的「在线演示」单独指向二五云（`idc.25y.cn`，目前唯一跑着系统的生产站），地址在 `src/data/site.ts` 的 `DEMO_URL`。
- 新增伙伴时三处必须同时改：README、`zh_CN.json` 的 `partners.items`、`src/data/site.ts` 的 `PARTNER_LOGOS`（顺序一致）。

## 7. 系统实拍、项目动态与社区

### 7.1 系统实拍图

Hero 右侧那张图不是画的示意图，是用无头 Chrome 打开二五云线上站点截的真实页面：

```cmd
cd site-web
npm run shots
```

- 默认截 `https://idc.25y.cn/products`（商品选购页）；换站点用 `SHOT_BASE=https://... npm run shots`。
- 视口 1280×800、2 倍图，压成 1600×1000 的 WebP（约 78 KB），产物 `public/shots/catalog.webp` **需要入库**：构建与部署只读产物，不跑浏览器。
- 脚本会隐藏 `.ai-guide-launcher` 这类固定浮层；站点改版导致选择器失效时，改脚本里的 `HIDE_SELECTORS`。
- Playwright 不在 site-web 的依赖里，脚本会到仓库根与 `frontend-user-v3-www` 的 `node_modules` 找，找不到直接报错退出。
- 图里是二五云自己的品牌，这是有意的：它就是「在线演示」指向的生产站，图注写明了来源。将来有官方演示站，改 `SHOT_BASE` 重跑即可。
- 不做控制台 / 管理端截图：那两页要登录，线上站没有可公开的演示账号。

### 7.2 实时数据与构建期快照

首页「还在维护」这件事分两种实现，别混：

| 数据                                                                      | 来源                                                                     | 何时刷新                             |
| ------------------------------------------------------------------------- | ------------------------------------------------------------------------ | ------------------------------------ |
| 11 枚徽章（版本/Star/Fork/贡献者/issue/最近提交/许可/CI/PHP/Laravel/Vue） | shields.io 与 GitHub Actions 出图（`src/data/site.ts` 的 `LIVE_BADGES`） | 访客每次打开页面，shields 缓存几分钟 |
| 项目热度曲线                                                              | star-history.com 出图（含 README 自带的 `sealed_token`）                 | 访客每次打开页面                     |
| 贡献者头像墙                                                              | contrib.rocks 出图                                                       | 访客每次打开页面                     |
| 最新版本 tag + 发布日期 + 历史版本                                        | `src/data/github.json` 构建期快照                                        | 每次构建（`prebuild`）               |
| 语言构成（前 5 + Other）                                                  | `src/data/github.json` 构建期快照                                        | 每次构建（`prebuild`）               |

- 实时三样都是**服务端渲染的图片地址**：页面零客户端 JS，不引第三方 SDK，也不在访客浏览器里请求 GitHub API。徽章与图表都写死 `width`/`height`，配 `loading="lazy"`、`decoding="async"`、`referrerpolicy="no-referrer"`。
- star-history 用 `<picture>` + `<source media="(prefers-color-scheme: dark)">` 切深浅两版：它**跟随系统配色，不跟随站内主题开关**。文案里不要写「跟随主题」。
- 构建期快照由 `scripts/fetch-github.mjs` 抓：`GET /repos/25Cloud/TuraIDC`、`/releases?per_page=5`，外加 `/languages`。
  - `repo` 与 `releases` 一起抓，任一失败就整份沿用仓库里的旧快照并打 warning。
  - `/languages` 单独 try/catch：它挂了只保留旧的 `languages`，已经拿到的新 release 照常写入。
  - 首次运行且没有任何快照才 `exit 1`；正常断网、限流（无 token 时 60 次/小时）、仓库改名都不会让构建挂掉。
  - 语言百分比按字节算：降序取前 5，其余合并成 `Other`，`percent` 保留一位小数。颜色取 `src/data/site.ts` 的 `LANGUAGE_COLORS`，表里没有的语言落到中性灰 `LANGUAGE_FALLBACK_COLOR`。
- 数据没变就不写文件，避免每次构建都在工作区留一处无意义改动；手动刷新用 `npm run sync:data`。需要更高额度时给 `GITHUB_TOKEN`。

### 7.3 新增或替换徽章

三处一起改，少一处 `npm run verify` 就会拦下：

1. `site-web/src/data/site.ts` 的 `LIVE_BADGES`：`key`、`group`（`repo` / `stack`，决定换行位置）、`label`（徽章上的英文标签，同时是 `alt` 与链接的 `aria-label`）、`src`、`href`、`width`、`height`。
2. `site-web/scripts/verify-build.mjs` 的 `SHIELD_BADGES`：把同一个 URL 抄进断言清单；数量变了还要改「徽章墙共 11 枚实时徽章」那条。

**CI 徽章**：`ci.yml` 现在随 push 与 PR 触发、当前为绿，所以首页挂着它。它以前只有 `workflow_dispatch`，实际从不跑、长期停在一次失败上，那段时间首页刻意不挂——判断标准是「它是否真的在跑并且是绿的」，不是「README 里有没有」。哪天它又长期红，按同样方式撤下（删 `LIVE_BADGES` 里 `key: "ci"` 那一项、verify 清单和总数），别让访客面对一枚停在几个月前的 failing。

3. 文案：`src/i18n/*.json` 的 `live.badgesTitle` 四语齐全（`zh_CN` 是真源）。

`width` / `height` 填的是**当前徽章实测宽度**（`curl` 一把看 SVG 头部的 `width`），只作为加载前的占位；渲染时 CSS 固定 `height: 20px`、`width: auto`，数字从 `97` 长到 `1.2k` 也不会被属性宽度拉扯变形。

### 7.4 断网或被墙时的页面表现

- shields.io / star-history / contrib.rocks 的图拉不到时，页面只留下占位框与 `alt` 文本；正文、链接、历史版本、语言占比（本地快照）照常显示，不白屏也不抖动。
- 这是刻意接受的行为：实时数据一旦引第三方主机，就有可用性风险；为此加 JS 兜底会破坏「零客户端 JS」，加服务端代理又多一处要维护的接口。要更稳，只能换成构建期快照（并同步改文案口径）。
- star-history 的深色版是它自己的深色主题，底色和站内深色卡片不完全一致，属正常现象。

### 7.5 社区板块（CommunitySection）

- 位置：`SiteShell.vue` 里排在 `LiveSection` 之后、`HighlightsSection` 之前；`id="community"`，用 `.section-alt` 与前后区块交替底色。
- 四块内容：项目热度（star-history）、贡献者（contrib.rocks）、语言构成、开发交流。
- 开发交流：QQ 群 `1105174267`、加群链接 `https://qm.qq.com/q/Doa31p5M30`、二维码 `public/images/qq-group.jpg`。二维码是从 `docs/images/qrcode_1787147945469.jpg` 复制过来的**仓库内素材**，不引 GitHub raw，也不外链。
- 群号写成可选中的文本（`user-select: all`），**不为了「复制按钮」加一行 JS**。
- 语言构成只在构建后随快照变：发版后走一次部署就会刷新。
- 换二维码：把新图覆盖到 `site-web/public/images/qq-group.jpg`，同时改 `src/data/site.ts` 里 `COMMUNITY.qqQrcodeWidth` / `qqQrcodeHeight`。

### 7.6 自检断言怎么跟着加

`scripts/verify-build.mjs` 是部署门禁，改这块页面时同步追加：

- 徽章：每枚徽章两条断言（URL 在页面里 + `width`/`height`/`loading`/`decoding`/`referrerpolicy` 齐全），外加总数断言。
- 社区：star-history 深浅两版 URL、`prefers-color-scheme` 的 `<source>`、star-history 外链、contrib.rocks 图片与贡献者链接、占比条至少 3 段、语言列表至少 3 行、群号与 `qm.qq.com` 链接、二维码走站内路径。
- 零 JS：沿用原有口径（内联 `<script>` 总数固定、HTML 不引用外部脚本、`dist` 里的 `.js` 未被引用），再加一条「社区板块内没有 `<script>`/`<iframe>`/内联事件」。
- 产物：`public/images/qq-group.jpg` 要进 `requiredAssets`。
- 旧断言里「正文出现 Star 数字」已随数字格子退役删除，换成「不再渲染快照数字格子」的反向断言；许可证改成「正文出现 `AGPL-3.0`」。

## 8. 发布检查清单

1. `cd site-web && npm run build && npm run verify` 通过（当前 130 项）。
2. 四个语言页的 `index.html` 都能看到对应语言的正文，`title` 与 `description` 不是占位符。
3. `sitemap-index.xml` 收录四个语言页；`robots.txt` 指向 sitemap 索引。
4. 明暗两种主题各看一遍：没有低对比度文字，也没有硬编码浅色块露出来。
5. 桌面 1280、平板 834、移动 390 各看一遍：没有横向滚动、没有元素重叠、首屏 CTA 可点；徽章墙在窄屏按两三个一行换行，不撑破容器。
6. 「项目动态」的版本号与 release 日期跟仓库对得上（对不上就 `npm run sync:data` 再构建）；Star / Fork / issue 这些数字徽章刷新页面即最新，不由本站构建决定。
7. 「社区」四块都在：star-history 图（深色系统下应是深色版）、贡献者头像墙、语言占比条与 Top 5、QQ 群号 / 加群链接 / 二维码。
8. Hero 的实拍图与当前线上站点一致（站点改版后 `npm run shots` 重截并入库）。
9. 页脚的 AGPL 声明、仓库链接、`contact@turaidc.com` 都在。
10. 改了 `docs/` 或 `AGENTS.md` 时，额外跑 `pnpm run docs:check`。

## 9. 排错

| 现象                         | 原因                                                               | 处理                                                                                |
| ---------------------------- | ------------------------------------------------------------------ | ----------------------------------------------------------------------------------- |
| 线上页面是空的/只有骨架      | 构建产物里的正文没了，或部署的是旧产物                             | 本地 `npm run verify` 复现；确认 EdgeOne 部署记录对应最新提交                       |
| 首屏以下内容滚动才出现       | 这是刻意的进场动效；`prefers-reduced-motion` 下应当全部直接显示    | 若在无动效环境下仍然空白，说明 `is-pending` 没被移除，检查 JS 报错                  |
| 构建日志里出现 `check_docs`  | 平台读的是仓库根 `edgeone.json`，不是 `site-web/edgeone.json`      | 按第 2 节回退：删仓库根配置，两个项目都改用控制台配置                               |
| 构建日志出现 GitHub 抓取告警 | 断网、限流或仓库改名；构建会继续用旧快照                           | 看告警里的 HTTP 状态；需要时配 `GITHUB_TOKEN`，或本地 `npm run sync:data` 后提交    |
| 项目动态的版本号落后一版     | 新 release 发了但还没重新部署                                      | 推一次 `main` 触发重建，或本地 `npm run sync:data && npm run build`                 |
| 实拍图和线上站点对不上       | 截图是上一次跑的产物，站点改版后没重截                             | `npm run shots` 重新生成 `public/shots/catalog.webp` 并提交                         |
| 截图里有客服浮窗挡住内容     | 站点新增了固定浮层，`HIDE_SELECTORS` 没覆盖到                      | 在 `capture-shots.mjs` 里补选择器后重跑                                             |
| 结构化数据报错               | `src/i18n/*.json` 或 `schema.ts` 的结构被改坏                      | `npm run verify` 会直接报"结构化数据可解析"失败                                     |
| 切换主题时闪一下白底         | 主题脚本被挪到 `<body>` 或改成外链                                 | 必须留在 `<head>` 内联执行，见第 6 节                                               |
| 某个语言页 404               | `src/pages/<path>/index.astro` 没建，或 `LOCALES` 路径与目录不一致 | 两处路径要一致；`verify-build.mjs` 会报缺页                                         |
| 社交分享没有预览图           | 目前没有生成 `og:image`，只有文字卡片                              | 补一张 1200×630 的封面放进 `public/`，再加 `og:image` 与 `twitter:image`            |
| 页面能开但百度不收录         | 境外节点 + 新域名的抓取周期长，且未提交 sitemap                    | 到百度搜索资源平台提交 sitemap，补齐外链                                            |
| 产物里多出一个未引用的 JS    | `@astrojs/vue` 的水合运行时会留在 `dist/_astro/`，页面不引用它     | 属正常；`npm run verify` 会断言它没被 HTML 引用，加载体积不受影响                   |
| 徽章空白或显示英文 alt       | shields.io 被墙、限流或临时故障                                    | 换网络再看；长期不可用就按 7.3 换图源，并在文案里改口径                             |
| CI 徽章红着或长期不变        | 要么最近一次运行真的失败，要么 workflow 只剩手动触发、根本没在跑   | 先去 Actions 页确认真实状态；若长期红就修 CI，修不动先按 7.3 把徽章撤下             |
| star-history 图不显示        | `sealed_token` 过期或 star-history 改接口                          | 回 README 抄一份新地址到 `src/data/site.ts` 的 `STAR_HISTORY`，verify 里的 URL 同步 |
| 深色模式下图表还是浅色       | `prefers-color-scheme` 跟系统走，不看站内主题                      | 属预期；文案已写明，不要为此加 JS 联动                                              |
| 语言占比条少了一种           | 新语言没进 `LANGUAGE_COLORS`，落到中性灰                           | 补色卡（取 GitHub 语言色）后重建；占比本来只取前 5，其余合并成 Other                |
| 二维码扫不出                 | `public/images/qq-group.jpg` 被替换成低分辨率图                    | 用原始二维码图覆盖，并同步 `COMMUNITY.qqQrcodeWidth` / `qqQrcodeHeight`             |
