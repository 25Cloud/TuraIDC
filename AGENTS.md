# TuraIDC Agent Map

`AGENTS.md` 只提供导航，不重复说明书。开始任何修改前，先读 [工作规则](docs/governance/WORKING_RULES.md) 和 [文档记录系统](docs/README.md)，随后只打开与任务直接相关的资料。

## 不可违反的边界

- 使用 CMD 与 UTF-8；创建文件前确认当前目录。
- 始终使用中文交流，注释，提问。
- 先查代码、测试和对应文档，禁止猜测接口、业务或目录。
- 最小范围修改；不保留无调用方的旧代码或运行时兼容层。
- 工作区可能很脏，绝不回滚、覆盖或格式化非本任务改动。
- 临时文件仅放系统临时目录；不要用输出重定向污染仓库。
- 不提交密钥、密码、Token、私钥、生产数据或测试账号凭据。
- 不在管理员端做脱敏，不在日志做脱敏，都需要显示完整信息。
- 代码变更后执行回归测试；前端变更追加对应 e2e 测试。
- 子任务完成先给出改动和验证摘要；用户确认后才独立提交，格式 `Fix:中文描述`、`Feat:中文描述` 或 `Refactor:中文描述`。

## 项目地图

| 目录                        | 职责                                            |
| --------------------------- | ----------------------------------------------- |
| `backend/`                  | Laravel 12 API、业务服务、插件与 Artisan 命令。 |
| `docs-web/`                 | 官方文档站，VitePress 构建，内容源为 `docs/`。  |
| `frontend-admin-v3/`        | 管理端，Vue 3 + TDesign Vue Next。              |
| `frontend-user-v3-www/`     | 官网与用户入口，Vue 3 + Element Plus。          |
| `frontend-user-v4-console/` | 用户控制台，Vue 3 + TDesign Vue Next。          |
| `shared/`                   | 跨端状态、组件和运行时能力。                    |
| `docs/`                     | 唯一的长期文档记录系统。                        |

当前不存在 `frontend-admin/`、`frontend-client/`、`frontend-user-v3-console/`、根 `scripts/` 与 `.kiro/`；不要重新引用或创建它们。

## 按任务阅读

| 任务                         | 先读                                                                                                                                                                                  |
| ---------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| 入项、资料定位               | [docs/README.md](docs/README.md)                                                                                                                                                      |
| 系统边界、运行方式           | [ARCHITECTURE.md](docs/ARCHITECTURE.md) 与 [运维参考](docs/references/operations/deployment-and-scheduling.md)                                                                        |
| 后端、API、上游或插件        | [BACKEND.md](docs/BACKEND.md)、[API 规范](docs/references/api/api-format.md)、[插件开发](docs/references/integrations/plugins/README.md)                                              |
| API 重构                     | [API 直接重构方案](docs/designs/backend/direct-api-refactor.md) 与自动生成 [API 清单](docs/generated/api/backend-api-catalog.md)                                                      |
| 前端或页面                   | [FRONTEND.md](docs/FRONTEND.md) 与 [DESIGN.md](docs/DESIGN.md)                                                                                                                        |
| 数据库、迁移、归档           | [DATABASE.md](docs/DATABASE.md) 与 [数据库参考](docs/references/README.md)                                                                                                            |
| 产品范围与验收               | [产品规格索引](docs/product-specs/README.md)                                                                                                                                          |
| 跨模块、迁移、上线或回滚风险 | [执行计划索引](docs/execution-plans/README.md)；先建或更新计划                                                                                                                        |
| 部署、本地启动、测试         | [本地启动](docs/references/operations/local-development.md)、[部署与调度](docs/references/operations/deployment-and-scheduling.md)、[测试指南](docs/references/operations/testing.md) |

## 关键约束入口

- 后端：控制器薄层；第三方调用进入专用 Service/Driver/插件；Payment 不物理删除；迁移只新增。
- 插件：全部特有逻辑收敛到 `backend/plugins/{domain}/{slug}/`，不得注册系统级路由、调度或全局中间件；所有插件不需要 SSL 和 CA。
- 前端：不混用 UI 框架；管理端不加头部说明卡片；v4-console 财务页不加指标卡片。
- 自动生成物：修改路由或结构后运行生成脚本，绝不手工编辑 `docs/generated/`。
- 细节以 [工作规则](docs/governance/WORKING_RULES.md) 和任务对应领域文档为准。

## 事实与裁决

执行规则优先级为本文件、[工作规则](docs/governance/WORKING_RULES.md)、任务文档、其他参考资料。事实冲突时，以运行代码与测试、实库 `information_schema`、自动生成文档为准，并更新或标记过期文档。

## 验证

| 影响范围                    | 最小验证                                                                             |
| --------------------------- | ------------------------------------------------------------------------------------ |
| 文档                        | `pnpm run docs:check`                                                                |
| `backend/`                  | `cd backend && php artisan test`（可先缩小到受影响文件）                             |
| `frontend-admin-v3/`        | `cd frontend-admin-v3 && pnpm run build`                                             |
| `frontend-user-v3-www/`     | `cd frontend-user-v3-www && pnpm run build`；重构追加 `pnpm run verify:refactor`     |
| `frontend-user-v4-console/` | `cd frontend-user-v4-console && pnpm run build`；重构追加 `pnpm run verify:refactor` |
| `shared/`                   | `pnpm run typecheck:shared && pnpm run test:shared`，再按影响端构建                  |

每个子任务先跑受影响验证；全部完成后跑本次变更覆盖范围的完整验证。不能运行时，说明原因和未覆盖风险。

## 文档维护

- 所有长期文档放入 `docs/` 的正确分区；不在仓库根或应用根新增临时说明。
- 设计、产品规格、执行计划、参考资料和生成物分别进入对应索引；不为同一主题创建重复真源。
- 执行计划必须放在 `active/`、`completed/` 或 `tech-debt/`，包含状态、进度和决策日志。
- 文档链接与目录覆盖由 `pnpm run docs:check` 校验；复核期限由 `pnpm run docs:freshness` 扫描。
