# 自助退款插件（addons/auto_refund）

用户在控制台自助申请退款 → 插件按**逐商品**的退款策略判定资格、算金额、选通道
→ `manual` / `auto` 在本站收口，`bridge` 走上游 `api.php`，`api_ticket` 向上游开工单
→ 结果落本地台账，人工或自动结算复用本站既有账务链路（退款到余额）。

这套语义完整复刻智简魔方财务的 `AutoRefund` 插件（`Wu-jiyan/idcsmart-plugin-auto-refund` v1.3.0）：
四种退款类型、三种退款规则、三种退款要求，全部按商品配置。

- 插件域 / 标识：`addons` / `auto_refund`
- 入口类：`AutoRefundPlugin`（`execute()` 只做无副作用的状态自检，**不发上游请求**）
- 服务提供者：`AutoRefundServiceProvider`（只注册 `/api/v2/{client,admin}/auto-refund/*`）
- 数据表：`auto_refund_applications`（台账）、`auto_refund_product_rules`（商品策略）
- 可选功能键：`OptionalFeature::AUTO_REFUND = 'auto_refund'`（前端入口按它隐藏）

## 1. 能力

| 能力           | 说明                                                                  |
| -------------- | --------------------------------------------------------------------- |
| 可退机器列表   | 本地服务 ∩ 上游 `query` 的 `hostid`，附商品策略、预估金额与不可退原因 |
| 提交申请       | 按商品策略分流到四条通道之一；先占幂等闸门再外发                      |
| 用户取消       | 只有 `bridge` 通道且状态为 `pending_review` 时才有上游可取消对象      |
| 管理端台账     | 列表 / 详情 / 结算 / 驳回 / 作废（含类型、通道、工单号、策略快照）    |
| 管理端商品策略 | `GET/PUT /product-rules`：逐商品配置类型 / 规则 / 要求 / 窗口 / 启用  |
| 上游自检       | `api.php` 连通性（机器数 + 服务端时间差）、上游全量机器与本地绑定对账 |

**非目标**：不提供退款进度查询（上游没有该接口）、不建插件自建运行日志表、
不把用户退款申请直接打给上游的真实支付渠道（结算仍走本站账务链路）。

## 2. 四种退款类型

| 类型                      | 提交时做什么                                                    | 本地状态                             | 结算                     |
| ------------------------- | --------------------------------------------------------------- | ------------------------------------ | ------------------------ |
| `manual` 人工审核         | 只落台账，不外发                                                | `pending_review`                     | 管理员在台账点「结算」   |
| `auto` 自动退款           | 落台账后立刻走 `UserService::refundService()` 入账              | 直接 `settled`                       | 无需人工                 |
| `api_ticket` API 工单退款 | 建本站工单（部门 `billing`）再按 `ticket_protocol` 投递上游工单 | `pending_review`                     | 上游回复确认后管理员结算 |
| `bridge` 插件间对接       | 调上游 `api.php` 的 `submit`（**不传金额**）                    | `pending_review`（超时 `uncertain`） | 管理员核对后结算         |

台账的 `channel` 只记三种：`local`（`manual` / `auto` 都不外发）、`bridge`、`api_ticket`。

## 3. 三种退款规则与金额公式

金额基数取**该服务首购账单的实付金额**（`invoices.paid_amount`，为 0 时退回 `amount`），
周期用本站服务字段，公式逐字照抄上游 `calcRefund()`：

```text
周期秒数 = 到期时间 - 开通时间
每秒单价 = 首付实付 / 周期秒数
已用秒数 = 现在 - 开通时间

按时长 duration：金额 = 首付实付 - 每秒单价 × 已用秒数
按月   month   ：月单价 = 每秒单价 × 2592000
                 已用月数 = ceil(已用秒数 / 2592000)
                 金额 = 首付实付 - 月单价 × 已用月数
全额   full    ：金额 = 首付实付

统一 round(金额, 2)；结果 <= 0 直接拒绝申请（负数按 0 展示，决策不变）
```

主机处于 `Pending`（未开通完成）时**强制按全额**算，不看商品配置的规则。

字段映射（算错这三项就是退错钱）：

