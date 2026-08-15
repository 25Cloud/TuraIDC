# 当前数据库结构说明

- 文档性质：参考资料 / 实库结构快照
- 生成时间：`2026-08-14 00:05:01 +08:00`
- 数据来源：Laravel 默认连接 `mysql` 直连 MySQL `information_schema` 与业务库 `idc`
- 数据库：`idc`
- MySQL 版本：`8.0.29`
- 当前表数量：`62`
- 字段数量：`885`
- 索引数量：`309`
- 外键约束数量：`92`
- CHECK 约束数量：`0`
- 说明：
  - 本文只导出表结构元数据，不包含任何业务行数据。
  - 行数来自 `information_schema.TABLES.TABLE_ROWS`，InnoDB 下仅作估算。
  - 字段、索引、外键与约束均来自当前实库，不以迁移文件或历史快照推断。
  - 需要更新时在项目根目录执行：`php backend/scripts/export_database_structure.php`。

> **自动生成**：请优先通过脚本重刷本文档，避免手工维护结构信息产生漂移。

## 1. 结构概览

### 1.1 表清单

| 表名                              | 类型       | 引擎   | 估算行数 | 数据大小 | 索引大小 |    自增值 | 排序规则           | 表注释                                                                       |
| --------------------------------- | ---------- | ------ | -------: | -------: | -------: | --------: | ------------------ | ---------------------------------------------------------------------------- |
| `account_transactions`            | BASE TABLE | InnoDB |      656 |   144 KB |   304 KB |     1,753 | utf8mb4_unicode_ci | 账户流水表，记录现金账户、授信账户、推荐奖励账户的每一次余额变化             |
| `activity_logs`                   | BASE TABLE | InnoDB |   38,843 | 24.56 MB |  9.06 MB | 1,049,773 | utf8mb4_unicode_ci | —                                                                            |
| `admin_user_roles`                | BASE TABLE | InnoDB |        2 |    16 KB |    32 KB |       228 | utf8mb4_unicode_ci | —                                                                            |
| `admin_users`                     | BASE TABLE | InnoDB |        2 |    16 KB |    48 KB |       314 | utf8mb4_unicode_ci | —                                                                            |
| `agent_applications`              | BASE TABLE | InnoDB |        0 |    16 KB |    32 KB |         1 | utf8mb4_unicode_ci | —                                                                            |
| `archive_audit_logs`              | BASE TABLE | InnoDB |        0 |    16 KB |    32 KB |         2 | utf8mb4_unicode_ci | —                                                                            |
| `automation_logs`                 | BASE TABLE | InnoDB |      737 |   160 KB |   192 KB |     1,408 | utf8mb4_unicode_ci | —                                                                            |
| `content_articles`                | BASE TABLE | InnoDB |       14 |    96 KB |   160 KB |        35 | utf8mb4_unicode_ci | —                                                                            |
| `content_categories`              | BASE TABLE | InnoDB |        4 |    16 KB |    48 KB |        11 | utf8mb4_unicode_ci | —                                                                            |
| `coupon_campaigns`                | BASE TABLE | InnoDB |        0 |    16 KB |    48 KB |        15 | utf8mb4_unicode_ci | —                                                                            |
| `coupons`                         | BASE TABLE | InnoDB |        3 |    16 KB |    48 KB |        92 | utf8mb4_unicode_ci | —                                                                            |
| `failed_jobs`                     | BASE TABLE | InnoDB |      215 |  4.52 MB |    16 KB |       462 | utf8mb4_unicode_ci | —                                                                            |
| `first_product_groups`            | BASE TABLE | InnoDB |        6 |    16 KB |    32 KB |        58 | utf8mb4_unicode_ci | —                                                                            |
| `gateway_logs`                    | BASE TABLE | InnoDB |       88 |    64 KB |   112 KB | 1,000,089 | utf8mb4_unicode_ci | —                                                                            |
| `integration_plugin_bindings`     | BASE TABLE | InnoDB |       10 |    16 KB |    80 KB |        13 | utf8mb4_unicode_ci | —                                                                            |
| `integration_plugin_configs`      | BASE TABLE | InnoDB |        8 |    16 KB |    16 KB |        10 | utf8mb4_unicode_ci | —                                                                            |
| `integration_plugin_runtime_logs` | BASE TABLE | InnoDB |   17,155 | 24.55 MB |  5.31 MB | 1,018,561 | utf8mb4_unicode_ci | —                                                                            |
| `integration_plugins`             | BASE TABLE | InnoDB |       12 |    64 KB |    48 KB |        23 | utf8mb4_unicode_ci | —                                                                            |
| `invoice_items`                   | BASE TABLE | InnoDB |    2,776 |   512 KB |    96 KB |     2,845 | utf8mb4_unicode_ci | 账单明细表，记录账单内每个收费项目和快照信息                                 |
| `invoices`                        | BASE TABLE | InnoDB |    2,978 |  1.52 MB |  1.48 MB |     3,064 | utf8mb4_unicode_ci | 账单主表，所有购买、续费、充值、扣款和退款流程以账单为财务入口               |
| `jobs`                            | BASE TABLE | InnoDB |        0 |    16 KB |    16 KB |     4,732 | utf8mb4_unicode_ci | —                                                                            |
| `media_files`                     | BASE TABLE | InnoDB |       12 |    16 KB |    48 KB |        62 | utf8mb4_unicode_ci | —                                                                            |
| `member_levels`                   | BASE TABLE | InnoDB |        3 |    16 KB |    48 KB |         9 | utf8mb4_unicode_ci | —                                                                            |
| `message_logs`                    | BASE TABLE | InnoDB |      783 | 12.52 MB | 1,008 KB |     3,057 | utf8mb4_unicode_ci | —                                                                            |
| `migrations`                      | BASE TABLE | InnoDB |      175 |    16 KB |      0 B |       188 | utf8mb4_unicode_ci | —                                                                            |
| `notice_reads`                    | BASE TABLE | InnoDB |      142 |    16 KB |    48 KB |       185 | utf8mb4_unicode_ci | —                                                                            |
| `notification_templates`          | BASE TABLE | InnoDB |       56 |   272 KB |    32 KB |       981 | utf8mb4_unicode_ci | —                                                                            |
| `operation_logs`                  | BASE TABLE | InnoDB |  181,260 | 78.61 MB | 37.09 MB |   178,740 | utf8mb4_unicode_ci | —                                                                            |
| `orders`                          | BASE TABLE | InnoDB |      291 |   352 KB |   176 KB |     3,124 | utf8mb4_unicode_ci | —                                                                            |
| `password_reset_tokens`           | BASE TABLE | InnoDB |        0 |    16 KB |      0 B |         — | utf8mb4_unicode_ci | —                                                                            |
| `payment_callbacks`               | BASE TABLE | InnoDB |      328 |   320 KB |    96 KB |       500 | utf8mb4_unicode_ci | 支付回调审计表，保存第三方通知、查询、退款等回调验签结果                     |
| `payments`                        | BASE TABLE | InnoDB |      319 |   320 KB |   160 KB |       375 | utf8mb4_unicode_ci | 第三方支付记录表，仅记录真实外部资金流入和退款状态，不记录余额/免费/手工开服 |
| `personal_access_tokens`          | BASE TABLE | InnoDB |      370 |    96 KB |    80 KB |       408 | utf8mb4_unicode_ci | —                                                                            |
| `product_upstream_bindings`       | BASE TABLE | InnoDB |      139 |  8.52 MB |    96 KB |       270 | utf8mb4_unicode_ci | —                                                                            |
| `products`                        | BASE TABLE | InnoDB |      127 |  9.52 MB |    48 KB |       379 | utf8mb4_unicode_ci | 商品表，记录可售卖产品的分类、定价、库存、上游绑定和开通策略                 |
| `recharge_records`                | BASE TABLE | InnoDB |       24 |    16 KB |   144 KB |        25 | utf8mb4_unicode_ci | —                                                                            |
| `referral_account_logs`           | BASE TABLE | InnoDB |        6 |    16 KB |    64 KB |         7 | utf8mb4_unicode_ci | —                                                                            |
| `referral_rewards`                | BASE TABLE | InnoDB |        5 |    16 KB |    96 KB |        11 | utf8mb4_unicode_ci | —                                                                            |
| `referral_withdrawals`            | BASE TABLE | InnoDB |        0 |    16 KB |    48 KB |         4 | utf8mb4_unicode_ci | —                                                                            |
| `refunds`                         | BASE TABLE | InnoDB |        2 |    16 KB |    96 KB |         4 | utf8mb4_unicode_ci | —                                                                            |
| `roles`                           | BASE TABLE | InnoDB |        2 |    16 KB |    16 KB |       398 | utf8mb4_unicode_ci | —                                                                            |
| `schedule_run_logs`               | BASE TABLE | InnoDB |  149,720 | 31.56 MB | 21.09 MB |   152,327 | utf8mb4_unicode_ci | —                                                                            |
| `schedule_task_runs`              | BASE TABLE | InnoDB |    3,408 |  1.52 MB |  1.23 MB |     3,568 | utf8mb4_unicode_ci | —                                                                            |
| `schedule_ticks`                  | BASE TABLE | InnoDB |      279 |    16 KB |    48 KB |       315 | utf8mb4_unicode_ci | —                                                                            |
| `second_product_groups`           | BASE TABLE | InnoDB |       15 |    16 KB |    32 KB |        45 | utf8mb4_unicode_ci | —                                                                            |
| `service_connection_snapshots`    | BASE TABLE | InnoDB |      183 |   304 KB |    80 KB |       214 | utf8mb4_unicode_ci | —                                                                            |
| `service_provision_attempts`      | BASE TABLE | InnoDB |      233 |   112 KB |    80 KB |       444 | utf8mb4_unicode_ci | —                                                                            |
| `service_runtime_snapshots`       | BASE TABLE | InnoDB |      183 |   272 KB |    80 KB |       214 | utf8mb4_unicode_ci | —                                                                            |
| `service_upstream_bindings`       | BASE TABLE | InnoDB |      178 |   144 KB |   112 KB |       300 | utf8mb4_unicode_ci | —                                                                            |
| `services`                        | BASE TABLE | InnoDB |      138 |  1.52 MB |   128 KB |       314 | utf8mb4_unicode_ci | 服务实例表，记录用户已购买产品的生命周期、计费、上游和续费状态               |
| `sessions`                        | BASE TABLE | InnoDB |        0 |    16 KB |    32 KB |         — | utf8mb4_unicode_ci | —                                                                            |
| `settings`                        | BASE TABLE | InnoDB |      113 |    96 KB |    16 KB |       343 | utf8mb4_unicode_ci | —                                                                            |
| `supplier_plugin_bindings`        | BASE TABLE | InnoDB |        2 |    16 KB |    64 KB |       105 | utf8mb4_unicode_ci | —                                                                            |
| `suppliers`                       | BASE TABLE | InnoDB |        2 |    16 KB |    32 KB |        93 | utf8mb4_unicode_ci | —                                                                            |
| `third_product_groups`            | BASE TABLE | InnoDB |       26 |    16 KB |    32 KB |        49 | utf8mb4_unicode_ci | —                                                                            |
| `ticket_replies`                  | BASE TABLE | InnoDB |      177 |    48 KB |    32 KB |       178 | utf8mb4_unicode_ci | —                                                                            |
| `tickets`                         | BASE TABLE | InnoDB |       69 |    16 KB |    96 KB |        76 | utf8mb4_unicode_ci | —                                                                            |
| `user_accounts`                   | BASE TABLE | InnoDB |      411 |    64 KB |      0 B |         — | utf8mb4_unicode_ci | 用户账户余额源表，集中承载现金余额、授信和推荐奖励余额                       |
| `user_coupons`                    | BASE TABLE | InnoDB |       25 |    16 KB |    64 KB |        90 | utf8mb4_unicode_ci | —                                                                            |
| `user_notifications`              | BASE TABLE | InnoDB |      108 |    64 KB |    48 KB |       158 | utf8mb4_unicode_ci | —                                                                            |
| `users`                           | BASE TABLE | InnoDB |      482 |   128 KB |   176 KB |       484 | utf8mb4_unicode_ci | —                                                                            |
| `verification_histories`          | BASE TABLE | InnoDB |       97 |    64 KB |    48 KB |       103 | utf8mb4_unicode_ci | —                                                                            |

### 1.2 字段类型分布

| 类型         | 字段数 |
| ------------ | -----: |
| `bigint`     |    184 |
| `char`       |      1 |
| `date`       |      1 |
| `decimal`    |     42 |
| `int`        |     38 |
| `json`       |     56 |
| `longtext`   |     10 |
| `mediumtext` |      1 |
| `smallint`   |      1 |
| `text`       |     15 |
| `timestamp`  |    169 |
| `tinyint`    |     50 |
| `varchar`    |    317 |

### 1.3 JSON 字段

- `activity_logs.context`
- `automation_logs.meta`
- `coupon_campaigns.weekdays`
- `coupon_campaigns.billing_cycles`
- `coupon_campaigns.product_ids`
- `coupons.billing_cycles`
- `coupons.product_ids`
- `gateway_logs.request_data`
- `gateway_logs.response_data`
- `integration_plugin_bindings.config_json`
- `integration_plugin_bindings.has_secret_json`
- `integration_plugin_bindings.runtime_policy_json`
- `integration_plugin_configs.config_json`
- `integration_plugin_configs.has_secret_json`
- `integration_plugin_runtime_logs.request_meta_json`
- `integration_plugin_runtime_logs.response_meta_json`
- `integration_plugins.capabilities_json`
- `integration_plugins.config_schema_json`
- `invoice_items.meta_json`
- `invoices.config_snapshot`
- `invoices.config_pricing_snapshot`
- `invoices.coupon_snapshot`
- `message_logs.params_json`
- `notification_templates.variables_json`
- `notification_templates.provider_variables_json`
- `operation_logs.context`
- `orders.config_snapshot`
- `orders.config_pricing_snapshot`
- `orders.coupon_snapshot`
- `orders.service_snapshot`
- `payment_callbacks.payload_json`
- `payments.callback_raw`
- `product_upstream_bindings.upstream_product_snapshot_json`
- `product_upstream_bindings.option_schema_json`
- `product_upstream_bindings.provision_policy_json`
- `products.pricing`
- `products.config_options`
- `products.purchase_requires`
- `roles.permissions`
- `schedule_run_logs.summary`
- `schedule_task_runs.summary`
- `service_connection_snapshots.connection_json`
- `service_connection_snapshots.has_secret_json`
- `service_provision_attempts.request_meta_json`
- `service_provision_attempts.response_meta_json`
- `service_runtime_snapshots.resource_json`
- `service_runtime_snapshots.metrics_json`
- `service_runtime_snapshots.snapshot_json`
- `service_upstream_bindings.runtime_snapshot_json`
- `service_upstream_bindings.connection_snapshot_json`
- `services.locked_pricing`
- `services.provision_data`
- `supplier_plugin_bindings.config_json`
- `supplier_plugin_bindings.has_secret_json`
- `ticket_replies.attachments`
- `user_notifications.data`

## 2. 表结构明细

### 2.1 `account_transactions`

- 类型：`BASE TABLE`
- 引擎：`InnoDB`
- 估算行数：`656`
- 数据大小：`144 KB`
- 索引大小：`304 KB`
- 自增值：`1753`
- 排序规则：`utf8mb4_unicode_ci`
- 表注释：账户流水表，记录现金账户、授信账户、推荐奖励账户的每一次余额变化

#### 字段

| 序号 | 字段            | 类型              | 可空 | 默认值 | 键  | 额外           | 字符集  | 排序规则           | 注释                                                                          |
| ---: | --------------- | ----------------- | ---- | ------ | --- | -------------- | ------- | ------------------ | ----------------------------------------------------------------------------- |
|    1 | `id`            | `bigint unsigned` | 否   | —      | PRI | auto_increment | —       | —                  | 账户流水自增主键                                                              |
|    2 | `user_id`       | `bigint unsigned` | 否   | —      | MUL | —              | —       | —                  | 所属用户ID                                                                    |
|    3 | `account_type`  | `varchar(30)`     | 否   | —      | —   | —              | utf8mb4 | utf8mb4_unicode_ci | 账户类型：cash/credit/referral 等                                             |
|    4 | `event_type`    | `varchar(30)`     | 否   | —      | —   | —              | utf8mb4 | utf8mb4_unicode_ci | 流水事件类型：recharge/consume/refund/adjust/reward_frozen/reward_released 等 |
|    5 | `change_amount` | `decimal(12,2)`   | 否   | `0.00` | —   | —              | —       | —                  | 本次变动金额，收入为正、支出为负                                              |
|    6 | `currency`      | `varchar(3)`      | 否   | `CNY`  | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —                                                                             |
|    7 | `balance_after` | `decimal(12,2)`   | 否   | `0.00` | —   | —              | —       | —                  | 本次变动后的账户余额                                                          |
|    8 | `source_type`   | `varchar(30)`     | 是   | `NULL` | MUL | —              | utf8mb4 | utf8mb4_unicode_ci | 业务来源类型，如 invoice/payment/referral_withdrawal                          |
|    9 | `source_id`     | `bigint unsigned` | 是   | `NULL` | —   | —              | —       | —                  | 业务来源ID                                                                    |
|   10 | `origin_type`   | `varchar(30)`     | 是   | `NULL` | MUL | —              | utf8mb4 | utf8mb4_unicode_ci | 原始触发对象类型，用于跨域追踪                                                |
|   11 | `origin_id`     | `bigint unsigned` | 是   | `NULL` | —   | —              | —       | —                  | 原始触发对象ID                                                                |
|   12 | `remark`        | `varchar(255)`    | 是   | `NULL` | —   | —              | utf8mb4 | utf8mb4_unicode_ci | 流水备注                                                                      |
|   13 | `operator`      | `varchar(50)`     | 是   | `NULL` | —   | —              | utf8mb4 | utf8mb4_unicode_ci | 操作人快照                                                                    |
|   14 | `trace_id`      | `varchar(64)`     | 是   | `NULL` | MUL | —              | utf8mb4 | utf8mb4_unicode_ci | 链路追踪号                                                                    |
|   15 | `created_at`    | `timestamp`       | 是   | `NULL` | MUL | —              | —       | —                  | 创建时间                                                                      |
|   16 | `updated_at`    | `timestamp`       | 是   | `NULL` | —   | —              | —       | —                  | 更新时间                                                                      |

#### 索引

| 索引名                                          | 唯一 | 类型    | 字段                                          | 基数 | 注释 |
| ----------------------------------------------- | ---- | ------- | --------------------------------------------- | ---: | ---- |
| `account_transactions_created_at_idx`           | 否   | `BTREE` | `created_at`                                  |  640 | —    |
| `account_transactions_origin_idx`               | 否   | `BTREE` | `origin_type`, `origin_id`                    |  649 | —    |
| `account_transactions_source_idx`               | 否   | `BTREE` | `source_type`, `source_id`                    |  611 | —    |
| `account_transactions_trace_id_idx`             | 否   | `BTREE` | `trace_id`                                    |  574 | —    |
| `account_transactions_user_account_created_idx` | 否   | `BTREE` | `user_id`, `account_type`, `created_at`, `id` |  654 | —    |
| `account_transactions_user_event_created_idx`   | 否   | `BTREE` | `user_id`, `event_type`, `created_at`         |  647 | —    |
| `PRIMARY`                                       | 是   | `BTREE` | `id`                                          |  654 | —    |

#### 外键约束

| 约束名                                   | 字段      | 引用表  | 引用字段 | 更新规则    | 删除规则   |
| ---------------------------------------- | --------- | ------- | -------- | ----------- | ---------- |
| `fk_stage2_account_transactions_user_id` | `user_id` | `users` | `id`     | `NO ACTION` | `RESTRICT` |

### 2.2 `activity_logs`

- 类型：`BASE TABLE`
- 引擎：`InnoDB`
- 估算行数：`38,843`
- 数据大小：`24.56 MB`
- 索引大小：`9.06 MB`
- 自增值：`1049773`
- 排序规则：`utf8mb4_unicode_ci`
- 表注释：—

#### 字段

| 序号 | 字段           | 类型              | 可空 | 默认值   | 键  | 额外           | 字符集  | 排序规则           | 注释                                                                 |
| ---: | -------------- | ----------------- | ---- | -------- | --- | -------------- | ------- | ------------------ | -------------------------------------------------------------------- |
|    1 | `id`           | `bigint unsigned` | 否   | —        | PRI | auto_increment | —       | —                  | —                                                                    |
|    2 | `actor_type`   | `varchar(20)`     | 否   | `system` | —   | —              | utf8mb4 | utf8mb4_unicode_ci | 操作者类型: admin, client, system, sub_account                       |
|    3 | `actor_id`     | `bigint unsigned` | 是   | `NULL`   | MUL | —              | —       | —                  | 操作者ID                                                             |
|    4 | `actor_name`   | `varchar(100)`    | 否   | 空字符串 | —   | —              | utf8mb4 | utf8mb4_unicode_ci | 操作者名称快照                                                       |
|    5 | `module`       | `varchar(50)`     | 否   | —        | MUL | —              | utf8mb4 | utf8mb4_unicode_ci | 模块: invoice, order, service, user, product, ticket, coupon, system |
|    6 | `action`       | `varchar(100)`    | 否   | —        | —   | —              | utf8mb4 | utf8mb4_unicode_ci | 动作描述: create, pay, refund, suspend, terminate 等                 |
|    7 | `description`  | `text`            | 否   | —        | —   | —              | utf8mb4 | utf8mb4_unicode_ci | 可读描述                                                             |
|    8 | `subject_type` | `varchar(50)`     | 是   | `NULL`   | MUL | —              | utf8mb4 | utf8mb4_unicode_ci | 关联对象类型: invoice, service, order, user, ticket                  |
|    9 | `subject_id`   | `bigint unsigned` | 是   | `NULL`   | —   | —              | —       | —                  | 关联对象ID                                                           |
|   10 | `context`      | `json`            | 是   | `NULL`   | —   | —              | —       | —                  | 附加结构化上下文                                                     |
|   11 | `ip_address`   | `varchar(45)`     | 是   | `NULL`   | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —                                                                    |
|   12 | `created_at`   | `timestamp`       | 是   | `NULL`   | MUL | —              | —       | —                  | —                                                                    |
|   13 | `updated_at`   | `timestamp`       | 是   | `NULL`   | —   | —              | —       | —                  | —                                                                    |

#### 索引

| 索引名                                        | 唯一 | 类型    | 字段                         |   基数 | 注释 |
| --------------------------------------------- | ---- | ------- | ---------------------------- | -----: | ---- |
| `activity_logs_actor_id_index`                | 否   | `BTREE` | `actor_id`                   |     57 | —    |
| `activity_logs_created_at_index`              | 否   | `BTREE` | `created_at`                 | 15,540 | —    |
| `activity_logs_module_action_index`           | 否   | `BTREE` | `module`, `action`           |    929 | —    |
| `activity_logs_subject_type_subject_id_index` | 否   | `BTREE` | `subject_type`, `subject_id` |    118 | —    |
| `PRIMARY`                                     | 是   | `BTREE` | `id`                         | 38,823 | —    |

#### 外键约束

无数据库级外键约束。

### 2.3 `admin_user_roles`

- 类型：`BASE TABLE`
- 引擎：`InnoDB`
- 估算行数：`2`
- 数据大小：`16 KB`
- 索引大小：`32 KB`
- 自增值：`228`
- 排序规则：`utf8mb4_unicode_ci`
- 表注释：—

#### 字段

| 序号 | 字段            | 类型              | 可空 | 默认值 | 键  | 额外           | 字符集 | 排序规则 | 注释 |
| ---: | --------------- | ----------------- | ---- | ------ | --- | -------------- | ------ | -------- | ---- |
|    1 | `id`            | `bigint unsigned` | 否   | —      | PRI | auto_increment | —      | —        | —    |
|    2 | `admin_user_id` | `bigint unsigned` | 否   | —      | MUL | —              | —      | —        | —    |
|    3 | `role_id`       | `bigint unsigned` | 否   | —      | MUL | —              | —      | —        | —    |

#### 索引

| 索引名                               | 唯一 | 类型    | 字段                       | 基数 | 注释 |
| ------------------------------------ | ---- | ------- | -------------------------- | ---: | ---- |
| `admin_user_roles_admin_role_unique` | 是   | `BTREE` | `admin_user_id`, `role_id` |    2 | —    |
| `admin_user_roles_role_id_idx`       | 否   | `BTREE` | `role_id`                  |    2 | —    |
| `PRIMARY`                            | 是   | `BTREE` | `id`                       |    2 | —    |

#### 外键约束

| 约束名                                     | 字段            | 引用表        | 引用字段 | 更新规则    | 删除规则   |
| ------------------------------------------ | --------------- | ------------- | -------- | ----------- | ---------- |
| `fk_stage2_admin_user_roles_admin_user_id` | `admin_user_id` | `admin_users` | `id`     | `NO ACTION` | `CASCADE`  |
| `fk_stage2_admin_user_roles_role_id`       | `role_id`       | `roles`       | `id`     | `NO ACTION` | `RESTRICT` |

### 2.4 `admin_users`

- 类型：`BASE TABLE`
- 引擎：`InnoDB`
- 估算行数：`2`
- 数据大小：`16 KB`
- 索引大小：`48 KB`
- 自增值：`314`
- 排序规则：`utf8mb4_unicode_ci`
- 表注释：—

#### 字段

| 序号 | 字段            | 类型              | 可空 | 默认值 | 键  | 额外           | 字符集  | 排序规则           | 注释          |
| ---: | --------------- | ----------------- | ---- | ------ | --- | -------------- | ------- | ------------------ | ------------- |
|    1 | `id`            | `bigint unsigned` | 否   | —      | PRI | auto_increment | —       | —                  | —             |
|    2 | `username`      | `varchar(50)`     | 否   | —      | UNI | —              | utf8mb4 | utf8mb4_unicode_ci | —             |
|    3 | `password`      | `varchar(255)`    | 否   | —      | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —             |
|    4 | `role_id`       | `bigint unsigned` | 否   | —      | MUL | —              | —       | —                  | —             |
|    5 | `nickname`      | `varchar(50)`     | 是   | `NULL` | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —             |
|    6 | `status`        | `tinyint`         | 否   | `1`    | —   | —              | —       | —                  | 0=禁用 1=正常 |
|    7 | `last_login_at` | `timestamp`       | 是   | `NULL` | —   | —              | —       | —                  | —             |
|    8 | `last_login_ip` | `varchar(45)`     | 是   | `NULL` | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —             |
|    9 | `created_at`    | `timestamp`       | 是   | `NULL` | —   | —              | —       | —                  | —             |
|   10 | `updated_at`    | `timestamp`       | 是   | `NULL` | —   | —              | —       | —                  | —             |
|   11 | `email`         | `varchar(100)`    | 是   | `NULL` | MUL | —              | utf8mb4 | utf8mb4_unicode_ci | —             |

#### 索引

| 索引名                        | 唯一 | 类型    | 字段       | 基数 | 注释 |
| ----------------------------- | ---- | ------- | ---------- | ---: | ---- |
| `admin_users_email_index`     | 否   | `BTREE` | `email`    |    2 | —    |
| `admin_users_role_id_index`   | 否   | `BTREE` | `role_id`  |    2 | —    |
| `admin_users_username_unique` | 是   | `BTREE` | `username` |    2 | —    |
| `PRIMARY`                     | 是   | `BTREE` | `id`       |    2 | —    |

#### 外键约束

| 约束名                          | 字段      | 引用表  | 引用字段 | 更新规则    | 删除规则   |
| ------------------------------- | --------- | ------- | -------- | ----------- | ---------- |
| `fk_stage2_admin_users_role_id` | `role_id` | `roles` | `id`     | `NO ACTION` | `RESTRICT` |

### 2.5 `agent_applications`

- 类型：`BASE TABLE`
- 引擎：`InnoDB`
- 估算行数：`0`
- 数据大小：`16 KB`
- 索引大小：`32 KB`
- 自增值：`1`
- 排序规则：`utf8mb4_unicode_ci`
- 表注释：—

#### 字段

| 序号 | 字段            | 类型              | 可空 | 默认值    | 键  | 额外           | 字符集  | 排序规则           | 注释                            |
| ---: | --------------- | ----------------- | ---- | --------- | --- | -------------- | ------- | ------------------ | ------------------------------- |
|    1 | `id`            | `bigint unsigned` | 否   | —         | PRI | auto_increment | —       | —                  | —                               |
|    2 | `user_id`       | `bigint unsigned` | 否   | —         | MUL | —              | —       | —                  | —                               |
|    3 | `contact_name`  | `varchar(50)`     | 否   | 空字符串  | —   | —              | utf8mb4 | utf8mb4_unicode_ci | 联系人                          |
|    4 | `contact_phone` | `varchar(30)`     | 否   | 空字符串  | —   | —              | utf8mb4 | utf8mb4_unicode_ci | 联系手机                        |
|    5 | `contact_qq`    | `varchar(30)`     | 否   | 空字符串  | —   | —              | utf8mb4 | utf8mb4_unicode_ci | QQ号                            |
|    6 | `company_name`  | `varchar(120)`    | 否   | 空字符串  | —   | —              | utf8mb4 | utf8mb4_unicode_ci | 公司名称                        |
|    7 | `reason`        | `varchar(500)`    | 否   | 空字符串  | —   | —              | utf8mb4 | utf8mb4_unicode_ci | 申请说明                        |
|    8 | `status`        | `varchar(20)`     | 否   | `pending` | MUL | —              | utf8mb4 | utf8mb4_unicode_ci | 状态: pending/approved/rejected |
|    9 | `api_key`       | `varchar(64)`     | 是   | `NULL`    | —   | —              | utf8mb4 | utf8mb4_unicode_ci | API密钥                         |
|   10 | `admin_note`    | `varchar(500)`    | 否   | 空字符串  | —   | —              | utf8mb4 | utf8mb4_unicode_ci | 管理员备注                      |
|   11 | `created_at`    | `timestamp`       | 是   | `NULL`    | —   | —              | —       | —                  | —                               |
|   12 | `updated_at`    | `timestamp`       | 是   | `NULL`    | —   | —              | —       | —                  | —                               |

