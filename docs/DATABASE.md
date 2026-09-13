# 当前数据库结构说明

- 文档性质：参考资料 / 实库结构快照
- 生成时间：`2026-09-13 10:33:39 +08:00`
- 数据来源：Laravel 默认连接 `mysql` 直连 MySQL `information_schema` 与业务库 `idc_test`
- 数据库：`idc_test`
- MySQL 版本：`5.7.26`
- 当前表数量：`75`
- 字段数量：`1047`
- 索引数量：`358`
- 外键约束数量：`103`
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
| `account_transactions`            | BASE TABLE | InnoDB |        9 |    16 KB |    96 KB |     1,759 | utf8mb4_unicode_ci | 账户流水表，记录现金账户、授信账户、推荐奖励账户的每一次余额变化             |
| `activity_logs`                   | BASE TABLE | InnoDB |      375 |   208 KB |    96 KB | 1,036,400 | utf8mb4_unicode_ci | —                                                                            |
| `admin_users`                     | BASE TABLE | InnoDB |      210 |    80 KB |    48 KB |       588 | utf8mb4_unicode_ci | —                                                                            |
| `admin_user_roles`                | BASE TABLE | InnoDB |        1 |    16 KB |    32 KB |       210 | utf8mb4_unicode_ci | —                                                                            |
| `agent_applications`              | BASE TABLE | InnoDB |        0 |    16 KB |    32 KB |         1 | utf8mb4_unicode_ci | —                                                                            |
| `agent_groups`                    | BASE TABLE | InnoDB |        1 |    16 KB |    16 KB |         6 | utf8mb4_unicode_ci | —                                                                            |
| `agent_group_discounts`           | BASE TABLE | InnoDB |        1 |    16 KB |    32 KB |         5 | utf8mb4_unicode_ci | —                                                                            |
| `api_keys`                        | BASE TABLE | InnoDB |        0 |    16 KB |    48 KB |         1 | utf8mb4_unicode_ci | —                                                                            |
| `api_key_usage_logs`              | BASE TABLE | InnoDB |        0 |    16 KB |    32 KB |         1 | utf8mb4_unicode_ci | —                                                                            |
| `archive_audit_logs`              | BASE TABLE | InnoDB |        0 |    16 KB |    32 KB |         2 | utf8mb4_unicode_ci | —                                                                            |
| `automation_logs`                 | BASE TABLE | InnoDB |        0 |    16 KB |    48 KB |     1,290 | utf8mb4_unicode_ci | —                                                                            |
| `content_articles`                | BASE TABLE | InnoDB |        8 |    16 KB |    96 KB |        45 | utf8mb4_unicode_ci | —                                                                            |
| `content_categories`              | BASE TABLE | InnoDB |        6 |    16 KB |    48 KB |        19 | utf8mb4_unicode_ci | —                                                                            |
| `coupons`                         | BASE TABLE | InnoDB |       14 |    16 KB |    48 KB |       106 | utf8mb4_unicode_ci | —                                                                            |
| `coupon_campaigns`                | BASE TABLE | InnoDB |        3 |    16 KB |    48 KB |        19 | utf8mb4_unicode_ci | —                                                                            |
| `failed_jobs`                     | BASE TABLE | InnoDB |        0 |    16 KB |    16 KB |       277 | utf8mb4_unicode_ci | —                                                                            |
| `first_product_groups`            | BASE TABLE | InnoDB |       36 |    16 KB |    32 KB |       110 | utf8mb4_unicode_ci | —                                                                            |
| `gateway_logs`                    | BASE TABLE | InnoDB |        5 |    16 KB |   112 KB | 1,000,083 | utf8mb4_unicode_ci | —                                                                            |
| `integration_plugins`             | BASE TABLE | InnoDB |        1 |    16 KB |    48 KB |        38 | utf8mb4_unicode_ci | —                                                                            |
| `integration_plugin_bindings`     | BASE TABLE | InnoDB |        1 |    16 KB |    80 KB |         3 | utf8mb4_unicode_ci | —                                                                            |
| `integration_plugin_configs`      | BASE TABLE | InnoDB |        0 |    16 KB |    16 KB |        37 | utf8mb4_unicode_ci | —                                                                            |
| `integration_plugin_runtime_logs` | BASE TABLE | InnoDB |        8 |    16 KB |    80 KB | 1,014,097 | utf8mb4_unicode_ci | —                                                                            |
| `invoices`                        | BASE TABLE | InnoDB |       45 |    16 KB |   208 KB |     3,068 | utf8mb4_unicode_ci | 账单主表，所有购买、续费、充值、扣款和退款流程以账单为财务入口               |
| `invoice_items`                   | BASE TABLE | InnoDB |        2 |    16 KB |    16 KB |     2,799 | utf8mb4_unicode_ci | 账单明细表，记录账单内每个收费项目和快照信息                                 |
| `jobs`                            | BASE TABLE | InnoDB |        0 |    16 KB |    16 KB |     4,002 | utf8mb4_unicode_ci | —                                                                            |
| `media_files`                     | BASE TABLE | InnoDB |        0 |    16 KB |    48 KB |        68 | utf8mb4_unicode_ci | —                                                                            |
| `member_levels`                   | BASE TABLE | InnoDB |        0 |    16 KB |    48 KB |        12 | utf8mb4_unicode_ci | —                                                                            |
| `message_logs`                    | BASE TABLE | InnoDB |        2 |    16 KB |   112 KB |     2,616 | utf8mb4_unicode_ci | —                                                                            |
| `migrations`                      | BASE TABLE | InnoDB |      206 |    48 KB |      0 B |       209 | utf8mb4_unicode_ci | —                                                                            |
| `notice_reads`                    | BASE TABLE | InnoDB |        2 |    16 KB |    32 KB |       175 | utf8mb4_unicode_ci | —                                                                            |
| `notification_templates`          | BASE TABLE | InnoDB |        0 |    16 KB |    32 KB |     1,013 | utf8mb4_unicode_ci | —                                                                            |
| `operation_logs`                  | BASE TABLE | InnoDB |      373 |   192 KB |    80 KB |   166,161 | utf8mb4_unicode_ci | —                                                                            |
| `orders`                          | BASE TABLE | InnoDB |       26 |    16 KB |   176 KB |     3,113 | utf8mb4_unicode_ci | —                                                                            |
| `password_reset_tokens`           | BASE TABLE | InnoDB |        0 |    16 KB |      0 B |         — | utf8mb4_unicode_ci | —                                                                            |
| `payments`                        | BASE TABLE | InnoDB |       21 |    16 KB |   160 KB |       389 | utf8mb4_unicode_ci | 第三方支付记录表，仅记录真实外部资金流入和退款状态，不记录余额/免费/手工开服 |
| `payment_callbacks`               | BASE TABLE | InnoDB |        3 |    16 KB |    96 KB |       491 | utf8mb4_unicode_ci | 支付回调审计表，保存第三方通知、查询、退款等回调验签结果                     |
| `personal_access_tokens`          | BASE TABLE | InnoDB |        1 |    16 KB |    48 KB |       176 | utf8mb4_unicode_ci | —                                                                            |
| `products`                        | BASE TABLE | InnoDB |      210 |    80 KB |    48 KB |       623 | utf8mb4_unicode_ci | 商品表，记录可售卖产品的分类、定价、库存、上游绑定和开通策略                 |
| `product_discount_groups`         | BASE TABLE | InnoDB |        1 |    16 KB |    16 KB |         4 | utf8mb4_unicode_ci | —                                                                            |
| `product_upstream_bindings`       | BASE TABLE | InnoDB |        3 |    16 KB |    96 KB |       284 | utf8mb4_unicode_ci | —                                                                            |
| `recharge_records`                | BASE TABLE | InnoDB |        0 |    16 KB |   144 KB |         2 | utf8mb4_unicode_ci | —                                                                            |
| `referral_account_logs`           | BASE TABLE | InnoDB |        0 |    16 KB |    64 KB |         7 | utf8mb4_unicode_ci | —                                                                            |
| `referral_rewards`                | BASE TABLE | InnoDB |        0 |    16 KB |    96 KB |        13 | utf8mb4_unicode_ci | —                                                                            |
| `referral_withdrawals`            | BASE TABLE | InnoDB |        3 |    16 KB |    48 KB |         9 | utf8mb4_unicode_ci | —                                                                            |
| `refunds`                         | BASE TABLE | InnoDB |        0 |    16 KB |    96 KB |         1 | utf8mb4_unicode_ci | —                                                                            |
| `roles`                           | BASE TABLE | InnoDB |      218 |    64 KB |    16 KB |       686 | utf8mb4_unicode_ci | —                                                                            |
| `schedule_run_logs`               | BASE TABLE | InnoDB |        4 |    16 KB |    48 KB |   151,570 | utf8mb4_unicode_ci | —                                                                            |
| `schedule_task_runs`              | BASE TABLE | InnoDB |        0 |    16 KB |    96 KB |     3,359 | utf8mb4_unicode_ci | —                                                                            |
| `schedule_ticks`                  | BASE TABLE | InnoDB |        0 |    16 KB |    64 KB |       281 | utf8mb4_unicode_ci | —                                                                            |
| `second_product_groups`           | BASE TABLE | InnoDB |      117 |    16 KB |    32 KB |       206 | utf8mb4_unicode_ci | —                                                                            |
| `services`                        | BASE TABLE | InnoDB |       42 |    48 KB |   128 KB |       362 | utf8mb4_unicode_ci | 服务实例表，记录用户已购买产品的生命周期、计费、上游和续费状态               |
| `service_connection_snapshots`    | BASE TABLE | InnoDB |        1 |    16 KB |    80 KB |       198 | utf8mb4_unicode_ci | —                                                                            |
| `service_provision_attempts`      | BASE TABLE | InnoDB |        1 |    16 KB |    80 KB |       431 | utf8mb4_unicode_ci | —                                                                            |
| `service_runtime_snapshots`       | BASE TABLE | InnoDB |        0 |    16 KB |    80 KB |       198 | utf8mb4_unicode_ci | —                                                                            |
| `service_upstream_bindings`       | BASE TABLE | InnoDB |        1 |    16 KB |   112 KB |       288 | utf8mb4_unicode_ci | —                                                                            |
| `sessions`                        | BASE TABLE | InnoDB |        0 |    16 KB |    32 KB |         — | utf8mb4_unicode_ci | —                                                                            |
| `settings`                        | BASE TABLE | InnoDB |       13 |    16 KB |    16 KB |       534 | utf8mb4_unicode_ci | —                                                                            |
| `suppliers`                       | BASE TABLE | InnoDB |       15 |    16 KB |    32 KB |       132 | utf8mb4_unicode_ci | —                                                                            |
| `supplier_balances`               | BASE TABLE | MyISAM |        5 |    456 B |     4 KB |        13 | utf8mb4_unicode_ci | —                                                                            |
| `supplier_balance_logs`           | BASE TABLE | MyISAM |        0 |      0 B |     1 KB |         1 | utf8mb4_unicode_ci | —                                                                            |
| `supplier_plugin_bindings`        | BASE TABLE | InnoDB |       13 |    16 KB |    80 KB |       133 | utf8mb4_unicode_ci | —                                                                            |
| `third_product_groups`            | BASE TABLE | InnoDB |      233 |    64 KB |    32 KB |       302 | utf8mb4_unicode_ci | —                                                                            |
| `tickets`                         | BASE TABLE | InnoDB |       30 |    16 KB |    80 KB |       119 | utf8mb4_unicode_ci | —                                                                            |
| `ticket_delivery_rules`           | BASE TABLE | InnoDB |        0 |    16 KB |    48 KB |         3 | utf8mb4_unicode_ci | —                                                                            |
| `ticket_delivery_rule_products`   | BASE TABLE | InnoDB |        0 |    16 KB |    32 KB |         1 | utf8mb4_unicode_ci | —                                                                            |
| `ticket_replies`                  | BASE TABLE | InnoDB |       62 |    16 KB |    32 KB |       251 | utf8mb4_unicode_ci | —                                                                            |
| `ticket_reply_deliveries`         | BASE TABLE | InnoDB |        3 |    16 KB |    48 KB |         4 | utf8mb4_unicode_ci | —                                                                            |
| `ticket_upstream_bindings`        | BASE TABLE | InnoDB |        3 |    16 KB |    48 KB |         6 | utf8mb4_unicode_ci | —                                                                            |
| `ticket_upstream_delivery_logs`   | BASE TABLE | InnoDB |        7 |    16 KB |    80 KB |        12 | utf8mb4_unicode_ci | —                                                                            |
| `users`                           | BASE TABLE | InnoDB |      121 |    64 KB |   176 KB |   988,233 | utf8mb4_unicode_ci | —                                                                            |
| `user_accounts`                   | BASE TABLE | InnoDB |       11 |    16 KB |      0 B |         — | utf8mb4_unicode_ci | 用户账户余额源表，集中承载现金余额、授信和推荐奖励余额                       |
| `user_coupons`                    | BASE TABLE | InnoDB |       10 |    16 KB |    64 KB |       100 | utf8mb4_unicode_ci | —                                                                            |
| `user_notifications`              | BASE TABLE | InnoDB |       10 |    16 KB |    48 KB |       138 | utf8mb4_unicode_ci | —                                                                            |
| `verification_histories`          | BASE TABLE | InnoDB |       10 |    16 KB |    48 KB |       109 | utf8mb4_unicode_ci | —                                                                            |
| `zjmf_upstream_bindings`          | BASE TABLE | MyISAM |        0 |      0 B |     1 KB |         1 | utf8mb4_unicode_ci | —                                                                            |

### 1.2 字段类型分布

| 类型         | 字段数 |
| ------------ | -----: |
| `bigint`     |    223 |
| `char`       |      1 |
| `date`       |      1 |
| `decimal`    |     51 |
| `int`        |     45 |
| `json`       |     59 |
| `longtext`   |     10 |
| `mediumtext` |      1 |
| `smallint`   |      3 |
| `text`       |     20 |
| `timestamp`  |    205 |
| `tinyint`    |     60 |
| `varchar`    |    368 |

### 1.3 JSON 字段

- `activity_logs.context`
- `api_keys.scopes`
- `api_keys.ip_allowlist`
- `automation_logs.meta`
- `coupons.billing_cycles`
- `coupons.product_ids`
- `coupon_campaigns.weekdays`
- `coupon_campaigns.billing_cycles`
- `coupon_campaigns.product_ids`
- `gateway_logs.request_data`
- `gateway_logs.response_data`
- `integration_plugins.capabilities_json`
- `integration_plugins.config_schema_json`
- `integration_plugin_bindings.config_json`
- `integration_plugin_bindings.has_secret_json`
- `integration_plugin_bindings.runtime_policy_json`
- `integration_plugin_configs.config_json`
- `integration_plugin_configs.has_secret_json`
- `integration_plugin_runtime_logs.request_meta_json`
- `integration_plugin_runtime_logs.response_meta_json`
- `invoices.config_snapshot`
- `invoices.config_pricing_snapshot`
- `invoices.coupon_snapshot`
- `invoice_items.meta_json`
- `message_logs.params_json`
- `notification_templates.variables_json`
- `notification_templates.provider_variables_json`
- `operation_logs.context`
- `orders.config_snapshot`
- `orders.config_pricing_snapshot`
- `orders.coupon_snapshot`
- `orders.service_snapshot`
- `payments.callback_raw`
- `payment_callbacks.payload_json`
- `products.pricing`
- `products.config_options`
- `products.purchase_requires`
- `product_upstream_bindings.upstream_product_snapshot_json`
- `product_upstream_bindings.option_schema_json`
- `product_upstream_bindings.provision_policy_json`
- `roles.permissions`
- `schedule_run_logs.summary`
- `schedule_task_runs.summary`
- `services.locked_pricing`
- `services.provision_data`
- `service_connection_snapshots.connection_json`
- `service_connection_snapshots.has_secret_json`
- `service_provision_attempts.request_meta_json`
- `service_provision_attempts.response_meta_json`
- `service_runtime_snapshots.resource_json`
- `service_runtime_snapshots.metrics_json`
- `service_runtime_snapshots.snapshot_json`
- `service_upstream_bindings.runtime_snapshot_json`
- `service_upstream_bindings.connection_snapshot_json`
- `supplier_plugin_bindings.config_json`
- `supplier_plugin_bindings.has_secret_json`
- `ticket_replies.attachments`
- `user_notifications.data`
- `zjmf_upstream_bindings.payload`

## 2. 表结构明细

### 2.1 `account_transactions`

- 类型：`BASE TABLE`
- 引擎：`InnoDB`
- 估算行数：`9`
- 数据大小：`16 KB`
- 索引大小：`96 KB`
- 自增值：`1759`
- 排序规则：`utf8mb4_unicode_ci`
- 表注释：账户流水表，记录现金账户、授信账户、推荐奖励账户的每一次余额变化

#### 字段

| 序号 | 字段            | 类型                  | 可空 | 默认值 | 键  | 额外           | 字符集  | 排序规则           | 注释                                                                          |
| ---: | --------------- | --------------------- | ---- | ------ | --- | -------------- | ------- | ------------------ | ----------------------------------------------------------------------------- |
|    1 | `id`            | `bigint(20) unsigned` | 否   | —      | PRI | auto_increment | —       | —                  | 账户流水自增主键                                                              |
|    2 | `user_id`       | `bigint(20) unsigned` | 否   | —      | MUL | —              | —       | —                  | 所属用户ID                                                                    |
|    3 | `account_type`  | `varchar(30)`         | 否   | —      | —   | —              | utf8mb4 | utf8mb4_unicode_ci | 账户类型：cash/credit/referral 等                                             |
|    4 | `event_type`    | `varchar(30)`         | 否   | —      | —   | —              | utf8mb4 | utf8mb4_unicode_ci | 流水事件类型：recharge/consume/refund/adjust/reward_frozen/reward_released 等 |
|    5 | `change_amount` | `decimal(12,2)`       | 否   | `0.00` | —   | —              | —       | —                  | 本次变动金额，收入为正、支出为负                                              |
|    6 | `currency`      | `varchar(3)`          | 否   | `CNY`  | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —                                                                             |
|    7 | `balance_after` | `decimal(12,2)`       | 否   | `0.00` | —   | —              | —       | —                  | 本次变动后的账户余额                                                          |
|    8 | `source_type`   | `varchar(30)`         | 是   | `NULL` | MUL | —              | utf8mb4 | utf8mb4_unicode_ci | 业务来源类型，如 invoice/payment/referral_withdrawal                          |
|    9 | `source_id`     | `bigint(20) unsigned` | 是   | `NULL` | —   | —              | —       | —                  | 业务来源ID                                                                    |
|   10 | `origin_type`   | `varchar(30)`         | 是   | `NULL` | MUL | —              | utf8mb4 | utf8mb4_unicode_ci | 原始触发对象类型，用于跨域追踪                                                |
|   11 | `origin_id`     | `bigint(20) unsigned` | 是   | `NULL` | —   | —              | —       | —                  | 原始触发对象ID                                                                |
|   12 | `remark`        | `varchar(255)`        | 是   | `NULL` | —   | —              | utf8mb4 | utf8mb4_unicode_ci | 流水备注                                                                      |
|   13 | `operator`      | `varchar(50)`         | 是   | `NULL` | —   | —              | utf8mb4 | utf8mb4_unicode_ci | 操作人快照                                                                    |
|   14 | `trace_id`      | `varchar(64)`         | 是   | `NULL` | MUL | —              | utf8mb4 | utf8mb4_unicode_ci | 链路追踪号                                                                    |
|   15 | `created_at`    | `timestamp`           | 是   | `NULL` | MUL | —              | —       | —                  | 创建时间                                                                      |
|   16 | `updated_at`    | `timestamp`           | 是   | `NULL` | —   | —              | —       | —                  | 更新时间                                                                      |

#### 索引

| 索引名                                          | 唯一 | 类型    | 字段                                          | 基数 | 注释 |
| ----------------------------------------------- | ---- | ------- | --------------------------------------------- | ---: | ---- |
| `account_transactions_created_at_idx`           | 否   | `BTREE` | `created_at`                                  |    6 | —    |
| `account_transactions_origin_idx`               | 否   | `BTREE` | `origin_type`, `origin_id`                    |    9 | —    |
| `account_transactions_source_idx`               | 否   | `BTREE` | `source_type`, `source_id`                    |    9 | —    |
| `account_transactions_trace_id_idx`             | 否   | `BTREE` | `trace_id`                                    |    8 | —    |
| `account_transactions_user_account_created_idx` | 否   | `BTREE` | `user_id`, `account_type`, `created_at`, `id` |    9 | —    |
| `account_transactions_user_event_created_idx`   | 否   | `BTREE` | `user_id`, `event_type`, `created_at`         |    9 | —    |
| `PRIMARY`                                       | 是   | `BTREE` | `id`                                          |    9 | —    |

#### 外键约束

| 约束名                                   | 字段      | 引用表  | 引用字段 | 更新规则   | 删除规则   |
| ---------------------------------------- | --------- | ------- | -------- | ---------- | ---------- |
| `fk_stage2_account_transactions_user_id` | `user_id` | `users` | `id`     | `RESTRICT` | `RESTRICT` |

### 2.2 `activity_logs`

- 类型：`BASE TABLE`
- 引擎：`InnoDB`
- 估算行数：`375`
- 数据大小：`208 KB`
- 索引大小：`96 KB`
- 自增值：`1036400`
- 排序规则：`utf8mb4_unicode_ci`
- 表注释：—

#### 字段

| 序号 | 字段           | 类型                  | 可空 | 默认值   | 键  | 额外           | 字符集  | 排序规则           | 注释                                                                 |
| ---: | -------------- | --------------------- | ---- | -------- | --- | -------------- | ------- | ------------------ | -------------------------------------------------------------------- |
|    1 | `id`           | `bigint(20) unsigned` | 否   | —        | PRI | auto_increment | —       | —                  | —                                                                    |
|    2 | `actor_type`   | `varchar(20)`         | 否   | `system` | —   | —              | utf8mb4 | utf8mb4_unicode_ci | 操作者类型: admin, client, system, sub_account                       |
|    3 | `actor_id`     | `bigint(20) unsigned` | 是   | `NULL`   | MUL | —              | —       | —                  | 操作者ID                                                             |
|    4 | `actor_name`   | `varchar(100)`        | 否   | 空字符串 | —   | —              | utf8mb4 | utf8mb4_unicode_ci | 操作者名称快照                                                       |
|    5 | `module`       | `varchar(50)`         | 否   | —        | MUL | —              | utf8mb4 | utf8mb4_unicode_ci | 模块: invoice, order, service, user, product, ticket, coupon, system |
|    6 | `action`       | `varchar(100)`        | 否   | —        | —   | —              | utf8mb4 | utf8mb4_unicode_ci | 动作描述: create, pay, refund, suspend, terminate 等                 |
|    7 | `description`  | `text`                | 否   | —        | —   | —              | utf8mb4 | utf8mb4_unicode_ci | 可读描述                                                             |
|    8 | `subject_type` | `varchar(50)`         | 是   | `NULL`   | MUL | —              | utf8mb4 | utf8mb4_unicode_ci | 关联对象类型: invoice, service, order, user, ticket                  |
|    9 | `subject_id`   | `bigint(20) unsigned` | 是   | `NULL`   | —   | —              | —       | —                  | 关联对象ID                                                           |
|   10 | `context`      | `json`                | 是   | `NULL`   | —   | —              | —       | —                  | 附加结构化上下文                                                     |
|   11 | `ip_address`   | `varchar(45)`         | 是   | `NULL`   | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —                                                                    |
|   12 | `created_at`   | `timestamp`           | 是   | `NULL`   | MUL | —              | —       | —                  | —                                                                    |
|   13 | `updated_at`   | `timestamp`           | 是   | `NULL`   | —   | —              | —       | —                  | —                                                                    |

#### 索引

| 索引名                                        | 唯一 | 类型    | 字段                         | 基数 | 注释 |
| --------------------------------------------- | ---- | ------- | ---------------------------- | ---: | ---- |
| `activity_logs_actor_id_index`                | 否   | `BTREE` | `actor_id`                   |  195 | —    |
| `activity_logs_created_at_index`              | 否   | `BTREE` | `created_at`                 |   95 | —    |
| `activity_logs_module_action_index`           | 否   | `BTREE` | `module`, `action`           |  193 | —    |
| `activity_logs_subject_type_subject_id_index` | 否   | `BTREE` | `subject_type`, `subject_id` |   59 | —    |
| `PRIMARY`                                     | 是   | `BTREE` | `id`                         |  375 | —    |

#### 外键约束

无数据库级外键约束。

### 2.3 `admin_users`

- 类型：`BASE TABLE`
- 引擎：`InnoDB`
- 估算行数：`210`
- 数据大小：`80 KB`
- 索引大小：`48 KB`
- 自增值：`588`
- 排序规则：`utf8mb4_unicode_ci`
- 表注释：—

#### 字段

| 序号 | 字段            | 类型                  | 可空 | 默认值 | 键  | 额外           | 字符集  | 排序规则           | 注释          |
| ---: | --------------- | --------------------- | ---- | ------ | --- | -------------- | ------- | ------------------ | ------------- |
|    1 | `id`            | `bigint(20) unsigned` | 否   | —      | PRI | auto_increment | —       | —                  | —             |
|    2 | `username`      | `varchar(50)`         | 否   | —      | UNI | —              | utf8mb4 | utf8mb4_unicode_ci | —             |
|    3 | `password`      | `varchar(255)`        | 否   | —      | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —             |
|    4 | `role_id`       | `bigint(20) unsigned` | 否   | —      | MUL | —              | —       | —                  | —             |
|    5 | `nickname`      | `varchar(50)`         | 是   | `NULL` | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —             |
|    6 | `status`        | `tinyint(4)`          | 否   | `1`    | —   | —              | —       | —                  | 0=禁用 1=正常 |
|    7 | `last_login_at` | `timestamp`           | 是   | `NULL` | —   | —              | —       | —                  | —             |
|    8 | `last_login_ip` | `varchar(45)`         | 是   | `NULL` | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —             |
|    9 | `created_at`    | `timestamp`           | 是   | `NULL` | —   | —              | —       | —                  | —             |
|   10 | `updated_at`    | `timestamp`           | 是   | `NULL` | —   | —              | —       | —                  | —             |
|   11 | `email`         | `varchar(100)`        | 是   | `NULL` | MUL | —              | utf8mb4 | utf8mb4_unicode_ci | —             |

#### 索引

| 索引名                        | 唯一 | 类型    | 字段       | 基数 | 注释 |
| ----------------------------- | ---- | ------- | ---------- | ---: | ---- |
| `admin_users_email_index`     | 否   | `BTREE` | `email`    |  210 | —    |
| `admin_users_role_id_index`   | 否   | `BTREE` | `role_id`  |  210 | —    |
| `admin_users_username_unique` | 是   | `BTREE` | `username` |  210 | —    |
| `PRIMARY`                     | 是   | `BTREE` | `id`       |  210 | —    |

#### 外键约束

| 约束名                          | 字段      | 引用表  | 引用字段 | 更新规则   | 删除规则   |
| ------------------------------- | --------- | ------- | -------- | ---------- | ---------- |
| `fk_stage2_admin_users_role_id` | `role_id` | `roles` | `id`     | `RESTRICT` | `RESTRICT` |

### 2.4 `admin_user_roles`

- 类型：`BASE TABLE`
- 引擎：`InnoDB`
- 估算行数：`1`
- 数据大小：`16 KB`
- 索引大小：`32 KB`
- 自增值：`210`
- 排序规则：`utf8mb4_unicode_ci`
- 表注释：—

#### 字段

| 序号 | 字段            | 类型                  | 可空 | 默认值 | 键  | 额外           | 字符集 | 排序规则 | 注释 |
| ---: | --------------- | --------------------- | ---- | ------ | --- | -------------- | ------ | -------- | ---- |
|    1 | `id`            | `bigint(20) unsigned` | 否   | —      | PRI | auto_increment | —      | —        | —    |
|    2 | `admin_user_id` | `bigint(20) unsigned` | 否   | —      | MUL | —              | —      | —        | —    |
|    3 | `role_id`       | `bigint(20) unsigned` | 否   | —      | MUL | —              | —      | —        | —    |

#### 索引

| 索引名                               | 唯一 | 类型    | 字段                       | 基数 | 注释 |
| ------------------------------------ | ---- | ------- | -------------------------- | ---: | ---- |
| `admin_user_roles_admin_role_unique` | 是   | `BTREE` | `admin_user_id`, `role_id` |    1 | —    |
| `admin_user_roles_role_id_idx`       | 否   | `BTREE` | `role_id`                  |    1 | —    |
| `PRIMARY`                            | 是   | `BTREE` | `id`                       |    1 | —    |

#### 外键约束

| 约束名                                     | 字段            | 引用表        | 引用字段 | 更新规则   | 删除规则   |
| ------------------------------------------ | --------------- | ------------- | -------- | ---------- | ---------- |
| `fk_stage2_admin_user_roles_admin_user_id` | `admin_user_id` | `admin_users` | `id`     | `RESTRICT` | `CASCADE`  |
| `fk_stage2_admin_user_roles_role_id`       | `role_id`       | `roles`       | `id`     | `RESTRICT` | `RESTRICT` |

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

| 序号 | 字段            | 类型                  | 可空 | 默认值    | 键  | 额外           | 字符集  | 排序规则           | 注释                            |
| ---: | --------------- | --------------------- | ---- | --------- | --- | -------------- | ------- | ------------------ | ------------------------------- |
|    1 | `id`            | `bigint(20) unsigned` | 否   | —         | PRI | auto_increment | —       | —                  | —                               |
|    2 | `user_id`       | `bigint(20) unsigned` | 否   | —         | MUL | —              | —       | —                  | —                               |
|    3 | `contact_name`  | `varchar(50)`         | 否   | 空字符串  | —   | —              | utf8mb4 | utf8mb4_unicode_ci | 联系人                          |
|    4 | `contact_phone` | `varchar(30)`         | 否   | 空字符串  | —   | —              | utf8mb4 | utf8mb4_unicode_ci | 联系手机                        |
|    5 | `contact_qq`    | `varchar(30)`         | 否   | 空字符串  | —   | —              | utf8mb4 | utf8mb4_unicode_ci | QQ号                            |
|    6 | `company_name`  | `varchar(120)`        | 否   | 空字符串  | —   | —              | utf8mb4 | utf8mb4_unicode_ci | 公司名称                        |
|    7 | `reason`        | `varchar(500)`        | 否   | 空字符串  | —   | —              | utf8mb4 | utf8mb4_unicode_ci | 申请说明                        |
|    8 | `status`        | `varchar(20)`         | 否   | `pending` | MUL | —              | utf8mb4 | utf8mb4_unicode_ci | 状态: pending/approved/rejected |
|    9 | `api_key`       | `varchar(64)`         | 是   | `NULL`    | —   | —              | utf8mb4 | utf8mb4_unicode_ci | API密钥                         |
|   10 | `admin_note`    | `varchar(500)`        | 否   | 空字符串  | —   | —              | utf8mb4 | utf8mb4_unicode_ci | 管理员备注                      |
|   11 | `created_at`    | `timestamp`           | 是   | `NULL`    | —   | —              | —       | —                  | —                               |
|   12 | `updated_at`    | `timestamp`           | 是   | `NULL`    | —   | —              | —       | —                  | —                               |

#### 索引

| 索引名                               | 唯一 | 类型    | 字段      | 基数 | 注释 |
| ------------------------------------ | ---- | ------- | --------- | ---: | ---- |
| `agent_applications_status_index`    | 否   | `BTREE` | `status`  |    0 | —    |
| `agent_applications_user_id_foreign` | 否   | `BTREE` | `user_id` |    0 | —    |
| `PRIMARY`                            | 是   | `BTREE` | `id`      |    0 | —    |

#### 外键约束

| 约束名                               | 字段      | 引用表  | 引用字段 | 更新规则   | 删除规则  |
| ------------------------------------ | --------- | ------- | -------- | ---------- | --------- |
| `agent_applications_user_id_foreign` | `user_id` | `users` | `id`     | `RESTRICT` | `CASCADE` |

### 2.6 `agent_groups`

- 类型：`BASE TABLE`
- 引擎：`InnoDB`
- 估算行数：`1`
- 数据大小：`16 KB`
- 索引大小：`16 KB`
- 自增值：`6`
- 排序规则：`utf8mb4_unicode_ci`
- 表注释：—

#### 字段

| 序号 | 字段                    | 类型                  | 可空 | 默认值 | 键  | 额外           | 字符集  | 排序规则           | 注释 |
| ---: | ----------------------- | --------------------- | ---- | ------ | --- | -------------- | ------- | ------------------ | ---- |
|    1 | `id`                    | `bigint(20) unsigned` | 否   | —      | PRI | auto_increment | —       | —                  | —    |
|    2 | `name`                  | `varchar(50)`         | 否   | —      | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|    3 | `code`                  | `varchar(30)`         | 否   | —      | UNI | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|    4 | `status`                | `tinyint(4)`          | 否   | `1`    | —   | —              | —       | —                  | —    |
|    5 | `default_discount_rate` | `decimal(5,2)`        | 是   | `NULL` | —   | —              | —       | —                  | —    |
|    6 | `sort_order`            | `int(11)`             | 否   | `0`    | —   | —              | —       | —                  | —    |
|    7 | `remark`                | `varchar(255)`        | 是   | `NULL` | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|    8 | `created_at`            | `timestamp`           | 是   | `NULL` | —   | —              | —       | —                  | —    |
|    9 | `updated_at`            | `timestamp`           | 是   | `NULL` | —   | —              | —       | —                  | —    |

#### 索引

| 索引名                     | 唯一 | 类型    | 字段   | 基数 | 注释 |
| -------------------------- | ---- | ------- | ------ | ---: | ---- |
| `agent_groups_code_unique` | 是   | `BTREE` | `code` |    1 | —    |
| `PRIMARY`                  | 是   | `BTREE` | `id`   |    1 | —    |

#### 外键约束

无数据库级外键约束。

### 2.7 `agent_group_discounts`

- 类型：`BASE TABLE`
- 引擎：`InnoDB`
- 估算行数：`1`
- 数据大小：`16 KB`
- 索引大小：`32 KB`
- 自增值：`5`
- 排序规则：`utf8mb4_unicode_ci`
- 表注释：—

#### 字段