| 上游 `calcRefund()`           | 本站来源                                                                                     |
| ----------------------------- | -------------------------------------------------------------------------------------------- |
| `regdate` 开通时间            | `services.created_at`                                                                        |
| `nextduedate` 到期时间        | `services.expires_at`                                                                        |
| `firstpaymentamount` 首付实付 | 首购账单 `invoices.paid_amount`（优先 `services.order.invoice`，其次 `services.invoice_id`） |

## 4. 三种退款要求与九条前置校验

退款要求（`auto_refund_product_rules.requirement`）：

| 取值             | 语义           | 判定                                                                                                |
| ---------------- | -------------- | --------------------------------------------------------------------------------------------------- |
| `product_first`  | 同商品首次退款 | 该用户在同商品的其他服务上没有未取消的申请                                                          |
| `category_first` | 同分组首次退款 | 同上，但按**一级商品分组**判定（上游实现与 `product_first` 完全相同，本站按真实分组，属于刻意差异） |
| `within_hours`   | 指定时间内可退 | `开通时间 + window_hours × 3600 < 现在` 才拒，边界放行                                              |

`api_ticket` / `bridge` 两条通道的「首次」与「时间窗口」由上游按其自身商品配置复核，
本地只保留其余前置校验；`manual` / `auto` 完全按本地策略判定。

九条前置校验（顺序照搬上游 `check()`，命中即进 `blocked_reasons`）：

| #   | 校验                                         | 枚举码                       |
| --- | -------------------------------------------- | ---------------------------- |
| 1   | 商品没有退款策略（或无策略行 / `enabled=0`） | `rule_missing`               |
| 2   | 首购账单不存在 / 未支付                      | `no_paid_invoice` / `unpaid` |
| 3   | 同一主机已有未取消的申请                     | `pending_application`        |
| 4   | 非 Pending 且已过期                          | `expired`                    |
| 5   | 服务已删除（软删 / 取消态）                  | `service_status`             |
| 6   | 既不是运行中也不是 Pending                   | `service_status`             |
| 7   | 存在续费账单                                 | `renewed`                    |
| 8   | 首付金额为 0（免费订单）                     | `free_order`                 |
| 9   | 存在升降级账单                               | `upgraded`                   |

另有两条来自上游可见性的拦截：`upstream_missing`（上游列表里没有这台）、
`upstream_unavailable`（上游暂不可用）；金额不足是 `amount_too_low`，
超出时间窗口是 `window_expired`，首次额度用掉是 `first_only_used`。

商品隐藏 / 下架**不参与**判定：退款是既有交易关系的收尾，不是售卖行为。

## 5. 接口

客户端（`api` + `auth:sanctum` + `ensure.client`，全部按 `$request->user()->id` 过滤）：

| 方法 | 路径                                                  | 说明                                                  |
| ---- | ----------------------------------------------------- | ----------------------------------------------------- |
| GET  | `/api/v2/client/auto-refund/bootstrap`                | 开关、策略默认值与说明、通道就绪情况（不请求上游）    |
| GET  | `/api/v2/client/auto-refund/candidates`               | 可申请列表；上游不可用时降级返回并带 `upstream_error` |
| POST | `/api/v2/client/auto-refund/applications`             | `{service_id, reason}`，返回体带 `channel` 决定话术   |
| POST | `/api/v2/client/auto-refund/applications/{id}/cancel` | 仅 `bridge` + `pending_review` 可取消                 |
| GET  | `/api/v2/client/auto-refund/applications`             | 我的申请（分页、`status` 可筛）                       |

`/candidates` 每项除原有字段外还带：`refund_type` / `refund_type_label`、`refund_rule` /
`refund_rule_label`、`requirement` / `requirement_label`、`window_hours`、`policy_configured`。

管理端（`api` + `auth:sanctum` + `ensure.admin` + `permission:*`）：

