# TuraIDC 文档记录系统

`docs/` 是项目文档的唯一记录系统。这里保存可执行的知识、决策和计划；代码、测试、路由和数据库实况优先于文字说明。

## 阅读顺序

1. 先读仓库根目录的 [AGENTS.md](../AGENTS.md)。
2. 编辑前读 [工作规则](治理/WORKING_RULES.md) 与 [文档治理](治理/DOCUMENTATION_POLICY.md)。
3. 按任务进入下面的索引，不做全量阅读。

| 任务                         | 入口                                                                                                     |
| ---------------------------- | -------------------------------------------------------------------------------------------------------- |
| 系统边界、目录和运行模型     | [ARCHITECTURE.md](ARCHITECTURE.md)                                                                       |
| 后端、前端、数据库、视觉约束 | [BACKEND.md](BACKEND.md)、[FRONTEND.md](FRONTEND.md)、[DATABASE.md](DATABASE.md)、[DESIGN.md](DESIGN.md) |
| 方案和架构决策               | [设计文档/index.md](设计文档/index.md)                                                                   |
| 用户价值、范围与验收         | [产品规格/README.md](产品规格/README.md)                                                                 |
| 当前工作、已完成工作、技术债 | [执行计划/README.md](执行计划/README.md)                                                                 |
| API、集成、运维与数据库参考  | [参考资料/README.md](参考资料/README.md)                                                                 |
| 脚本生成的快照               | [自动生成/README.md](自动生成/README.md)                                                                 |

## 目录边界

```text
docs/
├── ARCHITECTURE.md       # 运行中的系统结构
├── BACKEND.md            # 后端工程约束
├── DATABASE.md           # 数据库结构快照
├── DESIGN.md             # 视觉与交互约束
├── FRONTEND.md           # 三个前端与 shared 约束
├── 设计文档/              # 需要做出或已记录的技术设计
├── 产品规格/              # 用户问题、范围、验收条件
├── 执行计划/              # 版本控制的执行计划与决策日志
├── 参考资料/              # 稳定操作资料和外部接口参考
├── 自动生成/              # 由代码或脚本生成，禁止手工伪维护
├── 治理/                  # 工作规则与文档生命周期
└── 模板/                  # 新工件模板
```

## 状态语义

`current` 表示当前可依赖的规则或快照；`active` 表示正在执行；`completed` 表示保留结果；`tech-debt` 表示已知欠账；`needs-review` 表示不能在未对照代码前直接执行；`generated` 表示必须由对应脚本刷新；`archived` 只用于追溯。

[catalog.json](catalog.json) 是机器可读目录。`npm run docs:check` 校验目录、链接、计划结构和目录覆盖；`npm run docs:freshness` 扫描到期复核项。
