---
status: active
updated: 2026-11-01
owner: backend + frontend
---

# 自助退款插件（auto_refund）

## 背景

站内目前只有管理端能退款：`UserService::refundService()` / `PaymentService::refundOrder()` 由管理员在「用户 → 服务 → 退款」里手工发起。用户想退机器只能在工单里提，运营每次都要人工核对上游、人工计算金额、人工点退款。

上游是智简魔方财务，它有一个开源的产品退款插件 `AutoRefund`（`plugins/addons/auto_refund`），既能在上游站点内部完成退款，也对外暴露一组 `api.php` 接口供下游站点串联退款。上游已把这套 API 的接入契约定死（`action=query|submit|cancel`、`api_key` + `timestamp` 五分钟窗口、JSON `code` 判定、无进度查询、`cancel` 非幂等）。

本计划的目标：在 TuraIDC 里做一个 `addons/auto_refund` 插件 + 控制台自助退款页 + 管理端审核台账，让用户自己在控制台申请退款，插件按上游契约把申请提交给上游，再把「上游已退款」这件事落回本站账务（退款到余额）。

**目标**

- 用户在控制台看到自己「可申请退款」的机器，自己填原因提交，无需开单。
- 提交走上游 `submit`，上游审核通过前用户可自行 `cancel`。
- 每一次申请在本地有台账：谁、哪台、什么时候、上游 code / trace_id、本地是否已结算。
- 本地结算复用既有账务链路（`UserService::refundService` → `PaymentService::refundOrder`），不新造余额加减逻辑。
- 管理端能审核、能结算、能作废，并能验证上游连通性。

**非目标**

- 不做上游「自动退款」规则的本地复刻：真正退多少钱由上游按其退款规则算，本站只给预估。
- 不提供退款进度查询（上游没有这个接口），进度靠本地台账 + 管理员确认。
- 不动上游的开源插件代码，不把它搬进本仓库。
- 不新增插件自建运行日志表：调用留痕写专用日志通道，结果摘要写申请行。

## 上游契约（必须逐条遵守）

来源：智简魔方财务 AutoRefund 商业版《API 开发接入指南》。

- 端点形如 `POST {base_url}`，默认 `https://www.zixiyun.cn/plugins/addons/auto_refund/api.php`。
- 请求体必须是 `application/x-www-form-urlencoded`，**不接受 JSON body**；除旧版兼容外所有参数放表单。
- 必填参数：`api_key`（完整密钥，页面掩码值无效）、`timestamp`（Unix 秒，与服务端误差 ≤ 300 秒）、`action`（`query` / `submit` / `cancel`，新接入必须显式传）。
- `submit` / `cancel` 必填 `upstream_product_id`，值来自 `query` 返回的 `data[].hostid`。
- 响应是 JSON，业务结果只看 `code`：`200` 成功、`400` 参数/时间戳/业务条件不满足、`401` 密钥无效、`403` 插件授权无效、`404` 产品不存在、`405` 非 POST、`500` 服务端失败。多数失败仍是 HTTP 200，**禁止用 HTTP 状态码判断业务结果**，也禁止把 `msg` 当稳定枚举。
- `trace_id` 只有 `submit` 成功与未知 action 保证返回，其它响应可能没有。
- `submit` 成功只代表「申请已创建」，可能是待审核也可能是自动审批，**不代表资金到账**。
- 无进度查询接口；`cancel` 只能取消该产品实例最新一条仍待审核的申请，第二次调用返回 `400`（非幂等）。
- 网络超时属于「结果未知」：重试前必须先记录目标产品与首次调用结果，避免重复创建申请。
- API KEY 绑定上游客户账号（本站对应一个上游代理账号），`query` 只返回该账号名下、未隐藏、在展示范围且配置了退款规则的机器。

## 本地设计

### 目录与命名