| 方法 | 路径                                                 | 权限码                    |
| ---- | ---------------------------------------------------- | ------------------------- |
| GET  | `/api/v2/admin/auto-refund/applications`             | `invoice.list`            |
| GET  | `/api/v2/admin/auto-refund/applications/{id}`        | `invoice.list`            |
| POST | `/api/v2/admin/auto-refund/applications/{id}/settle` | `invoice.manage`          |
| POST | `/api/v2/admin/auto-refund/applications/{id}/reject` | `invoice.manage`          |
| POST | `/api/v2/admin/auto-refund/applications/{id}/close`  | `invoice.manage`          |
| GET  | `/api/v2/admin/auto-refund/product-rules`            | `invoice.list`            |
| PUT  | `/api/v2/admin/auto-refund/product-rules/{product}`  | `invoice.manage`          |
| POST | `/api/v2/admin/auto-refund/upstream/test`            | `integration_plugin.test` |
| GET  | `/api/v2/admin/auto-refund/upstream/products`        | `integration_plugin.test` |

台账列表 / 详情响应新增：`refund_type`、`refund_type_label`、`channel`、`channel_label`、
`ticket_id`、`upstream_ticket_id`、`policy_snapshot`（提交时策略 + 算出的金额 + 命中的规则）。

权限码沿用管理端既有目录（`App\Support\AdminPermissions`）。插件不能自行注册权限码，
需要独立权限时由维护者在核心里加码并回填迁移。

## 6. 上游工单两条协议（`api_ticket`）

| 协议           | 适用上游           | 实现                                                                                                                                                                                                                                                                                                                                   |
| -------------- | ------------------ | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `zjfm`（默认） | 智简魔方财务真上游 | `POST {hostname}/v1/login_api`（form：`username` + `api_key`，取响应**根级** `jwt`）→ `POST {hostname}/submitticket?step=2&dptid={部门}`（multipart，`Bearer` 头，字段 `title` / `content` / `host_id`）。成功判据：**302 且 Location 含 `supporttickets`**，或变体 `200` + JSON `code=200`；其它一律失败并回传 HTTP 码与响应前 300 字 |
| `tura`         | 上游也是 TuraIDC   | 建本站工单 → `TicketDeliveryService::queueRefundTicket()` → 既有 `DeliverTicketToUpstreamJob` 投递 `/ticket/create` → 回写上游工单号                                                                                                                                                                                                   |

共同点：

- 都先建一张**本站工单**（部门 `billing`）承载沟通记录，模板支持
  `{product_name}` `{host_id}` `{amount}` `{reason}` 占位；
- 申请单记 `ticket_id`；`zjfm` 拿不到上游工单号（302 只给列表页），
  `upstream_ticket_id` 允许为空并在摘要里写明「上游未返回工单号」；
- `tura` 是异步投递：提交那一刻上游工单还没建出来，台账读取时会回查
  `ticket_upstream_bindings` 把上游工单号补上；
- 工单回复回流后**不自动结算**：上游说「已退款」和「钱到了本站账上」是两件事。

## 7. 配置项

在管理端「插件管理 → 自助退款 → 配置」里填写，落地在 `integration_plugin_configs`
（`api_key`、`ticket_api_key` 进 `secret_json` 加密列，详情不回显明文）。

| 配置项                    | 类型              | 默认                                     | 说明                                                                                    |
| ------------------------- | ----------------- | ---------------------------------------- | --------------------------------------------------------------------------------------- |
| `enabled`                 | switch            | 开                                       | 关闭后控制台显示未开放，台账保留                                                        |
| `api_base_url`            | url               | 上游 `api.php` 默认地址                  | 只给「插件间对接」用，留空表示不用这条通道                                              |
| `api_key`                 | password + secret | 空                                       | 上游 `api.php` 完整密钥，留空则该通道判定未就绪                                         |
| `request_timeout`         | number            | 15                                       | 上游请求总超时（秒），超时按「结果未知」处理                                            |
| `console_notice`          | textarea          | 空                                       | 控制台说明文案，留空用内置默认                                                          |
| `settle_remark`           | text              | 自助退款申请结算                         | 退到余额时的备注前缀，会附申请单号                                                      |
| `upstream_precheck`       | switch            | 开                                       | `bridge` 提交前先 `query` 确认 hostid 在列                                              |
| `ticket_protocol`         | select            | `zjfm`                                   | `zjfm` / `tura`                                                                         |
| `ticket_hostname`         | url               | 空                                       | zjfm 上游站点根地址（拼 `/v1/login_api`、`/submitticket`）                              |
| `ticket_username`         | text              | 空                                       | zjfm `login_api` 用户名                                                                 |
| `ticket_api_key`          | password + secret | 空                                       | zjfm `login_api` 密钥，留空则该通道判定未就绪（绝不带空密钥发单）                       |
| `ticket_department_id`    | text              | 空                                       | zjfm 的 `dptid` / tura 的 `upstream_department_id`；tura 缺省时回退「工单传递规则」匹配 |
| `ticket_title_template`   | text              | `【退款申请】{product_name} - {host_id}` | 工单标题模板                                                                            |
| `ticket_content_template` | textarea          | 内置多行模板                             | 工单内容模板                                                                            |

