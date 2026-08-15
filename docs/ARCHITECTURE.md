# 架构现状说明

- 文档性质：当前真源
- 对齐时间：`2026-07-22`
- 读者画像：新入项开发者、代理、后端/前端/运维协作者

## 1. 仓库结构

```text
turaidc/
├── backend/                  Laravel 12 后端
├── frontend-admin-v3/        当前管理端，Vue 3 + TDesign
├── frontend-user-v3-www/     官网与用户入口，Vue 3 + Element Plus
├── frontend-user-v4-console/ 新版用户控制台，Vue 3 + TDesign
├── shared/                   跨前端共享包
└── docs/                     项目长期开发文档
```

当前不存在 `frontend-admin`、`frontend-client`、`frontend-user-v3-console`、`frontend-www-v2`、`frontend-console-v2`。文档或脚本中若出现这些路径，先按历史引用处理并核对真实目录。

## 2. 后端架构

技术基线：

- PHP 8.2+
- Laravel 12
- Sanctum 4 Token 鉴权
- MySQL 8
- Redis 缓存
- `database` 队列驱动
- 本地统一入口：`php artisan app:serve`

主要目录：

- `app/Http/Controllers/Admin/V2/`：管理端 API 控制器。
- `app/Http/Controllers/Client/V2/`：用户端 API 控制器。
- `app/Http/Controllers/`：公共站点 API 与基类，如 `SiteHomeController`、`SiteProductController`、`SecureAssetController`。
- `app/Http/Requests/Admin/V2|Client/V2/`：管理端和用户端 FormRequest。
- `app/Http/Resources/Admin/V2|Client/V2/`：管理端和用户端 API Resource；其余 Resource 按公共业务域组织。
- `app/Services/`：业务主层，已按 `Finance`、`Order`、`ProductCatalog`、`ClientServiceConsole`、`Provisioning`、`Upstream`、`Integrations`、`Notification`、`Ticket` 等领域收敛。
- `app/Services/Integrations/Plugins/`：插件扫描、安装、配置、运行时注册和能力适配。
- `plugins/`：支付、实名、短信、邮件、上游服务器等插件实现目录。
- `plugins/servers/zjmf_finance/`：承载 ZJMF 财务数据面对接，以及旧密码兼容、账单恢复等 ZJMF 专属能力；兼容实现位于其 `lib/`，由插件 Provider 注册。
- `app/Constants/`：状态、枚举、业务常量。
- `app/Console/Commands/`：Artisan 命令，包括迁移、同步、VNC Relay、`app:serve`。

路由分工：

- `routes/api.php`：公开站点 V2 API，主要是 `/api/v2/site/*` 和 `/api/health`。
- `routes/v2-admin.php`：管理端 V2 API，走 `auth:sanctum` + `ensure.admin` + `permission:{code}`。
- `routes/v2-client.php`：用户端 V2 API，走 `auth:sanctum` + `ensure.client`，支付/实名/VNC token 等回调或公开入口有独立签名/限流。
- `routes/console.php`：调度任务。
- `routes/web.php`：非 API 入口与静态兼容。

API 直接重构路由口径：

- 旧接口 `/api/admin/*`、`/api/client/*`、`/api/site/*` 已删除，不再注册或保留兼容层。
- 当前业务接口统一使用 `/api/v2/admin/*`、`/api/v2/client/*`、`/api/v2/site/*` 命名空间。
- v2 路由仍复用现有鉴权、权限码、业务 Service、插件边界和统一响应外层；变化集中在 FormRequest、Resource/Response DTO、字段投影、分页和子资源拆分。
- 具体任务、接口拆分和验收标准见 `docs/设计文档/后端/API直接重构方案.md`。

响应规范：

- 成功固定 `code = 0`。
- JSON 外层由 `App\Traits\ApiResponse` / `App\Support\ApiResponseBuilder` 生成。
- 分页结构放在 `data` 内：`list`、`total`、`page`、`page_size`。
- 校验失败统一 `42200`，字段错误在 `data.errors`。
- v2 新接口禁止继承旧 `per_page`、列表详情混用、宽 Resource 和深层聚合响应；默认 JSON 响应应控制在 `100KB` 以内。

关键业务约束：

- Controller 不直接写复杂业务，不直接调用第三方 `Http::*`。
- 第三方和上游调用走插件目录、`Services/Integrations/Plugins`、`Services/Upstream` 等专用层。
- `zjmf_finance_api` 是独立 provider key，禁止归一化为 `hosting_panel_api`。
- `payments` 表只记录真实第三方资金流入，不记录余额支付、手动开服、免费订单；禁止物理删除 Payment。
- 财务、订单、余额、返佣、开通、回调必须考虑事务、幂等和审计字段。

第三方插件化现状：