#### 索引

| 索引名                               | 唯一 | 类型    | 字段      | 基数 | 注释 |
| ------------------------------------ | ---- | ------- | --------- | ---: | ---- |
| `agent_applications_status_index`    | 否   | `BTREE` | `status`  |    0 | —    |
| `agent_applications_user_id_foreign` | 否   | `BTREE` | `user_id` |    0 | —    |
| `PRIMARY`                            | 是   | `BTREE` | `id`      |    0 | —    |

#### 外键约束

| 约束名                               | 字段      | 引用表  | 引用字段 | 更新规则    | 删除规则  |
| ------------------------------------ | --------- | ------- | -------- | ----------- | --------- |
| `agent_applications_user_id_foreign` | `user_id` | `users` | `id`     | `NO ACTION` | `CASCADE` |

### 2.6 `archive_audit_logs`

- 类型：`BASE TABLE`
- 引擎：`InnoDB`
- 估算行数：`0`
- 数据大小：`16 KB`
- 索引大小：`32 KB`
- 自增值：`2`
- 排序规则：`utf8mb4_unicode_ci`
- 表注释：—

#### 字段

| 序号 | 字段              | 类型              | 可空 | 默认值 | 键  | 额外           | 字符集  | 排序规则           | 注释 |
| ---: | ----------------- | ----------------- | ---- | ------ | --- | -------------- | ------- | ------------------ | ---- |
|    1 | `id`              | `bigint unsigned` | 否   | —      | PRI | auto_increment | —       | —                  | —    |
|    2 | `batch_id`        | `varchar(64)`     | 否   | —      | MUL | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|    3 | `table_name`      | `varchar(64)`     | 否   | —      | MUL | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|    4 | `mode`            | `varchar(30)`     | 否   | —      | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|    5 | `row_count`       | `int unsigned`    | 否   | `0`    | —   | —              | —       | —                  | —    |
|    6 | `file_path`       | `varchar(500)`    | 是   | `NULL` | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|    7 | `file_size`       | `bigint unsigned` | 是   | `NULL` | —   | —              | —       | —                  | —    |
|    8 | `checksum_sha256` | `char(64)`        | 是   | `NULL` | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|    9 | `status`          | `varchar(30)`     | 否   | —      | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|   10 | `error_message`   | `varchar(500)`    | 是   | `NULL` | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|   11 | `started_at`      | `timestamp`       | 是   | `NULL` | —   | —              | —       | —                  | —    |
|   12 | `finished_at`     | `timestamp`       | 是   | `NULL` | —   | —              | —       | —                  | —    |
|   13 | `created_at`      | `timestamp`       | 是   | `NULL` | —   | —              | —       | —                  | —    |

#### 索引

| 索引名                     | 唯一 | 类型    | 字段                                 | 基数 | 注释 |
| -------------------------- | ---- | ------- | ------------------------------------ | ---: | ---- |
| `archive_batch_idx`        | 否   | `BTREE` | `batch_id`                           |    0 | —    |
| `archive_table_status_idx` | 否   | `BTREE` | `table_name`, `status`, `created_at` |    0 | —    |
| `PRIMARY`                  | 是   | `BTREE` | `id`                                 |    0 | —    |

#### 外键约束

无数据库级外键约束。

### 2.7 `automation_logs`

- 类型：`BASE TABLE`
- 引擎：`InnoDB`
- 估算行数：`737`
- 数据大小：`160 KB`
- 索引大小：`192 KB`
- 自增值：`1408`
- 排序规则：`utf8mb4_unicode_ci`
- 表注释：—

#### 字段

| 序号 | 字段          | 类型              | 可空 | 默认值   | 键  | 额外           | 字符集  | 排序规则           | 注释 |
| ---: | ------------- | ----------------- | ---- | -------- | --- | -------------- | ------- | ------------------ | ---- |
|    1 | `id`          | `bigint unsigned` | 否   | —        | PRI | auto_increment | —       | —                  | —    |
|    2 | `task_key`    | `varchar(80)`     | 否   | —        | MUL | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|    3 | `action`      | `varchar(80)`     | 否   | —        | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|    4 | `object_type` | `varchar(40)`     | 否   | —        | MUL | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|    5 | `object_id`   | `bigint unsigned` | 否   | —        | —   | —              | —       | —                  | —    |
|    6 | `rule_key`    | `varchar(191)`    | 否   | 空字符串 | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|    7 | `meta`        | `json`            | 是   | `NULL`   | —   | —              | —       | —                  | —    |
|    8 | `executed_at` | `timestamp`       | 是   | `NULL`   | —   | —              | —       | —                  | —    |
|    9 | `created_at`  | `timestamp`       | 是   | `NULL`   | —   | —              | —       | —                  | —    |
|   10 | `updated_at`  | `timestamp`       | 是   | `NULL`   | —   | —              | —       | —                  | —    |

#### 索引

| 索引名                           | 唯一 | 类型    | 字段                                                         | 基数 | 注释 |
| -------------------------------- | ---- | ------- | ------------------------------------------------------------ | ---: | ---- |
| `automation_logs_object_idx`     | 否   | `BTREE` | `object_type`, `object_id`                                   |  382 | —    |
| `automation_logs_task_key_index` | 否   | `BTREE` | `task_key`                                                   |    6 | —    |
| `automation_logs_unique_rule`    | 是   | `BTREE` | `task_key`, `action`, `object_type`, `object_id`, `rule_key` |  737 | —    |
| `PRIMARY`                        | 是   | `BTREE` | `id`                                                         |  737 | —    |

#### 外键约束

无数据库级外键约束。

### 2.8 `content_articles`

- 类型：`BASE TABLE`
- 引擎：`InnoDB`
- 估算行数：`14`
- 数据大小：`96 KB`
- 索引大小：`160 KB`
- 自增值：`35`
- 排序规则：`utf8mb4_unicode_ci`
- 表注释：—

#### 字段

| 序号 | 字段                | 类型              | 可空 | 默认值 | 键  | 额外           | 字符集  | 排序规则           | 注释                     |
| ---: | ------------------- | ----------------- | ---- | ------ | --- | -------------- | ------- | ------------------ | ------------------------ |
|    1 | `id`                | `bigint unsigned` | 否   | —      | PRI | auto_increment | —       | —                  | —                        |
|    2 | `content_type`      | `varchar(20)`     | 否   | —      | MUL | —              | utf8mb4 | utf8mb4_unicode_ci | notice&#124;help         |
|    3 | `category_id`       | `bigint unsigned` | 是   | `NULL` | MUL | —              | —       | —                  | —                        |
|    4 | `title`             | `varchar(200)`    | 否   | —      | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —                        |
|    5 | `slug`              | `varchar(220)`    | 否   | —      | UNI | —              | utf8mb4 | utf8mb4_unicode_ci | —                        |
|    6 | `summary`           | `varchar(500)`    | 是   | `NULL` | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —                        |
|    7 | `content`           | `longtext`        | 否   | —      | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —                        |
|    8 | `category_name`     | `varchar(60)`     | 是   | `NULL` | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —                        |
|    9 | `keywords`          | `varchar(255)`    | 是   | `NULL` | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —                        |
|   10 | `cover_image`       | `varchar(500)`    | 是   | `NULL` | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —                        |
|   11 | `status`            | `tinyint`         | 否   | `0`    | —   | —              | —       | —                  | 0=草稿 1=已发布 2=已下线 |
|   12 | `is_pinned`         | `tinyint`         | 否   | `0`    | —   | —              | —       | —                  | —                        |
|   13 | `is_recommended`    | `tinyint`         | 否   | `0`    | —   | —              | —       | —                  | —                        |
|   14 | `sort_order`        | `int`             | 否   | `0`    | —   | —              | —       | —                  | —                        |
|   15 | `view_count`        | `int unsigned`    | 否   | `0`    | —   | —              | —       | —                  | —                        |
|   16 | `require_reread_at` | `timestamp`       | 是   | `NULL` | —   | —              | —       | —                  | —                        |
|   17 | `publish_at`        | `timestamp`       | 是   | `NULL` | —   | —              | —       | —                  | —                        |
|   18 | `last_published_at` | `timestamp`       | 是   | `NULL` | —   | —              | —       | —                  | —                        |
|   19 | `created_by`        | `bigint unsigned` | 是   | `NULL` | —   | —              | —       | —                  | —                        |
|   20 | `updated_by`        | `bigint unsigned` | 是   | `NULL` | —   | —              | —       | —                  | —                        |
|   21 | `operator`          | `varchar(50)`     | 是   | `NULL` | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —                        |
|   22 | `remark`            | `varchar(255)`    | 是   | `NULL` | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —                        |
|   23 | `trace_id`          | `varchar(64)`     | 是   | `NULL` | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —                        |
|   24 | `created_at`        | `timestamp`       | 是   | `NULL` | —   | —              | —       | —                  | —                        |
|   25 | `updated_at`        | `timestamp`       | 是   | `NULL` | —   | —              | —       | —                  | —                        |
|   26 | `deleted_at`        | `timestamp`       | 是   | `NULL` | —   | —              | —       | —                  | —                        |

#### 索引

| 索引名                              | 唯一 | 类型    | 字段                                            | 基数 | 注释 |
| ----------------------------------- | ---- | ------- | ----------------------------------------------- | ---: | ---- |
| `content_articles_slug_unique`      | 是   | `BTREE` | `slug`                                          |   14 | —    |
| `idx_article_category_published`    | 否   | `BTREE` | `category_id`, `status`, `publish_at`           |   14 | —    |
| `idx_content_article_type_category` | 否   | `BTREE` | `content_type`, `category_id`                   |    4 | —    |
| `idx_content_type_pin_sort`         | 否   | `BTREE` | `content_type`, `is_pinned`, `sort_order`, `id` |   14 | —    |
| `idx_content_type_recommend`        | 否   | `BTREE` | `content_type`, `is_recommended`, `publish_at`  |   14 | —    |
| `idx_content_type_status_publish`   | 否   | `BTREE` | `content_type`, `status`, `publish_at`          |   14 | —    |
| `PRIMARY`                           | 是   | `BTREE` | `id`                                            |   14 | —    |

#### 外键约束

| 约束名                                   | 字段          | 引用表               | 引用字段 | 更新规则    | 删除规则   |
| ---------------------------------------- | ------------- | -------------------- | -------- | ----------- | ---------- |
| `fk_stage2_content_articles_category_id` | `category_id` | `content_categories` | `id`     | `NO ACTION` | `SET NULL` |

### 2.9 `content_categories`

- 类型：`BASE TABLE`
- 引擎：`InnoDB`
- 估算行数：`4`
- 数据大小：`16 KB`
- 索引大小：`48 KB`
- 自增值：`11`
- 排序规则：`utf8mb4_unicode_ci`
- 表注释：—

#### 字段

| 序号 | 字段           | 类型              | 可空 | 默认值 | 键  | 额外           | 字符集  | 排序规则           | 注释             |
| ---: | -------------- | ----------------- | ---- | ------ | --- | -------------- | ------- | ------------------ | ---------------- |
|    1 | `id`           | `bigint unsigned` | 否   | —      | PRI | auto_increment | —       | —                  | —                |
|    2 | `content_type` | `varchar(20)`     | 否   | —      | MUL | —              | utf8mb4 | utf8mb4_unicode_ci | notice&#124;help |
|    3 | `name`         | `varchar(80)`     | 否   | —      | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —                |
|    4 | `slug`         | `varchar(120)`    | 否   | —      | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —                |
|    5 | `description`  | `varchar(255)`    | 是   | `NULL` | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —                |
|    6 | `status`       | `tinyint`         | 否   | `1`    | —   | —              | —       | —                  | 0=禁用 1=启用    |
|    7 | `sort_order`   | `int`             | 否   | `0`    | —   | —              | —       | —                  | —                |
|    8 | `created_by`   | `bigint unsigned` | 是   | `NULL` | —   | —              | —       | —                  | —                |
|    9 | `updated_by`   | `bigint unsigned` | 是   | `NULL` | —   | —              | —       | —                  | —                |
|   10 | `created_at`   | `timestamp`       | 是   | `NULL` | —   | —              | —       | —                  | —                |
|   11 | `updated_at`   | `timestamp`       | 是   | `NULL` | —   | —              | —       | —                  | —                |

#### 索引

| 索引名                                  | 唯一 | 类型    | 字段                                   | 基数 | 注释 |
| --------------------------------------- | ---- | ------- | -------------------------------------- | ---: | ---- |
| `idx_content_category_type_status_sort` | 否   | `BTREE` | `content_type`, `status`, `sort_order` |    2 | —    |
| `PRIMARY`                               | 是   | `BTREE` | `id`                                   |    4 | —    |
| `uniq_content_category_type_name`       | 是   | `BTREE` | `content_type`, `name`                 |    4 | —    |
| `uniq_content_category_type_slug`       | 是   | `BTREE` | `content_type`, `slug`                 |    4 | —    |

#### 外键约束

无数据库级外键约束。

### 2.10 `coupon_campaigns`

- 类型：`BASE TABLE`
- 引擎：`InnoDB`
- 估算行数：`0`
- 数据大小：`16 KB`
- 索引大小：`48 KB`
- 自增值：`15`
- 排序规则：`utf8mb4_unicode_ci`
- 表注释：—

#### 字段

| 序号 | 字段                   | 类型              | 可空 | 默认值        | 键  | 额外           | 字符集  | 排序规则           | 注释                |
| ---: | ---------------------- | ----------------- | ---- | ------------- | --- | -------------- | ------- | ------------------ | ------------------- |
|    1 | `id`                   | `bigint unsigned` | 否   | —             | PRI | auto_increment | —       | —                  | —                   |
|    2 | `name`                 | `varchar(120)`    | 否   | —             | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —                   |
|    3 | `description`          | `varchar(255)`    | 是   | `NULL`        | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —                   |
|    4 | `weekdays`             | `json`            | 是   | `NULL`        | —   | —              | —       | —                  | —                   |
|    5 | `trigger_time`         | `varchar(8)`      | 否   | —             | MUL | —              | utf8mb4 | utf8mb4_unicode_ci | —                   |
|    6 | `issue_quantity`       | `int unsigned`    | 否   | `1`           | —   | —              | —       | —                  | —                   |
|    7 | `valid_duration_hours` | `int unsigned`    | 是   | `NULL`        | —   | —              | —       | —                  | —                   |
|    8 | `discount_scope`       | `varchar(20)`     | 否   | `first_month` | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —                   |
|    9 | `discount_type`        | `varchar(20)`     | 否   | —             | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —                   |
|   10 | `discount_value`       | `decimal(12,2)`   | 否   | `0.00`        | —   | —              | —       | —                  | —                   |
|   11 | `min_amount`           | `decimal(12,2)`   | 否   | `0.00`        | —   | —              | —       | —                  | —                   |
|   12 | `max_discount_amount`  | `decimal(12,2)`   | 是   | `NULL`        | —   | —              | —       | —                  | —                   |
|   13 | `billing_cycles`       | `json`            | 是   | `NULL`        | —   | —              | —       | —                  | —                   |
|   14 | `product_ids`          | `json`            | 是   | `NULL`        | —   | —              | —       | —                  | —                   |
|   15 | `first_order_only`     | `tinyint(1)`      | 否   | `0`           | —   | —              | —       | —                  | —                   |
|   16 | `per_user_limit`       | `int unsigned`    | 是   | `NULL`        | —   | —              | —       | —                  | —                   |
|   17 | `status`               | `tinyint`         | 否   | `1`           | MUL | —              | —       | —                  | 状态：0=禁用 1=启用 |
|   18 | `sort_order`           | `int unsigned`    | 否   | `0`           | —   | —              | —       | —                  | —                   |
|   19 | `last_dispatched_at`   | `timestamp`       | 是   | `NULL`        | —   | —              | —       | —                  | —                   |
|   20 | `last_coupon_id`       | `bigint unsigned` | 是   | `NULL`        | MUL | —              | —       | —                  | —                   |
|   21 | `remark`               | `varchar(255)`    | 是   | `NULL`        | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —                   |
|   22 | `operator`             | `varchar(100)`    | 是   | `NULL`        | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —                   |
|   23 | `trace_id`             | `varchar(100)`    | 是   | `NULL`        | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —                   |
|   24 | `created_at`           | `timestamp`       | 是   | `NULL`        | —   | —              | —       | —                  | —                   |
|   25 | `updated_at`           | `timestamp`       | 是   | `NULL`        | —   | —              | —       | —                  | —                   |

#### 索引

| 索引名                                       | 唯一 | 类型    | 字段                     | 基数 | 注释 |
| -------------------------------------------- | ---- | ------- | ------------------------ | ---: | ---- |
| `coupon_campaigns_status_sort_idx`           | 否   | `BTREE` | `status`, `sort_order`   |    0 | —    |
| `coupon_campaigns_trigger_status_idx`        | 否   | `BTREE` | `trigger_time`, `status` |    0 | —    |
| `idx_stage2_coupon_campaigns_last_coupon_id` | 否   | `BTREE` | `last_coupon_id`         |    0 | —    |
| `PRIMARY`                                    | 是   | `BTREE` | `id`                     |    0 | —    |

#### 外键约束

| 约束名                                      | 字段             | 引用表    | 引用字段 | 更新规则    | 删除规则   |
| ------------------------------------------- | ---------------- | --------- | -------- | ----------- | ---------- |
| `fk_stage2_coupon_campaigns_last_coupon_id` | `last_coupon_id` | `coupons` | `id`     | `NO ACTION` | `SET NULL` |

### 2.11 `coupons`

- 类型：`BASE TABLE`
- 引擎：`InnoDB`
- 估算行数：`3`
- 数据大小：`16 KB`
- 索引大小：`48 KB`
- 自增值：`92`
- 排序规则：`utf8mb4_unicode_ci`
- 表注释：—

#### 字段

| 序号 | 字段                  | 类型              | 可空 | 默认值        | 键  | 额外           | 字符集  | 排序规则           | 注释                |
| ---: | --------------------- | ----------------- | ---- | ------------- | --- | -------------- | ------- | ------------------ | ------------------- |
|    1 | `id`                  | `bigint unsigned` | 否   | —             | PRI | auto_increment | —       | —                  | —                   |
|    2 | `coupon_campaign_id`  | `bigint unsigned` | 是   | `NULL`        | MUL | —              | —       | —                  | —                   |
|    3 | `name`                | `varchar(120)`    | 否   | —             | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —                   |
|    4 | `code`                | `varchar(50)`     | 否   | —             | UNI | —              | utf8mb4 | utf8mb4_unicode_ci | —                   |
|    5 | `description`         | `varchar(255)`    | 是   | `NULL`        | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —                   |
|    6 | `distribution_type`   | `varchar(20)`     | 否   | `public`      | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —                   |
|    7 | `discount_scope`      | `varchar(20)`     | 否   | `first_month` | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —                   |
|    8 | `discount_type`       | `varchar(20)`     | 否   | —             | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —                   |
|    9 | `discount_value`      | `decimal(12,2)`   | 否   | `0.00`        | —   | —              | —       | —                  | —                   |
|   10 | `min_amount`          | `decimal(12,2)`   | 否   | `0.00`        | —   | —              | —       | —                  | —                   |
|   11 | `max_discount_amount` | `decimal(12,2)`   | 是   | `NULL`        | —   | —              | —       | —                  | —                   |
|   12 | `billing_cycles`      | `json`            | 是   | `NULL`        | —   | —              | —       | —                  | —                   |
|   13 | `product_ids`         | `json`            | 是   | `NULL`        | —   | —              | —       | —                  | —                   |
|   14 | `first_order_only`    | `tinyint(1)`      | 否   | `0`           | —   | —              | —       | —                  | —                   |
|   15 | `total_usage_limit`   | `int unsigned`    | 是   | `NULL`        | —   | —              | —       | —                  | —                   |
|   16 | `per_user_limit`      | `int unsigned`    | 是   | `NULL`        | —   | —              | —       | —                  | —                   |
|   17 | `used_count`          | `int unsigned`    | 否   | `0`           | —   | —              | —       | —                  | —                   |
|   18 | `status`              | `tinyint`         | 否   | `1`           | MUL | —              | —       | —                  | 状态：0=禁用 1=启用 |
|   19 | `sort_order`          | `int unsigned`    | 否   | `0`           | —   | —              | —       | —                  | —                   |
|   20 | `starts_at`           | `timestamp`       | 是   | `NULL`        | —   | —              | —       | —                  | —                   |
|   21 | `expires_at`          | `timestamp`       | 是   | `NULL`        | —   | —              | —       | —                  | —                   |
|   22 | `remark`              | `varchar(255)`    | 是   | `NULL`        | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —                   |
|   23 | `operator`            | `varchar(100)`    | 是   | `NULL`        | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —                   |
|   24 | `trace_id`            | `varchar(100)`    | 是   | `NULL`        | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —                   |
|   25 | `created_at`          | `timestamp`       | 是   | `NULL`        | —   | —              | —       | —                  | —                   |
|   26 | `updated_at`          | `timestamp`       | 是   | `NULL`        | —   | —              | —       | —                  | —                   |

#### 索引

| 索引名                        | 唯一 | 类型    | 字段                           | 基数 | 注释 |
| ----------------------------- | ---- | ------- | ------------------------------ | ---: | ---- |
| `coupons_campaign_status_idx` | 否   | `BTREE` | `coupon_campaign_id`, `status` |    1 | —    |
| `coupons_code_unique`         | 是   | `BTREE` | `code`                         |    3 | —    |
| `coupons_status_sort_idx`     | 否   | `BTREE` | `status`, `sort_order`         |    1 | —    |
| `PRIMARY`                     | 是   | `BTREE` | `id`                           |    3 | —    |

#### 外键约束

| 约束名                                 | 字段                 | 引用表             | 引用字段 | 更新规则    | 删除规则   |
| -------------------------------------- | -------------------- | ------------------ | -------- | ----------- | ---------- |
| `fk_stage2_coupons_coupon_campaign_id` | `coupon_campaign_id` | `coupon_campaigns` | `id`     | `NO ACTION` | `SET NULL` |

### 2.12 `failed_jobs`

- 类型：`BASE TABLE`
- 引擎：`InnoDB`
- 估算行数：`215`
- 数据大小：`4.52 MB`
- 索引大小：`16 KB`
- 自增值：`462`
- 排序规则：`utf8mb4_unicode_ci`
- 表注释：—

#### 字段

| 序号 | 字段         | 类型              | 可空 | 默认值              | 键  | 额外              | 字符集  | 排序规则           | 注释 |
| ---: | ------------ | ----------------- | ---- | ------------------- | --- | ----------------- | ------- | ------------------ | ---- |
|    1 | `id`         | `bigint unsigned` | 否   | —                   | PRI | auto_increment    | —       | —                  | —    |
|    2 | `uuid`       | `varchar(255)`    | 否   | —                   | UNI | —                 | utf8mb4 | utf8mb4_unicode_ci | —    |
|    3 | `connection` | `text`            | 否   | —                   | —   | —                 | utf8mb4 | utf8mb4_unicode_ci | —    |
|    4 | `queue`      | `text`            | 否   | —                   | —   | —                 | utf8mb4 | utf8mb4_unicode_ci | —    |
|    5 | `payload`    | `longtext`        | 否   | —                   | —   | —                 | utf8mb4 | utf8mb4_unicode_ci | —    |
|    6 | `exception`  | `longtext`        | 否   | —                   | —   | —                 | utf8mb4 | utf8mb4_unicode_ci | —    |
|    7 | `failed_at`  | `timestamp`       | 否   | `CURRENT_TIMESTAMP` | —   | DEFAULT_GENERATED | —       | —                  | —    |

#### 索引

| 索引名                    | 唯一 | 类型    | 字段   | 基数 | 注释 |
| ------------------------- | ---- | ------- | ------ | ---: | ---- |
| `failed_jobs_uuid_unique` | 是   | `BTREE` | `uuid` |  215 | —    |
| `PRIMARY`                 | 是   | `BTREE` | `id`   |  215 | —    |

#### 外键约束

无数据库级外键约束。

### 2.13 `first_product_groups`

- 类型：`BASE TABLE`
- 引擎：`InnoDB`
- 估算行数：`6`
- 数据大小：`16 KB`
- 索引大小：`32 KB`
- 自增值：`58`
- 排序规则：`utf8mb4_unicode_ci`
- 表注释：—

#### 字段

| 序号 | 字段           | 类型               | 可空 | 默认值 | 键  | 额外           | 字符集  | 排序规则           | 注释 |
| ---: | -------------- | ------------------ | ---- | ------ | --- | -------------- | ------- | ------------------ | ---- |
|    1 | `id`           | `bigint unsigned`  | 否   | —      | PRI | auto_increment | —       | —                  | —    |
|    2 | `code`         | `varchar(50)`      | 是   | `NULL` | UNI | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|    3 | `product_type` | `varchar(50)`      | 是   | `NULL` | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|    4 | `name`         | `varchar(100)`     | 否   | —      | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|    5 | `slug`         | `varchar(100)`     | 是   | `NULL` | UNI | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|    6 | `description`  | `varchar(255)`     | 是   | `NULL` | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|    7 | `icon`         | `varchar(100)`     | 是   | `NULL` | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|    8 | `banner_image` | `varchar(255)`     | 是   | `NULL` | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|    9 | `sort_order`   | `int`              | 否   | `0`    | —   | —              | —       | —                  | —    |
|   10 | `is_visible`   | `tinyint unsigned` | 否   | `1`    | —   | —              | —       | —                  | —    |
|   11 | `is_system`    | `tinyint unsigned` | 否   | `0`    | —   | —              | —       | —                  | —    |
|   12 | `created_at`   | `timestamp`        | 是   | `NULL` | —   | —              | —       | —                  | —    |
|   13 | `updated_at`   | `timestamp`        | 是   | `NULL` | —   | —              | —       | —                  | —    |

#### 索引

| 索引名                             | 唯一 | 类型    | 字段   | 基数 | 注释 |
| ---------------------------------- | ---- | ------- | ------ | ---: | ---- |
| `first_product_groups_code_unique` | 是   | `BTREE` | `code` |    6 | —    |
| `first_product_groups_slug_unique` | 是   | `BTREE` | `slug` |    6 | —    |
| `PRIMARY`                          | 是   | `BTREE` | `id`   |    6 | —    |

#### 外键约束

无数据库级外键约束。

### 2.14 `gateway_logs`

- 类型：`BASE TABLE`
- 引擎：`InnoDB`
- 估算行数：`88`
- 数据大小：`64 KB`
- 索引大小：`112 KB`
- 自增值：`1000089`
- 排序规则：`utf8mb4_unicode_ci`
- 表注释：—

#### 字段

| 序号 | 字段            | 类型              | 可空 | 默认值    | 键  | 额外           | 字符集  | 排序规则           | 注释                                    |
| ---: | --------------- | ----------------- | ---- | --------- | --- | -------------- | ------- | ------------------ | --------------------------------------- |
|    1 | `id`            | `bigint unsigned` | 否   | —         | PRI | auto_increment | —       | —                  | —                                       |
|    2 | `plugin_id`     | `bigint unsigned` | 是   | `NULL`    | MUL | —              | —       | —                  | —                                       |
|    3 | `gateway_key`   | `varchar(120)`    | 是   | `NULL`    | MUL | —              | utf8mb4 | utf8mb4_unicode_ci | —                                       |
|    4 | `gateway`       | `varchar(50)`     | 否   | —         | MUL | —              | utf8mb4 | utf8mb4_unicode_ci | 网关标识: alipay_f2f, wechat_native 等  |
|    5 | `action`        | `varchar(50)`     | 否   | —         | —   | —              | utf8mb4 | utf8mb4_unicode_ci | 操作: precreate, notify, query, refund  |
|    6 | `out_trade_no`  | `varchar(128)`    | 是   | `NULL`    | MUL | —              | utf8mb4 | utf8mb4_unicode_ci | 商户订单号                              |
|    7 | `trade_no`      | `varchar(128)`    | 是   | `NULL`    | —   | —              | utf8mb4 | utf8mb4_unicode_ci | 第三方交易号                            |
|    8 | `invoice_id`    | `bigint unsigned` | 是   | `NULL`    | MUL | —              | —       | —                  | 关联账单ID                              |
|    9 | `trace_id`      | `varchar(64)`     | 是   | `NULL`    | MUL | —              | utf8mb4 | utf8mb4_unicode_ci | —                                       |
|   10 | `request_data`  | `json`            | 是   | `NULL`    | —   | —              | —       | —                  | 请求数据(脱敏后)                        |
|   11 | `response_data` | `json`            | 是   | `NULL`    | —   | —              | —       | —                  | 响应数据                                |
|   12 | `result_status` | `varchar(20)`     | 否   | `unknown` | —   | —              | utf8mb4 | utf8mb4_unicode_ci | 结果: success, failed, pending, unknown |
|   13 | `error_msg`     | `text`            | 是   | `NULL`    | —   | —              | utf8mb4 | utf8mb4_unicode_ci | 错误信息                                |
|   14 | `ip_address`    | `varchar(45)`     | 是   | `NULL`    | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —                                       |
|   15 | `created_at`    | `timestamp`       | 是   | `NULL`    | MUL | —              | —       | —                  | —                                       |
|   16 | `updated_at`    | `timestamp`       | 是   | `NULL`    | —   | —              | —       | —                  | —                                       |

