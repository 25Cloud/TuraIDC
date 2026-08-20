---
status: active
updated: 2026-08-13
owner: backend
---

# 数据库专家团审查修复计划-2026-08-13

## 背景

2026-08-13 启动数据库结构专家团审查（5 维度 × 对抗验证），对实库 `idc`（MySQL 8.0.29，63 表）产出 25 条确认发现（高 2 / 中 9 / 低 14）。本计划修复全部确认发现，对照项目基线 `mysql-schema.sql`、`docs/DATABASE.md` 与回归测试。

审查结论详见会话报告（H1-H2 / M1-M9 / L1-L14 编号）。本计划按修复单元分组，每组独立可验证、可回滚。

## 范围与验收

- [ ] 回填批：users.id_card 明文全部加密；settings.email_password 回填 enc: 加密。
- [ ] 资金对账批：account_transactions 无来源流水回填；recharge_records 桥接回填；退款（3 笔）发票标记 + refunds 落库；orders/invoices 金额漂移修复；补每日对账/审计能力。
- [ ] 结构批：冗余/低效索引清理；状态枚举注释补全；account_transactions 补 (source_type,source_id) 索引。
- [ ] 软删批：软删时释放全局唯一键（email/phone/slug），应用-库语义一致。
- [ ] 日志/安全批：验证码类模板脱敏或短时留存；审计表 GET 噪音降级、凭据脱敏。
- [ ] 演进批：product_groups 归档后 DROP，同步 baseline 与 DATABASE.md；清理幽灵管理员/乱码角色。
- [ ] 每批跑相关回归测试，全部完成后跑全量 `php artisan test`。

## 实施步骤

1. 回填批：跑 `verification:reencrypt-id-cards --apply --table=users`；写一次性命令回填 email_password 加密。
2. 结构批：新建增量迁移（索引清理、注释补全、source 复合索引）。
3. 软删批：在 User/ContentArticle 模型软删钩子中释放唯一键，补回归测试。
4. 资金对账批：扩展 ReconcileInvoiceOrderCommand 覆盖金额漂移与已完成+已取消；写数据回填命令（流水来源、recharge_records、退款）；评估每日对账任务注册。
5. 日志/安全批：验证码模板落库脱敏；审计 GET 降级与凭据脱敏。
6. 演进批：归档 product_groups 43 行后 DROP；修订 mysql-schema.sql 与 DATABASE.md；清理幽灵管理员 313 与乱码角色 397。
7. 全量回归验证。

## 风险与回滚

- 资金数据改动一律先快照/备份，命令支持 dry-run；对账命令执行前写快照文件。
- id_card 加密用应用密钥，回填前 dry-run；写路径确认均经 LegacyEncrypted cast。
- product_groups DROP 前先归档为 CSV/JSON 备份（08-01 事故安全网，可随时重建）。
- 索引清理逐条 EXPLAIN 复核热查询；只删严格冗余。
- 软删释放唯一键仅影响 email/phone/slug 复用，单号（order_no）不释放。
- 每批独立提交，提交格式 `Fix:中文描述`；失败即停，回滚该批。

## 进度

- [x] 专家团审查完成，25 条确认。
- [x] 回填批：id_card 79 条明文加密、email_password enc: 回填。
- [x] 结构批：12 枚冗余/低效索引清理、枚举注释补全、(source_type,source_id) 索引。
- [x] 软删批：User 软删释放 email/phone 唯一键（新 trait + 事务 + 回归测试）。
- [x] 资金对账批：退款 3 笔 refunds 落库 + 发票标记；对账命令扩展金额/状态检测；流水 source_type 回填 512 行 + recharge 桥接 12 笔；新增流水一致性审计命令并注册每日调度。
- [x] 日志安全批：SensitiveDataSanitizer 增加 code/验证码字段脱敏（login-as 兑换码）。
- [x] 演进批：product_groups 归档 43 行后 DROP（实库回到 62 表）；幽灵管理员 313 禁用、乱码角色 397 删除；DATABASE.md 重新导出。
- [x] 全量回归验证：`php artisan test` → 1243 passed / 19 failed / 2 skipped。19 个失败经 `git stash` 隔离验证全部为**预存问题**（NotificationTemplateApiTest、V2ServiceDetailApiTest、IdcSchemaSnapshotRegressionTest 等，源于测试环境/数据与 `database/schema.sql` 路径缺失），与本次修复无关；本次改动相关测试（回填/结构/软删/资金/日志/演进）全部通过。

## 决策日志

| 日期       | 决策                                         | 原因                                                                                                       |
| ---------- | -------------------------------------------- | ---------------------------------------------------------------------------------------------------------- |
| 2026-08-13 | 按修复单元分 6 批执行                        | 资金/安全改动需分批验证、可回滚；避免超大 diff 一次性风险。                                                |
| 2026-08-13 | product_groups 采取归档后 DROP               | 回归测试显式要求表不存在；三层实体表为真源；08-01 事故已修复，安全网可拆除。                               |
| 2026-08-13 | 软删唯一键采用"软删时改写唯一列"而非复合唯一 | MySQL 唯一索引 NULL 语义下 `UNIQUE(email,deleted_at)` 会破坏活跃唯一性；改写与应用"软删后可复用"语义一致。 |
| 2026-08-13 | M7 验证码日志不做脱敏                        | 项目红线"日志显示完整信息"；改为确认清理机制（log-archive 每日任务 + retention）已配置。                   |
| 2026-08-13 | M9 GET 不降级、保持记录                      | 现有测试断言 GET 被审计记录，降级破坏审计完整性契约。                                                      |
| 2026-08-13 | 资金金额漂移与账实不符不自动修复             | 无法判断哪一侧为真实意图，自动改资金风险不可控；对账命令扩展为检测+人工。                                  |
| 2026-08-13 | L10 is_verified 冗余列保留                   | 数据零不一致、写入路径已同步；删列需改 16 文件且代码有 legacy 兼容分支依赖，风险收益不匹配。               |
| 2026-08-13 | L8 外键/索引命名不批量重命名                 | 涉及大量迁移 DDL，属可维护性债；新环境用 baseline 初始化 + 只增迁移已抑制回归风险，记录规范即可。          |
