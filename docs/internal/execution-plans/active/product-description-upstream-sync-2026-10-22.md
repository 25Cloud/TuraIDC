---
status: 进行中
updated: 2026-10-22
owner: product-platform
---

# 商品描述同步上游（单商品 / 分组批量 / 定时自动）

## 背景

上游（魔方类供应商）商品自带描述（转义过的卡片 HTML）。今天的落库路径只有一条：导入或批量同步商品时，
`ProductSyncService` 把上游描述归一后写进 `products.description`；但对**本地已存在**的商品，
同步逻辑会在 `products.description` 非空时主动 `unset($payload['description'])`——
人工填写的描述不会被覆盖，代价是**上游描述改了以后，本地没有任何途径能刷回来**。

现状还留下两个缺口：

1. `remark` 是 varchar(255)，历史导入把上游描述塞在这里会被截断，且 `remark` 同时承担内部备注、
   显示名兜底、回传魔方 `description` 三重职责，不能拿来当描述真源；
2. 没有「哪些商品要跟着上游走」这个维度——要么全量刷（会踩人工描述），要么一个都不刷。

## 目标

1. **单商品**：管理端商品配置页与商品列表都能对指定商品执行「同步上游描述」；
2. **分组批量**：二级分组、三级分组的菜单上能对该分组下商品批量同步描述；
3. **定时自动**：商品可单独开启「描述自动同步」，按全局节拍定时刷新；
4. **批量配置**：分组菜单的「同步设置」弹窗能批量开关该分组下商品的自动同步开关，
   商品列表工具栏的「同步设置」弹窗能配置全局节拍并一键全量同步。

## 决策日志

### D1 描述真源不变

`products.description` 仍是唯一真源。本功能**只增加「从上游拉」的写路径**，
不新增 `description_source` 之类的第二真源：同步是「上游 → 本地」的一次性动作，
与商品表单的人工编辑并存，由「是否开启自动同步」表达覆盖意愿。

### D2 落点只加两列 + 一组节拍配置

| 落点                                | 内容                                               | 理由                                               |
| ----------------------------------- | -------------------------------------------------- | -------------------------------------------------- |
| `products.description_sync_enabled` | tinyint，默认 0                                    | 商品级「参与自动同步」开关                         |
| `products.description_synced_at`    | timestamp，可空                                    | 上次同步时间，参照既有 `stock_synced_at`           |
| `settings`（`automation` 组）       | `product_description_sync_schedule_mode` / `_time` | 全局节拍，复用 `AutomationScheduleExpression` 词表 |

分组级**不加列**：分组菜单的批量开关直接写该分组下商品的 `description_sync_enabled`，
避免「分组开关」与「商品开关」两套状态互相打架。

### D3 全局开关不另设 enabled

`AutomationScheduleExpression` 的 mode 词表只有 every_fifteen_minutes / every_thirty_minutes / hourly / daily，
没有 disabled，因此不引入「全局关闭」态：关闭的方式是**不给任何商品开启** `description_sync_enabled`，
定时任务没有目标就直接空转。也不单独加全局 enabled 开关，避免与商品开关形成两套状态。

### D4 上游描述归一只留一份

`ProductSyncService::normalizeUpstreamDescription()`（解码实体 → 补 `ul` 容器 → 富文本白名单）
与 `ProductDescriptionService::remarkHtml()` 是同一套管线的两个入口。本次把管线抽到
`ProductDescriptionService::normalizeUpstreamHtml()`，导入路径与 remark 兜底共用，删掉重复实现。

### D5 同步走既有 supplier 目录能力

复用 `ProvidesConsoleCatalog` 的 `getProductCatalog($supplier)`：按供应商分组拉一次目录，
按上游商品 id 命中后取 `description`。不新增上游调用协议，也不做逐商品拉取（避免放大上游压力）。

### D6 定时任务走心跳体系

在 `CoreScheduledTaskProvider` 注册 `product-description-sync`，trigger 用
`ScheduleRule::automation(mode, time, 默认 mode, 默认 time)`，与
`service-lifecycle-maintenance` 等系统任务同构。**不新增 `Schedule::command`**
（`ScheduleConsoleHookCoverageTest` / `HeartbeatSchedulerTest` 明确约束了 `routes/console.php` 的注册面）。

### D7 写路径性质

同步接口是「上游拉取」语义，与既有 `POST /products/{id}/status`、`/products/batch-update` 同类；
`GET /products/{id}/description` 仍保持只读（其 docblock 立的规矩是「不给同一份数据第二个写接口」，
同步不是通用写接口，不违反该规矩）。

## 进度

- [x] T1 勘察：描述真源与四态口径、导入路径的覆盖保护、分组层级与作用域、节拍机制与注册面
- [x] T2 迁移：`products.description_sync_enabled` / `description_synced_at`（只新增，不删改既有列）
- [x] T3 后端服务：`ProductDescriptionService::normalizeUpstreamHtml()` 抽出；
      `ProductSyncService::syncUpstreamProductDescriptions()`；`ProductDescriptionSyncService` 编排
- [x] T4 节拍与设置：`automation` 组新增两个键（默认值 + 归一 + 载荷下发）、
      `CoreScheduledTaskProvider` 注册定时任务
- [x] T5 接口：`POST /products/{product}/description/sync`、`POST /products/description/sync-batch`、
      `POST /products/description/auto-sync`、`POST /product-groups/{group}/description/sync`、
      `POST /product-groups/{group}/description/auto-sync`、全局设置读写
- [ ] T6 前端（管理端：商品列表行/批量、分组菜单、描述编辑器、全局节拍设置；进行中）：商品列表行操作 + 自动同步列；商品编辑页描述区同步按钮与开关；
      二级/三级分组页批量同步与「同步设置」弹窗；全局节拍弹窗
- [ ] T7 i18n：四个语言包补 key
- [ ] T8 验证：后端单测、前端 build/typecheck/lint、e2e、`docs:check`

## 验收标准

1. 商品列表对一个已绑定上游的商品执行同步，描述被上游内容覆盖，且写入 `description_synced_at`；
2. 未绑定上游、供应商不支持目录能力的商品，返回明确的跳过原因，不报错中断整批；
3. 未开启 `description_sync_enabled` 的商品，定时任务不碰它；
4. 开启后，定时任务按 `automation.product_description_sync_schedule_mode` / `_time` 的节拍刷新；
5. 二级、三级分组的「同步设置」弹窗能批量开关该分组下商品的自动同步，并显示受影响数量；
6. 分组批量同步只影响该分组（含其下级分组）的商品；
7. 人工填写的描述在没有显式同步、未开启自动同步时不被覆盖。

## 风险

- 上游目录拉取有耗时：批量同步按供应商分组，一次拉取覆盖该供应商下全部目标商品，避免 N 次调用；
- 描述被上游覆盖后不可回退：同步前在返回体里带上「本次覆盖了多少商品」，管理端做二次确认；
- `remark` 里的历史上游描述不主动清理：它同时是内部备注与回传字段，清理会牵连魔方回传，保持只读。
