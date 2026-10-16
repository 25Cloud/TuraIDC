# 开发者文档

面向二次开发、上游对接与仓库贡献者：运行中的系统结构、三端工程规范、数据库与接口契约、插件扩展点，以及本地开发与测试。只负责部署和运营这套系统，请进[使用者文档](../guide/index.md)。

## 1. 按目的进入

| 目的                           | 入口                                                                                                 |
| ------------------------------ | ---------------------------------------------------------------------------------------------------- |
| 先搞清楚系统怎么跑             | [系统架构](architecture.md)                                                                          |
| 改后端 / 前端 / 视觉           | [后端规范](backend.md)、[前端规范](frontend.md)、[视觉与交互规范](design.md)                         |
| 查表结构、SQL 兼容约束         | [数据库快照](database.md)、[MySQL 版本兼容基线](mysql-compatibility.md)                              |
| 对接或改造接口                 | [API 文档](api/index.md)、[后端 API 清单](api/backend-api-catalog.md)                                |
| 写插件（供应商 / 支付 / 短信） | [插件开发](plugins/index.md)、[插件包规范](plugins/package-spec.md)                                  |
| 与上游系统互相打通             | [魔方上游协议](integrations/zjmf-upstream-api.md)、[本地对接说明](integrations/local-integration.md) |
| 本地跑起来并跑测试             | [本地启动](local-development.md)、[测试指南](testing.md)                                             |
| 提交改动之前                   | [安全开发规范](security.md)、[工程踩坑记录](pitfalls.md)、[参与贡献](contributing.md)                |

## 2. 事实优先级与生成物

冲突时依次以运行代码与测试、实库 `information_schema`、自动生成文档、本目录规范为准。两份自动生成物**禁止手工编辑**，只能重刷脚本：

```bash
php backend/scripts/export_database_structure.php   # 刷新数据库快照 develop/database.md
php backend/scripts/export_api_inventory.php        # 刷新后端 API 清单
```

## 3. 改动前必读

- [后端规范](backend.md) 与 [前端规范](frontend.md)：控制器保持薄层，第三方调用进 Service/Driver/插件，三端 UI 框架不混用。
- [安全开发规范](security.md)：WAF 只是外层防御，鉴权与越权必须在认证授权层修；签名或令牌密钥读到空值必须拒绝服务。
- [MySQL 版本兼容基线](mysql-compatibility.md)：SQL 必须同时兼容 MySQL 5.7.44 与 8.x，禁用窗口函数、CTE 等 8.0 专属语法。
- [工程踩坑记录](pitfalls.md)：改代码、改文案、跑测试、提交与增量部署环节的实测坑。

## 关联文档

- [使用者文档](../guide/index.md)：部署、配置与日常运营手册。
