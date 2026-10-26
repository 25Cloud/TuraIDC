---
title: TuraIDC 文件中心
titleTemplate: false
source: README.md
sourceHash: a625480036b05906c7514070318cda006aab337f3b18ef8d312a3de537975b08
generated: zh-tw
---

<!-- 本檔案由 docs-i18n.mjs trad zh-tw 從 docs/README.md 產生，請勿手動編輯。 -->
# TuraIDC 文件記錄系統

`docs/` 是專案文件的唯一記錄系統。這裡儲存可執行的知識、決策和計劃；程式碼、測試、路由和資料庫實況優先於文字說明。

文件按讀者身份分三層：`guide/` 給部署並運營這套系統的站長，`develop/` 給二次開發者與貢獻者，`internal/` 給維護這個倉庫的人。前兩層進公開文件站，第三層只留在倉庫。

## 1. 閱讀順序

1. 先讀倉庫根目錄的 [AGENTS.md](https://github.com/25Cloud/TuraIDC/blob/main/AGENTS.md)。
2. 編輯前讀 [工作規則](https://github.com/25Cloud/TuraIDC/blob/main/docs/internal/governance/WORKING_RULES.md) 與 [文件治理](https://github.com/25Cloud/TuraIDC/blob/main/docs/internal/governance/DOCUMENTATION_POLICY.md)。
3. 按任務進入下面的索引，不做全量閱讀。

| 任務                         | 入口                                                                                                                              |
| ---------------------------- | --------------------------------------------------------------------------------------------------------------------------------- |
| 部署、配置與日常運營         | [使用者文件](guide/index.md)                                                                                                      |
| 系統邊界、目錄和執行模型     | [系統架構](develop/architecture.md)                                                                                               |
| 後端、前端、資料庫、視覺約束 | [後端規範](develop/backend.md)、[前端規範](develop/frontend.md)、[資料庫快照](/develop/database)、[視覺規範](develop/design.md) |
| 從智簡魔方財務系統遷移       | [從智簡魔方財務系統遷移](guide/migrate/zjmf-finance.md)                                                                           |
| 介面契約與 API 清單          | [API 文件](develop/api/index.md)                                                                                                  |
| 本地啟動、測試與提交         | [本地啟動](develop/local-development.md)、[測試指南](develop/testing.md)、[工程踩坑記錄](develop/pitfalls.md)                     |
| 方案和架構決策               | [設計文件索引](https://github.com/25Cloud/TuraIDC/blob/main/docs/internal/designs/index.md)                                                                                         |
| 使用者價值、範圍與驗收         | [產品規格索引](https://github.com/25Cloud/TuraIDC/blob/main/docs/internal/product-specs/README.md)                                                                                  |
| 當前工作、已完成工作、技術債 | [執行計劃索引](https://github.com/25Cloud/TuraIDC/blob/main/docs/internal/execution-plans/README.md)                                                                                |
| 一次性線上修復記錄           | [修復記錄](https://github.com/25Cloud/TuraIDC/blob/main/docs/internal/fix-records/README.md)                                                                                        |
| 版本變更記錄                 | [變更記錄](https://github.com/25Cloud/TuraIDC/blob/main/docs/internal/changelog.md)                                                                                                 |
| 工作規則與文件治理           | [治理規則](https://github.com/25Cloud/TuraIDC/blob/main/docs/internal/governance/README.md)                                                                                         |
| 官方文件站維護與釋出         | [文件官網維護指南](https://github.com/25Cloud/TuraIDC/blob/main/docs/internal/governance/docs-web-maintenance.md)                                                                   |
| 文件站翻譯與術語             | [多語言維護規則](https://github.com/25Cloud/TuraIDC/blob/main/docs/internal/i18n/README.md)、[術語表](https://github.com/25Cloud/TuraIDC/blob/main/docs/internal/i18n/glossary.md)                                                    |

## 2. 目錄邊界

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

中文佔根路徑，是唯一真源；其餘三種語言是它的映象，路徑一一對應。譯文頁面在 front matter 裡記錄中文源與源雜湊，由 `pnpm run docs:i18n` 跟蹤過期情況；繁體兩版由 `pnpm run docs:i18n:trad` 從中文生成。

生成物按內容歸屬放在所屬分割槽內（資料庫快照在 `develop/database.md`，後端 API 清單在 `develop/api/backend-api-catalog.md`），不再單獨設生成物目錄；它們的狀態在 `catalog.json` 中標記為 `generated`。

## 3. 狀態語義

`current` 表示當前可依賴的規則或快照；`active` 表示正在執行；`completed` 表示保留結果；`tech-debt` 表示已知欠賬；`needs-review` 表示不能在未對照程式碼前直接執行；`generated` 表示必須由對應指令碼重新整理；`archived` 只用於追溯。

[catalog.json](https://github.com/25Cloud/TuraIDC/blob/main/docs/catalog.json) 是機器可讀目錄。`pnpm run docs:check` 校驗目錄、連結、計劃結構和目錄覆蓋；`pnpm run docs:freshness` 掃描到期複核項。

## 4. 公開站與倉庫的邊界

- 公開文件站的內容源就是 `docs/`，`docs-web/.vitepress/config.ts` 用 `srcExclude` 把 `internal/**` 排除在站點產物之外；它仍受 `docs:check` 校驗。
- 公開站有四種語言：簡體中文（`/`）、English（`/en/`）、繁體中文臺灣（`/zh-tw/`）、繁體中文香港（`/zh-hk/`）。譯文規則見 [多語言維護規則](https://github.com/25Cloud/TuraIDC/blob/main/docs/internal/i18n/README.md)。
- 判斷一篇文件放哪裡：讀者是站長進 `guide/`，讀者是開發者進 `develop/`，只有維護者關心進 `internal/`。
- `guide/` 與 `develop/` 的正文裡不要連結 `internal/` 的文件——那會讓站點讀者點到 404。需要指向治理規則時用 GitHub 上的 URL（譯文頁由指令碼自動改寫為 GitHub 連結）。