| 序号 | 字段                        | 类型                  | 可空 | 默认值 | 键  | 额外           | 字符集 | 排序规则 | 注释 |
| ---: | --------------------------- | --------------------- | ---- | ------ | --- | -------------- | ------ | -------- | ---- |
|    1 | `id`                        | `bigint(20) unsigned` | 否   | —      | PRI | auto_increment | —      | —        | —    |
|    2 | `agent_group_id`            | `bigint(20) unsigned` | 否   | —      | MUL | —              | —      | —        | —    |
|    3 | `product_discount_group_id` | `bigint(20) unsigned` | 否   | —      | MUL | —              | —      | —        | —    |
|    4 | `discount_rate`             | `decimal(5,2)`        | 否   | —      | —   | —              | —      | —        | —    |
|    5 | `created_at`                | `timestamp`           | 是   | `NULL` | —   | —              | —      | —        | —    |
|    6 | `updated_at`                | `timestamp`           | 是   | `NULL` | —   | —              | —      | —        | —    |

#### 索引

| 索引名                                                    | 唯一 | 类型    | 字段                                          | 基数 | 注释 |
| --------------------------------------------------------- | ---- | ------- | --------------------------------------------- | ---: | ---- |
| `agent_group_discounts_product_discount_group_id_foreign` | 否   | `BTREE` | `product_discount_group_id`                   |    1 | —    |
| `agent_group_discount_unique`                             | 是   | `BTREE` | `agent_group_id`, `product_discount_group_id` |    1 | —    |
| `PRIMARY`                                                 | 是   | `BTREE` | `id`                                          |    1 | —    |

#### 外键约束

| 约束名                                                    | 字段                        | 引用表                    | 引用字段 | 更新规则   | 删除规则  |
| --------------------------------------------------------- | --------------------------- | ------------------------- | -------- | ---------- | --------- |
| `agent_group_discounts_agent_group_id_foreign`            | `agent_group_id`            | `agent_groups`            | `id`     | `RESTRICT` | `CASCADE` |
| `agent_group_discounts_product_discount_group_id_foreign` | `product_discount_group_id` | `product_discount_groups` | `id`     | `RESTRICT` | `CASCADE` |

### 2.8 `api_keys`

- 类型：`BASE TABLE`
- 引擎：`InnoDB`
- 估算行数：`0`
- 数据大小：`16 KB`
- 索引大小：`48 KB`
- 自增值：`1`
- 排序规则：`utf8mb4_unicode_ci`
- 表注释：—

#### 字段

| 序号 | 字段           | 类型                  | 可空 | 默认值    | 键  | 额外           | 字符集  | 排序规则           | 注释 |
| ---: | -------------- | --------------------- | ---- | --------- | --- | -------------- | ------- | ------------------ | ---- |
|    1 | `id`           | `bigint(20) unsigned` | 否   | —         | PRI | auto_increment | —       | —                  | —    |
|    2 | `user_id`      | `bigint(20) unsigned` | 否   | —         | MUL | —              | —       | —                  | —    |
|    3 | `name`         | `varchar(64)`         | 否   | 空字符串  | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|    4 | `key_prefix`   | `varchar(32)`         | 否   | —         | UNI | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|    5 | `secret_hash`  | `varchar(64)`         | 否   | —         | MUL | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|    6 | `secret_last4` | `varchar(4)`          | 否   | —         | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|    7 | `scopes`       | `json`                | 是   | `NULL`    | —   | —              | —       | —                  | —    |
|    8 | `expires_at`   | `timestamp`           | 是   | `NULL`    | —   | —              | —       | —                  | —    |
|    9 | `ip_allowlist` | `json`                | 是   | `NULL`    | —   | —              | —       | —                  | —    |
|   10 | `status`       | `varchar(16)`         | 否   | `enabled` | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|   11 | `last_used_at` | `timestamp`           | 是   | `NULL`    | —   | —              | —       | —                  | —    |
|   12 | `created_at`   | `timestamp`           | 是   | `NULL`    | —   | —              | —       | —                  | —    |
|   13 | `updated_at`   | `timestamp`           | 是   | `NULL`    | —   | —              | —       | —                  | —    |
|   14 | `deleted_at`   | `timestamp`           | 是   | `NULL`    | —   | —              | —       | —                  | —    |

#### 索引

| 索引名                       | 唯一 | 类型    | 字段          | 基数 | 注释 |
| ---------------------------- | ---- | ------- | ------------- | ---: | ---- |
| `api_keys_key_prefix_unique` | 是   | `BTREE` | `key_prefix`  |    0 | —    |
| `api_keys_secret_hash_index` | 否   | `BTREE` | `secret_hash` |    0 | —    |
| `api_keys_user_id_index`     | 否   | `BTREE` | `user_id`     |    0 | —    |
| `PRIMARY`                    | 是   | `BTREE` | `id`          |    0 | —    |

#### 外键约束

无数据库级外键约束。

### 2.9 `api_key_usage_logs`

- 类型：`BASE TABLE`
- 引擎：`InnoDB`
- 估算行数：`0`
- 数据大小：`16 KB`
- 索引大小：`32 KB`
- 自增值：`1`
- 排序规则：`utf8mb4_unicode_ci`
- 表注释：—

#### 字段

| 序号 | 字段          | 类型                   | 可空 | 默认值              | 键  | 额外                        | 字符集  | 排序规则           | 注释 |
| ---: | ------------- | ---------------------- | ---- | ------------------- | --- | --------------------------- | ------- | ------------------ | ---- |
|    1 | `id`          | `bigint(20) unsigned`  | 否   | —                   | PRI | auto_increment              | —       | —                  | —    |
|    2 | `api_key_id`  | `bigint(20) unsigned`  | 否   | —                   | MUL | —                           | —       | —                  | —    |
|    3 | `user_id`     | `bigint(20) unsigned`  | 否   | —                   | —   | —                           | —       | —                  | —    |
|    4 | `method`      | `varchar(8)`           | 否   | 空字符串            | —   | —                           | utf8mb4 | utf8mb4_unicode_ci | —    |
|    5 | `path`        | `varchar(255)`         | 否   | 空字符串            | —   | —                           | utf8mb4 | utf8mb4_unicode_ci | —    |
|    6 | `status_code` | `smallint(5) unsigned` | 否   | `0`                 | —   | —                           | —       | —                  | —    |
|    7 | `ip`          | `varchar(45)`          | 否   | 空字符串            | —   | —                           | utf8mb4 | utf8mb4_unicode_ci | —    |
|    8 | `duration_ms` | `int(10) unsigned`     | 否   | `0`                 | —   | —                           | —       | —                  | —    |
|    9 | `created_at`  | `timestamp`            | 否   | `CURRENT_TIMESTAMP` | MUL | on update CURRENT_TIMESTAMP | —       | —                  | —    |

#### 索引

| 索引名                                | 唯一 | 类型    | 字段         | 基数 | 注释 |
| ------------------------------------- | ---- | ------- | ------------ | ---: | ---- |
| `api_key_usage_logs_api_key_id_index` | 否   | `BTREE` | `api_key_id` |    0 | —    |
| `api_key_usage_logs_created_at_index` | 否   | `BTREE` | `created_at` |    0 | —    |
| `PRIMARY`                             | 是   | `BTREE` | `id`         |    0 | —    |

#### 外键约束

无数据库级外键约束。

### 2.10 `archive_audit_logs`

- 类型：`BASE TABLE`
- 引擎：`InnoDB`
- 估算行数：`0`
- 数据大小：`16 KB`
- 索引大小：`32 KB`
- 自增值：`2`
- 排序规则：`utf8mb4_unicode_ci`
- 表注释：—

#### 字段

| 序号 | 字段              | 类型                  | 可空 | 默认值 | 键  | 额外           | 字符集  | 排序规则           | 注释 |
| ---: | ----------------- | --------------------- | ---- | ------ | --- | -------------- | ------- | ------------------ | ---- |
|    1 | `id`              | `bigint(20) unsigned` | 否   | —      | PRI | auto_increment | —       | —                  | —    |
|    2 | `batch_id`        | `varchar(64)`         | 否   | —      | MUL | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|    3 | `table_name`      | `varchar(64)`         | 否   | —      | MUL | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|    4 | `mode`            | `varchar(30)`         | 否   | —      | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|    5 | `row_count`       | `int(10) unsigned`    | 否   | `0`    | —   | —              | —       | —                  | —    |
|    6 | `file_path`       | `varchar(500)`        | 是   | `NULL` | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|    7 | `file_size`       | `bigint(20) unsigned` | 是   | `NULL` | —   | —              | —       | —                  | —    |
|    8 | `checksum_sha256` | `char(64)`            | 是   | `NULL` | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|    9 | `status`          | `varchar(30)`         | 否   | —      | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|   10 | `error_message`   | `varchar(500)`        | 是   | `NULL` | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|   11 | `started_at`      | `timestamp`           | 是   | `NULL` | —   | —              | —       | —                  | —    |
|   12 | `finished_at`     | `timestamp`           | 是   | `NULL` | —   | —              | —       | —                  | —    |
|   13 | `created_at`      | `timestamp`           | 是   | `NULL` | —   | —              | —       | —                  | —    |

#### 索引

| 索引名                     | 唯一 | 类型    | 字段                                 | 基数 | 注释 |
| -------------------------- | ---- | ------- | ------------------------------------ | ---: | ---- |
| `archive_batch_idx`        | 否   | `BTREE` | `batch_id`                           |    0 | —    |
| `archive_table_status_idx` | 否   | `BTREE` | `table_name`, `status`, `created_at` |    0 | —    |
| `PRIMARY`                  | 是   | `BTREE` | `id`                                 |    0 | —    |

#### 外键约束

无数据库级外键约束。

### 2.11 `automation_logs`

- 类型：`BASE TABLE`
- 引擎：`InnoDB`
- 估算行数：`0`
- 数据大小：`16 KB`
- 索引大小：`48 KB`
- 自增值：`1290`
- 排序规则：`utf8mb4_unicode_ci`
- 表注释：—

#### 字段

| 序号 | 字段          | 类型                  | 可空 | 默认值   | 键  | 额外           | 字符集  | 排序规则           | 注释 |
| ---: | ------------- | --------------------- | ---- | -------- | --- | -------------- | ------- | ------------------ | ---- |
|    1 | `id`          | `bigint(20) unsigned` | 否   | —        | PRI | auto_increment | —       | —                  | —    |
|    2 | `task_key`    | `varchar(80)`         | 否   | —        | MUL | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|    3 | `action`      | `varchar(80)`         | 否   | —        | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|    4 | `object_type` | `varchar(40)`         | 否   | —        | MUL | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|    5 | `object_id`   | `bigint(20) unsigned` | 否   | —        | —   | —              | —       | —                  | —    |
|    6 | `rule_key`    | `varchar(191)`        | 否   | 空字符串 | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|    7 | `meta`        | `json`                | 是   | `NULL`   | —   | —              | —       | —                  | —    |
|    8 | `executed_at` | `timestamp`           | 是   | `NULL`   | —   | —              | —       | —                  | —    |
|    9 | `created_at`  | `timestamp`           | 是   | `NULL`   | —   | —              | —       | —                  | —    |
|   10 | `updated_at`  | `timestamp`           | 是   | `NULL`   | —   | —              | —       | —                  | —    |

#### 索引

| 索引名                           | 唯一 | 类型    | 字段                                                         | 基数 | 注释 |
| -------------------------------- | ---- | ------- | ------------------------------------------------------------ | ---: | ---- |
| `automation_logs_object_idx`     | 否   | `BTREE` | `object_type`, `object_id`                                   |    0 | —    |
| `automation_logs_task_key_index` | 否   | `BTREE` | `task_key`                                                   |    0 | —    |
| `automation_logs_unique_rule`    | 是   | `BTREE` | `task_key`, `action`, `object_type`, `object_id`, `rule_key` |    0 | —    |
| `PRIMARY`                        | 是   | `BTREE` | `id`                                                         |    0 | —    |

#### 外键约束

无数据库级外键约束。

### 2.12 `content_articles`

- 类型：`BASE TABLE`
- 引擎：`InnoDB`
- 估算行数：`8`
- 数据大小：`16 KB`
- 索引大小：`96 KB`
- 自增值：`45`
- 排序规则：`utf8mb4_unicode_ci`
- 表注释：—

#### 字段

| 序号 | 字段                | 类型                  | 可空 | 默认值 | 键  | 额外           | 字符集  | 排序规则           | 注释                     |
| ---: | ------------------- | --------------------- | ---- | ------ | --- | -------------- | ------- | ------------------ | ------------------------ |
|    1 | `id`                | `bigint(20) unsigned` | 否   | —      | PRI | auto_increment | —       | —                  | —                        |
|    2 | `content_type`      | `varchar(20)`         | 否   | —      | MUL | —              | utf8mb4 | utf8mb4_unicode_ci | notice&#124;help         |
|    3 | `category_id`       | `bigint(20) unsigned` | 是   | `NULL` | MUL | —              | —       | —                  | —                        |
|    4 | `title`             | `varchar(200)`        | 否   | —      | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —                        |
|    5 | `slug`              | `varchar(220)`        | 否   | —      | UNI | —              | utf8mb4 | utf8mb4_unicode_ci | —                        |
|    6 | `summary`           | `varchar(500)`        | 是   | `NULL` | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —                        |
|    7 | `content`           | `longtext`            | 否   | —      | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —                        |
|    8 | `category_name`     | `varchar(60)`         | 是   | `NULL` | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —                        |
|    9 | `keywords`          | `varchar(255)`        | 是   | `NULL` | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —                        |
|   10 | `cover_image`       | `varchar(500)`        | 是   | `NULL` | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —                        |
|   11 | `status`            | `tinyint(4)`          | 否   | `0`    | —   | —              | —       | —                  | 0=草稿 1=已发布 2=已下线 |
|   12 | `is_pinned`         | `tinyint(4)`          | 否   | `0`    | —   | —              | —       | —                  | —                        |
|   13 | `is_recommended`    | `tinyint(4)`          | 否   | `0`    | —   | —              | —       | —                  | —                        |
|   14 | `sort_order`        | `int(11)`             | 否   | `0`    | —   | —              | —       | —                  | —                        |
|   15 | `view_count`        | `int(10) unsigned`    | 否   | `0`    | —   | —              | —       | —                  | —                        |
|   16 | `require_reread_at` | `timestamp`           | 是   | `NULL` | —   | —              | —       | —                  | —                        |
|   17 | `publish_at`        | `timestamp`           | 是   | `NULL` | —   | —              | —       | —                  | —                        |
|   18 | `last_published_at` | `timestamp`           | 是   | `NULL` | —   | —              | —       | —                  | —                        |
|   19 | `created_by`        | `bigint(20) unsigned` | 是   | `NULL` | —   | —              | —       | —                  | —                        |
|   20 | `updated_by`        | `bigint(20) unsigned` | 是   | `NULL` | —   | —              | —       | —                  | —                        |
|   21 | `operator`          | `varchar(50)`         | 是   | `NULL` | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —                        |
|   22 | `remark`            | `varchar(255)`        | 是   | `NULL` | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —                        |
|   23 | `trace_id`          | `varchar(64)`         | 是   | `NULL` | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —                        |
|   24 | `created_at`        | `timestamp`           | 是   | `NULL` | —   | —              | —       | —                  | —                        |
|   25 | `updated_at`        | `timestamp`           | 是   | `NULL` | —   | —              | —       | —                  | —                        |
|   26 | `deleted_at`        | `timestamp`           | 是   | `NULL` | —   | —              | —       | —                  | —                        |

#### 索引

| 索引名                              | 唯一 | 类型    | 字段                                            | 基数 | 注释 |
| ----------------------------------- | ---- | ------- | ----------------------------------------------- | ---: | ---- |
| `content_articles_slug_unique`      | 是   | `BTREE` | `slug`                                          |    8 | —    |
| `idx_article_category_published`    | 否   | `BTREE` | `category_id`, `status`, `publish_at`           |    7 | —    |
| `idx_content_article_type_category` | 否   | `BTREE` | `content_type`, `category_id`                   |    8 | —    |
| `idx_content_type_pin_sort`         | 否   | `BTREE` | `content_type`, `is_pinned`, `sort_order`, `id` |    8 | —    |
| `idx_content_type_recommend`        | 否   | `BTREE` | `content_type`, `is_recommended`, `publish_at`  |    6 | —    |
| `idx_content_type_status_publish`   | 否   | `BTREE` | `content_type`, `status`, `publish_at`          |    6 | —    |
| `PRIMARY`                           | 是   | `BTREE` | `id`                                            |    8 | —    |

#### 外键约束

| 约束名                                   | 字段          | 引用表               | 引用字段 | 更新规则   | 删除规则   |
| ---------------------------------------- | ------------- | -------------------- | -------- | ---------- | ---------- |
| `fk_stage2_content_articles_category_id` | `category_id` | `content_categories` | `id`     | `RESTRICT` | `SET NULL` |

### 2.13 `content_categories`

- 类型：`BASE TABLE`
- 引擎：`InnoDB`
- 估算行数：`6`
- 数据大小：`16 KB`
- 索引大小：`48 KB`
- 自增值：`19`
- 排序规则：`utf8mb4_unicode_ci`
- 表注释：—

#### 字段

| 序号 | 字段           | 类型                  | 可空 | 默认值 | 键  | 额外           | 字符集  | 排序规则           | 注释             |
| ---: | -------------- | --------------------- | ---- | ------ | --- | -------------- | ------- | ------------------ | ---------------- |
|    1 | `id`           | `bigint(20) unsigned` | 否   | —      | PRI | auto_increment | —       | —                  | —                |
|    2 | `content_type` | `varchar(20)`         | 否   | —      | MUL | —              | utf8mb4 | utf8mb4_unicode_ci | notice&#124;help |
|    3 | `name`         | `varchar(80)`         | 否   | —      | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —                |
|    4 | `slug`         | `varchar(120)`        | 否   | —      | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —                |
|    5 | `description`  | `varchar(255)`        | 是   | `NULL` | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —                |
|    6 | `status`       | `tinyint(4)`          | 否   | `1`    | —   | —              | —       | —                  | 0=禁用 1=启用    |
|    7 | `sort_order`   | `int(11)`             | 否   | `0`    | —   | —              | —       | —                  | —                |
|    8 | `created_by`   | `bigint(20) unsigned` | 是   | `NULL` | —   | —              | —       | —                  | —                |
|    9 | `updated_by`   | `bigint(20) unsigned` | 是   | `NULL` | —   | —              | —       | —                  | —                |
|   10 | `created_at`   | `timestamp`           | 是   | `NULL` | —   | —              | —       | —                  | —                |
|   11 | `updated_at`   | `timestamp`           | 是   | `NULL` | —   | —              | —       | —                  | —                |

#### 索引

| 索引名                                  | 唯一 | 类型    | 字段                                   | 基数 | 注释 |
| --------------------------------------- | ---- | ------- | -------------------------------------- | ---: | ---- |
| `idx_content_category_type_status_sort` | 否   | `BTREE` | `content_type`, `status`, `sort_order` |    2 | —    |
| `PRIMARY`                               | 是   | `BTREE` | `id`                                   |    6 | —    |
| `uniq_content_category_type_name`       | 是   | `BTREE` | `content_type`, `name`                 |    6 | —    |
| `uniq_content_category_type_slug`       | 是   | `BTREE` | `content_type`, `slug`                 |    6 | —    |

#### 外键约束

无数据库级外键约束。

### 2.14 `coupons`

- 类型：`BASE TABLE`
- 引擎：`InnoDB`
- 估算行数：`14`
- 数据大小：`16 KB`
- 索引大小：`48 KB`
- 自增值：`106`
- 排序规则：`utf8mb4_unicode_ci`
- 表注释：—

#### 字段

| 序号 | 字段                  | 类型                  | 可空 | 默认值        | 键  | 额外           | 字符集  | 排序规则           | 注释                |
| ---: | --------------------- | --------------------- | ---- | ------------- | --- | -------------- | ------- | ------------------ | ------------------- |
|    1 | `id`                  | `bigint(20) unsigned` | 否   | —             | PRI | auto_increment | —       | —                  | —                   |
|    2 | `coupon_campaign_id`  | `bigint(20) unsigned` | 是   | `NULL`        | MUL | —              | —       | —                  | —                   |
|    3 | `name`                | `varchar(120)`        | 否   | —             | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —                   |
|    4 | `code`                | `varchar(50)`         | 否   | —             | UNI | —              | utf8mb4 | utf8mb4_unicode_ci | —                   |
|    5 | `description`         | `varchar(255)`        | 是   | `NULL`        | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —                   |
|    6 | `distribution_type`   | `varchar(20)`         | 否   | `public`      | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —                   |
|    7 | `discount_scope`      | `varchar(20)`         | 否   | `first_month` | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —                   |
|    8 | `discount_type`       | `varchar(20)`         | 否   | —             | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —                   |
|    9 | `discount_value`      | `decimal(12,2)`       | 否   | `0.00`        | —   | —              | —       | —                  | —                   |
|   10 | `min_amount`          | `decimal(12,2)`       | 否   | `0.00`        | —   | —              | —       | —                  | —                   |
|   11 | `max_discount_amount` | `decimal(12,2)`       | 是   | `NULL`        | —   | —              | —       | —                  | —                   |
|   12 | `billing_cycles`      | `json`                | 是   | `NULL`        | —   | —              | —       | —                  | —                   |
|   13 | `product_ids`         | `json`                | 是   | `NULL`        | —   | —              | —       | —                  | —                   |
|   14 | `first_order_only`    | `tinyint(1)`          | 否   | `0`           | —   | —              | —       | —                  | —                   |
|   15 | `total_usage_limit`   | `int(10) unsigned`    | 是   | `NULL`        | —   | —              | —       | —                  | —                   |
|   16 | `per_user_limit`      | `int(10) unsigned`    | 是   | `NULL`        | —   | —              | —       | —                  | —                   |
|   17 | `used_count`          | `int(10) unsigned`    | 否   | `0`           | —   | —              | —       | —                  | —                   |
|   18 | `status`              | `tinyint(4)`          | 否   | `1`           | MUL | —              | —       | —                  | 状态：0=禁用 1=启用 |
|   19 | `sort_order`          | `int(10) unsigned`    | 否   | `0`           | —   | —              | —       | —                  | —                   |
|   20 | `starts_at`           | `timestamp`           | 是   | `NULL`        | —   | —              | —       | —                  | —                   |
|   21 | `expires_at`          | `timestamp`           | 是   | `NULL`        | —   | —              | —       | —                  | —                   |
|   22 | `remark`              | `varchar(255)`        | 是   | `NULL`        | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —                   |
|   23 | `operator`            | `varchar(100)`        | 是   | `NULL`        | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —                   |
|   24 | `trace_id`            | `varchar(100)`        | 是   | `NULL`        | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —                   |
|   25 | `created_at`          | `timestamp`           | 是   | `NULL`        | —   | —              | —       | —                  | —                   |
|   26 | `updated_at`          | `timestamp`           | 是   | `NULL`        | —   | —              | —       | —                  | —                   |
|   27 | `allow_agent`         | `tinyint(1)`          | 否   | `1`           | —   | —              | —       | —                  | —                   |

#### 索引

| 索引名                        | 唯一 | 类型    | 字段                           | 基数 | 注释 |
| ----------------------------- | ---- | ------- | ------------------------------ | ---: | ---- |
| `coupons_campaign_status_idx` | 否   | `BTREE` | `coupon_campaign_id`, `status` |    4 | —    |
| `coupons_code_unique`         | 是   | `BTREE` | `code`                         |   14 | —    |
| `coupons_status_sort_idx`     | 否   | `BTREE` | `status`, `sort_order`         |    2 | —    |
| `PRIMARY`                     | 是   | `BTREE` | `id`                           |   14 | —    |

#### 外键约束

| 约束名                                 | 字段                 | 引用表             | 引用字段 | 更新规则   | 删除规则   |
| -------------------------------------- | -------------------- | ------------------ | -------- | ---------- | ---------- |
| `fk_stage2_coupons_coupon_campaign_id` | `coupon_campaign_id` | `coupon_campaigns` | `id`     | `RESTRICT` | `SET NULL` |

### 2.15 `coupon_campaigns`

- 类型：`BASE TABLE`
- 引擎：`InnoDB`
- 估算行数：`3`
- 数据大小：`16 KB`
- 索引大小：`48 KB`
- 自增值：`19`
- 排序规则：`utf8mb4_unicode_ci`
- 表注释：—

#### 字段

| 序号 | 字段                   | 类型                  | 可空 | 默认值        | 键  | 额外           | 字符集  | 排序规则           | 注释                |
| ---: | ---------------------- | --------------------- | ---- | ------------- | --- | -------------- | ------- | ------------------ | ------------------- |
|    1 | `id`                   | `bigint(20) unsigned` | 否   | —             | PRI | auto_increment | —       | —                  | —                   |
|    2 | `name`                 | `varchar(120)`        | 否   | —             | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —                   |
|    3 | `description`          | `varchar(255)`        | 是   | `NULL`        | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —                   |
|    4 | `weekdays`             | `json`                | 是   | `NULL`        | —   | —              | —       | —                  | —                   |
|    5 | `trigger_time`         | `varchar(8)`          | 否   | —             | MUL | —              | utf8mb4 | utf8mb4_unicode_ci | —                   |
|    6 | `issue_quantity`       | `int(10) unsigned`    | 否   | `1`           | —   | —              | —       | —                  | —                   |
|    7 | `valid_duration_hours` | `int(10) unsigned`    | 是   | `NULL`        | —   | —              | —       | —                  | —                   |
|    8 | `discount_scope`       | `varchar(20)`         | 否   | `first_month` | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —                   |
|    9 | `discount_type`        | `varchar(20)`         | 否   | —             | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —                   |
|   10 | `discount_value`       | `decimal(12,2)`       | 否   | `0.00`        | —   | —              | —       | —                  | —                   |
|   11 | `min_amount`           | `decimal(12,2)`       | 否   | `0.00`        | —   | —              | —       | —                  | —                   |
|   12 | `max_discount_amount`  | `decimal(12,2)`       | 是   | `NULL`        | —   | —              | —       | —                  | —                   |
|   13 | `billing_cycles`       | `json`                | 是   | `NULL`        | —   | —              | —       | —                  | —                   |
|   14 | `product_ids`          | `json`                | 是   | `NULL`        | —   | —              | —       | —                  | —                   |
|   15 | `first_order_only`     | `tinyint(1)`          | 否   | `0`           | —   | —              | —       | —                  | —                   |
|   16 | `per_user_limit`       | `int(10) unsigned`    | 是   | `NULL`        | —   | —              | —       | —                  | —                   |
|   17 | `status`               | `tinyint(4)`          | 否   | `1`           | MUL | —              | —       | —                  | 状态：0=禁用 1=启用 |
|   18 | `sort_order`           | `int(10) unsigned`    | 否   | `0`           | —   | —              | —       | —                  | —                   |
|   19 | `last_dispatched_at`   | `timestamp`           | 是   | `NULL`        | —   | —              | —       | —                  | —                   |
|   20 | `last_coupon_id`       | `bigint(20) unsigned` | 是   | `NULL`        | MUL | —              | —       | —                  | —                   |
|   21 | `remark`               | `varchar(255)`        | 是   | `NULL`        | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —                   |
|   22 | `operator`             | `varchar(100)`        | 是   | `NULL`        | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —                   |
|   23 | `trace_id`             | `varchar(100)`        | 是   | `NULL`        | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —                   |
|   24 | `created_at`           | `timestamp`           | 是   | `NULL`        | —   | —              | —       | —                  | —                   |
|   25 | `updated_at`           | `timestamp`           | 是   | `NULL`        | —   | —              | —       | —                  | —                   |

#### 索引

| 索引名                                       | 唯一 | 类型    | 字段                     | 基数 | 注释 |
| -------------------------------------------- | ---- | ------- | ------------------------ | ---: | ---- |
| `coupon_campaigns_status_sort_idx`           | 否   | `BTREE` | `status`, `sort_order`   |    1 | —    |
| `coupon_campaigns_trigger_status_idx`        | 否   | `BTREE` | `trigger_time`, `status` |    2 | —    |
| `idx_stage2_coupon_campaigns_last_coupon_id` | 否   | `BTREE` | `last_coupon_id`         |    3 | —    |
| `PRIMARY`                                    | 是   | `BTREE` | `id`                     |    3 | —    |

#### 外键约束

| 约束名                                      | 字段             | 引用表    | 引用字段 | 更新规则   | 删除规则   |
| ------------------------------------------- | ---------------- | --------- | -------- | ---------- | ---------- |
| `fk_stage2_coupon_campaigns_last_coupon_id` | `last_coupon_id` | `coupons` | `id`     | `RESTRICT` | `SET NULL` |

### 2.16 `failed_jobs`

- 类型：`BASE TABLE`
- 引擎：`InnoDB`
- 估算行数：`0`
- 数据大小：`16 KB`
- 索引大小：`16 KB`
- 自增值：`277`
- 排序规则：`utf8mb4_unicode_ci`
- 表注释：—

#### 字段

| 序号 | 字段         | 类型                  | 可空 | 默认值              | 键  | 额外           | 字符集  | 排序规则           | 注释 |
| ---: | ------------ | --------------------- | ---- | ------------------- | --- | -------------- | ------- | ------------------ | ---- |
|    1 | `id`         | `bigint(20) unsigned` | 否   | —                   | PRI | auto_increment | —       | —                  | —    |
|    2 | `uuid`       | `varchar(255)`        | 否   | —                   | UNI | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|    3 | `connection` | `text`                | 否   | —                   | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|    4 | `queue`      | `text`                | 否   | —                   | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|    5 | `payload`    | `longtext`            | 否   | —                   | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|    6 | `exception`  | `longtext`            | 否   | —                   | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|    7 | `failed_at`  | `timestamp`           | 否   | `CURRENT_TIMESTAMP` | —   | —              | —       | —                  | —    |

#### 索引

| 索引名                    | 唯一 | 类型    | 字段   | 基数 | 注释 |
| ------------------------- | ---- | ------- | ------ | ---: | ---- |
| `failed_jobs_uuid_unique` | 是   | `BTREE` | `uuid` |    0 | —    |
| `PRIMARY`                 | 是   | `BTREE` | `id`   |    0 | —    |

#### 外键约束

无数据库级外键约束。

### 2.17 `first_product_groups`

- 类型：`BASE TABLE`
- 引擎：`InnoDB`
- 估算行数：`36`
- 数据大小：`16 KB`
- 索引大小：`32 KB`
- 自增值：`110`
- 排序规则：`utf8mb4_unicode_ci`
- 表注释：—

#### 字段

| 序号 | 字段           | 类型                  | 可空 | 默认值 | 键  | 额外           | 字符集  | 排序规则           | 注释                                |
| ---: | -------------- | --------------------- | ---- | ------ | --- | -------------- | ------- | ------------------ | ----------------------------------- |
|    1 | `id`           | `bigint(20) unsigned` | 否   | —      | PRI | auto_increment | —       | —                  | —                                   |
|    2 | `code`         | `varchar(50)`         | 是   | `NULL` | UNI | —              | utf8mb4 | utf8mb4_unicode_ci | 业务编码：vps/dedicated/domain/…    |
|    3 | `product_type` | `varchar(50)`         | 是   | `NULL` | —   | —              | utf8mb4 | utf8mb4_unicode_ci | 商品类型：cloud_server/game_cloud/… |
|    4 | `name`         | `varchar(100)`        | 否   | —      | —   | —              | utf8mb4 | utf8mb4_unicode_ci | 名称                                |
|    5 | `slug`         | `varchar(100)`        | 是   | `NULL` | UNI | —              | utf8mb4 | utf8mb4_unicode_ci | URL标识                             |
|    6 | `description`  | `varchar(255)`        | 是   | `NULL` | —   | —              | utf8mb4 | utf8mb4_unicode_ci | 分组说明                            |
|    7 | `icon`         | `varchar(100)`        | 是   | `NULL` | —   | —              | utf8mb4 | utf8mb4_unicode_ci | 图标                                |
|    8 | `banner_image` | `varchar(255)`        | 是   | `NULL` | —   | —              | utf8mb4 | utf8mb4_unicode_ci | 横幅图                              |
|    9 | `sort_order`   | `int(11)`             | 否   | `0`    | —   | —              | —       | —                  | 排序                                |
|   10 | `is_visible`   | `tinyint(3) unsigned` | 否   | `1`    | —   | —              | —       | —                  | 前台可见                            |
|   11 | `is_system`    | `tinyint(3) unsigned` | 否   | `0`    | —   | —              | —       | —                  | 系统内置                            |
|   12 | `created_at`   | `timestamp`           | 是   | `NULL` | —   | —              | —       | —                  | —                                   |
|   13 | `updated_at`   | `timestamp`           | 是   | `NULL` | —   | —              | —       | —                  | —                                   |

#### 索引

| 索引名                             | 唯一 | 类型    | 字段   | 基数 | 注释 |
| ---------------------------------- | ---- | ------- | ------ | ---: | ---- |
| `first_product_groups_code_unique` | 是   | `BTREE` | `code` |   36 | —    |
| `first_product_groups_slug_unique` | 是   | `BTREE` | `slug` |   36 | —    |
| `PRIMARY`                          | 是   | `BTREE` | `id`   |   36 | —    |

#### 外键约束

无数据库级外键约束。

### 2.18 `gateway_logs`

- 类型：`BASE TABLE`
- 引擎：`InnoDB`
- 估算行数：`5`
- 数据大小：`16 KB`
- 索引大小：`112 KB`
- 自增值：`1000083`
- 排序规则：`utf8mb4_unicode_ci`
- 表注释：—

#### 字段