```text
backend/plugins/addons/auto_refund/
├── AutoRefundPlugin.php            # 入口类，execute() 只做状态自检
├── config.php                      # 元信息 + 配置 schema
├── README.md
├── logic/autoload.php              # 只注册本插件命名空间
├── routes/client.php               # /api/v2/client/auto-refund/*
├── routes/admin.php                # /api/v2/admin/auto-refund/*
├── src/Providers/AutoRefundServiceProvider.php
├── src/Models/AutoRefundApplication.php
├── src/Services/AutoRefundApiClient.php   # 上游 HTTP + 契约校验
├── src/Services/AutoRefundSettings.php    # 读插件配置
├── src/Services/AutoRefundPolicy.php      # 本地可退资格 + 预估金额
├── src/Services/AutoRefundService.php     # 申请/取消/结算编排
├── src/Http/Controllers/Client/AutoRefundController.php
├── src/Http/Controllers/Admin/AutoRefundAdminController.php
├── src/Http/Requests/...                  # 表单校验
└── tests/Feature/*.php
```

### 数据表 `auto_refund_applications`

建表落在**核心迁移** `backend/database/migrations/2026_11_01_000001_create_auto_refund_applications_table.php`，插件目录不保留 `database/`——插件不能自注册迁移（插件标准 v2 §3），与 `addons/ai_assistant` 同口径。MySQL 5.7 兼容，只新增、不删列，显式 InnoDB。

| 列                                                   | 类型                   | 说明                                                                                           |
| ---------------------------------------------------- | ---------------------- | ---------------------------------------------------------------------------------------------- |
| `id`                                                 | bigint PK              |                                                                                                |
| `application_no`                                     | varchar(32) unique     | 形如 `AR20261101A1B2`，便于工单引用                                                            |
| `user_id` / `service_id` / `order_id` / `invoice_id` | bigint                 | 本地对象，前两个建索引                                                                         |
| `upstream_product_id`                                | varchar(120)           | 上游 `hostid`                                                                                  |
| `status`                                             | varchar(20)            | 见状态机                                                                                       |
| `active_service_id`                                  | bigint nullable unique | **幂等闸门**：待审核/结果未知时等于 `service_id`，收口后置 NULL（MySQL 唯一索引允许多个 NULL） |
| `reason`                                             | varchar(500)           | 用户填写的退款原因                                                                             |
| `estimated_amount`                                   | decimal(12,2)          | 提交时的本地预估                                                                               |
| `settled_amount`                                     | decimal(12,2)          | 实际结算金额                                                                                   |
| `upstream_code`                                      | int nullable           | 最近一次上游 `code`                                                                            |
| `upstream_msg`                                       | varchar(255) nullable  | 上游 `msg` 原文（仅供人看）                                                                    |
| `trace_id`                                           | varchar(64) nullable   | 上游调用链标识                                                                                 |
| `response_meta`                                      | json nullable          | 脱敏后的请求/响应摘要                                                                          |
| `submitted_at` / `cancelled_at` / `settled_at`       | timestamp nullable     |                                                                                                |
| `operator_id`                                        | bigint nullable        | 结算/作废的管理员                                                                              |
| `settle_remark`                                      | varchar(255) nullable  |                                                                                                |
| `created_at` / `updated_at`                          | timestamp              |                                                                                                |

### 状态机

```text
(提交)
  created --submit 200--> pending_review --cancel 200--> cancelled
                          pending_review --管理员结算--> settled
                          pending_review --管理员驳回--> rejected
  created --submit 非200--> failed            （凭据/参数/业务拒绝）
  created --网络超时------> uncertain         （结果未知，占住幂等闸门）
  uncertain/pending_review --管理员作废--> closed
```

- `pending_review` 是唯一允许用户 `cancel` 的状态。
- `uncertain` 必须保留 `active_service_id`，否则用户重提会造出第二笔上游申请。
- `settled` 必须已经过本地账务结算，两者在同一次事务语义内完成；账务失败则状态留在 `pending_review` 并回报错误。

### 客户端 API（`/api/v2/client/auto-refund`）

