# 执行计划

执行计划是版本控制的一等工件。任何跨模块、迁移、上线或存在回滚风险的工作，先在这里建立计划，再修改实现。

每个计划必须有 YAML 元数据（`status`、`updated`、`owner`）、`## 进度` 和 `## 决策日志`。完成后移入 `completed/`；未完成但不再排期的工作移入 `tech-debt/`，不要删除决策依据。

计划 frontmatter 的 `status` 可使用中文值 `进行中`；`docs/catalog.json` 继续使用机器状态 `active` 登记。

## 进行中（active/）

| 计划                                                                                                                   | 状态   | 简介                                                                                      |
| ---------------------------------------------------------------------------------------------------------------------- | ------ | ----------------------------------------------------------------------------------------- |
| [代理折扣实施计划](active/2026-08-20-agent-discount.md)                                                                | active | 代理折扣体系落地：AgentDiscountService 与三张配置表、新购续费计价与三端展示。             |
| [后端专家团审查修复计划](active/backend-expert-review-remediation-2026-08-13.md)                                       | active | 6 功能域 × 5 视角专家团审查结论的分批修复计划，P0 资金与账号安全优先。                    |
| [工单上游 CodeRabbit 安全修复](active/coderabbit-security-remediation-ticket-upstream-2026-08-21.md)                   | active | 按 PR #20 CodeRabbit 审查修复工单上游传递安全与一致性问题，含上游 zjmfv376 配套改动。     |
| [数据库专家团审查修复计划](active/database-expert-review-remediation-2026-08-13.md)                                    | active | 数据库结构专家团审查发现的分组修复与回归计划。                                            |
| [专家团审查修复计划](active/expert-review-remediation-2026-08-12.md)                                                   | active | 换绑安全、密码策略、履约锁、升级锁、共享状态常量、回调 URL 与文档索引修复。               |
| [日志归档系统可靠性重构方案](active/log-archive-reliability-refactor-2026-08-01.md)                                    | active | 日志写入、检索、归档、存储、运维与恢复实施计划。                                          |
| [开放 API（Open API v2）实施计划](active/open-api-2026-08-24.md)                                                       | active | API Key 认证、open 网关中间件、Open 控制器与三端配置管理界面。                            |
| [定时任务体系重构方案](active/scheduler-refactor-2026-08-09.md)                                                        | active | ZJMF 定时任务机制与 TuraIDC 心跳、队列、台账、Hook 和人工重跑能力的差距及分阶段重构计划。 |
| [调度、VNC 与插件链路审查修复](active/scheduler-vnc-provisioning-renewal-review-2026-08-13.md)                         | active | 定时任务、VNC、上游开通、续费与插件链路的专项审查与修复，P0 资金与并发安全优先。          |
| [工单预回复](active/ticket-pre-reply-2026-08-23.md)                                                                    | active | 用户建单后以配置管理员名义自动回复，管理端预回复设置页与独立权限。                        |
| [工单上游传递日志与自动转发排查](active/ticket-upstream-delivery-logs-and-auto-delivery-troubleshooting-2026-08-20.md) | active | 工单上游转发事件日志、管理端状态展示与自动转发未触发原因排查。                            |

## 已完成（completed/）

| 计划                                                                                            | 状态      | 简介                                                                           |
| ----------------------------------------------------------------------------------------------- | --------- | ------------------------------------------------------------------------------ |
| [管理员代登录可靠性修复](completed/admin-impersonation-reliability-fix-2026-08-01.md)           | completed | 管理端代登录弹窗可靠性、消息交接与 E2E 契约修复。                              |
| [文档路径英文化迁移](completed/documentation-path-migration-2026-08-20.md)                      | completed | 将 `docs/` 下中文目录和文件名迁移为英文路径，并同步 VitePress URL 与全仓引用。 |
| [财务账务一致性与回归修复](completed/financial-ledger-consistency-regression-fix-2026-08-04.md) | completed | 账务金额、收入统计、单据投影与管理端财务回归修复。                             |
| [本地 IDC 异构数据映射迁移](completed/local-idc-heterogeneous-data-migration-20260722.md)       | completed | 异构数据中转恢复、受控 DML 导入与结构与数据验收。                              |
| [商品分类结构修复](completed/product-category-structure-fix-2026-08-01.md)                      | completed | 恢复三层商品分类实体表、重建外键并修复异常商品归属。                           |
| [生产备份本地恢复修复](completed/production-backup-local-restore-20260726.md)                   | completed | 生产备份已映射并完整恢复到本地 `idc`。                                         |
| [VNC 与业务调度隔离方案](completed/vnc-and-business-scheduling-isolation-2026-08-04.md)         | completed | VNC Relay、业务队列与定时队列隔离实施计划。                                    |
| [ZJMF 统一迁移方案](completed/zjmf-unified-migration.md)                                        | completed | ZJMF 实时绑定已切换、实例控制已验证，旧插件已卸载且历史已保留。                |

## 技术债（tech-debt/）

| 计划                                                                                   | 状态      | 简介                                                                           |
| -------------------------------------------------------------------------------------- | --------- | ------------------------------------------------------------------------------ |
| [日志归档系统可靠性重构方案](tech-debt/log-archive-reliability-refactor-2026-08-01.md) | tech-debt | 日志真源收敛、两阶段归档、冷热检索与上线验收；不在当前迭代排期，保留决策依据。 |
| [上游目录库存字段不可信](tech-debt/upstream-catalog-stock-unreliable-2026-08-26.md)    | tech-debt | 上游目录接口库存字段不可信的实测记录；属开源初始提交遗留，只记录未修复。       |

## 模板

新计划必须采用 [执行计划模板](../templates/exec-plan.md) 的最小结构。