#### 索引

| 索引名                              | 唯一 | 类型    | 字段                        | 基数 | 注释 |
| ----------------------------------- | ---- | ------- | --------------------------- | ---: | ---- |
| `gateway_logs_created_at_index`     | 否   | `BTREE` | `created_at`                |   88 | —    |
| `gateway_logs_gateway_action_index` | 否   | `BTREE` | `gateway`, `action`         |    2 | —    |
| `gateway_logs_gateway_key_idx`      | 否   | `BTREE` | `gateway_key`, `created_at` |   88 | —    |
| `gateway_logs_invoice_id_index`     | 否   | `BTREE` | `invoice_id`                |    1 | —    |
| `gateway_logs_out_trade_no_index`   | 否   | `BTREE` | `out_trade_no`              |   84 | —    |
| `gateway_logs_plugin_created_idx`   | 否   | `BTREE` | `plugin_id`, `created_at`   |   88 | —    |
| `gateway_logs_trace_idx`            | 否   | `BTREE` | `trace_id`                  |   35 | —    |
| `PRIMARY`                           | 是   | `BTREE` | `id`                        |   88 | —    |

#### 外键约束

| 约束名                              | 字段         | 引用表                | 引用字段 | 更新规则    | 删除规则   |
| ----------------------------------- | ------------ | --------------------- | -------- | ----------- | ---------- |
| `fk_stage2_gateway_logs_invoice_id` | `invoice_id` | `invoices`            | `id`     | `NO ACTION` | `SET NULL` |
| `gateway_logs_plugin_fk`            | `plugin_id`  | `integration_plugins` | `id`     | `NO ACTION` | `SET NULL` |

### 2.15 `integration_plugin_bindings`

- 类型：`BASE TABLE`
- 引擎：`InnoDB`
- 估算行数：`10`
- 数据大小：`16 KB`
- 索引大小：`80 KB`
- 自增值：`13`
- 排序规则：`utf8mb4_unicode_ci`
- 表注释：—

#### 字段

| 序号 | 字段                  | 类型               | 可空 | 默认值   | 键  | 额外           | 字符集  | 排序规则           | 注释                                                        |
| ---: | --------------------- | ------------------ | ---- | -------- | --- | -------------- | ------- | ------------------ | ----------------------------------------------------------- |
|    1 | `id`                  | `bigint unsigned`  | 否   | —        | PRI | auto_increment | —       | —                  | —                                                           |
|    2 | `domain`              | `varchar(32)`      | 否   | —        | MUL | —              | utf8mb4 | utf8mb4_unicode_ci | —                                                           |
|    3 | `plugin_id`           | `bigint unsigned`  | 否   | —        | MUL | —              | —       | —                  | —                                                           |
|    4 | `binding_type`        | `varchar(50)`      | 否   | —        | —   | —              | utf8mb4 | utf8mb4_unicode_ci | global/supplier/product/service/payment/notification/custom |
|    5 | `bindable_type`       | `varchar(120)`     | 否   | `global` | MUL | —              | utf8mb4 | utf8mb4_unicode_ci | —                                                           |
|    6 | `bindable_id`         | `bigint unsigned`  | 否   | `0`      | —   | —              | —       | —                  | —                                                           |
|    7 | `binding_key`         | `varchar(120)`     | 否   | —        | —   | —              | utf8mb4 | utf8mb4_unicode_ci | 同一对象下的绑定名                                          |
|    8 | `provider_key`        | `varchar(120)`     | 是   | `NULL`   | —   | —              | utf8mb4 | utf8mb4_unicode_ci | 外部协议标识快照                                            |
|    9 | `priority`            | `int`              | 否   | `0`      | —   | —              | —       | —                  | —                                                           |
|   10 | `status`              | `tinyint unsigned` | 否   | `1`      | —   | —              | —       | —                  | —                                                           |
|   11 | `config_json`         | `json`             | 是   | `NULL`   | —   | —              | —       | —                  | —                                                           |
|   12 | `secret_json`         | `longtext`         | 是   | `NULL`   | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —                                                           |
|   13 | `has_secret_json`     | `json`             | 是   | `NULL`   | —   | —              | —       | —                  | —                                                           |
|   14 | `runtime_policy_json` | `json`             | 是   | `NULL`   | —   | —              | —       | —                  | —                                                           |
|   15 | `created_by`          | `bigint unsigned`  | 是   | `NULL`   | —   | —              | —       | —                  | —                                                           |
|   16 | `updated_by`          | `bigint unsigned`  | 是   | `NULL`   | —   | —              | —       | —                  | —                                                           |
|   17 | `backfill_batch_id`   | `varchar(64)`      | 是   | `NULL`   | MUL | —              | utf8mb4 | utf8mb4_unicode_ci | —                                                           |
|   18 | `created_at`          | `timestamp`        | 是   | `NULL`   | —   | —              | —       | —                  | —                                                           |
|   19 | `updated_at`          | `timestamp`        | 是   | `NULL`   | —   | —              | —       | —                  | —                                                           |

#### 索引

| 索引名                                       | 唯一 | 类型    | 字段                                                                    | 基数 | 注释 |
| -------------------------------------------- | ---- | ------- | ----------------------------------------------------------------------- | ---: | ---- |
| `plugin_bindings_backfill_batch_idx`         | 否   | `BTREE` | `backfill_batch_id`                                                     |    2 | —    |
| `plugin_bindings_bindable_idx`               | 否   | `BTREE` | `bindable_type`, `bindable_id`, `domain`                                |    9 | —    |
| `plugin_bindings_domain_provider_status_idx` | 否   | `BTREE` | `domain`, `provider_key`, `status`                                      |    7 | —    |
| `plugin_bindings_plugin_status_idx`          | 否   | `BTREE` | `plugin_id`, `status`                                                   |    7 | —    |
| `plugin_bindings_unique`                     | 是   | `BTREE` | `domain`, `binding_type`, `bindable_type`, `bindable_id`, `binding_key` |   10 | —    |
| `PRIMARY`                                    | 是   | `BTREE` | `id`                                                                    |   10 | —    |

#### 外键约束

| 约束名                                          | 字段        | 引用表                | 引用字段 | 更新规则    | 删除规则   |
| ----------------------------------------------- | ----------- | --------------------- | -------- | ----------- | ---------- |
| `integration_plugin_bindings_plugin_id_foreign` | `plugin_id` | `integration_plugins` | `id`     | `NO ACTION` | `RESTRICT` |

### 2.16 `integration_plugin_configs`

- 类型：`BASE TABLE`
- 引擎：`InnoDB`
- 估算行数：`8`
- 数据大小：`16 KB`
- 索引大小：`16 KB`
- 自增值：`10`
- 排序规则：`utf8mb4_unicode_ci`
- 表注释：—

#### 字段

| 序号 | 字段              | 类型              | 可空 | 默认值 | 键  | 额外           | 字符集  | 排序规则           | 注释 |
| ---: | ----------------- | ----------------- | ---- | ------ | --- | -------------- | ------- | ------------------ | ---- |
|    1 | `id`              | `bigint unsigned` | 否   | —      | PRI | auto_increment | —       | —                  | —    |
|    2 | `plugin_id`       | `bigint unsigned` | 否   | —      | UNI | —              | —       | —                  | —    |
|    3 | `config_json`     | `json`            | 是   | `NULL` | —   | —              | —       | —                  | —    |
|    4 | `secret_json`     | `longtext`        | 是   | `NULL` | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|    5 | `has_secret_json` | `json`            | 是   | `NULL` | —   | —              | —       | —                  | —    |
|    6 | `updated_by`      | `bigint unsigned` | 是   | `NULL` | —   | —              | —       | —                  | —    |
|    7 | `created_at`      | `timestamp`       | 是   | `NULL` | —   | —              | —       | —                  | —    |
|    8 | `updated_at`      | `timestamp`       | 是   | `NULL` | —   | —              | —       | —                  | —    |

#### 索引

| 索引名                                     | 唯一 | 类型    | 字段        | 基数 | 注释 |
| ------------------------------------------ | ---- | ------- | ----------- | ---: | ---- |
| `integration_plugin_configs_plugin_unique` | 是   | `BTREE` | `plugin_id` |    8 | —    |
| `PRIMARY`                                  | 是   | `BTREE` | `id`        |    8 | —    |

#### 外键约束

| 约束名                                         | 字段        | 引用表                | 引用字段 | 更新规则    | 删除规则  |
| ---------------------------------------------- | ----------- | --------------------- | -------- | ----------- | --------- |
| `integration_plugin_configs_plugin_id_foreign` | `plugin_id` | `integration_plugins` | `id`     | `NO ACTION` | `CASCADE` |

### 2.17 `integration_plugin_runtime_logs`

- 类型：`BASE TABLE`
- 引擎：`InnoDB`
- 估算行数：`17,155`
- 数据大小：`24.55 MB`
- 索引大小：`5.31 MB`
- 自增值：`1018561`
- 排序规则：`utf8mb4_unicode_ci`
- 表注释：—

#### 字段

| 序号 | 字段                 | 类型              | 可空 | 默认值 | 键  | 额外           | 字符集  | 排序规则           | 注释 |
| ---: | -------------------- | ----------------- | ---- | ------ | --- | -------------- | ------- | ------------------ | ---- |
|    1 | `id`                 | `bigint unsigned` | 否   | —      | PRI | auto_increment | —       | —                  | —    |
|    2 | `trace_id`           | `varchar(64)`     | 是   | `NULL` | MUL | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|    3 | `domain`             | `varchar(32)`     | 否   | —      | MUL | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|    4 | `plugin_id`          | `bigint unsigned` | 是   | `NULL` | MUL | —              | —       | —                  | —    |
|    5 | `plugin_key`         | `varchar(120)`    | 否   | —      | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|    6 | `slug`               | `varchar(120)`    | 否   | —      | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|    7 | `action`             | `varchar(120)`    | 否   | —      | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|    8 | `binding_id`         | `bigint unsigned` | 是   | `NULL` | —   | —              | —       | —                  | —    |
|    9 | `bindable_type`      | `varchar(120)`    | 是   | `NULL` | MUL | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|   10 | `bindable_id`        | `bigint unsigned` | 是   | `NULL` | —   | —              | —       | —                  | —    |
|   11 | `actor_type`         | `varchar(50)`     | 是   | `NULL` | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|   12 | `actor_id`           | `bigint unsigned` | 是   | `NULL` | —   | —              | —       | —                  | —    |
|   13 | `status`             | `varchar(30)`     | 否   | —      | MUL | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|   14 | `duration_ms`        | `int unsigned`    | 是   | `NULL` | —   | —              | —       | —                  | —    |
|   15 | `error_code`         | `varchar(80)`     | 是   | `NULL` | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|   16 | `error_message`      | `varchar(500)`    | 是   | `NULL` | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|   17 | `request_meta_json`  | `json`            | 是   | `NULL` | —   | —              | —       | —                  | —    |
|   18 | `response_meta_json` | `json`            | 是   | `NULL` | —   | —              | —       | —                  | —    |
|   19 | `created_at`         | `timestamp`       | 是   | `NULL` | —   | —              | —       | —                  | —    |

#### 索引

| 索引名                                     | 唯一 | 类型    | 字段                                         |   基数 | 注释 |
| ------------------------------------------ | ---- | ------- | -------------------------------------------- | -----: | ---- |
| `plugin_runtime_bindable_idx`              | 否   | `BTREE` | `bindable_type`, `bindable_id`, `created_at` | 10,239 | —    |
| `plugin_runtime_domain_action_created_idx` | 否   | `BTREE` | `domain`, `action`, `created_at`             | 13,940 | —    |
| `plugin_runtime_plugin_created_idx`        | 否   | `BTREE` | `plugin_id`, `created_at`                    | 10,531 | —    |
| `plugin_runtime_status_created_idx`        | 否   | `BTREE` | `status`, `created_at`                       | 10,268 | —    |
| `plugin_runtime_trace_idx`                 | 否   | `BTREE` | `trace_id`                                   | 17,155 | —    |
| `PRIMARY`                                  | 是   | `BTREE` | `id`                                         | 17,155 | —    |

#### 外键约束

| 约束名                                              | 字段        | 引用表                | 引用字段 | 更新规则    | 删除规则   |
| --------------------------------------------------- | ----------- | --------------------- | -------- | ----------- | ---------- |
| `integration_plugin_runtime_logs_plugin_id_foreign` | `plugin_id` | `integration_plugins` | `id`     | `NO ACTION` | `SET NULL` |

### 2.18 `integration_plugins`

- 类型：`BASE TABLE`
- 引擎：`InnoDB`
- 估算行数：`12`
- 数据大小：`64 KB`
- 索引大小：`48 KB`
- 自增值：`23`
- 排序规则：`utf8mb4_unicode_ci`
- 表注释：—

#### 字段

| 序号 | 字段                 | 类型               | 可空 | 默认值  | 键  | 额外           | 字符集  | 排序规则           | 注释 |
| ---: | -------------------- | ------------------ | ---- | ------- | --- | -------------- | ------- | ------------------ | ---- |
|    1 | `id`                 | `bigint unsigned`  | 否   | —       | PRI | auto_increment | —       | —                  | —    |
|    2 | `domain`             | `varchar(32)`      | 否   | —       | MUL | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|    3 | `slug`               | `varchar(120)`     | 否   | —       | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|    4 | `plugin_key`         | `varchar(120)`     | 否   | —       | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|    5 | `name`               | `varchar(120)`     | 否   | —       | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|    6 | `version`            | `varchar(32)`      | 否   | `1.0.0` | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|    7 | `manifest_hash`      | `varchar(64)`      | 是   | `NULL`  | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|    8 | `provider_class`     | `varchar(255)`     | 是   | `NULL`  | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|    9 | `entry_class`        | `varchar(255)`     | 否   | —       | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|   10 | `capabilities_json`  | `json`             | 是   | `NULL`  | —   | —              | —       | —                  | —    |
|   11 | `config_schema_json` | `json`             | 是   | `NULL`  | —   | —              | —       | —                  | —    |
|   12 | `status`             | `tinyint unsigned` | 否   | `0`     | —   | —              | —       | —                  | —    |
|   13 | `installed_at`       | `timestamp`        | 是   | `NULL`  | —   | —              | —       | —                  | —    |
|   14 | `enabled_at`         | `timestamp`        | 是   | `NULL`  | —   | —              | —       | —                  | —    |
|   15 | `disabled_at`        | `timestamp`        | 是   | `NULL`  | —   | —              | —       | —                  | —    |
|   16 | `installed_by`       | `bigint unsigned`  | 是   | `NULL`  | —   | —              | —       | —                  | —    |
|   17 | `enabled_by`         | `bigint unsigned`  | 是   | `NULL`  | —   | —              | —       | —                  | —    |
|   18 | `source_hash`        | `varchar(128)`     | 是   | `NULL`  | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|   19 | `created_at`         | `timestamp`        | 是   | `NULL`  | —   | —              | —       | —                  | —    |
|   20 | `updated_at`         | `timestamp`        | 是   | `NULL`  | —   | —              | —       | —                  | —    |

#### 索引

| 索引名                                    | 唯一 | 类型    | 字段                   | 基数 | 注释 |
| ----------------------------------------- | ---- | ------- | ---------------------- | ---: | ---- |
| `integration_plugins_domain_key_unique`   | 是   | `BTREE` | `domain`, `plugin_key` |   12 | —    |
| `integration_plugins_domain_slug_unique`  | 是   | `BTREE` | `domain`, `slug`       |   12 | —    |
| `integration_plugins_domain_status_index` | 否   | `BTREE` | `domain`, `status`     |   11 | —    |
| `PRIMARY`                                 | 是   | `BTREE` | `id`                   |   12 | —    |

#### 外键约束

无数据库级外键约束。

### 2.19 `invoice_items`

- 类型：`BASE TABLE`
- 引擎：`InnoDB`
- 估算行数：`2,776`
- 数据大小：`512 KB`
- 索引大小：`96 KB`
- 自增值：`2845`
- 排序规则：`utf8mb4_unicode_ci`
- 表注释：账单明细表，记录账单内每个收费项目和快照信息

#### 字段

| 序号 | 字段              | 类型              | 可空 | 默认值   | 键  | 额外           | 字符集  | 排序规则           | 注释                                             |
| ---: | ----------------- | ----------------- | ---- | -------- | --- | -------------- | ------- | ------------------ | ------------------------------------------------ |
|    1 | `id`              | `bigint unsigned` | 否   | —        | PRI | auto_increment | —       | —                  | 账单明细自增主键                                 |
|    2 | `invoice_id`      | `bigint unsigned` | 否   | —        | MUL | —              | —       | —                  | 所属账单ID                                       |
|    3 | `item_name`       | `varchar(200)`    | 否   | —        | —   | —              | utf8mb4 | utf8mb4_unicode_ci | 明细名称                                         |
|    4 | `item_type`       | `varchar(30)`     | 否   | `normal` | —   | —              | utf8mb4 | utf8mb4_unicode_ci | 明细类型：normal/config/addon/discount/refund 等 |
|    5 | `quantity`        | `int unsigned`    | 否   | `1`      | —   | —              | —       | —                  | 明细数量                                         |
|    6 | `unit_price`      | `decimal(12,2)`   | 否   | `0.00`   | —   | —              | —       | —                  | 明细单价                                         |
|    7 | `discount_amount` | `decimal(12,2)`   | 否   | `0.00`   | —   | —              | —       | —                  | 明细优惠金额                                     |
|    8 | `line_amount`     | `decimal(12,2)`   | 否   | `0.00`   | —   | —              | —       | —                  | 明细小计金额                                     |
|    9 | `meta_json`       | `json`            | 是   | `NULL`   | —   | —              | —       | —                  | 明细扩展快照 JSON                                |
|   10 | `created_at`      | `timestamp`       | 是   | `NULL`   | —   | —              | —       | —                  | 创建时间                                         |
|   11 | `updated_at`      | `timestamp`       | 是   | `NULL`   | —   | —              | —       | —                  | 更新时间                                         |

#### 索引

| 索引名                           | 唯一 | 类型    | 字段         |  基数 | 注释 |
| -------------------------------- | ---- | ------- | ------------ | ----: | ---- |
| `invoice_items_invoice_id_index` | 否   | `BTREE` | `invoice_id` | 2,776 | —    |
| `PRIMARY`                        | 是   | `BTREE` | `id`         | 2,776 | —    |

#### 外键约束

| 约束名                        | 字段         | 引用表     | 引用字段 | 更新规则    | 删除规则  |
| ----------------------------- | ------------ | ---------- | -------- | ----------- | --------- |
| `fk_invoice_items_invoice_id` | `invoice_id` | `invoices` | `id`     | `NO ACTION` | `CASCADE` |

### 2.20 `invoices`

- 类型：`BASE TABLE`
- 引擎：`InnoDB`
- 估算行数：`2,978`
- 数据大小：`1.52 MB`
- 索引大小：`1.48 MB`
- 自增值：`3064`
- 排序规则：`utf8mb4_unicode_ci`
- 表注释：账单主表，所有购买、续费、充值、扣款和退款流程以账单为财务入口

#### 字段

| 序号 | 字段                      | 类型              | 可空 | 默认值   | 键  | 额外           | 字符集  | 排序规则           | 注释                                                                         |
| ---: | ------------------------- | ----------------- | ---- | -------- | --- | -------------- | ------- | ------------------ | ---------------------------------------------------------------------------- |
|    1 | `id`                      | `bigint unsigned` | 否   | —        | PRI | auto_increment | —       | —                  | 账单自增主键                                                                 |
|    2 | `invoice_no`              | `varchar(32)`     | 否   | —        | UNI | —              | utf8mb4 | utf8mb4_unicode_ci | 业务账单号，对外展示和支付关联使用                                           |
|    3 | `user_id`                 | `bigint unsigned` | 否   | —        | MUL | —              | —       | —                  | 所属用户ID                                                                   |
|    4 | `order_id`                | `bigint unsigned` | 是   | `NULL`   | MUL | —              | —       | —                  | 内部订单/开通投影ID，仅用于流程追踪                                          |
|    5 | `origin_invoice_id`       | `bigint unsigned` | 是   | `NULL`   | MUL | —              | —       | —                  | —                                                                            |
|    6 | `product_id`              | `bigint unsigned` | 是   | `NULL`   | MUL | —              | —       | —                  | 关联商品ID，手工账单可为空                                                   |
|    7 | `product_spec_snapshot`   | `varchar(255)`    | 是   | `NULL`   | —   | —              | utf8mb4 | utf8mb4_unicode_ci | 账单生成时的商品规格展示快照                                                 |
|    8 | `product_type_snapshot`   | `varchar(100)`    | 是   | `NULL`   | —   | —              | utf8mb4 | utf8mb4_unicode_ci | 账单生成时的商品类型快照                                                     |
|    9 | `service_id`              | `bigint unsigned` | 是   | `NULL`   | MUL | —              | —       | —                  | 关联服务实例ID                                                               |
|   10 | `coupon_id`               | `bigint unsigned` | 是   | `NULL`   | MUL | —              | —       | —                  | 使用的优惠券模板ID                                                           |
|   11 | `user_coupon_id`          | `bigint unsigned` | 是   | `NULL`   | MUL | —              | —       | —                  | 使用的用户优惠券ID                                                           |
|   12 | `coupon_code`             | `varchar(100)`    | 是   | `NULL`   | —   | —              | utf8mb4 | utf8mb4_unicode_ci | 使用的优惠码快照                                                             |
|   13 | `type`                    | `varchar(20)`     | 否   | `normal` | —   | —              | utf8mb4 | utf8mb4_unicode_ci | 账单类型：normal/new/renew/recharge/deduction/referral_credit/manual/upgrade |
|   14 | `amount`                  | `decimal(12,2)`   | 否   | —        | —   | —              | —       | —                  | 账单应收金额                                                                 |
|   15 | `currency`                | `varchar(3)`      | 否   | `CNY`    | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —                                                                            |
|   16 | `discount`                | `decimal(12,2)`   | 否   | `0.00`   | —   | —              | —       | —                  | 账单优惠抵扣金额                                                             |
|   17 | `billing_cycle`           | `varchar(30)`     | 是   | `NULL`   | —   | —              | utf8mb4 | utf8mb4_unicode_ci | 计费周期：monthly/quarterly/annually/onetime 等                              |
|   18 | `quantity`                | `int unsigned`    | 否   | `1`      | —   | —              | —       | —                  | 购买数量或计费数量                                                           |
|   19 | `config_snapshot`         | `json`            | 是   | `NULL`   | —   | —              | —       | —                  | 下单配置快照 JSON                                                            |
|   20 | `config_pricing_snapshot` | `json`            | 是   | `NULL`   | —   | —              | —       | —                  | 配置项计价快照 JSON                                                          |
|   21 | `coupon_snapshot`         | `json`            | 是   | `NULL`   | —   | —              | —       | —                  | 优惠券使用快照 JSON                                                          |
|   22 | `paid_amount`             | `decimal(12,2)`   | 否   | `0.00`   | —   | —              | —       | —                  | 已支付入账金额                                                               |
|   23 | `status`                  | `tinyint`         | 否   | `0`      | MUL | —              | —       | —                  | 账单状态：0未付 1已付 2已取消 3逾期 5已退款 6部分退款                        |
|   24 | `due_date`                | `date`            | 是   | `NULL`   | —   | —              | —       | —                  | —                                                                            |
|   25 | `paid_at`                 | `timestamp`       | 是   | `NULL`   | —   | —              | —       | —                  | 账单支付完成时间                                                             |
|   26 | `deleted_at`              | `timestamp`       | 是   | `NULL`   | —   | —              | —       | —                  | —                                                                            |
|   27 | `refund_trace_id`         | `varchar(64)`     | 是   | `NULL`   | —   | —              | utf8mb4 | utf8mb4_unicode_ci | 退款链路追踪号                                                               |
|   28 | `refund_method`           | `varchar(32)`     | 是   | `NULL`   | —   | —              | utf8mb4 | utf8mb4_unicode_ci | 退款方式                                                                     |
|   29 | `refund_amount`           | `decimal(12,2)`   | 是   | `NULL`   | —   | —              | —       | —                  | 退款金额                                                                     |
|   30 | `refunded_at`             | `timestamp`       | 是   | `NULL`   | —   | —              | —       | —                  | 退款完成时间                                                                 |
|   31 | `created_at`              | `timestamp`       | 是   | `NULL`   | —   | —              | —       | —                  | 创建时间                                                                     |
|   32 | `updated_at`              | `timestamp`       | 是   | `NULL`   | —   | —              | —       | —                  | 更新时间                                                                     |
|   33 | `remark`                  | `varchar(255)`    | 是   | `NULL`   | —   | —              | utf8mb4 | utf8mb4_unicode_ci | 账单备注                                                                     |
|   34 | `operator`                | `varchar(50)`     | 是   | `NULL`   | —   | —              | utf8mb4 | utf8mb4_unicode_ci | 操作人快照                                                                   |
|   35 | `trace_id`                | `varchar(64)`     | 是   | `NULL`   | MUL | —              | utf8mb4 | utf8mb4_unicode_ci | 链路追踪号                                                                   |

#### 索引

| 索引名                               | 唯一 | 类型    | 字段                              |  基数 | 注释 |
| ------------------------------------ | ---- | ------- | --------------------------------- | ----: | ---- |
| `fk_invoices_user_coupon_id`         | 否   | `BTREE` | `user_coupon_id`                  |     3 | —    |
| `idx_stage2_invoices_coupon_id`      | 否   | `BTREE` | `coupon_id`                       |     3 | —    |
| `invoices_invoice_no_unique`         | 是   | `BTREE` | `invoice_no`                      | 2,812 | —    |
| `invoices_order_id_idx`              | 否   | `BTREE` | `order_id`                        |   296 | —    |
| `invoices_origin_invoice_id_foreign` | 否   | `BTREE` | `origin_invoice_id`               |     1 | —    |
| `invoices_product_id_idx`            | 否   | `BTREE` | `product_id`                      |    56 | —    |
| `invoices_service_id_idx`            | 否   | `BTREE` | `service_id`                      |   137 | —    |
| `invoices_status_due_date_index`     | 否   | `BTREE` | `status`, `due_date`              |   740 | —    |
| `invoices_status_paid_at_idx`        | 否   | `BTREE` | `status`, `paid_at`               |   980 | —    |
| `invoices_trace_id_idx`              | 否   | `BTREE` | `trace_id`                        | 2,190 | —    |
| `invoices_user_status_created_idx`   | 否   | `BTREE` | `user_id`, `status`, `created_at` | 2,674 | —    |
| `invoices_user_status_id_idx`        | 否   | `BTREE` | `user_id`, `status`, `id`         | 2,812 | —    |
| `PRIMARY`                            | 是   | `BTREE` | `id`                              | 2,978 | —    |

#### 外键约束

| 约束名                               | 字段                | 引用表         | 引用字段 | 更新规则    | 删除规则   |
| ------------------------------------ | ------------------- | -------------- | -------- | ----------- | ---------- |
| `fk_invoices_order_id`               | `order_id`          | `orders`       | `id`     | `NO ACTION` | `SET NULL` |
| `fk_invoices_product_id`             | `product_id`        | `products`     | `id`     | `NO ACTION` | `RESTRICT` |
| `fk_invoices_user_coupon_id`         | `user_coupon_id`    | `user_coupons` | `id`     | `NO ACTION` | `SET NULL` |
| `fk_invoices_user_id`                | `user_id`           | `users`        | `id`     | `NO ACTION` | `RESTRICT` |
| `fk_stage2_invoices_coupon_id`       | `coupon_id`         | `coupons`      | `id`     | `NO ACTION` | `SET NULL` |
| `fk_stage2_invoices_service_id`      | `service_id`        | `services`     | `id`     | `NO ACTION` | `SET NULL` |
| `invoices_origin_invoice_id_foreign` | `origin_invoice_id` | `invoices`     | `id`     | `NO ACTION` | `SET NULL` |

### 2.21 `jobs`

- 类型：`BASE TABLE`
- 引擎：`InnoDB`
- 估算行数：`0`
- 数据大小：`16 KB`
- 索引大小：`16 KB`
- 自增值：`4732`
- 排序规则：`utf8mb4_unicode_ci`
- 表注释：—

#### 字段