- 插件元数据和启用配置由 `integration_plugins`、`integration_plugin_configs` 承载。
- 插件按能力域放在 `backend/plugins/{domain}/{slug}/`，当前包含 `addons`、`captcha`、`certification`、`gateways`、`mail`、`servers`、`sms` 等域。
- 插件配置中的敏感字段进入加密存储，前端只展示是否已配置和脱敏预览。
- 支付、实名、短信、邮件、上游服务器能力由平台内部接口/manager 调用，不允许业务 Controller 直接依赖插件实现类。
- ZJMF 财务上游保持独立 `zjmf_finance_api` provider key，不得折叠为 `hosting_panel_api`。
- ZJMF 财务 adapter 必须显式声明平台可调用方法，不得恢复 `__call()` 动态转发。

订单、账单、充值记录职责边界：

- `orders` 是购买契约，承载用户买了什么产品、配置、周期、数量和配置定价快照；`config_snapshot`、`config_pricing_snapshot` 只在订单详情展示。
- `invoices` 是结算凭证，承载应付、实付、账单状态、账单行项目和支付明细；账单详情不展示产品配置快照。
- `payments` 是第三方资金流入记录，只记录支付宝等外部渠道真实入账；余额支付、管理员手动开服、免费订单不产生 Payment。
- 三类记录通过 ID 互联，详情页只保留必要跳转或追溯引用，不内联不属于自身职责的冗余字段。
- 订单、账单、支付状态枚举统一来自 `shared/statusConfig.js`，前端状态展示优先复用共享状态能力。

## 3. 前端架构

### 3.1 `frontend-admin-v3`

- 定位：当前管理端。
- 技术：Vue 3、Vite、TypeScript、TDesign Vue Next、TDesign Icons Vue Next、Pinia、Less。
- 入口：`src/main.ts`、`src/permission.ts`、`src/router/`、`src/store/`。
- 页面：`src/pages/`，按业务域组织。
- API：`src/api/`。
- 样式：`src/style/`，沿用 TDesign/TDesign Starter token。
- 验证：`cd frontend-admin-v3 && npm run build`。

### 3.2 `frontend-user-v3-www`

- 定位：官网、登录注册、用户入口与部分用户中心页面。
- 技术：Vue 3、Vite、Element Plus、Pinia、Sass。
- 入口：`src/main.js`、`src/app/`、`src/router/`。
- 页面：`src/pages/website`、`src/pages/client`，并保留部分 `src/views` 兼容结构。
- 领域逻辑：`src/domains/`、`src/composables/`、`src/features/`。
- 样式：`src/assets/styles/`。
- 构建附带 sitemap/prerender。
- 验证：`cd frontend-user-v3-www && npm run build`，重构时追加 `npm run verify:refactor`。

### 3.3 `frontend-user-v4-console`

- 定位：新版用户控制台。
- 技术：Vue 3、Vite、TypeScript、TDesign Vue Next、TDesign Icons Vue Next、Pinia、Less。
- 入口：`src/main.ts`、`src/permission.ts`、`src/router/`、`src/store/`。
- 页面：`src/pages/client/`。
- 领域逻辑：`src/domains/`、`src/composables/`、`src/api/`。
- 共享组件：优先复用 `shared/user-v3` 与 `@turaidc/shared`。
- 验证：`cd frontend-user-v4-console && npm run build`，重构时追加 `npm run verify:refactor`。

### 3.4 `shared`

跨端共享包，当前承载：

- `status`：状态映射、状态标签。
- `runtime`：session、network、branding、route loading、HTTP runtime。
- `content`：Markdown/HTML 安全渲染。
- `user-v3`：用户控制台通用 TDesign 风格组件。

涉及状态文案、状态颜色、状态标签、控制台基础组件时，先查 `shared`。

## 4. 本地启动

```bash
cd backend
php artisan app:serve

npm run dev:admin-v3
npm run dev:user-v3-www
npm run dev:user-v4-console
```

不要使用 `php artisan serve` 替代 `app:serve`。

## 5. 生产运行

- 后端生产入口：PHP-FPM 指向 `backend/public`。
- 业务队列与定时队列：由宝塔每分钟 `php artisan schedule:run` 并行启动两个独立 `queue:work`，分别消费 `provision,referral,notification,coupon,default` 与 `automation`；两类 Worker 使用独立互斥锁，任一队列的长任务不阻塞另一队列的下一轮消费。
- VNC Relay：独立运行 `php artisan vnc:relay`，由宝塔守护或进程管理器自动重启，不由心跳任务拉起。
- 异步任务可能有 0-60 秒延迟。
- 前端分别构建后按站点静态发布：
  - `frontend-admin-v3/dist`
  - `frontend-user-v3-www/dist`
  - `frontend-user-v4-console/dist`

## 6. 数据库口径

- 表名和字段名使用 `snake_case`。
- 迁移只新增文件，不改历史迁移。
- 仓库存在历史激进迁移，禁止补跑。
- 真实表结构以实库 `information_schema` 为准；文档以 `docs/DATABASE.md` 为摘要。

## 7. 文档与历史资产口径

- `docs/` 是长期开发、联调、部署和维护的记录系统。
- 阶段执行记录、审查快照、一次性审计报告和计划按 `docs/` 的分类维护；仍有效的规则应沉淀到对应真源。
- 历史旧系统解包目录、前端 zip 导入包、根目录 SQL dump、旧构建产物属于迁移/导入临时资产，确认无使用后应清理或移出仓库。
