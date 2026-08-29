---
status: active
updated: 2026-08-24
owner: backend-platform
---

# 开放 API（Open API v2）设计文档

本文定义面向系统间对接与转售场景（魔方 → tura1 → tura2 复合对接）的开放 API 设计，状态为已确认（用户批准，授权自主执行）。实施计划见 [开放 API（Open API v2）实施计划](../../execution-plans/active/open-api-2026-08-24.md)。

## 1. 目标与范围

为 TuraIDC 提供一套自有的机器对机器开放接口，核心用途：

- 其他系统（含其他 TuraIDC 实例）通过 API Key 对接本系统：查询商品、下单购买、管理服务、查询余额
- 形成上游供应商链路，支持无限层级与复合对接（每个实例既可作为上游被对接，也可作为下游对接别人）
- 用户端可自助生成 API Key（类 GitHub secret），配置权限范围（业务域 × 读/写）
- 管理端可对 API Key 生成做全局限制（总开关、前置条件、数量上限），并可查看/禁用任意用户密钥与调用审计

明确不做（一期）：

- Webhook 事件通知（二期，一期用同步返回 + 轮询）
- 密钥代表他人操作（仅本人账户）
- 接口级细粒度权限（业务域 × 读写已足够）
- 工单、发票开具、支付回调等非转售核心接口

## 2. 已确认的关键决策

| 决策点     | 结论                                                    |
| ---------- | ------------------------------------------------------- |
| 核心场景   | 实例间对接 / 转售                                       |
| 接口形态   | 独立 open 网关层 `/api/v2/open/*`，内部复用现有 Service |
| 权限粒度   | 业务域 × 读/写                                          |
| 授权范围   | 密钥仅能操作生成者本人账户                              |
| 状态同步   | 同步返回 + 轮询查询                                     |
| 管理端限制 | 全局配置项（总开关、前置条件、数量上限）                |

## 3. 整体架构

```
请求 → api.key 中间件 → Open 控制器（薄） → 复用现有 Service 层
                                             ├─ CheckoutService       报价/下单/幂等
                                             ├─ ProductCatalogService 商品目录
                                             ├─ ServiceConsoleService 服务列表/详情
                                             ├─ ServicePowerService   电源操作
                                             └─ ServiceRenewService   续费
```

- 新增路由文件 `backend/routes/v2-open.php`，统一前缀 `/api/v2/open`
- 认证：`Authorization: Bearer <api_key>`，中间件解析密钥 → 绑定 User + scope + 校验
- 响应复用现有统一格式 `{code, message, data, timestamp}`
- 错误码延续现有体系：
  - `40100` 密钥不存在/已禁用/已过期/未激活
  - `40300` 权限不足（scope 不含该接口）、IP 不在白名单、账号被禁用
  - `42200` 参数验证失败、业务校验失败（复用 BusinessException）
  - `42900` 限流
- 限流：open 路由统一 `throttle:60,1,open-api`（可配置），关键写接口单独收紧

## 4. 数据模型

### 4.1 `api_keys`

| 字段                    | 类型           | 说明                                                           |
| ----------------------- | -------------- | -------------------------------------------------------------- |
| id                      | bigint PK      |                                                                |
| user_id                 | bigint FK      | 所属用户                                                       |
| name                    | string(64)     | 密钥名称                                                       |
| key_prefix              | string(32)     | 展示前缀，如 `tura_abc123`                                     |
| secret_hash             | string(64)     | SHA-256（加盐）哈希                                            |
| secret_last4            | string(4)      | 展示用后 4 位                                                  |
| scopes                  | json           | 业务域 → read/write，如 `{"services":"write","orders":"read"}` |
| expires_at              | timestamp null | 过期时间，空 = 永不过期                                        |
| ip_allowlist            | json null      | IP 白名单，空 = 不限                                           |
| status                  | string         | `enabled` / `disabled`                                         |
| last_used_at            | timestamp null | 最后调用时间                                                   |
| created_at / updated_at | timestamp      |                                                                |
| deleted_at              | timestamp null | 软删除                                                         |

索引：`user_id`、`key_prefix`（唯一）。

密钥格式：`tura_` + 32 位随机字符。明文仅在创建响应中返回一次，数据库只存哈希。

### 4.2 `api_key_usage_logs`

