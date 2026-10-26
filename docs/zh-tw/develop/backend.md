---
status: current
updated: 2026-09-13
owner: backend-platform
source: develop/backend.md
sourceHash: 8f5b9f6107c37132433e79a5e2845d79f04fef76d0b8ed6a95eea5e022c33ad7
generated: zh-tw
---

<!-- 本檔案由 docs-i18n.mjs trad zh-tw 從 docs/develop/backend.md 產生，請勿手動編輯。 -->
# 後端工程規範

本文件定義 TuraIDC Laravel 12 後端後續人工開發、審查、重構和第三方整合治理的長期標準。目標是讓後端程式碼可讀、可測、可替換、可追蹤，並降低後續人工維護成本。

重要約束：當前開發文件、開發規範和其他約束性文件不具備完全真實性，不保證完全真實。後續治理必須以當前程式碼檢查、真實路由、可觀察執行行為和必要的受控驗證為主，發現文件與當前程式碼衝突時，應先記錄衝突，再按檢查結論更新補充文件。

治理執行原則：審計要覆蓋關鍵目錄和風險域，但修改只落在有價值檔案上；不得為了“覆蓋”機械加註釋、機械格式化或製造無意義改動。自動生成的 `docs/develop/api/backend-api-catalog.md` 不手工編輯。

## 1. 治理目標

1. Controller 保持薄層，業務邏輯收斂到 Service、Action、Domain Service 或專用整合客戶端。
2. 引數驗證、響應轉換、業務流程、第三方協議、日誌審計各自有清晰邊界。
3. API 請求和響應工程化，避免參數歸一化、響應欄位轉換、錯誤對映散落在 Controller 或第三方驅動裡。
4. 支付、實名認證、ZJMF 財務/ZJMF 雲、簡訊、郵件、物件儲存等第三方能力以外掛/驅動方式接入，業務層依賴專案內部介面而不是具體供應商。
5. 所有高風險業務具備可測試入口，包括支付回撥、餘額扣減、服務開通、上游失敗、許可權校驗。
6. 必要註釋解釋業務原因、邊界條件、第三方協議差異和歷史相容，不寫無意義的逐行翻譯。
7. 後端註釋、API 錯誤 `message`、欄位校驗錯誤和前端使用者可見提示必須使用簡體中文。
8. 建立審查閉環，防止錯審、漏審和審完不報。
9. 新增或修改程式碼必須遵循同一模組現有的命名、目錄、分層、異常處理、響應結構和測試組織方式；確需引入新模式時，必須在對應設計或執行計劃中說明原因，並同步更新相關規範。
10. 規則變化必須同步專案文件和 TuraIDC 相關 skills，避免後續代理和人工開發繼續沿用舊約定。

## 2. 非目標

1. 不做一次性大重寫。
2. 不為了抽象破壞當前穩定支付、賬單、服務例項、回撥鏈路。
3. 不未經確認執行資料庫初始化、遷移或歷史遷移補跑。
4. 不手工編輯自動生成的 `docs/develop/api/backend-api-catalog.md`。
5. 不把所有第三方能力塞進一個萬能介面。

## 3. 後端分層標準

### Controller

- 只負責接收請求、鑑權上下文、呼叫應用服務、返回響應。
- 不直接拼裝第三方請求。
- 不直接呼叫 `Http::*`。
- 不寫複雜業務分支。
- 不在 Controller 中散落事務。

### FormRequest

- 請求引數、型別、範圍、列舉值、業務前置約束優先放入 FormRequest。
- 複雜校驗可以委託 Rule 或 Service，但 Controller 不應手寫 validate。

### Resource

- 負責 API 輸出結構轉換。
- 不承載業務判斷。
- 不直接訪問第三方或觸發寫操作。

### Service / Action

- Service 按業務領域聚合，例如賬單、支付、餘額、服務例項、上游整合。
- 單次清晰業務動作可抽為 Action，避免一個 Service 無限膨脹。
- 涉及財務、審計、狀態流轉的 Service 必須考慮事務、冪等、審計欄位和併發鎖。

