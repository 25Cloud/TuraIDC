# TuraIDC Agent Map

`AGENTS.md` 只提供导航，不重复说明书。开始任何修改前，先读 [工作规则](docs/internal/governance/WORKING_RULES.md) 和 [文档记录系统](docs/README.md)，随后只打开与任务直接相关的资料。

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

| 目录                        | 职责                                                                    |
| --------------------------- | ----------------------------------------------------------------------- |
| `backend/`                  | Laravel 12 API、业务服务、插件与 Artisan 命令。                         |
| `docs-web/`                 | 官方文档站，VitePress 构建，内容源为 `docs/`。                          |
| `site-web/`                 | 项目官网首页（单页），Astro 构建、零客户端 JS，部署 `www.turaidc.com`。 |
| `frontend-admin-v3/`        | 管理端，Vue 3 + TDesign Vue Next。                                      |
| `frontend-user-v3-www/`     | 官网与用户入口，Vue 3 + Element Plus。                                  |
| `frontend-user-v4-console/` | 用户控制台，Vue 3 + TDesign Vue Next。                                  |
| `shared/`                   | 跨端状态、组件和运行时能力。                                            |
| `docs/`                     | 唯一的长期文档记录系统，按读者分三层（见下）。                          |

`docs/` 的分层：`guide/` 给部署并运营这套系统的站长，`develop/` 给二次开发者与贡献者，`internal/` 给维护这个仓库的人。前两层进公开文档站，`internal/` 不上站但同样受文档校验。

当前不存在 `frontend-admin/`、`frontend-client/`、`frontend-user-v3-console/`、根 `scripts/` 与 `.kiro/`；不要重新引用或创建它们。

## 按任务阅读

| 任务                                   | 先读                                                                                                                                                 |
| -------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------- |
| 入项、资料定位                         | [docs/README.md](docs/README.md)                                                                                                                     |
| 系统边界、运行方式                     | [ARCHITECTURE.md](docs/develop/architecture.md) 与 [运维参考](docs/guide/deploy/scheduling.md)                                                       |
| 后端、API、上游或插件                  | [BACKEND.md](docs/develop/backend.md)、[API 规范](docs/develop/api/format.md)、[插件开发](docs/develop/plugins/index.md)                             |
| API 重构                               | [API 直接重构方案](docs/internal/designs/backend/direct-api-refactor.md) 与自动生成 [API 清单](docs/develop/api/backend-api-catalog.md)              |
| 前端或页面                             | [FRONTEND.md](docs/develop/frontend.md) 与 [DESIGN.md](docs/develop/design.md)                                                                       |
| 项目官网首页 `site-web/`               | [官网首页维护](docs/internal/governance/site-web-maintenance.md) 与 [设计文档](docs/internal/designs/frontend/site-web-landing-design.md)            |
| 数据库、迁移、归档                     | [DATABASE.md](docs/develop/database.md)、[MySQL 版本兼容基线](docs/develop/mysql-compatibility.md) 与 [日志归档](docs/guide/maintain/log-archive.md) |
| 产品范围与验收                         | [产品规格索引](docs/internal/product-specs/README.md)                                                                                                |
| 跨模块、迁移、上线或回滚风险           | [执行计划索引](docs/internal/execution-plans/README.md)；先建或更新计划                                                                              |
| 部署、配置与运营（站长视角）           | [使用者文档](docs/guide/index.md)                                                                                                                    |
| 部署、本地启动、测试                   | [本地启动](docs/develop/local-development.md)、[部署与调度](docs/guide/deploy/scheduling.md)、[测试指南](docs/develop/testing.md)                    |
| 改代码、改文案、跑测试、提交、增量部署 | [工程踩坑记录](docs/develop/pitfalls.md)：这些环节的实测坑，动之前先看                                                                               |

## 关键约束入口

- 后端：控制器薄层；第三方调用进入专用 Service/Driver/插件；Payment 不物理删除；迁移只新增。
- 数据库：绝不删表/删列/删索引（存量升级与魔方财务搬迁依赖表结构稳定）；SQL 须同时兼容 MySQL 5.7.44 与 8.x，禁用窗口函数、CTE 等 8.0 专属语法。细则见 [MySQL 版本兼容基线](docs/develop/mysql-compatibility.md)。
- 插件：全部特有逻辑收敛到 `backend/plugins/{domain}/{slug}/`，不得注册系统级路由、调度或全局中间件；不得自建鉴权中间件绕过统一认证入口；所有插件不需要 SSL 和 CA——上游可能是纯 HTTP，或证书链不完整，强制校验会直接打断对接，这是既定取舍，评审时不必反复提，但**不得**以此为由放松签名校验、密钥纪律与输入输出净化。
- 安全分层：入站 WAF（正则载荷特征匹配，配置在 `backend/config/waf.php`）是纵深防御的外层，不是唯一防线，也识别不了身份与权限。禁止把鉴权、授权、越权与令牌伪造问题「交给 WAF 解决」，这类必须在认证/授权层修；同样禁止因为有 WAF 就放松参数绑定、FormRequest 校验与富文本白名单净化。WAF 豁免名单每加一条都是一个洞，必须在该条目旁写明理由；规则库改动先以观察模式确认无误伤，再切拦截。
- 密钥缺省即拒绝：任何签名、HMAC 或令牌密钥读到空值时必须拒绝服务（拒签 + 拒验），绝不允许降级为「空密钥照常计算」——那等于任何人都能伪造凭据。新增此类配置项必须同时写入 `backend/.env.example` 并注明留空的后果。
- 前端：不混用 UI 框架；管理端不加头部说明卡片；v4-console 财务页不加指标卡片。
- 自动生成物：修改路由或结构后运行生成脚本，绝不手工编辑 [数据库快照](docs/develop/database.md) 与 [后端 API 清单](docs/develop/api/backend-api-catalog.md)。
- 细节以 [工作规则](docs/internal/governance/WORKING_RULES.md) 和任务对应领域文档为准。

