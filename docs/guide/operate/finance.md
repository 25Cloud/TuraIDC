# 财务与账单

本文面向运营 TuraIDC 的站长与财务人员，说明资金从哪进、账单怎么生成、退款走哪条通道、账对不上时按什么顺序查。所有金额与状态判定都以服务端代码为准，后台页面只是这些规则的入口。

## 1. 三层账务模型

系统把「钱」拆成三张表，后面所有操作都在这个结构上做。

余额表 `user_accounts` 以 `user_id` 为主键，回答「这个人现在有多少钱」。字段：

- `cash_balance`（现金余额）、`credit_limit`（信用额度）；
- `referral_frozen_balance`、`referral_available_balance`、`referral_pending_withdrawal_balance`、`referral_withdrawn_balance`（分销奖励的四个子账户，见[用户与分销](users.md)）；
- 以上金额默认值全部是 `0.00`。

表上还有 `version` 做乐观锁：并发写入版本不一致时直接报 40900「账户数据已被并发修改，请重试」，不会静默覆盖。

流水表 `account_transactions` 回答「每一笔变动从哪来、变动后是多少」。

- `account_type` 取 `cash`、`referral_frozen`、`referral_available`、`referral_pending_withdrawal`、`referral_withdrawn`；
- `change_amount` 带正负号，`balance_after` 是该笔之后的余额快照；
- `source_type` / `source_id` 与 `origin_type` / `origin_id` 指回来源单据，`trace_id` 贯穿同一笔业务的全部记录。

`event_type` 在读取时会被归一，筛选时按归一后的值理解：`consume` → `invoice_payment`、`refund` → `invoice_refund`、`admin_deduct` 与 `deduct` → `manual_deduction`、`adjust` → `system_adjustment`、`referral_withdraw_approved` → `referral_credit_cash`。中文标签依次是：账单支付、账单退款、充值到账、手动充值、手动扣款、奖励转余额、系统调账、实名认证费用。

账单表 `invoices` 回答「该收多少、收了多少、退了多少」，是唯一的对外财务凭证。核心字段：`amount`（应付额）、`discount`（优惠券抵扣）、`paid_amount`（已付）、`refund_amount`（已退）、`due_date`（账期）、`status`。

`Order`（订单）只是开通侧的内部投影，用户在控制台看到的是账单；充值、扣款、返利三类账单根本没有对应的订单。

**查看入口**：管理端「用户 → 用户列表 → 用户详情 → `资金流水`」标签页，列为 时间、类型、变动、变动后余额、备注、操作人；相邻的「账单」标签页按状态与类型筛选该用户的账单明细。用户侧在控制台的余额流水页查看同一批数据。

## 2. 账单的生成与状态

**类型与状态**：

- **类型** `invoices.type`：`new`（新购）、`renew`（续费）、`recharge`（充值）、`upgrade`（附加配置）、`deduction`（扣款）、`refund`（退款红字账单）、`referral_credit`（推荐奖励）、`manual`（手工账单）。历史值 `normal` 等同 `new`。
- **状态** `invoices.status`：`0` 未付、`1` 已付、`2` 已取消、`3` 逾期、`5` 已退款、`6` 部分退款。后台状态标签显示为 待支付 / 已支付 / 已取消 / 已逾期 / 已退款 / 部分退款。
- 逾期不等于作废：`3` 只是标记，账单仍可支付，也可以被人工取消。

**生成与金额口径**：

- 用户下单时由 `CheckoutService::create` 直接创建账单，随后才补一条 `projection_type = provisioning` 的投影订单。
- 默认账期是下单后 **7 天**（`due_date`）。
- **金额一律服务端重算**：下单请求必须带报价凭证 `quote_token`，服务端拿它重新校验折后金额与券后应付额。`amount` 存的是券后应付额，代理折扣在下单报价阶段已经算进 `amount`；原价、代理折扣率、成本金额都写进 `config_pricing_snapshot` 快照，事后可回溯。
- 账单有 `(user_id, idempotency_key)` 唯一索引，重复提交不会重复建单。

管理端入口：