### Model

- Model 保持資料模型、關聯、scope、cast、輕量領域狀態判斷。
- 不放跨表複雜流程。
- `$fillable` 必須精確，不開放敏感欄位批次賦值。

### Job / Listener / Command

- 只做非同步排程和流程編排，具體業務邏輯委託 Service。
- Job 必須明確 `$tries`、`$timeout`、`$backoff` 或說明不需要的原因。
- 排程任務涉及併發時使用 `withoutOverlapping()`。

## 4. 第三方外掛化標準

第三方能力按“能力域介面 + Provider 驅動 + DTO + 統一異常 + 配置註冊 + fake provider 測試替身”治理。

### 推薦目錄

```text
backend/app/Contracts/Integrations/
backend/app/Services/Integrations/
backend/app/Services/Integrations/Payments/
backend/app/Services/Integrations/Identity/
backend/app/Services/Integrations/Notifications/
backend/app/Services/Integrations/Storage/
backend/app/Services/Integrations/Drivers/
backend/app/Services/Upstream/            # 上游驱动独立领域
backend/app/Services/Upstream/Contracts/  # UpstreamDriver 基接口 + Provides* 能力接口
backend/app/Services/Upstream/Drivers/    # 应用内驱动实现（HostingPanelApi）
backend/config/integrations.php
backend/tests/Fakes/Integrations/
```

### 推薦介面

- `PaymentGatewayInterface`：支付建立、查詢、關閉、回撥驗籤、回撥解析。
- `RefundGatewayInterface`：退款申請、退款查詢、退款回撥解析。
- `IdentityVerificationProviderInterface`：實名二要素、三要素或供應商支援的實名能力。
- 上游驅動契約位於 `app/Services/Upstream/Contracts/`：基介面 `UpstreamDriver`（`key()`/`label()`/`capabilities()`/`supports()`/`resolve()`），開通、續費、狀態同步、供應商餘額、控制台、網路、安全組、賬單恢復等能力按 `Provides*` 介面逐項宣告（如 `ProvidesProvisioning`、`ProvidesRenewal`、`ProvidesStatusSync`、`ProvidesSupplierBalance`），驅動按需實現，不要求全能。
- `SmsProviderInterface`：簡訊傳送、模板校驗、傳送結果查詢。
- `ObjectStorageProviderInterface`：上傳、下載、臨時連結、刪除。

### 強制要求

1. 業務 Service 依賴專案內部介面，不依賴具體 SDK 或供應商欄位。
2. Provider 原始響應必須轉換為專案內部 DTO。
3. Provider 異常必須對映為統一業務異常。
4. 回撥必須先通過統一入口處理簽名、日誌、冪等，再分發到具體 provider。
5. 日誌必須包含 trace_id、provider、能力型別、資源 ID、結果狀態，並對敏感欄位脫敏。
6. 每個 Provider 必須宣告能力，例如是否支援退款、實名三要素、服務暫停、續費、重試。
7. 必須提供 fake provider 或 mock gateway，用於自動化測試和本地開發。
8. 允許舊 Provider 和新 Provider 並存，歷史記錄按原 provider 解釋，新請求可按配置切換。

### 當前落地進度