| 序号 | 字段            | 类型                  | 可空 | 默认值    | 键  | 额外           | 字符集  | 排序规则           | 注释                                    |
| ---: | --------------- | --------------------- | ---- | --------- | --- | -------------- | ------- | ------------------ | --------------------------------------- |
|    1 | `id`            | `bigint(20) unsigned` | 否   | —         | PRI | auto_increment | —       | —                  | —                                       |
|    2 | `plugin_id`     | `bigint(20) unsigned` | 是   | `NULL`    | MUL | —              | —       | —                  | —                                       |
|    3 | `gateway_key`   | `varchar(120)`        | 是   | `NULL`    | MUL | —              | utf8mb4 | utf8mb4_unicode_ci | —                                       |
|    4 | `gateway`       | `varchar(50)`         | 否   | —         | MUL | —              | utf8mb4 | utf8mb4_unicode_ci | 网关标识: alipay_f2f, wechat_native 等  |
|    5 | `action`        | `varchar(50)`         | 否   | —         | —   | —              | utf8mb4 | utf8mb4_unicode_ci | 操作: precreate, notify, query, refund  |
|    6 | `out_trade_no`  | `varchar(128)`        | 是   | `NULL`    | MUL | —              | utf8mb4 | utf8mb4_unicode_ci | 商户订单号                              |
|    7 | `trade_no`      | `varchar(128)`        | 是   | `NULL`    | —   | —              | utf8mb4 | utf8mb4_unicode_ci | 第三方交易号                            |
|    8 | `invoice_id`    | `bigint(20) unsigned` | 是   | `NULL`    | MUL | —              | —       | —                  | 关联账单ID                              |
|    9 | `trace_id`      | `varchar(64)`         | 是   | `NULL`    | MUL | —              | utf8mb4 | utf8mb4_unicode_ci | —                                       |
|   10 | `request_data`  | `json`                | 是   | `NULL`    | —   | —              | —       | —                  | 请求数据(脱敏后)                        |
|   11 | `response_data` | `json`                | 是   | `NULL`    | —   | —              | —       | —                  | 响应数据                                |
|   12 | `result_status` | `varchar(20)`         | 否   | `unknown` | —   | —              | utf8mb4 | utf8mb4_unicode_ci | 结果: success, failed, pending, unknown |
|   13 | `error_msg`     | `text`                | 是   | `NULL`    | —   | —              | utf8mb4 | utf8mb4_unicode_ci | 错误信息                                |
|   14 | `ip_address`    | `varchar(45)`         | 是   | `NULL`    | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —                                       |
|   15 | `created_at`    | `timestamp`           | 是   | `NULL`    | MUL | —              | —       | —                  | —                                       |
|   16 | `updated_at`    | `timestamp`           | 是   | `NULL`    | —   | —              | —       | —                  | —                                       |

#### 索引

| 索引名                              | 唯一 | 类型    | 字段                        | 基数 | 注释 |
| ----------------------------------- | ---- | ------- | --------------------------- | ---: | ---- |
| `gateway_logs_created_at_index`     | 否   | `BTREE` | `created_at`                |    2 | —    |
| `gateway_logs_gateway_action_index` | 否   | `BTREE` | `gateway`, `action`         |    2 | —    |
| `gateway_logs_gateway_key_idx`      | 否   | `BTREE` | `gateway_key`, `created_at` |    2 | —    |
| `gateway_logs_invoice_id_index`     | 否   | `BTREE` | `invoice_id`                |    1 | —    |
| `gateway_logs_out_trade_no_index`   | 否   | `BTREE` | `out_trade_no`              |    4 | —    |
| `gateway_logs_plugin_created_idx`   | 否   | `BTREE` | `plugin_id`, `created_at`   |    2 | —    |
| `gateway_logs_trace_idx`            | 否   | `BTREE` | `trace_id`                  |    1 | —    |
| `PRIMARY`                           | 是   | `BTREE` | `id`                        |    5 | —    |

#### 外键约束

| 约束名                              | 字段         | 引用表                | 引用字段 | 更新规则   | 删除规则   |
| ----------------------------------- | ------------ | --------------------- | -------- | ---------- | ---------- |
| `fk_stage2_gateway_logs_invoice_id` | `invoice_id` | `invoices`            | `id`     | `RESTRICT` | `SET NULL` |
| `gateway_logs_plugin_fk`            | `plugin_id`  | `integration_plugins` | `id`     | `RESTRICT` | `SET NULL` |

### 2.19 `integration_plugins`

- 类型：`BASE TABLE`
- 引擎：`InnoDB`
- 估算行数：`1`
- 数据大小：`16 KB`
- 索引大小：`48 KB`
- 自增值：`38`
- 排序规则：`utf8mb4_unicode_ci`
- 表注释：—

#### 字段

| 序号 | 字段                 | 类型                  | 可空 | 默认值  | 键  | 额外           | 字符集  | 排序规则           | 注释 |
| ---: | -------------------- | --------------------- | ---- | ------- | --- | -------------- | ------- | ------------------ | ---- |
|    1 | `id`                 | `bigint(20) unsigned` | 否   | —       | PRI | auto_increment | —       | —                  | —    |
|    2 | `domain`             | `varchar(32)`         | 否   | —       | MUL | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|    3 | `slug`               | `varchar(120)`        | 否   | —       | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|    4 | `plugin_key`         | `varchar(120)`        | 否   | —       | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|    5 | `name`               | `varchar(120)`        | 否   | —       | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|    6 | `version`            | `varchar(32)`         | 否   | `1.0.0` | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|    7 | `manifest_hash`      | `varchar(64)`         | 是   | `NULL`  | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|    8 | `provider_class`     | `varchar(255)`        | 是   | `NULL`  | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|    9 | `entry_class`        | `varchar(255)`        | 否   | —       | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|   10 | `capabilities_json`  | `json`                | 是   | `NULL`  | —   | —              | —       | —                  | —    |
|   11 | `config_schema_json` | `json`                | 是   | `NULL`  | —   | —              | —       | —                  | —    |
|   12 | `status`             | `tinyint(3) unsigned` | 否   | `0`     | —   | —              | —       | —                  | —    |
|   13 | `installed_at`       | `timestamp`           | 是   | `NULL`  | —   | —              | —       | —                  | —    |
|   14 | `enabled_at`         | `timestamp`           | 是   | `NULL`  | —   | —              | —       | —                  | —    |
|   15 | `disabled_at`        | `timestamp`           | 是   | `NULL`  | —   | —              | —       | —                  | —    |
|   16 | `installed_by`       | `bigint(20) unsigned` | 是   | `NULL`  | —   | —              | —       | —                  | —    |
|   17 | `enabled_by`         | `bigint(20) unsigned` | 是   | `NULL`  | —   | —              | —       | —                  | —    |
|   18 | `source_hash`        | `varchar(128)`        | 是   | `NULL`  | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|   19 | `created_at`         | `timestamp`           | 是   | `NULL`  | —   | —              | —       | —                  | —    |
|   20 | `updated_at`         | `timestamp`           | 是   | `NULL`  | —   | —              | —       | —                  | —    |

#### 索引

| 索引名                                    | 唯一 | 类型    | 字段                   | 基数 | 注释 |
| ----------------------------------------- | ---- | ------- | ---------------------- | ---: | ---- |
| `integration_plugins_domain_key_unique`   | 是   | `BTREE` | `domain`, `plugin_key` |    1 | —    |
| `integration_plugins_domain_slug_unique`  | 是   | `BTREE` | `domain`, `slug`       |    1 | —    |
| `integration_plugins_domain_status_index` | 否   | `BTREE` | `domain`, `status`     |    1 | —    |
| `PRIMARY`                                 | 是   | `BTREE` | `id`                   |    1 | —    |

#### 外键约束

无数据库级外键约束。

### 2.20 `integration_plugin_bindings`

- 类型：`BASE TABLE`
- 引擎：`InnoDB`
- 估算行数：`1`
- 数据大小：`16 KB`
- 索引大小：`80 KB`
- 自增值：`3`
- 排序规则：`utf8mb4_unicode_ci`
- 表注释：—

#### 字段

| 序号 | 字段                  | 类型                  | 可空 | 默认值   | 键  | 额外           | 字符集  | 排序规则           | 注释                                                        |
| ---: | --------------------- | --------------------- | ---- | -------- | --- | -------------- | ------- | ------------------ | ----------------------------------------------------------- |
|    1 | `id`                  | `bigint(20) unsigned` | 否   | —        | PRI | auto_increment | —       | —                  | —                                                           |
|    2 | `domain`              | `varchar(32)`         | 否   | —        | MUL | —              | utf8mb4 | utf8mb4_unicode_ci | —                                                           |
|    3 | `plugin_id`           | `bigint(20) unsigned` | 否   | —        | MUL | —              | —       | —                  | —                                                           |
|    4 | `binding_type`        | `varchar(50)`         | 否   | —        | —   | —              | utf8mb4 | utf8mb4_unicode_ci | global/supplier/product/service/payment/notification/custom |
|    5 | `bindable_type`       | `varchar(120)`        | 否   | `global` | MUL | —              | utf8mb4 | utf8mb4_unicode_ci | —                                                           |
|    6 | `bindable_id`         | `bigint(20) unsigned` | 否   | `0`      | —   | —              | —       | —                  | —                                                           |
|    7 | `binding_key`         | `varchar(120)`        | 否   | —        | —   | —              | utf8mb4 | utf8mb4_unicode_ci | 同一对象下的绑定名                                          |
|    8 | `provider_key`        | `varchar(120)`        | 是   | `NULL`   | —   | —              | utf8mb4 | utf8mb4_unicode_ci | 外部协议标识快照                                            |
|    9 | `priority`            | `int(11)`             | 否   | `0`      | —   | —              | —       | —                  | —                                                           |
|   10 | `status`              | `tinyint(3) unsigned` | 否   | `1`      | —   | —              | —       | —                  | —                                                           |
|   11 | `config_json`         | `json`                | 是   | `NULL`   | —   | —              | —       | —                  | —                                                           |
|   12 | `secret_json`         | `longtext`            | 是   | `NULL`   | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —                                                           |
|   13 | `has_secret_json`     | `json`                | 是   | `NULL`   | —   | —              | —       | —                  | —                                                           |
|   14 | `runtime_policy_json` | `json`                | 是   | `NULL`   | —   | —              | —       | —                  | —                                                           |
|   15 | `created_by`          | `bigint(20) unsigned` | 是   | `NULL`   | —   | —              | —       | —                  | —                                                           |
|   16 | `updated_by`          | `bigint(20) unsigned` | 是   | `NULL`   | —   | —              | —       | —                  | —                                                           |
|   17 | `backfill_batch_id`   | `varchar(64)`         | 是   | `NULL`   | MUL | —              | utf8mb4 | utf8mb4_unicode_ci | —                                                           |
|   18 | `created_at`          | `timestamp`           | 是   | `NULL`   | —   | —              | —       | —                  | —                                                           |
|   19 | `updated_at`          | `timestamp`           | 是   | `NULL`   | —   | —              | —       | —                  | —                                                           |

#### 索引

| 索引名                                       | 唯一 | 类型    | 字段                                                                    | 基数 | 注释 |
| -------------------------------------------- | ---- | ------- | ----------------------------------------------------------------------- | ---: | ---- |
| `plugin_bindings_backfill_batch_idx`         | 否   | `BTREE` | `backfill_batch_id`                                                     |    1 | —    |
| `plugin_bindings_bindable_idx`               | 否   | `BTREE` | `bindable_type`, `bindable_id`, `domain`                                |    1 | —    |
| `plugin_bindings_domain_provider_status_idx` | 否   | `BTREE` | `domain`, `provider_key`, `status`                                      |    1 | —    |
| `plugin_bindings_plugin_status_idx`          | 否   | `BTREE` | `plugin_id`, `status`                                                   |    1 | —    |
| `plugin_bindings_unique`                     | 是   | `BTREE` | `domain`, `binding_type`, `bindable_type`, `bindable_id`, `binding_key` |    1 | —    |
| `PRIMARY`                                    | 是   | `BTREE` | `id`                                                                    |    1 | —    |

#### 外键约束

| 约束名                                          | 字段        | 引用表                | 引用字段 | 更新规则   | 删除规则   |
| ----------------------------------------------- | ----------- | --------------------- | -------- | ---------- | ---------- |
| `integration_plugin_bindings_plugin_id_foreign` | `plugin_id` | `integration_plugins` | `id`     | `RESTRICT` | `RESTRICT` |

### 2.21 `integration_plugin_configs`

- 类型：`BASE TABLE`
- 引擎：`InnoDB`
- 估算行数：`0`
- 数据大小：`16 KB`
- 索引大小：`16 KB`
- 自增值：`37`
- 排序规则：`utf8mb4_unicode_ci`
- 表注释：—

#### 字段

| 序号 | 字段              | 类型                  | 可空 | 默认值 | 键  | 额外           | 字符集  | 排序规则           | 注释 |
| ---: | ----------------- | --------------------- | ---- | ------ | --- | -------------- | ------- | ------------------ | ---- |
|    1 | `id`              | `bigint(20) unsigned` | 否   | —      | PRI | auto_increment | —       | —                  | —    |
|    2 | `plugin_id`       | `bigint(20) unsigned` | 否   | —      | UNI | —              | —       | —                  | —    |
|    3 | `config_json`     | `json`                | 是   | `NULL` | —   | —              | —       | —                  | —    |
|    4 | `secret_json`     | `longtext`            | 是   | `NULL` | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|    5 | `has_secret_json` | `json`                | 是   | `NULL` | —   | —              | —       | —                  | —    |
|    6 | `updated_by`      | `bigint(20) unsigned` | 是   | `NULL` | —   | —              | —       | —                  | —    |
|    7 | `created_at`      | `timestamp`           | 是   | `NULL` | —   | —              | —       | —                  | —    |
|    8 | `updated_at`      | `timestamp`           | 是   | `NULL` | —   | —              | —       | —                  | —    |

#### 索引

| 索引名                                     | 唯一 | 类型    | 字段        | 基数 | 注释 |
| ------------------------------------------ | ---- | ------- | ----------- | ---: | ---- |
| `integration_plugin_configs_plugin_unique` | 是   | `BTREE` | `plugin_id` |    0 | —    |
| `PRIMARY`                                  | 是   | `BTREE` | `id`        |    0 | —    |

#### 外键约束

| 约束名                                         | 字段        | 引用表                | 引用字段 | 更新规则   | 删除规则  |
| ---------------------------------------------- | ----------- | --------------------- | -------- | ---------- | --------- |
| `integration_plugin_configs_plugin_id_foreign` | `plugin_id` | `integration_plugins` | `id`     | `RESTRICT` | `CASCADE` |

### 2.22 `integration_plugin_runtime_logs`

- 类型：`BASE TABLE`
- 引擎：`InnoDB`
- 估算行数：`8`
- 数据大小：`16 KB`
- 索引大小：`80 KB`
- 自增值：`1014097`
- 排序规则：`utf8mb4_unicode_ci`
- 表注释：—

#### 字段

| 序号 | 字段                 | 类型                  | 可空 | 默认值 | 键  | 额外           | 字符集  | 排序规则           | 注释 |
| ---: | -------------------- | --------------------- | ---- | ------ | --- | -------------- | ------- | ------------------ | ---- |
|    1 | `id`                 | `bigint(20) unsigned` | 否   | —      | PRI | auto_increment | —       | —                  | —    |
|    2 | `trace_id`           | `varchar(64)`         | 是   | `NULL` | MUL | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|    3 | `domain`             | `varchar(32)`         | 否   | —      | MUL | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|    4 | `plugin_id`          | `bigint(20) unsigned` | 是   | `NULL` | MUL | —              | —       | —                  | —    |
|    5 | `plugin_key`         | `varchar(120)`        | 否   | —      | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|    6 | `slug`               | `varchar(120)`        | 否   | —      | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|    7 | `action`             | `varchar(120)`        | 否   | —      | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|    8 | `binding_id`         | `bigint(20) unsigned` | 是   | `NULL` | —   | —              | —       | —                  | —    |
|    9 | `bindable_type`      | `varchar(120)`        | 是   | `NULL` | MUL | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|   10 | `bindable_id`        | `bigint(20) unsigned` | 是   | `NULL` | —   | —              | —       | —                  | —    |
|   11 | `actor_type`         | `varchar(50)`         | 是   | `NULL` | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|   12 | `actor_id`           | `bigint(20) unsigned` | 是   | `NULL` | —   | —              | —       | —                  | —    |
|   13 | `status`             | `varchar(30)`         | 否   | —      | MUL | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|   14 | `duration_ms`        | `int(10) unsigned`    | 是   | `NULL` | —   | —              | —       | —                  | —    |
|   15 | `error_code`         | `varchar(80)`         | 是   | `NULL` | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|   16 | `error_message`      | `varchar(500)`        | 是   | `NULL` | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|   17 | `request_meta_json`  | `json`                | 是   | `NULL` | —   | —              | —       | —                  | —    |
|   18 | `response_meta_json` | `json`                | 是   | `NULL` | —   | —              | —       | —                  | —    |
|   19 | `created_at`         | `timestamp`           | 是   | `NULL` | —   | —              | —       | —                  | —    |

#### 索引

| 索引名                                     | 唯一 | 类型    | 字段                                         | 基数 | 注释 |
| ------------------------------------------ | ---- | ------- | -------------------------------------------- | ---: | ---- |
| `plugin_runtime_bindable_idx`              | 否   | `BTREE` | `bindable_type`, `bindable_id`, `created_at` |    3 | —    |
| `plugin_runtime_domain_action_created_idx` | 否   | `BTREE` | `domain`, `action`, `created_at`             |    4 | —    |
| `plugin_runtime_plugin_created_idx`        | 否   | `BTREE` | `plugin_id`, `created_at`                    |    3 | —    |
| `plugin_runtime_status_created_idx`        | 否   | `BTREE` | `status`, `created_at`                       |    3 | —    |
| `plugin_runtime_trace_idx`                 | 否   | `BTREE` | `trace_id`                                   |    8 | —    |
| `PRIMARY`                                  | 是   | `BTREE` | `id`                                         |    8 | —    |

#### 外键约束

| 约束名                                              | 字段        | 引用表                | 引用字段 | 更新规则   | 删除规则   |
| --------------------------------------------------- | ----------- | --------------------- | -------- | ---------- | ---------- |
| `integration_plugin_runtime_logs_plugin_id_foreign` | `plugin_id` | `integration_plugins` | `id`     | `RESTRICT` | `SET NULL` |

### 2.23 `invoices`

- 类型：`BASE TABLE`
- 引擎：`InnoDB`
- 估算行数：`45`
- 数据大小：`16 KB`
- 索引大小：`208 KB`
- 自增值：`3068`
- 排序规则：`utf8mb4_unicode_ci`
- 表注释：账单主表，所有购买、续费、充值、扣款和退款流程以账单为财务入口

#### 字段

| 序号 | 字段                      | 类型                  | 可空 | 默认值   | 键  | 额外           | 字符集  | 排序规则           | 注释                                                                         |
| ---: | ------------------------- | --------------------- | ---- | -------- | --- | -------------- | ------- | ------------------ | ---------------------------------------------------------------------------- |
|    1 | `id`                      | `bigint(20) unsigned` | 否   | —        | PRI | auto_increment | —       | —                  | 账单自增主键                                                                 |
|    2 | `invoice_no`              | `varchar(32)`         | 否   | —        | UNI | —              | utf8mb4 | utf8mb4_unicode_ci | 业务账单号，对外展示和支付关联使用                                           |
|    3 | `user_id`                 | `bigint(20) unsigned` | 否   | —        | MUL | —              | —       | —                  | 所属用户ID                                                                   |
|    4 | `order_id`                | `bigint(20) unsigned` | 是   | `NULL`   | MUL | —              | —       | —                  | 内部订单/开通投影ID，仅用于流程追踪                                          |
|    5 | `origin_invoice_id`       | `bigint(20) unsigned` | 是   | `NULL`   | MUL | —              | —       | —                  | —                                                                            |
|    6 | `product_id`              | `bigint(20) unsigned` | 是   | `NULL`   | MUL | —              | —       | —                  | 关联商品ID，手工账单可为空                                                   |
|    7 | `product_spec_snapshot`   | `varchar(255)`        | 是   | `NULL`   | —   | —              | utf8mb4 | utf8mb4_unicode_ci | 账单生成时的商品规格展示快照                                                 |
|    8 | `product_type_snapshot`   | `varchar(100)`        | 是   | `NULL`   | —   | —              | utf8mb4 | utf8mb4_unicode_ci | 账单生成时的商品类型快照                                                     |
|    9 | `service_id`              | `bigint(20) unsigned` | 是   | `NULL`   | MUL | —              | —       | —                  | 关联服务实例ID                                                               |
|   10 | `coupon_id`               | `bigint(20) unsigned` | 是   | `NULL`   | MUL | —              | —       | —                  | 使用的优惠券模板ID                                                           |
|   11 | `user_coupon_id`          | `bigint(20) unsigned` | 是   | `NULL`   | MUL | —              | —       | —                  | 使用的用户优惠券ID                                                           |
|   12 | `coupon_code`             | `varchar(100)`        | 是   | `NULL`   | —   | —              | utf8mb4 | utf8mb4_unicode_ci | 使用的优惠码快照                                                             |
|   13 | `type`                    | `varchar(20)`         | 否   | `normal` | —   | —              | utf8mb4 | utf8mb4_unicode_ci | 账单类型：normal/new/renew/recharge/deduction/referral_credit/manual/upgrade |
|   14 | `amount`                  | `decimal(12,2)`       | 否   | —        | —   | —              | —       | —                  | 账单应收金额                                                                 |
|   15 | `currency`                | `varchar(3)`          | 否   | `CNY`    | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —                                                                            |
|   16 | `discount`                | `decimal(12,2)`       | 否   | `0.00`   | —   | —              | —       | —                  | 账单优惠抵扣金额                                                             |
|   17 | `billing_cycle`           | `varchar(30)`         | 是   | `NULL`   | —   | —              | utf8mb4 | utf8mb4_unicode_ci | 计费周期：monthly/quarterly/annually/onetime 等                              |
|   18 | `quantity`                | `int(10) unsigned`    | 否   | `1`      | —   | —              | —       | —                  | 购买数量或计费数量                                                           |
|   19 | `config_snapshot`         | `json`                | 是   | `NULL`   | —   | —              | —       | —                  | 下单配置快照 JSON                                                            |
|   20 | `config_pricing_snapshot` | `json`                | 是   | `NULL`   | —   | —              | —       | —                  | 配置项计价快照 JSON                                                          |
|   21 | `coupon_snapshot`         | `json`                | 是   | `NULL`   | —   | —              | —       | —                  | 优惠券使用快照 JSON                                                          |
|   22 | `paid_amount`             | `decimal(12,2)`       | 否   | `0.00`   | —   | —              | —       | —                  | 已支付入账金额                                                               |
|   23 | `status`                  | `tinyint(4)`          | 否   | `0`      | MUL | —              | —       | —                  | 账单状态：0未付 1已付 2已取消 3逾期 5已退款 6部分退款                        |
|   24 | `due_date`                | `date`                | 是   | `NULL`   | —   | —              | —       | —                  | —                                                                            |
|   25 | `paid_at`                 | `timestamp`           | 是   | `NULL`   | —   | —              | —       | —                  | 账单支付完成时间                                                             |
|   26 | `deleted_at`              | `timestamp`           | 是   | `NULL`   | —   | —              | —       | —                  | —                                                                            |
|   27 | `refund_trace_id`         | `varchar(64)`         | 是   | `NULL`   | —   | —              | utf8mb4 | utf8mb4_unicode_ci | 退款链路追踪号                                                               |
|   28 | `refund_method`           | `varchar(32)`         | 是   | `NULL`   | —   | —              | utf8mb4 | utf8mb4_unicode_ci | 退款方式                                                                     |
|   29 | `refund_amount`           | `decimal(12,2)`       | 是   | `NULL`   | —   | —              | —       | —                  | 退款金额                                                                     |
|   30 | `refunded_at`             | `timestamp`           | 是   | `NULL`   | —   | —              | —       | —                  | 退款完成时间                                                                 |
|   31 | `created_at`              | `timestamp`           | 是   | `NULL`   | —   | —              | —       | —                  | 创建时间                                                                     |
|   32 | `updated_at`              | `timestamp`           | 是   | `NULL`   | —   | —              | —       | —                  | 更新时间                                                                     |
|   33 | `remark`                  | `varchar(255)`        | 是   | `NULL`   | —   | —              | utf8mb4 | utf8mb4_unicode_ci | 账单备注                                                                     |
|   34 | `operator`                | `varchar(50)`         | 是   | `NULL`   | —   | —              | utf8mb4 | utf8mb4_unicode_ci | 操作人快照                                                                   |
|   35 | `trace_id`                | `varchar(64)`         | 是   | `NULL`   | MUL | —              | utf8mb4 | utf8mb4_unicode_ci | 链路追踪号                                                                   |
|   36 | `idempotency_key`         | `varchar(64)`         | 是   | `NULL`   | —   | —              | utf8mb4 | utf8mb4_unicode_ci | 开放 API 下单幂等键，同一用户内唯一；站内下单为 NULL                         |

#### 索引

| 索引名                                 | 唯一 | 类型    | 字段                              | 基数 | 注释 |
| -------------------------------------- | ---- | ------- | --------------------------------- | ---: | ---- |
| `fk_invoices_user_coupon_id`           | 否   | `BTREE` | `user_coupon_id`                  |    6 | —    |
| `idx_stage2_invoices_coupon_id`        | 否   | `BTREE` | `coupon_id`                       |    6 | —    |
| `invoices_invoice_no_unique`           | 是   | `BTREE` | `invoice_no`                      |   45 | —    |
| `invoices_order_id_idx`                | 否   | `BTREE` | `order_id`                        |   24 | —    |
| `invoices_origin_invoice_id_foreign`   | 否   | `BTREE` | `origin_invoice_id`               |    1 | —    |
| `invoices_product_id_idx`              | 否   | `BTREE` | `product_id`                      |   22 | —    |
| `invoices_service_id_idx`              | 否   | `BTREE` | `service_id`                      |    8 | —    |
| `invoices_status_due_date_index`       | 否   | `BTREE` | `status`, `due_date`              |    6 | —    |
| `invoices_status_paid_at_idx`          | 否   | `BTREE` | `status`, `paid_at`               |   27 | —    |
| `invoices_trace_id_idx`                | 否   | `BTREE` | `trace_id`                        |   45 | —    |
| `invoices_user_idempotency_key_unique` | 是   | `BTREE` | `user_id`, `idempotency_key`      |   38 | —    |
| `invoices_user_status_created_idx`     | 否   | `BTREE` | `user_id`, `status`, `created_at` |   40 | —    |
| `invoices_user_status_id_idx`          | 否   | `BTREE` | `user_id`, `status`, `id`         |   45 | —    |
| `PRIMARY`                              | 是   | `BTREE` | `id`                              |   45 | —    |

#### 外键约束

| 约束名                               | 字段                | 引用表         | 引用字段 | 更新规则   | 删除规则   |
| ------------------------------------ | ------------------- | -------------- | -------- | ---------- | ---------- |
| `fk_invoices_order_id`               | `order_id`          | `orders`       | `id`     | `RESTRICT` | `SET NULL` |
| `fk_invoices_product_id`             | `product_id`        | `products`     | `id`     | `RESTRICT` | `RESTRICT` |
| `fk_invoices_user_coupon_id`         | `user_coupon_id`    | `user_coupons` | `id`     | `RESTRICT` | `SET NULL` |
| `fk_invoices_user_id`                | `user_id`           | `users`        | `id`     | `RESTRICT` | `RESTRICT` |
| `fk_stage2_invoices_coupon_id`       | `coupon_id`         | `coupons`      | `id`     | `RESTRICT` | `SET NULL` |
| `fk_stage2_invoices_service_id`      | `service_id`        | `services`     | `id`     | `RESTRICT` | `SET NULL` |
| `invoices_origin_invoice_id_foreign` | `origin_invoice_id` | `invoices`     | `id`     | `RESTRICT` | `SET NULL` |

### 2.24 `invoice_items`

- 类型：`BASE TABLE`
- 引擎：`InnoDB`
- 估算行数：`2`
- 数据大小：`16 KB`
- 索引大小：`16 KB`
- 自增值：`2799`
- 排序规则：`utf8mb4_unicode_ci`
- 表注释：账单明细表，记录账单内每个收费项目和快照信息

#### 字段

| 序号 | 字段              | 类型                  | 可空 | 默认值   | 键  | 额外           | 字符集  | 排序规则           | 注释                                             |
| ---: | ----------------- | --------------------- | ---- | -------- | --- | -------------- | ------- | ------------------ | ------------------------------------------------ |
|    1 | `id`              | `bigint(20) unsigned` | 否   | —        | PRI | auto_increment | —       | —                  | 账单明细自增主键                                 |
|    2 | `invoice_id`      | `bigint(20) unsigned` | 否   | —        | MUL | —              | —       | —                  | 所属账单ID                                       |
|    3 | `item_name`       | `varchar(200)`        | 否   | —        | —   | —              | utf8mb4 | utf8mb4_unicode_ci | 明细名称                                         |
|    4 | `item_type`       | `varchar(30)`         | 否   | `normal` | —   | —              | utf8mb4 | utf8mb4_unicode_ci | 明细类型：normal/config/addon/discount/refund 等 |
|    5 | `quantity`        | `int(10) unsigned`    | 否   | `1`      | —   | —              | —       | —                  | 明细数量                                         |
|    6 | `unit_price`      | `decimal(12,2)`       | 否   | `0.00`   | —   | —              | —       | —                  | 明细单价                                         |
|    7 | `discount_amount` | `decimal(12,2)`       | 否   | `0.00`   | —   | —              | —       | —                  | 明细优惠金额                                     |
|    8 | `line_amount`     | `decimal(12,2)`       | 否   | `0.00`   | —   | —              | —       | —                  | 明细小计金额                                     |
|    9 | `meta_json`       | `json`                | 是   | `NULL`   | —   | —              | —       | —                  | 明细扩展快照 JSON                                |
|   10 | `created_at`      | `timestamp`           | 是   | `NULL`   | —   | —              | —       | —                  | 创建时间                                         |
|   11 | `updated_at`      | `timestamp`           | 是   | `NULL`   | —   | —              | —       | —                  | 更新时间                                         |

#### 索引

| 索引名                           | 唯一 | 类型    | 字段         | 基数 | 注释 |
| -------------------------------- | ---- | ------- | ------------ | ---: | ---- |
| `invoice_items_invoice_id_index` | 否   | `BTREE` | `invoice_id` |    2 | —    |
| `PRIMARY`                        | 是   | `BTREE` | `id`         |    2 | —    |

#### 外键约束

| 约束名                        | 字段         | 引用表     | 引用字段 | 更新规则   | 删除规则  |
| ----------------------------- | ------------ | ---------- | -------- | ---------- | --------- |
| `fk_invoice_items_invoice_id` | `invoice_id` | `invoices` | `id`     | `RESTRICT` | `CASCADE` |

### 2.25 `jobs`

- 类型：`BASE TABLE`
- 引擎：`InnoDB`
- 估算行数：`0`
- 数据大小：`16 KB`
- 索引大小：`16 KB`
- 自增值：`4002`
- 排序规则：`utf8mb4_unicode_ci`
- 表注释：—

#### 字段

| 序号 | 字段           | 类型                  | 可空 | 默认值 | 键  | 额外           | 字符集  | 排序规则           | 注释 |
| ---: | -------------- | --------------------- | ---- | ------ | --- | -------------- | ------- | ------------------ | ---- |
|    1 | `id`           | `bigint(20) unsigned` | 否   | —      | PRI | auto_increment | —       | —                  | —    |
|    2 | `queue`        | `varchar(255)`        | 否   | —      | MUL | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|    3 | `payload`      | `longtext`            | 否   | —      | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|    4 | `attempts`     | `tinyint(3) unsigned` | 否   | —      | —   | —              | —       | —                  | —    |
|    5 | `reserved_at`  | `int(10) unsigned`    | 是   | `NULL` | —   | —              | —       | —                  | —    |
|    6 | `available_at` | `int(10) unsigned`    | 否   | —      | —   | —              | —       | —                  | —    |
|    7 | `created_at`   | `int(10) unsigned`    | 否   | —      | —   | —              | —       | —                  | —    |

#### 索引

| 索引名             | 唯一 | 类型    | 字段    | 基数 | 注释 |
| ------------------ | ---- | ------- | ------- | ---: | ---- |
| `jobs_queue_index` | 否   | `BTREE` | `queue` |    0 | —    |
| `PRIMARY`          | 是   | `BTREE` | `id`    |    0 | —    |

#### 外键约束

无数据库级外键约束。

### 2.26 `media_files`

- 类型：`BASE TABLE`
- 引擎：`InnoDB`
- 估算行数：`0`
- 数据大小：`16 KB`
- 索引大小：`48 KB`
- 自增值：`68`
- 排序规则：`utf8mb4_unicode_ci`
- 表注释：—

#### 字段

| 序号 | 字段          | 类型                  | 可空 | 默认值    | 键  | 额外           | 字符集  | 排序规则           | 注释                                                 |
| ---: | ------------- | --------------------- | ---- | --------- | --- | -------------- | ------- | ------------------ | ---------------------------------------------------- |
|    1 | `id`          | `bigint(20) unsigned` | 否   | —         | PRI | auto_increment | —       | —                  | —                                                    |
|    2 | `filename`    | `varchar(255)`        | 否   | —         | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —                                                    |
|    3 | `path`        | `varchar(500)`        | 否   | —         | —   | —              | utf8mb4 | utf8mb4_unicode_ci | 相对路径，如 /uploads/content/20260419/cover_xxx.jpg |
|    4 | `url`         | `varchar(500)`        | 否   | —         | —   | —              | utf8mb4 | utf8mb4_unicode_ci | 完整访问 URL                                         |
|    5 | `mime_type`   | `varchar(100)`        | 是   | `NULL`    | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —                                                    |
|    6 | `size`        | `bigint(20) unsigned` | 否   | `0`       | —   | —              | —       | —                  | 文件大小(字节)                                       |
|    7 | `width`       | `int(10) unsigned`    | 是   | `NULL`    | —   | —              | —       | —                  | 图片宽度                                             |
|    8 | `height`      | `int(10) unsigned`    | 是   | `NULL`    | —   | —              | —       | —                  | 图片高度                                             |
|    9 | `group`       | `varchar(50)`         | 否   | `content` | MUL | —              | utf8mb4 | utf8mb4_unicode_ci | 分组: content, avatar, brand 等                      |
|   10 | `uploaded_by` | `bigint(20) unsigned` | 否   | `0`       | MUL | —              | —       | —                  | 上传管理员ID                                         |
|   11 | `created_at`  | `timestamp`           | 是   | `NULL`    | MUL | —              | —       | —                  | —                                                    |
|   12 | `updated_at`  | `timestamp`           | 是   | `NULL`    | —   | —              | —       | —                  | —                                                    |

#### 索引

| 索引名                          | 唯一 | 类型    | 字段          | 基数 | 注释 |
| ------------------------------- | ---- | ------- | ------------- | ---: | ---- |
| `media_files_created_at_index`  | 否   | `BTREE` | `created_at`  |    0 | —    |
| `media_files_group_index`       | 否   | `BTREE` | `group`       |    0 | —    |
| `media_files_uploaded_by_index` | 否   | `BTREE` | `uploaded_by` |    0 | —    |
| `PRIMARY`                       | 是   | `BTREE` | `id`          |    0 | —    |

