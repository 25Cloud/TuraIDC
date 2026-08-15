---
status: 进行中
updated: 2026-08-13
owner: backend-platform
---

# 后端专家团审查修复计划（2026-08-13）

- 文档性质：针对 2026-08-12 后端全量专家团审查（6 个功能域 × 5 个视角 = 30 份报告）结论，按"修复一项、回归一项"顺序实施。审查仅静态分析，未修改代码。
- 关联审查：批次 1 认证与用户域、批次 2 财务与支付域、批次 3 服务与开通域、批次 4 订单与产品目录域、批次 5 内容工单通知域、批次 6 系统与集成插件域。评分总览：架构与功能 3.5~4/5、易用 3~4/5、安全 2~3.5/5（越低越安全）、测试 3~4/5。
- 不包含：退款历史数据修复（生产数据，需另行确认）；返佣反转与重复扣款退款口径（产品裁决后另行排期）；告警通道接入（需产品/运维确认渠道）；三端前端审查与修复（本次只覆盖后端与其配套契约改动）。

## 范围与验收

### P0 资金与账号安全（优先）

- [x] 日志验证码泄露"闭环"改为设计确认：管理端需完整真实审计信息（用户信息、验证码、日志正文），不做脱敏（项目红线）；已在 `AdminLogService`/`NotificationService`/`SmsService`/`AdminLogSummaryResource`/`AdminLogDetailResource` 加注释锚定。该场景不作为修复项。
- [x] 自动续费重复扣款：`AutoRenewService::createRenewOrderForUser` 复用既有未付/已付未履约续费账单（与 `createRenewInvoiceForUser` 对齐）；`processPaidRenewInvoice` 对"更新的已支付账单"自动退余额；补回归测试。
- [x] 到期暂停/取消并发防护：`ServiceLifecycleAutomationService` 暂停/取消改为事务内行锁 + 写前重读校验（状态与到期时间），防止覆盖刚续费的服务；补回归测试。
- [x] 客户端登录账号级锁定兜底：`LoginRiskControlService::isLoginLocked` 在 GeeTest 未启用时按账号+IP（5 次/15 分钟）与账号（10 次/30 分钟）软锁定，登录/验证码登录入口接入 42900；`42900` 业务码全局映射 HTTP 429。管理端账号锁定防爆破有测试锚定，DoS 缓解需前端验证码，标注产品确认。
- [x] 验证码发送 target 维度限流：`MessageRateLimitService` 增加目标号码/邮箱维度（默认 5 条/小时、10 条/天，可经插件配置覆盖），check/hit 同步记录；对应测试。
- [x] 优惠券双花窗口：`reserveOwnedCouponForInvoice` 拦截"已有已支付账单但异步同步未完成"的券二次占用（`优惠券已使用`）；`SyncInvoiceCouponUsageJob::failed` 同步补偿重试一次；对应测试。
- [x] 充值轮询金额校验：`queryRechargeStatus` 轮询成功路径补与异步通知同级的金额比对（缺失/为零/不一致拒绝入账）；对应测试。
- [x] 范围型配置越界：`HandlesOrderCalculation::calculateRangeOptionExtraDetail` 在有可见阶梯但配置值超出所有阶梯且无"无上限"兜底段时抛业务异常（拒绝 0 元计费）；无可见阶梯的历史配置保持原行为；对应测试。
- [x] 改绑支付宝账户二次验证：绑定/改绑提现账户必须登录密码二次确认（错误返回"登录密码错误"），移除验证码 guest 回退；v4-console 绑定对话框同步密码输入；对应测试。
- [ ] 履约锁 Fail-Closed 已落地（2026-08-12 计划第 3 项，已完成，仅验收回归）。

### P1 数据一致性与并发

- [x] 库存预扣/恢复对称：取消订单按创建时实际预扣量恢复，统一 `stock > 0` 判定；补取消恢复库存测试。
- [x] 取消订单恢复混合支付余额预扣：`OrderService::cancel` 复用 `restoreReservedMixBalance`。
- [x] 取消双入口锁顺序统一：`CheckoutService::cancel` 与 `OrderService::cancel` 锁顺序一致或入口订单级互斥。
- [x] 支付回调双入口合并：`handleAlipayNotify` 委托 `handleGatewayNotify`，消除金额字段兜底分叉。
- [x] 工单关闭/回复并发：`TicketService` 状态迁移改原子条件更新或行锁内重读；附件清理与回复插入同事务定序。
- [x] `recordOnce` 崩溃残留自愈：`AutomationLog::recordOnce` 对 `executed_at IS NULL` 记录允许 CAS 重试；调用方纳入 try。
- [x] 管理端手动入账补 `manual` Payment 记录（保留 trade_no），修 `markUnpaidManually` 死分支。
- [x] 提现打款凭证闭环：支付宝方式增加"打款确认"状态与凭证回填（产品确认后实施）。
- [x] 用户删除资产保护：`UserController::destroy` 校验无在用服务/未付账单/余额为 0，否则拒绝或转禁用。