| 序号 | 字段           | 类型               | 可空 | 默认值 | 键  | 额外           | 字符集  | 排序规则           | 注释 |
| ---: | -------------- | ------------------ | ---- | ------ | --- | -------------- | ------- | ------------------ | ---- |
|    1 | `id`           | `bigint unsigned`  | 否   | —      | PRI | auto_increment | —       | —                  | —    |
|    2 | `queue`        | `varchar(255)`     | 否   | —      | MUL | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|    3 | `payload`      | `longtext`         | 否   | —      | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|    4 | `attempts`     | `tinyint unsigned` | 否   | —      | —   | —              | —       | —                  | —    |
|    5 | `reserved_at`  | `int unsigned`     | 是   | `NULL` | —   | —              | —       | —                  | —    |
|    6 | `available_at` | `int unsigned`     | 否   | —      | —   | —              | —       | —                  | —    |
|    7 | `created_at`   | `int unsigned`     | 否   | —      | —   | —              | —       | —                  | —    |

#### 索引

| 索引名             | 唯一 | 类型    | 字段    | 基数 | 注释 |
| ------------------ | ---- | ------- | ------- | ---: | ---- |
| `jobs_queue_index` | 否   | `BTREE` | `queue` |    1 | —    |
| `PRIMARY`          | 是   | `BTREE` | `id`    |    9 | —    |

#### 外键约束

无数据库级外键约束。

### 2.22 `media_files`

- 类型：`BASE TABLE`
- 引擎：`InnoDB`
- 估算行数：`12`
- 数据大小：`16 KB`
- 索引大小：`48 KB`
- 自增值：`62`
- 排序规则：`utf8mb4_unicode_ci`
- 表注释：—

#### 字段

| 序号 | 字段          | 类型              | 可空 | 默认值    | 键  | 额外           | 字符集  | 排序规则           | 注释                                                 |
| ---: | ------------- | ----------------- | ---- | --------- | --- | -------------- | ------- | ------------------ | ---------------------------------------------------- |
|    1 | `id`          | `bigint unsigned` | 否   | —         | PRI | auto_increment | —       | —                  | —                                                    |
|    2 | `filename`    | `varchar(255)`    | 否   | —         | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —                                                    |
|    3 | `path`        | `varchar(500)`    | 否   | —         | —   | —              | utf8mb4 | utf8mb4_unicode_ci | 相对路径，如 /uploads/content/20260419/cover_xxx.jpg |
|    4 | `url`         | `varchar(500)`    | 否   | —         | —   | —              | utf8mb4 | utf8mb4_unicode_ci | 完整访问 URL                                         |
|    5 | `mime_type`   | `varchar(100)`    | 是   | `NULL`    | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —                                                    |
|    6 | `size`        | `bigint unsigned` | 否   | `0`       | —   | —              | —       | —                  | 文件大小(字节)                                       |
|    7 | `width`       | `int unsigned`    | 是   | `NULL`    | —   | —              | —       | —                  | 图片宽度                                             |
|    8 | `height`      | `int unsigned`    | 是   | `NULL`    | —   | —              | —       | —                  | 图片高度                                             |
|    9 | `group`       | `varchar(50)`     | 否   | `content` | MUL | —              | utf8mb4 | utf8mb4_unicode_ci | 分组: content, avatar, brand 等                      |
|   10 | `uploaded_by` | `bigint unsigned` | 否   | `0`       | MUL | —              | —       | —                  | 上传管理员ID                                         |
|   11 | `created_at`  | `timestamp`       | 是   | `NULL`    | MUL | —              | —       | —                  | —                                                    |
|   12 | `updated_at`  | `timestamp`       | 是   | `NULL`    | —   | —              | —       | —                  | —                                                    |

#### 索引

| 索引名                          | 唯一 | 类型    | 字段          | 基数 | 注释 |
| ------------------------------- | ---- | ------- | ------------- | ---: | ---- |
| `media_files_created_at_index`  | 否   | `BTREE` | `created_at`  |    1 | —    |
| `media_files_group_index`       | 否   | `BTREE` | `group`       |    2 | —    |
| `media_files_uploaded_by_index` | 否   | `BTREE` | `uploaded_by` |    1 | —    |
| `PRIMARY`                       | 是   | `BTREE` | `id`          |   12 | —    |

#### 外键约束

无数据库级外键约束。

### 2.23 `member_levels`

- 类型：`BASE TABLE`
- 引擎：`InnoDB`
- 估算行数：`3`
- 数据大小：`16 KB`
- 索引大小：`48 KB`
- 自增值：`9`
- 排序规则：`utf8mb4_unicode_ci`
- 表注释：—

#### 字段

| 序号 | 字段               | 类型              | 可空 | 默认值 | 键  | 额外           | 字符集  | 排序规则           | 注释          |
| ---: | ------------------ | ----------------- | ---- | ------ | --- | -------------- | ------- | ------------------ | ------------- |
|    1 | `id`               | `bigint unsigned` | 否   | —      | PRI | auto_increment | —       | —                  | —             |
|    2 | `name`             | `varchar(50)`     | 否   | —      | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —             |
|    3 | `code`             | `varchar(30)`     | 否   | —      | UNI | —              | utf8mb4 | utf8mb4_unicode_ci | —             |
|    4 | `sales_amount_min` | `decimal(12,2)`   | 否   | `0.00` | MUL | —              | —       | —                  | —             |
|    5 | `sales_amount_max` | `decimal(12,2)`   | 是   | `NULL` | —   | —              | —       | —                  | —             |
|    6 | `reward_rate`      | `decimal(5,2)`    | 否   | `0.00` | —   | —              | —       | —                  | —             |
|    7 | `status`           | `tinyint`         | 否   | `1`    | MUL | —              | —       | —                  | 0=禁用 1=启用 |
|    8 | `sort_order`       | `int`             | 否   | `0`    | —   | —              | —       | —                  | —             |
|    9 | `remark`           | `varchar(255)`    | 是   | `NULL` | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —             |
|   10 | `created_at`       | `timestamp`       | 是   | `NULL` | —   | —              | —       | —                  | —             |
|   11 | `updated_at`       | `timestamp`       | 是   | `NULL` | —   | —              | —       | —                  | —             |

#### 索引

| 索引名                         | 唯一 | 类型    | 字段                                   | 基数 | 注释 |
| ------------------------------ | ---- | ------- | -------------------------------------- | ---: | ---- |
| `idx_member_level_sales_range` | 否   | `BTREE` | `sales_amount_min`, `sales_amount_max` |    3 | —    |
| `idx_member_level_status_sort` | 否   | `BTREE` | `status`, `sort_order`                 |    1 | —    |
| `member_levels_code_unique`    | 是   | `BTREE` | `code`                                 |    3 | —    |
| `PRIMARY`                      | 是   | `BTREE` | `id`                                   |    3 | —    |

#### 外键约束

无数据库级外键约束。

### 2.24 `message_logs`

- 类型：`BASE TABLE`
- 引擎：`InnoDB`
- 估算行数：`783`
- 数据大小：`12.52 MB`
- 索引大小：`1,008 KB`
- 自增值：`3057`
- 排序规则：`utf8mb4_unicode_ci`
- 表注释：—

#### 字段

| 序号 | 字段            | 类型              | 可空 | 默认值    | 键  | 额外           | 字符集  | 排序规则           | 注释                       |
| ---: | --------------- | ----------------- | ---- | --------- | --- | -------------- | ------- | ------------------ | -------------------------- |
|    1 | `id`            | `bigint unsigned` | 否   | —         | PRI | auto_increment | —       | —                  | 消息日志ID                 |
|    2 | `plugin_id`     | `bigint unsigned` | 是   | `NULL`    | MUL | —              | —       | —                  | 插件ID                     |
|    3 | `driver_key`    | `varchar(120)`    | 是   | `NULL`    | MUL | —              | utf8mb4 | utf8mb4_unicode_ci | 驱动标识                   |
|    4 | `trace_id`      | `varchar(64)`     | 是   | `NULL`    | MUL | —              | utf8mb4 | utf8mb4_unicode_ci | 链路追踪ID                 |
|    5 | `channel`       | `varchar(20)`     | 否   | —         | MUL | —              | utf8mb4 | utf8mb4_unicode_ci | 消息渠道：email/sms        |
|    6 | `recipient`     | `varchar(255)`    | 否   | —         | MUL | —              | utf8mb4 | utf8mb4_unicode_ci | 接收人邮箱或手机号         |
|    7 | `template_code` | `varchar(120)`    | 是   | `NULL`    | —   | —              | utf8mb4 | utf8mb4_unicode_ci | 业务模板编码或供应商模板ID |
|    8 | `subject`       | `varchar(255)`    | 是   | `NULL`    | —   | —              | utf8mb4 | utf8mb4_unicode_ci | 邮件主题                   |
|    9 | `content`       | `mediumtext`      | 否   | —         | —   | —              | utf8mb4 | utf8mb4_unicode_ci | 发送内容快照               |
|   10 | `params_json`   | `json`            | 是   | `NULL`    | —   | —              | —       | —                  | 渲染参数快照               |
|   11 | `provider`      | `varchar(120)`    | 是   | `NULL`    | —   | —              | utf8mb4 | utf8mb4_unicode_ci | 供应商或驱动               |
|   12 | `request_id`    | `varchar(100)`    | 是   | `NULL`    | MUL | —              | utf8mb4 | utf8mb4_unicode_ci | 供应商请求ID               |
|   13 | `status`        | `varchar(20)`     | 否   | `pending` | —   | —              | utf8mb4 | utf8mb4_unicode_ci | 发送状态                   |
|   14 | `error_msg`     | `text`            | 是   | `NULL`    | —   | —              | utf8mb4 | utf8mb4_unicode_ci | 失败原因                   |
|   15 | `sent_at`       | `timestamp`       | 是   | `NULL`    | —   | —              | —       | —                  | 发送完成时间               |
|   16 | `origin_type`   | `varchar(50)`     | 是   | `NULL`    | MUL | —              | utf8mb4 | utf8mb4_unicode_ci | 来源类型快照               |
|   17 | `origin_id`     | `bigint unsigned` | 是   | `NULL`    | —   | —              | —       | —                  | 来源ID快照                 |
|   18 | `created_at`    | `timestamp`       | 是   | `NULL`    | —   | —              | —       | —                  | —                          |
|   19 | `updated_at`    | `timestamp`       | 是   | `NULL`    | —   | —              | —       | —                  | —                          |

#### 索引

| 索引名                                  | 唯一 | 类型    | 字段                                  | 基数 | 注释 |
| --------------------------------------- | ---- | ------- | ------------------------------------- | ---: | ---- |
| `message_logs_channel_driver_idx`       | 否   | `BTREE` | `channel`, `driver_key`, `created_at` |  783 | —    |
| `message_logs_driver_created_idx`       | 否   | `BTREE` | `driver_key`, `created_at`            |  783 | —    |
| `message_logs_origin_idx`               | 否   | `BTREE` | `origin_type`, `origin_id`            |  268 | —    |
| `message_logs_plugin_created_idx`       | 否   | `BTREE` | `plugin_id`, `created_at`             |  783 | —    |
| `message_logs_recipient_created_at_idx` | 否   | `BTREE` | `recipient`, `created_at`             |  783 | —    |
| `message_logs_request_id_idx`           | 否   | `BTREE` | `request_id`                          |   23 | —    |
| `message_logs_trace_idx`                | 否   | `BTREE` | `trace_id`                            |  783 | —    |
| `PRIMARY`                               | 是   | `BTREE` | `id`                                  |  783 | —    |

#### 外键约束

| 约束名                   | 字段        | 引用表                | 引用字段 | 更新规则    | 删除规则   |
| ------------------------ | ----------- | --------------------- | -------- | ----------- | ---------- |
| `message_logs_plugin_fk` | `plugin_id` | `integration_plugins` | `id`     | `NO ACTION` | `SET NULL` |

### 2.25 `migrations`

- 类型：`BASE TABLE`
- 引擎：`InnoDB`
- 估算行数：`175`
- 数据大小：`16 KB`
- 索引大小：`0 B`
- 自增值：`188`
- 排序规则：`utf8mb4_unicode_ci`
- 表注释：—

#### 字段

| 序号 | 字段        | 类型           | 可空 | 默认值 | 键  | 额外           | 字符集  | 排序规则           | 注释 |
| ---: | ----------- | -------------- | ---- | ------ | --- | -------------- | ------- | ------------------ | ---- |
|    1 | `id`        | `int unsigned` | 否   | —      | PRI | auto_increment | —       | —                  | —    |
|    2 | `migration` | `varchar(255)` | 否   | —      | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|    3 | `batch`     | `int`          | 否   | —      | —   | —              | —       | —                  | —    |

#### 索引

| 索引名    | 唯一 | 类型    | 字段 | 基数 | 注释 |
| --------- | ---- | ------- | ---- | ---: | ---- |
| `PRIMARY` | 是   | `BTREE` | `id` |  174 | —    |

#### 外键约束

无数据库级外键约束。

### 2.26 `notice_reads`

- 类型：`BASE TABLE`
- 引擎：`InnoDB`
- 估算行数：`142`
- 数据大小：`16 KB`
- 索引大小：`48 KB`
- 自增值：`185`
- 排序规则：`utf8mb4_unicode_ci`
- 表注释：—

#### 字段

| 序号 | 字段         | 类型              | 可空 | 默认值              | 键  | 额外              | 字符集 | 排序规则 | 注释 |
| ---: | ------------ | ----------------- | ---- | ------------------- | --- | ----------------- | ------ | -------- | ---- |
|    1 | `id`         | `bigint unsigned` | 否   | —                   | PRI | auto_increment    | —      | —        | —    |
|    2 | `user_id`    | `bigint unsigned` | 否   | —                   | MUL | —                 | —      | —        | —    |
|    3 | `article_id` | `bigint unsigned` | 否   | —                   | MUL | —                 | —      | —        | —    |
|    4 | `read_at`    | `timestamp`       | 否   | —                   | —   | —                 | —      | —        | —    |
|    5 | `created_at` | `timestamp`       | 否   | `CURRENT_TIMESTAMP` | —   | DEFAULT_GENERATED | —      | —        | —    |

#### 索引

| 索引名                                   | 唯一 | 类型    | 字段                    | 基数 | 注释 |
| ---------------------------------------- | ---- | ------- | ----------------------- | ---: | ---- |
| `notice_reads_article_id_index`          | 否   | `BTREE` | `article_id`            |   10 | —    |
| `notice_reads_user_id_article_id_unique` | 是   | `BTREE` | `user_id`, `article_id` |  142 | —    |
| `PRIMARY`                                | 是   | `BTREE` | `id`                    |  142 | —    |

#### 外键约束

| 约束名                              | 字段         | 引用表             | 引用字段 | 更新规则    | 删除规则  |
| ----------------------------------- | ------------ | ------------------ | -------- | ----------- | --------- |
| `fk_stage2_notice_reads_article_id` | `article_id` | `content_articles` | `id`     | `NO ACTION` | `CASCADE` |
| `fk_stage2_notice_reads_user_id`    | `user_id`    | `users`            | `id`     | `NO ACTION` | `CASCADE` |

### 2.27 `notification_templates`

- 类型：`BASE TABLE`
- 引擎：`InnoDB`
- 估算行数：`56`
- 数据大小：`272 KB`
- 索引大小：`32 KB`
- 自增值：`981`
- 排序规则：`utf8mb4_unicode_ci`
- 表注释：—

#### 字段

| 序号 | 字段                      | 类型              | 可空 | 默认值   | 键  | 额外           | 字符集  | 排序规则           | 注释 |
| ---: | ------------------------- | ----------------- | ---- | -------- | --- | -------------- | ------- | ------------------ | ---- |
|    1 | `id`                      | `bigint unsigned` | 否   | —        | PRI | auto_increment | —       | —                  | —    |
|    2 | `channel`                 | `varchar(20)`     | 否   | —        | MUL | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|    3 | `code`                    | `varchar(64)`     | 否   | —        | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|    4 | `name`                    | `varchar(120)`    | 否   | —        | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|    5 | `description`             | `varchar(500)`    | 否   | 空字符串 | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|    6 | `audience`                | `varchar(20)`     | 否   | `user`   | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|    7 | `subject`                 | `varchar(255)`    | 是   | `NULL`   | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|    8 | `content`                 | `longtext`        | 否   | —        | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|    9 | `variables_json`          | `json`            | 是   | `NULL`   | —   | —              | —       | —                  | —    |
|   10 | `provider_variables_json` | `json`            | 是   | `NULL`   | —   | —              | —       | —                  | —    |
|   11 | `provider_template_id`    | `varchar(120)`    | 是   | `NULL`   | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|   12 | `is_enabled`              | `tinyint(1)`      | 否   | `1`      | —   | —              | —       | —                  | —    |
|   13 | `is_custom`               | `tinyint(1)`      | 否   | `0`      | —   | —              | —       | —                  | —    |
|   14 | `sort_order`              | `int unsigned`    | 否   | `0`      | —   | —              | —       | —                  | —    |
|   15 | `created_at`              | `timestamp`       | 是   | `NULL`   | —   | —              | —       | —                  | —    |
|   16 | `updated_at`              | `timestamp`       | 是   | `NULL`   | —   | —              | —       | —                  | —    |

#### 索引

| 索引名                                                  | 唯一 | 类型    | 字段                                | 基数 | 注释 |
| ------------------------------------------------------- | ---- | ------- | ----------------------------------- | ---: | ---- |
| `notification_templates_channel_audience_enabled_index` | 否   | `BTREE` | `channel`, `audience`, `is_enabled` |    3 | —    |
| `notification_templates_channel_code_unique`            | 是   | `BTREE` | `channel`, `code`                   |   56 | —    |
| `PRIMARY`                                               | 是   | `BTREE` | `id`                                |   56 | —    |

#### 外键约束

无数据库级外键约束。

### 2.28 `operation_logs`

- 类型：`BASE TABLE`
- 引擎：`InnoDB`
- 估算行数：`181,260`
- 数据大小：`78.61 MB`
- 索引大小：`37.09 MB`
- 自增值：`178740`
- 排序规则：`utf8mb4_unicode_ci`
- 表注释：—

#### 字段

| 序号 | 字段         | 类型              | 可空 | 默认值              | 键  | 额外              | 字符集  | 排序规则           | 注释              |
| ---: | ------------ | ----------------- | ---- | ------------------- | --- | ----------------- | ------- | ------------------ | ----------------- |
|    1 | `id`         | `bigint unsigned` | 否   | —                   | PRI | auto_increment    | —       | —                  | —                 |
|    2 | `user_id`    | `bigint unsigned` | 是   | `NULL`              | MUL | —                 | —       | —                  | —                 |
|    3 | `user_type`  | `varchar(10)`     | 是   | `NULL`              | —   | —                 | utf8mb4 | utf8mb4_unicode_ci | admin&#124;client |
|    4 | `action`     | `varchar(100)`    | 否   | —                   | —   | —                 | utf8mb4 | utf8mb4_unicode_ci | —                 |
|    5 | `module`     | `varchar(50)`     | 是   | `NULL`              | MUL | —                 | utf8mb4 | utf8mb4_unicode_ci | —                 |
|    6 | `subject_id` | `bigint unsigned` | 是   | `NULL`              | —   | —                 | —       | —                  | —                 |
|    7 | `context`    | `json`            | 是   | `NULL`              | —   | —                 | —       | —                  | —                 |
|    8 | `ip_address` | `varchar(45)`     | 是   | `NULL`              | —   | —                 | utf8mb4 | utf8mb4_unicode_ci | —                 |
|    9 | `created_at` | `timestamp`       | 否   | `CURRENT_TIMESTAMP` | MUL | DEFAULT_GENERATED | —       | —                  | —                 |

#### 索引

| 索引名                                      | 唯一 | 类型    | 字段                                       |    基数 | 注释 |
| ------------------------------------------- | ---- | ------- | ------------------------------------------ | ------: | ---- |
| `operation_logs_created_at_idx`             | 否   | `BTREE` | `created_at`                               |   4,868 | —    |
| `operation_logs_module_created_at_index`    | 否   | `BTREE` | `module`, `created_at`                     |   7,510 | —    |
| `operation_logs_module_subject_created_idx` | 否   | `BTREE` | `module`, `subject_id`, `created_at`, `id` | 174,241 | —    |
| `operation_logs_user_created_at_idx`        | 否   | `BTREE` | `user_id`, `created_at`                    |   7,064 | —    |
| `operation_logs_user_type_created_at_idx`   | 否   | `BTREE` | `user_id`, `user_type`, `created_at`       |   6,661 | —    |
| `PRIMARY`                                   | 是   | `BTREE` | `id`                                       | 181,027 | —    |

#### 外键约束

无数据库级外键约束。

### 2.29 `orders`

- 类型：`BASE TABLE`
- 引擎：`InnoDB`
- 估算行数：`291`
- 数据大小：`352 KB`
- 索引大小：`176 KB`
- 自增值：`3124`
- 排序规则：`utf8mb4_unicode_ci`
- 表注释：—

#### 字段

| 序号 | 字段                      | 类型              | 可空 | 默认值         | 键  | 额外           | 字符集  | 排序规则           | 注释                                                  |
| ---: | ------------------------- | ----------------- | ---- | -------------- | --- | -------------- | ------- | ------------------ | ----------------------------------------------------- |
|    1 | `id`                      | `bigint unsigned` | 否   | —              | PRI | auto_increment | —       | —                  | —                                                     |
|    2 | `order_no`                | `varchar(32)`     | 否   | —              | UNI | —              | utf8mb4 | utf8mb4_unicode_ci | —                                                     |
|    3 | `user_id`                 | `bigint unsigned` | 否   | —              | MUL | —              | —       | —                  | —                                                     |
|    4 | `product_id`              | `bigint unsigned` | 是   | `NULL`         | MUL | —              | —       | —                  | —                                                     |
|    5 | `product_spec_snapshot`   | `varchar(200)`    | 是   | `NULL`         | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —                                                     |
|    6 | `product_type_snapshot`   | `varchar(50)`     | 是   | `NULL`         | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —                                                     |
|    7 | `service_id`              | `bigint unsigned` | 是   | `NULL`         | MUL | —              | —       | —                  | —                                                     |
|    8 | `type`                    | `varchar(20)`     | 否   | —              | —   | —              | utf8mb4 | utf8mb4_unicode_ci | new&#124;renew&#124;upgrade&#124;downgrade            |
|    9 | `coupon_id`               | `bigint unsigned` | 是   | `NULL`         | MUL | —              | —       | —                  | —                                                     |
|   10 | `user_coupon_id`          | `bigint unsigned` | 是   | `NULL`         | MUL | —              | —       | —                  | —                                                     |
|   11 | `coupon_code`             | `varchar(50)`     | 是   | `NULL`         | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —                                                     |
|   12 | `amount`                  | `decimal(12,2)`   | 否   | —              | —   | —              | —       | —                  | —                                                     |
|   13 | `currency`                | `varchar(3)`      | 否   | `CNY`          | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —                                                     |
|   14 | `discount`                | `decimal(12,2)`   | 否   | `0.00`         | —   | —              | —       | —                  | —                                                     |
|   15 | `paid_amount`             | `decimal(12,2)`   | 否   | `0.00`         | —   | —              | —       | —                  | —                                                     |
|   16 | `billing_cycle`           | `varchar(20)`     | 是   | `NULL`         | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —                                                     |
|   17 | `quantity`                | `int unsigned`    | 否   | `1`            | —   | —              | —       | —                  | —                                                     |
|   18 | `config_snapshot`         | `json`            | 是   | `NULL`         | —   | —              | —       | —                  | —                                                     |
|   19 | `config_pricing_snapshot` | `json`            | 是   | `NULL`         | —   | —              | —       | —                  | —                                                     |
|   20 | `coupon_snapshot`         | `json`            | 是   | `NULL`         | —   | —              | —       | —                  | —                                                     |
|   21 | `service_snapshot`        | `json`            | 是   | `NULL`         | —   | —              | —       | —                  | 服务实例快照：{instance_id, hostname}                 |
|   22 | `status`                  | `tinyint`         | 否   | `0`            | MUL | —              | —       | —                  | 0=待付款 1=已付款 2=开通中 3=已完成 4=已取消 5=已退款 |
|   23 | `paid_at`                 | `timestamp`       | 是   | `NULL`         | —   | —              | —       | —                  | —                                                     |
|   24 | `deleted_at`              | `timestamp`       | 是   | `NULL`         | —   | —              | —       | —                  | —                                                     |
|   25 | `created_at`              | `timestamp`       | 是   | `NULL`         | MUL | —              | —       | —                  | —                                                     |
|   26 | `updated_at`              | `timestamp`       | 是   | `NULL`         | —   | —              | —       | —                  | —                                                     |
|   27 | `remark`                  | `varchar(255)`    | 是   | `NULL`         | —   | —              | utf8mb4 | utf8mb4_unicode_ci | 备注                                                  |
|   28 | `operator`                | `varchar(50)`     | 是   | `NULL`         | —   | —              | utf8mb4 | utf8mb4_unicode_ci | 操作人                                                |
|   29 | `trace_id`                | `varchar(64)`     | 是   | `NULL`         | MUL | —              | utf8mb4 | utf8mb4_unicode_ci | 链路追踪号                                            |
|   30 | `projection_type`         | `varchar(32)`     | 否   | `provisioning` | MUL | —              | utf8mb4 | utf8mb4_unicode_ci | 内部投影类型：provisioning=开通投影                   |

#### 索引

| 索引名                              | 唯一 | 类型    | 字段                                 | 基数 | 注释 |
| ----------------------------------- | ---- | ------- | ------------------------------------ | ---: | ---- |
| `orders_coupon_id_idx`              | 否   | `BTREE` | `coupon_id`                          |    3 | —    |
| `orders_created_at_idx`             | 否   | `BTREE` | `created_at`                         |  291 | —    |
| `orders_order_no_unique`            | 是   | `BTREE` | `order_no`                           |  291 | —    |
| `orders_product_id_idx`             | 否   | `BTREE` | `product_id`                         |   54 | —    |
| `orders_projection_type_idx`        | 否   | `BTREE` | `projection_type`                    |    1 | —    |
| `orders_service_status_id_idx`      | 否   | `BTREE` | `service_id`, `status`, `id`         |  291 | —    |
| `orders_status_type_created_at_idx` | 否   | `BTREE` | `status`, `type`, `created_at`, `id` |  291 | —    |
| `orders_trace_id_idx`               | 否   | `BTREE` | `trace_id`                           |  121 | —    |
| `orders_user_coupon_id_idx`         | 否   | `BTREE` | `user_coupon_id`                     |    3 | —    |
| `orders_user_id_index`              | 否   | `BTREE` | `user_id`                            |  105 | —    |
| `orders_user_status_id_idx`         | 否   | `BTREE` | `user_id`, `status`, `id`            |  291 | —    |
| `PRIMARY`                           | 是   | `BTREE` | `id`                                 |  291 | —    |

#### 外键约束

| 约束名                            | 字段             | 引用表         | 引用字段 | 更新规则    | 删除规则   |
| --------------------------------- | ---------------- | -------------- | -------- | ----------- | ---------- |
| `fk_stage2_orders_coupon_id`      | `coupon_id`      | `coupons`      | `id`     | `NO ACTION` | `SET NULL` |
| `fk_stage2_orders_product_id`     | `product_id`     | `products`     | `id`     | `NO ACTION` | `RESTRICT` |
| `fk_stage2_orders_service_id`     | `service_id`     | `services`     | `id`     | `NO ACTION` | `SET NULL` |
| `fk_stage2_orders_user_coupon_id` | `user_coupon_id` | `user_coupons` | `id`     | `NO ACTION` | `SET NULL` |
| `fk_stage2_orders_user_id`        | `user_id`        | `users`        | `id`     | `NO ACTION` | `RESTRICT` |

### 2.30 `password_reset_tokens`

- 类型：`BASE TABLE`
- 引擎：`InnoDB`
- 估算行数：`0`
- 数据大小：`16 KB`
- 索引大小：`0 B`
- 自增值：—
- 排序规则：`utf8mb4_unicode_ci`
- 表注释：—

#### 字段

| 序号 | 字段         | 类型           | 可空 | 默认值 | 键  | 额外 | 字符集  | 排序规则           | 注释 |
| ---: | ------------ | -------------- | ---- | ------ | --- | ---- | ------- | ------------------ | ---- |
|    1 | `email`      | `varchar(255)` | 否   | —      | PRI | —    | utf8mb4 | utf8mb4_unicode_ci | —    |
|    2 | `token`      | `varchar(255)` | 否   | —      | —   | —    | utf8mb4 | utf8mb4_unicode_ci | —    |
|    3 | `created_at` | `timestamp`    | 是   | `NULL` | —   | —    | —       | —                  | —    |

#### 索引

| 索引名    | 唯一 | 类型    | 字段    | 基数 | 注释 |
| --------- | ---- | ------- | ------- | ---: | ---- |
| `PRIMARY` | 是   | `BTREE` | `email` |    0 | —    |

#### 外键约束

无数据库级外键约束。

### 2.31 `payment_callbacks`

- 类型：`BASE TABLE`
- 引擎：`InnoDB`
- 估算行数：`328`
- 数据大小：`320 KB`
- 索引大小：`96 KB`
- 自增值：`500`
- 排序规则：`utf8mb4_unicode_ci`
- 表注释：支付回调审计表，保存第三方通知、查询、退款等回调验签结果

#### 字段

