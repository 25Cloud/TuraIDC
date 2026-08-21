# 参考资料索引

参考资料解释稳定操作、接口和外部系统；与运行代码冲突时，先以代码、测试和实库为准，并创建复核任务。

| 分区   | 文档                                                                                 | 状态         | 简介                                                           |
| ------ | ------------------------------------------------------------------------------------ | ------------ | -------------------------------------------------------------- |
| 接口   | [API 格式规范](api/api-format.md)                                                    | current      | 响应、鉴权、分页、校验和版本边界。                             |
| 接口   | [API 清单导航](api/api-catalog-navigation.md)                                        | current      | 业务域与核心流程的人类可读导航。                               |
| 后端   | [后端目录分类规范](backend/backend-directory-structure.md)                           | needs-review | 后端目录与职责分层建议。                                       |
| 后端   | [财务单据生成规则](backend/financial-document-generation.md)                         | current      | 财务单据的生成边界和规则。                                     |
| 数据库 | [DATABASE.md](../DATABASE.md)                                                        | generated    | 由实库结构导出的数据库快照。                                   |
| 数据库 | [本地 IDC 数据迁移流程](database/local-idc-data-migration.md)                        | current      | 旧数据迁移的操作流程。                                         |
| 数据库 | [从智简魔方财务系统迁移](database/migrate-from-zjmf-finance.md)                      | current      | 智简魔方财务（ZJMF）→ TuraIDC 完整迁移教程（含生产踩坑记录）。 |
| 数据库 | [从智简魔方财务系统迁移（实验）](database/migrate-from-zjmf-finance-experimental.md) | experimental | 魔方财务 shd\_ 前缀数据迁移实验工具（已归档，见正式指南）。    |
| 数据库 | [日志归档与 MySQL 日志维护](database/log-archive-and-mysql-maintenance.md)           | current      | 日志保留与维护操作。                                           |
| 集成   | [本地对接说明](integrations/local-integration.md)                                    | needs-review | 上游本地联调入口与边界。                                       |
| 集成   | [插件开发](integrations/plugins/README.md)                                           | current      | 插件目录、扩展点与示例。                                       |
| 集成   | [demo-ali-pay](integrations/plugins/demo-ali-pay.md)                                 | current      | 支付网关插件示例。                                             |
| 集成   | [kanghostx 源码说明](../../backend/plugins/servers/kanghostx/README.md)              | current      | 服务器插件的源码旁维护说明。                                   |
| 运维   | [本地启动指南](operations/local-development.md)                                      | current      | 本地后端与前端启动命令。                                       |
| 运维   | [测试指南](operations/testing.md)                                                    | current      | 测试环境、命令和范围。                                         |
| 运维   | [部署与调度指南](operations/deployment-and-scheduling.md)                            | current      | 现网部署、调度与队列口径。                                     |
| 运维   | [宝塔部署项目指南](operations/bt-panel-deployment.md)                                | current      | 全新服务器部署步骤。                                           |
| 运维   | [前端 Nginx 伪静态配置](operations/frontend-nginx-rules.md)                          | current      | 四端站点 Nginx 规则。                                          |
| 运维   | [Docker 与 1Panel 部署指南](operations/docker-and-1panel-deployment.md)              | current      | Docker Compose 一键部署与 1Panel 托管。                        |