运行时统一经 `PluginConfigRepository::resolvedConfigByDomainAndSlug('addons','auto_refund')`
读取，不读 `settings` 表，也不缓存解密结果。

**不再有全局的 `policy_mode` / `policy_days` / `require_first_only` / `min_amount` / `auto_settle`**：
这些语义自第二阶段起逐商品落在 `auto_refund_product_rules`，
全局一个开关根本表达不了「这个商品全额、那个商品按月、第三个只对首退开放」。

## 8. 状态机

```text
(提交)
  created --manual/auto_ticket 建单--> pending_review --cancel(bridge)--> cancelled
                                     pending_review --管理员结算--> settled
                                     pending_review --管理员驳回--> rejected
  created --bridge submit 非200 / 工单通道失败--> failed          （闸门释放）
  created --bridge 网络超时-------------> uncertain                （闸门保留）
  uncertain/pending_review/failed --管理员作废--> closed
  created --auto 入账成功--> settled（同一请求内完成）
```

不变式：

- `pending_review` 是用户唯一可取消的状态，且只有 `bridge` 通道真的有上游取消对象；
- `uncertain` 必须保留 `active_service_id`，否则用户重提会造出第二笔上游申请；
- `auto` 的账务失败**不**回滚台账：状态留在 `pending_review`，失败原因写
  `response_meta.auto_settle_error` 等人工处理；
- 幂等靠 `active_service_id` 可空唯一索引（MySQL 唯一索引允许多个 NULL），
  不靠「先查再写」——并发下先查再写必然漏。

## 9. 上游契约要点（`bridge` 通道，逐条对应实现）

- `POST {api_base_url}`，请求体必须是 `application/x-www-form-urlencoded`
  （实现用 `Http::asForm()`，**绝不发 JSON body**）；
- 必填 `api_key`、`timestamp`（Unix 秒）、`action`（`query` / `submit` / `cancel` 显式传）；
- `submit` / `cancel` 必填 `upstream_product_id`，值取自 `query` 的 `data[].hostid`；
- `submit` **不传 `amount`**：商业版契约不允许调用方指定金额；
- 业务结果**只看响应 JSON 的 `code`**（200 成功；400/401/403/404/405/500 为失败），
  绝大多数失败仍是 HTTP 200，不用 HTTP 状态码判业务结果，也不把 `msg` 当枚举；
- `trace_id` 只有部分响应返回，没返回就留空（本机会另外生成 `local_trace_id` 只用于对齐日志）；
- `submit` 成功只代表「申请已创建」，不代表资金到账；
- 网络超时 / 连不上 / 响应不是可解析 JSON → 归为「结果未知」，
  与「上游返回失败」严格分开：前者不释放闸门，只由管理端「作废」人工收口。

## 10. 部署

1. 上传/更新目录后跑一次 `php artisan migrate`：建表与内置登记都由**核心迁移**完成
   （`2026_11_01_000001`、`2026_11_01_000002`、`2026_11_03_000001_create_auto_refund_product_rules_table`、
   `2026_11_03_000002_add_channel_and_ticket_to_auto_refund_applications`）。
   插件不能自己注册迁移（插件标准 v2 §3），所以 DDL 不在插件目录里。
