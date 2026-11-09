---
status: current
updated: 2026-11-09
owner: frontend
---

# 演示站（demo site）设计

## 1. 背景与目标

要卖这套系统，就得让人**点得到**：现在唯一的可交互入口是生产站，既不合适拿给陌生人乱点，也没法在文章、README、release 里给一个"随便试"的地址。

目标：一个**纯静态**演示站，四个入口，全部跑在 EdgeOne Pages（与 `site-web` 同一套做法）：

| 路径 | 内容 | 来源 |
| --- | --- | --- |
| `/` | 官网首页（四语言） | `site-web/` 产物原样复用 |
| `/user/` | 用户端（选购、下单、账户） | `frontend-user-v3-www` demo 构建 |
| `/console/` | 用户控制台（实例、账单、工单） | `frontend-user-v4-console` demo 构建 |
| `/admin/` | 管理端（看板、商品、订单、设置） | `frontend-admin-v3` demo 构建 |

非目标：真实鉴权、数据持久化、多租户、后端接口。演示站**没有任何后端**，所有响应用打包进产物的示例数据。

## 2. 现状约束（代码事实，不是设想）

- 三端都是 Vue 3 + Vite SPA，**请求统一走一个 axios 实例**：控制台 `frontend-user-v4-console/src/utils/request.ts`、管理端 `frontend-admin-v3/src/utils/request/Axios.ts`、用户端 `frontend-user-v3-www/src/app/http/request.ts`。这是唯一需要挂假数据的地方。
- `VITE_API_BASE_URL` 由 `backend/scripts/build_frontends.mjs` 从 `APP_URL` 推导；`VITE_BASE_URL` 已经支持**子路径部署**（该脚本里的本地补丁就是为 `/console`、`/admin` 同域部署做的）。
- 三端路由目前都是 `createWebHistory`：控制台用 `import.meta.env.VITE_BASE_URL`，管理端硬编码 `'/'`，用户端另有一套。
- `site-web/` 是 Astro 多语言静态站，已作为独立 EdgeOne Pages 项目部署，仓库里有 `site-web/edgeone.json` 的现成样板。
- EdgeOne Pages 的 SPA 回退只有**整站一条** `{"source": "/*", "destination": "/index.html"}`，且官方明确只有这一条会被识别为 SPA fallback；其余 `rewrites` 是普通重写，会连静态资源一起改写。四个应用共用一个域名时，**做不到按前缀各自回退**。

## 3. 架构决策

### 3.1 一个 EdgeOne 项目、一个域名、四个子目录

`demo.turaidc.com/` 给首页，`/user/`、`/console/`、`/admin/` 给三个 SPA。理由：分享一条链接就够；首页与演示同域，转化路径最短；与文档站、首页站互不影响。

### 3.2 假数据挂在 axios adapter 层，而不是改每个调用点

演示构建时给实例装上一个 `demoTransport`：

```ts
// 各端 request 模块里只有这几行差异
if (import.meta.env.VITE_DEMO_MODE === 'true') {
  installDemoTransport(instance, { app: 'console' });
}
```

理由：`src/api/*.ts` 里有一两百个调用点，改调用点等于把演示逻辑摊进业务代码；adapter 是 axios 的正式扩展点，拿到 `config` 直接返回一个 `AxiosResponse`，请求根本不出浏览器。生产构建里这段被 `VITE_DEMO_MODE` 严格等于 `'true'` 挡掉，产物内不引用 fixtures。

### 3.3 demo 构建改用 hash 路由

`createWebHashHistory()` 只在 demo 构建生效（生产仍是 history）。理由见 §2 最后一条：静态托管下四个应用共域名时，deep link 无法靠服务端回退；hash 让 `/console/#/services/12` 这类地址**不需要任何服务端配置**就能打开，也不影响 `base` 前缀下的静态资源。

代价：URL 里带 `#`；只在演示站出现。

### 3.4 fixtures 由生成器产出，不手写散装 JSON

`demo/fixtures/` 下按端分模块（业务域一个文件），构建时合并成每端一份 `fixtures.json` 注入产物。理由：示例数据要**内部自洽**（实例、账单、工单里的客户名、到期时间、金额互相对得上），手写散 JSON 一分钟就漂移；用 JS 生成还能共用一套「客户 / 产品 / 实例」基础数据。

后续若后端有可用测试实例，可加录制脚本（Playwright 走一遍页面、把 XHR 响应脱敏落盘）替换生成器，接口不变。

## 4. 传输层契约