| 序号 | 字段               | 类型              | 可空 | 默认值 | 键  | 额外           | 字符集  | 排序规则           | 注释                             |
| ---: | ------------------ | ----------------- | ---- | ------ | --- | -------------- | ------- | ------------------ | -------------------------------- |
|    1 | `id`               | `bigint unsigned` | 否   | —      | PRI | auto_increment | —       | —                  | 支付回调自增主键                 |
|    2 | `payment_id`       | `bigint unsigned` | 否   | —      | MUL | —              | —       | —                  | 关联支付记录ID                   |
|    3 | `plugin_id`        | `bigint unsigned` | 是   | `NULL` | MUL | —              | —       | —                  | —                                |
|    4 | `gateway_key`      | `varchar(120)`    | 是   | `NULL` | MUL | —              | utf8mb4 | utf8mb4_unicode_ci | —                                |
|    5 | `callback_type`    | `varchar(20)`     | 否   | —      | —   | —              | utf8mb4 | utf8mb4_unicode_ci | 回调类型：notify/query/refund 等 |
|    6 | `gateway_trade_no` | `varchar(100)`    | 是   | `NULL` | MUL | —              | utf8mb4 | utf8mb4_unicode_ci | 第三方交易号                     |
|    7 | `payload_json`     | `json`            | 是   | `NULL` | —   | —              | —       | —                  | 回调载荷 JSON                    |
|    8 | `is_verified`      | `tinyint`         | 否   | `0`    | MUL | —              | —       | —                  | 验签结果：0未通过/未验签 1已通过 |
|    9 | `received_at`      | `timestamp`       | 是   | `NULL` | —   | —              | —       | —                  | 收到回调时间                     |
|   10 | `remark`           | `varchar(255)`    | 是   | `NULL` | —   | —              | utf8mb4 | utf8mb4_unicode_ci | 回调备注或处理说明               |
|   11 | `operator`         | `varchar(50)`     | 是   | `NULL` | —   | —              | utf8mb4 | utf8mb4_unicode_ci | 操作人快照                       |
|   12 | `trace_id`         | `varchar(64)`     | 是   | `NULL` | MUL | —              | utf8mb4 | utf8mb4_unicode_ci | 链路追踪号                       |
|   13 | `created_at`       | `timestamp`       | 是   | `NULL` | —   | —              | —       | —                  | 创建时间                         |
|   14 | `updated_at`       | `timestamp`       | 是   | `NULL` | —   | —              | —       | —                  | 更新时间                         |

#### 索引

| 索引名                                    | 唯一 | 类型    | 字段                          | 基数 | 注释 |
| ----------------------------------------- | ---- | ------- | ----------------------------- | ---: | ---- |
| `payment_callbacks_gateway_key_idx`       | 否   | `BTREE` | `gateway_key`, `received_at`  |  318 | —    |
| `payment_callbacks_gateway_trade_no_idx`  | 否   | `BTREE` | `gateway_trade_no`            |  217 | —    |
| `payment_callbacks_payment_type_unique`   | 是   | `BTREE` | `payment_id`, `callback_type` |  328 | —    |
| `payment_callbacks_plugin_received_idx`   | 否   | `BTREE` | `plugin_id`, `received_at`    |  318 | —    |
| `payment_callbacks_trace_id_idx`          | 否   | `BTREE` | `trace_id`                    |  126 | —    |
| `payment_callbacks_verified_received_idx` | 否   | `BTREE` | `is_verified`, `received_at`  |  327 | —    |
| `PRIMARY`                                 | 是   | `BTREE` | `id`                          |  328 | —    |

#### 外键约束

| 约束名                            | 字段         | 引用表                | 引用字段 | 更新规则    | 删除规则   |
| --------------------------------- | ------------ | --------------------- | -------- | ----------- | ---------- |
| `fk_payment_callbacks_payment_id` | `payment_id` | `payments`            | `id`     | `NO ACTION` | `CASCADE`  |
| `payment_callbacks_plugin_fk`     | `plugin_id`  | `integration_plugins` | `id`     | `NO ACTION` | `SET NULL` |

### 2.32 `payments`

- 类型：`BASE TABLE`
- 引擎：`InnoDB`
- 估算行数：`319`
- 数据大小：`320 KB`
- 索引大小：`160 KB`
- 自增值：`375`
- 排序规则：`utf8mb4_unicode_ci`
- 表注释：第三方支付记录表，仅记录真实外部资金流入和退款状态，不记录余额/免费/手工开服

#### 字段

| 序号 | 字段           | 类型              | 可空 | 默认值 | 键  | 额外           | 字符集  | 排序规则           | 注释                                          |
| ---: | -------------- | ----------------- | ---- | ------ | --- | -------------- | ------- | ------------------ | --------------------------------------------- |
|    1 | `id`           | `bigint unsigned` | 否   | —      | PRI | auto_increment | —       | —                  | 支付记录自增主键                              |
|    2 | `payment_no`   | `varchar(32)`     | 否   | —      | UNI | —              | utf8mb4 | utf8mb4_unicode_ci | 内部支付单号                                  |
|    3 | `user_id`      | `bigint unsigned` | 否   | —      | MUL | —              | —       | —                  | 支付用户ID                                    |
|    4 | `order_id`     | `bigint unsigned` | 是   | `NULL` | MUL | —              | —       | —                  | 内部订单/开通投影ID，仅用于流程追踪           |
|    5 | `invoice_id`   | `bigint unsigned` | 是   | `NULL` | MUL | —              | —       | —                  | 关联账单ID                                    |
|    6 | `plugin_id`    | `bigint unsigned` | 是   | `NULL` | MUL | —              | —       | —                  | —                                             |
|    7 | `gateway_key`  | `varchar(120)`    | 是   | `NULL` | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —                                             |
|    8 | `trade_no`     | `varchar(100)`    | 是   | `NULL` | MUL | —              | utf8mb4 | utf8mb4_unicode_ci | 第三方交易号                                  |
|    9 | `amount`       | `decimal(12,2)`   | 否   | —      | —   | —              | —       | —                  | 第三方支付金额                                |
|   10 | `currency`     | `varchar(3)`      | 否   | `CNY`  | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —                                             |
|   11 | `status`       | `tinyint`         | 否   | `0`    | MUL | —              | —       | —                  | 支付状态：0待支付 1成功 2失败 3已退款 4已取消 |
|   12 | `callback_raw` | `json`            | 是   | `NULL` | —   | —              | —       | —                  | 最近一次回调原始载荷 JSON                     |
|   13 | `paid_at`      | `timestamp`       | 是   | `NULL` | —   | —              | —       | —                  | 第三方确认支付时间                            |
|   14 | `deleted_at`   | `timestamp`       | 是   | `NULL` | —   | —              | —       | —                  | —                                             |
|   15 | `created_at`   | `timestamp`       | 是   | `NULL` | —   | —              | —       | —                  | 创建时间                                      |
|   16 | `updated_at`   | `timestamp`       | 是   | `NULL` | —   | —              | —       | —                  | 更新时间                                      |
|   17 | `remark`       | `varchar(255)`    | 是   | `NULL` | —   | —              | utf8mb4 | utf8mb4_unicode_ci | 支付备注                                      |
|   18 | `operator`     | `varchar(50)`     | 是   | `NULL` | —   | —              | utf8mb4 | utf8mb4_unicode_ci | 操作人快照                                    |
|   19 | `trace_id`     | `varchar(64)`     | 是   | `NULL` | MUL | —              | utf8mb4 | utf8mb4_unicode_ci | 链路追踪号                                    |

#### 索引

| 索引名                                   | 唯一 | 类型    | 字段                                       | 基数 | 注释 |
| ---------------------------------------- | ---- | ------- | ------------------------------------------ | ---: | ---- |
| `payments_invoice_gateway_status_id_idx` | 否   | `BTREE` | `invoice_id`, `status`, `id`               |  319 | —    |
| `payments_invoice_status_created_at_idx` | 否   | `BTREE` | `invoice_id`, `status`, `created_at`, `id` |  319 | —    |
| `payments_order_status_idx`              | 否   | `BTREE` | `order_id`, `status`                       |  174 | —    |
| `payments_payment_no_unique`             | 是   | `BTREE` | `payment_no`                               |  319 | —    |
| `payments_plugin_status_paid_idx`        | 否   | `BTREE` | `plugin_id`, `status`, `paid_at`           |  268 | —    |
| `payments_plugin_trade_unique`           | 是   | `BTREE` | `plugin_id`, `gateway_key`, `trade_no`     |  218 | —    |
| `payments_status_paid_at_idx`            | 否   | `BTREE` | `status`, `paid_at`                        |  266 | —    |
| `payments_trace_id_idx`                  | 否   | `BTREE` | `trace_id`                                 |  129 | —    |
| `payments_trade_no_index`                | 否   | `BTREE` | `trade_no`                                 |  217 | —    |
| `payments_user_status_created_idx`       | 否   | `BTREE` | `user_id`, `status`, `created_at`          |  319 | —    |
| `PRIMARY`                                | 是   | `BTREE` | `id`                                       |  319 | —    |

#### 外键约束

| 约束名                        | 字段         | 引用表                | 引用字段 | 更新规则    | 删除规则   |
| ----------------------------- | ------------ | --------------------- | -------- | ----------- | ---------- |
| `fk_payments_invoice_id`      | `invoice_id` | `invoices`            | `id`     | `NO ACTION` | `RESTRICT` |
| `fk_payments_user_id`         | `user_id`    | `users`               | `id`     | `NO ACTION` | `RESTRICT` |
| `fk_stage2_payments_order_id` | `order_id`   | `orders`              | `id`     | `NO ACTION` | `SET NULL` |
| `payments_plugin_fk`          | `plugin_id`  | `integration_plugins` | `id`     | `NO ACTION` | `SET NULL` |

### 2.33 `personal_access_tokens`

- 类型：`BASE TABLE`
- 引擎：`InnoDB`
- 估算行数：`370`
- 数据大小：`96 KB`
- 索引大小：`80 KB`
- 自增值：`408`
- 排序规则：`utf8mb4_unicode_ci`
- 表注释：—

#### 字段

| 序号 | 字段             | 类型              | 可空 | 默认值 | 键  | 额外           | 字符集  | 排序规则           | 注释 |
| ---: | ---------------- | ----------------- | ---- | ------ | --- | -------------- | ------- | ------------------ | ---- |
|    1 | `id`             | `bigint unsigned` | 否   | —      | PRI | auto_increment | —       | —                  | —    |
|    2 | `tokenable_type` | `varchar(255)`    | 否   | —      | MUL | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|    3 | `tokenable_id`   | `bigint unsigned` | 否   | —      | —   | —              | —       | —                  | —    |
|    4 | `name`           | `text`            | 否   | —      | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|    5 | `token`          | `varchar(64)`     | 否   | —      | UNI | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|    6 | `abilities`      | `text`            | 是   | `NULL` | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|    7 | `last_used_at`   | `timestamp`       | 是   | `NULL` | —   | —              | —       | —                  | —    |
|    8 | `expires_at`     | `timestamp`       | 是   | `NULL` | MUL | —              | —       | —                  | —    |
|    9 | `created_at`     | `timestamp`       | 是   | `NULL` | —   | —              | —       | —                  | —    |
|   10 | `updated_at`     | `timestamp`       | 是   | `NULL` | —   | —              | —       | —                  | —    |

#### 索引

| 索引名                                                     | 唯一 | 类型    | 字段                             | 基数 | 注释 |
| ---------------------------------------------------------- | ---- | ------- | -------------------------------- | ---: | ---- |
| `personal_access_tokens_expires_at_index`                  | 否   | `BTREE` | `expires_at`                     |    8 | —    |
| `personal_access_tokens_token_unique`                      | 是   | `BTREE` | `token`                          |  370 | —    |
| `personal_access_tokens_tokenable_type_tokenable_id_index` | 否   | `BTREE` | `tokenable_type`, `tokenable_id` |   55 | —    |
| `PRIMARY`                                                  | 是   | `BTREE` | `id`                             |  370 | —    |

#### 外键约束

无数据库级外键约束。

### 2.34 `product_upstream_bindings`

- 类型：`BASE TABLE`
- 引擎：`InnoDB`
- 估算行数：`139`
- 数据大小：`8.52 MB`
- 索引大小：`96 KB`
- 自增值：`270`
- 排序规则：`utf8mb4_unicode_ci`
- 表注释：—

#### 字段

| 序号 | 字段                             | 类型               | 可空 | 默认值 | 键  | 额外           | 字符集  | 排序规则           | 注释 |
| ---: | -------------------------------- | ------------------ | ---- | ------ | --- | -------------- | ------- | ------------------ | ---- |
|    1 | `id`                             | `bigint unsigned`  | 否   | —      | PRI | auto_increment | —       | —                  | —    |
|    2 | `product_id`                     | `bigint unsigned`  | 否   | —      | MUL | —              | —       | —                  | —    |
|    3 | `supplier_plugin_binding_id`     | `bigint unsigned`  | 否   | —      | MUL | —              | —       | —                  | —    |
|    4 | `plugin_id`                      | `bigint unsigned`  | 否   | —      | MUL | —              | —       | —                  | —    |
|    5 | `provider_key`                   | `varchar(120)`     | 否   | —      | MUL | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|    6 | `upstream_product_id`            | `varchar(120)`     | 否   | —      | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|    7 | `upstream_product_snapshot_json` | `json`             | 是   | `NULL` | —   | —              | —       | —                  | —    |
|    8 | `option_schema_json`             | `json`             | 是   | `NULL` | —   | —              | —       | —                  | —    |
|    9 | `provision_policy_json`          | `json`             | 是   | `NULL` | —   | —              | —       | —                  | —    |
|   10 | `auto_setup`                     | `tinyint(1)`       | 否   | `0`    | —   | —              | —       | —                  | —    |
|   11 | `status`                         | `tinyint unsigned` | 否   | `1`    | —   | —              | —       | —                  | —    |
|   12 | `last_synced_at`                 | `timestamp`        | 是   | `NULL` | —   | —              | —       | —                  | —    |
|   13 | `last_sync_error`                | `varchar(500)`     | 是   | `NULL` | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|   14 | `backfill_batch_id`              | `varchar(64)`      | 是   | `NULL` | MUL | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|   15 | `created_at`                     | `timestamp`        | 是   | `NULL` | —   | —              | —       | —                  | —    |
|   16 | `updated_at`                     | `timestamp`        | 是   | `NULL` | —   | —              | —       | —                  | —    |

#### 索引

| 索引名                                                         | 唯一 | 类型    | 字段                                                              | 基数 | 注释 |
| -------------------------------------------------------------- | ---- | ------- | ----------------------------------------------------------------- | ---: | ---- |
| `PRIMARY`                                                      | 是   | `BTREE` | `id`                                                              |  139 | —    |
| `product_upstream_backfill_batch_idx`                          | 否   | `BTREE` | `backfill_batch_id`                                               |    1 | —    |
| `product_upstream_bindings_supplier_plugin_binding_id_foreign` | 否   | `BTREE` | `supplier_plugin_binding_id`                                      |    2 | —    |
| `product_upstream_plugin_status_idx`                           | 否   | `BTREE` | `plugin_id`, `status`                                             |    2 | —    |
| `product_upstream_product_status_idx`                          | 否   | `BTREE` | `product_id`, `status`                                            |  139 | —    |
| `product_upstream_provider_status_idx`                         | 否   | `BTREE` | `provider_key`, `status`                                          |    2 | —    |
| `product_upstream_unique`                                      | 是   | `BTREE` | `product_id`, `supplier_plugin_binding_id`, `upstream_product_id` |  139 | —    |

#### 外键约束

| 约束名                                                         | 字段                         | 引用表                     | 引用字段 | 更新规则    | 删除规则   |
| -------------------------------------------------------------- | ---------------------------- | -------------------------- | -------- | ----------- | ---------- |
| `product_upstream_bindings_plugin_id_foreign`                  | `plugin_id`                  | `integration_plugins`      | `id`     | `NO ACTION` | `RESTRICT` |
| `product_upstream_bindings_product_id_foreign`                 | `product_id`                 | `products`                 | `id`     | `NO ACTION` | `RESTRICT` |
| `product_upstream_bindings_supplier_plugin_binding_id_foreign` | `supplier_plugin_binding_id` | `supplier_plugin_bindings` | `id`     | `NO ACTION` | `RESTRICT` |

### 2.35 `products`

- 类型：`BASE TABLE`
- 引擎：`InnoDB`
- 估算行数：`127`
- 数据大小：`9.52 MB`
- 索引大小：`48 KB`
- 自增值：`379`
- 排序规则：`utf8mb4_unicode_ci`
- 表注释：商品表，记录可售卖产品的分类、定价、库存、上游绑定和开通策略

#### 字段

| 序号 | 字段                  | 类型              | 可空 | 默认值    | 键  | 额外           | 字符集  | 排序规则           | 注释                                         |
| ---: | --------------------- | ----------------- | ---- | --------- | --- | -------------- | ------- | ------------------ | -------------------------------------------- |
|    1 | `id`                  | `bigint unsigned` | 否   | —         | PRI | auto_increment | —       | —                  | 商品自增主键                                 |
|    2 | `product_group_id`    | `bigint unsigned` | 是   | `NULL`    | MUL | —              | —       | —                  | 当前所属商品分组ID                           |
|    3 | `service_type_code`   | `varchar(50)`     | 是   | `NULL`    | —   | —              | utf8mb4 | utf8mb4_unicode_ci | 服务类型代码，用于前后端能力分流             |
|    4 | `product_type`        | `varchar(30)`     | 否   | —         | MUL | —              | utf8mb4 | utf8mb4_unicode_ci | 商品类型：vps/dedicated/hosting/domain/other |
|    5 | `console_template`    | `varchar(32)`     | 否   | `compute` | —   | —              | utf8mb4 | utf8mb4_unicode_ci | 用户控制台模板：compute 或 port_mapping      |
|    6 | `custom_display_name` | `varchar(190)`    | 是   | `NULL`    | —   | —              | utf8mb4 | utf8mb4_unicode_ci | 自定义展示名称                               |
|    7 | `remark`              | `varchar(255)`    | 是   | `NULL`    | —   | —              | utf8mb4 | utf8mb4_unicode_ci | 商品备注                                     |
|    8 | `pricing`             | `json`            | 否   | —         | —   | —              | —       | —                  | 周期价格 JSON，如 monthly/quarterly/annually |
|    9 | `setup_fee`           | `decimal(12,2)`   | 否   | `0.00`    | —   | —              | —       | —                  | 初装费                                       |
|   10 | `config_options`      | `json`            | 是   | `NULL`    | —   | —              | —       | —                  | 可选配置项 JSON                              |
|   11 | `purchase_requires`   | `json`            | 是   | `NULL`    | —   | —              | —       | —                  | 购买限制 JSON，如实名认证、手机号要求        |
|   12 | `stock`               | `int`             | 否   | `-1`      | —   | —              | —       | —                  | 库存数量，-1 表示不限                        |
|   13 | `status`              | `tinyint`         | 否   | `1`       | —   | —              | —       | —                  | 商品状态：0下架 1上架                        |
|   14 | `sort_order`          | `int`             | 否   | `0`       | —   | —              | —       | —                  | 排序值，越小越靠前                           |
|   15 | `auto_setup`          | `tinyint`         | 否   | `0`       | —   | —              | —       | —                  | 是否自动开通：0手动 1自动                    |
|   16 | `created_at`          | `timestamp`       | 是   | `NULL`    | —   | —              | —       | —                  | 创建时间                                     |
|   17 | `updated_at`          | `timestamp`       | 是   | `NULL`    | —   | —              | —       | —                  | 更新时间                                     |
|   18 | `deleted_at`          | `timestamp`       | 是   | `NULL`    | —   | —              | —       | —                  | 软删除时间                                   |

#### 索引

| 索引名                              | 唯一 | 类型    | 字段                                             | 基数 | 注释 |
| ----------------------------------- | ---- | ------- | ------------------------------------------------ | ---: | ---- |
| `PRIMARY`                           | 是   | `BTREE` | `id`                                             |  127 | —    |
| `products_group_status_sort_id_idx` | 否   | `BTREE` | `product_group_id`, `status`, `sort_order`, `id` |  127 | —    |
| `products_type_status_index`        | 否   | `BTREE` | `product_type`, `status`                         |    9 | —    |

#### 外键约束

| 约束名                      | 字段               | 引用表                 | 引用字段 | 更新规则    | 删除规则   |
| --------------------------- | ------------------ | ---------------------- | -------- | ----------- | ---------- |
| `products_product_group_fk` | `product_group_id` | `third_product_groups` | `id`     | `NO ACTION` | `RESTRICT` |

### 2.36 `recharge_records`

- 类型：`BASE TABLE`
- 引擎：`InnoDB`
- 估算行数：`24`
- 数据大小：`16 KB`
- 索引大小：`144 KB`
- 自增值：`25`
- 排序规则：`utf8mb4_unicode_ci`
- 表注释：—

#### 字段

| 序号 | 字段                        | 类型              | 可空 | 默认值 | 键  | 额外           | 字符集  | 排序规则           | 注释                                                                         |
| ---: | --------------------------- | ----------------- | ---- | ------ | --- | -------------- | ------- | ------------------ | ---------------------------------------------------------------------------- |
|    1 | `id`                        | `bigint unsigned` | 否   | —      | PRI | auto_increment | —       | —                  | —                                                                            |
|    2 | `record_no`                 | `varchar(32)`     | 否   | —      | UNI | —              | utf8mb4 | utf8mb4_unicode_ci | —                                                                            |
|    3 | `user_id`                   | `bigint unsigned` | 否   | —      | MUL | —              | —       | —                  | —                                                                            |
|    4 | `order_id`                  | `bigint unsigned` | 是   | `NULL` | MUL | —              | —       | —                  | —                                                                            |
|    5 | `invoice_id`                | `bigint unsigned` | 是   | `NULL` | MUL | —              | —       | —                  | —                                                                            |
|    6 | `payment_id`                | `bigint unsigned` | 是   | `NULL` | UNI | —              | —       | —                  | —                                                                            |
|    7 | `account_transaction_id`    | `bigint unsigned` | 是   | `NULL` | UNI | —              | —       | —                  | —                                                                            |
|    8 | `refund_id`                 | `bigint unsigned` | 是   | `NULL` | MUL | —              | —       | —                  | —                                                                            |
|    9 | `origin_recharge_record_id` | `bigint unsigned` | 是   | `NULL` | MUL | —              | —       | —                  | —                                                                            |
|   10 | `scene`                     | `varchar(30)`     | 否   | —      | —   | —              | utf8mb4 | utf8mb4_unicode_ci | 业务场景：recharge=支付充值 admin_recharge=管理员充值 refund=退款 等         |
|   11 | `direction`                 | `varchar(8)`      | 否   | —      | —   | —              | utf8mb4 | utf8mb4_unicode_ci | 资金方向：in=入账 out=出账                                                   |
|   12 | `amount`                    | `decimal(12,2)`   | 否   | —      | —   | —              | —       | —                  | —                                                                            |
|   13 | `currency`                  | `varchar(3)`      | 否   | `CNY`  | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —                                                                            |
|   14 | `entry_type`                | `varchar(30)`     | 否   | —      | —   | —              | utf8mb4 | utf8mb4_unicode_ci | 入账类型：third_party_payment/manual_recharge/account_recharge/refund_offset |
|   15 | `remark`                    | `varchar(255)`    | 是   | `NULL` | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —                                                                            |
|   16 | `operator_type`             | `varchar(30)`     | 是   | `NULL` | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —                                                                            |
|   17 | `operator_id`               | `bigint unsigned` | 是   | `NULL` | —   | —              | —       | —                  | —                                                                            |
|   18 | `operator_name`             | `varchar(50)`     | 是   | `NULL` | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —                                                                            |
|   19 | `trace_id`                  | `varchar(64)`     | 是   | `NULL` | MUL | —              | utf8mb4 | utf8mb4_unicode_ci | —                                                                            |
|   20 | `created_at`                | `timestamp`       | 是   | `NULL` | —   | —              | —       | —                  | —                                                                            |
|   21 | `updated_at`                | `timestamp`       | 是   | `NULL` | —   | —              | —       | —                  | —                                                                            |

#### 索引

| 索引名                                                | 唯一 | 类型    | 字段                              | 基数 | 注释 |
| ----------------------------------------------------- | ---- | ------- | --------------------------------- | ---: | ---- |
| `PRIMARY`                                             | 是   | `BTREE` | `id`                              |   12 | —    |
| `recharge_records_account_transaction_id_unique`      | 是   | `BTREE` | `account_transaction_id`          |    3 | —    |
| `recharge_records_invoice_id_id_index`                | 否   | `BTREE` | `invoice_id`, `id`                |   12 | —    |
| `recharge_records_order_id_id_index`                  | 否   | `BTREE` | `order_id`, `id`                  |   12 | —    |
| `recharge_records_origin_recharge_record_id_id_index` | 否   | `BTREE` | `origin_recharge_record_id`, `id` |   12 | —    |
| `recharge_records_payment_id_unique`                  | 是   | `BTREE` | `payment_id`                      |   11 | —    |
| `recharge_records_record_no_unique`                   | 是   | `BTREE` | `record_no`                       |   12 | —    |
| `recharge_records_refund_id_foreign`                  | 否   | `BTREE` | `refund_id`                       |    1 | —    |
| `recharge_records_trace_id_index`                     | 否   | `BTREE` | `trace_id`                        |   12 | —    |
| `recharge_records_user_id_created_at_index`           | 否   | `BTREE` | `user_id`, `created_at`           |   12 | —    |

#### 外键约束

| 约束名                                               | 字段                        | 引用表                 | 引用字段 | 更新规则    | 删除规则   |
| ---------------------------------------------------- | --------------------------- | ---------------------- | -------- | ----------- | ---------- |
| `recharge_records_account_transaction_id_foreign`    | `account_transaction_id`    | `account_transactions` | `id`     | `NO ACTION` | `SET NULL` |
| `recharge_records_invoice_id_foreign`                | `invoice_id`                | `invoices`             | `id`     | `NO ACTION` | `SET NULL` |
| `recharge_records_order_id_foreign`                  | `order_id`                  | `orders`               | `id`     | `NO ACTION` | `SET NULL` |
| `recharge_records_origin_recharge_record_id_foreign` | `origin_recharge_record_id` | `recharge_records`     | `id`     | `NO ACTION` | `SET NULL` |
| `recharge_records_payment_id_foreign`                | `payment_id`                | `payments`             | `id`     | `NO ACTION` | `SET NULL` |
| `recharge_records_refund_id_foreign`                 | `refund_id`                 | `refunds`              | `id`     | `NO ACTION` | `SET NULL` |
| `recharge_records_user_id_foreign`                   | `user_id`                   | `users`                | `id`     | `NO ACTION` | `RESTRICT` |

### 2.37 `referral_account_logs`

- 类型：`BASE TABLE`
- 引擎：`InnoDB`
- 估算行数：`6`
- 数据大小：`16 KB`
- 索引大小：`64 KB`
- 自增值：`7`
- 排序规则：`utf8mb4_unicode_ci`
- 表注释：—

#### 字段

| 序号 | 字段                         | 类型              | 可空 | 默认值              | 键  | 额外              | 字符集  | 排序规则           | 注释                                                                                                 |
| ---: | ---------------------------- | ----------------- | ---- | ------------------- | --- | ----------------- | ------- | ------------------ | ---------------------------------------------------------------------------------------------------- |
|    1 | `id`                         | `bigint unsigned` | 否   | —                   | PRI | auto_increment    | —       | —                  | —                                                                                                    |
|    2 | `user_id`                    | `bigint unsigned` | 否   | —                   | MUL | —                 | —       | —                  | —                                                                                                    |
|    3 | `event_type`                 | `varchar(30)`     | 否   | —                   | —   | —                 | utf8mb4 | utf8mb4_unicode_ci | reward_frozen&#124;reward_released&#124;withdraw_apply&#124;withdraw_approved&#124;withdraw_rejected |
|    4 | `change_amount`              | `decimal(12,2)`   | 否   | —                   | —   | —                 | —       | —                  | 正=增加 负=减少                                                                                      |
|    5 | `frozen_balance`             | `decimal(12,2)`   | 否   | `0.00`              | —   | —                 | —       | —                  | —                                                                                                    |
|    6 | `available_balance`          | `decimal(12,2)`   | 否   | `0.00`              | —   | —                 | —       | —                  | —                                                                                                    |
|    7 | `pending_withdrawal_balance` | `decimal(12,2)`   | 否   | `0.00`              | —   | —                 | —       | —                  | —                                                                                                    |
|    8 | `withdrawn_balance`          | `decimal(12,2)`   | 否   | `0.00`              | —   | —                 | —       | —                  | —                                                                                                    |
|    9 | `remark`                     | `varchar(255)`    | 否   | 空字符串            | —   | —                 | utf8mb4 | utf8mb4_unicode_ci | —                                                                                                    |
|   10 | `reference_id`               | `bigint unsigned` | 是   | `NULL`              | —   | —                 | —       | —                  | —                                                                                                    |
|   11 | `reference_type`             | `varchar(30)`     | 是   | `NULL`              | MUL | —                 | utf8mb4 | utf8mb4_unicode_ci | —                                                                                                    |
|   12 | `operator`                   | `varchar(50)`     | 是   | `NULL`              | —   | —                 | utf8mb4 | utf8mb4_unicode_ci | —                                                                                                    |
|   13 | `trace_id`                   | `varchar(64)`     | 是   | `NULL`              | —   | —                 | utf8mb4 | utf8mb4_unicode_ci | —                                                                                                    |
|   14 | `created_at`                 | `timestamp`       | 否   | `CURRENT_TIMESTAMP` | MUL | DEFAULT_GENERATED | —       | —                  | —                                                                                                    |