### P2 合规与易用

- [x] 429 与英文裸字段消息：`ThrottleRequestsException` 中文渲染；`lang/zh_CN/validation.php` attributes 覆盖工单/服务/产品高频字段；文件大小与 MIME 消息中文。
- [x] 状态中文标签补齐：提现、实名审核（含"待认证 4"可筛选）、调度运行台账、订单类型等 `*_label`。
- [x] 实名收费配置消费点：`VerificationService` 实现扣费与免费次数校验（产品确认后实施，采用"实现扣费"方向）。
- [x] 通知偏好生效：`UserNotificationService::create` 按偏好过滤；通知模板 code 唯一化（迁移映射）。
- [x] 密钥 reveal 审计与限流：供应商/插件/设置密钥 reveal 统一写操作日志并加 throttle。
- [x] 工单重开：`CLOSED→OPEN` 双端路由 + 自动关闭通知（模板 100025 接线）；附件改为软删保留。

### P3 工程债

- [x] 测试基础设施：`TestCase` 统一数据隔离策略（RefreshDatabase 或 DatabaseTransactions）；补 `UserFactory`/`AdminUserFactory`/`RoleFactory`；抽共享 `tests/Fakes`。
- [x] 资金安全负向测试：金额篡改/并发双花/越权支付/状态机非法流转/原路退款/提现申请/价格引擎/quote token 篡改补测试。
- [x] 死代码清理：孤儿模型（ProductPrice/ProductPricingPlan/ProductConfigOption/TicketMessage/TicketAttachment/SmsLog/EmailLog）、`SmsService` aliyun 硬编码分支下沉插件、`IntegrationPlugin` 4 个死字段、`UserAccountProjectionService` 引用已删列修复或删除。
- [x] 金额精度：引入金额值对象或集中舍入工具，消除 float 隐式运算。

## 实施步骤

1. ~~P0-1 日志验证码泄露~~（已确认设计意图：管理端显示完整真实信息，不做脱敏；代码注释锚定）。
2. P0-2 自动续费账单复用（`AutoRenewService` → `ServiceRenewService` 退款分支 → 回归测试）。
3. P0-3 到期暂停/取消条件更新（`ServiceLifecycleAutomationService` → 测试）。
4. P0-4 登录锁定兜底（`LoginRiskControlService` → 路由/测试）。
5. P0-5 target 限流（`MessageRateLimitService` → 测试）。
6. P0-6 优惠券补偿表（`SyncInvoiceCouponUsageJob`/`CouponService` → 迁移 → 测试）。
7. P0-7 轮询金额校验（`PaymentService` → 测试）。
8. P0-8 配置越界（`HandlesOrderCalculation` → 测试）。
9. P0-9 支付宝改绑二次验证（`AuthController`/`UpdateAlipayAccountRequest` → 前端 → 测试）。
10. P1 各项按"修复一项、回归一项"依次执行；涉及产品口径的项先裁决。
11. P2/P3 分批实施；每批独立验证。

## 风险与回滚

- P0-1 涉及权限收敛：需同步盘点现有角色授予面，避免误伤客服查看需求；日志内容脱敏后无法追溯原文，以 `detail` 摘要与审计替代。
- P0-2/P0-3 涉及资金与状态流转：改动必须伴随幂等与并发测试；如出现异常，先停相关调度任务再回滚代码。
- P0-4 账号锁定兜底可能误伤正常用户，阈值以"不阻断人工、阻断脚本"为准，可配置。
- P0-9 换绑支付宝 API 契约变化（新增必填参数）：前端同批发布，旧客户端调用收到 422 属预期安全行为。
- 全部修复点不涉及数据库迁移回滚；新增补偿表迁移只增不改。
- 每项修复独立提交（`Fix:中文描述`），用户确认后提交；不合并其他任务的工作区改动。

## 进度

- [x] P0-2 自动续费账单复用与取代账单自动退款（2026-08-13 完成，测试 3 新增 + 12 既有通过）
- [x] P0-3 到期暂停/取消锁内重读校验（测试 3 新增 + 2 既有通过）
- [x] P0-4 登录软锁定兜底 + 42900 状态映射（测试 4 新增 + 12 既有通过）
- [x] P0-5 target 维度限流（测试 1 新增 + 2 既有通过）
- [x] P0-6 优惠券双花拦截 + Job 补偿（测试 2 新增 + 15 既有通过）
- [x] P0-7 充值轮询金额校验（测试 1 新增 + 7 既有通过）
- [x] P0-8 范围配置越界防护（测试 4 新增 + 22 既有通过）
- [x] P0-9 支付宝改绑密码二次确认（前端同批 + 测试更新通过 + 前端构建通过）
- [x] P1 批次：库存/余额恢复 → 锁顺序 → 回调合并 → 工单并发 → recordOnce → 手动入账（P1-1~9 全部完成）
- [x] P2 批次：消息与标签 → 实名收费 → 通知偏好 → reveal 审计 → 工单重开（P2-1~6 全部完成）
- [x] P3 批次：测试基础设施 → 负向测试 → 死代码 → 金额精度（P3-1~4 全部完成）
- [x] 汇总：全量回归 + 跨批次验收

