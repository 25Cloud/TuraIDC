# 文档治理策略

本策略规定 `docs/` 的目录归属、单篇结构与生命周期；`AGENTS.md` 提供导航，`docs/README.md` 提供入口。与代码冲突时以代码为准，并回写本策略。

## 1. 放置规则

先按读者分三层，再按主题进分区。**读者身份是唯一的分层依据**，不要按「技术/业务」或「重要/次要」再切一次——同一篇文档出现在两个分区，就是下一次混乱的起点。

- `guide/`：读者是部署并运营这套系统的站长与运营人员——部署安装、初始配置、日常运营、维护升级、数据迁移、故障排查。
- `develop/`：读者是二次开发者、上游对接方与仓库贡献者——系统架构、三端工程规范、数据库、接口契约、插件、本地开发与测试、贡献流程。
- `internal/`：读者只有维护这个仓库的人——执行计划、设计文档、产品规格、一次性修复记录、前端迁移台账、变更记录与仓库治理规则。这一层不进公开站。

分区内的再分层：

- `guide/deploy/`、`guide/configure/`、`guide/operate/`、`guide/maintain/`、`guide/migrate/` 按站长的工作阶段划分；`guide/troubleshooting.md` 承载「症状 → 排查 → 处理」的横向问题。
- `develop/api/`、`develop/plugins/`、`develop/integrations/` 按接口与扩展点划分，其余工程文档平铺在 `develop/` 根目录，不再造中间层。
- `internal/` 按工件类型划分；执行计划必须落在 `active/`、`completed/` 或 `tech-debt/` 之一。

生成物按内容归属放在所属分区内，不单独设生成物目录：`develop/database.md`（实库结构快照）与 `develop/api/backend-api-catalog.md`（路由清单）只能由脚本重刷，绝不手工编辑。

## 2. 文档结构基线

单篇文档统一遵循以下结构；重构时保持原意，只调整组织方式。

- 首行为唯一 H1 标题；标题后紧跟 1–3 句导语，说明文档性质、覆盖边界和与真源的关系。已有独立“目标/用途”长节的保留原节，不再重复导语。
- 正文含 3 个及以上 H2 时，统一使用阿拉伯编号 `## 1. 标题`；子级跟随父级编号（如 `### 2.1`）；开头的概述、结论类前言节不编号，编号从第一个正文节开始。
- 状态、日期、负责人等元信息统一放在 YAML front matter（`status`、`updated`、`owner`），不在正文重复 bullet 或 blockquote 式元信息。
- 结尾设不编号的“关联文档”节，链接与本主题直接相关的文档或索引；索引页与执行计划豁免。
- 中文正文使用全角标点；代码、命令、路径与标识符保持半角。

## 3. 生命周期

新需求先补产品规格或设计，再建立执行计划；实施中持续更新计划进度和决策日志；完成后记录验收与遗留风险并归档。仍对当前决策有解释价值的历史资料标为 `archived` 或 `needs-review`；已被当前真源替代、一次性且没有被现行计划引用的快照从 `docs/` 删除，通过 Git 历史回溯。

应用或插件源码旁的 `README.md`、`CHANGELOG.md`、`DEVELOPMENT.md` 只说明该模块的安装、接口或维护方式，可与代码同放；跨模块的方案、台账、验收记录和运行知识必须进入 `docs/`。

一次性线上修复写进 `internal/fix-records/`，用日期前缀命名（`YYYY-MM-DD-` + ASCII 词组），并在该目录索引登记一行；不要为同一问题同时留一份仓库外的临时说明。

## 4. 公开站边界

- 公开文档站的内容源就是 `docs/`，`docs-web/.vitepress/config.ts` 用 `srcExclude` 排除 `internal/**`：内部记录不在站点产物中出现，但仍受 `docs:check` 校验。
- `guide/` 与 `develop/` 的正文不要链接 `internal/` 的文档——站点读者点到的是 404。需要引用治理规则时给 GitHub 上的 URL。
- 站点侧栏顺序由 `config.ts` 显式维护，不按目录名排序：文档的阅读顺序是产品决策，不是文件系统属性。

## 5. 可验证性

[catalog.json](../../catalog.json) 列出非索引文档的路径、状态、简介和复核期限，记录必须按 `path` 升序排列。`pnpm run docs:check` 必须在合并前通过；`pnpm run docs:freshness` 用于例行扫描。Markdown 使用相对链接，不链接本机绝对路径，不把凭据写入文档。

目录结构本身也是被校验的对象：新增或改名顶层分区时，必须同步更新 `backend/scripts/check_docs.mjs` 的 `requiredDirectories`／`requiredFiles`／`requiredGeneratedCatalogPaths` 与 VitePress 的 `sidebar`、`srcExclude`，否则校验会立刻失败。

## 6. 生成与更新

生成物只能从源脚本更新。涉及路由、数据库结构、部署方式、接口契约或用户流程的代码改动，必须同步复核相关记录；若无文档变更，在执行计划或 PR 中说明理由。任何旧快照生成器不得覆盖当前真源。