#### 索引

| 索引名                                   | 唯一 | 类型    | 字段                             | 基数 | 注释 |
| ---------------------------------------- | ---- | ------- | -------------------------------- | ---: | ---- |
| `idx_referral_account_related`           | 否   | `BTREE` | `reference_type`, `reference_id` |    6 | —    |
| `idx_referral_account_user_created_idx`  | 否   | `BTREE` | `user_id`, `created_at`, `id`    |    6 | —    |
| `idx_referral_account_user_type`         | 否   | `BTREE` | `user_id`, `event_type`          |    2 | —    |
| `PRIMARY`                                | 是   | `BTREE` | `id`                             |    6 | —    |
| `referral_account_logs_created_at_index` | 否   | `BTREE` | `created_at`                     |    1 | —    |

#### 外键约束

| 约束名                                    | 字段      | 引用表  | 引用字段 | 更新规则    | 删除规则   |
| ----------------------------------------- | --------- | ------- | -------- | ----------- | ---------- |
| `fk_stage2_referral_account_logs_user_id` | `user_id` | `users` | `id`     | `NO ACTION` | `RESTRICT` |

### 2.38 `referral_rewards`

- 类型：`BASE TABLE`
- 引擎：`InnoDB`
- 估算行数：`5`
- 数据大小：`16 KB`
- 索引大小：`96 KB`
- 自增值：`11`
- 排序规则：`utf8mb4_unicode_ci`
- 表注释：—

#### 字段

| 序号 | 字段               | 类型              | 可空 | 默认值 | 键  | 额外           | 字符集  | 排序规则           | 注释                       |
| ---: | ------------------ | ----------------- | ---- | ------ | --- | -------------- | ------- | ------------------ | -------------------------- |
|    1 | `id`               | `bigint unsigned` | 否   | —      | PRI | auto_increment | —       | —                  | —                          |
|    2 | `referrer_user_id` | `bigint unsigned` | 否   | —      | MUL | —              | —       | —                  | —                          |
|    3 | `referred_user_id` | `bigint unsigned` | 否   | —      | MUL | —              | —       | —                  | —                          |
|    4 | `order_id`         | `bigint unsigned` | 否   | —      | UNI | —              | —       | —                  | —                          |
|    5 | `invoice_id`       | `bigint unsigned` | 是   | `NULL` | MUL | —              | —       | —                  | —                          |
|    6 | `product_id`       | `bigint unsigned` | 是   | `NULL` | MUL | —              | —       | —                  | —                          |
|    7 | `order_amount`     | `decimal(12,2)`   | 否   | `0.00` | —   | —              | —       | —                  | —                          |
|    8 | `reward_rate`      | `decimal(5,2)`    | 否   | `0.00` | —   | —              | —       | —                  | —                          |
|    9 | `reward_amount`    | `decimal(12,2)`   | 否   | `0.00` | —   | —              | —       | —                  | —                          |
|   10 | `available_at`     | `timestamp`       | 是   | `NULL` | —   | —              | —       | —                  | —                          |
|   11 | `released_at`      | `timestamp`       | 是   | `NULL` | —   | —              | —       | —                  | —                          |
|   12 | `status`           | `tinyint`         | 否   | `0`    | —   | —              | —       | —                  | 0=冻结中 1=已发放 2=已回退 |
|   13 | `operator`         | `varchar(50)`     | 是   | `NULL` | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —                          |
|   14 | `remark`           | `varchar(255)`    | 是   | `NULL` | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —                          |
|   15 | `trace_id`         | `varchar(64)`     | 是   | `NULL` | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —                          |
|   16 | `rewarded_at`      | `timestamp`       | 是   | `NULL` | MUL | —              | —       | —                  | —                          |
|   17 | `created_at`       | `timestamp`       | 是   | `NULL` | —   | —              | —       | —                  | —                          |
|   18 | `updated_at`       | `timestamp`       | 是   | `NULL` | —   | —              | —       | —                  | —                          |

#### 索引

| 索引名                                | 唯一 | 类型    | 字段                         | 基数 | 注释 |
| ------------------------------------- | ---- | ------- | ---------------------------- | ---: | ---- |
| `idx_referral_reward_referred_status` | 否   | `BTREE` | `referred_user_id`, `status` |    2 | —    |
| `idx_referral_reward_referrer_status` | 否   | `BTREE` | `referrer_user_id`, `status` |    1 | —    |
| `PRIMARY`                             | 是   | `BTREE` | `id`                         |    5 | —    |
| `referral_rewards_invoice_id_idx`     | 否   | `BTREE` | `invoice_id`                 |    4 | —    |
| `referral_rewards_order_id_unique`    | 是   | `BTREE` | `order_id`                   |    5 | —    |
| `referral_rewards_product_id_index`   | 否   | `BTREE` | `product_id`                 |    4 | —    |
| `referral_rewards_rewarded_at_index`  | 否   | `BTREE` | `rewarded_at`                |    5 | —    |

#### 外键约束

| 约束名                                        | 字段               | 引用表     | 引用字段 | 更新规则    | 删除规则   |
| --------------------------------------------- | ------------------ | ---------- | -------- | ----------- | ---------- |
| `fk_stage2_referral_rewards_invoice_id`       | `invoice_id`       | `invoices` | `id`     | `NO ACTION` | `SET NULL` |
| `fk_stage2_referral_rewards_order_id`         | `order_id`         | `orders`   | `id`     | `NO ACTION` | `RESTRICT` |
| `fk_stage2_referral_rewards_product_id`       | `product_id`       | `products` | `id`     | `NO ACTION` | `SET NULL` |
| `fk_stage2_referral_rewards_referred_user_id` | `referred_user_id` | `users`    | `id`     | `NO ACTION` | `RESTRICT` |
| `fk_stage2_referral_rewards_referrer_user_id` | `referrer_user_id` | `users`    | `id`     | `NO ACTION` | `RESTRICT` |

### 2.39 `referral_withdrawals`

- 类型：`BASE TABLE`
- 引擎：`InnoDB`
- 估算行数：`0`
- 数据大小：`16 KB`
- 索引大小：`48 KB`
- 自增值：`4`
- 排序规则：`utf8mb4_unicode_ci`
- 表注释：—

#### 字段

| 序号 | 字段           | 类型              | 可空 | 默认值   | 键  | 额外           | 字符集  | 排序规则           | 注释                       |
| ---: | -------------- | ----------------- | ---- | -------- | --- | -------------- | ------- | ------------------ | -------------------------- |
|    1 | `id`           | `bigint unsigned` | 否   | —        | PRI | auto_increment | —       | —                  | —                          |
|    2 | `user_id`      | `bigint unsigned` | 否   | —        | MUL | —              | —       | —                  | —                          |
|    3 | `amount`       | `decimal(12,2)`   | 否   | `0.00`   | —   | —              | —       | —                  | —                          |
|    4 | `method`       | `varchar(20)`     | 否   | `alipay` | —   | —              | utf8mb4 | utf8mb4_unicode_ci | balance&#124;alipay        |
|    5 | `account_name` | `varchar(80)`     | 否   | 空字符串 | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —                          |
|    6 | `account_no`   | `varchar(120)`    | 否   | 空字符串 | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —                          |
|    7 | `status`       | `tinyint`         | 否   | `0`      | MUL | —              | —       | —                  | 0=待处理 1=已通过 2=已拒绝 |
|    8 | `payment_no`   | `varchar(120)`    | 是   | `NULL`   | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —                          |
|    9 | `remark`       | `varchar(255)`    | 是   | `NULL`   | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —                          |
|   10 | `operator`     | `varchar(50)`     | 是   | `NULL`   | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —                          |
|   11 | `paid_at`      | `timestamp`       | 是   | `NULL`   | —   | —              | —       | —                  | —                          |
|   12 | `trace_id`     | `varchar(64)`     | 否   | —        | UNI | —              | utf8mb4 | utf8mb4_unicode_ci | —                          |
|   13 | `processed_at` | `timestamp`       | 是   | `NULL`   | —   | —              | —       | —                  | —                          |
|   14 | `created_at`   | `timestamp`       | 是   | `NULL`   | —   | —              | —       | —                  | —                          |
|   15 | `updated_at`   | `timestamp`       | 是   | `NULL`   | —   | —              | —       | —                  | —                          |

#### 索引

| 索引名                                 | 唯一 | 类型    | 字段                   | 基数 | 注释 |
| -------------------------------------- | ---- | ------- | ---------------------- | ---: | ---- |
| `idx_referral_withdraw_status_created` | 否   | `BTREE` | `status`, `created_at` |    0 | —    |
| `idx_referral_withdraw_user_status`    | 否   | `BTREE` | `user_id`, `status`    |    0 | —    |
| `PRIMARY`                              | 是   | `BTREE` | `id`                   |    0 | —    |
| `referral_withdrawals_trace_id_unique` | 是   | `BTREE` | `trace_id`             |    0 | —    |

#### 外键约束

| 约束名                                   | 字段      | 引用表  | 引用字段 | 更新规则    | 删除规则   |
| ---------------------------------------- | --------- | ------- | -------- | ----------- | ---------- |
| `fk_stage2_referral_withdrawals_user_id` | `user_id` | `users` | `id`     | `NO ACTION` | `RESTRICT` |

### 2.40 `refunds`

- 类型：`BASE TABLE`
- 引擎：`InnoDB`
- 估算行数：`2`
- 数据大小：`16 KB`
- 索引大小：`96 KB`
- 自增值：`4`
- 排序规则：`utf8mb4_unicode_ci`
- 表注释：—

#### 字段

| 序号 | 字段                | 类型               | 可空 | 默认值    | 键  | 额外           | 字符集  | 排序规则           | 注释               |
| ---: | ------------------- | ------------------ | ---- | --------- | --- | -------------- | ------- | ------------------ | ------------------ |
|    1 | `id`                | `bigint unsigned`  | 否   | —         | PRI | auto_increment | —       | —                  | —                  |
|    2 | `refund_no`         | `varchar(32)`      | 否   | —         | UNI | —              | utf8mb4 | utf8mb4_unicode_ci | —                  |
|    3 | `user_id`           | `bigint unsigned`  | 否   | —         | MUL | —              | —       | —                  | —                  |
|    4 | `invoice_id`        | `bigint unsigned`  | 否   | —         | MUL | —              | —       | —                  | —                  |
|    5 | `refund_invoice_id` | `bigint unsigned`  | 是   | `NULL`    | MUL | —              | —       | —                  | —                  |
|    6 | `payment_id`        | `bigint unsigned`  | 是   | `NULL`    | MUL | —              | —       | —                  | —                  |
|    7 | `amount`            | `decimal(12,2)`    | 否   | —         | —   | —              | —       | —                  | —                  |
|    8 | `status`            | `tinyint unsigned` | 否   | `1`       | —   | —              | —       | —                  | 退款状态：1=已完成 |
|    9 | `refund_method`     | `varchar(32)`      | 否   | `balance` | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —                  |
|   10 | `currency`          | `varchar(3)`       | 否   | `CNY`     | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —                  |
|   11 | `reason`            | `varchar(255)`     | 是   | `NULL`    | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —                  |
|   12 | `gateway_refund_no` | `varchar(100)`     | 是   | `NULL`    | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —                  |
|   13 | `operator_type`     | `varchar(30)`      | 是   | `NULL`    | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —                  |
|   14 | `operator_id`       | `bigint unsigned`  | 是   | `NULL`    | —   | —              | —       | —                  | —                  |
|   15 | `operator_name`     | `varchar(50)`      | 是   | `NULL`    | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —                  |
|   16 | `trace_id`          | `varchar(64)`      | 是   | `NULL`    | MUL | —              | utf8mb4 | utf8mb4_unicode_ci | —                  |
|   17 | `refunded_at`       | `timestamp`        | 是   | `NULL`    | —   | —              | —       | —                  | —                  |
|   18 | `created_at`        | `timestamp`        | 是   | `NULL`    | —   | —              | —       | —                  | —                  |
|   19 | `updated_at`        | `timestamp`        | 是   | `NULL`    | —   | —              | —       | —                  | —                  |

#### 索引

| 索引名                               | 唯一 | 类型    | 字段                         | 基数 | 注释 |
| ------------------------------------ | ---- | ------- | ---------------------------- | ---: | ---- |
| `PRIMARY`                            | 是   | `BTREE` | `id`                         |    0 | —    |
| `refunds_invoice_id_status_id_index` | 否   | `BTREE` | `invoice_id`, `status`, `id` |    0 | —    |
| `refunds_payment_id_foreign`         | 否   | `BTREE` | `payment_id`                 |    0 | —    |
| `refunds_refund_invoice_id_foreign`  | 否   | `BTREE` | `refund_invoice_id`          |    0 | —    |
| `refunds_refund_no_unique`           | 是   | `BTREE` | `refund_no`                  |    0 | —    |
| `refunds_trace_id_index`             | 否   | `BTREE` | `trace_id`                   |    0 | —    |
| `refunds_user_id_created_at_index`   | 否   | `BTREE` | `user_id`, `created_at`      |    0 | —    |

#### 外键约束

| 约束名                              | 字段                | 引用表     | 引用字段 | 更新规则    | 删除规则   |
| ----------------------------------- | ------------------- | ---------- | -------- | ----------- | ---------- |
| `refunds_invoice_id_foreign`        | `invoice_id`        | `invoices` | `id`     | `NO ACTION` | `RESTRICT` |
| `refunds_payment_id_foreign`        | `payment_id`        | `payments` | `id`     | `NO ACTION` | `SET NULL` |
| `refunds_refund_invoice_id_foreign` | `refund_invoice_id` | `invoices` | `id`     | `NO ACTION` | `SET NULL` |
| `refunds_user_id_foreign`           | `user_id`           | `users`    | `id`     | `NO ACTION` | `RESTRICT` |

### 2.41 `roles`

- 类型：`BASE TABLE`
- 引擎：`InnoDB`
- 估算行数：`2`
- 数据大小：`16 KB`
- 索引大小：`16 KB`
- 自增值：`398`
- 排序规则：`utf8mb4_unicode_ci`
- 表注释：—

#### 字段

| 序号 | 字段          | 类型              | 可空 | 默认值 | 键  | 额外           | 字符集  | 排序规则           | 注释         |
| ---: | ------------- | ----------------- | ---- | ------ | --- | -------------- | ------- | ------------------ | ------------ |
|    1 | `id`          | `bigint unsigned` | 否   | —      | PRI | auto_increment | —       | —                  | —            |
|    2 | `name`        | `varchar(50)`     | 否   | —      | UNI | —              | utf8mb4 | utf8mb4_unicode_ci | —            |
|    3 | `label`       | `varchar(100)`    | 是   | `NULL` | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —            |
|    4 | `permissions` | `json`            | 否   | —      | —   | —              | —       | —                  | 权限标识数组 |
|    5 | `created_at`  | `timestamp`       | 是   | `NULL` | —   | —              | —       | —                  | —            |
|    6 | `updated_at`  | `timestamp`       | 是   | `NULL` | —   | —              | —       | —                  | —            |

#### 索引

| 索引名              | 唯一 | 类型    | 字段   | 基数 | 注释 |
| ------------------- | ---- | ------- | ------ | ---: | ---- |
| `PRIMARY`           | 是   | `BTREE` | `id`   |    2 | —    |
| `roles_name_unique` | 是   | `BTREE` | `name` |    2 | —    |

#### 外键约束

无数据库级外键约束。

### 2.42 `schedule_run_logs`

- 类型：`BASE TABLE`
- 引擎：`InnoDB`
- 估算行数：`149,720`
- 数据大小：`31.56 MB`
- 索引大小：`21.09 MB`
- 自增值：`152327`
- 排序规则：`utf8mb4_unicode_ci`
- 表注释：—

#### 字段

| 序号 | 字段          | 类型              | 可空 | 默认值    | 键  | 额外           | 字符集  | 排序规则           | 注释                               |
| ---: | ------------- | ----------------- | ---- | --------- | --- | -------------- | ------- | ------------------ | ---------------------------------- |
|    1 | `id`          | `bigint unsigned` | 否   | —         | PRI | auto_increment | —       | —                  | —                                  |
|    2 | `task_name`   | `varchar(100)`    | 否   | —         | MUL | —              | utf8mb4 | utf8mb4_unicode_ci | 任务名称                           |
|    3 | `status`      | `varchar(20)`     | 否   | `success` | MUL | —              | utf8mb4 | utf8mb4_unicode_ci | 执行状态: success, failed, skipped |
|    4 | `duration_ms` | `int unsigned`    | 否   | `0`       | —   | —              | —       | —                  | 执行耗时(毫秒)                     |
|    5 | `summary`     | `json`            | 是   | `NULL`    | —   | —              | —       | —                  | 执行摘要数据                       |
|    6 | `error_msg`   | `text`            | 是   | `NULL`    | —   | —              | utf8mb4 | utf8mb4_unicode_ci | 错误信息                           |
|    7 | `started_at`  | `timestamp`       | 是   | `NULL`    | —   | —              | —       | —                  | 开始时间                           |
|    8 | `finished_at` | `timestamp`       | 是   | `NULL`    | —   | —              | —       | —                  | 结束时间                           |
|    9 | `created_at`  | `timestamp`       | 是   | `NULL`    | MUL | —              | —       | —                  | —                                  |
|   10 | `updated_at`  | `timestamp`       | 是   | `NULL`    | —   | —              | —       | —                  | —                                  |

#### 索引

| 索引名                                         | 唯一 | 类型    | 字段                      |    基数 | 注释 |
| ---------------------------------------------- | ---- | ------- | ------------------------- | ------: | ---- |
| `PRIMARY`                                      | 是   | `BTREE` | `id`                      | 149,720 | —    |
| `schedule_run_logs_created_at_index`           | 否   | `BTREE` | `created_at`              |  78,500 | —    |
| `schedule_run_logs_status_created_at_index`    | 否   | `BTREE` | `status`, `created_at`    |  79,620 | —    |
| `schedule_run_logs_task_name_created_at_index` | 否   | `BTREE` | `task_name`, `created_at` | 149,720 | —    |

#### 外键约束

无数据库级外键约束。

### 2.43 `schedule_task_runs`

- 类型：`BASE TABLE`
- 引擎：`InnoDB`
- 估算行数：`3,408`
- 数据大小：`1.52 MB`
- 索引大小：`1.23 MB`
- 自增值：`3568`
- 排序规则：`utf8mb4_unicode_ci`
- 表注释：—

#### 字段

| 序号 | 字段               | 类型                | 可空 | 默认值      | 键  | 额外           | 字符集  | 排序规则           | 注释 |
| ---: | ------------------ | ------------------- | ---- | ----------- | --- | -------------- | ------- | ------------------ | ---- |
|    1 | `id`               | `bigint unsigned`   | 否   | —           | PRI | auto_increment | —       | —                  | —    |
|    2 | `parent_run_id`    | `bigint unsigned`   | 是   | `NULL`      | MUL | —              | —       | —                  | —    |
|    3 | `schedule_tick_id` | `bigint unsigned`   | 是   | `NULL`      | MUL | —              | —       | —                  | —    |
|    4 | `task_key`         | `varchar(120)`      | 否   | —           | MUL | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|    5 | `task_name`        | `varchar(160)`      | 否   | —           | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|    6 | `rule_description` | `varchar(160)`      | 是   | `NULL`      | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|    7 | `source`           | `varchar(40)`       | 否   | `heartbeat` | MUL | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|    8 | `queue`            | `varchar(80)`       | 是   | `NULL`      | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|    9 | `status`           | `varchar(30)`       | 否   | `queued`    | MUL | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|   10 | `attempt`          | `smallint unsigned` | 否   | `1`         | —   | —              | —       | —                  | —    |
|   11 | `duration_ms`      | `int unsigned`      | 是   | `NULL`      | —   | —              | —       | —                  | —    |
|   12 | `summary`          | `json`              | 是   | `NULL`      | —   | —              | —       | —                  | —    |
|   13 | `error_msg`        | `text`              | 是   | `NULL`      | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|   14 | `queued_at`        | `timestamp`         | 是   | `NULL`      | —   | —              | —       | —                  | —    |
|   15 | `started_at`       | `timestamp`         | 是   | `NULL`      | —   | —              | —       | —                  | —    |
|   16 | `finished_at`      | `timestamp`         | 是   | `NULL`      | —   | —              | —       | —                  | —    |
|   17 | `manual_retry_at`  | `timestamp`         | 是   | `NULL`      | —   | —              | —       | —                  | —    |
|   18 | `manual_retry_by`  | `bigint unsigned`   | 是   | `NULL`      | —   | —              | —       | —                  | —    |
|   19 | `created_at`       | `timestamp`         | 是   | `NULL`      | —   | —              | —       | —                  | —    |
|   20 | `updated_at`       | `timestamp`         | 是   | `NULL`      | —   | —              | —       | —                  | —    |

#### 索引

| 索引名                                         | 唯一 | 类型    | 字段                                     |  基数 | 注释 |
| ---------------------------------------------- | ---- | ------- | ---------------------------------------- | ----: | ---- |
| `PRIMARY`                                      | 是   | `BTREE` | `id`                                     | 3,408 | —    |
| `schedule_task_runs_active_lookup_index`       | 否   | `BTREE` | `task_key`, `status`, `queued_at`        | 3,408 | —    |
| `schedule_task_runs_parent_created_at_index`   | 否   | `BTREE` | `parent_run_id`, `created_at`            |   329 | —    |
| `schedule_task_runs_source_created_at_index`   | 否   | `BTREE` | `source`, `created_at`                   |   329 | —    |
| `schedule_task_runs_status_created_at_index`   | 否   | `BTREE` | `status`, `created_at`                   |   420 | —    |
| `schedule_task_runs_task_key_created_at_index` | 否   | `BTREE` | `task_key`, `created_at`                 | 3,408 | —    |
| `schedule_task_runs_tick_task_source_unique`   | 是   | `BTREE` | `schedule_tick_id`, `task_key`, `source` | 3,408 | —    |

#### 外键约束

| 约束名                                        | 字段               | 引用表           | 引用字段 | 更新规则    | 删除规则   |
| --------------------------------------------- | ------------------ | ---------------- | -------- | ----------- | ---------- |
| `schedule_task_runs_schedule_tick_id_foreign` | `schedule_tick_id` | `schedule_ticks` | `id`     | `NO ACTION` | `SET NULL` |

### 2.44 `schedule_ticks`

- 类型：`BASE TABLE`
- 引擎：`InnoDB`
- 估算行数：`279`
- 数据大小：`16 KB`
- 索引大小：`48 KB`
- 自增值：`315`
- 排序规则：`utf8mb4_unicode_ci`
- 表注释：—

#### 字段

| 序号 | 字段              | 类型               | 可空 | 默认值 | 键  | 额外           | 字符集 | 排序规则 | 注释 |
| ---: | ----------------- | ------------------ | ---- | ------ | --- | -------------- | ------ | -------- | ---- |
|    1 | `id`              | `bigint unsigned`  | 否   | —      | PRI | auto_increment | —      | —        | —    |
|    2 | `slot_started_at` | `timestamp`        | 否   | —      | UNI | —              | —      | —        | —    |
|    3 | `global_number`   | `bigint unsigned`  | 否   | —      | UNI | —              | —      | —        | —    |
|    4 | `daily_index`     | `tinyint unsigned` | 否   | —      | MUL | —              | —      | —        | —    |
|    5 | `triggered_at`    | `timestamp`        | 是   | `NULL` | MUL | —              | —      | —        | —    |
|    6 | `created_at`      | `timestamp`        | 是   | `NULL` | —   | —              | —      | —        | —    |
|    7 | `updated_at`      | `timestamp`        | 是   | `NULL` | —   | —              | —      | —        | —    |

#### 索引

| 索引名                                  | 唯一 | 类型    | 字段              | 基数 | 注释 |
| --------------------------------------- | ---- | ------- | ----------------- | ---: | ---- |
| `PRIMARY`                               | 是   | `BTREE` | `id`              |  279 | —    |
| `schedule_ticks_daily_index_index`      | 否   | `BTREE` | `daily_index`     |   96 | —    |
| `schedule_ticks_global_number_unique`   | 是   | `BTREE` | `global_number`   |  279 | —    |
| `schedule_ticks_slot_started_at_unique` | 是   | `BTREE` | `slot_started_at` |  279 | —    |
| `schedule_ticks_triggered_at_index`     | 否   | `BTREE` | `triggered_at`    |  279 | —    |

#### 外键约束

无数据库级外键约束。

### 2.45 `second_product_groups`

- 类型：`BASE TABLE`
- 引擎：`InnoDB`
- 估算行数：`15`
- 数据大小：`16 KB`
- 索引大小：`32 KB`
- 自增值：`45`
- 排序规则：`utf8mb4_unicode_ci`
- 表注释：—

#### 字段

| 序号 | 字段                     | 类型               | 可空 | 默认值 | 键  | 额外           | 字符集  | 排序规则           | 注释 |
| ---: | ------------------------ | ------------------ | ---- | ------ | --- | -------------- | ------- | ------------------ | ---- |
|    1 | `id`                     | `bigint unsigned`  | 否   | —      | PRI | auto_increment | —       | —                  | —    |
|    2 | `first_product_group_id` | `bigint unsigned`  | 否   | —      | MUL | —              | —       | —                  | —    |
|    3 | `name`                   | `varchar(100)`     | 否   | —      | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|    4 | `slug`                   | `varchar(100)`     | 是   | `NULL` | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|    5 | `description`            | `varchar(255)`     | 是   | `NULL` | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|    6 | `banner_image`           | `varchar(255)`     | 是   | `NULL` | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|    7 | `sort_order`             | `int`              | 否   | `0`    | —   | —              | —       | —                  | —    |
|    8 | `is_visible`             | `tinyint unsigned` | 否   | `1`    | —   | —              | —       | —                  | —    |
|    9 | `created_at`             | `timestamp`        | 是   | `NULL` | —   | —              | —       | —                  | —    |
|   10 | `updated_at`             | `timestamp`        | 是   | `NULL` | —   | —              | —       | —                  | —    |

#### 索引

| 索引名                          | 唯一 | 类型    | 字段                                                 | 基数 | 注释 |
| ------------------------------- | ---- | ------- | ---------------------------------------------------- | ---: | ---- |
| `idx_second_first_visible_sort` | 否   | `BTREE` | `first_product_group_id`, `is_visible`, `sort_order` |   14 | —    |
| `PRIMARY`                       | 是   | `BTREE` | `id`                                                 |   15 | —    |
| `uq_second_first_slug`          | 是   | `BTREE` | `first_product_group_id`, `slug`                     |   15 | —    |

#### 外键约束

| 约束名                  | 字段                     | 引用表                 | 引用字段 | 更新规则    | 删除规则   |
| ----------------------- | ------------------------ | ---------------------- | -------- | ----------- | ---------- |
| `fk_second_first_group` | `first_product_group_id` | `first_product_groups` | `id`     | `NO ACTION` | `RESTRICT` |

### 2.46 `service_connection_snapshots`

- 类型：`BASE TABLE`
- 引擎：`InnoDB`
- 估算行数：`183`
- 数据大小：`304 KB`
- 索引大小：`80 KB`
- 自增值：`214`
- 排序规则：`utf8mb4_unicode_ci`
- 表注释：—

#### 字段

| 序号 | 字段                          | 类型              | 可空 | 默认值    | 键  | 额外           | 字符集  | 排序规则           | 注释 |
| ---: | ----------------------------- | ----------------- | ---- | --------- | --- | -------------- | ------- | ------------------ | ---- |
|    1 | `id`                          | `bigint unsigned` | 否   | —         | PRI | auto_increment | —       | —                  | —    |
|    2 | `service_id`                  | `bigint unsigned` | 否   | —         | MUL | —              | —       | —                  | —    |
|    3 | `service_upstream_binding_id` | `bigint unsigned` | 是   | `NULL`    | MUL | —              | —       | —                  | —    |
|    4 | `plugin_id`                   | `bigint unsigned` | 是   | `NULL`    | MUL | —              | —       | —                  | —    |
|    5 | `provider_key`                | `varchar(120)`    | 是   | `NULL`    | MUL | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|    6 | `connection_type`             | `varchar(60)`     | 否   | `default` | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|    7 | `hostname`                    | `varchar(255)`    | 是   | `NULL`    | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|    8 | `ip_address`                  | `varchar(120)`    | 是   | `NULL`    | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|    9 | `port`                        | `int unsigned`    | 是   | `NULL`    | —   | —              | —       | —                  | —    |
|   10 | `connection_json`             | `json`            | 是   | `NULL`    | —   | —              | —       | —                  | —    |
|   11 | `secret_json`                 | `longtext`        | 是   | `NULL`    | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|   12 | `has_secret_json`             | `json`            | 是   | `NULL`    | —   | —              | —       | —                  | —    |
|   13 | `checked_at`                  | `timestamp`       | 是   | `NULL`    | —   | —              | —       | —                  | —    |
|   14 | `backfill_batch_id`           | `varchar(64)`     | 是   | `NULL`    | MUL | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|   15 | `created_at`                  | `timestamp`       | 是   | `NULL`    | —   | —              | —       | —                  | —    |
|   16 | `updated_at`                  | `timestamp`       | 是   | `NULL`    | —   | —              | —       | —                  | —    |