| 页面         | 路径                                       | 权限             |
| ------------ | ------------------------------------------ | ---------------- |
| 全部订单     | `/admin/finance/orders`                    | `order.list`     |
| 普通订单     | `/admin/finance/orders/normal`             | `order.list`     |
| 续费订单     | `/admin/finance/orders/renewals`           | `order.list`     |
| 附加配置订单 | `/admin/finance/orders/upgrades`           | `order.list`     |
| 账单管理     | `/admin/finance/invoices`                  | `invoice.list`   |
| 充值管理     | `/admin/finance/recharges`                 | `invoice.list`   |
| 新客户       | `/admin/finance/new-customers`（菜单隐藏） | `finance.report` |

账单管理页可按关键词、类型、状态、起始日期、结束日期筛选，行内只有「详情」和「取消」两个动作。

**取消**仅在账单状态为 `0` 或 `3`、且当前管理员拥有 `invoice.manage` 时出现。取消会：把该账单下待支付的支付单置为失败、取消关联的投影订单、释放占用的优惠券、按库存预扣快照回补库存。取消是改状态，不是删除。

## 3. 余额与充值

**充值链路**：用户控制台选择支付渠道 → 服务端创建一条待支付的支付记录（此时 `invoice_id` 为空）→ 用户完成付款 → 网关回调或主动查询 → 校验金额 → 入账并补建 `recharge` 类型账单、写充值流水与充值记录。

入账后的落点：`user_accounts.cash_balance` 增加、`account_transactions` 写一条 `event_type = recharge` 的流水、`recharge_records` 写一条充值记录（`scene` 为 `new_purchase` 或 `renewal`，`entry_type = third_party_payment`）。

- **支付会话有效期 300 秒**：超时未支付的充值单会被置为「已取消」，并在 `callback_raw` 里记录 `closed_reason = payment_window_expired`。用户在有效期内可在控制台轮询状态。
- **金额校验**：主动查询到网关已成功时，如果网关实收金额与支付单金额差额超过 `0.0001`，一律拒绝入账并写 warning 日志，页面提示「支付金额与充值金额不一致，请核对后重试」。这是防止同一笔网关流水重复入账的关卡。
- 支付记录模型在创建时就限制「仅允许记录第三方真实资金流入，余额、手工、免费流程请使用账单和账户流水表达」，唯一例外是管理员手工入账时的审计记录（见第 7 节）。

**充值须实名**：设置项 `finance.recharge_requires_verification` 默认开启（值为 `1`）。开启时未实名用户充值会被拒（错误码 40301「请先完成实名认证后再继续操作」）。

配置入口：管理端「营销推广 → 推广管理 → 推荐与充值」（`/admin/referral-settings`）的「账户充值」分段；保存需要 `settings.manage`。

**充值管理页**：`/admin/finance/recharges` 是**只读**列表：列为 支付单号、用户、支付记录、金额、已付金额、状态、创建时间、支付时间；筛选支持关键词、状态、起止日期；只有「详情」按钮，没有改状态或删除的入口。详情抽屉展示账单金额、优惠金额、已付金额、支付记录与操作日志。

## 4. 退款的两条通道

入口在「用户 → 用户详情 → `账单` 标签页 → 账单行 `退款`」，对应接口 `POST /v2/admin/users/{user}/invoices/{invoice}/refunds`。弹窗里退款金额是只读的（取该账单的 `paid_amount`，没有就取 `amount`），**不支持部分金额**；退款方式与退款原因都必填。

两条通道怎么选：

- **退回余额**（`refund_method = balance`）：只要账单满足前置条件就可用。把金额加到用户 `cash_balance`。
- **原路退款**（`refund_method = original`）：只在主支付渠道是 `alipay` 或 `balance` 时可选。账单是混付（余额加第三方）时一律禁用，页面提示「该账单包含余额支付，无法全额原路退款，请使用『退回余额』」。该通道要求账单必须关联投影订单，未关联时提示「账单未关联订单，暂不支持原路退款」。

前置条件（任一条不满足，整条退款通道就不可用，页面会给出对应文案）：

1. 账单状态必须是 `1` 已付，否则「仅已支付账单支持退款」；
2. 必须能找到可退款的支付记录，否则「未找到可退款的支付记录」；
3. 支付记录已是「已退款」→「该账单已完成退款」；
4. 账单已开通服务（`service_id` 有值且服务状态不是 `0`）→「账单已开通服务，请先在服务控制台处理资源后再退款」；
5. 该账单款项已通过重复支付转入余额 →「该账单款项已通过重复支付转入余额，无需再退款」。