#### 外键约束

无数据库级外键约束。

### 2.27 `member_levels`

- 类型：`BASE TABLE`
- 引擎：`InnoDB`
- 估算行数：`0`
- 数据大小：`16 KB`
- 索引大小：`48 KB`
- 自增值：`12`
- 排序规则：`utf8mb4_unicode_ci`
- 表注释：—

#### 字段

| 序号 | 字段               | 类型                  | 可空 | 默认值 | 键  | 额外           | 字符集  | 排序规则           | 注释          |
| ---: | ------------------ | --------------------- | ---- | ------ | --- | -------------- | ------- | ------------------ | ------------- |
|    1 | `id`               | `bigint(20) unsigned` | 否   | —      | PRI | auto_increment | —       | —                  | —             |
|    2 | `name`             | `varchar(50)`         | 否   | —      | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —             |
|    3 | `code`             | `varchar(30)`         | 否   | —      | UNI | —              | utf8mb4 | utf8mb4_unicode_ci | —             |
|    4 | `sales_amount_min` | `decimal(12,2)`       | 否   | `0.00` | MUL | —              | —       | —                  | —             |
|    5 | `sales_amount_max` | `decimal(12,2)`       | 是   | `NULL` | —   | —              | —       | —                  | —             |
|    6 | `reward_rate`      | `decimal(5,2)`        | 否   | `0.00` | —   | —              | —       | —                  | —             |
|    7 | `status`           | `tinyint(4)`          | 否   | `1`    | MUL | —              | —       | —                  | 0=禁用 1=启用 |
|    8 | `sort_order`       | `int(11)`             | 否   | `0`    | —   | —              | —       | —                  | —             |
|    9 | `remark`           | `varchar(255)`        | 是   | `NULL` | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —             |
|   10 | `created_at`       | `timestamp`           | 是   | `NULL` | —   | —              | —       | —                  | —             |
|   11 | `updated_at`       | `timestamp`           | 是   | `NULL` | —   | —              | —       | —                  | —             |

#### 索引

| 索引名                         | 唯一 | 类型    | 字段                                   | 基数 | 注释 |
| ------------------------------ | ---- | ------- | -------------------------------------- | ---: | ---- |
| `idx_member_level_sales_range` | 否   | `BTREE` | `sales_amount_min`, `sales_amount_max` |    0 | —    |
| `idx_member_level_status_sort` | 否   | `BTREE` | `status`, `sort_order`                 |    0 | —    |
| `member_levels_code_unique`    | 是   | `BTREE` | `code`                                 |    0 | —    |
| `PRIMARY`                      | 是   | `BTREE` | `id`                                   |    0 | —    |

#### 外键约束

无数据库级外键约束。

### 2.28 `message_logs`

- 类型：`BASE TABLE`
- 引擎：`InnoDB`
- 估算行数：`2`
- 数据大小：`16 KB`
- 索引大小：`112 KB`
- 自增值：`2616`
- 排序规则：`utf8mb4_unicode_ci`
- 表注释：—

#### 字段

| 序号 | 字段            | 类型                  | 可空 | 默认值    | 键  | 额外           | 字符集  | 排序规则           | 注释                       |
| ---: | --------------- | --------------------- | ---- | --------- | --- | -------------- | ------- | ------------------ | -------------------------- |
|    1 | `id`            | `bigint(20) unsigned` | 否   | —         | PRI | auto_increment | —       | —                  | 消息日志ID                 |
|    2 | `plugin_id`     | `bigint(20) unsigned` | 是   | `NULL`    | MUL | —              | —       | —                  | 插件ID                     |
|    3 | `driver_key`    | `varchar(120)`        | 是   | `NULL`    | MUL | —              | utf8mb4 | utf8mb4_unicode_ci | 驱动标识                   |
|    4 | `trace_id`      | `varchar(64)`         | 是   | `NULL`    | MUL | —              | utf8mb4 | utf8mb4_unicode_ci | 链路追踪ID                 |
|    5 | `channel`       | `varchar(20)`         | 否   | —         | MUL | —              | utf8mb4 | utf8mb4_unicode_ci | 消息渠道：email/sms        |
|    6 | `recipient`     | `varchar(255)`        | 否   | —         | MUL | —              | utf8mb4 | utf8mb4_unicode_ci | 接收人邮箱或手机号         |
|    7 | `template_code` | `varchar(120)`        | 是   | `NULL`    | —   | —              | utf8mb4 | utf8mb4_unicode_ci | 业务模板编码或供应商模板ID |
|    8 | `subject`       | `varchar(255)`        | 是   | `NULL`    | —   | —              | utf8mb4 | utf8mb4_unicode_ci | 邮件主题                   |
|    9 | `content`       | `mediumtext`          | 否   | —         | —   | —              | utf8mb4 | utf8mb4_unicode_ci | 发送内容快照               |
|   10 | `params_json`   | `json`                | 是   | `NULL`    | —   | —              | —       | —                  | 渲染参数快照               |
|   11 | `provider`      | `varchar(120)`        | 是   | `NULL`    | —   | —              | utf8mb4 | utf8mb4_unicode_ci | 供应商或驱动               |
|   12 | `request_id`    | `varchar(100)`        | 是   | `NULL`    | MUL | —              | utf8mb4 | utf8mb4_unicode_ci | 供应商请求ID               |
|   13 | `status`        | `varchar(20)`         | 否   | `pending` | —   | —              | utf8mb4 | utf8mb4_unicode_ci | 发送状态                   |
|   14 | `error_msg`     | `text`                | 是   | `NULL`    | —   | —              | utf8mb4 | utf8mb4_unicode_ci | 失败原因                   |
|   15 | `sent_at`       | `timestamp`           | 是   | `NULL`    | —   | —              | —       | —                  | 发送完成时间               |
|   16 | `origin_type`   | `varchar(50)`         | 是   | `NULL`    | MUL | —              | utf8mb4 | utf8mb4_unicode_ci | 来源类型快照               |
|   17 | `origin_id`     | `bigint(20) unsigned` | 是   | `NULL`    | —   | —              | —       | —                  | 来源ID快照                 |
|   18 | `created_at`    | `timestamp`           | 是   | `NULL`    | —   | —              | —       | —                  | —                          |
|   19 | `updated_at`    | `timestamp`           | 是   | `NULL`    | —   | —              | —       | —                  | —                          |

#### 索引

| 索引名                                  | 唯一 | 类型    | 字段                                  | 基数 | 注释 |
| --------------------------------------- | ---- | ------- | ------------------------------------- | ---: | ---- |
| `message_logs_channel_driver_idx`       | 否   | `BTREE` | `channel`, `driver_key`, `created_at` |    2 | —    |
| `message_logs_driver_created_idx`       | 否   | `BTREE` | `driver_key`, `created_at`            |    2 | —    |
| `message_logs_origin_idx`               | 否   | `BTREE` | `origin_type`, `origin_id`            |    2 | —    |
| `message_logs_plugin_created_idx`       | 否   | `BTREE` | `plugin_id`, `created_at`             |    1 | —    |
| `message_logs_recipient_created_at_idx` | 否   | `BTREE` | `recipient`, `created_at`             |    2 | —    |
| `message_logs_request_id_idx`           | 否   | `BTREE` | `request_id`                          |    2 | —    |
| `message_logs_trace_idx`                | 否   | `BTREE` | `trace_id`                            |    2 | —    |
| `PRIMARY`                               | 是   | `BTREE` | `id`                                  |    2 | —    |

#### 外键约束

| 约束名                   | 字段        | 引用表                | 引用字段 | 更新规则   | 删除规则   |
| ------------------------ | ----------- | --------------------- | -------- | ---------- | ---------- |
| `message_logs_plugin_fk` | `plugin_id` | `integration_plugins` | `id`     | `RESTRICT` | `SET NULL` |

### 2.29 `migrations`

- 类型：`BASE TABLE`
- 引擎：`InnoDB`
- 估算行数：`206`
- 数据大小：`48 KB`
- 索引大小：`0 B`
- 自增值：`209`
- 排序规则：`utf8mb4_unicode_ci`
- 表注释：—

#### 字段

| 序号 | 字段        | 类型               | 可空 | 默认值 | 键  | 额外           | 字符集  | 排序规则           | 注释 |
| ---: | ----------- | ------------------ | ---- | ------ | --- | -------------- | ------- | ------------------ | ---- |
|    1 | `id`        | `int(10) unsigned` | 否   | —      | PRI | auto_increment | —       | —                  | —    |
|    2 | `migration` | `varchar(255)`     | 否   | —      | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|    3 | `batch`     | `int(11)`          | 否   | —      | —   | —              | —       | —                  | —    |

#### 索引

| 索引名    | 唯一 | 类型    | 字段 | 基数 | 注释 |
| --------- | ---- | ------- | ---- | ---: | ---- |
| `PRIMARY` | 是   | `BTREE` | `id` |  206 | —    |

#### 外键约束

无数据库级外键约束。

### 2.30 `notice_reads`

- 类型：`BASE TABLE`
- 引擎：`InnoDB`
- 估算行数：`2`
- 数据大小：`16 KB`
- 索引大小：`32 KB`
- 自增值：`175`
- 排序规则：`utf8mb4_unicode_ci`
- 表注释：—

#### 字段

| 序号 | 字段         | 类型                  | 可空 | 默认值              | 键  | 额外                        | 字符集 | 排序规则 | 注释 |
| ---: | ------------ | --------------------- | ---- | ------------------- | --- | --------------------------- | ------ | -------- | ---- |
|    1 | `id`         | `bigint(20) unsigned` | 否   | —                   | PRI | auto_increment              | —      | —        | —    |
|    2 | `user_id`    | `bigint(20) unsigned` | 否   | —                   | MUL | —                           | —      | —        | —    |
|    3 | `article_id` | `bigint(20) unsigned` | 否   | —                   | MUL | —                           | —      | —        | —    |
|    4 | `read_at`    | `timestamp`           | 否   | `CURRENT_TIMESTAMP` | —   | on update CURRENT_TIMESTAMP | —      | —        | —    |
|    5 | `created_at` | `timestamp`           | 否   | `CURRENT_TIMESTAMP` | —   | —                           | —      | —        | —    |

#### 索引

| 索引名                                   | 唯一 | 类型    | 字段                    | 基数 | 注释 |
| ---------------------------------------- | ---- | ------- | ----------------------- | ---: | ---- |
| `notice_reads_article_id_index`          | 否   | `BTREE` | `article_id`            |    2 | —    |
| `notice_reads_user_id_article_id_unique` | 是   | `BTREE` | `user_id`, `article_id` |    2 | —    |
| `PRIMARY`                                | 是   | `BTREE` | `id`                    |    2 | —    |

#### 外键约束

| 约束名                              | 字段         | 引用表             | 引用字段 | 更新规则   | 删除规则  |
| ----------------------------------- | ------------ | ------------------ | -------- | ---------- | --------- |
| `fk_stage2_notice_reads_article_id` | `article_id` | `content_articles` | `id`     | `RESTRICT` | `CASCADE` |
| `fk_stage2_notice_reads_user_id`    | `user_id`    | `users`            | `id`     | `RESTRICT` | `CASCADE` |

### 2.31 `notification_templates`

- 类型：`BASE TABLE`
- 引擎：`InnoDB`
- 估算行数：`0`
- 数据大小：`16 KB`
- 索引大小：`32 KB`
- 自增值：`1013`
- 排序规则：`utf8mb4_unicode_ci`
- 表注释：—

#### 字段

| 序号 | 字段                      | 类型                  | 可空 | 默认值   | 键  | 额外           | 字符集  | 排序规则           | 注释 |
| ---: | ------------------------- | --------------------- | ---- | -------- | --- | -------------- | ------- | ------------------ | ---- |
|    1 | `id`                      | `bigint(20) unsigned` | 否   | —        | PRI | auto_increment | —       | —                  | —    |
|    2 | `channel`                 | `varchar(20)`         | 否   | —        | MUL | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|    3 | `code`                    | `varchar(64)`         | 否   | —        | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|    4 | `name`                    | `varchar(120)`        | 否   | —        | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|    5 | `description`             | `varchar(500)`        | 否   | 空字符串 | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|    6 | `audience`                | `varchar(20)`         | 否   | `user`   | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|    7 | `subject`                 | `varchar(255)`        | 是   | `NULL`   | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|    8 | `content`                 | `longtext`            | 否   | —        | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|    9 | `variables_json`          | `json`                | 是   | `NULL`   | —   | —              | —       | —                  | —    |
|   10 | `provider_variables_json` | `json`                | 是   | `NULL`   | —   | —              | —       | —                  | —    |
|   11 | `provider_template_id`    | `varchar(120)`        | 是   | `NULL`   | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|   12 | `is_enabled`              | `tinyint(1)`          | 否   | `1`      | —   | —              | —       | —                  | —    |
|   13 | `is_custom`               | `tinyint(1)`          | 否   | `0`      | —   | —              | —       | —                  | —    |
|   14 | `sort_order`              | `int(10) unsigned`    | 否   | `0`      | —   | —              | —       | —                  | —    |
|   15 | `created_at`              | `timestamp`           | 是   | `NULL`   | —   | —              | —       | —                  | —    |
|   16 | `updated_at`              | `timestamp`           | 是   | `NULL`   | —   | —              | —       | —                  | —    |

#### 索引

| 索引名                                                  | 唯一 | 类型    | 字段                                | 基数 | 注释 |
| ------------------------------------------------------- | ---- | ------- | ----------------------------------- | ---: | ---- |
| `notification_templates_channel_audience_enabled_index` | 否   | `BTREE` | `channel`, `audience`, `is_enabled` |    0 | —    |
| `notification_templates_channel_code_unique`            | 是   | `BTREE` | `channel`, `code`                   |    0 | —    |
| `PRIMARY`                                               | 是   | `BTREE` | `id`                                |    0 | —    |

#### 外键约束

无数据库级外键约束。

### 2.32 `operation_logs`

- 类型：`BASE TABLE`
- 引擎：`InnoDB`
- 估算行数：`373`
- 数据大小：`192 KB`
- 索引大小：`80 KB`
- 自增值：`166161`
- 排序规则：`utf8mb4_unicode_ci`
- 表注释：—

#### 字段

| 序号 | 字段         | 类型                  | 可空 | 默认值              | 键  | 额外           | 字符集  | 排序规则           | 注释              |
| ---: | ------------ | --------------------- | ---- | ------------------- | --- | -------------- | ------- | ------------------ | ----------------- |
|    1 | `id`         | `bigint(20) unsigned` | 否   | —                   | PRI | auto_increment | —       | —                  | —                 |
|    2 | `user_id`    | `bigint(20) unsigned` | 是   | `NULL`              | MUL | —              | —       | —                  | —                 |
|    3 | `user_type`  | `varchar(10)`         | 是   | `NULL`              | —   | —              | utf8mb4 | utf8mb4_unicode_ci | admin&#124;client |
|    4 | `action`     | `varchar(100)`        | 否   | —                   | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —                 |
|    5 | `module`     | `varchar(50)`         | 是   | `NULL`              | MUL | —              | utf8mb4 | utf8mb4_unicode_ci | —                 |
|    6 | `subject_id` | `bigint(20) unsigned` | 是   | `NULL`              | —   | —              | —       | —                  | —                 |
|    7 | `context`    | `json`                | 是   | `NULL`              | —   | —              | —       | —                  | —                 |
|    8 | `ip_address` | `varchar(45)`         | 是   | `NULL`              | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —                 |
|    9 | `created_at` | `timestamp`           | 否   | `CURRENT_TIMESTAMP` | MUL | —              | —       | —                  | —                 |

#### 索引

| 索引名                                      | 唯一 | 类型    | 字段                                       | 基数 | 注释 |
| ------------------------------------------- | ---- | ------- | ------------------------------------------ | ---: | ---- |
| `operation_logs_created_at_idx`             | 否   | `BTREE` | `created_at`                               |   95 | —    |
| `operation_logs_module_created_at_index`    | 否   | `BTREE` | `module`, `created_at`                     |  139 | —    |
| `operation_logs_module_subject_created_idx` | 否   | `BTREE` | `module`, `subject_id`, `created_at`, `id` |  373 | —    |
| `operation_logs_user_created_at_idx`        | 否   | `BTREE` | `user_id`, `created_at`                    |  220 | —    |
| `operation_logs_user_type_created_at_idx`   | 否   | `BTREE` | `user_id`, `user_type`, `created_at`       |  220 | —    |
| `PRIMARY`                                   | 是   | `BTREE` | `id`                                       |  373 | —    |

#### 外键约束

无数据库级外键约束。

### 2.33 `orders`

- 类型：`BASE TABLE`
- 引擎：`InnoDB`
- 估算行数：`26`
- 数据大小：`16 KB`
- 索引大小：`176 KB`
- 自增值：`3113`
- 排序规则：`utf8mb4_unicode_ci`
- 表注释：—

#### 字段

| 序号 | 字段                      | 类型                  | 可空 | 默认值         | 键  | 额外           | 字符集  | 排序规则           | 注释                                                  |
| ---: | ------------------------- | --------------------- | ---- | -------------- | --- | -------------- | ------- | ------------------ | ----------------------------------------------------- |
|    1 | `id`                      | `bigint(20) unsigned` | 否   | —              | PRI | auto_increment | —       | —                  | —                                                     |
|    2 | `order_no`                | `varchar(32)`         | 否   | —              | UNI | —              | utf8mb4 | utf8mb4_unicode_ci | —                                                     |
|    3 | `user_id`                 | `bigint(20) unsigned` | 否   | —              | MUL | —              | —       | —                  | —                                                     |
|    4 | `product_id`              | `bigint(20) unsigned` | 是   | `NULL`         | MUL | —              | —       | —                  | —                                                     |
|    5 | `product_spec_snapshot`   | `varchar(200)`        | 是   | `NULL`         | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —                                                     |
|    6 | `product_type_snapshot`   | `varchar(50)`         | 是   | `NULL`         | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —                                                     |
|    7 | `service_id`              | `bigint(20) unsigned` | 是   | `NULL`         | MUL | —              | —       | —                  | —                                                     |
|    8 | `type`                    | `varchar(20)`         | 否   | —              | —   | —              | utf8mb4 | utf8mb4_unicode_ci | new&#124;renew&#124;upgrade&#124;downgrade            |
|    9 | `coupon_id`               | `bigint(20) unsigned` | 是   | `NULL`         | MUL | —              | —       | —                  | —                                                     |
|   10 | `user_coupon_id`          | `bigint(20) unsigned` | 是   | `NULL`         | MUL | —              | —       | —                  | —                                                     |
|   11 | `coupon_code`             | `varchar(50)`         | 是   | `NULL`         | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —                                                     |
|   12 | `amount`                  | `decimal(12,2)`       | 否   | —              | —   | —              | —       | —                  | —                                                     |
|   13 | `currency`                | `varchar(3)`          | 否   | `CNY`          | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —                                                     |
|   14 | `discount`                | `decimal(12,2)`       | 否   | `0.00`         | —   | —              | —       | —                  | —                                                     |
|   15 | `paid_amount`             | `decimal(12,2)`       | 否   | `0.00`         | —   | —              | —       | —                  | —                                                     |
|   16 | `billing_cycle`           | `varchar(20)`         | 是   | `NULL`         | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —                                                     |
|   17 | `quantity`                | `int(10) unsigned`    | 否   | `1`            | —   | —              | —       | —                  | —                                                     |
|   18 | `config_snapshot`         | `json`                | 是   | `NULL`         | —   | —              | —       | —                  | —                                                     |
|   19 | `config_pricing_snapshot` | `json`                | 是   | `NULL`         | —   | —              | —       | —                  | —                                                     |
|   20 | `coupon_snapshot`         | `json`                | 是   | `NULL`         | —   | —              | —       | —                  | —                                                     |
|   21 | `service_snapshot`        | `json`                | 是   | `NULL`         | —   | —              | —       | —                  | 服务实例快照：{instance_id, hostname}                 |
|   22 | `status`                  | `tinyint(4)`          | 否   | `0`            | MUL | —              | —       | —                  | 0=待付款 1=已付款 2=开通中 3=已完成 4=已取消 5=已退款 |
|   23 | `paid_at`                 | `timestamp`           | 是   | `NULL`         | —   | —              | —       | —                  | —                                                     |
|   24 | `deleted_at`              | `timestamp`           | 是   | `NULL`         | —   | —              | —       | —                  | —                                                     |
|   25 | `created_at`              | `timestamp`           | 是   | `NULL`         | MUL | —              | —       | —                  | —                                                     |
|   26 | `updated_at`              | `timestamp`           | 是   | `NULL`         | —   | —              | —       | —                  | —                                                     |
|   27 | `remark`                  | `varchar(255)`        | 是   | `NULL`         | —   | —              | utf8mb4 | utf8mb4_unicode_ci | 备注                                                  |
|   28 | `operator`                | `varchar(50)`         | 是   | `NULL`         | —   | —              | utf8mb4 | utf8mb4_unicode_ci | 操作人                                                |
|   29 | `trace_id`                | `varchar(64)`         | 是   | `NULL`         | MUL | —              | utf8mb4 | utf8mb4_unicode_ci | 链路追踪号                                            |
|   30 | `projection_type`         | `varchar(32)`         | 否   | `provisioning` | MUL | —              | utf8mb4 | utf8mb4_unicode_ci | 内部投影类型：provisioning=开通投影                   |

#### 索引

| 索引名                              | 唯一 | 类型    | 字段                                 | 基数 | 注释 |
| ----------------------------------- | ---- | ------- | ------------------------------------ | ---: | ---- |
| `orders_coupon_id_idx`              | 否   | `BTREE` | `coupon_id`                          |    1 | —    |
| `orders_created_at_idx`             | 否   | `BTREE` | `created_at`                         |   18 | —    |
| `orders_order_no_unique`            | 是   | `BTREE` | `order_no`                           |   26 | —    |
| `orders_product_id_idx`             | 否   | `BTREE` | `product_id`                         |   23 | —    |
| `orders_projection_type_idx`        | 否   | `BTREE` | `projection_type`                    |    1 | —    |
| `orders_service_status_id_idx`      | 否   | `BTREE` | `service_id`, `status`, `id`         |   26 | —    |
| `orders_status_type_created_at_idx` | 否   | `BTREE` | `status`, `type`, `created_at`, `id` |   26 | —    |
| `orders_trace_id_idx`               | 否   | `BTREE` | `trace_id`                           |   11 | —    |
| `orders_user_coupon_id_idx`         | 否   | `BTREE` | `user_coupon_id`                     |    1 | —    |
| `orders_user_id_index`              | 否   | `BTREE` | `user_id`                            |   23 | —    |
| `orders_user_status_id_idx`         | 否   | `BTREE` | `user_id`, `status`, `id`            |   26 | —    |
| `PRIMARY`                           | 是   | `BTREE` | `id`                                 |   26 | —    |

#### 外键约束

| 约束名                            | 字段             | 引用表         | 引用字段 | 更新规则   | 删除规则   |
| --------------------------------- | ---------------- | -------------- | -------- | ---------- | ---------- |
| `fk_stage2_orders_coupon_id`      | `coupon_id`      | `coupons`      | `id`     | `RESTRICT` | `SET NULL` |
| `fk_stage2_orders_product_id`     | `product_id`     | `products`     | `id`     | `RESTRICT` | `RESTRICT` |
| `fk_stage2_orders_service_id`     | `service_id`     | `services`     | `id`     | `RESTRICT` | `SET NULL` |
| `fk_stage2_orders_user_coupon_id` | `user_coupon_id` | `user_coupons` | `id`     | `RESTRICT` | `SET NULL` |
| `fk_stage2_orders_user_id`        | `user_id`        | `users`        | `id`     | `RESTRICT` | `RESTRICT` |

### 2.34 `password_reset_tokens`

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

### 2.35 `payments`

- 类型：`BASE TABLE`
- 引擎：`InnoDB`
- 估算行数：`21`
- 数据大小：`16 KB`
- 索引大小：`160 KB`
- 自增值：`389`
- 排序规则：`utf8mb4_unicode_ci`
- 表注释：第三方支付记录表，仅记录真实外部资金流入和退款状态，不记录余额/免费/手工开服

#### 字段

| 序号 | 字段           | 类型                  | 可空 | 默认值 | 键  | 额外           | 字符集  | 排序规则           | 注释                                          |
| ---: | -------------- | --------------------- | ---- | ------ | --- | -------------- | ------- | ------------------ | --------------------------------------------- |
|    1 | `id`           | `bigint(20) unsigned` | 否   | —      | PRI | auto_increment | —       | —                  | 支付记录自增主键                              |
|    2 | `payment_no`   | `varchar(32)`         | 否   | —      | UNI | —              | utf8mb4 | utf8mb4_unicode_ci | 内部支付单号                                  |
|    3 | `user_id`      | `bigint(20) unsigned` | 否   | —      | MUL | —              | —       | —                  | 支付用户ID                                    |
|    4 | `order_id`     | `bigint(20) unsigned` | 是   | `NULL` | MUL | —              | —       | —                  | 内部订单/开通投影ID，仅用于流程追踪           |
|    5 | `invoice_id`   | `bigint(20) unsigned` | 是   | `NULL` | MUL | —              | —       | —                  | 关联账单ID                                    |
|    6 | `plugin_id`    | `bigint(20) unsigned` | 是   | `NULL` | MUL | —              | —       | —                  | —                                             |
|    7 | `gateway_key`  | `varchar(120)`        | 是   | `NULL` | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —                                             |
|    8 | `trade_no`     | `varchar(100)`        | 是   | `NULL` | MUL | —              | utf8mb4 | utf8mb4_unicode_ci | 第三方交易号                                  |
|    9 | `amount`       | `decimal(12,2)`       | 否   | —      | —   | —              | —       | —                  | 第三方支付金额                                |
|   10 | `currency`     | `varchar(3)`          | 否   | `CNY`  | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —                                             |
|   11 | `status`       | `tinyint(4)`          | 否   | `0`    | MUL | —              | —       | —                  | 支付状态：0待支付 1成功 2失败 3已退款 4已取消 |
|   12 | `callback_raw` | `json`                | 是   | `NULL` | —   | —              | —       | —                  | 最近一次回调原始载荷 JSON                     |
|   13 | `paid_at`      | `timestamp`           | 是   | `NULL` | —   | —              | —       | —                  | 第三方确认支付时间                            |
|   14 | `deleted_at`   | `timestamp`           | 是   | `NULL` | —   | —              | —       | —                  | —                                             |
|   15 | `created_at`   | `timestamp`           | 是   | `NULL` | —   | —              | —       | —                  | 创建时间                                      |
|   16 | `updated_at`   | `timestamp`           | 是   | `NULL` | —   | —              | —       | —                  | 更新时间                                      |
|   17 | `remark`       | `varchar(255)`        | 是   | `NULL` | —   | —              | utf8mb4 | utf8mb4_unicode_ci | 支付备注                                      |
|   18 | `operator`     | `varchar(50)`         | 是   | `NULL` | —   | —              | utf8mb4 | utf8mb4_unicode_ci | 操作人快照                                    |
|   19 | `trace_id`     | `varchar(64)`         | 是   | `NULL` | MUL | —              | utf8mb4 | utf8mb4_unicode_ci | 链路追踪号                                    |

#### 索引

| 索引名                                   | 唯一 | 类型    | 字段                                       | 基数 | 注释 |
| ---------------------------------------- | ---- | ------- | ------------------------------------------ | ---: | ---- |
| `payments_invoice_gateway_status_id_idx` | 否   | `BTREE` | `invoice_id`, `status`, `id`               |   21 | —    |
| `payments_invoice_status_created_at_idx` | 否   | `BTREE` | `invoice_id`, `status`, `created_at`, `id` |   21 | —    |
| `payments_order_status_idx`              | 否   | `BTREE` | `order_id`, `status`                       |   17 | —    |
| `payments_payment_no_unique`             | 是   | `BTREE` | `payment_no`                               |   21 | —    |
| `payments_plugin_status_paid_idx`        | 否   | `BTREE` | `plugin_id`, `status`, `paid_at`           |   12 | —    |
| `payments_plugin_trade_unique`           | 是   | `BTREE` | `plugin_id`, `gateway_key`, `trade_no`     |   21 | —    |
| `payments_status_paid_at_idx`            | 否   | `BTREE` | `status`, `paid_at`                        |   12 | —    |
| `payments_trace_id_idx`                  | 否   | `BTREE` | `trace_id`                                 |   21 | —    |
| `payments_trade_no_index`                | 否   | `BTREE` | `trade_no`                                 |   21 | —    |
| `payments_user_status_created_idx`       | 否   | `BTREE` | `user_id`, `status`, `created_at`          |   21 | —    |
| `PRIMARY`                                | 是   | `BTREE` | `id`                                       |   21 | —    |

#### 外键约束

| 约束名                        | 字段         | 引用表                | 引用字段 | 更新规则   | 删除规则   |
| ----------------------------- | ------------ | --------------------- | -------- | ---------- | ---------- |
| `fk_payments_invoice_id`      | `invoice_id` | `invoices`            | `id`     | `RESTRICT` | `RESTRICT` |
| `fk_payments_user_id`         | `user_id`    | `users`               | `id`     | `RESTRICT` | `RESTRICT` |
| `fk_stage2_payments_order_id` | `order_id`   | `orders`              | `id`     | `RESTRICT` | `SET NULL` |
| `payments_plugin_fk`          | `plugin_id`  | `integration_plugins` | `id`     | `RESTRICT` | `SET NULL` |

### 2.36 `payment_callbacks`

- 类型：`BASE TABLE`
- 引擎：`InnoDB`
- 估算行数：`3`
- 数据大小：`16 KB`
- 索引大小：`96 KB`
- 自增值：`491`
- 排序规则：`utf8mb4_unicode_ci`
- 表注释：支付回调审计表，保存第三方通知、查询、退款等回调验签结果

#### 字段

| 序号 | 字段               | 类型                  | 可空 | 默认值 | 键  | 额外           | 字符集  | 排序规则           | 注释                             |
| ---: | ------------------ | --------------------- | ---- | ------ | --- | -------------- | ------- | ------------------ | -------------------------------- |
|    1 | `id`               | `bigint(20) unsigned` | 否   | —      | PRI | auto_increment | —       | —                  | 支付回调自增主键                 |
|    2 | `payment_id`       | `bigint(20) unsigned` | 否   | —      | MUL | —              | —       | —                  | 关联支付记录ID                   |
|    3 | `plugin_id`        | `bigint(20) unsigned` | 是   | `NULL` | MUL | —              | —       | —                  | —                                |
|    4 | `gateway_key`      | `varchar(120)`        | 是   | `NULL` | MUL | —              | utf8mb4 | utf8mb4_unicode_ci | —                                |
|    5 | `callback_type`    | `varchar(20)`         | 否   | —      | —   | —              | utf8mb4 | utf8mb4_unicode_ci | 回调类型：notify/query/refund 等 |
|    6 | `gateway_trade_no` | `varchar(100)`        | 是   | `NULL` | MUL | —              | utf8mb4 | utf8mb4_unicode_ci | 第三方交易号                     |
|    7 | `payload_json`     | `json`                | 是   | `NULL` | —   | —              | —       | —                  | 回调载荷 JSON                    |
|    8 | `is_verified`      | `tinyint(4)`          | 否   | `0`    | MUL | —              | —       | —                  | 验签结果：0未通过/未验签 1已通过 |
|    9 | `received_at`      | `timestamp`           | 是   | `NULL` | —   | —              | —       | —                  | 收到回调时间                     |
|   10 | `remark`           | `varchar(255)`        | 是   | `NULL` | —   | —              | utf8mb4 | utf8mb4_unicode_ci | 回调备注或处理说明               |
|   11 | `operator`         | `varchar(50)`         | 是   | `NULL` | —   | —              | utf8mb4 | utf8mb4_unicode_ci | 操作人快照                       |
|   12 | `trace_id`         | `varchar(64)`         | 是   | `NULL` | MUL | —              | utf8mb4 | utf8mb4_unicode_ci | 链路追踪号                       |
|   13 | `created_at`       | `timestamp`           | 是   | `NULL` | —   | —              | —       | —                  | 创建时间                         |
|   14 | `updated_at`       | `timestamp`           | 是   | `NULL` | —   | —              | —       | —                  | 更新时间                         |

#### 索引

| 索引名                                    | 唯一 | 类型    | 字段                          | 基数 | 注释 |
| ----------------------------------------- | ---- | ------- | ----------------------------- | ---: | ---- |
| `payment_callbacks_gateway_key_idx`       | 否   | `BTREE` | `gateway_key`, `received_at`  |    3 | —    |
| `payment_callbacks_gateway_trade_no_idx`  | 否   | `BTREE` | `gateway_trade_no`            |    3 | —    |
| `payment_callbacks_payment_type_unique`   | 是   | `BTREE` | `payment_id`, `callback_type` |    3 | —    |
| `payment_callbacks_plugin_received_idx`   | 否   | `BTREE` | `plugin_id`, `received_at`    |    3 | —    |
| `payment_callbacks_trace_id_idx`          | 否   | `BTREE` | `trace_id`                    |    3 | —    |
| `payment_callbacks_verified_received_idx` | 否   | `BTREE` | `is_verified`, `received_at`  |    3 | —    |
| `PRIMARY`                                 | 是   | `BTREE` | `id`                          |    3 | —    |

#### 外键约束

| 约束名                            | 字段         | 引用表                | 引用字段 | 更新规则   | 删除规则   |
| --------------------------------- | ------------ | --------------------- | -------- | ---------- | ---------- |
| `fk_payment_callbacks_payment_id` | `payment_id` | `payments`            | `id`     | `RESTRICT` | `CASCADE`  |
| `payment_callbacks_plugin_fk`     | `plugin_id`  | `integration_plugins` | `id`     | `RESTRICT` | `SET NULL` |

### 2.37 `personal_access_tokens`

- 类型：`BASE TABLE`
- 引擎：`InnoDB`
- 估算行数：`1`
- 数据大小：`16 KB`
- 索引大小：`48 KB`
- 自增值：`176`
- 排序规则：`utf8mb4_unicode_ci`
- 表注释：—

#### 字段