响应统一用平台 `success()/error()` 包装（`code:0` 成功）。

| 方法 | 路径                        | 说明                                                     |
| ---- | --------------------------- | -------------------------------------------------------- |
| GET  | `/bootstrap`                | 开关、规则文案、可退窗口、是否已配置上游；**不请求上游** |
| GET  | `/candidates`               | 可申请列表：本地服务 ∩ 上游 `query` 的 hostid 集合       |
| POST | `/applications`             | `{service_id, reason}` → 上游 `submit`                   |
| POST | `/applications/{id}/cancel` | 上游 `cancel`                                            |
| GET  | `/applications`             | 我的申请列表（分页、按状态筛选）                         |

`/candidates` 每一项：

```json
{
  "service_id": 12,
  "service_name": "香港-CN2-01",
  "product_name": "香港 CN2",
  "domain": "hk01.example.com",
  "dedicatedip": "192.0.2.10",
  "assignedips": "",
  "regdate": "2026-10-20 10:00:00",
  "nextduedate": "2026-11-20 10:00:00",
  "estimated_amount": "45.20",
  "estimate_hint": "按已用 12 天 / 30 天折算",
  "refundable": true,
  "blocked_reasons": [],
  "application": null
}
```

`blocked_reasons` 用枚举码（前端本地化）：`upstream_missing`（上游列表里没有这台）、`no_paid_invoice`、`window_expired`、`first_only_used`、`pending_application`、`amount_too_low`、`upstream_unavailable`。

`/candidates` 上游不可用时**不报错**：返回本地候选并带 `upstream_error` 字符串，前端把整页置为「上游暂不可用，稍后再试」。

### 管理端 API（`/api/v2/admin/auto-refund`）

| 方法 | 路径                        | 说明                                           |
| ---- | --------------------------- | ---------------------------------------------- |
| GET  | `/applications`             | 台账列表：状态、用户、服务、时间范围、关键字   |
| GET  | `/applications/{id}`        | 详情（含上游原始摘要）                         |
| POST | `/applications/{id}/settle` | `{amount, remark}` → 本地退款到余额            |
| POST | `/applications/{id}/reject` | `{remark}` → 标记上游驳回                      |
| POST | `/applications/{id}/close`  | `{remark}` → 强制作废（卡死/结果未知）         |
| POST | `/upstream/test`            | 用当前配置调 `query`，回报机器数与服务端时间差 |
| GET  | `/upstream/products`        | 上游全量机器 + 本地绑定情况（对账）            |

权限沿用管理端控制器既有约定（与 `addons/ai_assistant` 的 `routes/admin.php` 同款鉴权中间件与权限码命名）。

### 配置项（`config.php` schema）

`enabled` / `api_base_url`（url，必填）/ `api_key`（password+secret，必填）/ `request_timeout`（number，默认 15）/ `console_notice`（textarea）/ `policy_mode`（select：`full` 全额、`duration` 按时长折算、`month` 按月折算）/ `policy_days`（number，默认 7，0 表示不限）/ `require_first_only`（switch）/ `min_amount`（number）/ `auto_settle`（switch，默认关）/ `settle_remark`（text）/ `upstream_precheck`（switch，默认开：提交前先 `query` 确认 hostid 在列）。

> 第二阶段已把 `policy_mode` / `policy_days` / `require_first_only` / `min_amount` / `auto_settle` 五项**删除**，改为按商品配置（见下文「第二阶段」）；`api_base_url` / `api_key` 也由必填改为可选。以「第二阶段」与实际 `config.php` 为准，本段保留作为第一阶段的历史记录。

### 前端