| 字段        | 类型        | 说明 |
| ----------- | ----------- | ---- |
| id          | bigint PK   |      |
| api_key_id  | bigint FK   |      |
| user_id     | bigint      |      |
| method      | string(8)   |      |
| path        | string(255) |      |
| status_code | int         |      |
| ip          | string(45)  |      |
| duration_ms | int         |      |
| created_at  | timestamp   |      |

索引：`api_key_id`、`created_at`。按需归档（后续可走日志归档体系）。

### 4.3 配置项（`settings` 表，group = `open_api`）

| key               | 类型 | 默认  | 说明                     |
| ----------------- | ---- | ----- | ------------------------ |
| enabled           | bool | false | 全局总开关               |
| require_phone     | bool | false | 创建密钥必须绑定手机号   |
| require_verified  | bool | false | 创建密钥必须通过实名认证 |
| max_keys_per_user | int  | 10    | 每人最大密钥数           |
| rate_limit        | int  | 60    | open API 每分钟请求上限  |

## 5. 权限模型（scope）

业务域枚举（一期）：

- `products` —— 商品目录查询（只读）
- `orders` —— 报价、下单、支付、订单/账单查询
- `services` —— 服务列表/详情、电源操作、续费、重装
- `finance` —— 余额查询

每个域两级：`read` / `write`。scope 字符串形式 `域:级别`，如 `services:write`。

路由声明所需 scope，中间件校验。规则：

- 只读接口要求 `read`；写接口要求 `write`（write 蕴含 read，即拥有 `write` 可调用该域全部接口）
- 密钥的 `scopes` 中未声明的域一律 403

## 6. 接口清单（一期）

### 6.1 商品（products）

| 方法 | 路径                                | scope         | 说明                                                   |
| ---- | ----------------------------------- | ------------- | ------------------------------------------------------ |
| GET  | `/api/v2/open/products`             | products:read | 可售商品列表（含可售套餐/配置项，不含内部成本）        |
| GET  | `/api/v2/open/products/{id}`        | products:read | 商品详情                                               |
| GET  | `/api/v2/open/products/{id}/quotes` | products:read | 报价（billing_cycle + config，返回金额与 quote_token） |

### 6.2 订单（orders）

| 方法 | 路径                           | scope        | 说明                                                                                              |
| ---- | ------------------------------ | ------------ | ------------------------------------------------------------------------------------------------- |
| POST | `/api/v2/open/orders`          | orders:write | 下单（复用 CheckoutService::create，必传 quote_token + idempotency_key + billing_cycle + config） |
| POST | `/api/v2/open/orders/{id}/pay` | orders:write | 余额支付（复用 PaymentService::payByBalance）                                                     |
| GET  | `/api/v2/open/orders`          | orders:read  | 账单列表                                                                                          |
| GET  | `/api/v2/open/orders/{id}`     | orders:read  | 账单详情                                                                                          |

### 6.3 服务（services）

| 方法 | 路径                                   | scope          | 说明                                           |
| ---- | -------------------------------------- | -------------- | ---------------------------------------------- |
| GET  | `/api/v2/open/services`                | services:read  | 服务列表                                       |
| GET  | `/api/v2/open/services/{id}`           | services:read  | 服务详情（含状态）                             |
| POST | `/api/v2/open/services/{id}/power`     | services:write | 电源操作（on/off/reboot/hard_off/hard_reboot） |
| GET  | `/api/v2/open/services/{id}/renewals`  | services:read  | 续费预览（价格与周期）                         |
| POST | `/api/v2/open/services/{id}/renew`     | services:write | 创建续费账单并余额支付                         |
| POST | `/api/v2/open/services/{id}/reinstall` | services:write | 重装系统                                       |

### 6.4 财务（finance）

| 方法 | 路径                   | scope        | 说明     |
| ---- | ---------------------- | ------------ | -------- |
| GET  | `/api/v2/open/balance` | finance:read | 余额查询 |

### 6.5 密钥自身（无需业务 scope）

| 方法 | 路径                             | 说明                                           |
| ---- | -------------------------------- | ---------------------------------------------- |
| GET  | `/api/v2/open/keys/self`         | 查询当前密钥信息（前缀、权限、过期、最后使用） |
| POST | `/api/v2/open/keys/self/disable` | 立即停用当前密钥                               |