| 序号 | 字段             | 类型                  | 可空 | 默认值 | 键  | 额外           | 字符集  | 排序规则           | 注释 |
| ---: | ---------------- | --------------------- | ---- | ------ | --- | -------------- | ------- | ------------------ | ---- |
|    1 | `id`             | `bigint(20) unsigned` | 否   | —      | PRI | auto_increment | —       | —                  | —    |
|    2 | `tokenable_type` | `varchar(255)`        | 否   | —      | MUL | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|    3 | `tokenable_id`   | `bigint(20) unsigned` | 否   | —      | —   | —              | —       | —                  | —    |
|    4 | `name`           | `text`                | 否   | —      | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|    5 | `token`          | `varchar(64)`         | 否   | —      | UNI | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|    6 | `abilities`      | `text`                | 是   | `NULL` | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|    7 | `last_used_at`   | `timestamp`           | 是   | `NULL` | —   | —              | —       | —                  | —    |
|    8 | `expires_at`     | `timestamp`           | 是   | `NULL` | MUL | —              | —       | —                  | —    |
|    9 | `created_at`     | `timestamp`           | 是   | `NULL` | —   | —              | —       | —                  | —    |
|   10 | `updated_at`     | `timestamp`           | 是   | `NULL` | —   | —              | —       | —                  | —    |

#### 索引

| 索引名                                                     | 唯一 | 类型    | 字段                             | 基数 | 注释 |
| ---------------------------------------------------------- | ---- | ------- | -------------------------------- | ---: | ---- |
| `personal_access_tokens_expires_at_index`                  | 否   | `BTREE` | `expires_at`                     |    1 | —    |
| `personal_access_tokens_tokenable_type_tokenable_id_index` | 否   | `BTREE` | `tokenable_type`, `tokenable_id` |    1 | —    |
| `personal_access_tokens_token_unique`                      | 是   | `BTREE` | `token`                          |    1 | —    |
| `PRIMARY`                                                  | 是   | `BTREE` | `id`                             |    1 | —    |

#### 外键约束

无数据库级外键约束。

### 2.38 `products`

- 类型：`BASE TABLE`
- 引擎：`InnoDB`
- 估算行数：`210`
- 数据大小：`80 KB`
- 索引大小：`48 KB`
- 自增值：`623`
- 排序规则：`utf8mb4_unicode_ci`
- 表注释：商品表，记录可售卖产品的分类、定价、库存、上游绑定和开通策略

#### 字段

| 序号 | 字段                        | 类型                  | 可空 | 默认值    | 键  | 额外           | 字符集  | 排序规则           | 注释                                             |
| ---: | --------------------------- | --------------------- | ---- | --------- | --- | -------------- | ------- | ------------------ | ------------------------------------------------ |
|    1 | `id`                        | `bigint(20) unsigned` | 否   | —         | PRI | auto_increment | —       | —                  | 商品自增主键                                     |
|    2 | `product_group_id`          | `bigint(20) unsigned` | 是   | `NULL`    | MUL | —              | —       | —                  | 当前所属商品分组ID                               |
|    3 | `service_type_code`         | `varchar(50)`         | 是   | `NULL`    | —   | —              | utf8mb4 | utf8mb4_unicode_ci | 服务类型代码，用于前后端能力分流                 |
|    4 | `product_type`              | `varchar(30)`         | 否   | —         | MUL | —              | utf8mb4 | utf8mb4_unicode_ci | 商品类型：vps/dedicated/hosting/domain/other     |
|    5 | `console_template`          | `varchar(32)`         | 否   | `compute` | —   | —              | utf8mb4 | utf8mb4_unicode_ci | 用户控制台模板：compute 或 port_mapping          |
|    6 | `custom_display_name`       | `varchar(190)`        | 是   | `NULL`    | —   | —              | utf8mb4 | utf8mb4_unicode_ci | 自定义展示名称                                   |
|    7 | `remark`                    | `varchar(255)`        | 是   | `NULL`    | —   | —              | utf8mb4 | utf8mb4_unicode_ci | 商品备注                                         |
|    8 | `pricing`                   | `json`                | 否   | —         | —   | —              | —       | —                  | 周期价格 JSON，如 monthly/quarterly/annually     |
|    9 | `cpu_model`                 | `varchar(120)`        | 是   | `NULL`    | —   | —              | utf8mb4 | utf8mb4_unicode_ci | CPU 型号（分组批量设置，展示优先级高于目录绑定） |
|   10 | `cpu_turbo`                 | `varchar(40)`         | 是   | `NULL`    | —   | —              | utf8mb4 | utf8mb4_unicode_ci | CPU 睿频（如 3.8GHz）                            |
|   11 | `setup_fee`                 | `decimal(12,2)`       | 否   | `0.00`    | —   | —              | —       | —                  | 初装费                                           |
|   12 | `config_options`            | `json`                | 是   | `NULL`    | —   | —              | —       | —                  | 可选配置项 JSON                                  |
|   13 | `purchase_requires`         | `json`                | 是   | `NULL`    | —   | —              | —       | —                  | 购买限制 JSON，如实名认证、手机号要求            |
|   14 | `stock`                     | `int(11)`             | 否   | `-1`      | —   | —              | —       | —                  | 库存数量，-1 表示不限                            |
|   15 | `stock_synced_at`           | `timestamp`           | 是   | `NULL`    | MUL | —              | —       | —                  | 上游库存最近同步时间，为空表示从未同步           |
|   16 | `status`                    | `tinyint(4)`          | 否   | `1`       | —   | —              | —       | —                  | 商品状态：0下架 1上架                            |
|   17 | `sort_order`                | `int(11)`             | 否   | `0`       | —   | —              | —       | —                  | 排序值，越小越靠前                               |
|   18 | `auto_setup`                | `tinyint(4)`          | 否   | `0`       | —   | —              | —       | —                  | 是否自动开通：0手动 1自动                        |
|   19 | `created_at`                | `timestamp`           | 是   | `NULL`    | —   | —              | —       | —                  | 创建时间                                         |
|   20 | `updated_at`                | `timestamp`           | 是   | `NULL`    | —   | —              | —       | —                  | 更新时间                                         |
|   21 | `deleted_at`                | `timestamp`           | 是   | `NULL`    | —   | —              | —       | —                  | 软删除时间                                       |
|   22 | `product_discount_group_id` | `bigint(20) unsigned` | 是   | `NULL`    | —   | —              | —       | —                  | —                                                |

#### 索引

| 索引名                              | 唯一 | 类型    | 字段                                             | 基数 | 注释 |
| ----------------------------------- | ---- | ------- | ------------------------------------------------ | ---: | ---- |
| `PRIMARY`                           | 是   | `BTREE` | `id`                                             |  208 | —    |
| `products_group_status_sort_id_idx` | 否   | `BTREE` | `product_group_id`, `status`, `sort_order`, `id` |  208 | —    |
| `products_stock_synced_at_index`    | 否   | `BTREE` | `stock_synced_at`                                |    1 | —    |
| `products_type_status_index`        | 否   | `BTREE` | `product_type`, `status`                         |   11 | —    |

#### 外键约束

| 约束名                      | 字段               | 引用表                 | 引用字段 | 更新规则   | 删除规则   |
| --------------------------- | ------------------ | ---------------------- | -------- | ---------- | ---------- |
| `products_product_group_fk` | `product_group_id` | `third_product_groups` | `id`     | `RESTRICT` | `RESTRICT` |

### 2.39 `product_discount_groups`

- 类型：`BASE TABLE`
- 引擎：`InnoDB`
- 估算行数：`1`
- 数据大小：`16 KB`
- 索引大小：`16 KB`
- 自增值：`4`
- 排序规则：`utf8mb4_unicode_ci`
- 表注释：—

#### 字段

| 序号 | 字段                | 类型                  | 可空 | 默认值   | 键  | 额外           | 字符集  | 排序规则           | 注释 |
| ---: | ------------------- | --------------------- | ---- | -------- | --- | -------------- | ------- | ------------------ | ---- |
|    1 | `id`                | `bigint(20) unsigned` | 否   | —        | PRI | auto_increment | —       | —                  | —    |
|    2 | `name`              | `varchar(50)`         | 否   | —        | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|    3 | `code`              | `varchar(30)`         | 否   | —        | UNI | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|    4 | `min_discount_rate` | `decimal(5,2)`        | 否   | `100.00` | —   | —              | —       | —                  | —    |
|    5 | `cost_rate`         | `decimal(5,2)`        | 否   | `0.00`   | —   | —              | —       | —                  | —    |
|    6 | `status`            | `tinyint(4)`          | 否   | `1`      | —   | —              | —       | —                  | —    |
|    7 | `sort_order`        | `int(11)`             | 否   | `0`      | —   | —              | —       | —                  | —    |
|    8 | `remark`            | `varchar(255)`        | 是   | `NULL`   | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|    9 | `created_at`        | `timestamp`           | 是   | `NULL`   | —   | —              | —       | —                  | —    |
|   10 | `updated_at`        | `timestamp`           | 是   | `NULL`   | —   | —              | —       | —                  | —    |

#### 索引

| 索引名                                | 唯一 | 类型    | 字段   | 基数 | 注释 |
| ------------------------------------- | ---- | ------- | ------ | ---: | ---- |
| `PRIMARY`                             | 是   | `BTREE` | `id`   |    1 | —    |
| `product_discount_groups_code_unique` | 是   | `BTREE` | `code` |    1 | —    |

#### 外键约束

无数据库级外键约束。

### 2.40 `product_upstream_bindings`

- 类型：`BASE TABLE`
- 引擎：`InnoDB`
- 估算行数：`3`
- 数据大小：`16 KB`
- 索引大小：`96 KB`
- 自增值：`284`
- 排序规则：`utf8mb4_unicode_ci`
- 表注释：—

#### 字段

| 序号 | 字段                             | 类型                  | 可空 | 默认值 | 键  | 额外           | 字符集  | 排序规则           | 注释 |
| ---: | -------------------------------- | --------------------- | ---- | ------ | --- | -------------- | ------- | ------------------ | ---- |
|    1 | `id`                             | `bigint(20) unsigned` | 否   | —      | PRI | auto_increment | —       | —                  | —    |
|    2 | `product_id`                     | `bigint(20) unsigned` | 否   | —      | MUL | —              | —       | —                  | —    |
|    3 | `supplier_plugin_binding_id`     | `bigint(20) unsigned` | 否   | —      | MUL | —              | —       | —                  | —    |
|    4 | `plugin_id`                      | `bigint(20) unsigned` | 否   | —      | MUL | —              | —       | —                  | —    |
|    5 | `provider_key`                   | `varchar(120)`        | 否   | —      | MUL | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|    6 | `upstream_product_id`            | `varchar(120)`        | 否   | —      | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|    7 | `upstream_product_snapshot_json` | `json`                | 是   | `NULL` | —   | —              | —       | —                  | —    |
|    8 | `option_schema_json`             | `json`                | 是   | `NULL` | —   | —              | —       | —                  | —    |
|    9 | `provision_policy_json`          | `json`                | 是   | `NULL` | —   | —              | —       | —                  | —    |
|   10 | `auto_setup`                     | `tinyint(1)`          | 否   | `0`    | —   | —              | —       | —                  | —    |
|   11 | `status`                         | `tinyint(3) unsigned` | 否   | `1`    | —   | —              | —       | —                  | —    |
|   12 | `last_synced_at`                 | `timestamp`           | 是   | `NULL` | —   | —              | —       | —                  | —    |
|   13 | `last_sync_error`                | `varchar(500)`        | 是   | `NULL` | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|   14 | `backfill_batch_id`              | `varchar(64)`         | 是   | `NULL` | MUL | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|   15 | `created_at`                     | `timestamp`           | 是   | `NULL` | —   | —              | —       | —                  | —    |
|   16 | `updated_at`                     | `timestamp`           | 是   | `NULL` | —   | —              | —       | —                  | —    |

#### 索引

| 索引名                                                         | 唯一 | 类型    | 字段                                                              | 基数 | 注释 |
| -------------------------------------------------------------- | ---- | ------- | ----------------------------------------------------------------- | ---: | ---- |
| `PRIMARY`                                                      | 是   | `BTREE` | `id`                                                              |    3 | —    |
| `product_upstream_backfill_batch_idx`                          | 否   | `BTREE` | `backfill_batch_id`                                               |    1 | —    |
| `product_upstream_bindings_supplier_plugin_binding_id_foreign` | 否   | `BTREE` | `supplier_plugin_binding_id`                                      |    3 | —    |
| `product_upstream_plugin_status_idx`                           | 否   | `BTREE` | `plugin_id`, `status`                                             |    1 | —    |
| `product_upstream_product_status_idx`                          | 否   | `BTREE` | `product_id`, `status`                                            |    3 | —    |
| `product_upstream_provider_status_idx`                         | 否   | `BTREE` | `provider_key`, `status`                                          |    1 | —    |
| `product_upstream_unique`                                      | 是   | `BTREE` | `product_id`, `supplier_plugin_binding_id`, `upstream_product_id` |    3 | —    |

#### 外键约束

| 约束名                                                         | 字段                         | 引用表                     | 引用字段 | 更新规则   | 删除规则   |
| -------------------------------------------------------------- | ---------------------------- | -------------------------- | -------- | ---------- | ---------- |
| `product_upstream_bindings_plugin_id_foreign`                  | `plugin_id`                  | `integration_plugins`      | `id`     | `RESTRICT` | `RESTRICT` |
| `product_upstream_bindings_product_id_foreign`                 | `product_id`                 | `products`                 | `id`     | `RESTRICT` | `RESTRICT` |
| `product_upstream_bindings_supplier_plugin_binding_id_foreign` | `supplier_plugin_binding_id` | `supplier_plugin_bindings` | `id`     | `RESTRICT` | `RESTRICT` |

### 2.41 `recharge_records`

- 类型：`BASE TABLE`
- 引擎：`InnoDB`
- 估算行数：`0`
- 数据大小：`16 KB`
- 索引大小：`144 KB`
- 自增值：`2`
- 排序规则：`utf8mb4_unicode_ci`
- 表注释：—

#### 字段

| 序号 | 字段                        | 类型                  | 可空 | 默认值 | 键  | 额外           | 字符集  | 排序规则           | 注释                                                                         |
| ---: | --------------------------- | --------------------- | ---- | ------ | --- | -------------- | ------- | ------------------ | ---------------------------------------------------------------------------- |
|    1 | `id`                        | `bigint(20) unsigned` | 否   | —      | PRI | auto_increment | —       | —                  | —                                                                            |
|    2 | `record_no`                 | `varchar(32)`         | 否   | —      | UNI | —              | utf8mb4 | utf8mb4_unicode_ci | —                                                                            |
|    3 | `user_id`                   | `bigint(20) unsigned` | 否   | —      | MUL | —              | —       | —                  | —                                                                            |
|    4 | `order_id`                  | `bigint(20) unsigned` | 是   | `NULL` | MUL | —              | —       | —                  | —                                                                            |
|    5 | `invoice_id`                | `bigint(20) unsigned` | 是   | `NULL` | MUL | —              | —       | —                  | —                                                                            |
|    6 | `payment_id`                | `bigint(20) unsigned` | 是   | `NULL` | UNI | —              | —       | —                  | —                                                                            |
|    7 | `account_transaction_id`    | `bigint(20) unsigned` | 是   | `NULL` | UNI | —              | —       | —                  | —                                                                            |
|    8 | `refund_id`                 | `bigint(20) unsigned` | 是   | `NULL` | MUL | —              | —       | —                  | —                                                                            |
|    9 | `origin_recharge_record_id` | `bigint(20) unsigned` | 是   | `NULL` | MUL | —              | —       | —                  | —                                                                            |
|   10 | `scene`                     | `varchar(30)`         | 否   | —      | —   | —              | utf8mb4 | utf8mb4_unicode_ci | 业务场景：recharge=支付充值 admin_recharge=管理员充值 refund=退款 等         |
|   11 | `direction`                 | `varchar(8)`          | 否   | —      | —   | —              | utf8mb4 | utf8mb4_unicode_ci | 资金方向：in=入账 out=出账                                                   |
|   12 | `amount`                    | `decimal(12,2)`       | 否   | —      | —   | —              | —       | —                  | —                                                                            |
|   13 | `currency`                  | `varchar(3)`          | 否   | `CNY`  | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —                                                                            |
|   14 | `entry_type`                | `varchar(30)`         | 否   | —      | —   | —              | utf8mb4 | utf8mb4_unicode_ci | 入账类型：third_party_payment/manual_recharge/account_recharge/refund_offset |
|   15 | `remark`                    | `varchar(255)`        | 是   | `NULL` | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —                                                                            |
|   16 | `operator_type`             | `varchar(30)`         | 是   | `NULL` | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —                                                                            |
|   17 | `operator_id`               | `bigint(20) unsigned` | 是   | `NULL` | —   | —              | —       | —                  | —                                                                            |
|   18 | `operator_name`             | `varchar(50)`         | 是   | `NULL` | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —                                                                            |
|   19 | `trace_id`                  | `varchar(64)`         | 是   | `NULL` | MUL | —              | utf8mb4 | utf8mb4_unicode_ci | —                                                                            |
|   20 | `created_at`                | `timestamp`           | 是   | `NULL` | —   | —              | —       | —                  | —                                                                            |
|   21 | `updated_at`                | `timestamp`           | 是   | `NULL` | —   | —              | —       | —                  | —                                                                            |

#### 索引

| 索引名                                                | 唯一 | 类型    | 字段                              | 基数 | 注释 |
| ----------------------------------------------------- | ---- | ------- | --------------------------------- | ---: | ---- |
| `PRIMARY`                                             | 是   | `BTREE` | `id`                              |    0 | —    |
| `recharge_records_account_transaction_id_unique`      | 是   | `BTREE` | `account_transaction_id`          |    0 | —    |
| `recharge_records_invoice_id_id_index`                | 否   | `BTREE` | `invoice_id`, `id`                |    0 | —    |
| `recharge_records_order_id_id_index`                  | 否   | `BTREE` | `order_id`, `id`                  |    0 | —    |
| `recharge_records_origin_recharge_record_id_id_index` | 否   | `BTREE` | `origin_recharge_record_id`, `id` |    0 | —    |
| `recharge_records_payment_id_unique`                  | 是   | `BTREE` | `payment_id`                      |    0 | —    |
| `recharge_records_record_no_unique`                   | 是   | `BTREE` | `record_no`                       |    0 | —    |
| `recharge_records_refund_id_foreign`                  | 否   | `BTREE` | `refund_id`                       |    0 | —    |
| `recharge_records_trace_id_index`                     | 否   | `BTREE` | `trace_id`                        |    0 | —    |
| `recharge_records_user_id_created_at_index`           | 否   | `BTREE` | `user_id`, `created_at`           |    0 | —    |

#### 外键约束

| 约束名                                               | 字段                        | 引用表                 | 引用字段 | 更新规则   | 删除规则   |
| ---------------------------------------------------- | --------------------------- | ---------------------- | -------- | ---------- | ---------- |
| `recharge_records_account_transaction_id_foreign`    | `account_transaction_id`    | `account_transactions` | `id`     | `RESTRICT` | `SET NULL` |
| `recharge_records_invoice_id_foreign`                | `invoice_id`                | `invoices`             | `id`     | `RESTRICT` | `SET NULL` |
| `recharge_records_order_id_foreign`                  | `order_id`                  | `orders`               | `id`     | `RESTRICT` | `SET NULL` |
| `recharge_records_origin_recharge_record_id_foreign` | `origin_recharge_record_id` | `recharge_records`     | `id`     | `RESTRICT` | `SET NULL` |
| `recharge_records_payment_id_foreign`                | `payment_id`                | `payments`             | `id`     | `RESTRICT` | `SET NULL` |
| `recharge_records_refund_id_foreign`                 | `refund_id`                 | `refunds`              | `id`     | `RESTRICT` | `SET NULL` |
| `recharge_records_user_id_foreign`                   | `user_id`                   | `users`                | `id`     | `RESTRICT` | `RESTRICT` |

### 2.42 `referral_account_logs`

- 类型：`BASE TABLE`
- 引擎：`InnoDB`
- 估算行数：`0`
- 数据大小：`16 KB`
- 索引大小：`64 KB`
- 自增值：`7`
- 排序规则：`utf8mb4_unicode_ci`
- 表注释：—

#### 字段

| 序号 | 字段                         | 类型                  | 可空 | 默认值              | 键  | 额外           | 字符集  | 排序规则           | 注释                                                                                                 |
| ---: | ---------------------------- | --------------------- | ---- | ------------------- | --- | -------------- | ------- | ------------------ | ---------------------------------------------------------------------------------------------------- |
|    1 | `id`                         | `bigint(20) unsigned` | 否   | —                   | PRI | auto_increment | —       | —                  | —                                                                                                    |
|    2 | `user_id`                    | `bigint(20) unsigned` | 否   | —                   | MUL | —              | —       | —                  | —                                                                                                    |
|    3 | `event_type`                 | `varchar(30)`         | 否   | —                   | —   | —              | utf8mb4 | utf8mb4_unicode_ci | reward_frozen&#124;reward_released&#124;withdraw_apply&#124;withdraw_approved&#124;withdraw_rejected |
|    4 | `change_amount`              | `decimal(12,2)`       | 否   | —                   | —   | —              | —       | —                  | 正=增加 负=减少                                                                                      |
|    5 | `frozen_balance`             | `decimal(12,2)`       | 否   | `0.00`              | —   | —              | —       | —                  | —                                                                                                    |
|    6 | `available_balance`          | `decimal(12,2)`       | 否   | `0.00`              | —   | —              | —       | —                  | —                                                                                                    |
|    7 | `pending_withdrawal_balance` | `decimal(12,2)`       | 否   | `0.00`              | —   | —              | —       | —                  | —                                                                                                    |
|    8 | `withdrawn_balance`          | `decimal(12,2)`       | 否   | `0.00`              | —   | —              | —       | —                  | —                                                                                                    |
|    9 | `remark`                     | `varchar(255)`        | 否   | 空字符串            | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —                                                                                                    |
|   10 | `reference_id`               | `bigint(20) unsigned` | 是   | `NULL`              | —   | —              | —       | —                  | —                                                                                                    |
|   11 | `reference_type`             | `varchar(30)`         | 是   | `NULL`              | MUL | —              | utf8mb4 | utf8mb4_unicode_ci | —                                                                                                    |
|   12 | `operator`                   | `varchar(50)`         | 是   | `NULL`              | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —                                                                                                    |
|   13 | `trace_id`                   | `varchar(64)`         | 是   | `NULL`              | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —                                                                                                    |
|   14 | `created_at`                 | `timestamp`           | 否   | `CURRENT_TIMESTAMP` | MUL | —              | —       | —                  | —                                                                                                    |

#### 索引

| 索引名                                   | 唯一 | 类型    | 字段                             | 基数 | 注释 |
| ---------------------------------------- | ---- | ------- | -------------------------------- | ---: | ---- |
| `idx_referral_account_related`           | 否   | `BTREE` | `reference_type`, `reference_id` |    0 | —    |
| `idx_referral_account_user_created_idx`  | 否   | `BTREE` | `user_id`, `created_at`, `id`    |    0 | —    |
| `idx_referral_account_user_type`         | 否   | `BTREE` | `user_id`, `event_type`          |    0 | —    |
| `PRIMARY`                                | 是   | `BTREE` | `id`                             |    0 | —    |
| `referral_account_logs_created_at_index` | 否   | `BTREE` | `created_at`                     |    0 | —    |

#### 外键约束

| 约束名                                    | 字段      | 引用表  | 引用字段 | 更新规则   | 删除规则   |
| ----------------------------------------- | --------- | ------- | -------- | ---------- | ---------- |
| `fk_stage2_referral_account_logs_user_id` | `user_id` | `users` | `id`     | `RESTRICT` | `RESTRICT` |

### 2.43 `referral_rewards`

- 类型：`BASE TABLE`
- 引擎：`InnoDB`
- 估算行数：`0`
- 数据大小：`16 KB`
- 索引大小：`96 KB`
- 自增值：`13`
- 排序规则：`utf8mb4_unicode_ci`
- 表注释：—

#### 字段

| 序号 | 字段               | 类型                  | 可空 | 默认值 | 键  | 额外           | 字符集  | 排序规则           | 注释                       |
| ---: | ------------------ | --------------------- | ---- | ------ | --- | -------------- | ------- | ------------------ | -------------------------- |
|    1 | `id`               | `bigint(20) unsigned` | 否   | —      | PRI | auto_increment | —       | —                  | —                          |
|    2 | `referrer_user_id` | `bigint(20) unsigned` | 否   | —      | MUL | —              | —       | —                  | —                          |
|    3 | `referred_user_id` | `bigint(20) unsigned` | 否   | —      | MUL | —              | —       | —                  | —                          |
|    4 | `order_id`         | `bigint(20) unsigned` | 是   | `NULL` | UNI | —              | —       | —                  | —                          |
|    5 | `invoice_id`       | `bigint(20) unsigned` | 是   | `NULL` | MUL | —              | —       | —                  | —                          |
|    6 | `product_id`       | `bigint(20) unsigned` | 是   | `NULL` | MUL | —              | —       | —                  | —                          |
|    7 | `order_amount`     | `decimal(12,2)`       | 否   | `0.00` | —   | —              | —       | —                  | —                          |
|    8 | `reward_rate`      | `decimal(5,2)`        | 否   | `0.00` | —   | —              | —       | —                  | —                          |
|    9 | `reward_amount`    | `decimal(12,2)`       | 否   | `0.00` | —   | —              | —       | —                  | —                          |
|   10 | `available_at`     | `timestamp`           | 是   | `NULL` | —   | —              | —       | —                  | —                          |
|   11 | `released_at`      | `timestamp`           | 是   | `NULL` | —   | —              | —       | —                  | —                          |
|   12 | `status`           | `tinyint(4)`          | 否   | `0`    | —   | —              | —       | —                  | 0=冻结中 1=已发放 2=已回退 |
|   13 | `operator`         | `varchar(50)`         | 是   | `NULL` | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —                          |
|   14 | `remark`           | `varchar(255)`        | 是   | `NULL` | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —                          |
|   15 | `trace_id`         | `varchar(64)`         | 是   | `NULL` | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —                          |
|   16 | `rewarded_at`      | `timestamp`           | 是   | `NULL` | MUL | —              | —       | —                  | —                          |
|   17 | `created_at`       | `timestamp`           | 是   | `NULL` | —   | —              | —       | —                  | —                          |
|   18 | `updated_at`       | `timestamp`           | 是   | `NULL` | —   | —              | —       | —                  | —                          |

#### 索引

| 索引名                                | 唯一 | 类型    | 字段                         | 基数 | 注释 |
| ------------------------------------- | ---- | ------- | ---------------------------- | ---: | ---- |
| `idx_referral_reward_referred_status` | 否   | `BTREE` | `referred_user_id`, `status` |    0 | —    |
| `idx_referral_reward_referrer_status` | 否   | `BTREE` | `referrer_user_id`, `status` |    0 | —    |
| `PRIMARY`                             | 是   | `BTREE` | `id`                         |    0 | —    |
| `referral_rewards_invoice_id_idx`     | 否   | `BTREE` | `invoice_id`                 |    0 | —    |
| `referral_rewards_order_id_unique`    | 是   | `BTREE` | `order_id`                   |    0 | —    |
| `referral_rewards_product_id_index`   | 否   | `BTREE` | `product_id`                 |    0 | —    |
| `referral_rewards_rewarded_at_index`  | 否   | `BTREE` | `rewarded_at`                |    0 | —    |

#### 外键约束

| 约束名                                        | 字段               | 引用表     | 引用字段 | 更新规则   | 删除规则   |
| --------------------------------------------- | ------------------ | ---------- | -------- | ---------- | ---------- |
| `fk_stage2_referral_rewards_invoice_id`       | `invoice_id`       | `invoices` | `id`     | `RESTRICT` | `SET NULL` |
| `fk_stage2_referral_rewards_order_id`         | `order_id`         | `orders`   | `id`     | `RESTRICT` | `RESTRICT` |
| `fk_stage2_referral_rewards_product_id`       | `product_id`       | `products` | `id`     | `RESTRICT` | `SET NULL` |
| `fk_stage2_referral_rewards_referred_user_id` | `referred_user_id` | `users`    | `id`     | `RESTRICT` | `RESTRICT` |
| `fk_stage2_referral_rewards_referrer_user_id` | `referrer_user_id` | `users`    | `id`     | `RESTRICT` | `RESTRICT` |

### 2.44 `referral_withdrawals`

- 类型：`BASE TABLE`
- 引擎：`InnoDB`
- 估算行数：`3`
- 数据大小：`16 KB`
- 索引大小：`48 KB`
- 自增值：`9`
- 排序规则：`utf8mb4_unicode_ci`
- 表注释：—

#### 字段

| 序号 | 字段           | 类型                  | 可空 | 默认值   | 键  | 额外           | 字符集  | 排序规则           | 注释                       |
| ---: | -------------- | --------------------- | ---- | -------- | --- | -------------- | ------- | ------------------ | -------------------------- |
|    1 | `id`           | `bigint(20) unsigned` | 否   | —        | PRI | auto_increment | —       | —                  | —                          |
|    2 | `user_id`      | `bigint(20) unsigned` | 否   | —        | MUL | —              | —       | —                  | —                          |
|    3 | `amount`       | `decimal(12,2)`       | 否   | `0.00`   | —   | —              | —       | —                  | —                          |
|    4 | `method`       | `varchar(20)`         | 否   | `alipay` | —   | —              | utf8mb4 | utf8mb4_unicode_ci | balance&#124;alipay        |
|    5 | `account_name` | `varchar(80)`         | 否   | 空字符串 | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —                          |
|    6 | `account_no`   | `varchar(120)`        | 否   | 空字符串 | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —                          |
|    7 | `status`       | `tinyint(4)`          | 否   | `0`      | MUL | —              | —       | —                  | 0=待处理 1=已通过 2=已拒绝 |
|    8 | `payment_no`   | `varchar(120)`        | 是   | `NULL`   | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —                          |
|    9 | `remark`       | `varchar(255)`        | 是   | `NULL`   | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —                          |
|   10 | `operator`     | `varchar(50)`         | 是   | `NULL`   | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —                          |
|   11 | `paid_at`      | `timestamp`           | 是   | `NULL`   | —   | —              | —       | —                  | —                          |
|   12 | `trace_id`     | `varchar(64)`         | 否   | —        | UNI | —              | utf8mb4 | utf8mb4_unicode_ci | —                          |
|   13 | `processed_at` | `timestamp`           | 是   | `NULL`   | —   | —              | —       | —                  | —                          |
|   14 | `created_at`   | `timestamp`           | 是   | `NULL`   | —   | —              | —       | —                  | —                          |
|   15 | `updated_at`   | `timestamp`           | 是   | `NULL`   | —   | —              | —       | —                  | —                          |

#### 索引

| 索引名                                 | 唯一 | 类型    | 字段                   | 基数 | 注释 |
| -------------------------------------- | ---- | ------- | ---------------------- | ---: | ---- |
| `idx_referral_withdraw_status_created` | 否   | `BTREE` | `status`, `created_at` |    3 | —    |
| `idx_referral_withdraw_user_status`    | 否   | `BTREE` | `user_id`, `status`    |    3 | —    |
| `PRIMARY`                              | 是   | `BTREE` | `id`                   |    3 | —    |
| `referral_withdrawals_trace_id_unique` | 是   | `BTREE` | `trace_id`             |    3 | —    |

#### 外键约束

| 约束名                                   | 字段      | 引用表  | 引用字段 | 更新规则   | 删除规则   |
| ---------------------------------------- | --------- | ------- | -------- | ---------- | ---------- |
| `fk_stage2_referral_withdrawals_user_id` | `user_id` | `users` | `id`     | `RESTRICT` | `RESTRICT` |

### 2.45 `refunds`

- 类型：`BASE TABLE`
- 引擎：`InnoDB`
- 估算行数：`0`
- 数据大小：`16 KB`
- 索引大小：`96 KB`
- 自增值：`1`
- 排序规则：`utf8mb4_unicode_ci`
- 表注释：—

#### 字段

| 序号 | 字段                | 类型                  | 可空 | 默认值    | 键  | 额外           | 字符集  | 排序规则           | 注释               |
| ---: | ------------------- | --------------------- | ---- | --------- | --- | -------------- | ------- | ------------------ | ------------------ |
|    1 | `id`                | `bigint(20) unsigned` | 否   | —         | PRI | auto_increment | —       | —                  | —                  |
|    2 | `refund_no`         | `varchar(32)`         | 否   | —         | UNI | —              | utf8mb4 | utf8mb4_unicode_ci | —                  |
|    3 | `user_id`           | `bigint(20) unsigned` | 否   | —         | MUL | —              | —       | —                  | —                  |
|    4 | `invoice_id`        | `bigint(20) unsigned` | 否   | —         | MUL | —              | —       | —                  | —                  |
|    5 | `refund_invoice_id` | `bigint(20) unsigned` | 是   | `NULL`    | MUL | —              | —       | —                  | —                  |
|    6 | `payment_id`        | `bigint(20) unsigned` | 是   | `NULL`    | MUL | —              | —       | —                  | —                  |
|    7 | `amount`            | `decimal(12,2)`       | 否   | —         | —   | —              | —       | —                  | —                  |
|    8 | `status`            | `tinyint(3) unsigned` | 否   | `1`       | —   | —              | —       | —                  | 退款状态：1=已完成 |
|    9 | `refund_method`     | `varchar(32)`         | 否   | `balance` | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —                  |
|   10 | `currency`          | `varchar(3)`          | 否   | `CNY`     | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —                  |
|   11 | `reason`            | `varchar(255)`        | 是   | `NULL`    | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —                  |
|   12 | `gateway_refund_no` | `varchar(100)`        | 是   | `NULL`    | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —                  |
|   13 | `operator_type`     | `varchar(30)`         | 是   | `NULL`    | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —                  |
|   14 | `operator_id`       | `bigint(20) unsigned` | 是   | `NULL`    | —   | —              | —       | —                  | —                  |
|   15 | `operator_name`     | `varchar(50)`         | 是   | `NULL`    | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —                  |
|   16 | `trace_id`          | `varchar(64)`         | 是   | `NULL`    | MUL | —              | utf8mb4 | utf8mb4_unicode_ci | —                  |
|   17 | `refunded_at`       | `timestamp`           | 是   | `NULL`    | —   | —              | —       | —                  | —                  |
|   18 | `created_at`        | `timestamp`           | 是   | `NULL`    | —   | —              | —       | —                  | —                  |
|   19 | `updated_at`        | `timestamp`           | 是   | `NULL`    | —   | —              | —       | —                  | —                  |

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

