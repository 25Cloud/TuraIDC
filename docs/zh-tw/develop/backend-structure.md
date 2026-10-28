---
source: develop/backend-structure.md
sourceHash: 915c6d1592589d88f73c2812c46c3dd8dcd48e55a595c1a39901744201ec6880
generated: zh-tw
---

<!-- 本檔案由 docs-i18n.mjs trad zh-tw 從 docs/develop/backend-structure.md 產生，請勿手動編輯。 -->
# 後端目錄分類規範

- 文件性質：現行方向
- 對齊時間：`2026-07-02`
- 讀者畫像：後端開發、程式碼整理、文件維護人員
- 適用範圍：`backend/` 下目錄與新增 PHP 檔案的落點規則

## 1. 目標

本規範用於給 `backend` 建立穩定的目錄分類和檔案落點規則，減少“同一類檔案散落多處”“新檔案放哪全靠臨時判斷”“一個目錄裡堆滿跨域類”的問題。

本規範的執行原則：

1. 以當前真實程式碼結構為基礎做增量收斂。
2. 先規範新增檔案落點，再處理歷史雜項。
3. 不為了“看起來整齊”一次性大規模移動名稱空間。
4. 現有穩定引用路徑優先，不做無關重構。

## 2. backend 頂層目錄分類

| 目錄         | 分類       | 用途                                   | 規則                 |
| ------------ | ---------- | -------------------------------------- | -------------------- |
| `app/`       | 業務程式碼   | 控制器、服務、模型、支援類、命令、任務 | 長期業務程式碼只放這裡 |
| `bootstrap/` | 框架啟動   | Laravel 啟動配置                       | 不放業務邏輯         |
| `config/`    | 配置層     | 系統配置、驅動配置                     | 不放執行時資料       |
| `database/`  | 資料結構層 | migrations、seeders、factories         | 只放資料庫相關內容   |
| `public/`    | Web 入口層 | 對外公開入口、公開靜態資源             | 不放業務實現         |
| `routes/`    | 路由層     | admin/client/site/web/console 路由     | 只放路由註冊         |
| `scripts/`   | 維護指令碼   | 一次性匯出、排查、修復工具             | 不放長期業務類       |
| `storage/`   | 執行時輸出 | 日誌、快取、上傳、臨時檔案             | 不手寫業務原始碼       |
| `tests/`     | 測試層     | Feature、Unit、測試基類                | 測試檔案統一在此     |
| `vendor/`    | 第三方依賴 | Composer 依賴                          | 不手工修改           |

### 2.1 頂層禁止項

- 禁止把新業務 PHP 檔案直接放在 `backend/` 根目錄。
- 禁止把長期業務邏輯放進 `scripts/`。
- 禁止把介面、服務、模型混放在 `routes/`、`config/`、`public/`。
- 禁止把臨時除錯產物作為正式結構的一部分保留。

## 3. `app/` 目錄分類

當前 `app/` 目錄分為以下固定層：

| 目錄          | 分類       | 用途                               |
| ------------- | ---------- | ---------------------------------- |
| `Http/`       | 交付層     | 控制器、中介軟體、請求校驗、響應資源 |
| `Services/`   | 業務層     | 業務流程、領域服務、對上游封裝     |
| `Models/`     | 實體層     | Eloquent 模型                      |
| `Support/`    | 支撐層     | 跨域工具類、生成器、淨化器、值物件 |
| `Constants/`  | 常量層     | 狀態列舉、許可權碼、錯誤碼常量       |
| `Exceptions/` | 異常層     | 業務異常、自定義異常               |
| `Jobs/`       | 非同步任務層 | 佇列任務                           |
| `Console/`    | 命令層     | Artisan 命令                       |
| `Providers/`  | 註冊層     | Laravel Provider                   |
| `Traits/`     | 複用層     | 輕量共用 trait                     |
| `Casts/`      | 轉換層     | 自定義欄位 cast                    |

## 4. `Http/` 目錄分類規則

### 4.1 Controllers

當前控制器分類：

- `app/Http/Controllers/Admin/`
  - 管理端介面
- `app/Http/Controllers/Client/`
  - 使用者端介面
- `app/Http/Controllers/`
  - 只保留以下兩類
  - 基類控制器
  - 公共入口控制器，例如 `SecureAssetController.php`