#### 索引

| 索引名                                                             | 唯一 | 类型    | 字段                              | 基数 | 注释 |
| ------------------------------------------------------------------ | ---- | ------- | --------------------------------- | ---: | ---- |
| `PRIMARY`                                                          | 是   | `BTREE` | `id`                              |  183 | —    |
| `service_connection_backfill_batch_idx`                            | 否   | `BTREE` | `backfill_batch_id`               |    2 | —    |
| `service_connection_plugin_checked_idx`                            | 否   | `BTREE` | `plugin_id`, `checked_at`         |  104 | —    |
| `service_connection_provider_type_idx`                             | 否   | `BTREE` | `provider_key`, `connection_type` |    1 | —    |
| `service_connection_service_type_unique`                           | 是   | `BTREE` | `service_id`, `connection_type`   |  183 | —    |
| `service_connection_snapshots_service_upstream_binding_id_foreign` | 否   | `BTREE` | `service_upstream_binding_id`     |  179 | —    |

#### 外键约束

| 约束名                                                             | 字段                          | 引用表                      | 引用字段 | 更新规则    | 删除规则   |
| ------------------------------------------------------------------ | ----------------------------- | --------------------------- | -------- | ----------- | ---------- |
| `service_connection_snapshots_plugin_id_foreign`                   | `plugin_id`                   | `integration_plugins`       | `id`     | `NO ACTION` | `SET NULL` |
| `service_connection_snapshots_service_id_foreign`                  | `service_id`                  | `services`                  | `id`     | `NO ACTION` | `CASCADE`  |
| `service_connection_snapshots_service_upstream_binding_id_foreign` | `service_upstream_binding_id` | `service_upstream_bindings` | `id`     | `NO ACTION` | `SET NULL` |

### 2.47 `service_provision_attempts`

- 类型：`BASE TABLE`
- 引擎：`InnoDB`
- 估算行数：`233`
- 数据大小：`112 KB`
- 索引大小：`80 KB`
- 自增值：`444`
- 排序规则：`utf8mb4_unicode_ci`
- 表注释：—

#### 字段

| 序号 | 字段                          | 类型              | 可空 | 默认值 | 键  | 额外           | 字符集  | 排序规则           | 注释 |
| ---: | ----------------------------- | ----------------- | ---- | ------ | --- | -------------- | ------- | ------------------ | ---- |
|    1 | `id`                          | `bigint unsigned` | 否   | —      | PRI | auto_increment | —       | —                  | —    |
|    2 | `service_id`                  | `bigint unsigned` | 是   | `NULL` | MUL | —              | —       | —                  | —    |
|    3 | `service_upstream_binding_id` | `bigint unsigned` | 是   | `NULL` | MUL | —              | —       | —                  | —    |
|    4 | `plugin_id`                   | `bigint unsigned` | 是   | `NULL` | MUL | —              | —       | —                  | —    |
|    5 | `provider_key`                | `varchar(120)`    | 是   | `NULL` | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|    6 | `action`                      | `varchar(80)`     | 否   | —      | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|    7 | `attempt_status`              | `varchar(30)`     | 否   | —      | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|    8 | `trace_id`                    | `varchar(64)`     | 是   | `NULL` | MUL | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|    9 | `request_meta_json`           | `json`            | 是   | `NULL` | —   | —              | —       | —                  | —    |
|   10 | `response_meta_json`          | `json`            | 是   | `NULL` | —   | —              | —       | —                  | —    |
|   11 | `error_code`                  | `varchar(80)`     | 是   | `NULL` | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|   12 | `error_message`               | `varchar(500)`    | 是   | `NULL` | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|   13 | `attempted_at`                | `timestamp`       | 是   | `NULL` | —   | —              | —       | —                  | —    |
|   14 | `backfill_batch_id`           | `varchar(64)`     | 是   | `NULL` | MUL | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|   15 | `created_at`                  | `timestamp`       | 是   | `NULL` | —   | —              | —       | —                  | —    |
|   16 | `updated_at`                  | `timestamp`       | 是   | `NULL` | —   | —              | —       | —                  | —    |

#### 索引

| 索引名                                                           | 唯一 | 类型    | 字段                                          | 基数 | 注释 |
| ---------------------------------------------------------------- | ---- | ------- | --------------------------------------------- | ---: | ---- |
| `PRIMARY`                                                        | 是   | `BTREE` | `id`                                          |  233 | —    |
| `service_attempt_backfill_batch_idx`                             | 否   | `BTREE` | `backfill_batch_id`                           |    2 | —    |
| `service_attempt_plugin_status_idx`                              | 否   | `BTREE` | `plugin_id`, `attempt_status`, `attempted_at` |  187 | —    |
| `service_attempt_service_action_idx`                             | 否   | `BTREE` | `service_id`, `action`, `attempted_at`        |  233 | —    |
| `service_attempt_trace_idx`                                      | 否   | `BTREE` | `trace_id`                                    |   95 | —    |
| `service_provision_attempts_service_upstream_binding_id_foreign` | 否   | `BTREE` | `service_upstream_binding_id`                 |  158 | —    |

#### 外键约束

| 约束名                                                           | 字段                          | 引用表                      | 引用字段 | 更新规则    | 删除规则   |
| ---------------------------------------------------------------- | ----------------------------- | --------------------------- | -------- | ----------- | ---------- |
| `service_provision_attempts_plugin_id_foreign`                   | `plugin_id`                   | `integration_plugins`       | `id`     | `NO ACTION` | `SET NULL` |
| `service_provision_attempts_service_id_foreign`                  | `service_id`                  | `services`                  | `id`     | `NO ACTION` | `SET NULL` |
| `service_provision_attempts_service_upstream_binding_id_foreign` | `service_upstream_binding_id` | `service_upstream_bindings` | `id`     | `NO ACTION` | `SET NULL` |

### 2.48 `service_runtime_snapshots`

- 类型：`BASE TABLE`
- 引擎：`InnoDB`
- 估算行数：`183`
- 数据大小：`272 KB`
- 索引大小：`80 KB`
- 自增值：`214`
- 排序规则：`utf8mb4_unicode_ci`
- 表注释：—

#### 字段

| 序号 | 字段                          | 类型              | 可空 | 默认值 | 键  | 额外           | 字符集  | 排序规则           | 注释 |
| ---: | ----------------------------- | ----------------- | ---- | ------ | --- | -------------- | ------- | ------------------ | ---- |
|    1 | `id`                          | `bigint unsigned` | 否   | —      | PRI | auto_increment | —       | —                  | —    |
|    2 | `service_id`                  | `bigint unsigned` | 否   | —      | UNI | —              | —       | —                  | —    |
|    3 | `service_upstream_binding_id` | `bigint unsigned` | 是   | `NULL` | MUL | —              | —       | —                  | —    |
|    4 | `plugin_id`                   | `bigint unsigned` | 是   | `NULL` | MUL | —              | —       | —                  | —    |
|    5 | `provider_key`                | `varchar(120)`    | 是   | `NULL` | MUL | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|    6 | `status_key`                  | `varchar(60)`     | 是   | `NULL` | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|    7 | `status_text`                 | `varchar(120)`    | 是   | `NULL` | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|    8 | `resource_json`               | `json`            | 是   | `NULL` | —   | —              | —       | —                  | —    |
|    9 | `metrics_json`                | `json`            | 是   | `NULL` | —   | —              | —       | —                  | —    |
|   10 | `snapshot_json`               | `json`            | 是   | `NULL` | —   | —              | —       | —                  | —    |
|   11 | `synced_at`                   | `timestamp`       | 是   | `NULL` | —   | —              | —       | —                  | —    |
|   12 | `backfill_batch_id`           | `varchar(64)`     | 是   | `NULL` | MUL | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|   13 | `created_at`                  | `timestamp`       | 是   | `NULL` | —   | —              | —       | —                  | —    |
|   14 | `updated_at`                  | `timestamp`       | 是   | `NULL` | —   | —              | —       | —                  | —    |

#### 索引

| 索引名                                                          | 唯一 | 类型    | 字段                          | 基数 | 注释 |
| --------------------------------------------------------------- | ---- | ------- | ----------------------------- | ---: | ---- |
| `PRIMARY`                                                       | 是   | `BTREE` | `id`                          |  183 | —    |
| `service_runtime_backfill_batch_idx`                            | 否   | `BTREE` | `backfill_batch_id`           |    2 | —    |
| `service_runtime_plugin_synced_idx`                             | 否   | `BTREE` | `plugin_id`, `synced_at`      |  100 | —    |
| `service_runtime_provider_status_idx`                           | 否   | `BTREE` | `provider_key`, `status_key`  |    3 | —    |
| `service_runtime_service_unique`                                | 是   | `BTREE` | `service_id`                  |  183 | —    |
| `service_runtime_snapshots_service_upstream_binding_id_foreign` | 否   | `BTREE` | `service_upstream_binding_id` |  179 | —    |

#### 外键约束

| 约束名                                                          | 字段                          | 引用表                      | 引用字段 | 更新规则    | 删除规则   |
| --------------------------------------------------------------- | ----------------------------- | --------------------------- | -------- | ----------- | ---------- |
| `service_runtime_snapshots_plugin_id_foreign`                   | `plugin_id`                   | `integration_plugins`       | `id`     | `NO ACTION` | `SET NULL` |
| `service_runtime_snapshots_service_id_foreign`                  | `service_id`                  | `services`                  | `id`     | `NO ACTION` | `CASCADE`  |
| `service_runtime_snapshots_service_upstream_binding_id_foreign` | `service_upstream_binding_id` | `service_upstream_bindings` | `id`     | `NO ACTION` | `SET NULL` |

### 2.49 `service_upstream_bindings`

- 类型：`BASE TABLE`
- 引擎：`InnoDB`
- 估算行数：`178`
- 数据大小：`144 KB`
- 索引大小：`112 KB`
- 自增值：`300`
- 排序规则：`utf8mb4_unicode_ci`
- 表注释：—

#### 字段

| 序号 | 字段                          | 类型              | 可空 | 默认值 | 键  | 额外           | 字符集  | 排序规则           | 注释 |
| ---: | ----------------------------- | ----------------- | ---- | ------ | --- | -------------- | ------- | ------------------ | ---- |
|    1 | `id`                          | `bigint unsigned` | 否   | —      | PRI | auto_increment | —       | —                  | —    |
|    2 | `service_id`                  | `bigint unsigned` | 否   | —      | MUL | —              | —       | —                  | —    |
|    3 | `product_upstream_binding_id` | `bigint unsigned` | 是   | `NULL` | MUL | —              | —       | —                  | —    |
|    4 | `supplier_plugin_binding_id`  | `bigint unsigned` | 是   | `NULL` | MUL | —              | —       | —                  | —    |
|    5 | `plugin_id`                   | `bigint unsigned` | 否   | —      | MUL | —              | —       | —                  | —    |
|    6 | `provider_key`                | `varchar(120)`    | 否   | —      | MUL | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|    7 | `upstream_service_id`         | `varchar(120)`    | 否   | —      | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|    8 | `upstream_account_id`         | `varchar(120)`    | 是   | `NULL` | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|    9 | `runtime_snapshot_json`       | `json`            | 是   | `NULL` | —   | —              | —       | —                  | —    |
|   10 | `connection_snapshot_json`    | `json`            | 是   | `NULL` | —   | —              | —       | —                  | —    |
|   11 | `status_snapshot`             | `varchar(60)`     | 是   | `NULL` | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|   12 | `last_synced_at`              | `timestamp`       | 是   | `NULL` | —   | —              | —       | —                  | —    |
|   13 | `last_sync_error`             | `varchar(500)`    | 是   | `NULL` | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|   14 | `backfill_batch_id`           | `varchar(64)`     | 是   | `NULL` | MUL | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|   15 | `created_at`                  | `timestamp`       | 是   | `NULL` | —   | —              | —       | —                  | —    |
|   16 | `updated_at`                  | `timestamp`       | 是   | `NULL` | —   | —              | —       | —                  | —    |

#### 索引

| 索引名                                                          | 唯一 | 类型    | 字段                                             | 基数 | 注释 |
| --------------------------------------------------------------- | ---- | ------- | ------------------------------------------------ | ---: | ---- |
| `PRIMARY`                                                       | 是   | `BTREE` | `id`                                             |  178 | —    |
| `service_upstream_backfill_batch_idx`                           | 否   | `BTREE` | `backfill_batch_id`                              |    2 | —    |
| `service_upstream_bindings_product_upstream_binding_id_foreign` | 否   | `BTREE` | `product_upstream_binding_id`                    |   49 | —    |
| `service_upstream_bindings_supplier_plugin_binding_id_foreign`  | 否   | `BTREE` | `supplier_plugin_binding_id`                     |    2 | —    |
| `service_upstream_plugin_sync_idx`                              | 否   | `BTREE` | `plugin_id`, `last_synced_at`                    |   99 | —    |
| `service_upstream_provider_status_idx`                          | 否   | `BTREE` | `provider_key`, `status_snapshot`                |    4 | —    |
| `service_upstream_service_idx`                                  | 否   | `BTREE` | `service_id`                                     |  178 | —    |
| `service_upstream_unique`                                       | 是   | `BTREE` | `service_id`, `plugin_id`, `upstream_service_id` |  178 | —    |

#### 外键约束

| 约束名                                                          | 字段                          | 引用表                      | 引用字段 | 更新规则    | 删除规则   |
| --------------------------------------------------------------- | ----------------------------- | --------------------------- | -------- | ----------- | ---------- |
| `service_upstream_bindings_plugin_id_foreign`                   | `plugin_id`                   | `integration_plugins`       | `id`     | `NO ACTION` | `RESTRICT` |
| `service_upstream_bindings_product_upstream_binding_id_foreign` | `product_upstream_binding_id` | `product_upstream_bindings` | `id`     | `NO ACTION` | `SET NULL` |
| `service_upstream_bindings_service_id_foreign`                  | `service_id`                  | `services`                  | `id`     | `NO ACTION` | `RESTRICT` |
| `service_upstream_bindings_supplier_plugin_binding_id_foreign`  | `supplier_plugin_binding_id`  | `supplier_plugin_bindings`  | `id`     | `NO ACTION` | `SET NULL` |

### 2.50 `services`

- 类型：`BASE TABLE`
- 引擎：`InnoDB`
- 估算行数：`138`
- 数据大小：`1.52 MB`
- 索引大小：`128 KB`
- 自增值：`314`
- 排序规则：`utf8mb4_unicode_ci`
- 表注释：服务实例表，记录用户已购买产品的生命周期、计费、上游和续费状态

#### 字段

| 序号 | 字段               | 类型              | 可空 | 默认值   | 键  | 额外           | 字符集  | 排序规则           | 注释                                              |
| ---: | ------------------ | ----------------- | ---- | -------- | --- | -------------- | ------- | ------------------ | ------------------------------------------------- |
|    1 | `id`               | `bigint unsigned` | 否   | —        | PRI | auto_increment | —       | —                  | 服务实例自增主键                                  |
|    2 | `user_id`          | `bigint unsigned` | 否   | —        | MUL | —              | —       | —                  | 所属用户ID                                        |
|    3 | `product_id`       | `bigint unsigned` | 否   | —        | MUL | —              | —       | —                  | 关联商品ID                                        |
|    4 | `order_id`         | `bigint unsigned` | 是   | `NULL`   | MUL | —              | —       | —                  | 内部订单/开通投影ID，仅用于流程追踪               |
|    5 | `invoice_id`       | `bigint unsigned` | 是   | `NULL`   | MUL | —              | —       | —                  | 最近一次关联账单ID                                |
|    6 | `name`             | `varchar(200)`    | 否   | 空字符串 | —   | —              | utf8mb4 | utf8mb4_unicode_ci | 服务自定义名称                                    |
|    7 | `domain`           | `varchar(200)`    | 否   | 空字符串 | —   | —              | utf8mb4 | utf8mb4_unicode_ci | 服务域名或主机名                                  |
|    8 | `billing_cycle`    | `varchar(20)`     | 否   | —        | —   | —              | utf8mb4 | utf8mb4_unicode_ci | 计费周期                                          |
|    9 | `amount`           | `decimal(12,2)`   | 否   | —        | —   | —              | —       | —                  | 服务续费/购买金额                                 |
|   10 | `locked_pricing`   | `json`            | 是   | `NULL`   | —   | —              | —       | —                  | 锁定续费定价 JSON，null 表示跟随商品定价          |
|   11 | `status`           | `tinyint`         | 否   | `0`      | MUL | —              | —       | —                  | 服务状态：0待开通 1运行中 2已暂停 3已到期 4已取消 |
|   12 | `provision_data`   | `json`            | 是   | `NULL`   | —   | —              | —       | —                  | 开通和上游实例数据 JSON                           |
|   13 | `expires_at`       | `timestamp`       | 是   | `NULL`   | MUL | —              | —       | —                  | 服务到期时间                                      |
|   14 | `auto_renew`       | `tinyint`         | 否   | `0`      | —   | —              | —       | —                  | 是否自动续费：0关闭 1开启                         |
|   15 | `suspended_reason` | `varchar(200)`    | 是   | `NULL`   | —   | —              | utf8mb4 | utf8mb4_unicode_ci | 暂停原因                                          |
|   16 | `created_at`       | `timestamp`       | 是   | `NULL`   | —   | —              | —       | —                  | 创建时间                                          |
|   17 | `updated_at`       | `timestamp`       | 是   | `NULL`   | —   | —              | —       | —                  | 更新时间                                          |
|   18 | `deleted_at`       | `timestamp`       | 是   | `NULL`   | —   | —              | —       | —                  | —                                                 |
|   19 | `remark`           | `varchar(255)`    | 是   | `NULL`   | —   | —              | utf8mb4 | utf8mb4_unicode_ci | 服务备注                                          |
|   20 | `operator`         | `varchar(50)`     | 是   | `NULL`   | —   | —              | utf8mb4 | utf8mb4_unicode_ci | 操作人快照                                        |
|   21 | `trace_id`         | `varchar(64)`     | 是   | `NULL`   | MUL | —              | utf8mb4 | utf8mb4_unicode_ci | 链路追踪号                                        |

#### 索引

| 索引名                              | 唯一 | 类型    | 字段                         | 基数 | 注释 |
| ----------------------------------- | ---- | ------- | ---------------------------- | ---: | ---- |
| `PRIMARY`                           | 是   | `BTREE` | `id`                         |  138 | —    |
| `services_expires_at_index`         | 否   | `BTREE` | `expires_at`                 |  138 | —    |
| `services_invoice_id_idx`           | 否   | `BTREE` | `invoice_id`                 |   48 | —    |
| `services_order_id_idx`             | 否   | `BTREE` | `order_id`                   |  138 | —    |
| `services_product_id_idx`           | 否   | `BTREE` | `product_id`                 |   49 | —    |
| `services_status_expires_at_id_idx` | 否   | `BTREE` | `status`, `expires_at`, `id` |  138 | —    |
| `services_trace_id_idx`             | 否   | `BTREE` | `trace_id`                   |   78 | —    |
| `services_user_id_index`            | 否   | `BTREE` | `user_id`                    |   97 | —    |
| `services_user_status_id_idx`       | 否   | `BTREE` | `user_id`, `status`, `id`    |  138 | —    |

#### 外键约束

| 约束名                        | 字段         | 引用表     | 引用字段 | 更新规则    | 删除规则   |
| ----------------------------- | ------------ | ---------- | -------- | ----------- | ---------- |
| `fk_services_invoice_id`      | `invoice_id` | `invoices` | `id`     | `NO ACTION` | `SET NULL` |
| `fk_services_product_id`      | `product_id` | `products` | `id`     | `NO ACTION` | `RESTRICT` |
| `fk_services_user_id`         | `user_id`    | `users`    | `id`     | `NO ACTION` | `RESTRICT` |
| `fk_stage2_services_order_id` | `order_id`   | `orders`   | `id`     | `NO ACTION` | `SET NULL` |

### 2.51 `sessions`

- 类型：`BASE TABLE`
- 引擎：`InnoDB`
- 估算行数：`0`
- 数据大小：`16 KB`
- 索引大小：`32 KB`
- 自增值：—
- 排序规则：`utf8mb4_unicode_ci`
- 表注释：—

#### 字段

| 序号 | 字段            | 类型              | 可空 | 默认值 | 键  | 额外 | 字符集  | 排序规则           | 注释 |
| ---: | --------------- | ----------------- | ---- | ------ | --- | ---- | ------- | ------------------ | ---- |
|    1 | `id`            | `varchar(255)`    | 否   | —      | PRI | —    | utf8mb4 | utf8mb4_unicode_ci | —    |
|    2 | `user_id`       | `bigint unsigned` | 是   | `NULL` | MUL | —    | —       | —                  | —    |
|    3 | `ip_address`    | `varchar(45)`     | 是   | `NULL` | —   | —    | utf8mb4 | utf8mb4_unicode_ci | —    |
|    4 | `user_agent`    | `text`            | 是   | `NULL` | —   | —    | utf8mb4 | utf8mb4_unicode_ci | —    |
|    5 | `payload`       | `longtext`        | 否   | —      | —   | —    | utf8mb4 | utf8mb4_unicode_ci | —    |
|    6 | `last_activity` | `int`             | 否   | —      | MUL | —    | —       | —                  | —    |

#### 索引

| 索引名                         | 唯一 | 类型    | 字段            | 基数 | 注释 |
| ------------------------------ | ---- | ------- | --------------- | ---: | ---- |
| `PRIMARY`                      | 是   | `BTREE` | `id`            |    0 | —    |
| `sessions_last_activity_index` | 否   | `BTREE` | `last_activity` |    0 | —    |
| `sessions_user_id_index`       | 否   | `BTREE` | `user_id`       |    0 | —    |

#### 外键约束

| 约束名                       | 字段      | 引用表  | 引用字段 | 更新规则    | 删除规则  |
| ---------------------------- | --------- | ------- | -------- | ----------- | --------- |
| `fk_stage2_sessions_user_id` | `user_id` | `users` | `id`     | `NO ACTION` | `CASCADE` |

### 2.52 `settings`

- 类型：`BASE TABLE`
- 引擎：`InnoDB`
- 估算行数：`113`
- 数据大小：`96 KB`
- 索引大小：`16 KB`
- 自增值：`343`
- 排序规则：`utf8mb4_unicode_ci`
- 表注释：—

#### 字段

| 序号 | 字段         | 类型              | 可空 | 默认值 | 键  | 额外           | 字符集  | 排序规则           | 注释 |
| ---: | ------------ | ----------------- | ---- | ------ | --- | -------------- | ------- | ------------------ | ---- |
|    1 | `id`         | `bigint unsigned` | 否   | —      | PRI | auto_increment | —       | —                  | —    |
|    2 | `group_key`  | `varchar(50)`     | 否   | —      | MUL | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|    3 | `item_key`   | `varchar(100)`    | 否   | —      | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|    4 | `item_value` | `text`            | 是   | `NULL` | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —    |

#### 索引

| 索引名                      | 唯一 | 类型    | 字段                    | 基数 | 注释 |
| --------------------------- | ---- | ------- | ----------------------- | ---: | ---- |
| `PRIMARY`                   | 是   | `BTREE` | `id`                    |  113 | —    |
| `settings_group_key_unique` | 是   | `BTREE` | `group_key`, `item_key` |  113 | —    |

#### 外键约束

无数据库级外键约束。

### 2.53 `supplier_plugin_bindings`

- 类型：`BASE TABLE`
- 引擎：`InnoDB`
- 估算行数：`2`
- 数据大小：`16 KB`
- 索引大小：`64 KB`
- 自增值：`105`
- 排序规则：`utf8mb4_unicode_ci`
- 表注释：—

#### 字段

| 序号 | 字段                | 类型               | 可空 | 默认值       | 键  | 额外           | 字符集  | 排序规则           | 注释 |
| ---: | ------------------- | ------------------ | ---- | ------------ | --- | -------------- | ------- | ------------------ | ---- |
|    1 | `id`                | `bigint unsigned`  | 否   | —            | PRI | auto_increment | —       | —                  | —    |
|    2 | `supplier_id`       | `bigint unsigned`  | 否   | —            | MUL | —              | —       | —                  | —    |
|    3 | `plugin_id`         | `bigint unsigned`  | 否   | —            | MUL | —              | —       | —                  | —    |
|    4 | `provider_key`      | `varchar(120)`     | 否   | —            | MUL | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|    5 | `environment`       | `varchar(30)`      | 否   | `production` | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|    6 | `status`            | `tinyint unsigned` | 否   | `1`          | —   | —              | —       | —                  | —    |
|    7 | `priority`          | `int`              | 否   | `0`          | —   | —              | —       | —                  | —    |
|    8 | `base_url`          | `varchar(255)`     | 是   | `NULL`       | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|    9 | `account_name`      | `varchar(120)`     | 是   | `NULL`       | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|   10 | `config_json`       | `json`             | 是   | `NULL`       | —   | —              | —       | —                  | —    |
|   11 | `secret_json`       | `longtext`         | 是   | `NULL`       | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|   12 | `has_secret_json`   | `json`             | 是   | `NULL`       | —   | —              | —       | —                  | —    |
|   13 | `last_checked_at`   | `timestamp`        | 是   | `NULL`       | —   | —              | —       | —                  | —    |
|   14 | `last_check_status` | `varchar(30)`      | 是   | `NULL`       | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|   15 | `last_check_error`  | `varchar(500)`     | 是   | `NULL`       | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|   16 | `created_by`        | `bigint unsigned`  | 是   | `NULL`       | —   | —              | —       | —                  | —    |
|   17 | `updated_by`        | `bigint unsigned`  | 是   | `NULL`       | —   | —              | —       | —                  | —    |
|   18 | `backfill_batch_id` | `varchar(64)`      | 是   | `NULL`       | MUL | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|   19 | `created_at`        | `timestamp`        | 是   | `NULL`       | —   | —              | —       | —                  | —    |
|   20 | `updated_at`        | `timestamp`        | 是   | `NULL`       | —   | —              | —       | —                  | —    |

#### 索引

| 索引名                                | 唯一 | 类型    | 字段                                      | 基数 | 注释 |
| ------------------------------------- | ---- | ------- | ----------------------------------------- | ---: | ---- |
| `PRIMARY`                             | 是   | `BTREE` | `id`                                      |    2 | —    |
| `supplier_plugin_backfill_batch_idx`  | 否   | `BTREE` | `backfill_batch_id`                       |    1 | —    |
| `supplier_plugin_plugin_status_idx`   | 否   | `BTREE` | `plugin_id`, `status`                     |    1 | —    |
| `supplier_plugin_provider_status_idx` | 否   | `BTREE` | `provider_key`, `status`                  |    1 | —    |
| `supplier_plugin_unique`              | 是   | `BTREE` | `supplier_id`, `plugin_id`, `environment` |    2 | —    |

#### 外键约束

| 约束名                                         | 字段          | 引用表                | 引用字段 | 更新规则    | 删除规则   |
| ---------------------------------------------- | ------------- | --------------------- | -------- | ----------- | ---------- |
| `supplier_plugin_bindings_plugin_id_foreign`   | `plugin_id`   | `integration_plugins` | `id`     | `NO ACTION` | `RESTRICT` |
| `supplier_plugin_bindings_supplier_id_foreign` | `supplier_id` | `suppliers`           | `id`     | `NO ACTION` | `RESTRICT` |

### 2.54 `suppliers`

- 类型：`BASE TABLE`
- 引擎：`InnoDB`
- 估算行数：`2`
- 数据大小：`16 KB`
- 索引大小：`32 KB`
- 自增值：`93`
- 排序规则：`utf8mb4_unicode_ci`
- 表注释：—

#### 字段

| 序号 | 字段            | 类型              | 可空 | 默认值 | 键  | 额外           | 字符集  | 排序规则           | 注释          |
| ---: | --------------- | ----------------- | ---- | ------ | --- | -------------- | ------- | ------------------ | ------------- |
|    1 | `id`            | `bigint unsigned` | 否   | —      | PRI | auto_increment | —       | —                  | —             |
|    2 | `name`          | `varchar(120)`    | 否   | —      | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —             |
|    3 | `code`          | `varchar(50)`     | 否   | —      | UNI | —              | utf8mb4 | utf8mb4_unicode_ci | —             |
|    4 | `contact_name`  | `varchar(60)`     | 是   | `NULL` | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —             |
|    5 | `contact_phone` | `varchar(30)`     | 是   | `NULL` | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —             |
|    6 | `contact_email` | `varchar(100)`    | 是   | `NULL` | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —             |
|    7 | `website`       | `varchar(255)`    | 是   | `NULL` | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —             |
|    8 | `status`        | `tinyint`         | 否   | `1`    | MUL | —              | —       | —                  | 0=停用 1=启用 |
|    9 | `sort_order`    | `int`             | 否   | `0`    | —   | —              | —       | —                  | —             |
|   10 | `notes`         | `text`            | 是   | `NULL` | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —             |
|   11 | `created_at`    | `timestamp`       | 是   | `NULL` | —   | —              | —       | —                  | —             |
|   12 | `updated_at`    | `timestamp`       | 是   | `NULL` | —   | —              | —       | —                  | —             |

#### 索引

