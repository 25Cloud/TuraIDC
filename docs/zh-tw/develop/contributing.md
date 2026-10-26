---
source: develop/contributing.md
sourceHash: ee9a52c508f4bb7e42faef0146a3256a229eef54b6624b4cc8c3598abdb3f444
generated: zh-tw
---

<!-- 本檔案由 docs-i18n.mjs trad zh-tw 從 docs/develop/contributing.md 產生，請勿手動編輯。 -->
# 參與貢獻

面向給 TuraIDC 提程式碼、改文件或報問題的貢獻者。本文只寫提交前必須知道的事；倉庫根目錄的 `AGENTS.md` 是給編碼代理用的導航圖，完整約束以倉庫內的工作規則為準（見文末連結）。

## 1. 動手之前

1. 先讀與任務相關的文件和現有程式碼，不要猜介面、業務或目錄結構。
2. 保持最小改動，不做無關重構；無呼叫方的廢棄程式碼不保留相容層。
3. 工作區可能是髒的：絕不回滾、覆蓋或格式化不是本次任務造成的改動。
4. 任何金鑰、密碼、Token、私鑰與生產資料都不得進入程式碼、文件、提交資訊或日誌。

## 2. 改動落在哪裡

| 改動型別                 | 落點                                                                                             |
| ------------------------ | ------------------------------------------------------------------------------------------------ |
| 後端業務與介面           | `backend/app/`，控制器薄層，第三方呼叫進專用 Service、Driver 或外掛                              |
| 外掛                     | `backend/plugins/{domain}/{slug}/`，特有邏輯不汙染系統級擴充套件點                                   |
| 三端前端                 | `frontend-admin-v3/`、`frontend-user-v3-www/`、`frontend-user-v4-console/`，跨端能力進 `shared/` |
| 使用者文件               | `docs/guide/`                                                                                    |
| 開發者文件               | `docs/develop/`                                                                                  |
| 執行計劃、設計、修復記錄 | `docs/internal/`，並登記 `docs/catalog.json`                                                     |

## 3. 提交前驗證

| 影響範圍   | 最小驗證                                                            |
| ---------- | ------------------------------------------------------------------- |
| 文件       | `pnpm run docs:check`                                               |
| `backend/` | `cd backend && php artisan test`（可先縮小到受影響檔案）            |
| 前端各端   | 對應目錄 `pnpm run build`；重構追加該應用的 `verify:refactor`       |
| `shared/`  | `pnpm run typecheck:shared && pnpm run test:shared`，再按影響端構建 |

前端介面改動按倉庫要求補對應 e2e 用例。跑不了驗證時，在說明裡寫清原因與未覆蓋的風險。

## 4. CLA 簽署與許可授權

本專案採用 **AGPL-3.0-or-later 與商業許可雙許可**。為了讓專案方能同時按兩種許可分發你的貢獻，**第一次提交 PR 前需要簽署[貢獻者許可協議（CLA）](https://github.com/25Cloud/TuraIDC/blob/main/CLA.md)**：

- **個人貢獻者**：在任意一個 PR 評論中回覆 `I have read the CLA Document and I hereby sign the CLA（我已阅读并同意 CLA.md）`，維護者登記後生效；也可向 `contact@turaidc.com` 發郵件簽署。
- **組織貢獻**（員工/外包人員在履職範圍內提交）：由組織授權代表按 [CLA.md 第二部分](https://github.com/25Cloud/TuraIDC/blob/main/CLA.md)向 `contact@turaidc.com` 發郵件簽署，並附指定貢獻者的 GitHub 使用者名稱清單。
- **生效時間與歷史貢獻**：CLA 自 **2026-10-21** 起生效，適用於此後的新貢獻。生效日前已提交的貢獻預設仍按 AGPL 授權，不自動追溯；歷史貢獻者可在簽署宣告中加「並適用於我在 2026-10-21 前已提交的全部貢獻」自願補籤，未補籤的第三方歷史貢獻不進入商業版本。詳見 [CLA 開頭的說明](https://github.com/25Cloud/TuraIDC/blob/main/CLA.md#生效日期历史贡献与可行性结论)。
- 簽署一次即覆蓋此後向本專案提交的全部貢獻，不需要每個 PR 重複籤；未簽署的 PR 會在評審時被提醒，無法取得再許可授權的貢獻可能不予合併。
- 貢獻一經合併，即同時按 AGPL-3.0-or-later 向公眾提供，並授權專案方以商業許可再分發；**已經按 AGPL 獲得的公眾權利不會減少**，你仍是自己貢獻的版權人。
- 不同意上述授權時請不要提交貢獻；你仍可以按 AGPL 自由使用本專案。行為規範見[行為準則](https://github.com/25Cloud/TuraIDC/blob/main/CODE_OF_CONDUCT.md)。

安全漏洞不要通過公開 Issue 或 PR 提交，請按[安全策略](https://github.com/25Cloud/TuraIDC/blob/main/SECURITY.md)私下報告。

## 5. 提交與協作

- 一個提交只做一件事，資訊用中文：`Fix:中文描述`、`Feat:中文描述`、`Refactor:中文描述`；倉庫鉤子要求冒號後帶一個空格。
- 改動通過 Pull Request 合入 `main`，描述裡寫清改動範圍、驗證命令與結果。
- 涉及路由、資料庫結構、部署方式、介面契約或使用者流程的改動，必須同步複核對應文件；確實無需文件變更時，在 PR 裡說明理由。
- 自動生成物（資料庫快照與後端 API 清單）只能通過指令碼重刷，禁止手工編輯。

## 關聯文件

- [工作規則](https://github.com/25Cloud/TuraIDC/blob/main/docs/internal/governance/WORKING_RULES.md)：協作、修改、驗證與提交的完整強制規則。
- [文件治理策略](https://github.com/25Cloud/TuraIDC/blob/main/docs/internal/governance/DOCUMENTATION_POLICY.md)：文件放置、結構與生命週期。
- [工程踩坑記錄](pitfalls.md)：提交、測試與增量部署環節的實測坑。
- [測試指南](testing.md)：測試環境、命令與範圍。
- [開源與商業許可](../guide/licensing.md)：AGPL 強制義務、商業授權適用場景與獲取方式。
- [貢獻者許可協議 CLA](https://github.com/25Cloud/TuraIDC/blob/main/CLA.md)、[商業許可草案](https://github.com/25Cloud/TuraIDC/blob/main/LICENSE-COMMERCIAL.md)。
