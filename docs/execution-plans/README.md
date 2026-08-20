# 执行计划

执行计划是版本控制的一等工件。任何跨模块、迁移、上线或存在回滚风险的工作，先在这里建立计划，再修改实现。

| 位置                                                                                            | 状态      | 简介                                                                                      |
| ----------------------------------------------------------------------------------------------- | --------- | ----------------------------------------------------------------------------------------- |
| [文档路径英文化迁移](completed/documentation-path-migration-2026-08-20.md)                      | completed | 将 `docs/` 下中文目录和文件名迁移为英文路径，并同步 VitePress URL 与全仓引用。            |
| [管理员登录人机验证](completed/admin-login-captcha-2026-08-21.md)                               | completed | 管理员登录接入现有人机验证插件、服务端强制核验与前端 E2E 回归。                           |
| [后端专家团审查修复计划](active/backend-expert-review-remediation-2026-08-13.md)                | active    | 6 功能域 × 5 视角专家团审查结论的分批修复计划，P0 资金与账号安全优先。                    |
| [定时任务体系重构方案](active/scheduler-refactor-2026-08-09.md)                                 | active    | ZJMF 定时任务机制与 TuraIDC 心跳、队列、台账、Hook 和人工重跑能力的差距及分阶段重构计划。 |
| [调度、VNC 与插件链路审查修复](active/scheduler-vnc-provisioning-renewal-review-2026-08-13.md)  | active    | 定时任务、VNC、上游开通、续费与插件链路的专项审查与修复，P0 资金与并发安全优先。          |
| [ZJMF 统一迁移方案](completed/zjmf-unified-migration.md)                                        | completed | ZJMF 实时绑定已切换、实例控制已验证，旧插件已卸载且历史已保留。                           |
| [生产备份本地恢复修复](completed/production-backup-local-restore-20260726.md)                   | completed | 生产备份已映射并完整恢复到本地 `idc`。                                                    |
| [管理员代登录可靠性修复](completed/admin-impersonation-reliability-fix-2026-08-01.md)           | completed | 管理端代登录弹窗可靠性、消息交接与 E2E 契约修复。                                         |
| [商品分类结构修复](completed/product-category-structure-fix-2026-08-01.md)                      | completed | 恢复三层商品分类实体表、重建外键并修复异常商品归属。                                      |
| [本地 IDC 异构数据映射迁移](completed/local-idc-heterogeneous-data-migration-20260722.md)       | completed | 异构数据中转恢复、受控 DML 导入与结构与数据验收。                                         |
| [财务账务一致性与回归修复](completed/financial-ledger-consistency-regression-fix-2026-08-04.md) | completed | 账务金额、收入统计、单据投影与管理端财务回归修复。                                        |
| [VNC 与业务调度隔离方案](completed/vnc-and-business-scheduling-isolation-2026-08-04.md)         | completed | VNC Relay、业务队列与定时队列隔离实施计划。                                               |
| `completed/`                                                                                    | completed | 已完成计划的保留位置。                                                                    |
| [日志归档系统可靠性重构方案](tech-debt/log-archive-reliability-refactor-2026-08-01.md)          | tech-debt | 日志真源收敛、两阶段归档、冷热检索与上线验收；不在当前迭代排期，保留决策依据。            |
| [模板](../templates/exec-plan.md)                                                               | template  | 新计划必须采用的最小结构。                                                                |

每个计划必须有 YAML 元数据（`status`、`updated`、`owner`）、`## 进度` 和 `## 决策日志`。完成后移入 `completed/`；未完成但不再排期的工作移入 `tech-debt/`，不要删除决策依据。

计划 frontmatter 的 `status` 可使用中文值 `进行中`；`docs/catalog.json` 继续使用机器状态 `active` 登记。
