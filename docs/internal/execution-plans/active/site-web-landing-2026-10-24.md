---
status: 进行中
updated: 2026-10-24
owner: frontend
---

# 项目官网首页（site-web）实施计划

**Goal：** 在仓库内落地一个单页产品首页 `site-web/`，以独立 EdgeOne Pages 项目部署到 `www.turaidc.com`，静态输出完整 HTML，承担品牌展示与搜索入口；文档站与仓库作为内容与转化的落点。

**前置阅读：**

- 设计文档：[项目官网首页（site-web）设计](../../designs/frontend/site-web-landing-design.md)
- 文档站部署口径：[文档官网维护指南](../../governance/docs-web-maintenance.md)
- 视觉 token：[视觉与交互规范](../../../develop/design.md)
- 工程约束：[工作规则](../../governance/WORKING_RULES.md)

**验证命令：**

```cmd
cd site-web && npm ci && npm run build && npm run verify
pnpm run docs:check
```

## 任务

### Task 1：站点骨架

- [x] `site-web/package.json`（npm + `package-lock.json`，与 `docs-web` 同一套做法）
- [x] `astro.config.mjs` + `src/pages/index.astro`：`site`、sitemap 集成、head（canonical / OG / JSON-LD）
- [x] Vue 组件不加 `client:*`，由 `@astrojs/vue` 在构建期渲成静态 HTML
- [x] `edgeone.json` + `public/robots.txt`
- [x] `scripts/verify-build.mjs` 产物自检并接入构建命令（含「零客户端 JS」断言）

### Task 2：首页内容与动效

- [x] 九段结构：Hero、合作伙伴、能力矩阵、业务闭环、安全对比、部署、迁移、定价、FAQ、页脚
- [x] 文案按模块收进 `src/i18n/*.json`，FAQ 与 JSON-LD 同源
- [x] 首屏 CSS 关键帧；滚动进场只作用于视口外元素；`prefers-reduced-motion` 全静态
- [x] 响应式：1440 / 1080 / 900 / 560 四个断点，无横向溢出

### Task 3：仓库接入

- [x] 根 `package.json` 增加 `dev:site` / `build:site` / `verify:site`
- [x] `AGENTS.md` 项目地图、按任务阅读、验证矩阵补 `site-web/`
- [x] 维护文档 `docs/internal/governance/site-web-maintenance.md`

### Task 4：EdgeOne Pages 上线（待执行）

- [ ] 新建 Pages 项目，仓库 `25Cloud/TuraIDC`，根目录 `site-web/`，先不绑域名
- [ ] 验证 `edgeone.json` 归属：构建日志里应出现 `npm ci` 与 `astro build`，不应出现 `check_docs.mjs`
- [ ] 若平台读的是仓库根 `edgeone.json`：删除该文件，把 docs 的构建参数搬进 docs 项目的控制台配置后重试
- [ ] 绑定 `www.turaidc.com`，`turaidc.com` 301 到 `www`
- [ ] 提交 sitemap 到百度搜索资源平台、Bing Webmaster、Google Search Console
- [ ] 文档站顶栏与首页门户补一条指向 `https://www.turaidc.com/` 的入口（内链闭环，单独一次改动）

## 进度

- [x] 设计定稿：独立项目、单页、`www` 主域、只走境外节点
- [x] 站点骨架与九段内容落地，本地构建通过
- [x] 构建链从 VitePress 切到 Astro：首屏从 9 请求 / 520 KB 降到 3 请求 / 116 KB，零客户端 JS
- [x] 文案重写：改为「小字标签 → 大字主张 → 一句支撑」的结构，去掉此前偏口语的 AI 腔
- [x] 四语言静态页（`/`、`/zh-tw/`、`/zh-hk/`、`/en/`）+ 头部语言切换 + hreflang
- [x] 明 / 暗 / 跟随系统三态主题，首帧内联脚本防闪白
- [x] 合作伙伴 Logo 墙（README 的 Trusted Partners 四家）
- [x] 产物自检 79 项全过（4 语言正文、SEO、hreflang、JSON-LD、sitemap、零外部脚本、伙伴素材）
- [x] 桌面 1440 / 移动 390、明暗两种主题截图复核：无横向溢出、无控制台报错
- [ ] EdgeOne 项目创建与根目录归属验证
- [ ] 域名绑定、301 规则与搜索引擎提交
- [ ] 文档站内链入口

## 决策日志

- 2026-10-24：不新开仓库，在 `site-web/` 落地。理由是新仓库会让版本号、能力清单、截图与主仓漂移，并额外引入一套 CLA 与许可声明维护。
- 2026-10-24：独立 EdgeOne Pages 项目而不是合并进 `docs-web`。根 `edgeone.json` 的构建链带文档校验，合并后改一句首页文案都要过一遍坏链门禁。
- 2026-10-24：`www.turaidc.com` 为主域，`docs.turaidc.com` 原地不动。文档 URL 已被 README、GitHub 与搜索引擎引用，迁移收益不抵风险。
- 2026-10-24：只走境外节点，不做 ICP 备案。接受大陆访问与百度抓取的折损，换运维与合规成本下降；后续要进大陆再单独评估。
- 2026-10-24：v1 只做首页，不做多页矩阵。SEO 覆盖面靠文档站，首页只保证品牌词与核心词的落地页质量。
- 2026-10-24：滚动动效改为"只隐藏视口外元素"。初版用 `html.has-reveal` 统一隐藏，全页截图与不滚动场景会看到空白，等于拿正文可见性换动效。
- 2026-10-24：构建链从 VitePress 换到 Astro。VitePress 版实测首屏 9 请求 / 约 520 KB（默认主题 CSS 128 KB、运行时 JS 253 KB、Inter 字体 66 KB），而这页只有滚动进场一个交互，底座全是负债；换成 Astro 后 3 请求 / 116 KB、零 JS，组件写法不变。同时把 logo 换成 420px WebP（72 KB → 9 KB）、favicon 缩到 64px。
- 2026-10-24：多语言用四份预渲染静态页，不在运行时切语言。语言代码沿用 `shared/locale`，语言包按模块嵌套（与三端语言包同一套粒度）；繁体由简体经 OpenCC 生成后人工抽查，英文手写。
- 2026-10-24：主题做成三态（明 / 暗 / 跟随系统），默认跟随系统。深色只做浅色 token 的镜像，不引入第二套品牌色；配色全部收进 `tokens.css`，组件禁止写死颜色。
- 2026-10-24：合作伙伴墙只放 README 里的四家，素材本地化（仙林云原来是外链他站 CDN），顺序在 README、语言包、`PARTNER_LOGOS` 三处保持一致。
- 2026-10-24：伙伴墙不再宣称「正在使用 TuraIDC」。这四家是生态伙伴，真正跑着系统的只有二五云；改为「合作伙伴」标题 + 墙下方独立的「在线演示」条，指向 `idc.25y.cn`，Hero 也加一个入口。
