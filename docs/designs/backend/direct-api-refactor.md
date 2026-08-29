---
status: needs-review
updated: 2026-07-22
owner: backend-platform
---

# API 直接重构方案

本文记录 API 直接重构的历史结论与当前 V2 约束，适用于 admin、client、site 全量第一方 HTTP API。决策口径：旧接口已删除，V2 接口不保留旧结构兼容；基准来源是当时的旧接口快照、Git 历史与当前路由/测试。执行前必须与路由、测试和自动生成清单复核。

## 0. 执行入口

执行本方案前必须先阅读并对齐：

- `AGENTS.md`
- `docs/references/api/api-format.md`
- `docs/ARCHITECTURE.md`

本方案保留旧接口审查证据与 V2 设计约束；下文标注“旧接口”的路径均为历史对比，不代表当前可访问路由。后续改动以当前 V2 路由、Controller、FormRequest、Resource、Service 和 `后端API清单.md` 为准。

## 1. 决策结论

历史 API 治理采用“旧版本审计 + V2 直接重构 + 前端逐页迁移”的方式。当前覆盖范围不得由本文断言，必须以路由、测试和自动生成清单复核。

旧接口未保留兼容层、双字段或额外控制参数，相关路由及孤立 HTTP 代码已删除。后续结构性治理仅在 V2 API 进行。

新 API 建议使用以下路由命名空间：

- 管理端：`/api/v2/admin/*`
- 用户端：`/api/v2/client/*`
- 官网公开端：`/api/v2/site/*`

项目当前固定使用 `/v2`，不再注册 Admin、Client、Site 的旧根路由。

## 2. 当前可验证基线

- 接口清单由 `php backend/scripts/export_api_inventory.php` 从当前 Laravel 路由生成；不得手工维护数量或调用通过率。
- 2026-07-22 生成的 [后端 API 清单](../../generated/api/backend-api-catalog.md) 包含 337 条接口记录。
- 本文不维护人工调用成功率；以 Feature 测试、受控联调记录和当前生成清单为准。

## 3. 历史审计快照

| 问题类型             | 严重度 | 典型证据                                                                                                                                      | 影响                                 |
| -------------------- | ------ | --------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------ |
| 大响应越线           | 高     | `/api/admin/coupons/product-tree` 示例约 363KB，`/api/admin/product-groups` 和 `/api/admin/product-categories` 约 237KB，catalog 接口约 198KB | 首屏慢、移动端差、代理和网关成本升高 |
| 嵌套过深             | 高     | `/api/admin/coupons/product-tree` 深度 6；多个 catalog、products、orders、invoices 深度 4                                                     | 前端适配复杂，字段裁剪困难           |
| 列表和详情职责混用   | 高     | 产品、服务、账单、工单、内容接口的列表返回大量详情字段                                                                                        | 响应体膨胀，字段契约不清             |
| 敏感字段风险         | 高     | 服务详情、工单详情出现 `connection.password`、`has_password` 等字段                                                                           | 凭据暴露面扩大                       |
| 分页不统一           | 中     | 日志接口使用并返回 `per_page`；部分列表无标准分页                                                                                             | 前端请求层和表格组件无法统一         |
| 请求校验入口不集中   | 中     | 多个接口文档显示无 FormRequest 或未发现明确规则                                                                                               | 参数归一化分散，边界校验弱           |
| 动作式 URL 过多      | 中     | `toggle-status`、`mark-read`、`remote-status`、`power`、`reinstall` 等动作路径较多                                                            | 新接口风格分裂                       |
| 空值和默认值字段过多 | 低     | 大量 `null`、空字符串、`0`、空数组随主响应返回                                                                                                | 前端判断复杂，浪费传输               |
| 时间和金额表达需固化 | 低     | 时间多为字符串，金额多为字符串 decimal，但文档需明确单位和时区                                                                                | 联调歧义                             |

## 4. 新 API 总体设计规则

### 4.1 请求规则

