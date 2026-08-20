# 前端项目规范

- 文档性质：当前规范
- 对齐时间：`2026-07-02`
- 读者画像：维护当前三个前端与 `shared` 的开发者和代理

## 1. 当前前端项目

- `frontend-admin-v3`：管理端，Vue 3 + Vite + TypeScript + TDesign Vue Next。
- `frontend-user-v3-www`：官网与用户入口，Vue 3 + Vite + Element Plus。
- `frontend-user-v4-console`：新版用户控制台，Vue 3 + Vite + TypeScript + TDesign Vue Next。
- `shared`：跨端共享包，导出状态、runtime、content 和用户控制台基础组件。

旧路径 `frontend-admin`、`frontend-client`、`frontend-user-v3-console`、`frontend-www-v2`、`frontend-console-v2` 当前不存在，不要新增引用。

## 2. 通用规则

- 使用 Vue 3 `script setup` + Composition API。
- HTTP 请求走各端现有 `src/api/*` 与 request/runtime 工具，不直接创建 axios 实例。
- Token、session、站点信息走现有 auth/runtime/store，不直接读写 localStorage 键。
- 复杂页面逻辑下沉到同目录 `composables`、`domains`、`features`、`services` 或 `utils`。
- 用户可见文案使用简体中文，错误提示优先读取后端 `message` 和 `data.errors`，不要展示英文异常或第三方原始错误。
- 状态展示先复用 `@turaidc/shared`。

## 3. `frontend-admin-v3`

目录约定：

- `src/main.ts`：应用入口。
- `src/permission.ts`：路由守卫。
- `src/router/modules/`：按业务域拆分路由。
- `src/store/modules/`：Pinia 状态。
- `src/pages/`：页面，按业务域组织。
- `src/api/`：管理端 API 封装。
- `src/style/`：Less 样式与主题变量。
- `src/layouts/`：管理端布局。

实现约束：

- UI 只用 TDesign Vue Next。
- 图标只用 `tdesign-icons-vue-next`。
- 权限码与后端 `App\Support\AdminPermissions` 对齐。
- 页面不新增 Element Plus 组件、样式或图标。
- 管理列表页不做说明型页头大卡片，优先筛选区、指标区、工具栏、表格卡片。
- 管理端页面禁止新增独立“头部说明卡片”。

验证：

```bash
cd frontend-admin-v3
npm run build
```

## 4. `frontend-user-v3-www`

目录约定：

- `src/main.js`：应用入口。
- `src/app/`：bootstrap、router、runtime、stores 等核心能力。
- `src/api/`：站点与用户 API。
- `src/pages/website/`：官网页面路由入口；实现主体在 `src/views/website/`，`pages` 下多为转发壳。
- `src/pages/common/`：通用页面（当前为 `NotFound.vue`）。
- `src/views/website/`：官网页面实现主体（首页、产品、产品详情、内容、SEO 落地页）。
- `src/domains/`：领域逻辑（当前仅 `products`）。
- `src/assets/styles/`：Sass token、全局样式、Element Plus 样式入口。

实现约束：

- UI 使用 Element Plus 和 `@element-plus/icons-vue`。
- 本应用是纯官网门户，不含登录注册与用户中心；认证与控制台页面在 `frontend-user-v4-console`。
- 官网首页与产品页可以有更强视觉表现。
- 购买、结算、优惠券、恢复下单优先复用 `src/domains/products/*` 与现有 composables。
- SEO、sitemap、prerender 逻辑放脚本和构建流程，不在页面里硬拼。

验证：

```bash
cd frontend-user-v3-www
npm run build
npm run verify:refactor
```

只做小改时至少执行 `npm run build`；涉及重构或共享逻辑时追加 `verify:refactor`。

## 5. `frontend-user-v4-console`

目录约定：

- `src/main.ts`：应用入口。
- `src/permission.ts`：路由守卫。
- `src/router/`：控制台路由。
- `src/store/`：Pinia 状态。
- `src/pages/client/`：控制台页面。
- `src/domains/`：账户、财务、服务、工单、内容、营销、工具等领域逻辑。
- `src/composables/`：控制台组合逻辑。
- `src/api/`：用户端 API 封装。
- `src/style/`：Less 样式和 TDesign token。

实现约束：

- UI 只用 TDesign Vue Next。
- 图标只用 `tdesign-icons-vue-next`。
- 优先复用 `shared/user-v3` 的 `PageScaffold`、`DataState`、`StatusTag`、弹窗/抽屉/布局组件。
- 控制台页面优先稳定信息架构，不做官网式 Hero、深色大屏或装饰优先布局。
- `/client/*` 控制台业务能力与后端 `/api/v2/client/*` 对齐。
- 财务记录页面（账单、订单、充值的列表和详情页）禁止使用统计/指标卡片。
- 页面根元素使用 `padding: var(--td-comp-paddingTB-l) var(--td-comp-paddingLR-l)`；手机端所有页面 padding 统一为 12px，禁止使用 `paddingLR-s` 或 `paddingTB-m`。

验证：

```bash
cd frontend-user-v4-console
npm run build
npm run verify:refactor
```

只做小改时至少执行 `npm run build`；涉及重构或共享逻辑时追加 `verify:refactor`。

## 6. `shared`

主要导出：

- `@turaidc/shared/status`
- `@turaidc/shared/runtime`
- `@turaidc/shared/content`
- `@turaidc/shared/components/StatusTag.vue`
- `shared/user-v3`

规则：

- 状态文案、颜色和标签不要在页面里另写一套。
- runtime 能力优先从 `shared/runtime` 或各端已有 runtime 封装接入。
- 修改 `shared` 后至少执行：

```bash
npm run typecheck:shared
npm run test:shared
```

并按影响范围执行对应前端 build。

## 7. 根 workspace 命令

```bash
npm run dev:admin-v3
npm run dev:user-v3-www
npm run dev:user-v4-console

npm run build:frontends
npm run typecheck:frontends
npm run test:frontends
npm run verify:frontends
```

根 `package.json` 只保留当前真实 workspace。新增前端项目时，必须同步更新 `package.json`、`package-lock.json`、`AGENTS.md`、`docs/references/operations/local-development.md` 和本规范。
