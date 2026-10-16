# TuraIDC 文档记录系统

`docs/` 是项目文档的唯一记录系统。这里保存可执行的知识、决策和计划；代码、测试、路由和数据库实况优先于文字说明。

文档按读者身份分三层：`guide/` 给部署并运营这套系统的站长，`develop/` 给二次开发者与贡献者，`internal/` 给维护这个仓库的人。前两层进公开文档站，第三层只留在仓库。

## 1. 阅读顺序

1. 先读仓库根目录的 [AGENTS.md](../AGENTS.md)。
2. 编辑前读 [工作规则](internal/governance/WORKING_RULES.md) 与 [文档治理](internal/governance/DOCUMENTATION_POLICY.md)。
3. 按任务进入下面的索引，不做全量阅读。

| 任务                         | 入口                                                                                                                              |
| ---------------------------- | --------------------------------------------------------------------------------------------------------------------------------- |
| 部署、配置与日常运营         | [使用者文档](guide/index.md)                                                                                                      |
| 系统边界、目录和运行模型     | [系统架构](develop/architecture.md)                                                                                               |
| 后端、前端、数据库、视觉约束 | [后端规范](develop/backend.md)、[前端规范](develop/frontend.md)、[数据库快照](develop/database.md)、[视觉规范](develop/design.md) |
| 从智简魔方财务系统迁移       | [从智简魔方财务系统迁移](guide/migrate/zjmf-finance.md)                                                                           |
| 接口契约与 API 清单          | [API 文档](develop/api/index.md)                                                                                                  |
| 本地启动、测试与提交         | [本地启动](develop/local-development.md)、[测试指南](develop/testing.md)、[工程踩坑记录](develop/pitfalls.md)                     |
| 方案和架构决策               | [设计文档索引](internal/designs/index.md)                                                                                         |
| 用户价值、范围与验收         | [产品规格索引](internal/product-specs/README.md)                                                                                  |
| 当前工作、已完成工作、技术债 | [执行计划索引](internal/execution-plans/README.md)                                                                                |
| 一次性线上修复记录           | [修复记录](internal/fix-records/README.md)                                                                                        |
| 版本变更记录                 | [变更记录](internal/changelog.md)                                                                                                 |
| 工作规则与文档治理           | [治理规则](internal/governance/README.md)                                                                                         |
| 官方文档站维护与发布         | [文档官网维护指南](internal/governance/docs-web-maintenance.md)                                                                   |

## 2. 目录边界

```text
docs/
├── guide/            # 使用者：部署、初始配置、日常运营、维护升级与数据迁移（进公开站）
├── develop/          # 开发者：架构、工程规范、数据库、接口、插件与本地开发（进公开站）
├── internal/         # 维护者：执行计划、设计、产品规格、修复记录、治理规则（不进公开站）
├── images/           # 文档引用的图片资源
├── catalog.json      # 机器可读目录，记录非索引文档的路径、状态与复核期限
└── README.md         # 本文件
```

生成物按内容归属放在所属分区内（数据库快照在 `develop/database.md`，后端 API 清单在 `develop/api/backend-api-catalog.md`），不再单独设生成物目录；它们的状态在 `catalog.json` 中标记为 `generated`。

## 3. 状态语义

`current` 表示当前可依赖的规则或快照；`active` 表示正在执行；`completed` 表示保留结果；`tech-debt` 表示已知欠账；`needs-review` 表示不能在未对照代码前直接执行；`generated` 表示必须由对应脚本刷新；`archived` 只用于追溯。

[catalog.json](catalog.json) 是机器可读目录。`pnpm run docs:check` 校验目录、链接、计划结构和目录覆盖；`pnpm run docs:freshness` 扫描到期复核项。

## 4. 公开站与仓库的边界

- 公开文档站的内容源就是 `docs/`，`docs-web/.vitepress/config.ts` 用 `srcExclude` 把 `internal/**` 排除在站点产物之外；它仍受 `docs:check` 校验。
- 判断一篇文档放哪里：读者是站长进 `guide/`，读者是开发者进 `develop/`，只有维护者关心进 `internal/`。
- `guide/` 与 `develop/` 的正文里不要链接 `internal/` 的文档——那会让站点读者点到 404。需要指向治理规则时用 GitHub 上的 URL。