- 请求字段统一 `snake_case`。
- URL 使用小写复数资源名，避免新增长动作路径。
- 列表统一使用 `page`、`page_size`。
- 禁止在新接口中接受 `per_page`、`pageSize`、逗号字符串批量 ID。
- 批量 ID 使用数组：`ids: [1, 2, 3]`。
- 布尔参数接受 `true/false` 或 `1/0`，在 FormRequest 内归一化。
- 金额字段必须明确单位，优先使用字符串 decimal 表示元，例如 `"20.00"`；如使用整数分，字段必须命名为 `*_cents`。
- 日期时间字段必须说明时区，默认 `Asia/Shanghai`，格式为 `YYYY-MM-DD HH:mm:ss`。
- 写操作、复杂查询、批量操作、支付、账单、服务控制、实名、回调、上游相关接口必须使用 FormRequest。

### 4.2 返回规则

外层继续使用现行统一结构：

```json
{
  "code": 0,
  "message": "操作成功",
  "data": {},
  "timestamp": 1760000000
}
```

分页统一为：

```json
{
  "list": [],
  "total": 0,
  "page": 1,
  "page_size": 20
}
```

返回字段规则：

- 响应字段统一 `snake_case`。
- 列表 Resource 和详情 Resource 必须分离。
- 列表不返回长文本、完整配置、完整关联、凭据、第三方原始响应。
- 详情接口按业务模块返回，仍不得把第三方原始响应直接透传。
- 嵌套层级默认不超过 3 层；超过 3 层必须拆子资源或改分页。
- 任何 `password`、`secret`、`api_key`、私钥、完整 token 默认禁止返回。
- 凭据状态只返回 `has_value`、`has_password`、`masked_value`，且掩码不得等于原值。

### 4.3 大响应规则

- 单次 JSON 响应目标上限：100KB。
- 公开站点首屏接口目标上限：50KB。
- 管理端选择器/树接口目标上限：70KB。
- 大文本默认返回 `excerpt`，完整 `content`、`body`、`html` 只在详情接口返回。
- 图片、二维码、附件、视频统一返回 `url` 或 `asset_id`，禁止返回 base64。
- 日志、回复、操作记录必须分页，长字段默认裁剪为 `*_excerpt`。

## 5. 新接口分层设计

### 5.1 路由层

- 新增独立 v2 路由文件或路由分组。
- admin/client 继续使用 Sanctum Token。
- 权限码沿用旧管理端权限码，不重新发明权限体系。
- 回调接口继续使用签名中间件，业务层保留幂等校验。

### 5.2 Controller 层

- Controller 只做鉴权上下文收集、调用 Service、返回 Response。
- 禁止 Controller 直接拼复杂数组。
- 禁止 Controller 直接 `Http::*` 调第三方。
- 禁止 Controller 中散落 `$request->input()`、`$request->query()` 后拼业务数组；使用 `$request->validated()`。

### 5.3 FormRequest 层

- 每个新写接口都有专用 FormRequest。
- 复杂查询接口使用 List/Index FormRequest。
- 批量操作接口限制数组长度。
- 排序字段必须白名单映射。
- 兼容旧参数不是本轮目标，新接口直接拒绝旧参数。

### 5.4 Service 层

- 复用现有领域 Service。
- 如现有 Service 返回字段过宽，新增查询 DTO 或投影方法，不修改旧接口依赖的返回。
- 财务、订单、支付、退款、余额相关流程必须保留事务、幂等、审计。
- 上游 provider key 保持真实值，禁止把 `zjmf_finance_api` 别名为 `hosting_panel_api`。

### 5.5 Resource / DTO 层

- 新增 v2 Resource 或 Response DTO。
- 列表 Resource 只输出摘要字段。
- 详情 Resource 输出当前页面必要字段。
- 子资源如 replies、logs、products、children、rules 独立分页。
- 敏感字段用白名单显式控制。

## 6. P0 重构任务

P0 目标：先处理大响应、深嵌套、敏感字段风险。完成后新接口覆盖最高风险页面。

### 6.1 优惠券商品树

旧接口：

- `GET /api/admin/coupons/product-tree`

新接口建议：

- `GET /api/v2/admin/coupon-product-groups`
- `GET /api/v2/admin/coupon-product-groups/{group}/children`
- `GET /api/v2/admin/coupon-product-groups/{group}/products`

请求示例：

```http
GET /api/v2/admin/coupon-product-groups?page=1&page_size=50
```

返回示例：