## 事实与裁决

执行规则优先级为本文件、[工作规则](docs/internal/governance/WORKING_RULES.md)、任务文档、其他参考资料。事实冲突时，以运行代码与测试、实库 `information_schema`、自动生成文档为准，并更新或标记过期文档。

## 验证

| 影响范围                    | 最小验证                                                                             |
| --------------------------- | ------------------------------------------------------------------------------------ |
| 文档                        | `pnpm run docs:check`                                                                |
| `backend/`                  | `cd backend && php artisan test`（可先缩小到受影响文件）                             |
| `frontend-admin-v3/`        | `cd frontend-admin-v3 && pnpm run build`                                             |
| `frontend-user-v3-www/`     | `cd frontend-user-v3-www && pnpm run build`；重构追加 `pnpm run verify:refactor`     |
| `frontend-user-v4-console/` | `cd frontend-user-v4-console && pnpm run build`；重构追加 `pnpm run verify:refactor` |
| `shared/`                   | `pnpm run typecheck:shared && pnpm run test:shared`，再按影响端构建                  |
| `site-web/`                 | `cd site-web && npm ci && npm run build && npm run verify`                           |

每个子任务先跑受影响验证；全部完成后跑本次变更覆盖范围的完整验证。不能运行时，说明原因和未覆盖风险。

## 文档维护

- 所有长期文档放入 `docs/` 的正确分区；不在仓库根或应用根新增临时说明。
- 分区按读者决定：站长进 `docs/guide/`，开发者进 `docs/develop/`，只有维护者关心进 `docs/internal/`；同一主题不建第二个真源。
- 设计、产品规格、执行计划与一次性修复记录进入 `docs/internal/` 的对应分区与索引；生成物按内容归属放在所属分区内。
- 执行计划必须放在 `internal/execution-plans/` 的 `active/`、`completed/` 或 `tech-debt/`，包含状态、进度和决策日志。
- `guide/` 与 `develop/` 的正文不要链接 `internal/` 的文档，站点上会是 404。
- 文档链接与目录覆盖由 `pnpm run docs:check` 校验；复核期限由 `pnpm run docs:freshness` 扫描。该脚本也是 EdgeOne 自动部署的构建前置步骤。

## 开源许可与合规（AGPL-3.0-or-later）

本项目以 **AGPL-3.0-or-later** 发布。凡是参与修改、二次开发或部署的 AI 助手，都有责任在合适时机**主动提醒使用者**下列义务，不能因为用户没问就默认省略：

- **改了就得上线开源**：AGPL 第 13 条要求「通过网络向用户提供服务」的修改版，必须向这些用户提供完整对应源码（含自己的改动）。不能只部署不开源，也不能用「内部使用」「私有化交付」绕过。
- **不能把本项目改成闭源产品再分发**：二次开发后的对外服务同样受 AGPL 约束；需要闭源商用只能另行取得商业授权，不能自行去掉许可证与版权声明。
- **通用改进优先回流上游**：修 bug、做兼容、补文档这类改动，请提交 Pull Request 到 <https://github.com/25Cloud/TuraIDC>，而不是长期维护私有分叉；提交前遵守本文件的提交规范。
- **保留署名与许可文件**：不得删除 `LICENSE`、文件头版权声明、前端页脚/侧栏的版权与仓库链接，也不得把 TuraIDC 品牌包装成自研产品对外宣传。
- **依赖也要合规**：引入新依赖前确认其许可证与 AGPL 兼容（MIT/BSD/Apache-2.0/GPL 一般可行，专有或带限制条款的需评估），并在改动说明里注明。
- **提醒时机**：当用户提出「部署到自己的服务器给客户用」「改完别公开」「去掉版权或许可证」「做成私有版本卖」这类需求时，必须先用一两句话说明上述义务和风险，再继续技术工作；不要以「我只是执行」为由跳过提醒。

以上是法律与产品口径，优先级高于本文件中的一般工程偏好。

## 长期记忆（deja）

- 开工前用 `deja_recall` 按具体 token 检索（文件路径、报错原文、功能名），已有结论不要重复排查。
- 任务收尾主动 `deja_remember`，一条一个自包含事实：非显然的踩坑或口径、用户对方案的偏好与批评、多个可行方案中选定的那个及理由。
- 记忆承载跨会话的事实与偏好；产品与工程约定仍以 `docs/` 为唯一真源，同一内容不在两处各写一份。
