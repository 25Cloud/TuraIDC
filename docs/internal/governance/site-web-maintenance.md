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

- 所有访客可见文案在 `site-web/src/data/content.ts`。组件里不写硬编码文案。
- FAQ 数据同时喂给页面和 `FAQPage` 结构化数据，改一处即可，不要在两处各写一份。
- 事实口径以仓库根 `README.md` 与 `docs/` 为准。改能力清单、价格、许可描述时，**先改 README，再同步 `content.ts`**；反过来不行。
- 组件一律不加 `client:*` 指令。加了就相当于给这页装上 Vue 运行时，首屏立刻多出 70 KB 以上的 JS。
- 图标只用 `tdesign-icons-vue-next`，需要新图标时在 `site-web/src/icons.ts` 里加语义 key，不在组件里直接引图标组件。
- 视觉 token 在 `site-web/src/styles/tokens.css`，数值来自 [视觉规范](../../develop/design.md)。新增颜色前先问自己是不是已有 token 就能表达。

## 5. 发布检查清单

1. `cd site-web && npm run build && npm run verify` 通过。
2. 构建产物 `index.html` 里能看到正文（不是空壳），`title` 与 `description` 唯一且不含占位符。
3. `sitemap-index.xml`（以及被它引用的 `sitemap-0.xml`）与 `robots.txt` 使用 `https://www.turaidc.com/` 绝对地址。
4. 桌面 1440 与移动 390 各看一遍：没有横向滚动、没有元素重叠、首屏 CTA 可点。
5. 页脚的 AGPL 声明、仓库链接、`contact@turaidc.com` 都在。
6. 改了 `docs/` 或 `AGENTS.md` 时，额外跑 `pnpm run docs:check`。

## 6. 排错

| 现象                        | 原因                                                            | 处理                                                                     |
| --------------------------- | --------------------------------------------------------------- | ------------------------------------------------------------------------ |
| 线上页面是空的/只有骨架     | 构建产物里的正文没了，或部署的是旧产物                          | 本地 `npm run verify` 复现；确认 EdgeOne 部署记录对应最新提交            |
| 首屏以下内容滚动才出现      | 这是刻意的进场动效；`prefers-reduced-motion` 下应当全部直接显示 | 若在无动效环境下仍然空白，说明 `is-pending` 没被移除，检查 JS 报错       |
| 构建日志里出现 `check_docs` | 平台读的是仓库根 `edgeone.json`，不是 `site-web/edgeone.json`   | 按第 2 节回退：删仓库根配置，两个项目都改用控制台配置                    |
| 结构化数据报错              | `content.ts` 或 `schema.ts` 的 JSON-LD 结构被改坏               | `npm run verify` 会直接报"结构化数据可解析"失败                          |
| 社交分享没有预览图          | 目前没有生成 `og:image`，只有文字卡片                           | 补一张 1200×630 的封面放进 `public/`，再加 `og:image` 与 `twitter:image` |
| 页面能开但百度不收录        | 境外节点 + 新域名的抓取周期长，且未提交 sitemap                 | 到百度搜索资源平台提交 sitemap，补齐外链                                 |
| 产物里多出一个未引用的 JS   | `@astrojs/vue` 的水合运行时会留在 `dist/_astro/`，页面不引用它  | 属正常；`npm run verify` 会断言它没被 HTML 引用，加载体积不受影响        |