- 控制台 `frontend-user-v4-console`：`/client/refunds`「退款中心」，说明卡（规则文案）+ 可退机器列表 + 我的申请列表 + 提交/取消确认弹窗。**不加指标卡片**（v4-console 财务页既有口径）。
- 管理端 `frontend-admin-v3`：`/admin/auto-refund/applications` 台账页 + 详情抽屉 + 结算/驳回/作废 + 「测试上游连接」；插件配置仍走既有 schema 驱动配置页，不新建配置界面。
- 两端入口都用 `requiresFeature`：`OptionalFeature::map()` 增加 `auto_refund`，后端登录/取用户信息接口随之下发。

## 第二阶段：完整复刻上游 AutoRefund

第一阶段只做了「插件间对接」一条通道。第二阶段把上游 `Wu-jiyan/idcsmart-plugin-auto-refund`（v1.3.0）的整套语义搬过来：四种退款类型、三种退款规则、三种退款要求，全部**按商品**配置。

### 语义对照（上游取值 → 本站取值）

| 维度     | 上游 `product_refund` 字段   | 上游取值                                                | 本站取值                                                |
| -------- | ---------------------------- | ------------------------------------------------------- | ------------------------------------------------------- |
| 退款类型 | `type`                       | 1 人工审核 / 2 自动退款 / 3 API 工单退款 / 4 插件间对接 | `manual` / `auto` / `api_ticket` / `bridge`             |
| 退款规则 | `rules`                      | 1 按时长 / 2 按月 / 3 全额                              | `duration` / `month` / `full`                           |
| 退款要求 | `request`                    | 1 产品首次 / 2 同类产品首次 / 3 指定时间内              | `product_first` / `category_first` / `within_hours`     |
| 时间窗口 | `within`                     | 小时                                                    | `window_hours`（小时，仅 `within_hours` 生效）          |
| 申请状态 | `product_refund_list.status` | 1 待审核 / 2 已退款 / 3 已驳回 / 4 用户取消             | `pending_review` / `settled` / `rejected` / `cancelled` |

### 金额公式（照抄上游 `calcRefund()`，不要自己发明口径）

```text
周期秒数   = nextduedate - regdate
每秒单价   = firstpaymentamount / 周期秒数
已用秒数   = now - regdate

按时长:  金额 = firstpaymentamount - 每秒单价 * 已用秒数
按月:    月单价 = 每秒单价 * 2592000
         已用月数 = ceil(已用秒数 / 2592000)
         金额 = firstpaymentamount - 月单价 * 已用月数
全额:    金额 = firstpaymentamount
统一 round(金额, 2)；结果 <= 0 直接拒绝申请（上游同样拒）。
```

- 主机处于 `Pending`（未开通完成）时上游**强制按全额**算，不看商品配置的规则。
- 本站字段映射要求：把上游 `regdate` / `nextduedate` / `firstpaymentamount` 对应到本站的服务开通时间、到期时间、首付实付金额，映射关系必须写进代码注释——这三项算错就是退错钱。
- 本站**只把这个金额当预估展示**：商业版 `api.php` 契约明确「调用方不能指定金额」，所以 `bridge` 通道一律不传 amount，实退金额以上游与管理员确认为准。

### 前置校验（上游 `check()` 逐条搬，顺序也照搬）

1. 商品没有退款策略 → 「当前商品不支持退款」。
2. 首购账单未支付 → 拒绝。
3. 同一主机已有申请且状态不在「已取消」→ 重复申请拦截。
4. 非 Pending 且已过期 → 拒绝。
5. 服务状态为已删除 → 拒绝。
6. 服务状态既不是运行中也不是 Pending → 拒绝。
7. 该主机存在续费账单（`invoice_items.type = renew`）→ 「已续费的商品不支持自助申请退款」。
8. 首付金额为 `0.00`（免费）→ 拒绝。
9. 该主机存在升降级账单（`invoice_items.type = upgrade`）→ 拒绝。

### 四种类型的分流