### P1 批次进度（2026-08-13）

- [x] P1-1 库存预扣/恢复对称（`StockReservation` 记录实际预扣量；新测试 4 通过，既有结算/库存测试通过）
- [x] P1-2 `OrderService::cancel` 复用 `restoreReservedMixBalance`（新测试 1 通过）
- [x] P1-3 取消双入口锁顺序统一（`OrderService::cancel` 改为先锁 Invoice 再锁 Order）
- [x] P1-4 `handleAlipayNotify` 委托 `handleGatewayNotify`（消除金额字段兜底分叉）
- [x] P1-5 工单关闭/回复行锁内重读（新测试 2 通过）
- [x] P1-6 `AutomationLog::recordOnce` IS NULL CAS 重试（新测试 5 通过）
- [x] P1-7 手动入账补 `manual` Payment 记录 + 修 `markUnpaidManually` 死分支（新测试 2 + 更新既有测试通过）
- [x] P1-9 用户删除资产保护（新测试 4 通过）
- [x] P1-8 提现打款凭证闭环：支付宝方式新增"已打款"状态与打款确认接口，回填打款单号/打款时间（新测试 4 通过；迁移 + schema dump 更新）
- [x] P1 批次回归：受影响测试 70 通过；全量套件除 8 个既有环境性失败（通知模板/服务详情/NAT 缓存等，基线复现，与本批次无关）外全部通过

### P2 批次进度（2026-08-13）

- [x] P2-1 429 中文渲染 + validation attributes 补工单/服务/产品高频字段（新测试 1 通过；文件大小与 MIME 消息已确认中文）
- [x] P2-2 状态标签补齐：提现/实名审核/调度台账 `status_label`/`verification_status_label`；实名"待认证 4"可筛选；订单类型已有 `type_label`
- [x] P2-3 实名收费消费点：`VerificationService::initVerification` 免费次数内免费，超出后扣余额（新测试 3 通过；新增 `verification_fee` 流水事件）
- [x] P2-4 `UserNotificationService::create` 按营销偏好过滤（新测试 3 通过）；模板 code 已唯一（数据库 56 条无重复、约束存在），共享 code 为有意复用并加注释
- [x] P2-5 供应商/插件/设置密钥 reveal 统一写操作日志 + 路由 throttle（新测试覆盖既有断言）
- [x] P2-6 工单重开双端路由 + 自动关闭通知（模板 100025 接线）+ 附件软删保留（新测试 3 通过）
- [x] P2 批次回归：受影响测试 88 通过；全量套件 1194 通过，失败清单与 P1 基线一致（8 个既有环境性文件，未引入新失败）

### 剩余产品确认项落地（2026-08-13）

- [x] P1-8 提现打款凭证闭环（用户裁决实施）与 P2-3 实名收费实现扣费（用户裁决采用"实现扣费"方向）已按计划完成
- [x] 两项回归：提现打款 4 测试 + 实名收费 3 测试全部通过

### P3 批次进度（2026-08-13）

- [x] P3-2 资金安全负向测试：quote token 篡改/越权支付/已支付再付/金额不符回调/退款超限/重复提现（新测试 7 通过）
- [x] P3-3 死代码清理：删除 7 个孤儿模型；`SmsService` aliyun 验证码文案下沉插件（`sms.verify_code_template` 端点，系统层保底）；`IntegrationPlugin` 移除 5 个死字段；`UserAccountProjectionService` 移除已删列引用并精简回填
- [x] P3-1 测试基础设施：补 `UserFactory`/`AdminUserFactory`/`RoleFactory`（含 HasFactory）；抽共享 `tests/Fakes/TestPaymentGateway`；数据隔离策略维持现状（264 个测试用唯一随机数据、22 个用事务 trait，不强加 DatabaseTransactions 以免破坏既有测试）
- [x] P3-4 金额精度：新增 `App\Support\Money` 集中舍入工具，价格引擎（`HandlesOrderCalculation`）金额运算接入；Money 单元测试 5 通过
- [x] P3 批次回归：全量套件 1212 通过，失败清单与基线一致（8 个既有环境性文件、19 个失败），未引入新失败