两条通道共用同一把账单级锁，防止同时操作造成超额退款。

退款落库结果：以「退回余额」为例，一次退款会产生四类记录：

- `refunds`：`status = 1` 已完成、`refund_method = balance`、记录操作人与原因；
- 一张 `type = refund` 的红字账单：`amount` 与 `paid_amount` 为负数、`status = 1` 已付、通过 `origin_invoice_id` 指回原账单；
- 一条 `event_type = invoice_refund` 的入账流水；
- 若原账单有充值记录，按剩余可冲抵额度生成一条负向的 `refund_offset` 充值记录，通过 `origin_recharge_record_id` 指回原记录。

## 5. 对账口径与不一致排查

所有对账命令都不会删除或改写资金数据，只做检测与必要的补建。

主要口径：

| 命令                                             | 口径与退出码                                                                                                                                                                                            |
| ------------------------------------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `php artisan reconcile:account-balance`          | 比对每个 `(user_id, account_type)` 在流水里最新一条的 `balance_after` 与 `user_accounts` 对应余额字段；差超过 0.01 元列为差异、写 warning 日志并返回非零退出码，`--json` 输出明细                       |
| `php artisan finance:audit-ledger-consistency`   | 比对 `cash_balance` 与流水 `account_type = cash` 的 `change_amount` 合计，并统计缺 `source_type` / `source_id` / `trace_id` 的流水条数与「充值已成功但账单桥接缺失」条数；`--strict` 时有异常即非零退出 |
| `php artisan trade:reconcile-invoice-order`      | 审计并修复 `orders` 与 `invoices` 的绑定关系与支付状态投影                                                                                                                                              |
| `php artisan payment:audit-third-party-boundary` | 审计支付记录是否只承载第三方真实资金流入，历史非第三方记录只保留审计口径                                                                                                                                |
| `php artisan account:check-projections`          | 检查 `users` 与 `user_accounts` 的账户投影完整性与余额一致性                                                                                                                                            |

`reconcile:account-balance` 的账户字段映射：`cash` → `cash_balance`、`referral_frozen` → `referral_frozen_balance`、`referral_available` → `referral_available_balance`、`referral_pending_withdrawal` → `referral_pending_withdrawal_balance`、`referral_withdrawn` → `referral_withdrawn_balance`。

补数命令，只在确认缺口后使用：

- `php artisan payment:compensate-recharge-invoices`：为充值已成功但 `invoice_id` 为空的支付记录补建充值账单；
- `php artisan finance:backfill-refund-records`：回填已退款但未落 `refunds` 表的记录，并同步账单的退款标记；
- `php artisan finance:backfill-ledger-sources`：高置信回填流水的 `source_type` 与充值桥接。

建议的排查顺序：

1. 先跑 `reconcile:account-balance --json`，定位到具体用户与 `account_type`；
2. 到该用户详情的「资金流水」标签页按时间看明细，用 `trace_id` 把账单、支付、流水串起来；
3. 流水本身缺来源信息时，跑 `finance:audit-ledger-consistency --strict` 看缺口规模；
4. 账单侧金额或状态对不上时跑 `trade:reconcile-invoice-order`。注意其中的金额漂移（`amount_mismatch`）与「订单已完成但账单已取消」只报告、**不自动修复**，因为无法判断哪一侧才是真实意图；
5. 需要留档时把命令输出交给日志归档流程，见[日志归档](../maintain/log-archive.md)。

常见现象对照：

| 现象                      | 先看哪里                                                                                        |
| ------------------------- | ----------------------------------------------------------------------------------------------- |
| 用户说余额少了            | `reconcile:account-balance` 定位账户类型，再到该用户「资金流水」看 `balance_after` 序列         |
| 充值付了钱余额没到        | 充值管理页该笔的状态；状态成功但 `invoice_id` 为空时用 `payment:compensate-recharge-invoices`   |
| 账单已付但订单未开通      | [订单与自动开通](orders.md) 的重试路径；账单侧跑 `trade:reconcile-invoice-order` 看投影是否漂移 |
| 退款按钮点了没反应 / 置灰 | 对照第 4 节的五条前置条件，页面会给出具体原因                                                   |
| 上游余额告警              | 见第 6 节，这是供应商侧余额，不是本站用户余额                                                   |

## 6. 上游供应商余额

