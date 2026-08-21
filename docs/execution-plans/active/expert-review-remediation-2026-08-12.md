---
status: 进行中
updated: 2026-08-12
owner: backend-platform
---

# 专家团审查问题修复计划（2026-08-12）

- 文档性质：针对 2026-08-12 专家团审查（功能性/安全性/易用性四批八专家）结论，选择技术上可自主执行、范围可控的修复点，按"修复一项、回归一项"的顺序实施。
- 关联审查结论：安全 P1 换绑接管链、代码质量 P1 履约锁 Fail-Open、P1 升级履约无并发锁、共享状态常量缺项、SRE P2 支付回调 URL 兜底；治理类索引与忽略文件污染。
- 不包含：退款历史数据修复（生产数据，需另行确认）、生命周期策略产品确认（产品决策）、告警通道接入（需产品/运维确认渠道）、超大组件拆分（独立重构计划）。

## 范围与验收

- [ ] 换绑邮箱/手机必须验证旧联系方式验证码；guest 作用域回退移除；旧码发码接口校验目标归属；前端对话框同步支持；后端回归测试通过。
- [ ] 客户端注册与改密密码策略由 `min:6` 提升为 `min:8`，管理端不变。
- [ ] 支付后履约锁异常时 Fail-Closed（抛错走队列重试），不再无锁降级履约。
- [ ] 升降级履约 `processPaidUpgradeOrder` 增加内部并发锁，幂等边界进入锁内。
- [ ] `shared/statusConfig.js` 补齐 `PARTIALLY_REFUNDED=6` 及对应展示映射与类型声明。
- [ ] 支付宝回调 URL 兜底移除 `frontend_url` 指向，仅保留显式配置与后端 `APP_URL`。
- [ ] 执行计划索引与目录对齐、`.gitignore` 污染行清理；`pnpm run docs:check` 通过。

## 实施步骤

1. 换绑安全修复：`AuthController`、`UpdatePhoneRequest`、`UpdateEmailRequest`、`SendEmailCodeRequest`、`sendPhoneCode` 归属校验、v4-console `useProfile.ts`/`profile/index.vue`、新增回归测试。
2. 密码策略：`RegisterRequest`、`UpdatePasswordRequest` 提升 `min:8`，补校验测试。
3. 履约锁：`PaymentService::processPaidOrderFulfillmentById` 锁异常改抛错。
4. 升级锁：`ServiceUpgradeService::processPaidUpgradeOrder` 用 `Cache::lock` 包裹，参照续费路径 `block(3)` 模式。
5. 共享常量：`shared/statusConfig.js` 与 `shared/statusConfig.d.ts`、`index.d.ts` 同步。
6. 回调 URL：`plugins/gateways/ali_pay/lib/AlipayClient.php` 移除前端域名兜底。
7. 治理：`docs/execution-plans/README.md`、`completed/README.md` 对齐目录；根 `.gitignore` 清理 `frontend-admin/$null` 行。

## 风险与回滚

- 换绑 API 契约变化（新增必填 `old_code`）：前端同步修改，两端同批发布；旧客户端调用将收到 422，属预期安全行为。
- 密码策略收紧影响存量 6 位密码用户：仅在修改密码/注册时生效，不强制存量改密，无登录阻断。
- 履约锁 Fail-Closed：Redis 短暂异常时履约任务延迟至队列重试，不丢单（`fulfillment_pending` 兜底仍在）；异常时任务进入失败台账可人工重跑。
- 共享常量与回调 URL 改动为增量，无兼容性影响。
- 全部修复点不涉及迁移、不回滚历史数据。

## 进度

- [x] 1. 换绑安全修复（后端 + 前端 + 回归测试）
- [x] 2. 密码策略 min:8
- [x] 3. 履约锁 Fail-Closed
- [x] 4. 升级履约内部锁
- [x] 5. 共享账单状态常量补齐
- [x] 6. 支付宝回调 URL 兜底修复
- [x] 7. 文档索引与 .gitignore 清理

## 决策日志

| 日期       | 决策                                              | 原因                                                                                            |
| ---------- | ------------------------------------------------- | ----------------------------------------------------------------------------------------------- |
| 2026-08-12 | 初始计划                                          | 按专家团审查结论选取可自主修复项，逐项回归。                                                    |
| 2026-08-12 | 换绑采用"旧联系方式验证码"而非当前密码            | 旧联系方式是本人持有证据，前端已有验证码交互基础；同时移除 guest 回退并对发码接口增加归属校验。 |
| 2026-08-12 | 客户端密码统一 min:8（注册/改密/重置/管理端建户） | 与管理端策略对齐，登录接口保持 min:6 不强制存量。                                               |
| 2026-08-12 | 注册 nickname 空值归一为 ''                       | 测试暴露 users.nickname NOT NULL DEFAULT '' 导致注册 500 的真实缺陷，随密码策略修复一并修正。   |
| 2026-08-12 | 履约锁异常 Fail-Closed                            | 锁后端异常时抛错走队列重试，避免无锁并发调用上游；fulfillment_pending 兜底仍在。                |
| 2026-08-12 | 回调 URL 兜底仅保留显式配置与后端 APP_URL         | 前端域名不反代 /api 时通知将丢失；生产可配置 alipay.notify_url 覆盖。                           |