```json
{
  "code": 0,
  "message": "操作成功",
  "data": {
    "list": [
      {
        "id": 1,
        "name": "云服务器",
        "parent_id": null,
        "level": 1,
        "children_count": 8,
        "products_count": 126
      }
    ],
    "total": 1,
    "page": 1,
    "page_size": 50
  },
  "timestamp": 1760000000
}
```

量化目标：

- 响应体从约 363KB 降到 30KB 到 70KB。
- 嵌套深度从 6 降到 2。
- 首次加载不返回产品明细。

测试要求：

- Feature 测试：列表分页、子节点加载、产品分页。
- 权限测试：无 `coupon.*` 权限不可访问。
- 响应大小测试：构造多层树数据，断言首包低于 100KB。

### 6.2 产品分组、分类、目录

旧接口：

- `GET /api/admin/product-groups`
- `GET /api/admin/product-categories`
- `GET /api/site/product-groups/{groupId}/catalog`
- `GET /api/site/product-categories/{groupId}/catalog`

新接口建议：

- `GET /api/v2/admin/product-groups`
- `GET /api/v2/admin/product-groups/{group}`
- `GET /api/v2/site/product-groups/{group}/children`
- `GET /api/v2/site/product-groups/{group}/products`

量化目标：

- 管理端分组列表从约 237KB 降到 40KB 到 70KB。
- 官网 catalog 从约 198KB 降到 30KB 到 60KB。
- 列表字段控制在 15 到 25 个。

测试要求：

- 管理端分页和筛选测试。
- 官网公开访问测试。
- catalog 子资源分页测试。
- 字段白名单测试，列表不得返回详情大字段。

### 6.3 产品列表与详情

旧接口：

- `GET /api/admin/products`
- `GET /api/admin/products/{product}`
- `GET /api/site/products`
- `GET /api/site/products/{productId}`
- `GET /api/site/products/init`

新接口建议：

- `GET /api/v2/admin/products`
- `GET /api/v2/admin/products/{product}`
- `GET /api/v2/site/products`
- `GET /api/v2/site/products/{product}`
- `GET /api/v2/site/product-purchase-context`

列表摘要字段建议：

- `id`
- `name`
- `display_name`
- `product_type`
- `group_path`
- `primary_price`
- `stock_status`
- `status`
- `sort_order`
- `updated_at`

详情字段按模块组织：

- `base`
- `pricing`
- `provisioning`
- `upstream_binding`
- `options`
- `audit`

量化目标：

- 产品列表字段从约 61 个降到 20 个以内。
- 产品详情按模块输出，默认响应低于 100KB。

测试要求：

- 列表摘要字段快照测试。
- 详情模块字段测试。
- 公开站点只返回上架可见产品。
- 上游绑定仍保留真实 `provider_key`，不做别名。

### 6.4 服务详情与连接信息

旧接口：

- `GET /api/client/services/{id}`
- `GET /api/client/services/{id}/base`
- `GET /api/admin/users/{user}/services/{serviceId}`

新接口建议：

- `GET /api/v2/client/services/{service}`
- `GET /api/v2/client/services/{service}/connection`
- `GET /api/v2/client/services/{service}/runtime`
- `GET /api/v2/admin/users/{user}/services/{service}`
- `GET /api/v2/admin/users/{user}/services/{service}/connection`

安全规则：

- 服务详情不返回 `connection.password`。
- 连接详情如必须返回密码，必须是独立接口、短权限路径、审计记录、必要时二次确认。
- 用户端默认只返回 `has_password`。

量化目标：

- 服务详情字段从约 77 个降到 25 到 35 个。
- 默认详情响应低于 50KB。

测试要求：

- 用户只能访问自己的服务。
- 管理端权限按原权限码控制。
- 默认服务详情断言不存在 `password` 字段。
- 连接详情调用写操作日志或审计记录。

### 6.5 工单详情与回复

旧接口：

- `GET /api/admin/tickets/{ticket}`
- `GET /api/client/tickets/{id}`

新接口建议：

- `GET /api/v2/admin/tickets/{ticket}`
- `GET /api/v2/admin/tickets/{ticket}/replies`
- `GET /api/v2/client/tickets/{ticket}`
- `GET /api/v2/client/tickets/{ticket}/replies`