| 索引名                              | 唯一 | 类型    | 字段                   | 基数 | 注释 |
| ----------------------------------- | ---- | ------- | ---------------------- | ---: | ---- |
| `PRIMARY`                           | 是   | `BTREE` | `id`                   |    2 | —    |
| `suppliers_code_unique`             | 是   | `BTREE` | `code`                 |    2 | —    |
| `suppliers_status_sort_order_index` | 否   | `BTREE` | `status`, `sort_order` |    1 | —    |

#### 外键约束

无数据库级外键约束。

### 2.55 `third_product_groups`

- 类型：`BASE TABLE`
- 引擎：`InnoDB`
- 估算行数：`26`
- 数据大小：`16 KB`
- 索引大小：`32 KB`
- 自增值：`49`
- 排序规则：`utf8mb4_unicode_ci`
- 表注释：—

#### 字段

| 序号 | 字段                      | 类型               | 可空 | 默认值 | 键  | 额外           | 字符集  | 排序规则           | 注释 |
| ---: | ------------------------- | ------------------ | ---- | ------ | --- | -------------- | ------- | ------------------ | ---- |
|    1 | `id`                      | `bigint unsigned`  | 否   | —      | PRI | auto_increment | —       | —                  | —    |
|    2 | `second_product_group_id` | `bigint unsigned`  | 否   | —      | MUL | —              | —       | —                  | —    |
|    3 | `name`                    | `varchar(100)`     | 否   | —      | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|    4 | `slug`                    | `varchar(100)`     | 是   | `NULL` | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|    5 | `description`             | `varchar(255)`     | 是   | `NULL` | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|    6 | `sort_order`              | `int`              | 否   | `0`    | —   | —              | —       | —                  | —    |
|    7 | `is_visible`              | `tinyint unsigned` | 否   | `1`    | —   | —              | —       | —                  | —    |
|    8 | `created_at`              | `timestamp`        | 是   | `NULL` | —   | —              | —       | —                  | —    |
|    9 | `updated_at`              | `timestamp`        | 是   | `NULL` | —   | —              | —       | —                  | —    |

#### 索引

| 索引名                          | 唯一 | 类型    | 字段                                                  | 基数 | 注释 |
| ------------------------------- | ---- | ------- | ----------------------------------------------------- | ---: | ---- |
| `idx_third_second_visible_sort` | 否   | `BTREE` | `second_product_group_id`, `is_visible`, `sort_order` |   19 | —    |
| `PRIMARY`                       | 是   | `BTREE` | `id`                                                  |   26 | —    |
| `uq_third_second_slug`          | 是   | `BTREE` | `second_product_group_id`, `slug`                     |   26 | —    |

#### 外键约束

| 约束名                  | 字段                      | 引用表                  | 引用字段 | 更新规则    | 删除规则   |
| ----------------------- | ------------------------- | ----------------------- | -------- | ----------- | ---------- |
| `fk_third_second_group` | `second_product_group_id` | `second_product_groups` | `id`     | `NO ACTION` | `RESTRICT` |

### 2.56 `ticket_replies`

- 类型：`BASE TABLE`
- 引擎：`InnoDB`
- 估算行数：`177`
- 数据大小：`48 KB`
- 索引大小：`32 KB`
- 自增值：`178`
- 排序规则：`utf8mb4_unicode_ci`
- 表注释：—

#### 字段

| 序号 | 字段             | 类型              | 可空 | 默认值              | 键  | 额外              | 字符集  | 排序规则           | 注释 |
| ---: | ---------------- | ----------------- | ---- | ------------------- | --- | ----------------- | ------- | ------------------ | ---- |
|    1 | `id`             | `bigint unsigned` | 否   | —                   | PRI | auto_increment    | —       | —                  | —    |
|    2 | `ticket_id`      | `bigint unsigned` | 否   | —                   | MUL | —                 | —       | —                  | —    |
|    3 | `user_id`        | `bigint unsigned` | 否   | —                   | —   | —                 | —       | —                  | —    |
|    4 | `content`        | `text`            | 否   | —                   | —   | —                 | utf8mb4 | utf8mb4_unicode_ci | —    |
|    5 | `is_staff`       | `tinyint`         | 否   | `0`                 | —   | —                 | —       | —                  | —    |
|    6 | `attachments`    | `json`            | 是   | `NULL`              | —   | —                 | —       | —                  | —    |
|    7 | `quote_reply_id` | `bigint unsigned` | 是   | `NULL`              | MUL | —                 | —       | —                  | —    |
|    8 | `recalled_at`    | `timestamp`       | 是   | `NULL`              | —   | —                 | —       | —                  | —    |
|    9 | `created_at`     | `timestamp`       | 否   | `CURRENT_TIMESTAMP` | —   | DEFAULT_GENERATED | —       | —                  | —    |

#### 索引

| 索引名                                     | 唯一 | 类型    | 字段                            | 基数 | 注释 |
| ------------------------------------------ | ---- | ------- | ------------------------------- | ---: | ---- |
| `idx_stage2_ticket_replies_quote_reply_id` | 否   | `BTREE` | `quote_reply_id`                |    2 | —    |
| `PRIMARY`                                  | 是   | `BTREE` | `id`                            |  177 | —    |
| `ticket_replies_ticket_created_id_idx`     | 否   | `BTREE` | `ticket_id`, `created_at`, `id` |  177 | —    |

#### 外键约束

| 约束名                                    | 字段             | 引用表           | 引用字段 | 更新规则    | 删除规则   |
| ----------------------------------------- | ---------------- | ---------------- | -------- | ----------- | ---------- |
| `fk_stage2_ticket_replies_quote_reply_id` | `quote_reply_id` | `ticket_replies` | `id`     | `NO ACTION` | `SET NULL` |
| `fk_ticket_replies_ticket_id`             | `ticket_id`      | `tickets`        | `id`     | `NO ACTION` | `CASCADE`  |

### 2.57 `tickets`

- 类型：`BASE TABLE`
- 引擎：`InnoDB`
- 估算行数：`69`
- 数据大小：`16 KB`
- 索引大小：`96 KB`
- 自增值：`76`
- 排序规则：`utf8mb4_unicode_ci`
- 表注释：—

#### 字段

| 序号 | 字段           | 类型              | 可空 | 默认值    | 键  | 额外           | 字符集  | 排序规则           | 注释                                  |
| ---: | -------------- | ----------------- | ---- | --------- | --- | -------------- | ------- | ------------------ | ------------------------------------- |
|    1 | `id`           | `bigint unsigned` | 否   | —         | PRI | auto_increment | —       | —                  | —                                     |
|    2 | `user_id`      | `bigint unsigned` | 否   | —         | MUL | —              | —       | —                  | —                                     |
|    3 | `department`   | `varchar(30)`     | 否   | `support` | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —                                     |
|    4 | `subject`      | `varchar(200)`    | 否   | —         | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —                                     |
|    5 | `priority`     | `tinyint`         | 否   | `1`       | —   | —              | —       | —                  | 1=低 2=中 3=高 4=紧急                 |
|    6 | `status`       | `tinyint`         | 否   | `0`       | MUL | —              | —       | —                  | 0=开启 1=客户回复 2=员工回复 3=已关闭 |
|    7 | `service_id`   | `bigint unsigned` | 是   | `NULL`    | MUL | —              | —       | —                  | —                                     |
|    8 | `assignee_id`  | `bigint unsigned` | 是   | `NULL`    | MUL | —              | —       | —                  | —                                     |
|    9 | `created_at`   | `timestamp`       | 是   | `NULL`    | —   | —              | —       | —                  | —                                     |
|   10 | `updated_at`   | `timestamp`       | 是   | `NULL`    | —   | —              | —       | —                  | —                                     |
|   11 | `close_reason` | `varchar(20)`     | 是   | `NULL`    | —   | —              | utf8mb4 | utf8mb4_unicode_ci | admin, client, auto                   |

#### 索引

| 索引名                               | 唯一 | 类型    | 字段                              | 基数 | 注释 |
| ------------------------------------ | ---- | ------- | --------------------------------- | ---: | ---- |
| `idx_stage2_tickets_assignee_id`     | 否   | `BTREE` | `assignee_id`                     |    2 | —    |
| `PRIMARY`                            | 是   | `BTREE` | `id`                              |   69 | —    |
| `tickets_service_id_idx`             | 否   | `BTREE` | `service_id`                      |   32 | —    |
| `tickets_status_updated_at_idx`      | 否   | `BTREE` | `status`, `updated_at`            |   67 | —    |
| `tickets_user_status_updated_at_idx` | 否   | `BTREE` | `user_id`, `status`, `updated_at` |   69 | —    |
| `tickets_user_updated_at_idx`        | 否   | `BTREE` | `user_id`, `updated_at`, `id`     |   69 | —    |

#### 外键约束

| 约束名                          | 字段          | 引用表        | 引用字段 | 更新规则    | 删除规则   |
| ------------------------------- | ------------- | ------------- | -------- | ----------- | ---------- |
| `fk_stage2_tickets_assignee_id` | `assignee_id` | `admin_users` | `id`     | `NO ACTION` | `SET NULL` |
| `fk_stage2_tickets_service_id`  | `service_id`  | `services`    | `id`     | `NO ACTION` | `SET NULL` |
| `fk_tickets_user_id`            | `user_id`     | `users`       | `id`     | `NO ACTION` | `RESTRICT` |

### 2.58 `user_accounts`

- 类型：`BASE TABLE`
- 引擎：`InnoDB`
- 估算行数：`411`
- 数据大小：`64 KB`
- 索引大小：`0 B`
- 自增值：—
- 排序规则：`utf8mb4_unicode_ci`
- 表注释：用户账户余额源表，集中承载现金余额、授信和推荐奖励余额

#### 字段

| 序号 | 字段                                  | 类型              | 可空 | 默认值 | 键  | 额外 | 字符集 | 排序规则 | 注释                     |
| ---: | ------------------------------------- | ----------------- | ---- | ------ | --- | ---- | ------ | -------- | ------------------------ |
|    1 | `user_id`                             | `bigint unsigned` | 否   | —      | PRI | —    | —      | —        | 用户ID，同时作为账户主键 |
|    2 | `cash_balance`                        | `decimal(12,2)`   | 否   | `0.00` | —   | —    | —      | —        | 现金余额                 |
|    3 | `credit_limit`                        | `decimal(12,2)`   | 否   | `0.00` | —   | —    | —      | —        | 授信额度                 |
|    4 | `referral_frozen_balance`             | `decimal(12,2)`   | 否   | `0.00` | —   | —    | —      | —        | 冻结中的推荐奖励余额     |
|    5 | `referral_available_balance`          | `decimal(12,2)`   | 否   | `0.00` | —   | —    | —      | —        | 可用推荐奖励余额         |
|    6 | `referral_pending_withdrawal_balance` | `decimal(12,2)`   | 否   | `0.00` | —   | —    | —      | —        | 提现审核中的推荐奖励余额 |
|    7 | `referral_withdrawn_balance`          | `decimal(12,2)`   | 否   | `0.00` | —   | —    | —      | —        | 已提现推荐奖励累计金额   |
|    8 | `version`                             | `int unsigned`    | 否   | `0`    | —   | —    | —      | —        | 乐观锁版本号             |
|    9 | `created_at`                          | `timestamp`       | 是   | `NULL` | —   | —    | —      | —        | 创建时间                 |
|   10 | `updated_at`                          | `timestamp`       | 是   | `NULL` | —   | —    | —      | —        | 更新时间                 |

#### 索引

| 索引名    | 唯一 | 类型    | 字段      | 基数 | 注释 |
| --------- | ---- | ------- | --------- | ---: | ---- |
| `PRIMARY` | 是   | `BTREE` | `user_id` |  411 | —    |

#### 外键约束

| 约束名                     | 字段      | 引用表  | 引用字段 | 更新规则    | 删除规则   |
| -------------------------- | --------- | ------- | -------- | ----------- | ---------- |
| `fk_user_accounts_user_id` | `user_id` | `users` | `id`     | `NO ACTION` | `RESTRICT` |

### 2.59 `user_coupons`

- 类型：`BASE TABLE`
- 引擎：`InnoDB`
- 估算行数：`25`
- 数据大小：`16 KB`
- 索引大小：`64 KB`
- 自增值：`90`
- 排序规则：`utf8mb4_unicode_ci`
- 表注释：—

#### 字段

| 序号 | 字段             | 类型              | 可空 | 默认值  | 键  | 额外           | 字符集  | 排序规则           | 注释                                 |
| ---: | ---------------- | ----------------- | ---- | ------- | --- | -------------- | ------- | ------------------ | ------------------------------------ |
|    1 | `id`             | `bigint unsigned` | 否   | —       | PRI | auto_increment | —       | —                  | —                                    |
|    2 | `uid`            | `varchar(32)`     | 是   | `NULL`  | UNI | —              | utf8mb4 | utf8mb4_unicode_ci | —                                    |
|    3 | `coupon_id`      | `bigint unsigned` | 否   | —       | MUL | —              | —       | —                  | —                                    |
|    4 | `user_id`        | `bigint unsigned` | 否   | —       | MUL | —              | —       | —                  | —                                    |
|    5 | `receive_type`   | `varchar(20)`     | 否   | `claim` | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —                                    |
|    6 | `status`         | `tinyint`         | 否   | `1`     | —   | —              | —       | —                  | 优惠券状态：1=持有 2=已使用 3=已回收 |
|    7 | `claimed_at`     | `timestamp`       | 是   | `NULL`  | —   | —              | —       | —                  | —                                    |
|    8 | `used_at`        | `timestamp`       | 是   | `NULL`  | —   | —              | —       | —                  | —                                    |
|    9 | `revoked_at`     | `timestamp`       | 是   | `NULL`  | —   | —              | —       | —                  | —                                    |
|   10 | `reserved_until` | `timestamp`       | 是   | `NULL`  | —   | —              | —       | —                  | —                                    |
|   11 | `granted_at`     | `timestamp`       | 是   | `NULL`  | —   | —              | —       | —                  | —                                    |
|   12 | `last_used_at`   | `timestamp`       | 是   | `NULL`  | —   | —              | —       | —                  | —                                    |
|   13 | `remark`         | `varchar(255)`    | 是   | `NULL`  | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —                                    |
|   14 | `operator`       | `varchar(100)`    | 是   | `NULL`  | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —                                    |
|   15 | `trace_id`       | `varchar(100)`    | 是   | `NULL`  | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —                                    |
|   16 | `created_at`     | `timestamp`       | 是   | `NULL`  | —   | —              | —       | —                  | —                                    |
|   17 | `updated_at`     | `timestamp`       | 是   | `NULL`  | —   | —              | —       | —                  | —                                    |

#### 索引

| 索引名                            | 唯一 | 类型    | 字段                   | 基数 | 注释 |
| --------------------------------- | ---- | ------- | ---------------------- | ---: | ---- |
| `PRIMARY`                         | 是   | `BTREE` | `id`                   |   25 | —    |
| `user_coupons_coupon_status_idx`  | 否   | `BTREE` | `coupon_id`, `status`  |    4 | —    |
| `user_coupons_coupon_user_unique` | 是   | `BTREE` | `coupon_id`, `user_id` |   25 | —    |
| `user_coupons_uid_unique`         | 是   | `BTREE` | `uid`                  |   25 | —    |
| `user_coupons_user_status_idx`    | 否   | `BTREE` | `user_id`, `status`    |   23 | —    |

#### 外键约束

| 约束名                           | 字段        | 引用表    | 引用字段 | 更新规则    | 删除规则   |
| -------------------------------- | ----------- | --------- | -------- | ----------- | ---------- |
| `fk_stage2_user_coupons_user_id` | `user_id`   | `users`   | `id`     | `NO ACTION` | `RESTRICT` |
| `fk_user_coupons_coupon_id`      | `coupon_id` | `coupons` | `id`     | `NO ACTION` | `RESTRICT` |

### 2.60 `user_notifications`

- 类型：`BASE TABLE`
- 引擎：`InnoDB`
- 估算行数：`108`
- 数据大小：`64 KB`
- 索引大小：`48 KB`
- 自增值：`158`
- 排序规则：`utf8mb4_unicode_ci`
- 表注释：—

#### 字段

| 序号 | 字段         | 类型              | 可空 | 默认值 | 键  | 额外           | 字符集  | 排序规则           | 注释                                                                   |
| ---: | ------------ | ----------------- | ---- | ------ | --- | -------------- | ------- | ------------------ | ---------------------------------------------------------------------- |
|    1 | `id`         | `bigint unsigned` | 否   | —      | PRI | auto_increment | —       | —                  | —                                                                      |
|    2 | `user_id`    | `bigint unsigned` | 否   | —      | MUL | —              | —       | —                  | —                                                                      |
|    3 | `type`       | `varchar(50)`     | 否   | —      | MUL | —              | utf8mb4 | utf8mb4_unicode_ci | 消息类型：order_paid/service_renew_reminder/service_expire_reminder 等 |
|    4 | `title`      | `varchar(191)`    | 否   | —      | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —                                                                      |
|    5 | `content`    | `text`            | 是   | `NULL` | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —                                                                      |
|    6 | `link`       | `varchar(255)`    | 是   | `NULL` | —   | —              | utf8mb4 | utf8mb4_unicode_ci | 点击跳转的前端路由                                                     |
|    7 | `data`       | `json`            | 是   | `NULL` | —   | —              | —       | —                  | 附加业务数据                                                           |
|    8 | `read_at`    | `timestamp`       | 是   | `NULL` | —   | —              | —       | —                  | —                                                                      |
|    9 | `created_at` | `timestamp`       | 是   | `NULL` | —   | —              | —       | —                  | —                                                                      |
|   10 | `updated_at` | `timestamp`       | 是   | `NULL` | —   | —              | —       | —                  | —                                                                      |

#### 索引

| 索引名                                        | 唯一 | 类型    | 字段                    | 基数 | 注释 |
| --------------------------------------------- | ---- | ------- | ----------------------- | ---: | ---- |
| `PRIMARY`                                     | 是   | `BTREE` | `id`                    |  108 | —    |
| `user_notifications_type_index`               | 否   | `BTREE` | `type`                  |    6 | —    |
| `user_notifications_user_id_created_at_index` | 否   | `BTREE` | `user_id`, `created_at` |  107 | —    |
| `user_notifications_user_id_read_at_index`    | 否   | `BTREE` | `user_id`, `read_at`    |   58 | —    |

#### 外键约束

| 约束名                                 | 字段      | 引用表  | 引用字段 | 更新规则    | 删除规则  |
| -------------------------------------- | --------- | ------- | -------- | ----------- | --------- |
| `fk_stage2_user_notifications_user_id` | `user_id` | `users` | `id`     | `NO ACTION` | `CASCADE` |

### 2.61 `users`

- 类型：`BASE TABLE`
- 引擎：`InnoDB`
- 估算行数：`482`
- 数据大小：`128 KB`
- 索引大小：`176 KB`
- 自增值：`484`
- 排序规则：`utf8mb4_unicode_ci`
- 表注释：—

#### 字段

| 序号 | 字段                      | 类型              | 可空 | 默认值   | 键  | 额外           | 字符集  | 排序规则           | 注释                                  |
| ---: | ------------------------- | ----------------- | ---- | -------- | --- | -------------- | ------- | ------------------ | ------------------------------------- |
|    1 | `id`                      | `bigint unsigned` | 否   | —        | PRI | auto_increment | —       | —                  | —                                     |
|    2 | `email`                   | `varchar(100)`    | 是   | `NULL`   | UNI | —              | utf8mb4 | utf8mb4_unicode_ci | —                                     |
|    3 | `password`                | `varchar(255)`    | 否   | —        | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —                                     |
|    4 | `nickname`                | `varchar(50)`     | 否   | 空字符串 | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —                                     |
|    5 | `phone`                   | `varchar(20)`     | 是   | `NULL`   | UNI | —              | utf8mb4 | utf8mb4_unicode_ci | —                                     |
|    6 | `company`                 | `varchar(100)`    | 否   | 空字符串 | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —                                     |
|    7 | `qq`                      | `varchar(30)`     | 否   | 空字符串 | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —                                     |
|    8 | `alipay_real_name`        | `varchar(80)`     | 否   | 空字符串 | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —                                     |
|    9 | `alipay_account`          | `varchar(20)`     | 否   | 空字符串 | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —                                     |
|   10 | `referral_code`           | `varchar(24)`     | 是   | `NULL`   | UNI | —              | utf8mb4 | utf8mb4_unicode_ci | —                                     |
|   11 | `referrer_user_id`        | `bigint unsigned` | 是   | `NULL`   | MUL | —              | —       | —                  | —                                     |
|   12 | `member_level_id`         | `bigint unsigned` | 是   | `NULL`   | MUL | —              | —       | —                  | —                                     |
|   13 | `total_sales_amount`      | `decimal(12,2)`   | 否   | `0.00`   | —   | —              | —       | —                  | —                                     |
|   14 | `referred_at`             | `timestamp`       | 是   | `NULL`   | —   | —              | —       | —                  | —                                     |
|   15 | `status`                  | `tinyint`         | 否   | `1`      | MUL | —              | —       | —                  | 0=禁用 1=正常                         |
|   16 | `login_email_alert`       | `tinyint`         | 否   | `1`      | —   | —              | —       | —                  | 登录邮件提醒 0关闭 1开启              |
|   17 | `login_notify`            | `tinyint(1)`      | 否   | `1`      | —   | —              | —       | —                  | 账号登录提醒 0关闭 1开启              |
|   18 | `login_location_alert`    | `tinyint(1)`      | 否   | `1`      | —   | —              | —       | —                  | 异地登录提醒 0关闭 1开启              |
|   19 | `password_change_alert`   | `tinyint(1)`      | 否   | `1`      | —   | —              | —       | —                  | 密码变更提醒 0关闭 1开启              |
|   20 | `phone_change_alert`      | `tinyint(1)`      | 否   | `1`      | —   | —              | —       | —                  | 手机号变更提醒 0关闭 1开启            |
|   21 | `email_change_alert`      | `tinyint(1)`      | 否   | `1`      | —   | —              | —       | —                  | 邮箱变更提醒 0关闭 1开启              |
|   22 | `marketing_alert`         | `tinyint(1)`      | 否   | `0`      | —   | —              | —       | —                  | 营销提醒接收 0关闭 1开启              |
|   23 | `is_verified`             | `tinyint`         | 否   | `0`      | MUL | —              | —       | —                  | 0=未认证 1=已认证                     |
|   24 | `real_name`               | `varchar(50)`     | 否   | 空字符串 | —   | —              | utf8mb4 | utf8mb4_unicode_ci | 真实姓名                              |
|   25 | `id_card`                 | `varchar(512)`    | 否   | 空字符串 | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —                                     |
|   26 | `verification_status`     | `tinyint`         | 否   | `0`      | MUL | —              | —       | —                  | 0=未认证 1=认证中 2=已认证 3=认证失败 |
|   27 | `verification_message`    | `varchar(255)`    | 否   | 空字符串 | —   | —              | utf8mb4 | utf8mb4_unicode_ci | 实名认证状态描述                      |
|   28 | `verification_certify_id` | `varchar(100)`    | 是   | `NULL`   | MUL | —              | utf8mb4 | utf8mb4_unicode_ci | 实名认证平台 certify_id               |
|   29 | `verified_at`             | `timestamp`       | 是   | `NULL`   | —   | —              | —       | —                  | 实名认证通过时间                      |
|   30 | `last_login_ip`           | `varchar(45)`     | 是   | `NULL`   | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —                                     |
|   31 | `last_login_at`           | `timestamp`       | 是   | `NULL`   | —   | —              | —       | —                  | —                                     |
|   32 | `admin_note`              | `text`            | 是   | `NULL`   | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —                                     |
|   33 | `created_at`              | `timestamp`       | 是   | `NULL`   | MUL | —              | —       | —                  | —                                     |
|   34 | `updated_at`              | `timestamp`       | 是   | `NULL`   | —   | —              | —       | —                  | —                                     |
|   35 | `deleted_at`              | `timestamp`       | 是   | `NULL`   | —   | —              | —       | —                  | —                                     |

#### 索引

| 索引名                              | 唯一 | 类型    | 字段                                       | 基数 | 注释 |
| ----------------------------------- | ---- | ------- | ------------------------------------------ | ---: | ---- |
| `PRIMARY`                           | 是   | `BTREE` | `id`                                       |  482 | —    |
| `users_created_at_idx`              | 否   | `BTREE` | `created_at`                               |  482 | —    |
| `users_email_unique`                | 是   | `BTREE` | `email`                                    |  329 | —    |
| `users_member_level_id_index`       | 否   | `BTREE` | `member_level_id`                          |    2 | —    |
| `users_phone_unique`                | 是   | `BTREE` | `phone`                                    |  180 | —    |
| `users_referral_code_unique`        | 是   | `BTREE` | `referral_code`                            |  151 | —    |
| `users_referrer_user_id_index`      | 否   | `BTREE` | `referrer_user_id`                         |    5 | —    |
| `users_status_id_idx`               | 否   | `BTREE` | `status`, `id`                             |  482 | —    |
| `users_verification_certify_id_idx` | 否   | `BTREE` | `verification_certify_id`                  |  113 | —    |
| `users_verification_mix_idx`        | 否   | `BTREE` | `is_verified`, `verification_status`, `id` |  482 | —    |
| `users_verification_status_id_idx`  | 否   | `BTREE` | `verification_status`, `id`                |  482 | —    |

#### 外键约束

| 约束名                             | 字段               | 引用表          | 引用字段 | 更新规则    | 删除规则   |
| ---------------------------------- | ------------------ | --------------- | -------- | ----------- | ---------- |
| `fk_stage2_users_member_level_id`  | `member_level_id`  | `member_levels` | `id`     | `NO ACTION` | `SET NULL` |
| `fk_stage2_users_referrer_user_id` | `referrer_user_id` | `users`         | `id`     | `NO ACTION` | `SET NULL` |

### 2.62 `verification_histories`

- 类型：`BASE TABLE`
- 引擎：`InnoDB`
- 估算行数：`97`
- 数据大小：`64 KB`
- 索引大小：`48 KB`
- 自增值：`103`
- 排序规则：`utf8mb4_unicode_ci`
- 表注释：—

#### 字段

| 序号 | 字段                      | 类型              | 可空 | 默认值              | 键  | 额外              | 字符集  | 排序规则           | 注释                         |
| ---: | ------------------------- | ----------------- | ---- | ------------------- | --- | ----------------- | ------- | ------------------ | ---------------------------- |
|    1 | `id`                      | `bigint unsigned` | 否   | —                   | PRI | auto_increment    | —       | —                  | —                            |
|    2 | `user_id`                 | `bigint unsigned` | 否   | —                   | MUL | —                 | —       | —                  | —                            |
|    3 | `real_name`               | `varchar(50)`     | 否   | 空字符串            | —   | —                 | utf8mb4 | utf8mb4_unicode_ci | —                            |
|    4 | `id_card`                 | `varchar(512)`    | 否   | 空字符串            | —   | —                 | utf8mb4 | utf8mb4_unicode_ci | —                            |
|    5 | `verification_status`     | `tinyint`         | 否   | `1`                 | —   | —                 | —       | —                  | 1=认证中 2=已认证 3=认证失败 |
|    6 | `verification_message`    | `varchar(255)`    | 否   | 空字符串            | —   | —                 | utf8mb4 | utf8mb4_unicode_ci | —                            |
|    7 | `verification_certify_id` | `varchar(100)`    | 是   | `NULL`              | MUL | —                 | utf8mb4 | utf8mb4_unicode_ci | —                            |
|    8 | `verification_biz_code`   | `varchar(30)`     | 否   | `FACE`              | —   | —                 | utf8mb4 | utf8mb4_unicode_ci | —                            |
|    9 | `verification_type`       | `varchar(20)`     | 否   | `personal`          | —   | —                 | utf8mb4 | utf8mb4_unicode_ci | —                            |
|   10 | `submitted_at`            | `timestamp`       | 否   | `CURRENT_TIMESTAMP` | —   | DEFAULT_GENERATED | —       | —                  | —                            |
|   11 | `completed_at`            | `timestamp`       | 是   | `NULL`              | —   | —                 | —       | —                  | —                            |
|   12 | `created_at`              | `timestamp`       | 是   | `NULL`              | —   | —                 | —       | —                  | —                            |
|   13 | `updated_at`              | `timestamp`       | 是   | `NULL`              | —   | —                 | —       | —                  | —                            |

#### 索引

| 索引名                                                 | 唯一 | 类型    | 字段                      | 基数 | 注释 |
| ------------------------------------------------------ | ---- | ------- | ------------------------- | ---: | ---- |
| `PRIMARY`                                              | 是   | `BTREE` | `id`                      |   97 | —    |
| `verification_histories_user_id_id_idx`                | 否   | `BTREE` | `user_id`, `id`           |   97 | —    |
| `verification_histories_user_id_submitted_at_index`    | 否   | `BTREE` | `user_id`, `submitted_at` |   64 | —    |
| `verification_histories_verification_certify_id_index` | 否   | `BTREE` | `verification_certify_id` |   93 | —    |

#### 外键约束

| 约束名                                     | 字段      | 引用表  | 引用字段 | 更新规则    | 删除规则   |
| ------------------------------------------ | --------- | ------- | -------- | ----------- | ---------- |
| `fk_stage2_verification_histories_user_id` | `user_id` | `users` | `id`     | `NO ACTION` | `RESTRICT` |