| 类型                      | 提交时做什么                                               | 本地状态                             | 结算                     |
| ------------------------- | ---------------------------------------------------------- | ------------------------------------ | ------------------------ |
| `manual` 人工审核         | 只落台账，不外发                                           | `pending_review`                     | 管理员在台账点「结算」   |
| `auto` 自动退款           | 落台账后立刻走既有退款链路入账                             | 直接 `settled`                       | 无需人工                 |
| `api_ticket` API 工单退款 | 建一张本站工单（部门=财务），再投递到上游 `/ticket/create` | `pending_review`                     | 上游回复确认后管理员结算 |
| `bridge` 插件间对接       | 调上游 `api.php` 的 `submit`                               | `pending_review`（超时 `uncertain`） | 管理员核对后结算         |

### 工单通道（`api_ticket`）的设计口径

上游原生做法（开源版 `controller/clientarea/IndexController.php` type=3 + `lib/ApiClient.php`）：

1. `POST {上游}/v1/login_api`，参数 `username` + `api_key`；JWT 在响应**根级** `jwt` 字段。
2. `POST {上游}/submitticket?step=2&dptid={部门}`，multipart 表单 + `Authorization: Bearer {jwt}`，字段含 `title` / `content` / `host_id`（上游 dcimid）。
3. 成功判据是 **302 且 Location 含 `supporttickets`**（不是 200 JSON）。

所以本站要支持两种上游工单协议，由配置 `ticket_protocol` 选择：

| 协议           | 适用上游                                  | 实现                                                                                                                                            |
| -------------- | ----------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------- |
| `zjfm`（默认） | 智简魔方财务真上游（zixiyun 这类）        | 插件自建 `ZjfmTicketApiClient`：`login_api` → `submitticket`，按 302/`supporttickets` 判成功，同时兼容返回 JSON `code` 的变体；每次调用脱敏留痕 |
| `tura`         | 上游也是 TuraIDC（暴露 `/ticket/create`） | 复用既有 `TicketDeliveryService`：建本站工单 → 投递 → 回写 `upstream_ticket_id`                                                                 |

共同点：

- 建一张**本站工单**（部门 `billing`）承载沟通记录，标题/内容模板支持 `{product_name}` `{host_id}` `{amount}` `{reason}` 占位，模板在插件配置里可改。
- 申请单记 `ticket_id`；`zjfm` 协议拿不到上游工单号（302 只给列表页），`upstream_ticket_id` 允许为空并在详情里说明「上游未返回工单号」。
- 上游部门 ID（`dptid`）由插件配置 `ticket_department_id` 提供；`tura` 协议缺省时回退到既有 `ticket_delivery_rules` 匹配。两者都没有就报错「未配置上游工单部门」，不允许猜部门发单。
- 工单回复回流后**不自动结算**：上游说「已退款」和「钱到了本站账上」是两件事，仍由管理员在台账点结算。

### 第二阶段接口增量（三个端共用的唯一真相）

**管理端 `api/v2/admin/auto-refund`**（新增 2 条，权限：查询走 `invoice.list`，写入走 `invoice.manage`）

| 方法 | 路径                       | 说明                                                                                                                                                   |
| ---- | -------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------ |
| GET  | `/product-rules`           | 商品策略列表：商品 id/名称/分组、策略（类型/规则/要求/窗口/启用）、是否有策略；支持 `keyword`、`status`（`all` / `configured` / `unconfigured`）、分页 |
| PUT  | `/product-rules/{product}` | `{refund_type, refund_rule, requirement, window_hours, enabled}` 保存单个商品策略；`enabled=false` 或不建行＝该商品不参与自助退款                      |

台账列表/详情响应新增字段：`refund_type`、`refund_type_label`、`channel`、`channel_label`、`ticket_id`、`upstream_ticket_id`、`policy_snapshot`。

**客户端 `api/v2/client/auto-refund`**（形状不变，语义增强）

