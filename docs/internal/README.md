# 维护者内部记录

本目录保存维护这个仓库本身的工作记录：执行计划、设计文档、产品规格、修复记录、前端 v3 迁移台账、变更记录，以及仓库治理规则。

读者是仓库维护者与贡献者。这些内容**不进入公开文档站**——`docs-web/.vitepress/config.ts` 用 `srcExclude` 把 `internal/**` 排除在站点产物之外，但它们仍受 `pnpm run docs:check` 的目录与链接校验。

## 1. 分区

| 分区               | 内容                                                    | 入口                                  |
| ------------------ | ------------------------------------------------------- | ------------------------------------- |
| `execution-plans/` | 按 `active/`、`completed/`、`tech-debt/` 管理的执行计划 | [执行计划](execution-plans/README.md) |
| `designs/`         | 架构与方案级设计                                        | [设计文档](designs/index.md)          |
| `product-specs/`   | 用户问题、范围与验收条件                                | [产品规格](product-specs/README.md)   |
| `fix-records/`     | 线上问题的一次性修复记录（现象、根因、验证与回滚）      | [修复记录](fix-records/README.md)     |
| `frontend-v3/`     | 前端 v3 迁移台账与最终验收报告                          | [前端 v3 迁移](frontend-v3/README.md) |
| `governance/`      | 工作规则、文档治理策略与文档站维护指南                  | [治理规则](governance/README.md)      |
| `changelog.md`     | 面向用户的版本变更记录（Keep a Changelog）              | [变更记录](changelog.md)              |
| `templates/`       | 新执行计划模板                                          | [计划模板](templates/exec-plan.md)    |

## 2. 什么放这里、什么不放这里

- 跨模块的方案、台账、验收记录与运行知识进本目录；应用或插件源码旁的 `README.md`、`CHANGELOG.md` 只说明该模块的安装、接口或维护方式，可与代码同放。
- 已完成后仍对当前决策有解释价值的资料保留并标记状态；已被现行真源取代、一次性且没有被任何计划引用的快照删除，通过 Git 历史回溯。
- 面向使用者的操作手册与面向开发者的工程文档**不放这里**，分别进 [`docs/guide/`](../guide/index.md) 与 [`docs/develop/`](../develop/index.md)。

## 关联文档

- [工作规则](governance/WORKING_RULES.md)：协作、修改、验证与提交的强制规则。
- [文档治理策略](governance/DOCUMENTATION_POLICY.md)：文档放置、结构与生命周期。
- [文档官网维护指南](governance/docs-web-maintenance.md)：`docs-web/` 的构建、导航与发布。