- `app/Http/Controllers/Site/V2/`
  - 公開站點介面（首頁聚合、商品目錄、內容公告）

### 4.2 Controllers 落點規則

- 新增管理端介面：放 `Controllers/Admin/`
- 新增使用者端介面：放 `Controllers/Client/`
- 新增公開站點介面：放 `Controllers/Site/V2/`，與既有首頁/商品/內容控制器同域
- 頂層 `Controllers/` 禁止新增後臺/使用者側/站點側控制器

### 4.3 Requests

當前 `Requests/` 已按端區分：

- `app/Http/Requests/Admin/`
- `app/Http/Requests/Client/`

後續規則：

- 端內請求物件繼續按業務域落子目錄
- 例如：
  - `Admin/Content/`
  - `Admin/Product/`
  - `Admin/User/`
  - `Client/Auth/`
  - `Client/Invoice/`
  - `Client/Service/`

### 4.4 Resources

`app/Http/Resources/` 當前仍較多平鋪檔案。

收斂規則：

- 現有資源類暫不強制整體遷移
- 新增資源類按業務域建子目錄，避免繼續平鋪增長
- 推薦目錄：
  - `Resources/Admin/`
  - `Resources/Client/`
  - `Resources/Content/`
  - `Resources/Product/`
  - `Resources/User/`

## 5. `Services/` 目錄分類規則

### 5.1 當前狀態

`app/Services/` 現在已經混合了兩類內容：

1. 已經成形的領域目錄
   - `ClientServiceConsole/`
   - `ProductCatalog/`
   - `Order/`
   - `Upstream/`
   - `User/`
2. 大量仍平鋪在頂層的服務類

這說明當前最容易變亂的就是 `Services/`。

### 5.2 頂層 `Services/` 的保留用途

以後頂層 `Services/` 只保留：

- 跨域編排服務
- 舊結構遺留但暫未遷移的類
- 單檔案級別的系統服務，且短期內不會擴充套件成一個子域

例如當前可接受繼續留頂層的型別：

- `AuthService.php`
- `PaymentService.php`
- `NotificationService.php`
- `ScheduleTaskService.php`

### 5.3 新增服務落點規則

新增服務優先按業務域進入子目錄，不再預設平鋪在 `Services/` 根下。

推薦收斂目錄如下：

| 目錄                             | 放什麼                                                |
| -------------------------------- | ----------------------------------------------------- |
| `Services/User/`                 | 使用者資料、使用者賬變、使用者管理相關服務                  |
| `Services/Order/`                | 下單、賬單、支付投影、支付編排                        |
| `Services/ProductCatalog/`       | 商品、分類、供應商商品對映、目錄能力                  |
| `Services/ClientServiceConsole/` | 服務控制台、監控、NAT、安全組、VNC                    |
| `Services/Upstream/`             | 供應商驅動基座、能力契約、provider 解析與具體驅動實現 |
| `Services/Content/`              | 文章、分類、媒體庫、站點內容                          |
| `Services/Referral/`             | 推薦、返佣、提現、等級                                |
| `Services/Site/`                 | 站點聚合、首頁、SEO、公開產品讀取                     |
| `Services/Automation/`           | 自動化任務、賬單清理、服務生命週期自動化              |
| `Services/Integrations/Plugins/` | 外掛掃描、安裝、配置、執行時註冊和領域 adapter        |

第三方真實實現落點：

- 支付、實名、簡訊、郵件、上游伺服器的真實 provider 實現優先放 `backend/plugins/{domain}/{slug}/`。
- 平臺側 `app/Services/Integrations/Plugins/` 只保留外掛基礎設施和 adapter，不承載具體供應商協議細節。
- 通用上游 provider 解析、能力契約和共享 transport 仍放 `Services/Upstream/`。
- 不再新增 `Services/Integration/` 單數目錄；歷史引用統一核對當前真實目錄。

### 5.4 什麼時候建立新子目錄

滿足以下任一條件就應建新目錄，而不是繼續平鋪：

1. 同一業務域預計會有 3 個及以上服務類。
2. 同一業務域同時存在“讀服務 + 寫服務 + 對上游適配”。
3. 服務名已經開始出現共同字首，例如 `Site*Service`、`Referral*Service`。
4. 一個服務需要配套 DTO、解析器、專用幫助類。