- `/bootstrap` 的 `policy` 由「全局一套」改为「默认值 + 说明」；真正生效的是每个商品自己的策略。
- `/candidates` 每项新增：`refund_type`、`refund_type_label`、`refund_rule`、`refund_rule_label`、`requirement`、`requirement_label`、`window_hours`、`policy_configured`(bool)。
- `blocked_reasons` 枚举扩充：`rule_missing`（该商品没配策略）、`renewed`（已续费）、`upgraded`（升降级过）、`free_order`（免费订单）、`service_status`（状态不支持）、`expired`（已过期）、`unpaid`（未支付）。
- 提交成功后：`channel` 决定用户看到的话术（`api_ticket` = 「已向上游提交工单，等待处理」；`bridge` = 「已提交上游审核」；`manual` = 「等待管理员审核」；`auto` = 「已退款到余额」）。

### 表结构

新增 `auto_refund_product_rules`（一行一个商品：类型/规则/要求/窗口/启用，显式 InnoDB，`product_id` 唯一）；
`auto_refund_applications` 增加 `refund_type`、`channel`、`ticket_id`、`upstream_ticket_id`、`policy_snapshot`（提交时的策略快照，事后能解释「当时为什么能退、金额怎么算的」）。

### 与上游的刻意差异

1. **同类产品首次按真实分组判定**：上游 `request = 2` 的实现与 `request = 1` 完全一样（都按 `productid` 查），等于两个选项没区别。本站按**一级商品分组**判定，语义符合它自己的界面文案，差异写进文档。
2. **代理豁免不做**：上游对代理组用户跳过「首次」校验并给额外可退天数（`setting.day`）。本站不引入代理组差异，所有用户同一口径——多一条隐式豁免就多一个对账说不清的地方。
3. **金额不传上游**：上游开源版旧接口允许下游指定 `amount`，商业版契约不允许。本站不传。
4. **状态码保留本站字符串**：不用上游的 1/2/3/4 数字，前端已经按字符串状态渲染。

## 范围与验收

- [ ] 插件可被后台扫描、安装、配置、启用；`api_key` 加密存储、详情不回显明文。
- [ ] 配置未填齐时控制台「退款中心」显示未开放而不是 500。
- [ ] `/candidates` 只返回当前用户自己的服务，且 hostid 必须出现在上游 `query` 结果里。
- [ ] 提交成功落 `pending_review` 并记录 `trace_id`；同一服务重复提交被 `active_service_id` 唯一索引挡住。
- [ ] 上游非 200 落 `failed` 并原样回报 `code` / `msg`；网络超时落 `uncertain` 并占住闸门。
- [ ] `cancel` 成功置 `cancelled`；上游返回 400 时不假装成功。
- [ ] 结算调用既有退款链路，金额不超过既有可退金额，写 `settled_amount` / `settled_at` / `operator_id`。
- [ ] 管理端权限不足时看不到入口、直接请求被拒。
- [ ] 插件停用后控制台与管理端入口消失、接口 404 而不是 500。
- [ ] `cd backend && php artisan test --filter=AutoRefund` 全绿；两端 `pnpm run build` 通过。
- [ ] `pnpm run docs:check` 通过（含三语繁体再生成）。

## 实施步骤

1. 落地执行计划（本文件）与契约。
2. 后端插件：配置/模型/迁移/上游客户端/策略/编排/路由/控制器/请求校验 + Feature 测试。
3. `OptionalFeature` 增加 `auto_refund`。
4. 控制台退款中心页 + 路由 + 导航 + 文案。
5. 管理端台账页 + 路由 + 导航 + 文案。
6. 文档：插件 README、`docs/develop/plugins/index.md` 插件清单、站长侧使用说明、本站计划索引登记。
7. 全量验证与回归。

## 风险与回滚

- **重复提交**：上游无幂等键。对策：`active_service_id` 唯一索引 + 超时落 `uncertain` 不释放闸门 + 管理端「作废」是唯一手动收口出口。
- **金额不一致**：上游实退金额不可查。对策：本地只做预估并标注「以上游核算为准」，结算以管理员确认金额为准，且受既有 `remainingRefundableAmount` 上限保护。
- **上游不可用**：`/candidates` 降级展示 + 禁止提交；已有台账不受影响。
- **配置泄漏**：`api_key` 只进 `secret_json`，不进日志、不进前端、不进 runtime log 明文。
- **回滚**：停用插件即消失入口与接口；数据表只新增，回滚不需要改结构。