量化目标：

- 工单详情不再内嵌全部 replies。
- 回复分页默认 `page_size=20`。
- 附件只返回 `id`、`name`、`type`、`url`、`deleted`。
- 不通过工单详情返回服务连接密码。

测试要求：

- 回复分页测试。
- 附件字段白名单测试。
- 用户端隔离测试。
- 管理端权限测试。

## 7. P1 重构任务

P1 目标：重构高频业务接口，统一分页、字段、Resource 边界。

### 7.1 订单、账单、支付、财务流水

涉及旧接口：

- admin `orders`
- admin/client `invoices`
- admin/client `finance/ledger`
- client `payments`
- client `balance-logs`

新接口建议：

- `GET /api/v2/admin/orders`
- `GET /api/v2/admin/orders/{order}`
- `GET /api/v2/admin/invoices`
- `GET /api/v2/admin/invoices/{invoice}`
- `GET /api/v2/client/invoices`
- `GET /api/v2/client/invoices/{invoice}`
- `GET /api/v2/client/ledger`

重构规则：

- 列表只返回摘要，不返回完整 `scene.fields`、完整 snapshot、完整 payment。
- 详情再返回场景字段和支付链路。
- 用户端账单详情使用独立 Resource，不复用管理端详情。
- 不返回内部审计字段给用户端。

量化目标：

- 财务列表字段减少 40% 到 60%。
- 列表响应保持 100KB 以下。

测试要求：

- 金额格式测试。
- 状态码和状态标签测试。
- 用户端不可读他人账单。
- Payment 不允许物理删除的回归测试不得破坏。

### 7.2 日志接口

涉及旧接口：

- `/api/admin/logs/activity`
- `/api/admin/logs/admin-logins`
- `/api/admin/logs/api`
- `/api/admin/logs/email`
- `/api/admin/logs/gateway`
- `/api/admin/logs/runtime`
- `/api/admin/logs/schedule`
- `/api/admin/logs/sms`
- `/api/admin/logs/system`
- `/api/admin/logs/tasks`

新接口建议：

- `GET /api/v2/admin/logs/{channel}`
- `GET /api/v2/admin/logs/{channel}/{log}`
- `GET /api/v2/admin/log-summaries/{channel}`

重构规则：

- 使用 `page`、`page_size`，禁止新接口使用 `per_page`。
- 列表返回 `message_excerpt` 和 `context_excerpt`。
- 详情返回完整 message/context。
- channel 使用白名单。

测试要求：

- 所有 channel 分页统一。
- 非法 channel 返回 `40400` 或 `42200`。
- 长日志列表不返回完整大字段。

### 7.3 内容与通知

涉及旧接口：

- site/client `notices`
- site/client `help-articles`
- site/client `content/overview`
- client `notifications`

新接口建议：

- `GET /api/v2/site/notices`
- `GET /api/v2/site/notices/{notice}`
- `GET /api/v2/site/help-articles`
- `GET /api/v2/site/help-articles/{article}`
- `GET /api/v2/client/notifications`

重构规则：

- 列表返回 `excerpt`，详情返回 `content`。
- 首页 overview 不返回正文。
- 通知列表分页，标记已读动作资源化。

测试要求：

- 公开内容只返回已发布内容。
- 列表不返回完整正文。
- 已读动作幂等。

## 8. P2 重构任务

P2 目标：收敛配置类、插件类、动作类接口风格。

### 8.1 插件与供应商

涉及旧接口：

- `integration-plugins`
- `suppliers`
- `settings`

新接口建议：

- `GET /api/v2/admin/integration-plugins`
- `GET /api/v2/admin/integration-plugins/{plugin}`
- `GET /api/v2/admin/integration-plugins/{plugin}/schema`
- `GET /api/v2/admin/suppliers`
- `GET /api/v2/admin/suppliers/{supplier}`
- `GET /api/v2/admin/suppliers/{supplier}/secrets/{key}`

重构规则：

- 列表不返回完整 schema。
- secret 类字段只返回存在状态和掩码。
- 第三方原始错误不进入 API message。
- 插件健康检查、测试短信、测试邮件作为命令资源返回任务结果。

### 8.2 动作类接口资源化

