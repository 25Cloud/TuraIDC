# 设计文档索引

设计文档说明“为什么这样做”和“准备怎样做”；它们不替代代码、接口清单或执行计划。

| 文档                                                                            | 状态         | 简介                                                      |
| ------------------------------------------------------------------------------- | ------------ | --------------------------------------------------------- |
| [ARCHITECTURE.md](../ARCHITECTURE.md)                                           | current      | 当前运行架构、边界和基础设施口径。                        |
| [插件架构说明](architecture/plugin-architecture.md)                             | needs-review | 插件运行时与能力扩展边界。                                |
| [API 直接重构方案](backend/direct-api-refactor.md)                              | needs-review | v2 API 约束与历史重构结论。                               |
| [日志与归档协同重构方案](backend/log-and-archive-coordination-refactor.md)      | active       | 日志真源、冷热检索、归档与删除边界。                      |
| [Zjmf 定时任务系统解析](backend/zjmf-scheduler-analysis.md)                     | needs-review | Zjmf 定时任务系统源码深度解析。                           |
| [Zjmf 新购续费单据对比](backend/zjmf-purchase-renewal-document-comparison.md)   | needs-review | Zjmf 与 TuraIDC 新购/续费单据创建流程对比与同步创建验证。 |
| [产品类型与一级菜单重构方案](product/product-type-and-primary-menu-refactor.md) | needs-review | 产品类型与一级菜单的结构方案。                            |

新设计先在本目录建立文档，再从 [执行计划](../execution-plans/README.md) 链接实施步骤。影响用户范围时，还要建立或更新产品规格。