## 进度

- [x] 契约确认与计划落地。
- [x] 后端插件（模型/迁移/上游客户端/策略/编排/路由/测试）：`php artisan test --filter=AutoRefund` 37 通过；`--testsuite=Plugins` 95 通过。
- [x] `OptionalFeature` 登记，客户端 `auth/info` 开始下发 `features`。
- [x] 控制台退款中心（`/client/refunds`）。
- [x] 管理端台账（`/admin/auto-refund/applications`）。
- [x] 文档：插件 README、`guide/configure/auto-refund.md` 四语、`develop/plugins/index.md` 清单与链接、本计划索引与 catalog 登记。
- [x] 全量验证：后端 `--filter=AutoRefund` 37 通过 / `--testsuite=Plugins` 95 通过 / 客户端鉴权与结构边界 26 通过；两端 `pnpm run build` 通过；控制台 e2e 3 通过、管理端 e2e 4 通过；`pnpm run docs:check` 通过。
- [ ] 待补（不阻塞交付）：完整 `php artisan test` 全量回归、`composer format:check` 全仓一次（本次新增迁移与既有 `register_ai_assistant_plugin` 同类，均不在 Pint 默认口径内）。

**第二阶段（完整复刻）**

- [x] 商品策略表 + 台账补通道/工单字段（两个核心迁移，MySQL 5.7.26 实跑通过）。
- [x] 策略引擎 v2：金额公式照抄上游 + 九条前置校验 + 三种要求（`category_first` 按一级分组真实判定）。
- [x] 四种类型分流：`manual` / `auto` / `api_ticket` / `bridge`，`auto` 直接走既有退款链路。
- [x] 上游工单两条协议：`zjfm`（`login_api` → `submitticket`，302+`supporttickets` 判成功）与 `tura`（复用 `TicketDeliveryService`，新增 `queueRefundTicket()` 一个方法）。
- [x] 管理端「退款商品策略」页 + 台账补列；控制台按商品策略展示 + `cancellable` 收口。
- [x] 文档四语重写（新增四类型/两协议/公式/九校验/策略页）与 `docs:check` 通过。
- [x] 验证：后端 `--filter=AutoRefund` **72 通过 / 383 断言**、`--testsuite=Plugins` **130 通过 / 745 断言**、工单与插件核心 6 文件 **114 通过 / 1074 断言**、`pint --test` 全绿；两端 `pnpm run build` 通过；控制台 e2e **7 通过**、管理端 e2e **9 通过**。

## 决策日志