旧动作路径示例：

- `toggle-status`
- `trigger`
- `copy`
- `scan`
- `enable`
- `disable`
- `reset-password`
- `mark-read`
- `power`
- `reinstall`
- `refund`
- `cancel`
- `approve`
- `reject`
- `recall`

新接口规则：

- 状态修改：`PATCH /resources/{id}/status`
- 复制：`POST /resources/{id}/copies`
- 退款：`POST /refunds`
- 取消：`POST /cancellations`
- 标记已读：`PUT /read-state`
- 电源操作：`POST /power-actions`
- 重装：`POST /reinstallations`
- 触发任务：`POST /tasks`

返回示例：

```json
{
  "code": 0,
  "message": "操作成功",
  "data": {
    "id": 123,
    "status": "queued",
    "task_id": "task_202607050001"
  },
  "timestamp": 1760000000
}
```

## 9. 测试策略

### 9.1 后端 Feature 测试

每组新接口至少覆盖：

- 未登录返回 `40100`。
- 权限不足返回 `40300`。
- 参数校验失败返回 `42200` 和 `data.errors`。
- 成功响应 `code=0`。
- 分页结构固定为 `list`、`total`、`page`、`page_size`。
- 列表不返回详情字段。
- 用户端资源归属隔离。
- 管理端权限码生效。

### 9.2 Resource 字段测试

必须断言：

- 响应字段白名单。
- 不存在 `password`、`secret`、`api_key`、第三方原始响应。
- 金额字段类型和单位。
- 时间字段格式。
- 嵌套层级不超过设计目标。

### 9.3 大响应测试

对以下接口构造批量数据并断言响应大小：

- coupon product groups
- product groups/categories
- site catalog
- product list/detail
- service detail
- ticket replies
- logs

测试目标：

- 默认 JSON 响应低于 100KB。
- 公开站点首屏接口低于 50KB。
- 树选择器首包低于 70KB。

### 9.4 回归验证命令

后端改动后执行：

```bash
cd backend
php artisan test
php vendor\bin\pint --test
```

涉及 API 文档或清单重建时，按项目规则执行对应导出脚本，不手工编辑自动生成的 `docs/generated/api/backend-api-catalog.md`。

## 10. 前端迁移策略

迁移原则：

- 每个页面完整切到新接口，不在同一页面混用旧结构和新结构。
- 前端 API 封装新增 v2 方法，不直接在页面模板拼请求。
- 管理端仍使用 TDesign Vue Next。
- 用户控制台仍使用 TDesign Vue Next。
- 官网仍使用 Element Plus。
- 状态展示继续复用 shared 状态配置。

推荐顺序：

1. 管理端优惠券商品选择器。
2. 官网商品目录与首页聚合。
3. 用户控制台服务详情。
4. 工单详情。
5. 产品管理。
6. 财务/订单/账单。
7. 日志、供应商、插件、设置。

## 11. 交付检查清单

每个重构子任务必须满足：

- [ ] 查过旧接口文档、路由、Controller、FormRequest、Resource、Service。
- [ ] 新接口有明确请求结构和返回结构。
- [ ] 新接口使用 FormRequest。
- [ ] 新接口使用 Resource 或 Response DTO。
- [ ] 新接口不复用旧宽 Resource。
- [ ] 列表和详情分离。
- [ ] 响应大小有测试或可量化验证。
- [ ] 敏感字段有白名单断言。
- [ ] Feature 测试覆盖鉴权、权限、校验、成功路径。
- [ ] 前端调用迁移到对应 v2 API 封装。
- [ ] 后端测试和相关前端构建通过。

## 12. 禁止事项

- 禁止修改旧接口返回结构来“顺手兼容”新规范。
- 禁止在新接口中接受 `per_page`。
- 禁止列表接口返回完整详情对象。
- 禁止直接返回 Model。
- 禁止 Controller 直接调用第三方 HTTP。
- 禁止返回第三方原始错误给前端。
- 禁止物理删除 Payment 记录。
- 禁止把 `zjmf_finance_api` 归一化或别名成 `hosting_panel_api`。
- 禁止把图片、二维码、附件、视频用 base64 放进 JSON。
- 禁止未测试就进入下一组接口。