- 外掛執行時已落地 `IntegrationPluginService`、`PluginInstaller`、`PluginScanner`、`PluginRuntimeRegistry`、`PluginConfigRepository` 和能力域 adapter。
- 外掛檔案位於 `backend/plugins/{domain}/{slug}/`，當前能力域包含支付閘道器、實名認證、簡訊、郵件、上游伺服器。
- 外掛配置由 `integration_plugins`、`integration_plugin_configs` 管理；敏感配置必須加密儲存，API 只返回是否已配置和脫敏預覽。
- 支付閘道器已通過 `PaymentGatewayInterface`、`PaymentGatewayManager` 和外掛支付介面卡接入；`payments.gateway` 仍保留歷史業務值，閘道器外掛 key 與業務 gateway 值不得混用。
- 後續新增支付方式必須新增外掛或 driver，並通過外掛執行時註冊，不得在 `PaymentService` 或 Controller 中直接注入具體 provider。
- 實名認證能力通過外掛實名介面卡接入，初始化、掃碼連結、查詢結果、回撥驗籤、收費配置必須通過內部 DTO/介面表達，業務層不得依賴供應商原始陣列。
- 簡訊、郵件能力通過外掛介面卡接入，供應商英文或技術類錯誤必須對映為簡體中文業務提示。
- 上游供應商驅動統一經 `PluginRuntimeRegistry` 從已啟用的 upstream 域外掛解析，由 `ProviderRegistry` 按 `key()` 彙總輸出；外掛驅動統一包裝為 `PluginUpstreamDriver`，執行時經 `server.resolve_capability` 取能力物件。當前 provider key：`hosting_panel_api`（`ProviderKey` 常量，應用內 transport）、`zjmf_finance_api`（ZJMF 財務外掛）、`tura_open_api`（TuraIDC 開放介面外掛）。
- 上游 provider 已通過能力物件宣告服務開通、續費、控制台、網路、安全組、狀態同步等能力；`zjmf_finance_api` 必須繼續解析到ZJMF外掛 adapter，不得摺疊為共享主機面板 transport。
- ZJMF 財務/ZJMF 雲和主機面板快取 key 必須 provider-aware；是否保留舊 key 讀取只能由當前已批准的執行計劃明確，禁止把 `zjmf_finance_api` 歸一化為 `hosting_panel_api`。
- 開放 API（`/api/v2/open/*`）是獨立閘道器層：`api_keys` 只存雜湊（明文僅建立時返回一次），`api.key` 中介軟體負責金鑰認證 + scope 授權 + IP 白名單 + 呼叫審計（`api_key_usage_logs`，每週由 `open-api:prune-usage-logs` 清理）；Open 控制器保持薄層並複用現有 Service。
- 物件儲存等尚未外掛化的能力域接入前，必須先補內部 contract、DTO、錯誤對映、fake provider 或外掛測試替身。

### 禁止做法

- Controller 直接選擇支付寶、微信、ZJMF 財務等 provider。
- 業務表直接儲存不可解釋的第三方原始響應作為唯一狀態來源。
- 一個萬能 `PluginInterface` 覆蓋支付、實名、上游、簡訊、儲存所有能力。
- 沒有回撥冪等和迴歸測試就切換真實供應商。

## 5. API 請求與響應工程化

API 治理必須同時覆蓋請求入口和響應出口。新增或重構介面時，以 `docs/develop/api/format.md` 為當前 API 規範文件，並以當前程式碼檢查結果反向補充該文件。

### 請求側

- 複雜介面必須使用 FormRequest，Controller 不應手寫大量引數讀取和型別轉換。
- 請求欄位統一 `snake_case`。
- 分頁統一 `page`、`page_size`。
- 排序、篩選、布林、列舉、金額、日期範圍必須在 FormRequest 或 Request DTO 中歸一化。
- 金額必須明確單位，支付/餘額相關不得隱式使用 float。
- 跨 Service 傳遞的請求引數推薦轉為 Command/DTO。

### 響應側

- Controller 使用統一響應方法或丟擲統一業務異常。
- Service 不直接返回 HTTP Response。
- 面向前端欄位由 Resource 或 Response DTO 控制。
- 第三方原始響應不得直接穿透到 Controller 或前端。
- 敏感欄位預設不返回。
- 錯誤響應必須統一業務碼、HTTP 狀態碼和 `message`。
- 相容欄位必須說明保留原因和移除條件。

### 文件同步

- API 請求/響應規則變化時，優先更新 `docs/develop/api/format.md`。
- 路由清單變化時按指令碼重刷 `docs/develop/api/backend-api-catalog.md`，禁止手工編輯該檔案。
- 影響前端欄位時，必須同步前端呼叫和測試建議。

## 6. 註釋標準

### 必須註釋