### 5.5 不允許的新增方式

- 禁止繼續新增 `XxxService.php.new`、`XxxService_v2.php`、`NewXxxService.php`
- 禁止把同一業務域的 5 到 10 個服務長期平鋪在根目錄
- 禁止用“供應商專用邏輯”汙染通用服務類

## 6. `Models/` 目錄分類規則

`app/Models/` 當前保持平鋪。

這是當前可接受狀態，因為：

- Laravel 模型天然高頻被引用
- 模型拆分名稱空間會帶來更高的遷移成本
- 當前模型數量雖多，但語義仍清晰

因此模型層規則如下：

- 現階段模型繼續平鋪，不做目錄化大遷移
- 新增模型優先保持單實體一檔案
- 只有當某個模型需要強繫結同域輔助物件時，優先把輔助物件放 `Support/` 或 `Casts/`，不要急著拆 `Models/`

## 7. `Support/` 目錄分類規則

`app/Support/` 只放跨域支撐類，不放業務流程。

可以放的型別：

- 響應建構子
- 編號生成器
- 純工具值物件
- 淨化器
- URL/檔案輔助類
- 站點配置讀取器

不該放的型別：

- 含大量業務查詢的服務
- 直接改寫業務狀態的流程類
- 某一單一業務域獨享的“偽工具類”

如果一個類只服務某單一業務域，優先回到該業務域目錄，不放 `Support/`。

## 8. `Constants` / `Exceptions` / `Traits` 規則

### 8.1 Constants

- 放狀態列舉、許可權碼、錯誤碼常量
- 禁止放執行時依賴資料庫的邏輯

### 8.2 Exceptions

- 放業務異常和自定義異常型別
- 不把普通流程控制寫成異常類

### 8.3 Traits

- 只放輕量複用片段
- Trait 超過一個業務域的理解成本時，應回收成 Service 或 Support 類

## 9. 新增檔案落點速查

| 你要新增的檔案  | 應放目錄                                    |
| --------------- | ------------------------------------------- |
| 管理端控制器    | `app/Http/Controllers/Admin/`               |
| 使用者端控制器    | `app/Http/Controllers/Client/`              |
| 公開站點控制器  | `app/Http/Controllers/`                     |
| 管理端請求校驗  | `app/Http/Requests/Admin/<业务域>/`         |
| 使用者端請求校驗  | `app/Http/Requests/Client/<业务域>/`        |
| 業務資源類      | `app/Http/Resources/<业务域>/` 或先按端區分 |
| 跨域響應/工具類 | `app/Support/`                              |
| 領域服務        | `app/Services/<业务域>/`                    |
| 佇列任務        | `app/Jobs/`                                 |
| Artisan 命令    | `app/Console/Commands/`                     |
| 遷移            | `database/migrations/`                      |
| 一次性維護指令碼  | `scripts/`                                  |
| 測試            | `tests/Feature/` 或 `tests/Unit/`           |

## 10. 當前需要重點防亂的區域

按當前倉庫現狀，優先控制以下區域繼續變亂：

1. `app/Services/`
2. `app/Http/Resources/`
3. `scripts/`
4. `backend/` 根目錄臨時檔案

## 11. 執行建議

### 11.1 現在就執行的規則

- 新增業務服務優先進入子目錄，不再預設平鋪
- 新增請求物件必須按端區分
- 新增公開站點控制器只允許放 `Controllers/` 頂層
- 新增文件型說明不放 `backend/` 根目錄

### 11.2 暫不做的事

- 暫不整體遷移 `Models/`
- 暫不批次改已有服務名稱空間
- 暫不因為文件規範而一次性移動全部歷史檔案

### 11.3 後續可選收斂順序

如果後面要繼續整理，建議按這個順序做：

1. 先收斂 `Services/`
2. 再收斂 `Http/Resources/`
3. 最後清理根目錄和 `scripts/` 的歷史雜項

## 12. 禁止項

- 禁止把新增業務檔案丟進 `backend/` 根目錄
- 禁止用檔名字尾區分版本，如 `.new`、`_v2`、`final`
- 禁止把“臨時排查指令碼”當成正式業務實現長期保留
- 禁止跨 admin/client/site 混放控制器
- 禁止在 `Support/` 裡塞入重業務流程