| 日期       | 决策                                                                                                                 | 原因                                                                                                                  |
| ---------- | -------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------- |
| 2026-11-01 | 插件做「下游调用方」，不做上游 `api.php` 的本地复刻                                                                  | 本站是上游代理账号方，需要的是把用户申请转发给上游并把结果落回本地账务。                                              |
| 2026-11-01 | 结算复用 `UserService::refundService`，不新建余额加减                                                                | 既有链路已含账单锁、可退上限、退款单、通知与流水，另造一套必然与账务真源分叉。                                        |
| 2026-11-01 | 无进度查询 → 用本地台账 + 管理员确认结算                                                                             | 上游明确不提供进度查询，任何「自动判定已到账」都是在猜；预估金额同理只做展示。                                        |
| 2026-11-01 | `auto_settle` 默认关闭                                                                                               | 只有上游产品配置成「自动退款」时才成立，默认开启等于把「申请已创建」当「钱已到账」。                                  |
| 2026-11-01 | 幂等靠 `active_service_id` 可空唯一索引，不靠应用层先查再写                                                          | 并发下先查再写必然漏；MySQL 5.7 没有部分索引，可空唯一列是唯一不引额外表的做法。                                      |
| 2026-11-01 | 不新建插件自建运行日志表                                                                                             | 插件规范禁止自建业务运行日志表；调用留痕走专用日志通道，结果摘要写申请行。                                            |
| 2026-11-01 | 建表与登记放**核心迁移**，插件目录不留 `database/`                                                                   | 插件标准 v2 §3 禁止插件自注册迁移，`PluginLifecycleRunner` 也写明迁移由调用方负责；与 `addons/ai_assistant` 同口径。  |
| 2026-11-01 | 内置登记**只安装不启用**，与 AI 助手「装完即启用」不同                                                               | 会动钱的插件，没填上游地址与密钥就启用，用户看到的是登录后点进去只有「暂未开放」的入口。                              |
| 2026-11-01 | 管理端权限沿用 `invoice.list` / `invoice.manage` / `integration_plugin.test`                                         | 插件不能自注册权限码；能看账单、能退款本来就是同一批人，另立 `auto_refund.*` 反而要核心加码与角色回填。               |
| 2026-11-01 | 客户端 `auth/info` 增加 `features` 下发                                                                              | 控制台原先没有可选功能机制，而插件停用必须收起入口（不能留死入口），这是最小改动点。                                  |
| 2026-11-01 | 建表显式 `engine = InnoDB`                                                                                           | 台账与结算同事务写入，实测存在把 `default_storage_engine` 配成 MyISAM 的实例，事务会静默失效。                        |
| 2026-11-01 | 上游响应非 JSON / 缺 `code` 也归「结果未知」，不释放闸门                                                             | 无法判定上游是否已建单时，宁可让管理员人工收口，也不能让用户重提造出第二笔申请。                                      |
| 2026-11-03 | 退款策略改为**按商品**，删掉全局 `policy_mode` / `policy_days` / `require_first_only` / `min_amount` / `auto_settle` | 上游三项参数本就挂在商品上；留着全局一套就是两套真源，「产品首次」也无处落地。                                        |
| 2026-11-03 | 金额基数取「开通那一单的账单实付」而非 `services.invoice_id`                                                         | `invoice_id` 注释是「最近一次关联账单」，续费后指向续费单，拿它当基数等于拿续费金额退款。                             |
| 2026-11-03 | 开通时间用 `services.created_at`、到期时间用 `services.expires_at`                                                   | services 表没有独立开通时间列，实例行创建即开通起点；到期时间只有 `expires_at` 一个真源。                             |
| 2026-11-03 | `category_first` 按一级商品分组真实判定，不复制上游的同款 SQL                                                        | 上游 `request=2` 与 `request=1` 用完全相同的条件（只有提示文案不同），照抄等于留一个假选项；差异已写进文档。          |
| 2026-11-03 | `api_ticket` / `bridge` 把「首次/时间窗口」交上游复核，其余校验本地照判                                              | 这两条通道上游自己会拦，本地重复拦只会和上游口径打架；账单未支付、已续费、免费、升降级这些仍必须本地拦。              |
| 2026-11-03 | `zjfm` 协议先打上游成功再建本地工单；`tura` 协议整段包事务                                                           | 上游没受理就不该留本地孤儿工单；`tura` 只能先建单再投递，失败必须回滚。                                               |
| 2026-11-03 | `api_base_url` / `api_key` 由必填改可选                                                                              | 只用工单通道或只有 `manual`/`auto` 商品的站点不该因为没配 `api.php` 就无法启用插件；`bridge` 通道提交前单独校验就绪。 |
| 2026-11-03 | 上游工单号允许为空，`tura` 异步回读                                                                                  | `zjfm` 的 302 只给列表页地址，拿不到工单号；`tura` 投递是队列异步，提交那一刻上游还没建单。                           |
| 2026-11-03 | 插件版本 1.0.0 → 1.1.0                                                                                               | 能力扩展（四类型 + 工单通道），`PluginBoundaryTest` 已同步断言。                                                      |