- 第三方協議差異和非顯然欄位對映。
- 財務、賬單、支付、餘額、服務例項狀態流轉中的關鍵業務約束。
- 冪等鍵、鎖、事務邊界的設計原因。
- 為相容歷史資料而保留的特殊邏輯。
- public interface、DTO、關鍵 Service public 方法、Job 的業務目的和副作用。
- 所有新增註釋優先使用簡體中文，便於後續人工維護。

### 不建議註釋

- “給變數賦值”“呼叫某方法”這類程式碼本身已經表達清楚的內容。
- 與程式碼不同步的長段背景說明。
- 包含金鑰、token、真實賬號、供應商私密引數的內容。
- 面向維護者的中文註釋中夾雜未解釋的英文業務縮寫。

### 推薦註釋形式

- 介面和 DTO 用 DocBlock 說明輸入輸出語義。
- 複雜業務分支前用 1 到 3 行說明“為什麼這樣做”。
- 歷史相容邏輯標註觸發條件和移除前提。

## 7. 錯誤、日誌與審計

- API 響應使用統一錯誤結構。
- 成功響應固定 `code = 0`。
- API 返回的 `message` 和 `data.errors` 中使用者可見錯誤必須使用簡體中文。
- 不把第三方原始錯誤直接暴露給使用者，必須對映為專案內部簡體中文提示。
- 關鍵業務日誌必須可串聯 trace_id、actor、resource、provider、operation。
- 日誌不得輸出 secret、token、password、私鑰、完整證件號。
- 財務和審計相關寫入必須記錄操作者、來源、IP、trace_id 或等價欄位。
- 設定、供應商、支付、實名、上游等配置和憑據輸出必須使用白名單 Resource/DTO；`api_key`、secret、token、私鑰不得出現在 API 響應。
- 敏感配置更新採用空值保留、非空替換，不能用掩碼值或空字串覆蓋真實配置。
- 外掛配置中的敏感項必須走加密儲存和脫敏預覽；更新時空值表示保留舊值，不得覆蓋為掩碼或空字串。
- `payments` 表為財務審計邊界，任何維護命令、清理命令和測試不得物理刪除 Payment 記錄；只能輸出只讀報告、標記異常或跳過處理。
- `balance_logs` 等財務流水也屬於審計鏈路，不得用物理刪除掩蓋撤銷、恢復或退款，必須使用反向流水、狀態投影或明確補償狀態。
- 支付寶原路退款、餘額退款、混合支付餘額恢復必須同步賬單退款投影欄位，收入統計必須排除或抵扣已退款賬單。
- 支付和實名回撥必須在路由層先過簽名中介軟體，Service 層保留二次驗籤、金額/狀態校驗、冪等和事務鎖。
- VNC、支付、實名、上游等日誌必須使用統一脫敏工具或專用 `mask*` 方法處理 token、sign、password、secret。
- 公開 VNC token 必須一次性消費、短 TTL、繫結必要上下文，不得在公開解析響應中返回 VNC 密碼、原始 token 或完整 URL。

## 8. 審查閉環與防漏審

後端體檢、重構審查和第三方整合審查必須輸出明確報告，不允許只做內部檢查後不報告。

強制要求：

- 輸出審查範圍矩陣，列出目錄、模組、介面、風險域、是否已覆蓋。
- 每個 Blocker / High 必須有檔案路徑、方法名、路由或命令證據。
- 每個審查維度必須標註“已檢查 / 未覆蓋 / 需進一步驗證”。
- 未覆蓋項必須說明原因、風險和後續補查建議。
- 審查發現必須按嚴重級別排序並給修復優先順序。
- 發現無問題的維度也必須寫“未發現問題”和檢查依據。
- 報告末尾必須包含完整性自檢表。

完整性自檢表至少檢查：

- 是否讀取 `AGENTS.md`。
- 是否以當前程式碼和真實路由為主。
- 是否檢查 HARD-GATE。
- 是否檢查 API 請求/響應工程化。
- 是否檢查第三方外掛化。
- 是否檢查簡體中文體驗。
- 是否列出未覆蓋項。
- 是否給出修復優先順序。
- 是否給出文件/skills 同步建議。