- **命中键**：`METHOD + 去掉查询串的路径`，路径里允许 `:id` 通配（如 `GET /v2/client/services/:id`）。查询串参与匹配时只做"忽略"，不参与取值。
- **未命中**：返回业务成功信封 + 空数据，并记进 `window.__DEMO_MISSES__`（数组），同时 `console.warn`。**不静默**：演示站要能一眼看出哪块数据没铺到。
- **写操作**：默认返回成功信封（`{status:200, msg:'success', data:{}}`），并按需就地改内存状态（比如开关机改实例状态），让操作"看得见反馈"。
- **危险动作白名单**：删除实例 / 退款 / 改密 / 重装这类，返回业务错误「演示环境不执行该操作」。比假装成功更真实——用户能看到系统自己的错误提示链路。
- **鉴权**：demo 构建在应用启动时写入固定 token（`demo-token`），`/auth/info`、权限、菜单全部由 fixtures 应答（管理端给全权限）。
- **角标**：右下角固定「演示环境 · 数据为示例，操作不会生效」，由 transport 注入 DOM，三端一致，不侵入各自布局。

## 5. 目录与构建

```text
demo/                        # 新包：只放 EO 项目配置与演示入口
  edgeone.json               # EO 项目根配置（根目录设为 demo/）
backend/scripts/build_demo.mjs   # 组装四个产物到 demo/dist
shared/demo/                 # 传输层（transport / matcher / fixtures 加载 / 角标）
demo/fixtures/               # 各端示例数据生成器
```

`demo/dist` 结构：`index.html`（site-web 首页四语言整套）、`user/`、`console/`、`admin/`。

### EdgeOne Pages 项目配置

平台口径（[构建指南](https://pages.edgeone.ai/zh/document/build-guide)）：**根目录就是执行构建命令的路径**，输出目录相对根目录，构建命令在 Bash 里跑，镜像预装 pnpm（6–11）与 Node 22.11.0。

| 控制台字段 | 取值 |
| --- | --- |
| Preset framework | `Other` |
| Root directory | `demo/` |
| Build output directory | `dist` |
| Install command | `cd .. && HUSKY=0 pnpm install --frozen-lockfile` |
| Build command | `cd .. && node backend/scripts/build_demo.mjs` |

同一份取值也写在 `demo/edgeone.json` 里，控制台与配置文件保持一致，避免两处漂移。

`site-web` 不在 pnpm workspace（自带 `package-lock.json`、用 npm），所以它的 `npm ci` 由 `build_demo.mjs` 自己兜，安装命令只管 workspace 依赖；首页装不上或构建失败会退回演示入口页，不让首页把整个演示站带翻。

构建参数：`VITE_DEMO_MODE=true`、`VITE_API_BASE_URL=https://demo.turaidc.com/api`（演示域名口径，永远不会被真正访问，但漏配时也不会打到真实接口）、三个 `VITE_BASE_URL` 分别为 `/user/`、`/console/`、`/admin/`。

## 6. 安全与合规

- 演示产物**零外网请求**：构建后由 Playwright 断言所有请求都落在本站域名（漏掉的统计脚本、字体、图床都要在这里暴露）。
- fixtures 脱敏：不得出现真实域名、IP、客户名、手机号、邮箱、订单号；一律使用「演示 / example.com」口径。
- 演示 token 不是真实凭据，不指向任何后端；`VITE_API_BASE_URL` 用 `.invalid` 顶级域，即使漏拦截也不会泄露到生产接口。

## 7. 验收

1. `node backend/scripts/build_demo.mjs` 一次产出 `demo/dist` 四个入口，三端构建退出码 0。
2. 本地 `serve demo/dist` 后 Playwright 冒烟：四入口首屏有内容、零 console error、零跨域请求。
3. 控制台：概览、服务列表、服务详情（含快照 / ISO / 安全组 tab）、账单、工单可点开。
4. 管理端：看板、商品列表、订单、用户、设置页可点开。
5. 用户端：首页商品、商品详情、下单页可点开。
6. EO 部署后任意深链（`/console/#/services/12`）可直接打开。

## 8. 风险与退路

| 风险 | 退路 |
| --- | --- |
| EO 项目根目录设为 `demo/` 后，`buildCommand` 里的 `cd ..` 可能被限制 | 构建产物改由本地/CI 生成后直传（EO 支持直接上传产物）；或把 `demo/` 换成一个独立仓库 |
| 管理端路由 `base` 硬编码 `/`，子路径部署要改 | 与 `VITE_BASE_URL` 对齐，改动一行；hash 路由再抹掉 deep link 依赖 |
| fixtures 覆盖不全 → 页面空白 | `__DEMO_MISSES__` 清单驱动补齐，验收标准里卡"零空白" |
| 三端 demo 分支长期漂移 | 只有 `installDemoTransport` 一处入口，版本随主仓走，不做独立分支 |