这套系统不代上游记账，也不向上游打款；它只**同步并留痕**上游账户余额，用于在余额耗尽前提醒你充值。

- `supplier_balances`：`balance`、`currency`、`low_balance_threshold`（默认 `20.00`）、`low_balance_alert_enabled`、`last_synced_at`、`last_attempted_at`、`last_sync_status`、`last_sync_error`、`low_balance_notified_at`。
- `supplier_balance_logs`：`balance`、`previous_balance`、`delta`、`currency`、`source`、`order_id`、`recorded_at`。`source` 取 `schedule`（定时同步）、`provision`（上游开通完成后触发）、`manual`（管理员手动查询）。
- **低余额判定**：从未同步成功（`balance` 为 `null`）时不算「不足」，避免刚接入就误报；比较走整数分而非浮点，边界不受浮点误差影响。
- 配置入口：商品 → 供应商（`/admin/products/suppliers`，权限 `supplier.list`）里按供应商设置阈值与告警开关；「上游同步日志保留天数」（默认 `30`，范围 1–365）与「余额告警重复间隔」（默认 `24` 小时，范围 1–720）在 `/admin/automation` 页面里。

## 7. 手工入账与资金调整的边界

资金调整只有一条对管理员开放的通道：**用户 → 用户详情顶部余额卡片的「资金管理」弹窗**。

- 字段：`用户`（只读）、`操作类型`（增加余额 / 扣减余额）、`金额`（`0.01`–`999999`，两位小数）、`备注`（必填）。
- 硬约束：金额为 `0` 直接拒绝；扣减后余额小于 `0` 拒绝，并回显当前余额与扣减额；请求必须带幂等标识（前端每次打开弹窗重新生成），缺标识即拒绝执行；同一 `(user_id, 幂等标识)` 重放会返回首次产生的单据而不重复动账，若标识相同但金额不同则明确拒绝；若账单在而流水查不到，直接报错让你核查，而不是再动一次账。
- 落库效果：改 `user_accounts.cash_balance`（`version` 加一）→ 写一条流水（增加记为 `manual_recharge`，扣减记为 `manual_deduction`）→ 建一张对应类型的账单 → 增加余额时再补一条 `scene = admin_recharge` 的充值记录。账单与流水共享同一个 `trace_id`。
- **手工把账单标记为已付**：服务层 `InvoiceService::markPaidManually` 存在，规则是只允许未付或逾期账单、只能按应付金额**全额**入账（不支持部分入账），会补一条 `gateway = manual` 的支付审计记录（保留 `trade_no`）供对账追溯，并把该账单下待支付的支付单置为失败，可选是否同步触发业务开通。**该能力当前没有管理端路由与页面入口**（该服务方法无调用方，订单侧的同类方法只在测试中被调用），因此不要把它写进日常操作流程。

## 8. 不能做什么

- **不物理删除支付记录**。支付记录使用软删除，任何让记录消失的操作都会让对账口径失去依据。
- **不物理删除账单**。作废用「取消」表达，退款用红字账单表达，两者都只改状态。
- **不用前端传来的金额**。金额一律服务端按商品配置、计费周期、代理折扣与优惠券重算，报价凭证会校验一致性。
- **不直接改数据库里的余额字段**。绕过 `AccountService` 就绕过了 `version` 乐观锁与流水写入，会让 `reconcile:account-balance` 永久报差异，且无法还原经过。
- **不手工改账单的 `amount` / `paid_amount` 去「平账」**。这只会制造金额漂移，而对账命令按设计不会自动修复金额差异。
- **不走线下打款记退款**。系统只有「退回余额」与「原路退款」两条退款通道；给分销奖励打款是另一条链路，见[用户与分销](users.md)。
- **不把对账命令的非零退出码当噪音**。非零就意味着确实存在差异，按第 5 节的排查顺序定位，而不是忽略。

## 关联文档

- [日常运营](index.md)：运营地图与每日建议复核。
- [用户与分销](users.md)：返佣与提现走的是另一套余额字段。
- [订单与自动开通](orders.md)：账单支付之后的开通链路。
- [支付渠道配置](../configure/payment.md)：渠道没配好时充值页会提示「当前没有可用支付方式」。
- [日志归档](../maintain/log-archive.md)：把对账与审计输出留档。
- [架构概览](../../develop/architecture.md)：账单优先（invoice-first）结构的整体背景。