说明：密钥 CRUD 主入口在用户控制台（带登录态），open 组只提供 self 查询/停用。

## 7. 下单与支付数据流（转售场景）

```
下游系统                    本系统（上游）
  │  GET products          →  商品列表
  │  GET products/{id}/quotes → 报价 + quote_token
  │  POST orders           →  CheckoutService::create(user, {quote_token, billing_cycle,
  │                            config, idempotency_key}) → Invoice(待支付) + shadow Order
  │  POST orders/{id}/pay  →  PaymentService::payByBalance → Invoice 已支付 → 异步开通
  │  GET services          →  轮询服务状态
```

- 下游需先保证密钥账户余额充足（充值走用户控制台，或二期提供充值接口）
- 幂等：`idempotency_key` 由下游生成并保存，重试不会重复建单（复用 CheckoutService 现有幂等机制）
- 价格：API 返回真实应付金额（含代理折扣等既有定价逻辑），加价/映射由下游自行决定

## 8. 用户端「API 密钥」页（frontend-user-v4-console）

- 路由：`/client/api-keys`，注册侧栏菜单
- 密钥列表：名称、前缀+last4、权限标签、状态、过期时间、最后使用、操作（禁用/启用/删除/使用日志）
- 创建弹窗：名称 + 业务域×读写勾选矩阵 + 可选过期时间 + 可选 IP 白名单
- 创建成功：高亮展示 secret 一次（复制按钮），提示"仅显示一次，请妥善保存"
- 使用日志抽屉：最近调用记录（时间、接口、IP、状态）
- 创建前置条件校验：总开关关闭 / 未绑手机 / 未实名时给出引导文案与跳转链接

用户端密钥 CRUD API（`/api/v2/client/api-keys*`）：

- GET 列表、POST 创建（返回 secret 一次）、PUT 更新（名称/权限/过期/IP）、POST 禁用/启用、DELETE 删除、GET 使用日志

## 9. 管理端（frontend-admin-v3）

### 9.1 「开放接口」设置页

- 入口：系统设置下新增「开放接口」配置页（复用现有 settings 页模式，settingsTab = `open_api`）
- 配置项：总开关、前置条件（手机/实名）、每人数量上限、限流

### 9.2 「API 密钥管理」页

- 查看全部用户密钥：用户、密钥前缀、权限、状态、过期、最后使用
- 操作：禁用/启用、删除
- 查看指定密钥的调用审计

### 9.3 权限点

- 新增权限：`open_api.view`（查看）、`open_api.manage`（配置与密钥管理）
- 同步 AdminPermissions 常量、impliedPermissions、PermissionCatalog 目录

## 10. 实施分期

- 阶段 1：迁移（api_keys、api_key_usage_logs）+ ApiKey 模型/Service + `api.key` 中间件 + 配置读取 + 用户端密钥 CRUD API
- 阶段 2：open 网关路由 + 控制器（products/orders/services/finance）+ scope 声明与校验 + 复用现有 Service
- 阶段 3：用户端「API 密钥」页面（列表/创建/日志/禁用删除）
- 阶段 4：管理端设置页 + 密钥管理页 + 权限点
- 阶段 5：后端 Feature 测试 + 前端构建验证 + 提交推送

## 11. 测试策略

后端 Feature 测试：

- `ApiKeyTest`：创建（明文仅一次/哈希入库/前缀唯一）、认证（错误密钥/禁用/过期/IP 白名单）、权限（scope 校验、write 蕴含 read）、CRUD、数量上限、前置条件
- `OpenApiFlowTest`：端到端（报价 → 下单 → 余额支付 → 服务查询）、幂等重试、余额不足
- `OpenApiScopeTest`：各接口 scope 矩阵

前端：vue-tsc 类型检查 + vite build。

## 12. 风险与对策

| 风险       | 对策                                                  |
| ---------- | ----------------------------------------------------- |
| 密钥泄露   | 仅显示一次、哈希存储、IP 白名单、可即时禁用、审计日志 |
| 恶意刷单   | 幂等键防重、限流、余额前置                            |
| 接口滥用   | open 组独立限流、管理端总开关一键关闭                 |
| 审计表膨胀 | 仅记录必要字段，后续接入日志归档                      |