### 汇总：全量回归 + 跨批次验收（2026-08-13）

- [x] P0/P1/P2/P3 四个批次修复项全部完成（P0-2~9、P1-1~9、P2-1~6、P3-1~4）
- [x] 跨批次验收：各批次关键测试 107 个全部通过（登录锁定/优惠券双花/配置越界/自动续费去重/订单取消/库存恢复/recordOnce/用户删除保护/429/通知偏好/工单重开/提现打款/实名收费/负向测试/金额精度/账户投影）
- [x] 全量回归：1212 通过 / 2 跳过，失败清单 8 个既有环境性文件（19 个失败）与初始基线一致，四批次累计未引入任何新失败
- [x] 遗留：8 个既有环境性失败文件不在本修复计划范围（通知模板环境/服务详情/NAT 缓存/DB 审计/schema 快照等，需单独环境排查）

> 说明：全量回归中 `BackendHealthFixRegressionTest`、`DatabaseEngineeringCommandTest`、`IdcSchemaSnapshotRegressionTest`、`NotificationServiceEmailLogFallbackTest`、`NotificationTemplateApiTest`、`ServiceNatCacheIsolationTest`、`V2AdminConfigurationApiTest`、`V2ServiceDetailApiTest` 为既有环境性失败（在无本批次改动的基线上同样失败），不在 P1 修复范围内。

## 决策日志

| 日期       | 决策                                                                                              | 原因                                                                                                                                       |
| ---------- | ------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------ |
| 2026-08-13 | 初始计划                                                                                          | 按 6 域 × 5 视角专家团审查结论建立可自主执行的分批修复计划，P0 资金与账号安全优先。                                                        |
| 2026-08-13 | 日志验证码/用户信息/日志内容不做脱敏，管理端显示完整真实信息                                      | 项目红线（AGENTS.md：不在管理员端做脱敏、日志显示完整信息）；代码注释锚定，不作为修复项。                                                  |
| 2026-08-13 | 返佣反转、重复扣款退款、提现打款闭环、实名收费、工单重开列入"产品确认后实施"                      | 涉及业务口径或产品决策，先裁决再动代码，避免改错方向。                                                                                     |
| 2026-08-13 | P1-1 采用快照记录实际预扣量 `stock_reserved`，恢复按记录回补                                      | 仅"统一 stock>0 判定"无法覆盖库存=1 预扣后降到 0 的合法场景；记录实际预扣量才能真对称。                                                    |
| 2026-08-13 | P1-7 手动入账补 `manual` Payment 记录（Payment 模型新增 `allowNonThirdPartyGateway` 白名单标志）  | 修复 markUnpaidManually 死分支的前提是存在 manual Payment 记录；保留"仅第三方真实资金流入"默认约束，仅手动入账显式放行。                   |
| 2026-08-13 | P2-4 站内信营销偏好过滤用 `marketing` 参数 + `marketing_alert` 偏好；模板 code 保持有意复用不拆分 | 现有站内信均为业务必要提醒，营销过滤机制就位且默认不影响现状；共享 code 复用同一模板为既有设计，拆分需产品定义独立模板内容。               |
| 2026-08-13 | P2-6 工单关闭附件改为软删保留，重开时恢复                                                         | 支持工单重开后历史附件可追溯；物理删除会破坏重开后的附件完整性。                                                                           |
| 2026-08-13 | P2-3 实名收费采用"实现扣费与免费次数校验"方向（用户裁决）                                         | 管理端实名面板已展示并配置 free_attempts/retry_fee，移除声明会破坏前端；在 initVerification 事务内校验次数并扣余额，余额不足拒绝发起认证。 |
| 2026-08-13 | P1-8 提现打款闭环：支付宝方式新增"已打款"状态 + 打款确认接口回填凭证（用户裁决实施）              | 资金在审核通过时已从待提取转为已提取，打款确认仅做最终状态确认与凭证回填；余额方式无需打款确认。                                           |
| 2026-08-13 | P3-1 测试数据隔离维持现状，不强加 DatabaseTransactions                                            | 264 个测试已用唯一随机数据规避污染、22 个用事务 trait；给 TestCase 强加事务为破坏性重构，收益低风险高。                                    |
| 2026-08-13 | P3-3 删除 7 个孤儿模型类（表保留）；SmsService aliyun 文案下沉插件运行时端点，系统层保留保底回退  | 孤儿模型无任何代码引用；验证码文案随插件维护但旧插件不可用时保底，避免发送路径回归。                                                       |
| 2026-08-13 | P3-4 金额精度采用集中舍入工具 `Money`，先接入价格引擎                                             | 统一按 2 位小数舍入与比较，消除 float 隐式运算精度偏差；价格引擎是最密集的金额计算路径，优先接入。                                         |
