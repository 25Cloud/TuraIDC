---
title: TuraIDC Documentation Center
titleTemplate: false
source: README.md
sourceHash: a625480036b05906c7514070318cda006aab337f3b18ef8d312a3de537975b08
translatedAt: 2026-10-26
---

# TuraIDC Documentation Record System

`docs/` is the single record system for project documentation. It holds actionable knowledge, decisions, and plans; code, tests, routes, and the live database take precedence over written descriptions.

Documentation is layered by reader identity: `guide/` is for site operators who deploy and run this system, `develop/` is for secondary developers and contributors, and `internal/` is for the people who maintain this repository. The first two layers go to the public documentation site; the third layer stays in the repository only.

## 1. Reading Order

1. Read [AGENTS.md](https://github.com/25Cloud/TuraIDC/blob/main/AGENTS.md) in the repository root first.
2. Before editing, read [Working Rules](https://github.com/25Cloud/TuraIDC/blob/main/docs/internal/governance/WORKING_RULES.md) and [Documentation Governance](https://github.com/25Cloud/TuraIDC/blob/main/docs/internal/governance/DOCUMENTATION_POLICY.md).
3. Enter the index below by task; do not read everything.

| Task                                              | Entry point                                                                                                                                                                                          |
| ------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Deployment, configuration, and daily operations   | [User documentation](guide/index.md)                                                                                                                                                                 |
| System boundaries, directories, and runtime model | [System architecture](develop/architecture.md)                                                                                                                                                       |
| Backend, frontend, database, visual constraints   | [Backend guidelines](develop/backend.md), [Frontend guidelines](develop/frontend.md), [Database snapshot](/develop/database), [Visual guidelines](develop/design.md)                                 |
| Migrating from ZJMF Finance                       | [Migrating from ZJMF Finance](guide/migrate/zjmf-finance.md)                                                                                                                                         |
| API contracts and the API catalog                 | [API documentation](develop/api/index.md)                                                                                                                                                            |
| Local startup, testing, and commits               | [Local development](develop/local-development.md), [Testing guide](develop/testing.md), [Engineering pitfalls](develop/pitfalls.md)                                                                  |
| Designs and architecture decisions                | [Design document index](https://github.com/25Cloud/TuraIDC/blob/main/docs/internal/designs/index.md)                                                                                                 |
| User value, scope, and acceptance                 | [Product spec index](https://github.com/25Cloud/TuraIDC/blob/main/docs/internal/product-specs/README.md)                                                                                             |
| Current work, completed work, technical debt      | [Execution plan index](https://github.com/25Cloud/TuraIDC/blob/main/docs/internal/execution-plans/README.md)                                                                                         |
| One-off production fix records                    | [Fix records](https://github.com/25Cloud/TuraIDC/blob/main/docs/internal/fix-records/README.md)                                                                                                      |
| Version changelog                                 | [Changelog](https://github.com/25Cloud/TuraIDC/blob/main/docs/internal/changelog.md)                                                                                                                 |
| Working rules and documentation governance        | [Governance rules](https://github.com/25Cloud/TuraIDC/blob/main/docs/internal/governance/README.md)                                                                                                  |
| Official docs site maintenance and publishing     | [Docs site maintenance guide](https://github.com/25Cloud/TuraIDC/blob/main/docs/internal/governance/docs-web-maintenance.md)                                                                         |
| Docs site translation and terminology             | [Multilingual maintenance rules](https://github.com/25Cloud/TuraIDC/blob/main/docs/internal/i18n/README.md), [Glossary](https://github.com/25Cloud/TuraIDC/blob/main/docs/internal/i18n/glossary.md) |

## 2. Directory Boundaries

```text
docs/
├── guide/            # 使用者：部署、初始配置、日常运营、维护升级与数据迁移（进公开站）
├── develop/          # 开发者：架构、工程规范、数据库、接口、插件与本地开发（进公开站）
├── internal/         # 维护者：执行计划、设计、产品规格、修复记录、治理规则（不进公开站）
├── en/               # English 译文镜像（/en/），人工/AI 翻译
├── zh-tw/            # 繁體中文（台灣）镜像（/zh-tw/），脚本生成，禁止手改
├── zh-hk/            # 繁體中文（香港）镜像（/zh-hk/），脚本生成，禁止手改
├── images/           # 文档引用的图片资源
├── catalog.json      # 机器可读目录，记录非索引文档的路径、状态与复核期限
└── README.md         # 本文件
```

Simplified Chinese lives at the root path and is the single source of truth; the other three languages are mirrors of it, and their paths correspond one-to-one. Translation pages record the Chinese source and its source hash in the front matter, and `pnpm run docs:i18n` tracks whether they are out of date; the two Traditional Chinese versions are generated from the Chinese by `pnpm run docs:i18n:trad`.

Generated artifacts live in the section they belong to by content (the database snapshot in `develop/database.md`, the backend API catalog in `develop/api/backend-api-catalog.md`); there is no separate output directory for them, and their status is marked as `generated` in `catalog.json`.

## 3. Status Semantics

`current` means a rule or snapshot that can be relied on now; `active` means being executed; `completed` means the result is kept; `tech-debt` means known outstanding debt; `needs-review` means it must not be executed without checking the code first; `generated` means it must be refreshed by the corresponding script; `archived` is for traceability only.

[catalog.json](https://github.com/25Cloud/TuraIDC/blob/main/docs/catalog.json) is the machine-readable catalog. `pnpm run docs:check` validates the catalog, links, plan structure, and directory coverage; `pnpm run docs:freshness` scans for review items that are due.

## 4. Boundary Between the Public Site and the Repository

- The content source of the public documentation site is `docs/`; `docs-web/.vitepress/config.ts` uses `srcExclude` to keep `internal/**` out of the site output; it is still validated by `docs:check`.
- The public site has four languages: Simplified Chinese (`/`), English (`/en/`), Traditional Chinese (Taiwan) (`/zh-tw/`), and Traditional Chinese (Hong Kong) (`/zh-hk/`). See the [Multilingual maintenance rules](https://github.com/25Cloud/TuraIDC/blob/main/docs/internal/i18n/README.md) for translation rules.
- Deciding where a document goes: if the reader is a site operator, it goes in `guide/`; if the reader is a developer, it goes in `develop/`; if only maintainers care, it goes in `internal/`.
- Do not link to documents under `internal/` from body text in `guide/` or `develop/`—that sends site readers to a 404. When you need to point at governance rules, use the URL on GitHub.