2. **插件只被登记、默认不启用**：这是会动钱的插件，配置填完再启用。
3. 填写配置：至少配一条出站通道（`api_base_url` + `api_key`，或 `ticket_*` 那一组）。
4. 到管理端「自助退款 → 商品策略」逐商品配置类型 / 规则 / 要求 / 窗口——**没配就没得退**。
5. 启用后核对入口：`OptionalFeature::map()` 的 `auto_refund` 会随登录/取用户信息接口下发。

数据库兼容：DDL 不含窗口函数、CTE、JSON 默认值或表达式默认值，MySQL 5.7.44 与 8.x 均可执行。

## 11. 排错

| 现象                                       | 排查                                                                                                      |
| ------------------------------------------ | --------------------------------------------------------------------------------------------------------- |
| 控制台「退款中心」显示未开放               | `bootstrap` 里看 `plugin_enabled` / `configured` / `bridge_ready` / `ticket_ready` / `table_ready`        |
| 某台机器显示「当前商品不支持自助退款」     | 该商品的策略没配或 `enabled=0`：管理端「商品策略」里 `status=unconfigured` 筛一下                         |
| 提交报「已续费/已升级/免费订单」           | 该机器存在 `renew` / `upgrade` 账单或首付为 0，属于九条前置校验，按人工流程处理                           |
| 提交报「上游暂不可用」                     | 管理端「测试上游连接」；`outcome=unknown` 说明超时/连不上                                                 |
| 上游返回 401 / 403                         | `api_key` 无效或上游插件授权失效                                                                          |
| 提交报「工单退款通道未就绪」               | 报错里会列出缺哪一项（`ticket_hostname` / `ticket_username` / `ticket_api_key` / `ticket_department_id`） |
| 工单渠道报「上游工单提交失败（HTTP ...）」 | 根据回传的 HTTP 码与响应前 300 字判断：302 判成功，其它都算失败                                           |
| 工单渠道报「未配置上游工单部门」           | 填 `ticket_department_id`，或给 `tura` 配一条启用中的工单传递规则                                         |
| `tura` 详情里上游工单号为空                | 投递是异步的：等队列跑完再看，或检查 `ticket_upstream_bindings.status` 与投递日志                         |
| 申请卡在「结果未知」                       | 先在上游核实是否已建单，再决定「结算」或「作废」；作废是唯一手动收口出口                                  |
| 结算报「超过原单可退金额」                 | 金额超出账单剩余可退额（既有账务链路的判定）                                                              |

日志：上游调用的请求/响应摘要写 `storage/logs/auto_refund.log`（JSON 行，
`api_key` 与 `ticket_api_key`、JWT 一律不进明文，只记 `jwt_obtained` 布尔），
结果摘要同时写进申请行的 `response_meta`，管理端详情页可见完整内容。
插件不自建日志表。

数据清理：卸载不会删表（插件标准 v2 §3）。需要清空时由站长手动执行：

```sql
TRUNCATE TABLE auto_refund_product_rules;
TRUNCATE TABLE auto_refund_applications;
DROP TABLE IF EXISTS auto_refund_product_rules;
DROP TABLE IF EXISTS auto_refund_applications;
```

## 12. 测试

```bash
cd backend
php artisan test --filter=AutoRefund
```

上游一律 `Http::fake()`，测试不会发真实请求。用例覆盖：三种规则公式与边界（刚开通 / 到期当天 /
超期 / 按月向上取整）、Pending 强制全额、金额 <= 0 拒绝、九条前置校验各一例、三种退款要求
（含「同分组不同商品被挡、不同分组放行」）、四种类型分流、`auto` 入账后台账 `settled` 且余额增加、
`api_ticket` 的 zjfm 302 成功 / 200 变体 / 失败回滚 / 缺凭据拒发、`tura` 建单与绑定回写、
商品策略 CRUD 与权限、`policy_snapshot` 落库、以及第一阶段那批（降级、幂等闸门、结算上限、停用 404）。

## 13. 许可

本插件随 TuraIDC 以 AGPL-3.0-or-later 发布。修改后通过网络向用户提供服务时，
必须按 AGPL 第 13 条向这些用户提供完整对应源码。