| 约束名                              | 字段                | 引用表     | 引用字段 | 更新规则   | 删除规则   |
| ----------------------------------- | ------------------- | ---------- | -------- | ---------- | ---------- |
| `refunds_invoice_id_foreign`        | `invoice_id`        | `invoices` | `id`     | `RESTRICT` | `RESTRICT` |
| `refunds_payment_id_foreign`        | `payment_id`        | `payments` | `id`     | `RESTRICT` | `SET NULL` |
| `refunds_refund_invoice_id_foreign` | `refund_invoice_id` | `invoices` | `id`     | `RESTRICT` | `SET NULL` |
| `refunds_user_id_foreign`           | `user_id`           | `users`    | `id`     | `RESTRICT` | `RESTRICT` |

### 2.46 `roles`

- 类型：`BASE TABLE`
- 引擎：`InnoDB`
- 估算行数：`218`
- 数据大小：`64 KB`
- 索引大小：`16 KB`
- 自增值：`686`
- 排序规则：`utf8mb4_unicode_ci`
- 表注释：—

#### 字段

| 序号 | 字段          | 类型                  | 可空 | 默认值 | 键  | 额外           | 字符集  | 排序规则           | 注释         |
| ---: | ------------- | --------------------- | ---- | ------ | --- | -------------- | ------- | ------------------ | ------------ |
|    1 | `id`          | `bigint(20) unsigned` | 否   | —      | PRI | auto_increment | —       | —                  | —            |
|    2 | `name`        | `varchar(50)`         | 否   | —      | UNI | —              | utf8mb4 | utf8mb4_unicode_ci | —            |
|    3 | `label`       | `varchar(100)`        | 是   | `NULL` | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —            |
|    4 | `permissions` | `json`                | 否   | —      | —   | —              | —       | —                  | 权限标识数组 |
|    5 | `created_at`  | `timestamp`           | 是   | `NULL` | —   | —              | —       | —                  | —            |
|    6 | `updated_at`  | `timestamp`           | 是   | `NULL` | —   | —              | —       | —                  | —            |

#### 索引

| 索引名              | 唯一 | 类型    | 字段   | 基数 | 注释 |
| ------------------- | ---- | ------- | ------ | ---: | ---- |
| `PRIMARY`           | 是   | `BTREE` | `id`   |  218 | —    |
| `roles_name_unique` | 是   | `BTREE` | `name` |  218 | —    |

#### 外键约束

无数据库级外键约束。

### 2.47 `schedule_run_logs`

- 类型：`BASE TABLE`
- 引擎：`InnoDB`
- 估算行数：`4`
- 数据大小：`16 KB`
- 索引大小：`48 KB`
- 自增值：`151570`
- 排序规则：`utf8mb4_unicode_ci`
- 表注释：—

#### 字段

| 序号 | 字段          | 类型                  | 可空 | 默认值    | 键  | 额外           | 字符集  | 排序规则           | 注释                               |
| ---: | ------------- | --------------------- | ---- | --------- | --- | -------------- | ------- | ------------------ | ---------------------------------- |
|    1 | `id`          | `bigint(20) unsigned` | 否   | —         | PRI | auto_increment | —       | —                  | —                                  |
|    2 | `task_name`   | `varchar(100)`        | 否   | —         | MUL | —              | utf8mb4 | utf8mb4_unicode_ci | 任务名称                           |
|    3 | `status`      | `varchar(20)`         | 否   | `success` | MUL | —              | utf8mb4 | utf8mb4_unicode_ci | 执行状态: success, failed, skipped |
|    4 | `duration_ms` | `int(10) unsigned`    | 否   | `0`       | —   | —              | —       | —                  | 执行耗时(毫秒)                     |
|    5 | `summary`     | `json`                | 是   | `NULL`    | —   | —              | —       | —                  | 执行摘要数据                       |
|    6 | `error_msg`   | `text`                | 是   | `NULL`    | —   | —              | utf8mb4 | utf8mb4_unicode_ci | 错误信息                           |
|    7 | `started_at`  | `timestamp`           | 是   | `NULL`    | —   | —              | —       | —                  | 开始时间                           |
|    8 | `finished_at` | `timestamp`           | 是   | `NULL`    | —   | —              | —       | —                  | 结束时间                           |
|    9 | `created_at`  | `timestamp`           | 是   | `NULL`    | MUL | —              | —       | —                  | —                                  |
|   10 | `updated_at`  | `timestamp`           | 是   | `NULL`    | —   | —              | —       | —                  | —                                  |

#### 索引

| 索引名                                         | 唯一 | 类型    | 字段                      | 基数 | 注释 |
| ---------------------------------------------- | ---- | ------- | ------------------------- | ---: | ---- |
| `PRIMARY`                                      | 是   | `BTREE` | `id`                      |    4 | —    |
| `schedule_run_logs_created_at_index`           | 否   | `BTREE` | `created_at`              |    1 | —    |
| `schedule_run_logs_status_created_at_index`    | 否   | `BTREE` | `status`, `created_at`    |    2 | —    |
| `schedule_run_logs_task_name_created_at_index` | 否   | `BTREE` | `task_name`, `created_at` |    4 | —    |

#### 外键约束

无数据库级外键约束。

### 2.48 `schedule_task_runs`

- 类型：`BASE TABLE`
- 引擎：`InnoDB`
- 估算行数：`0`
- 数据大小：`16 KB`
- 索引大小：`96 KB`
- 自增值：`3359`
- 排序规则：`utf8mb4_unicode_ci`
- 表注释：—

#### 字段

| 序号 | 字段               | 类型                   | 可空 | 默认值      | 键  | 额外           | 字符集  | 排序规则           | 注释 |
| ---: | ------------------ | ---------------------- | ---- | ----------- | --- | -------------- | ------- | ------------------ | ---- |
|    1 | `id`               | `bigint(20) unsigned`  | 否   | —           | PRI | auto_increment | —       | —                  | —    |
|    2 | `parent_run_id`    | `bigint(20) unsigned`  | 是   | `NULL`      | MUL | —              | —       | —                  | —    |
|    3 | `schedule_tick_id` | `bigint(20) unsigned`  | 是   | `NULL`      | MUL | —              | —       | —                  | —    |
|    4 | `task_key`         | `varchar(120)`         | 否   | —           | MUL | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|    5 | `task_name`        | `varchar(160)`         | 否   | —           | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|    6 | `rule_description` | `varchar(160)`         | 是   | `NULL`      | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|    7 | `source`           | `varchar(40)`          | 否   | `heartbeat` | MUL | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|    8 | `queue`            | `varchar(80)`          | 是   | `NULL`      | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|    9 | `status`           | `varchar(30)`          | 否   | `queued`    | MUL | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|   10 | `attempt`          | `smallint(5) unsigned` | 否   | `1`         | —   | —              | —       | —                  | —    |
|   11 | `duration_ms`      | `int(10) unsigned`     | 是   | `NULL`      | —   | —              | —       | —                  | —    |
|   12 | `summary`          | `json`                 | 是   | `NULL`      | —   | —              | —       | —                  | —    |
|   13 | `error_msg`        | `text`                 | 是   | `NULL`      | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|   14 | `queued_at`        | `timestamp`            | 是   | `NULL`      | —   | —              | —       | —                  | —    |
|   15 | `started_at`       | `timestamp`            | 是   | `NULL`      | —   | —              | —       | —                  | —    |
|   16 | `finished_at`      | `timestamp`            | 是   | `NULL`      | —   | —              | —       | —                  | —    |
|   17 | `manual_retry_at`  | `timestamp`            | 是   | `NULL`      | —   | —              | —       | —                  | —    |
|   18 | `manual_retry_by`  | `bigint(20) unsigned`  | 是   | `NULL`      | —   | —              | —       | —                  | —    |
|   19 | `created_at`       | `timestamp`            | 是   | `NULL`      | —   | —              | —       | —                  | —    |
|   20 | `updated_at`       | `timestamp`            | 是   | `NULL`      | —   | —              | —       | —                  | —    |

#### 索引

| 索引名                                         | 唯一 | 类型    | 字段                                     | 基数 | 注释 |
| ---------------------------------------------- | ---- | ------- | ---------------------------------------- | ---: | ---- |
| `PRIMARY`                                      | 是   | `BTREE` | `id`                                     |    0 | —    |
| `schedule_task_runs_active_lookup_index`       | 否   | `BTREE` | `task_key`, `status`, `queued_at`        |    0 | —    |
| `schedule_task_runs_parent_created_at_index`   | 否   | `BTREE` | `parent_run_id`, `created_at`            |    0 | —    |
| `schedule_task_runs_source_created_at_index`   | 否   | `BTREE` | `source`, `created_at`                   |    0 | —    |
| `schedule_task_runs_status_created_at_index`   | 否   | `BTREE` | `status`, `created_at`                   |    0 | —    |
| `schedule_task_runs_task_key_created_at_index` | 否   | `BTREE` | `task_key`, `created_at`                 |    0 | —    |
| `schedule_task_runs_tick_task_source_unique`   | 是   | `BTREE` | `schedule_tick_id`, `task_key`, `source` |    0 | —    |

#### 外键约束

| 约束名                                        | 字段               | 引用表           | 引用字段 | 更新规则   | 删除规则   |
| --------------------------------------------- | ------------------ | ---------------- | -------- | ---------- | ---------- |
| `schedule_task_runs_schedule_tick_id_foreign` | `schedule_tick_id` | `schedule_ticks` | `id`     | `RESTRICT` | `SET NULL` |

### 2.49 `schedule_ticks`

- 类型：`BASE TABLE`
- 引擎：`InnoDB`
- 估算行数：`0`
- 数据大小：`16 KB`
- 索引大小：`64 KB`
- 自增值：`281`
- 排序规则：`utf8mb4_unicode_ci`
- 表注释：—

#### 字段

| 序号 | 字段              | 类型                  | 可空 | 默认值              | 键  | 额外                        | 字符集 | 排序规则 | 注释 |
| ---: | ----------------- | --------------------- | ---- | ------------------- | --- | --------------------------- | ------ | -------- | ---- |
|    1 | `id`              | `bigint(20) unsigned` | 否   | —                   | PRI | auto_increment              | —      | —        | —    |
|    2 | `slot_started_at` | `timestamp`           | 否   | `CURRENT_TIMESTAMP` | UNI | on update CURRENT_TIMESTAMP | —      | —        | —    |
|    3 | `global_number`   | `bigint(20) unsigned` | 否   | —                   | UNI | —                           | —      | —        | —    |
|    4 | `daily_index`     | `tinyint(3) unsigned` | 否   | —                   | MUL | —                           | —      | —        | —    |
|    5 | `triggered_at`    | `timestamp`           | 是   | `NULL`              | MUL | —                           | —      | —        | —    |
|    6 | `created_at`      | `timestamp`           | 是   | `NULL`              | —   | —                           | —      | —        | —    |
|    7 | `updated_at`      | `timestamp`           | 是   | `NULL`              | —   | —                           | —      | —        | —    |

#### 索引

| 索引名                                  | 唯一 | 类型    | 字段              | 基数 | 注释 |
| --------------------------------------- | ---- | ------- | ----------------- | ---: | ---- |
| `PRIMARY`                               | 是   | `BTREE` | `id`              |    0 | —    |
| `schedule_ticks_daily_index_index`      | 否   | `BTREE` | `daily_index`     |    0 | —    |
| `schedule_ticks_global_number_unique`   | 是   | `BTREE` | `global_number`   |    0 | —    |
| `schedule_ticks_slot_started_at_unique` | 是   | `BTREE` | `slot_started_at` |    0 | —    |
| `schedule_ticks_triggered_at_index`     | 否   | `BTREE` | `triggered_at`    |    0 | —    |

#### 外键约束

无数据库级外键约束。

### 2.50 `second_product_groups`

- 类型：`BASE TABLE`
- 引擎：`InnoDB`
- 估算行数：`117`
- 数据大小：`16 KB`
- 索引大小：`32 KB`
- 自增值：`206`
- 排序规则：`utf8mb4_unicode_ci`
- 表注释：—

#### 字段

| 序号 | 字段                     | 类型                  | 可空 | 默认值 | 键  | 额外           | 字符集  | 排序规则           | 注释                      |
| ---: | ------------------------ | --------------------- | ---- | ------ | --- | -------------- | ------- | ------------------ | ------------------------- |
|    1 | `id`                     | `bigint(20) unsigned` | 否   | —      | PRI | auto_increment | —       | —                  | —                         |
|    2 | `first_product_group_id` | `bigint(20) unsigned` | 否   | —      | MUL | —              | —       | —                  | → first_product_groups.id |
|    3 | `name`                   | `varchar(100)`        | 否   | —      | —   | —              | utf8mb4 | utf8mb4_unicode_ci | 名称                      |
|    4 | `slug`                   | `varchar(100)`        | 是   | `NULL` | —   | —              | utf8mb4 | utf8mb4_unicode_ci | URL标识                   |
|    5 | `description`            | `varchar(255)`        | 是   | `NULL` | —   | —              | utf8mb4 | utf8mb4_unicode_ci | 分组说明                  |
|    6 | `banner_image`           | `varchar(255)`        | 是   | `NULL` | —   | —              | utf8mb4 | utf8mb4_unicode_ci | 横幅图                    |
|    7 | `sort_order`             | `int(11)`             | 否   | `0`    | —   | —              | —       | —                  | 排序                      |
|    8 | `is_visible`             | `tinyint(3) unsigned` | 否   | `1`    | —   | —              | —       | —                  | 前台可见                  |
|    9 | `created_at`             | `timestamp`           | 是   | `NULL` | —   | —              | —       | —                  | —                         |
|   10 | `updated_at`             | `timestamp`           | 是   | `NULL` | —   | —              | —       | —                  | —                         |

#### 索引

| 索引名                          | 唯一 | 类型    | 字段                                                 | 基数 | 注释 |
| ------------------------------- | ---- | ------- | ---------------------------------------------------- | ---: | ---- |
| `idx_second_first_visible_sort` | 否   | `BTREE` | `first_product_group_id`, `is_visible`, `sort_order` |  115 | —    |
| `PRIMARY`                       | 是   | `BTREE` | `id`                                                 |  117 | —    |
| `uq_second_first_slug`          | 是   | `BTREE` | `first_product_group_id`, `slug`                     |  117 | —    |

#### 外键约束

| 约束名                  | 字段                     | 引用表                 | 引用字段 | 更新规则   | 删除规则   |
| ----------------------- | ------------------------ | ---------------------- | -------- | ---------- | ---------- |
| `fk_second_first_group` | `first_product_group_id` | `first_product_groups` | `id`     | `RESTRICT` | `RESTRICT` |

### 2.51 `services`

- 类型：`BASE TABLE`
- 引擎：`InnoDB`
- 估算行数：`42`
- 数据大小：`48 KB`
- 索引大小：`128 KB`
- 自增值：`362`
- 排序规则：`utf8mb4_unicode_ci`
- 表注释：服务实例表，记录用户已购买产品的生命周期、计费、上游和续费状态

#### 字段

| 序号 | 字段               | 类型                  | 可空 | 默认值   | 键  | 额外           | 字符集  | 排序规则           | 注释                                              |
| ---: | ------------------ | --------------------- | ---- | -------- | --- | -------------- | ------- | ------------------ | ------------------------------------------------- |
|    1 | `id`               | `bigint(20) unsigned` | 否   | —        | PRI | auto_increment | —       | —                  | 服务实例自增主键                                  |
|    2 | `user_id`          | `bigint(20) unsigned` | 否   | —        | MUL | —              | —       | —                  | 所属用户ID                                        |
|    3 | `product_id`       | `bigint(20) unsigned` | 否   | —        | MUL | —              | —       | —                  | 关联商品ID                                        |
|    4 | `order_id`         | `bigint(20) unsigned` | 是   | `NULL`   | MUL | —              | —       | —                  | 内部订单/开通投影ID，仅用于流程追踪               |
|    5 | `invoice_id`       | `bigint(20) unsigned` | 是   | `NULL`   | MUL | —              | —       | —                  | 最近一次关联账单ID                                |
|    6 | `name`             | `varchar(200)`        | 否   | 空字符串 | —   | —              | utf8mb4 | utf8mb4_unicode_ci | 服务自定义名称                                    |
|    7 | `domain`           | `varchar(200)`        | 否   | 空字符串 | —   | —              | utf8mb4 | utf8mb4_unicode_ci | 服务域名或主机名                                  |
|    8 | `billing_cycle`    | `varchar(20)`         | 否   | —        | —   | —              | utf8mb4 | utf8mb4_unicode_ci | 计费周期                                          |
|    9 | `amount`           | `decimal(12,2)`       | 否   | —        | —   | —              | —       | —                  | 服务续费/购买金额                                 |
|   10 | `locked_pricing`   | `json`                | 是   | `NULL`   | —   | —              | —       | —                  | 锁定续费定价 JSON，null 表示跟随商品定价          |
|   11 | `status`           | `tinyint(4)`          | 否   | `0`      | MUL | —              | —       | —                  | 服务状态：0待开通 1运行中 2已暂停 3已到期 4已取消 |
|   12 | `provision_data`   | `json`                | 是   | `NULL`   | —   | —              | —       | —                  | 开通和上游实例数据 JSON                           |
|   13 | `expires_at`       | `timestamp`           | 是   | `NULL`   | MUL | —              | —       | —                  | 服务到期时间                                      |
|   14 | `auto_renew`       | `tinyint(4)`          | 否   | `0`      | —   | —              | —       | —                  | 是否自动续费：0关闭 1开启                         |
|   15 | `suspended_reason` | `varchar(200)`        | 是   | `NULL`   | —   | —              | utf8mb4 | utf8mb4_unicode_ci | 暂停原因                                          |
|   16 | `created_at`       | `timestamp`           | 是   | `NULL`   | —   | —              | —       | —                  | 创建时间                                          |
|   17 | `updated_at`       | `timestamp`           | 是   | `NULL`   | —   | —              | —       | —                  | 更新时间                                          |
|   18 | `deleted_at`       | `timestamp`           | 是   | `NULL`   | —   | —              | —       | —                  | —                                                 |
|   19 | `remark`           | `varchar(255)`        | 是   | `NULL`   | —   | —              | utf8mb4 | utf8mb4_unicode_ci | 服务备注                                          |
|   20 | `operator`         | `varchar(50)`         | 是   | `NULL`   | —   | —              | utf8mb4 | utf8mb4_unicode_ci | 操作人快照                                        |
|   21 | `trace_id`         | `varchar(64)`         | 是   | `NULL`   | MUL | —              | utf8mb4 | utf8mb4_unicode_ci | 链路追踪号                                        |

#### 索引

| 索引名                              | 唯一 | 类型    | 字段                         | 基数 | 注释 |
| ----------------------------------- | ---- | ------- | ---------------------------- | ---: | ---- |
| `PRIMARY`                           | 是   | `BTREE` | `id`                         |   39 | —    |
| `services_expires_at_index`         | 否   | `BTREE` | `expires_at`                 |   30 | —    |
| `services_invoice_id_idx`           | 否   | `BTREE` | `invoice_id`                 |   24 | —    |
| `services_order_id_idx`             | 否   | `BTREE` | `order_id`                   |   20 | —    |
| `services_product_id_idx`           | 否   | `BTREE` | `product_id`                 |   39 | —    |
| `services_status_expires_at_id_idx` | 否   | `BTREE` | `status`, `expires_at`, `id` |   39 | —    |
| `services_trace_id_idx`             | 否   | `BTREE` | `trace_id`                   |   39 | —    |
| `services_user_id_index`            | 否   | `BTREE` | `user_id`                    |   39 | —    |
| `services_user_status_id_idx`       | 否   | `BTREE` | `user_id`, `status`, `id`    |   39 | —    |

#### 外键约束

| 约束名                        | 字段         | 引用表     | 引用字段 | 更新规则   | 删除规则   |
| ----------------------------- | ------------ | ---------- | -------- | ---------- | ---------- |
| `fk_services_invoice_id`      | `invoice_id` | `invoices` | `id`     | `RESTRICT` | `SET NULL` |
| `fk_services_product_id`      | `product_id` | `products` | `id`     | `RESTRICT` | `RESTRICT` |
| `fk_services_user_id`         | `user_id`    | `users`    | `id`     | `RESTRICT` | `RESTRICT` |
| `fk_stage2_services_order_id` | `order_id`   | `orders`   | `id`     | `RESTRICT` | `SET NULL` |

### 2.52 `service_connection_snapshots`

- 类型：`BASE TABLE`
- 引擎：`InnoDB`
- 估算行数：`1`
- 数据大小：`16 KB`
- 索引大小：`80 KB`
- 自增值：`198`
- 排序规则：`utf8mb4_unicode_ci`
- 表注释：—

#### 字段

| 序号 | 字段                          | 类型                  | 可空 | 默认值    | 键  | 额外           | 字符集  | 排序规则           | 注释 |
| ---: | ----------------------------- | --------------------- | ---- | --------- | --- | -------------- | ------- | ------------------ | ---- |
|    1 | `id`                          | `bigint(20) unsigned` | 否   | —         | PRI | auto_increment | —       | —                  | —    |
|    2 | `service_id`                  | `bigint(20) unsigned` | 否   | —         | MUL | —              | —       | —                  | —    |
|    3 | `service_upstream_binding_id` | `bigint(20) unsigned` | 是   | `NULL`    | MUL | —              | —       | —                  | —    |
|    4 | `plugin_id`                   | `bigint(20) unsigned` | 是   | `NULL`    | MUL | —              | —       | —                  | —    |
|    5 | `provider_key`                | `varchar(120)`        | 是   | `NULL`    | MUL | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|    6 | `connection_type`             | `varchar(60)`         | 否   | `default` | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|    7 | `hostname`                    | `varchar(255)`        | 是   | `NULL`    | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|    8 | `ip_address`                  | `varchar(120)`        | 是   | `NULL`    | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|    9 | `port`                        | `int(10) unsigned`    | 是   | `NULL`    | —   | —              | —       | —                  | —    |
|   10 | `connection_json`             | `json`                | 是   | `NULL`    | —   | —              | —       | —                  | —    |
|   11 | `secret_json`                 | `longtext`            | 是   | `NULL`    | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|   12 | `has_secret_json`             | `json`                | 是   | `NULL`    | —   | —              | —       | —                  | —    |
|   13 | `checked_at`                  | `timestamp`           | 是   | `NULL`    | —   | —              | —       | —                  | —    |
|   14 | `backfill_batch_id`           | `varchar(64)`         | 是   | `NULL`    | MUL | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|   15 | `created_at`                  | `timestamp`           | 是   | `NULL`    | —   | —              | —       | —                  | —    |
|   16 | `updated_at`                  | `timestamp`           | 是   | `NULL`    | —   | —              | —       | —                  | —    |

#### 索引

| 索引名                                                             | 唯一 | 类型    | 字段                              | 基数 | 注释 |
| ------------------------------------------------------------------ | ---- | ------- | --------------------------------- | ---: | ---- |
| `PRIMARY`                                                          | 是   | `BTREE` | `id`                              |    1 | —    |
| `service_connection_backfill_batch_idx`                            | 否   | `BTREE` | `backfill_batch_id`               |    1 | —    |
| `service_connection_plugin_checked_idx`                            | 否   | `BTREE` | `plugin_id`, `checked_at`         |    1 | —    |
| `service_connection_provider_type_idx`                             | 否   | `BTREE` | `provider_key`, `connection_type` |    1 | —    |
| `service_connection_service_type_unique`                           | 是   | `BTREE` | `service_id`, `connection_type`   |    1 | —    |
| `service_connection_snapshots_service_upstream_binding_id_foreign` | 否   | `BTREE` | `service_upstream_binding_id`     |    1 | —    |

#### 外键约束

| 约束名                                                             | 字段                          | 引用表                      | 引用字段 | 更新规则   | 删除规则   |
| ------------------------------------------------------------------ | ----------------------------- | --------------------------- | -------- | ---------- | ---------- |
| `service_connection_snapshots_plugin_id_foreign`                   | `plugin_id`                   | `integration_plugins`       | `id`     | `RESTRICT` | `SET NULL` |
| `service_connection_snapshots_service_id_foreign`                  | `service_id`                  | `services`                  | `id`     | `RESTRICT` | `CASCADE`  |
| `service_connection_snapshots_service_upstream_binding_id_foreign` | `service_upstream_binding_id` | `service_upstream_bindings` | `id`     | `RESTRICT` | `SET NULL` |

### 2.53 `service_provision_attempts`

- 类型：`BASE TABLE`
- 引擎：`InnoDB`
- 估算行数：`1`
- 数据大小：`16 KB`
- 索引大小：`80 KB`
- 自增值：`431`
- 排序规则：`utf8mb4_unicode_ci`
- 表注释：—

#### 字段

| 序号 | 字段                          | 类型                  | 可空 | 默认值 | 键  | 额外           | 字符集  | 排序规则           | 注释 |
| ---: | ----------------------------- | --------------------- | ---- | ------ | --- | -------------- | ------- | ------------------ | ---- |
|    1 | `id`                          | `bigint(20) unsigned` | 否   | —      | PRI | auto_increment | —       | —                  | —    |
|    2 | `service_id`                  | `bigint(20) unsigned` | 是   | `NULL` | MUL | —              | —       | —                  | —    |
|    3 | `service_upstream_binding_id` | `bigint(20) unsigned` | 是   | `NULL` | MUL | —              | —       | —                  | —    |
|    4 | `plugin_id`                   | `bigint(20) unsigned` | 是   | `NULL` | MUL | —              | —       | —                  | —    |
|    5 | `provider_key`                | `varchar(120)`        | 是   | `NULL` | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|    6 | `action`                      | `varchar(80)`         | 否   | —      | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|    7 | `attempt_status`              | `varchar(30)`         | 否   | —      | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|    8 | `trace_id`                    | `varchar(64)`         | 是   | `NULL` | MUL | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|    9 | `request_meta_json`           | `json`                | 是   | `NULL` | —   | —              | —       | —                  | —    |
|   10 | `response_meta_json`          | `json`                | 是   | `NULL` | —   | —              | —       | —                  | —    |
|   11 | `error_code`                  | `varchar(80)`         | 是   | `NULL` | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|   12 | `error_message`               | `varchar(500)`        | 是   | `NULL` | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|   13 | `attempted_at`                | `timestamp`           | 是   | `NULL` | —   | —              | —       | —                  | —    |
|   14 | `backfill_batch_id`           | `varchar(64)`         | 是   | `NULL` | MUL | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|   15 | `created_at`                  | `timestamp`           | 是   | `NULL` | —   | —              | —       | —                  | —    |
|   16 | `updated_at`                  | `timestamp`           | 是   | `NULL` | —   | —              | —       | —                  | —    |

#### 索引

| 索引名                                                           | 唯一 | 类型    | 字段                                          | 基数 | 注释 |
| ---------------------------------------------------------------- | ---- | ------- | --------------------------------------------- | ---: | ---- |
| `PRIMARY`                                                        | 是   | `BTREE` | `id`                                          |    1 | —    |
| `service_attempt_backfill_batch_idx`                             | 否   | `BTREE` | `backfill_batch_id`                           |    1 | —    |
| `service_attempt_plugin_status_idx`                              | 否   | `BTREE` | `plugin_id`, `attempt_status`, `attempted_at` |    1 | —    |
| `service_attempt_service_action_idx`                             | 否   | `BTREE` | `service_id`, `action`, `attempted_at`        |    1 | —    |
| `service_attempt_trace_idx`                                      | 否   | `BTREE` | `trace_id`                                    |    1 | —    |
| `service_provision_attempts_service_upstream_binding_id_foreign` | 否   | `BTREE` | `service_upstream_binding_id`                 |    1 | —    |

#### 外键约束

| 约束名                                                           | 字段                          | 引用表                      | 引用字段 | 更新规则   | 删除规则   |
| ---------------------------------------------------------------- | ----------------------------- | --------------------------- | -------- | ---------- | ---------- |
| `service_provision_attempts_plugin_id_foreign`                   | `plugin_id`                   | `integration_plugins`       | `id`     | `RESTRICT` | `SET NULL` |
| `service_provision_attempts_service_id_foreign`                  | `service_id`                  | `services`                  | `id`     | `RESTRICT` | `SET NULL` |
| `service_provision_attempts_service_upstream_binding_id_foreign` | `service_upstream_binding_id` | `service_upstream_bindings` | `id`     | `RESTRICT` | `SET NULL` |

### 2.54 `service_runtime_snapshots`

- 类型：`BASE TABLE`
- 引擎：`InnoDB`
- 估算行数：`0`
- 数据大小：`16 KB`
- 索引大小：`80 KB`
- 自增值：`198`
- 排序规则：`utf8mb4_unicode_ci`
- 表注释：—

#### 字段

| 序号 | 字段                          | 类型                  | 可空 | 默认值 | 键  | 额外           | 字符集  | 排序规则           | 注释 |
| ---: | ----------------------------- | --------------------- | ---- | ------ | --- | -------------- | ------- | ------------------ | ---- |
|    1 | `id`                          | `bigint(20) unsigned` | 否   | —      | PRI | auto_increment | —       | —                  | —    |
|    2 | `service_id`                  | `bigint(20) unsigned` | 否   | —      | UNI | —              | —       | —                  | —    |
|    3 | `service_upstream_binding_id` | `bigint(20) unsigned` | 是   | `NULL` | MUL | —              | —       | —                  | —    |
|    4 | `plugin_id`                   | `bigint(20) unsigned` | 是   | `NULL` | MUL | —              | —       | —                  | —    |
|    5 | `provider_key`                | `varchar(120)`        | 是   | `NULL` | MUL | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|    6 | `status_key`                  | `varchar(60)`         | 是   | `NULL` | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|    7 | `status_text`                 | `varchar(120)`        | 是   | `NULL` | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|    8 | `resource_json`               | `json`                | 是   | `NULL` | —   | —              | —       | —                  | —    |
|    9 | `metrics_json`                | `json`                | 是   | `NULL` | —   | —              | —       | —                  | —    |
|   10 | `snapshot_json`               | `json`                | 是   | `NULL` | —   | —              | —       | —                  | —    |
|   11 | `synced_at`                   | `timestamp`           | 是   | `NULL` | —   | —              | —       | —                  | —    |
|   12 | `backfill_batch_id`           | `varchar(64)`         | 是   | `NULL` | MUL | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|   13 | `created_at`                  | `timestamp`           | 是   | `NULL` | —   | —              | —       | —                  | —    |
|   14 | `updated_at`                  | `timestamp`           | 是   | `NULL` | —   | —              | —       | —                  | —    |

#### 索引

| 索引名                                                          | 唯一 | 类型    | 字段                          | 基数 | 注释 |
| --------------------------------------------------------------- | ---- | ------- | ----------------------------- | ---: | ---- |
| `PRIMARY`                                                       | 是   | `BTREE` | `id`                          |    0 | —    |
| `service_runtime_backfill_batch_idx`                            | 否   | `BTREE` | `backfill_batch_id`           |    0 | —    |
| `service_runtime_plugin_synced_idx`                             | 否   | `BTREE` | `plugin_id`, `synced_at`      |    0 | —    |
| `service_runtime_provider_status_idx`                           | 否   | `BTREE` | `provider_key`, `status_key`  |    0 | —    |
| `service_runtime_service_unique`                                | 是   | `BTREE` | `service_id`                  |    0 | —    |
| `service_runtime_snapshots_service_upstream_binding_id_foreign` | 否   | `BTREE` | `service_upstream_binding_id` |    0 | —    |

#### 外键约束

| 约束名                                                          | 字段                          | 引用表                      | 引用字段 | 更新规则   | 删除规则   |
| --------------------------------------------------------------- | ----------------------------- | --------------------------- | -------- | ---------- | ---------- |
| `service_runtime_snapshots_plugin_id_foreign`                   | `plugin_id`                   | `integration_plugins`       | `id`     | `RESTRICT` | `SET NULL` |
| `service_runtime_snapshots_service_id_foreign`                  | `service_id`                  | `services`                  | `id`     | `RESTRICT` | `CASCADE`  |
| `service_runtime_snapshots_service_upstream_binding_id_foreign` | `service_upstream_binding_id` | `service_upstream_bindings` | `id`     | `RESTRICT` | `SET NULL` |

### 2.55 `service_upstream_bindings`

- 类型：`BASE TABLE`
- 引擎：`InnoDB`
- 估算行数：`1`
- 数据大小：`16 KB`
- 索引大小：`112 KB`
- 自增值：`288`
- 排序规则：`utf8mb4_unicode_ci`
- 表注释：—

#### 字段

| 序号 | 字段                          | 类型                  | 可空 | 默认值 | 键  | 额外           | 字符集  | 排序规则           | 注释 |
| ---: | ----------------------------- | --------------------- | ---- | ------ | --- | -------------- | ------- | ------------------ | ---- |
|    1 | `id`                          | `bigint(20) unsigned` | 否   | —      | PRI | auto_increment | —       | —                  | —    |
|    2 | `service_id`                  | `bigint(20) unsigned` | 否   | —      | MUL | —              | —       | —                  | —    |
|    3 | `product_upstream_binding_id` | `bigint(20) unsigned` | 是   | `NULL` | MUL | —              | —       | —                  | —    |
|    4 | `supplier_plugin_binding_id`  | `bigint(20) unsigned` | 是   | `NULL` | MUL | —              | —       | —                  | —    |
|    5 | `plugin_id`                   | `bigint(20) unsigned` | 否   | —      | MUL | —              | —       | —                  | —    |
|    6 | `provider_key`                | `varchar(120)`        | 否   | —      | MUL | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|    7 | `upstream_service_id`         | `varchar(120)`        | 否   | —      | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|    8 | `upstream_account_id`         | `varchar(120)`        | 是   | `NULL` | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|    9 | `runtime_snapshot_json`       | `json`                | 是   | `NULL` | —   | —              | —       | —                  | —    |
|   10 | `connection_snapshot_json`    | `json`                | 是   | `NULL` | —   | —              | —       | —                  | —    |
|   11 | `status_snapshot`             | `varchar(60)`         | 是   | `NULL` | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|   12 | `last_synced_at`              | `timestamp`           | 是   | `NULL` | —   | —              | —       | —                  | —    |
|   13 | `last_sync_error`             | `varchar(500)`        | 是   | `NULL` | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|   14 | `backfill_batch_id`           | `varchar(64)`         | 是   | `NULL` | MUL | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|   15 | `created_at`                  | `timestamp`           | 是   | `NULL` | —   | —              | —       | —                  | —    |
|   16 | `updated_at`                  | `timestamp`           | 是   | `NULL` | —   | —              | —       | —                  | —    |