## 9. 測試標準

必須優先補齊：

- 支付建立、回撥、重複回撥、失敗回撥。
- 餘額扣減、併發扣減、退款或撤銷。
- 實名認證成功、失敗、供應商異常、欄位異常。
- ZJMF 財務/ZJMF 雲上游開通、續費、暫停、重啟、同步失敗。
- 許可權碼缺失、水平越權、使用者資源歸屬。
- Provider fake 驅動下的本地可重複測試。
- 敏感配置讀取/編輯不洩露原值。
- 供應商列表、詳情、建立、更新響應不包含 `api_key` 原值。
- 公開 VNC token 不返回密碼、重複消費失效、過期失效、日誌脫敏。
- 使用者端賬單詳情不返回管理端內部欄位和第三方交易號。
- 維護命令不會刪除 `payments`。
- 餘額恢復和財務反轉不會刪除原始 `balance_logs`，保留反向流水或補償審計記錄。
- 支付原路退款覆蓋成功、失敗、重複退款、金額不一致和事務回滾。
- 收入統計不把已退款賬單計入正向收入。
- 關鍵 Job 失敗不吞異常，能夠進入重試、failed_jobs 或明確補償狀態。

後端改動預設執行：

```cmd
cd backend
php artisan test
```

如全量測試存在歷史基線失敗，必須補充 targeted test，並明確本次改動是否新增失敗。

執行後端測試前，必須確認測試庫隔離生效：`APP_ENV=testing`，測試連線不得指向真實業務庫，非 sqlite 測試庫名應包含 `test`。未完成隔離前不得執行全量測試。

## 10. 文件與 skills 同步

觸發以下變化時，必須同步專案文件和 TuraIDC 相關 skills：

- 新增或調整後端分層規則。
- 新增第三方 provider 或調整 provider 接入方式。
- 修改支付、實名、ZJMF 財務/ZJMF 雲、簡訊、物件儲存等整合邊界。
- 修改 API 響應格式、錯誤碼、分頁結構、許可權碼。
- 修改 API 請求引數、響應欄位、Resource、FormRequest、Request DTO、Response DTO。
- 修改 API 錯誤 `message`、前端使用者可見提示、欄位校驗錯誤等簡體中文體驗規則。
- 修改審查閉環、防漏審、防審完不報規則。
- 修改佇列、排程、回撥、部署假設。
- 新增必須遵守的註釋、日誌、測試規則。
- 當前程式碼檢查結果證明既有開發文件、開發規範或約束文件已經失真。

同步位置：

- 專案規則：`AGENTS.md`，僅在規則升級且確認為倉庫級硬約束時更新。
- 後端文件：`docs/develop/backend.md`。
- API 格式：`docs/develop/api/format.md`。
- API 分組導航：`docs/develop/api/catalog-navigation.md`。
- 本機 `.agents/skills/turaidc/` 下的 TuraIDC 技能（`SKILL.md`、`code-review/SKILL.md` 與 `references/11-backend-engineering-governance.md`），路徑因機器而異，不入庫。

禁止手工編輯：

- `docs/develop/api/backend-api-catalog.md`，該檔案由指令碼生成。

同步原則：

1. 當前程式碼檢查結論優先於舊文件描述。
2. 文件更新必須標明新的真實約束、適用範圍和不再可信的舊口徑。
3. 不確定的內容標註“需進一步驗證”，不要為了補齊文件而編造規則。
4. 對規則類文件的更新必須儘量小而準，避免把臨時實現誤寫成長期規範。

## 關聯文件

- [架構現狀說明](architecture.md)：系統結構與執行模型。
- [後端目錄分類規範](backend-structure.md)：新增檔案落點。
- [API 格式規範](api/format.md)：請求與響應契約。
- [工作規則](https://github.com/25Cloud/TuraIDC/blob/main/docs/internal/governance/WORKING_RULES.md)：驗證與提交要求。