#### 索引

| 索引名                                                          | 唯一 | 类型    | 字段                                             | 基数 | 注释 |
| --------------------------------------------------------------- | ---- | ------- | ------------------------------------------------ | ---: | ---- |
| `PRIMARY`                                                       | 是   | `BTREE` | `id`                                             |    1 | —    |
| `service_upstream_backfill_batch_idx`                           | 否   | `BTREE` | `backfill_batch_id`                              |    1 | —    |
| `service_upstream_bindings_product_upstream_binding_id_foreign` | 否   | `BTREE` | `product_upstream_binding_id`                    |    1 | —    |
| `service_upstream_bindings_supplier_plugin_binding_id_foreign`  | 否   | `BTREE` | `supplier_plugin_binding_id`                     |    1 | —    |
| `service_upstream_plugin_sync_idx`                              | 否   | `BTREE` | `plugin_id`, `last_synced_at`                    |    1 | —    |
| `service_upstream_provider_status_idx`                          | 否   | `BTREE` | `provider_key`, `status_snapshot`                |    1 | —    |
| `service_upstream_service_idx`                                  | 否   | `BTREE` | `service_id`                                     |    1 | —    |
| `service_upstream_unique`                                       | 是   | `BTREE` | `service_id`, `plugin_id`, `upstream_service_id` |    1 | —    |

#### 外键约束

| 约束名                                                          | 字段                          | 引用表                      | 引用字段 | 更新规则   | 删除规则   |
| --------------------------------------------------------------- | ----------------------------- | --------------------------- | -------- | ---------- | ---------- |
| `service_upstream_bindings_plugin_id_foreign`                   | `plugin_id`                   | `integration_plugins`       | `id`     | `RESTRICT` | `RESTRICT` |
| `service_upstream_bindings_product_upstream_binding_id_foreign` | `product_upstream_binding_id` | `product_upstream_bindings` | `id`     | `RESTRICT` | `SET NULL` |
| `service_upstream_bindings_service_id_foreign`                  | `service_id`                  | `services`                  | `id`     | `RESTRICT` | `RESTRICT` |
| `service_upstream_bindings_supplier_plugin_binding_id_foreign`  | `supplier_plugin_binding_id`  | `supplier_plugin_bindings`  | `id`     | `RESTRICT` | `SET NULL` |

### 2.56 `sessions`

- 类型：`BASE TABLE`
- 引擎：`InnoDB`
- 估算行数：`0`
- 数据大小：`16 KB`
- 索引大小：`32 KB`
- 自增值：—
- 排序规则：`utf8mb4_unicode_ci`
- 表注释：—

#### 字段

| 序号 | 字段            | 类型                  | 可空 | 默认值 | 键  | 额外 | 字符集  | 排序规则           | 注释 |
| ---: | --------------- | --------------------- | ---- | ------ | --- | ---- | ------- | ------------------ | ---- |
|    1 | `id`            | `varchar(255)`        | 否   | —      | PRI | —    | utf8mb4 | utf8mb4_unicode_ci | —    |
|    2 | `user_id`       | `bigint(20) unsigned` | 是   | `NULL` | MUL | —    | —       | —                  | —    |
|    3 | `ip_address`    | `varchar(45)`         | 是   | `NULL` | —   | —    | utf8mb4 | utf8mb4_unicode_ci | —    |
|    4 | `user_agent`    | `text`                | 是   | `NULL` | —   | —    | utf8mb4 | utf8mb4_unicode_ci | —    |
|    5 | `payload`       | `longtext`            | 否   | —      | —   | —    | utf8mb4 | utf8mb4_unicode_ci | —    |
|    6 | `last_activity` | `int(11)`             | 否   | —      | MUL | —    | —       | —                  | —    |

#### 索引

| 索引名                         | 唯一 | 类型    | 字段            | 基数 | 注释 |
| ------------------------------ | ---- | ------- | --------------- | ---: | ---- |
| `PRIMARY`                      | 是   | `BTREE` | `id`            |    0 | —    |
| `sessions_last_activity_index` | 否   | `BTREE` | `last_activity` |    0 | —    |
| `sessions_user_id_index`       | 否   | `BTREE` | `user_id`       |    0 | —    |

#### 外键约束

| 约束名                       | 字段      | 引用表  | 引用字段 | 更新规则   | 删除规则  |
| ---------------------------- | --------- | ------- | -------- | ---------- | --------- |
| `fk_stage2_sessions_user_id` | `user_id` | `users` | `id`     | `RESTRICT` | `CASCADE` |

### 2.57 `settings`

- 类型：`BASE TABLE`
- 引擎：`InnoDB`
- 估算行数：`13`
- 数据大小：`16 KB`
- 索引大小：`16 KB`
- 自增值：`534`
- 排序规则：`utf8mb4_unicode_ci`
- 表注释：—

#### 字段

| 序号 | 字段         | 类型                  | 可空 | 默认值 | 键  | 额外           | 字符集  | 排序规则           | 注释 |
| ---: | ------------ | --------------------- | ---- | ------ | --- | -------------- | ------- | ------------------ | ---- |
|    1 | `id`         | `bigint(20) unsigned` | 否   | —      | PRI | auto_increment | —       | —                  | —    |
|    2 | `group_key`  | `varchar(50)`         | 否   | —      | MUL | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|    3 | `item_key`   | `varchar(100)`        | 否   | —      | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|    4 | `item_value` | `text`                | 是   | `NULL` | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —    |

#### 索引

| 索引名                      | 唯一 | 类型    | 字段                    | 基数 | 注释 |
| --------------------------- | ---- | ------- | ----------------------- | ---: | ---- |
| `PRIMARY`                   | 是   | `BTREE` | `id`                    |   12 | —    |
| `settings_group_key_unique` | 是   | `BTREE` | `group_key`, `item_key` |   12 | —    |

#### 外键约束

无数据库级外键约束。

### 2.58 `suppliers`

- 类型：`BASE TABLE`
- 引擎：`InnoDB`
- 估算行数：`15`
- 数据大小：`16 KB`
- 索引大小：`32 KB`
- 自增值：`132`
- 排序规则：`utf8mb4_unicode_ci`
- 表注释：—

#### 字段

| 序号 | 字段            | 类型                  | 可空 | 默认值 | 键  | 额外           | 字符集  | 排序规则           | 注释          |
| ---: | --------------- | --------------------- | ---- | ------ | --- | -------------- | ------- | ------------------ | ------------- |
|    1 | `id`            | `bigint(20) unsigned` | 否   | —      | PRI | auto_increment | —       | —                  | —             |
|    2 | `name`          | `varchar(120)`        | 否   | —      | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —             |
|    3 | `code`          | `varchar(50)`         | 否   | —      | UNI | —              | utf8mb4 | utf8mb4_unicode_ci | —             |
|    4 | `contact_name`  | `varchar(60)`         | 是   | `NULL` | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —             |
|    5 | `contact_phone` | `varchar(30)`         | 是   | `NULL` | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —             |
|    6 | `contact_email` | `varchar(100)`        | 是   | `NULL` | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —             |
|    7 | `website`       | `varchar(255)`        | 是   | `NULL` | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —             |
|    8 | `status`        | `tinyint(4)`          | 否   | `1`    | MUL | —              | —       | —                  | 0=停用 1=启用 |
|    9 | `sort_order`    | `int(11)`             | 否   | `0`    | —   | —              | —       | —                  | —             |
|   10 | `notes`         | `text`                | 是   | `NULL` | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —             |
|   11 | `created_at`    | `timestamp`           | 是   | `NULL` | —   | —              | —       | —                  | —             |
|   12 | `updated_at`    | `timestamp`           | 是   | `NULL` | —   | —              | —       | —                  | —             |

#### 索引

| 索引名                              | 唯一 | 类型    | 字段                   | 基数 | 注释 |
| ----------------------------------- | ---- | ------- | ---------------------- | ---: | ---- |
| `PRIMARY`                           | 是   | `BTREE` | `id`                   |   15 | —    |
| `suppliers_code_unique`             | 是   | `BTREE` | `code`                 |   15 | —    |
| `suppliers_status_sort_order_index` | 否   | `BTREE` | `status`, `sort_order` |    4 | —    |

#### 外键约束

无数据库级外键约束。

### 2.59 `supplier_balances`

- 类型：`BASE TABLE`
- 引擎：`MyISAM`
- 估算行数：`5`
- 数据大小：`456 B`
- 索引大小：`4 KB`
- 自增值：`13`
- 排序规则：`utf8mb4_unicode_ci`
- 表注释：—

#### 字段

| 序号 | 字段                        | 类型                  | 可空 | 默认值  | 键  | 额外           | 字符集  | 排序规则           | 注释                                       |
| ---: | --------------------------- | --------------------- | ---- | ------- | --- | -------------- | ------- | ------------------ | ------------------------------------------ |
|    1 | `id`                        | `bigint(20) unsigned` | 否   | —       | PRI | auto_increment | —       | —                  | —                                          |
|    2 | `supplier_id`               | `bigint(20) unsigned` | 否   | —       | UNI | —              | —       | —                  | 所属供应商                                 |
|    3 | `provider_key`              | `varchar(120)`        | 是   | `NULL`  | —   | —              | utf8mb4 | utf8mb4_unicode_ci | 同步时使用的上游标识                       |
|    4 | `balance`                   | `decimal(14,2)`       | 是   | `NULL`  | —   | —              | —       | —                  | 最近一次成功同步到的上游余额               |
|    5 | `currency`                  | `varchar(20)`         | 是   | `NULL`  | —   | —              | utf8mb4 | utf8mb4_unicode_ci | 币种，取上游返回值                         |
|    6 | `low_balance_threshold`     | `decimal(14,2)`       | 否   | `20.00` | —   | —              | —       | —                  | 余额不足告警阈值，默认 20                  |
|    7 | `low_balance_alert_enabled` | `tinyint(1)`          | 否   | `1`     | —   | —              | —       | —                  | 是否启用余额不足邮件提醒                   |
|    8 | `last_synced_at`            | `timestamp`           | 是   | `NULL`  | MUL | —              | —       | —                  | 最近一次成功同步时间                       |
|    9 | `last_attempted_at`         | `timestamp`           | 是   | `NULL`  | —   | —              | —       | —                  | 最近一次尝试同步时间，含失败               |
|   10 | `last_sync_status`          | `varchar(30)`         | 是   | `NULL`  | —   | —              | utf8mb4 | utf8mb4_unicode_ci | success / failed                           |
|   11 | `last_sync_error`           | `varchar(500)`        | 是   | `NULL`  | —   | —              | utf8mb4 | utf8mb4_unicode_ci | 最近一次同步失败原因                       |
|   12 | `low_balance_notified_at`   | `timestamp`           | 是   | `NULL`  | —   | —              | —       | —                  | 最近一次余额不足告警时间，用于冷却与状态机 |
|   13 | `created_at`                | `timestamp`           | 是   | `NULL`  | —   | —              | —       | —                  | —                                          |
|   14 | `updated_at`                | `timestamp`           | 是   | `NULL`  | —   | —              | —       | —                  | —                                          |

#### 索引

| 索引名                                   | 唯一 | 类型    | 字段             | 基数 | 注释 |
| ---------------------------------------- | ---- | ------- | ---------------- | ---: | ---- |
| `PRIMARY`                                | 是   | `BTREE` | `id`             |    5 | —    |
| `supplier_balances_last_synced_at_index` | 否   | `BTREE` | `last_synced_at` |    0 | —    |
| `supplier_balances_supplier_id_unique`   | 是   | `BTREE` | `supplier_id`    |    5 | —    |

#### 外键约束

无数据库级外键约束。

### 2.60 `supplier_balance_logs`

- 类型：`BASE TABLE`
- 引擎：`MyISAM`
- 估算行数：`0`
- 数据大小：`0 B`
- 索引大小：`1 KB`
- 自增值：`1`
- 排序规则：`utf8mb4_unicode_ci`
- 表注释：—

#### 字段

| 序号 | 字段               | 类型                  | 可空 | 默认值     | 键  | 额外           | 字符集  | 排序规则           | 注释                                                   |
| ---: | ------------------ | --------------------- | ---- | ---------- | --- | -------------- | ------- | ------------------ | ------------------------------------------------------ |
|    1 | `id`               | `bigint(20) unsigned` | 否   | —          | PRI | auto_increment | —       | —                  | —                                                      |
|    2 | `supplier_id`      | `bigint(20) unsigned` | 否   | —          | MUL | —              | —       | —                  | 所属供应商                                             |
|    3 | `balance`          | `decimal(14,2)`       | 是   | `NULL`     | —   | —              | —       | —                  | 本次同步到的余额                                       |
|    4 | `previous_balance` | `decimal(14,2)`       | 是   | `NULL`     | —   | —              | —       | —                  | 变更前余额，首次同步为空                               |
|    5 | `delta`            | `decimal(14,2)`       | 是   | `NULL`     | —   | —              | —       | —                  | 增减值，负数表示消耗                                   |
|    6 | `currency`         | `varchar(20)`         | 是   | `NULL`     | —   | —              | utf8mb4 | utf8mb4_unicode_ci | 币种                                                   |
|    7 | `source`           | `varchar(30)`         | 否   | `schedule` | —   | —              | utf8mb4 | utf8mb4_unicode_ci | schedule=定时同步 provision=开通后触发 manual=手动查询 |
|    8 | `order_id`         | `bigint(20) unsigned` | 是   | `NULL`     | —   | —              | —       | —                  | 由开通触发时关联的订单                                 |
|    9 | `recorded_at`      | `timestamp`           | 是   | `NULL`     | MUL | —              | —       | —                  | 变更记录时间                                           |
|   10 | `created_at`       | `timestamp`           | 是   | `NULL`     | —   | —              | —       | —                  | —                                                      |
|   11 | `updated_at`       | `timestamp`           | 是   | `NULL`     | —   | —              | —       | —                  | —                                                      |

#### 索引

| 索引名                                          | 唯一 | 类型    | 字段                         | 基数 | 注释 |
| ----------------------------------------------- | ---- | ------- | ---------------------------- | ---: | ---- |
| `PRIMARY`                                       | 是   | `BTREE` | `id`                         |    0 | —    |
| `supplier_balance_logs_recorded_at_index`       | 否   | `BTREE` | `recorded_at`                |    0 | —    |
| `supplier_balance_logs_supplier_recorded_index` | 否   | `BTREE` | `supplier_id`, `recorded_at` |    0 | —    |

#### 外键约束

无数据库级外键约束。

### 2.61 `supplier_plugin_bindings`

- 类型：`BASE TABLE`
- 引擎：`InnoDB`
- 估算行数：`13`
- 数据大小：`16 KB`
- 索引大小：`80 KB`
- 自增值：`133`
- 排序规则：`utf8mb4_unicode_ci`
- 表注释：—

#### 字段

| 序号 | 字段                      | 类型                  | 可空 | 默认值       | 键  | 额外           | 字符集  | 排序规则           | 注释 |
| ---: | ------------------------- | --------------------- | ---- | ------------ | --- | -------------- | ------- | ------------------ | ---- |
|    1 | `id`                      | `bigint(20) unsigned` | 否   | —            | PRI | auto_increment | —       | —                  | —    |
|    2 | `supplier_id`             | `bigint(20) unsigned` | 否   | —            | MUL | —              | —       | —                  | —    |
|    3 | `plugin_id`               | `bigint(20) unsigned` | 否   | —            | MUL | —              | —       | —                  | —    |
|    4 | `provider_key`            | `varchar(120)`        | 否   | —            | MUL | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|    5 | `environment`             | `varchar(30)`         | 否   | `production` | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|    6 | `status`                  | `tinyint(3) unsigned` | 否   | `1`          | —   | —              | —       | —                  | —    |
|    7 | `ticket_delivery_enabled` | `tinyint(1)`          | 否   | `0`          | —   | —              | —       | —                  | —    |
|    8 | `priority`                | `int(11)`             | 否   | `0`          | —   | —              | —       | —                  | —    |
|    9 | `base_url`                | `varchar(255)`        | 是   | `NULL`       | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|   10 | `account_name`            | `varchar(120)`        | 是   | `NULL`       | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|   11 | `config_json`             | `json`                | 是   | `NULL`       | —   | —              | —       | —                  | —    |
|   12 | `secret_json`             | `longtext`            | 是   | `NULL`       | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|   13 | `has_secret_json`         | `json`                | 是   | `NULL`       | —   | —              | —       | —                  | —    |
|   14 | `last_checked_at`         | `timestamp`           | 是   | `NULL`       | —   | —              | —       | —                  | —    |
|   15 | `last_check_status`       | `varchar(30)`         | 是   | `NULL`       | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|   16 | `last_check_error`        | `varchar(500)`        | 是   | `NULL`       | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|   17 | `created_by`              | `bigint(20) unsigned` | 是   | `NULL`       | —   | —              | —       | —                  | —    |
|   18 | `updated_by`              | `bigint(20) unsigned` | 是   | `NULL`       | —   | —              | —       | —                  | —    |
|   19 | `backfill_batch_id`       | `varchar(64)`         | 是   | `NULL`       | MUL | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|   20 | `created_at`              | `timestamp`           | 是   | `NULL`       | —   | —              | —       | —                  | —    |
|   21 | `updated_at`              | `timestamp`           | 是   | `NULL`       | —   | —              | —       | —                  | —    |

#### 索引

| 索引名                                 | 唯一 | 类型    | 字段                                      | 基数 | 注释 |
| -------------------------------------- | ---- | ------- | ----------------------------------------- | ---: | ---- |
| `PRIMARY`                              | 是   | `BTREE` | `id`                                      |   12 | —    |
| `supplier_binding_ticket_delivery_idx` | 否   | `BTREE` | `provider_key`, `ticket_delivery_enabled` |    2 | —    |
| `supplier_plugin_backfill_batch_idx`   | 否   | `BTREE` | `backfill_batch_id`                       |    1 | —    |
| `supplier_plugin_plugin_status_idx`    | 否   | `BTREE` | `plugin_id`, `status`                     |    5 | —    |
| `supplier_plugin_provider_status_idx`  | 否   | `BTREE` | `provider_key`, `status`                  |    2 | —    |
| `supplier_plugin_unique`               | 是   | `BTREE` | `supplier_id`, `plugin_id`, `environment` |   12 | —    |

#### 外键约束

| 约束名                                         | 字段          | 引用表                | 引用字段 | 更新规则   | 删除规则   |
| ---------------------------------------------- | ------------- | --------------------- | -------- | ---------- | ---------- |
| `supplier_plugin_bindings_plugin_id_foreign`   | `plugin_id`   | `integration_plugins` | `id`     | `RESTRICT` | `RESTRICT` |
| `supplier_plugin_bindings_supplier_id_foreign` | `supplier_id` | `suppliers`           | `id`     | `RESTRICT` | `RESTRICT` |

### 2.62 `third_product_groups`

- 类型：`BASE TABLE`
- 引擎：`InnoDB`
- 估算行数：`233`
- 数据大小：`64 KB`
- 索引大小：`32 KB`
- 自增值：`302`
- 排序规则：`utf8mb4_unicode_ci`
- 表注释：—

#### 字段

| 序号 | 字段                      | 类型                  | 可空 | 默认值 | 键  | 额外           | 字符集  | 排序规则           | 注释                       |
| ---: | ------------------------- | --------------------- | ---- | ------ | --- | -------------- | ------- | ------------------ | -------------------------- |
|    1 | `id`                      | `bigint(20) unsigned` | 否   | —      | PRI | auto_increment | —       | —                  | —                          |
|    2 | `second_product_group_id` | `bigint(20) unsigned` | 否   | —      | MUL | —              | —       | —                  | → second_product_groups.id |
|    3 | `name`                    | `varchar(100)`        | 否   | —      | —   | —              | utf8mb4 | utf8mb4_unicode_ci | 名称                       |
|    4 | `slug`                    | `varchar(100)`        | 是   | `NULL` | —   | —              | utf8mb4 | utf8mb4_unicode_ci | URL标识                    |
|    5 | `description`             | `varchar(255)`        | 是   | `NULL` | —   | —              | utf8mb4 | utf8mb4_unicode_ci | 分组说明                   |
|    6 | `sort_order`              | `int(11)`             | 否   | `0`    | —   | —              | —       | —                  | 排序                       |
|    7 | `is_visible`              | `tinyint(3) unsigned` | 否   | `1`    | —   | —              | —       | —                  | 前台可见                   |
|    8 | `created_at`              | `timestamp`           | 是   | `NULL` | —   | —              | —       | —                  | —                          |
|    9 | `updated_at`              | `timestamp`           | 是   | `NULL` | —   | —              | —       | —                  | —                          |

#### 索引

| 索引名                          | 唯一 | 类型    | 字段                                                  | 基数 | 注释 |
| ------------------------------- | ---- | ------- | ----------------------------------------------------- | ---: | ---- |
| `idx_third_second_visible_sort` | 否   | `BTREE` | `second_product_group_id`, `is_visible`, `sort_order` |  181 | —    |
| `PRIMARY`                       | 是   | `BTREE` | `id`                                                  |  233 | —    |
| `uq_third_second_slug`          | 是   | `BTREE` | `second_product_group_id`, `slug`                     |  233 | —    |

#### 外键约束

| 约束名                  | 字段                      | 引用表                  | 引用字段 | 更新规则   | 删除规则   |
| ----------------------- | ------------------------- | ----------------------- | -------- | ---------- | ---------- |
| `fk_third_second_group` | `second_product_group_id` | `second_product_groups` | `id`     | `RESTRICT` | `RESTRICT` |

### 2.63 `tickets`

- 类型：`BASE TABLE`
- 引擎：`InnoDB`
- 估算行数：`30`
- 数据大小：`16 KB`
- 索引大小：`80 KB`
- 自增值：`119`
- 排序规则：`utf8mb4_unicode_ci`
- 表注释：—

#### 字段

| 序号 | 字段           | 类型                  | 可空 | 默认值    | 键  | 额外           | 字符集  | 排序规则           | 注释                                  |
| ---: | -------------- | --------------------- | ---- | --------- | --- | -------------- | ------- | ------------------ | ------------------------------------- |
|    1 | `id`           | `bigint(20) unsigned` | 否   | —         | PRI | auto_increment | —       | —                  | —                                     |
|    2 | `user_id`      | `bigint(20) unsigned` | 否   | —         | MUL | —              | —       | —                  | —                                     |
|    3 | `department`   | `varchar(30)`         | 否   | `support` | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —                                     |
|    4 | `subject`      | `varchar(200)`        | 否   | —         | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —                                     |
|    5 | `priority`     | `tinyint(4)`          | 否   | `1`       | —   | —              | —       | —                  | 1=低 2=中 3=高 4=紧急                 |
|    6 | `status`       | `tinyint(4)`          | 否   | `0`       | MUL | —              | —       | —                  | 0=开启 1=客户回复 2=员工回复 3=已关闭 |
|    7 | `service_id`   | `bigint(20) unsigned` | 是   | `NULL`    | MUL | —              | —       | —                  | —                                     |
|    8 | `assignee_id`  | `bigint(20) unsigned` | 是   | `NULL`    | MUL | —              | —       | —                  | —                                     |
|    9 | `created_at`   | `timestamp`           | 是   | `NULL`    | —   | —              | —       | —                  | —                                     |
|   10 | `updated_at`   | `timestamp`           | 是   | `NULL`    | —   | —              | —       | —                  | —                                     |
|   11 | `close_reason` | `varchar(20)`         | 是   | `NULL`    | —   | —              | utf8mb4 | utf8mb4_unicode_ci | admin, client, auto                   |

#### 索引

| 索引名                               | 唯一 | 类型    | 字段                              | 基数 | 注释 |
| ------------------------------------ | ---- | ------- | --------------------------------- | ---: | ---- |
| `idx_stage2_tickets_assignee_id`     | 否   | `BTREE` | `assignee_id`                     |    2 | —    |
| `PRIMARY`                            | 是   | `BTREE` | `id`                              |   29 | —    |
| `tickets_service_id_idx`             | 否   | `BTREE` | `service_id`                      |   14 | —    |
| `tickets_status_updated_at_idx`      | 否   | `BTREE` | `status`, `updated_at`            |   23 | —    |
| `tickets_user_status_updated_at_idx` | 否   | `BTREE` | `user_id`, `status`, `updated_at` |   29 | —    |
| `tickets_user_updated_at_idx`        | 否   | `BTREE` | `user_id`, `updated_at`, `id`     |   29 | —    |

#### 外键约束

| 约束名                          | 字段          | 引用表        | 引用字段 | 更新规则   | 删除规则   |
| ------------------------------- | ------------- | ------------- | -------- | ---------- | ---------- |
| `fk_stage2_tickets_assignee_id` | `assignee_id` | `admin_users` | `id`     | `RESTRICT` | `SET NULL` |
| `fk_stage2_tickets_service_id`  | `service_id`  | `services`    | `id`     | `RESTRICT` | `SET NULL` |
| `fk_tickets_user_id`            | `user_id`     | `users`       | `id`     | `RESTRICT` | `RESTRICT` |

### 2.64 `ticket_delivery_rules`

- 类型：`BASE TABLE`
- 引擎：`InnoDB`
- 估算行数：`0`
- 数据大小：`16 KB`
- 索引大小：`48 KB`
- 自增值：`3`
- 排序规则：`utf8mb4_unicode_ci`
- 表注释：—

#### 字段

| 序号 | 字段                     | 类型                  | 可空 | 默认值     | 键  | 额外                                                    | 字符集  | 排序规则           | 注释 |
| ---: | ------------------------ | --------------------- | ---- | ---------- | --- | ------------------------------------------------------- | ------- | ------------------ | ---- |
|    1 | `id`                     | `bigint(20) unsigned` | 否   | —          | PRI | auto_increment                                          | —       | —                  | —    |
|    2 | `name`                   | `varchar(120)`        | 否   | —          | —   | —                                                       | utf8mb4 | utf8mb4_unicode_ci | —    |
|    3 | `department`             | `varchar(32)`         | 否   | —          | —   | —                                                       | utf8mb4 | utf8mb4_unicode_ci | —    |
|    4 | `supplier_id`            | `bigint(20) unsigned` | 是   | `NULL`     | MUL | —                                                       | —       | —                  | —    |
|    5 | `provider_key`           | `varchar(64)`         | 否   | —          | —   | —                                                       | utf8mb4 | utf8mb4_unicode_ci | —    |
|    6 | `product_scope_mode`     | `varchar(16)`         | 否   | `selected` | —   | —                                                       | utf8mb4 | utf8mb4_unicode_ci | —    |
|    7 | `upstream_department_id` | `varchar(64)`         | 否   | —          | —   | —                                                       | utf8mb4 | utf8mb4_unicode_ci | —    |
|    8 | `enabled`                | `tinyint(1)`          | 否   | `1`        | —   | —                                                       | —       | —                  | —    |
|    9 | `sync_admin_replies`     | `tinyint(1)`          | 否   | `0`        | —   | —                                                       | —       | —                  | —    |
|   10 | `auto_reply_enabled`     | `tinyint(1)`          | 否   | `0`        | —   | —                                                       | —       | —                  | —    |
|   11 | `auto_reply_content`     | `text`                | 是   | `NULL`     | —   | —                                                       | utf8mb4 | utf8mb4_unicode_ci | —    |
|   12 | `mask_keywords`          | `text`                | 是   | `NULL`     | —   | —                                                       | utf8mb4 | utf8mb4_unicode_ci | —    |
|   13 | `created_at`             | `timestamp`           | 是   | `NULL`     | —   | —                                                       | —       | —                  | —    |
|   14 | `updated_at`             | `timestamp`           | 是   | `NULL`     | —   | —                                                       | —       | —                  | —    |
|   15 | `supplier_scope_key`     | `bigint(20) unsigned` | 是   | `NULL`     | MUL | VIRTUAL GENERATED; generated: coalesce(`supplier_id`,0) | —       | —                  | —    |

#### 索引

| 索引名                              | 唯一 | 类型    | 字段                                                                     | 基数 | 注释 |
| ----------------------------------- | ---- | ------- | ------------------------------------------------------------------------ | ---: | ---- |
| `PRIMARY`                           | 是   | `BTREE` | `id`                                                                     |    0 | —    |
| `ticket_delivery_rule_match_idx`    | 否   | `BTREE` | `supplier_id`, `department`, `provider_key`, `enabled`                   |    0 | —    |
| `ticket_delivery_rule_scope_unique` | 是   | `BTREE` | `supplier_scope_key`, `department`, `provider_key`, `product_scope_mode` |    0 | —    |
| `ticket_delivery_rule_settings_idx` | 否   | `BTREE` | `supplier_id`, `department`, `provider_key`, `enabled`                   |    0 | —    |

#### 外键约束

| 约束名                                      | 字段          | 引用表      | 引用字段 | 更新规则   | 删除规则   |
| ------------------------------------------- | ------------- | ----------- | -------- | ---------- | ---------- |
| `ticket_delivery_rules_supplier_id_foreign` | `supplier_id` | `suppliers` | `id`     | `RESTRICT` | `SET NULL` |

### 2.65 `ticket_delivery_rule_products`

- 类型：`BASE TABLE`
- 引擎：`InnoDB`
- 估算行数：`0`
- 数据大小：`16 KB`
- 索引大小：`32 KB`
- 自增值：`1`
- 排序规则：`utf8mb4_unicode_ci`
- 表注释：—

#### 字段

| 序号 | 字段         | 类型                  | 可空 | 默认值 | 键  | 额外           | 字符集 | 排序规则 | 注释 |
| ---: | ------------ | --------------------- | ---- | ------ | --- | -------------- | ------ | -------- | ---- |
|    1 | `id`         | `bigint(20) unsigned` | 否   | —      | PRI | auto_increment | —      | —        | —    |
|    2 | `rule_id`    | `bigint(20) unsigned` | 否   | —      | MUL | —              | —      | —        | —    |
|    3 | `product_id` | `bigint(20) unsigned` | 否   | —      | MUL | —              | —      | —        | —    |

#### 索引

| 索引名                                                    | 唯一 | 类型    | 字段                    | 基数 | 注释 |
| --------------------------------------------------------- | ---- | ------- | ----------------------- | ---: | ---- |
| `PRIMARY`                                                 | 是   | `BTREE` | `id`                    |    0 | —    |
| `ticket_delivery_rule_products_product_id_foreign`        | 否   | `BTREE` | `product_id`            |    0 | —    |
| `ticket_delivery_rule_products_rule_id_product_id_unique` | 是   | `BTREE` | `rule_id`, `product_id` |    0 | —    |

#### 外键约束

| 约束名                                             | 字段         | 引用表                  | 引用字段 | 更新规则   | 删除规则  |
| -------------------------------------------------- | ------------ | ----------------------- | -------- | ---------- | --------- |
| `ticket_delivery_rule_products_product_id_foreign` | `product_id` | `products`              | `id`     | `RESTRICT` | `CASCADE` |
| `ticket_delivery_rule_products_rule_id_foreign`    | `rule_id`    | `ticket_delivery_rules` | `id`     | `RESTRICT` | `CASCADE` |

### 2.66 `ticket_replies`

- 类型：`BASE TABLE`
- 引擎：`InnoDB`
- 估算行数：`62`
- 数据大小：`16 KB`
- 索引大小：`32 KB`
- 自增值：`251`
- 排序规则：`utf8mb4_unicode_ci`
- 表注释：—

#### 字段

| 序号 | 字段             | 类型                  | 可空 | 默认值              | 键  | 额外           | 字符集  | 排序规则           | 注释 |
| ---: | ---------------- | --------------------- | ---- | ------------------- | --- | -------------- | ------- | ------------------ | ---- |
|    1 | `id`             | `bigint(20) unsigned` | 否   | —                   | PRI | auto_increment | —       | —                  | —    |
|    2 | `ticket_id`      | `bigint(20) unsigned` | 否   | —                   | MUL | —              | —       | —                  | —    |
|    3 | `user_id`        | `bigint(20) unsigned` | 否   | —                   | —   | —              | —       | —                  | —    |
|    4 | `content`        | `text`                | 否   | —                   | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|    5 | `is_staff`       | `tinyint(4)`          | 否   | `0`                 | —   | —              | —       | —                  | —    |
|    6 | `sender_type`    | `varchar(32)`         | 是   | `NULL`              | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|    7 | `sender_name`    | `varchar(120)`        | 是   | `NULL`              | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|    8 | `is_pre_reply`   | `tinyint(3) unsigned` | 否   | `0`                 | —   | —              | —       | —                  | —    |
|    9 | `attachments`    | `json`                | 是   | `NULL`              | —   | —              | —       | —                  | —    |
|   10 | `quote_reply_id` | `bigint(20) unsigned` | 是   | `NULL`              | MUL | —              | —       | —                  | —    |
|   11 | `recalled_at`    | `timestamp`           | 是   | `NULL`              | —   | —              | —       | —                  | —    |
|   12 | `created_at`     | `timestamp`           | 否   | `CURRENT_TIMESTAMP` | —   | —              | —       | —                  | —    |

#### 索引

| 索引名                                     | 唯一 | 类型    | 字段                            | 基数 | 注释 |
| ------------------------------------------ | ---- | ------- | ------------------------------- | ---: | ---- |
| `idx_stage2_ticket_replies_quote_reply_id` | 否   | `BTREE` | `quote_reply_id`                |    1 | —    |
| `PRIMARY`                                  | 是   | `BTREE` | `id`                            |   62 | —    |
| `ticket_replies_ticket_created_id_idx`     | 否   | `BTREE` | `ticket_id`, `created_at`, `id` |   62 | —    |

#### 外键约束

| 约束名                                    | 字段             | 引用表           | 引用字段 | 更新规则   | 删除规则   |
| ----------------------------------------- | ---------------- | ---------------- | -------- | ---------- | ---------- |
| `fk_stage2_ticket_replies_quote_reply_id` | `quote_reply_id` | `ticket_replies` | `id`     | `RESTRICT` | `SET NULL` |
| `fk_ticket_replies_ticket_id`             | `ticket_id`      | `tickets`        | `id`     | `RESTRICT` | `CASCADE`  |

### 2.67 `ticket_reply_deliveries`

- 类型：`BASE TABLE`
- 引擎：`InnoDB`
- 估算行数：`3`
- 数据大小：`16 KB`
- 索引大小：`48 KB`
- 自增值：`4`
- 排序规则：`utf8mb4_unicode_ci`
- 表注释：—

#### 字段

| 序号 | 字段              | 类型                  | 可空 | 默认值    | 键  | 额外           | 字符集  | 排序规则           | 注释 |
| ---: | ----------------- | --------------------- | ---- | --------- | --- | -------------- | ------- | ------------------ | ---- |
|    1 | `id`              | `bigint(20) unsigned` | 否   | —         | PRI | auto_increment | —       | —                  | —    |
|    2 | `ticket_reply_id` | `bigint(20) unsigned` | 否   | —         | UNI | —              | —       | —                  | —    |
|    3 | `direction`       | `varchar(16)`         | 否   | —         | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|    4 | `content_prefix`  | `varchar(64)`         | 是   | `NULL`    | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|    5 | `status`          | `varchar(32)`         | 否   | `pending` | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|    6 | `idempotency_key` | `varchar(160)`        | 否   | —         | UNI | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|    7 | `remote_event_id` | `varchar(160)`        | 是   | `NULL`    | MUL | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|    8 | `attempts`        | `int(10) unsigned`    | 否   | `0`       | —   | —              | —       | —                  | —    |
|    9 | `last_attempt_at` | `timestamp`           | 是   | `NULL`    | —   | —              | —       | —                  | —    |
|   10 | `last_error`      | `text`                | 是   | `NULL`    | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|   11 | `delivered_at`    | `timestamp`           | 是   | `NULL`    | —   | —              | —       | —                  | —    |
|   12 | `created_at`      | `timestamp`           | 是   | `NULL`    | —   | —              | —       | —                  | —    |
|   13 | `updated_at`      | `timestamp`           | 是   | `NULL`    | —   | —              | —       | —                  | —    |

#### 索引

| 索引名                                                     | 唯一 | 类型    | 字段                           | 基数 | 注释 |
| ---------------------------------------------------------- | ---- | ------- | ------------------------------ | ---: | ---- |
| `PRIMARY`                                                  | 是   | `BTREE` | `id`                           |    3 | —    |
| `ticket_reply_deliveries_idempotency_key_unique`           | 是   | `BTREE` | `idempotency_key`              |    3 | —    |
| `ticket_reply_deliveries_remote_event_id_direction_unique` | 是   | `BTREE` | `remote_event_id`, `direction` |    3 | —    |
| `ticket_reply_deliveries_ticket_reply_id_unique`           | 是   | `BTREE` | `ticket_reply_id`              |    3 | —    |

#### 外键约束

| 约束名                                            | 字段              | 引用表           | 引用字段 | 更新规则   | 删除规则  |
| ------------------------------------------------- | ----------------- | ---------------- | -------- | ---------- | --------- |
| `ticket_reply_deliveries_ticket_reply_id_foreign` | `ticket_reply_id` | `ticket_replies` | `id`     | `RESTRICT` | `CASCADE` |

### 2.68 `ticket_upstream_bindings`

- 类型：`BASE TABLE`
- 引擎：`InnoDB`
- 估算行数：`3`
- 数据大小：`16 KB`
- 索引大小：`48 KB`
- 自增值：`6`
- 排序规则：`utf8mb4_unicode_ci`
- 表注释：—

#### 字段

| 序号 | 字段                     | 类型                  | 可空 | 默认值    | 键  | 额外           | 字符集  | 排序规则           | 注释 |
| ---: | ------------------------ | --------------------- | ---- | --------- | --- | -------------- | ------- | ------------------ | ---- |
|    1 | `id`                     | `bigint(20) unsigned` | 否   | —         | PRI | auto_increment | —       | —                  | —    |
|    2 | `ticket_id`              | `bigint(20) unsigned` | 否   | —         | UNI | —              | —       | —                  | —    |
|    3 | `provider_key`           | `varchar(64)`         | 否   | —         | MUL | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|    4 | `supplier_id`            | `bigint(20) unsigned` | 是   | `NULL`    | —   | —              | —       | —                  | —    |
|    5 | `upstream_department_id` | `varchar(64)`         | 否   | —         | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|    6 | `upstream_service_id`    | `varchar(128)`        | 否   | —         | MUL | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|    7 | `upstream_ticket_id`     | `varchar(128)`        | 是   | `NULL`    | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|    8 | `status`                 | `varchar(32)`         | 否   | `pending` | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|    9 | `attempts`               | `int(10) unsigned`    | 否   | `0`       | —   | —              | —       | —                  | —    |
|   10 | `last_error`             | `text`                | 是   | `NULL`    | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|   11 | `last_attempt_at`        | `timestamp`           | 是   | `NULL`    | —   | —              | —       | —                  | —    |
|   12 | `delivered_at`           | `timestamp`           | 是   | `NULL`    | —   | —              | —       | —                  | —    |
|   13 | `created_at`             | `timestamp`           | 是   | `NULL`    | —   | —              | —       | —                  | —    |
|   14 | `updated_at`             | `timestamp`           | 是   | `NULL`    | —   | —              | —       | —                  | —    |

#### 索引

| 索引名                                                            | 唯一 | 类型    | 字段                                        | 基数 | 注释 |
| ----------------------------------------------------------------- | ---- | ------- | ------------------------------------------- | ---: | ---- |
| `PRIMARY`                                                         | 是   | `BTREE` | `id`                                        |    3 | —    |
| `ticket_upstream_bindings_provider_key_upstream_ticket_id_unique` | 是   | `BTREE` | `provider_key`, `upstream_ticket_id`        |    3 | —    |
| `ticket_upstream_bindings_ticket_id_unique`                       | 是   | `BTREE` | `ticket_id`                                 |    3 | —    |
| `ticket_upstream_lookup_idx`                                      | 否   | `BTREE` | `upstream_service_id`, `upstream_ticket_id` |    3 | —    |

#### 外键约束

| 约束名                                       | 字段        | 引用表    | 引用字段 | 更新规则   | 删除规则  |
| -------------------------------------------- | ----------- | --------- | -------- | ---------- | --------- |
| `ticket_upstream_bindings_ticket_id_foreign` | `ticket_id` | `tickets` | `id`     | `RESTRICT` | `CASCADE` |

### 2.69 `ticket_upstream_delivery_logs`

- 类型：`BASE TABLE`
- 引擎：`InnoDB`
- 估算行数：`7`
- 数据大小：`16 KB`
- 索引大小：`80 KB`
- 自增值：`12`
- 排序规则：`utf8mb4_unicode_ci`
- 表注释：—

#### 字段

| 序号 | 字段              | 类型                   | 可空 | 默认值              | 键  | 额外                        | 字符集  | 排序规则           | 注释 |
| ---: | ----------------- | ---------------------- | ---- | ------------------- | --- | --------------------------- | ------- | ------------------ | ---- |
|    1 | `id`              | `bigint(20) unsigned`  | 否   | —                   | PRI | auto_increment              | —       | —                  | —    |
|    2 | `ticket_id`       | `bigint(20) unsigned`  | 否   | —                   | MUL | —                           | —       | —                  | —    |
|    3 | `ticket_reply_id` | `bigint(20) unsigned`  | 是   | `NULL`              | MUL | —                           | —       | —                  | —    |
|    4 | `binding_id`      | `bigint(20) unsigned`  | 是   | `NULL`              | MUL | —                           | —       | —                  | —    |
|    5 | `delivery_id`     | `bigint(20) unsigned`  | 是   | `NULL`              | MUL | —                           | —       | —                  | —    |
|    6 | `direction`       | `varchar(16)`          | 否   | `outbound`          | —   | —                           | utf8mb4 | utf8mb4_unicode_ci | —    |
|    7 | `operation`       | `varchar(32)`          | 否   | —                   | —   | —                           | utf8mb4 | utf8mb4_unicode_ci | —    |
|    8 | `event`           | `varchar(32)`          | 否   | —                   | —   | —                           | utf8mb4 | utf8mb4_unicode_ci | —    |
|    9 | `status`          | `varchar(32)`          | 否   | —                   | MUL | —                           | utf8mb4 | utf8mb4_unicode_ci | —    |
|   10 | `reason_code`     | `varchar(64)`          | 是   | `NULL`              | —   | —                           | utf8mb4 | utf8mb4_unicode_ci | —    |
|   11 | `provider_key`    | `varchar(64)`          | 是   | `NULL`              | —   | —                           | utf8mb4 | utf8mb4_unicode_ci | —    |
|   12 | `supplier_id`     | `bigint(20) unsigned`  | 是   | `NULL`              | —   | —                           | —       | —                  | —    |
|   13 | `attempt`         | `int(10) unsigned`     | 是   | `NULL`              | —   | —                           | —       | —                  | —    |
|   14 | `http_status`     | `smallint(5) unsigned` | 是   | `NULL`              | —   | —                           | —       | —                  | —    |
|   15 | `duration_ms`     | `int(10) unsigned`     | 是   | `NULL`              | —   | —                           | —       | —                  | —    |
|   16 | `message`         | `text`                 | 是   | `NULL`              | —   | —                           | utf8mb4 | utf8mb4_unicode_ci | —    |
|   17 | `occurred_at`     | `timestamp`            | 否   | `CURRENT_TIMESTAMP` | —   | on update CURRENT_TIMESTAMP | —       | —                  | —    |
|   18 | `created_at`      | `timestamp`            | 是   | `NULL`              | —   | —                           | —       | —                  | —    |
|   19 | `updated_at`      | `timestamp`            | 是   | `NULL`              | —   | —                           | —       | —                  | —    |

#### 索引

| 索引名                                              | 唯一 | 类型    | 字段                             | 基数 | 注释 |
| --------------------------------------------------- | ---- | ------- | -------------------------------- | ---: | ---- |
| `PRIMARY`                                           | 是   | `BTREE` | `id`                             |    7 | —    |
| `ticket_upstream_delivery_logs_delivery_id_foreign` | 否   | `BTREE` | `delivery_id`                    |    1 | —    |
| `ticket_upstream_log_binding_time_idx`              | 否   | `BTREE` | `binding_id`, `occurred_at`      |    6 | —    |
| `ticket_upstream_log_reply_time_idx`                | 否   | `BTREE` | `ticket_reply_id`, `occurred_at` |    7 | —    |
| `ticket_upstream_log_status_time_idx`               | 否   | `BTREE` | `status`, `occurred_at`          |    6 | —    |
| `ticket_upstream_log_ticket_time_idx`               | 否   | `BTREE` | `ticket_id`, `occurred_at`       |    7 | —    |

#### 外键约束

| 约束名                                                  | 字段              | 引用表                     | 引用字段 | 更新规则   | 删除规则   |
| ------------------------------------------------------- | ----------------- | -------------------------- | -------- | ---------- | ---------- |
| `ticket_upstream_delivery_logs_binding_id_foreign`      | `binding_id`      | `ticket_upstream_bindings` | `id`     | `RESTRICT` | `SET NULL` |
| `ticket_upstream_delivery_logs_delivery_id_foreign`     | `delivery_id`     | `ticket_reply_deliveries`  | `id`     | `RESTRICT` | `SET NULL` |
| `ticket_upstream_delivery_logs_ticket_id_foreign`       | `ticket_id`       | `tickets`                  | `id`     | `RESTRICT` | `CASCADE`  |
| `ticket_upstream_delivery_logs_ticket_reply_id_foreign` | `ticket_reply_id` | `ticket_replies`           | `id`     | `RESTRICT` | `SET NULL` |

### 2.70 `users`

- 类型：`BASE TABLE`
- 引擎：`InnoDB`
- 估算行数：`121`
- 数据大小：`64 KB`
- 索引大小：`176 KB`
- 自增值：`988233`
- 排序规则：`utf8mb4_unicode_ci`
- 表注释：—

#### 字段

| 序号 | 字段                      | 类型                  | 可空 | 默认值   | 键  | 额外           | 字符集  | 排序规则           | 注释                                  |
| ---: | ------------------------- | --------------------- | ---- | -------- | --- | -------------- | ------- | ------------------ | ------------------------------------- |
|    1 | `id`                      | `bigint(20) unsigned` | 否   | —        | PRI | auto_increment | —       | —                  | —                                     |
|    2 | `email`                   | `varchar(100)`        | 是   | `NULL`   | UNI | —              | utf8mb4 | utf8mb4_unicode_ci | —                                     |
|    3 | `password`                | `varchar(255)`        | 否   | —        | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —                                     |
|    4 | `nickname`                | `varchar(50)`         | 否   | 空字符串 | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —                                     |
|    5 | `phone`                   | `varchar(20)`         | 是   | `NULL`   | UNI | —              | utf8mb4 | utf8mb4_unicode_ci | —                                     |
|    6 | `company`                 | `varchar(100)`        | 否   | 空字符串 | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —                                     |
|    7 | `qq`                      | `varchar(30)`         | 否   | 空字符串 | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —                                     |
|    8 | `alipay_real_name`        | `varchar(80)`         | 否   | 空字符串 | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —                                     |
|    9 | `alipay_account`          | `varchar(20)`         | 否   | 空字符串 | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —                                     |
|   10 | `referral_code`           | `varchar(24)`         | 是   | `NULL`   | UNI | —              | utf8mb4 | utf8mb4_unicode_ci | —                                     |
|   11 | `referrer_user_id`        | `bigint(20) unsigned` | 是   | `NULL`   | MUL | —              | —       | —                  | —                                     |
|   12 | `member_level_id`         | `bigint(20) unsigned` | 是   | `NULL`   | MUL | —              | —       | —                  | —                                     |
|   13 | `total_sales_amount`      | `decimal(12,2)`       | 否   | `0.00`   | —   | —              | —       | —                  | —                                     |
|   14 | `referred_at`             | `timestamp`           | 是   | `NULL`   | —   | —              | —       | —                  | —                                     |
|   15 | `status`                  | `tinyint(4)`          | 否   | `1`      | MUL | —              | —       | —                  | 0=禁用 1=正常                         |
|   16 | `login_email_alert`       | `tinyint(4)`          | 否   | `1`      | —   | —              | —       | —                  | 登录邮件提醒 0关闭 1开启              |
|   17 | `login_notify`            | `tinyint(1)`          | 否   | `1`      | —   | —              | —       | —                  | 账号登录提醒 0关闭 1开启              |
|   18 | `login_location_alert`    | `tinyint(1)`          | 否   | `1`      | —   | —              | —       | —                  | 异地登录提醒 0关闭 1开启              |
|   19 | `password_change_alert`   | `tinyint(1)`          | 否   | `1`      | —   | —              | —       | —                  | 密码变更提醒 0关闭 1开启              |
|   20 | `phone_change_alert`      | `tinyint(1)`          | 否   | `1`      | —   | —              | —       | —                  | 手机号变更提醒 0关闭 1开启            |
|   21 | `email_change_alert`      | `tinyint(1)`          | 否   | `1`      | —   | —              | —       | —                  | 邮箱变更提醒 0关闭 1开启              |
|   22 | `marketing_alert`         | `tinyint(1)`          | 否   | `0`      | —   | —              | —       | —                  | 营销提醒接收 0关闭 1开启              |
|   23 | `is_verified`             | `tinyint(4)`          | 否   | `0`      | MUL | —              | —       | —                  | 0=未认证 1=已认证                     |
|   24 | `real_name`               | `varchar(50)`         | 否   | 空字符串 | —   | —              | utf8mb4 | utf8mb4_unicode_ci | 真实姓名                              |
|   25 | `id_card`                 | `varchar(512)`        | 否   | 空字符串 | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —                                     |
|   26 | `verification_status`     | `tinyint(4)`          | 否   | `0`      | MUL | —              | —       | —                  | 0=未认证 1=认证中 2=已认证 3=认证失败 |
|   27 | `verification_message`    | `varchar(255)`        | 否   | 空字符串 | —   | —              | utf8mb4 | utf8mb4_unicode_ci | 实名认证状态描述                      |
|   28 | `verification_certify_id` | `varchar(100)`        | 是   | `NULL`   | MUL | —              | utf8mb4 | utf8mb4_unicode_ci | 实名认证平台 certify_id               |
|   29 | `verified_at`             | `timestamp`           | 是   | `NULL`   | —   | —              | —       | —                  | 实名认证通过时间                      |
|   30 | `last_login_ip`           | `varchar(45)`         | 是   | `NULL`   | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —                                     |
|   31 | `last_login_at`           | `timestamp`           | 是   | `NULL`   | —   | —              | —       | —                  | —                                     |
|   32 | `admin_note`              | `text`                | 是   | `NULL`   | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —                                     |
|   33 | `created_at`              | `timestamp`           | 是   | `NULL`   | MUL | —              | —       | —                  | —                                     |
|   34 | `updated_at`              | `timestamp`           | 是   | `NULL`   | —   | —              | —       | —                  | —                                     |
|   35 | `deleted_at`              | `timestamp`           | 是   | `NULL`   | —   | —              | —       | —                  | —                                     |
|   36 | `agent_group_id`          | `bigint(20) unsigned` | 是   | `NULL`   | —   | —              | —       | —                  | —                                     |
|   37 | `api_open`                | `tinyint(1)`          | 否   | `0`      | —   | —              | —       | —                  | —                                     |
|   38 | `api_username`            | `varchar(64)`         | 是   | `NULL`   | UNI | —              | utf8mb4 | utf8mb4_unicode_ci | —                                     |
|   39 | `api_password`            | `varchar(255)`        | 是   | `NULL`   | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —                                     |

#### 索引

| 索引名                              | 唯一 | 类型    | 字段                                       | 基数 | 注释 |
| ----------------------------------- | ---- | ------- | ------------------------------------------ | ---: | ---- |
| `PRIMARY`                           | 是   | `BTREE` | `id`                                       |  121 | —    |
| `users_api_username_unique`         | 是   | `BTREE` | `api_username`                             |    1 | —    |
| `users_created_at_idx`              | 否   | `BTREE` | `created_at`                               |   66 | —    |
| `users_email_unique`                | 是   | `BTREE` | `email`                                    |  121 | —    |
| `users_member_level_id_index`       | 否   | `BTREE` | `member_level_id`                          |    1 | —    |
| `users_phone_unique`                | 是   | `BTREE` | `phone`                                    |  121 | —    |
| `users_referral_code_unique`        | 是   | `BTREE` | `referral_code`                            |    5 | —    |
| `users_referrer_user_id_index`      | 否   | `BTREE` | `referrer_user_id`                         |    1 | —    |
| `users_status_id_idx`               | 否   | `BTREE` | `status`, `id`                             |  121 | —    |
| `users_verification_certify_id_idx` | 否   | `BTREE` | `verification_certify_id`                  |   10 | —    |
| `users_verification_mix_idx`        | 否   | `BTREE` | `is_verified`, `verification_status`, `id` |  121 | —    |
| `users_verification_status_id_idx`  | 否   | `BTREE` | `verification_status`, `id`                |  121 | —    |

#### 外键约束

| 约束名                             | 字段               | 引用表          | 引用字段 | 更新规则   | 删除规则   |
| ---------------------------------- | ------------------ | --------------- | -------- | ---------- | ---------- |
| `fk_stage2_users_member_level_id`  | `member_level_id`  | `member_levels` | `id`     | `RESTRICT` | `SET NULL` |
| `fk_stage2_users_referrer_user_id` | `referrer_user_id` | `users`         | `id`     | `RESTRICT` | `SET NULL` |

### 2.71 `user_accounts`

- 类型：`BASE TABLE`
- 引擎：`InnoDB`
- 估算行数：`11`
- 数据大小：`16 KB`
- 索引大小：`0 B`
- 自增值：—
- 排序规则：`utf8mb4_unicode_ci`
- 表注释：用户账户余额源表，集中承载现金余额、授信和推荐奖励余额

#### 字段

| 序号 | 字段                                  | 类型                  | 可空 | 默认值 | 键  | 额外 | 字符集 | 排序规则 | 注释                     |
| ---: | ------------------------------------- | --------------------- | ---- | ------ | --- | ---- | ------ | -------- | ------------------------ |
|    1 | `user_id`                             | `bigint(20) unsigned` | 否   | —      | PRI | —    | —      | —        | 用户ID，同时作为账户主键 |
|    2 | `cash_balance`                        | `decimal(12,2)`       | 否   | `0.00` | —   | —    | —      | —        | 现金余额                 |
|    3 | `credit_limit`                        | `decimal(12,2)`       | 否   | `0.00` | —   | —    | —      | —        | 授信额度                 |
|    4 | `referral_frozen_balance`             | `decimal(12,2)`       | 否   | `0.00` | —   | —    | —      | —        | 冻结中的推荐奖励余额     |
|    5 | `referral_available_balance`          | `decimal(12,2)`       | 否   | `0.00` | —   | —    | —      | —        | 可用推荐奖励余额         |
|    6 | `referral_pending_withdrawal_balance` | `decimal(12,2)`       | 否   | `0.00` | —   | —    | —      | —        | 提现审核中的推荐奖励余额 |
|    7 | `referral_withdrawn_balance`          | `decimal(12,2)`       | 否   | `0.00` | —   | —    | —      | —        | 已提现推荐奖励累计金额   |
|    8 | `version`                             | `int(10) unsigned`    | 否   | `0`    | —   | —    | —      | —        | 乐观锁版本号             |
|    9 | `created_at`                          | `timestamp`           | 是   | `NULL` | —   | —    | —      | —        | 创建时间                 |
|   10 | `updated_at`                          | `timestamp`           | 是   | `NULL` | —   | —    | —      | —        | 更新时间                 |

#### 索引

| 索引名    | 唯一 | 类型    | 字段      | 基数 | 注释 |
| --------- | ---- | ------- | --------- | ---: | ---- |
| `PRIMARY` | 是   | `BTREE` | `user_id` |   11 | —    |

#### 外键约束

| 约束名                     | 字段      | 引用表  | 引用字段 | 更新规则   | 删除规则   |
| -------------------------- | --------- | ------- | -------- | ---------- | ---------- |
| `fk_user_accounts_user_id` | `user_id` | `users` | `id`     | `RESTRICT` | `RESTRICT` |

### 2.72 `user_coupons`

- 类型：`BASE TABLE`
- 引擎：`InnoDB`
- 估算行数：`10`
- 数据大小：`16 KB`
- 索引大小：`64 KB`
- 自增值：`100`
- 排序规则：`utf8mb4_unicode_ci`
- 表注释：—

#### 字段

| 序号 | 字段             | 类型                  | 可空 | 默认值  | 键  | 额外           | 字符集  | 排序规则           | 注释                                 |
| ---: | ---------------- | --------------------- | ---- | ------- | --- | -------------- | ------- | ------------------ | ------------------------------------ |
|    1 | `id`             | `bigint(20) unsigned` | 否   | —       | PRI | auto_increment | —       | —                  | —                                    |
|    2 | `uid`            | `varchar(32)`         | 是   | `NULL`  | UNI | —              | utf8mb4 | utf8mb4_unicode_ci | —                                    |
|    3 | `coupon_id`      | `bigint(20) unsigned` | 否   | —       | MUL | —              | —       | —                  | —                                    |
|    4 | `user_id`        | `bigint(20) unsigned` | 否   | —       | MUL | —              | —       | —                  | —                                    |
|    5 | `receive_type`   | `varchar(20)`         | 否   | `claim` | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —                                    |
|    6 | `status`         | `tinyint(4)`          | 否   | `1`     | —   | —              | —       | —                  | 优惠券状态：1=持有 2=已使用 3=已回收 |
|    7 | `claimed_at`     | `timestamp`           | 是   | `NULL`  | —   | —              | —       | —                  | —                                    |
|    8 | `used_at`        | `timestamp`           | 是   | `NULL`  | —   | —              | —       | —                  | —                                    |
|    9 | `revoked_at`     | `timestamp`           | 是   | `NULL`  | —   | —              | —       | —                  | —                                    |
|   10 | `reserved_until` | `timestamp`           | 是   | `NULL`  | —   | —              | —       | —                  | —                                    |
|   11 | `granted_at`     | `timestamp`           | 是   | `NULL`  | —   | —              | —       | —                  | —                                    |
|   12 | `last_used_at`   | `timestamp`           | 是   | `NULL`  | —   | —              | —       | —                  | —                                    |
|   13 | `remark`         | `varchar(255)`        | 是   | `NULL`  | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —                                    |
|   14 | `operator`       | `varchar(100)`        | 是   | `NULL`  | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —                                    |
|   15 | `trace_id`       | `varchar(100)`        | 是   | `NULL`  | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —                                    |
|   16 | `created_at`     | `timestamp`           | 是   | `NULL`  | —   | —              | —       | —                  | —                                    |
|   17 | `updated_at`     | `timestamp`           | 是   | `NULL`  | —   | —              | —       | —                  | —                                    |

#### 索引

| 索引名                            | 唯一 | 类型    | 字段                   | 基数 | 注释 |
| --------------------------------- | ---- | ------- | ---------------------- | ---: | ---- |
| `PRIMARY`                         | 是   | `BTREE` | `id`                   |   10 | —    |
| `user_coupons_coupon_status_idx`  | 否   | `BTREE` | `coupon_id`, `status`  |   10 | —    |
| `user_coupons_coupon_user_unique` | 是   | `BTREE` | `coupon_id`, `user_id` |   10 | —    |
| `user_coupons_uid_unique`         | 是   | `BTREE` | `uid`                  |   10 | —    |
| `user_coupons_user_status_idx`    | 否   | `BTREE` | `user_id`, `status`    |   10 | —    |

#### 外键约束

| 约束名                           | 字段        | 引用表    | 引用字段 | 更新规则   | 删除规则   |
| -------------------------------- | ----------- | --------- | -------- | ---------- | ---------- |
| `fk_stage2_user_coupons_user_id` | `user_id`   | `users`   | `id`     | `RESTRICT` | `RESTRICT` |
| `fk_user_coupons_coupon_id`      | `coupon_id` | `coupons` | `id`     | `RESTRICT` | `RESTRICT` |

### 2.73 `user_notifications`

- 类型：`BASE TABLE`
- 引擎：`InnoDB`
- 估算行数：`10`
- 数据大小：`16 KB`
- 索引大小：`48 KB`
- 自增值：`138`
- 排序规则：`utf8mb4_unicode_ci`
- 表注释：—

#### 字段

| 序号 | 字段         | 类型                  | 可空 | 默认值 | 键  | 额外           | 字符集  | 排序规则           | 注释                                                                   |
| ---: | ------------ | --------------------- | ---- | ------ | --- | -------------- | ------- | ------------------ | ---------------------------------------------------------------------- |
|    1 | `id`         | `bigint(20) unsigned` | 否   | —      | PRI | auto_increment | —       | —                  | —                                                                      |
|    2 | `user_id`    | `bigint(20) unsigned` | 否   | —      | MUL | —              | —       | —                  | —                                                                      |
|    3 | `type`       | `varchar(50)`         | 否   | —      | MUL | —              | utf8mb4 | utf8mb4_unicode_ci | 消息类型：order_paid/service_renew_reminder/service_expire_reminder 等 |
|    4 | `title`      | `varchar(191)`        | 否   | —      | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —                                                                      |
|    5 | `content`    | `text`                | 是   | `NULL` | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —                                                                      |
|    6 | `link`       | `varchar(255)`        | 是   | `NULL` | —   | —              | utf8mb4 | utf8mb4_unicode_ci | 点击跳转的前端路由                                                     |
|    7 | `data`       | `json`                | 是   | `NULL` | —   | —              | —       | —                  | 附加业务数据                                                           |
|    8 | `read_at`    | `timestamp`           | 是   | `NULL` | —   | —              | —       | —                  | —                                                                      |
|    9 | `created_at` | `timestamp`           | 是   | `NULL` | —   | —              | —       | —                  | —                                                                      |
|   10 | `updated_at` | `timestamp`           | 是   | `NULL` | —   | —              | —       | —                  | —                                                                      |

#### 索引

| 索引名                                        | 唯一 | 类型    | 字段                    | 基数 | 注释 |
| --------------------------------------------- | ---- | ------- | ----------------------- | ---: | ---- |
| `PRIMARY`                                     | 是   | `BTREE` | `id`                    |   10 | —    |
| `user_notifications_type_index`               | 否   | `BTREE` | `type`                  |    5 | —    |
| `user_notifications_user_id_created_at_index` | 否   | `BTREE` | `user_id`, `created_at` |   10 | —    |
| `user_notifications_user_id_read_at_index`    | 否   | `BTREE` | `user_id`, `read_at`    |   10 | —    |

#### 外键约束

| 约束名                                 | 字段      | 引用表  | 引用字段 | 更新规则   | 删除规则  |
| -------------------------------------- | --------- | ------- | -------- | ---------- | --------- |
| `fk_stage2_user_notifications_user_id` | `user_id` | `users` | `id`     | `RESTRICT` | `CASCADE` |

### 2.74 `verification_histories`

- 类型：`BASE TABLE`
- 引擎：`InnoDB`
- 估算行数：`10`
- 数据大小：`16 KB`
- 索引大小：`48 KB`
- 自增值：`109`
- 排序规则：`utf8mb4_unicode_ci`
- 表注释：—

#### 字段

| 序号 | 字段                      | 类型                  | 可空 | 默认值              | 键  | 额外           | 字符集  | 排序规则           | 注释                         |
| ---: | ------------------------- | --------------------- | ---- | ------------------- | --- | -------------- | ------- | ------------------ | ---------------------------- |
|    1 | `id`                      | `bigint(20) unsigned` | 否   | —                   | PRI | auto_increment | —       | —                  | —                            |
|    2 | `user_id`                 | `bigint(20) unsigned` | 否   | —                   | MUL | —              | —       | —                  | —                            |
|    3 | `real_name`               | `varchar(50)`         | 否   | 空字符串            | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —                            |
|    4 | `id_card`                 | `varchar(512)`        | 否   | 空字符串            | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —                            |
|    5 | `verification_status`     | `tinyint(4)`          | 否   | `1`                 | —   | —              | —       | —                  | 1=认证中 2=已认证 3=认证失败 |
|    6 | `verification_message`    | `varchar(255)`        | 否   | 空字符串            | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —                            |
|    7 | `verification_certify_id` | `varchar(100)`        | 是   | `NULL`              | MUL | —              | utf8mb4 | utf8mb4_unicode_ci | —                            |
|    8 | `verification_biz_code`   | `varchar(30)`         | 否   | `FACE`              | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —                            |
|    9 | `verification_type`       | `varchar(20)`         | 否   | `personal`          | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —                            |
|   10 | `submitted_at`            | `timestamp`           | 否   | `CURRENT_TIMESTAMP` | —   | —              | —       | —                  | —                            |
|   11 | `completed_at`            | `timestamp`           | 是   | `NULL`              | —   | —              | —       | —                  | —                            |
|   12 | `created_at`              | `timestamp`           | 是   | `NULL`              | —   | —              | —       | —                  | —                            |
|   13 | `updated_at`              | `timestamp`           | 是   | `NULL`              | —   | —              | —       | —                  | —                            |

#### 索引

| 索引名                                                 | 唯一 | 类型    | 字段                      | 基数 | 注释 |
| ------------------------------------------------------ | ---- | ------- | ------------------------- | ---: | ---- |
| `PRIMARY`                                              | 是   | `BTREE` | `id`                      |   10 | —    |
| `verification_histories_user_id_id_idx`                | 否   | `BTREE` | `user_id`, `id`           |   10 | —    |
| `verification_histories_user_id_submitted_at_index`    | 否   | `BTREE` | `user_id`, `submitted_at` |    8 | —    |
| `verification_histories_verification_certify_id_index` | 否   | `BTREE` | `verification_certify_id` |    8 | —    |

#### 外键约束

| 约束名                                     | 字段      | 引用表  | 引用字段 | 更新规则   | 删除规则   |
| ------------------------------------------ | --------- | ------- | -------- | ---------- | ---------- |
| `fk_stage2_verification_histories_user_id` | `user_id` | `users` | `id`     | `RESTRICT` | `RESTRICT` |

### 2.75 `zjmf_upstream_bindings`

- 类型：`BASE TABLE`
- 引擎：`MyISAM`
- 估算行数：`0`
- 数据大小：`0 B`
- 索引大小：`1 KB`
- 自增值：`1`
- 排序规则：`utf8mb4_unicode_ci`
- 表注释：—

#### 字段

| 序号 | 字段               | 类型                  | 可空 | 默认值   | 键  | 额外           | 字符集  | 排序规则           | 注释 |
| ---: | ------------------ | --------------------- | ---- | -------- | --- | -------------- | ------- | ------------------ | ---- |
|    1 | `id`               | `bigint(20) unsigned` | 否   | —        | PRI | auto_increment | —       | —                  | —    |
|    2 | `user_id`          | `bigint(20) unsigned` | 否   | —        | MUL | —              | —       | —                  | —    |
|    3 | `invoice_id`       | `bigint(20) unsigned` | 是   | `NULL`   | MUL | —              | —       | —                  | —    |
|    4 | `service_id`       | `bigint(20) unsigned` | 是   | `NULL`   | MUL | —              | —       | —                  | —    |
|    5 | `downstream_url`   | `varchar(255)`        | 否   | 空字符串 | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|    6 | `downstream_token` | `varchar(64)`         | 否   | 空字符串 | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|    7 | `downstream_id`    | `bigint(20) unsigned` | 否   | `0`      | —   | —              | —       | —                  | —    |
|    8 | `domain`           | `varchar(255)`        | 否   | 空字符串 | —   | —              | utf8mb4 | utf8mb4_unicode_ci | —    |
|    9 | `payload`          | `json`                | 是   | `NULL`   | —   | —              | —       | —                  | —    |
|   10 | `created_at`       | `timestamp`           | 是   | `NULL`   | —   | —              | —       | —                  | —    |
|   11 | `updated_at`       | `timestamp`           | 是   | `NULL`   | —   | —              | —       | —                  | —    |

#### 索引

| 索引名                                    | 唯一 | 类型    | 字段         | 基数 | 注释 |
| ----------------------------------------- | ---- | ------- | ------------ | ---: | ---- |
| `PRIMARY`                                 | 是   | `BTREE` | `id`         |    0 | —    |
| `zjmf_upstream_bindings_invoice_id_index` | 否   | `BTREE` | `invoice_id` |    0 | —    |
| `zjmf_upstream_bindings_service_id_index` | 否   | `BTREE` | `service_id` |    0 | —    |
| `zjmf_upstream_bindings_user_id_index`    | 否   | `BTREE` | `user_id`    |    0 | —    |

#### 外键约束

无数据库级外键约束。
