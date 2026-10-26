---
source: develop/api/format.md
sourceHash: ef915f39a3975177dd951681106956e42b5544f34703fa753593f664bfefa98b
generated: zh-hk
---

<!-- 本檔案由 docs-i18n.mjs trad zh-hk 從 docs/develop/api/format.md 產生，請勿手動編輯。 -->
# API 格式規範

- 文檔性質：現行方向
- 對齊時間：`2026-09-13`
- 讀者畫像：後端開發、前端聯調、接口測試、文檔維護人員
- 適用範圍：`backend` 下所有返回 JSON 的 HTTP API，主要覆蓋 `/api/v2/admin/*`、`/api/v2/client/*`、`/api/v2/site/*`、`/api/v2/open/*`（開放 API）、`/api/v2/zjmf/*`（ZJMF 上游協議）及少量獨立公共接口。

## 1. 文檔目標

本文定義本倉庫後端 API 的統一請求處理、統一返回格式、錯誤碼區間、分頁結構、校驗失敗結構和新增接口的落地約束。

發生衝突時按以下順序處理：

1. 當前代碼真實行為、真實路由、受控驗證結果
2. `AGENTS.md`
3. `docs/internal/designs/backend/direct-api-refactor.md`
4. 本文檔
5. 舊接口習慣或歷史藍圖

注意：當前開發文檔、開發規範和其他約束性文檔不具備完全真實性。API 治理必須以當前代碼檢查為主，發現本文檔與當前代碼衝突時，先記錄衝突，再按檢查結論更新本文檔或相關實現。

接口審查時，歷史舊接口快照僅作為審查證據；當前目標結構以 `docs/internal/designs/backend/direct-api-refactor.md`、本文和自動生成的 `后端API清单.md` 為準。

### 1.1 直接重構邊界

API 直接重構已完成，後續遵循以下 V2 邊界：

- 舊接口：`/api/admin/*`、`/api/client/*`、`/api/site/*` 已刪除，不保留結構、字段或參數兼容層。
- 當前接口：統一落在 `/api/v2/admin/*`、`/api/v2/client/*`、`/api/v2/site/*`，按本文和 `docs/internal/designs/backend/direct-api-refactor.md` 一次性採用新規範。
- 前端調用：按頁面或業務域完整消費 V2 接口，不混用歷史結構。
- 文檔維護：路由、請求、響應、測試和字段邊界以 V2 接口為準。

## 2. 統一響應外層

除文件流、受控資源查看等非 JSON 響應外，API 統一返回以下外層結構：

```json
{
  "code": 0,
  "message": "操作成功",
  "data": {},
  "timestamp": 1760000000
}
```

字段説明：

- `code`
  - 業務碼
  - 成功固定為 `0`
  - 失敗為非 `0` 整數
- `message`
  - 給前端和聯調使用的可讀提示
  - 必須使用簡體中文，不得返回英文、拼音、繁體中文或第三方原始錯誤文案
- `data`
  - 業務數據
  - 可為對象、數組、標量或 `null`
- `timestamp`
  - Unix 秒級時間戳

當前實現來源：

- `backend/app/Support/ApiResponseBuilder.php`
- `backend/app/Traits/ApiResponse.php`

## 3. 成功響應規範

### 3.1 普通成功

```json
{
  "code": 0,
  "message": "操作成功",
  "data": {
    "id": 123,
    "name": "示例"
  },
  "timestamp": 1760000000
}
```

### 3.2 無返回數據

用於刪除、退出、純狀態切換等操作：

```json
{
  "code": 0,
  "message": "已退出登录",
  "data": null,
  "timestamp": 1760000000
}
```

### 3.3 列表/分頁成功

標準分頁結構放在 `data` 內：

```json
{
  "code": 0,
  "message": "操作成功",
  "data": {
    "list": [],
    "total": 0,
    "page": 1,
    "page_size": 20
  },
  "timestamp": 1760000000
}
```

分頁字段含義：

- `list`: 當前頁數據
- `total`: 總條數
- `page`: 當前頁碼
- `page_size`: 當前每頁數量

當前標準分頁由 `ApiResponseBuilder::pagination(...)` 生成。

## 4. 失敗響應規範

失敗時外層結構不變，只是 `code != 0`。

```json
{
  "code": 40400,
  "message": "商品不存在或已下架",
  "data": null,
  "timestamp": 1760000000
}
```

### 4.1 業務碼到 HTTP 狀態碼映射

默認映射規則：

| 業務碼區間 | 默認 HTTP 狀態 |
| ---------- | -------------- |
| `50000+`   | `500`          |
| `42200+`   | `422`          |
| `40900+`   | `409`          |
| `40400+`   | `404`          |
| `40300+`   | `403`          |
| `40100+`   | `401`          |
| `40000+`   | `400`          |

説明：

- 當前映射邏輯位於 `ApiResponseBuilder::httpStatusForErrorCode(...)`
- 個別接口可顯式覆蓋 HTTP 狀態碼，例如回調驗籤失敗當前返回 `code=40001`，HTTP 狀態為 `401`

### 4.2 當前常見業務碼

| 業務碼  | 語義                             | 當前常見來源                                    |
| ------- | -------------------------------- | ----------------------------------------------- |
| `0`     | 成功                             | 全局統一                                        |
| `40001` | 簽名驗證失敗                     | `VerifyCallbackSignature`                       |
| `40100` | 未登錄、未認證或登錄已過期       | 認證異常、權限中間件                            |
| `40300` | 無權限、角色不匹配、賬號被禁用   | `ensure.admin` / `ensure.client` / `permission` |
| `40301` | 電郵驗證或實名認證前置條件未滿足 | `EnsureEmailIsVerified`                         |
| `40400` | 資源不存在                       | 控制器內資源查詢失敗                            |
| `42200` | 參數驗證失敗或一般業務校驗失敗   | `ValidationException`、`BusinessException`      |
| `50000` | 通用服務端錯誤                   | 未分類服務異常                                  |

## 5. 參數校驗失敗格式

Laravel `ValidationException` 已統一改寫為 JSON：

```json
{
  "code": 42200,
  "message": "参数验证失败",
  "data": {
    "errors": {
      "email": ["邮箱不能为空"],
      "page_size": ["page size 必须大于 0"]
    }
  },
  "timestamp": 1760000000
}
```

約定：

- HTTP 狀態碼固定 `422`
- 詳細字段錯誤放在 `data.errors`
- `errors` 的值保持 Laravel 原生數組結構，前端不要假設只有一條消息

當前實現來源：`backend/bootstrap/app.php`

## 6. 鑑權與權限相關格式

### 6.1 Token 認證

管理端與用戶端都通過 Header 傳 Bearer Token：

```http
Authorization: Bearer <token>
Accept: application/json
```

### 6.2 認證失敗

示例：

```json
{
  "code": 40100,
  "message": "未登录或登录已过期",
  "data": null,
  "timestamp": 1760000000
}
```

### 6.3 權限不足/角色不匹配

示例：

```json
{
  "code": 40300,
  "message": "无操作权限",
  "data": null,
  "timestamp": 1760000000
}
```

### 6.4 前置條件不滿足

例如電郵未驗證或未完成實名認證：

```json
{
  "code": 40301,
  "message": "请先完成实名认证",
  "data": null,
  "timestamp": 1760000000
}
```

## 7. 分頁與查詢參數規範

### 7.1 標準分頁參數

新增接口統一使用：

- `page`
- `page_size`

示例：

```http
GET /api/v2/admin/products?page=1&page_size=20
```

### 7.2 歷史參數邊界

歷史舊接口曾存在 `per_page` 等兼容參數；當前 V2 路由不再接受或返回這些字段。

規則：

- V2 接口禁止接受 `per_page`、`pageSize`、`limit/offset` 作為標準分頁參數，除非該接口不是分頁列表而是明確的業務限制參數，例如監控點數量上限。
- V2 分頁返回結構只能使用 `page_size`，不得額外返回 `per_page`。
- 前端請求統一使用 `page`、`page_size`。

## 8. 字段與命名規範

### 8.1 通用命名

- URL 路徑：小寫、按資源語義命名
- 請求字段：`snake_case`
- 響應字段：`snake_case`
- 表名、字段名、篩選參數名保持一致優先

### 8.2 布爾參數

如果控制器通過 `booleanQuery(...)` 讀取 query 參數，應接受常見布爾表達：

- `1` / `0`
- `true` / `false`
- `on` / `off`
- `yes` / `no`

非法布爾值應拋出校驗異常，統一進入 `42200`

## 9. 文件上傳與非 JSON 例外

### 9.1 文件上傳

上傳類接口請求體允許使用 `multipart/form-data`，但響應仍應回到統一 JSON 外層。

### 9.2 非 JSON 例外

以下接口可不走統一 JSON 外層：

- 直接文件流響應
- 受控資源預覽
- WebSocket

當前典型例外：

- `/api/secure-assets/view`

除這些例外，普通 HTTP API 不應返回裸數組、裸字符串或自定義包裹結構。

## 10. API 請求工程化規範

API 請求處理必須工程化，避免 Controller 裡散落參數讀取、類型轉換、默認值、權限上下文和業務前置判斷。

### 10.1 請求入口

- 新增寫操作、複雜查詢、批量操作、導入導出、支付/賬單/實名/上游相關接口必須使用 `FormRequest`。
- 簡單隻讀詳情接口可以使用路由參數，但資源歸屬、權限和業務可見性必須交給 Service 或 Policy/權限中間件處理。
- Controller 不應直接調用 `$request->validate(...)`，除非是極小範圍兼容舊接口，並在後續治理中遷移到 FormRequest。
- Controller 不應手寫大量 `$request->input(...)`、`$request->query(...)` 後再拼業務數組；應優先使用 `$request->validated()`。

### 10.2 請求字段

- 請求字段統一使用 `snake_case`。
- 布爾、枚舉、金額、日期範圍、分頁參數必須在 FormRequest 中完成校驗和歸一化。
- 金額字段必須明確單位；涉及財務、餘額、支付時優先使用整數分或字符串 decimal，不允許隱式 float。
- 日期時間字段必須明確格式和時區，接口文檔中需説明。
- 批量 ID 使用數組，例如 `ids: [1, 2, 3]`，禁止逗號字符串作為新增接口默認格式。

### 10.3 查詢參數歸一化

- 列表接口統一使用 `page`、`page_size`。
- 排序字段必須白名單映射，禁止直接把用戶輸入傳給 `orderBy` / `orderByRaw`。
- 篩選字段必須有明確類型，避免空字符串、`null`、`0`、`false` 在業務層語義混亂。
- 新接口不做舊參數兼容；舊接口如已有兼容邏輯，保持原實現邊界，不在重構中擴散到 v2。

### 10.4 請求 DTO 與上下文

當接口參數會跨多個 Service 傳遞，或涉及支付、實名、第三方上游、批量操作時，推薦引入請求 DTO 或命令對象：

```php
final class CreatePaymentCommand
{
    public function __construct(
        public readonly int $invoiceId,
        public readonly string $gateway,
        public readonly int $amountCents,
        public readonly int $actorId,
        public readonly string $traceId,
    ) {}
}
```

約束：

- DTO 字段使用業務語義命名，不照搬第三方字段。
- DTO 不直接讀取 Request。
- DTO 不包含 secret、token、私鑰等敏感值。
- `actor_id`、`trace_id`、`ip_address`、`operator_*` 等上下文由 Controller 或中間件收集後傳入 Service，不在深層 Service 裡重複讀取全局請求。

## 11. API 響應工程化規範

API 響應必須由統一響應構造器、Resource 或明確 DTO 轉換，避免 Controller、Service、第三方驅動各自拼 JSON。

### 11.1 響應來源

- Controller 返回 `$this->success(...)`、`$this->paginate(...)`、`$this->error(...)` 或拋出統一業務異常。
- 業務 Service 返回領域對象、DTO、數組或分頁器，不直接返回 HTTP Response。
- 第三方 Provider 返回內部 DTO 或統一異常，不把第三方原始響應直接傳給 Controller 或前端。
- Model 不應直接作為複雜接口響應暴露，優先通過 Resource 控制字段。

### 11.2 Resource 與字段治理

- 面向前端的字段由 Resource 或專用 Response DTO 統一轉換。
- 響應字段統一 `snake_case`。
- 敏感字段默認不返回，例如 password、token、secret、身份證完整號、私鑰、第三方原始簽名。
- 狀態字段必須返回穩定 code；展示文案可由前端映射或後端明確輸出，但不能散落魔法字符串。
- 涉及金額時必須明確單位，例如 `amount_cents`、`amount` + 文檔説明，不允許前端猜測。

### 11.2.1 敏感字段與憑據輸出

- 設置類接口遇到敏感 key 時，響應 `value` 必須為空字符串或安全佔位，不返回原始值。
- 設置類接口可返回 `has_value` 和 `masked_value`，但 `masked_value` 必須是真掩碼，例如 `******`，不得等於原始值。
- 敏感配置編輯遵循“空值保留、非空替換”：提交空字符串表示不修改舊密鑰；提交非空值才替換並按模型規則加密保存。
- 供應商接口不得在列表、詳情、創建、更新響應中返回 `api_key` 原值。
- 供應商憑據只允許返回 `has_api_key`、必要掩碼或存在狀態，字段必須由 Resource 或控制器白名單顯式控制。
- 第三方原始錯誤只能進入脫敏日誌或內部排查上下文，不得作為 API `message` 或前端提示直接展示。
- `payments` 表記錄屬於真實資金流或財務審計鏈路，維護命令、清理命令、API 和測試均不得物理刪除 Payment 記錄。
- 公開 VNC token 接口不得返回 VNC `password`，公開 token 必須一次性消費，後續 relay token 只能在受控短 TTL 內使用。
- 用戶端賬單詳情必須使用獨立 Resource/DTO 白名單，不得複用管理端詳情，不得返回 `raw_status` 等內部審計字段；用戶本人相關支付記錄可返回 `payment_no`（系統/商家訂單號）與 `trade_no`（第三方支付訂單號）用於對賬展示。
- 餘額恢復、混合支付撤銷、退款等財務反轉不得刪除原始流水，必須用反向流水、狀態投影或審計記錄保留完整鏈路。

### 11.3 錯誤響應

- 業務異常統一映射為 `code != 0` 的響應。
- 第三方異常必須轉換成項目內部錯誤碼和簡體中文 `message`，不直接暴露供應商原始錯誤。
- 表單校驗錯誤統一使用 `42200` 和 `data.errors`。
- 鑑權、權限、前置條件必須區分 `40100`、`40300`、`40301`。
- 不允許接口一部分返回 `code=0` 表示失敗，另一部分用 HTTP 500 表示失敗。

### 11.4 文案語言規範

- 所有 API 返回的 `message` 必須為簡體中文。
- `data.errors` 中面向前端展示的校驗錯誤必須為簡體中文。
- 第三方供應商返回的英文、繁體中文、錯誤碼、原始異常信息，必須在後端映射為項目內部簡體中文提示。
- 日誌可以保留第三方原始錯誤摘要，但 API 響應不得直接暴露第三方原文。
- 後端內部異常類、變量名、枚舉名可以使用英文；用戶可見響應文案必須使用簡體中文。
- 自動化測試應優先斷言業務碼和關鍵字段；涉及用戶展示文案時，應斷言為簡體中文。
- 前端錯誤提示統一走各端請求層或 `toUserMessage(error, fallback)` 等可信中文兜底，不得直接展示 axios/browser/第三方 `error.message`。
- 無業務明確要求時，管理端和用戶端不開放英文自動切換入口；403、404、500、登錄、表單、通知、空狀態、加載態和操作結果提示均使用簡體中文。

### 11.5 兼容與版本邊界

- 舊接口凍結，不得為了 v2 重構刪除、重命名或新增結構性字段。
- v2 新接口不繼承舊接口的冗餘字段、歷史兼容字段和列表詳情混用結構。
- 必須變更舊接口行為時，應在報告或需求中説明影響面，並同步前端調用、接口文檔和測試；這類變更只允許用於安全漏洞或明確 bug。
- 歷史兼容字段只保留在舊接口內，不進入 v2 接口。
- 同一業務對象在管理端、用戶端、站點端可有不同 Resource，但核心狀態 code 和金額單位必須一致。

### 11.5.1 大響應與字段邊界

新接口必須主動控制響應體積：

- 單次 JSON 響應目標上限為 `100KB`。
- 公開站點首屏接口目標上限為 `50KB`。
- 管理端樹選擇器、目錄選擇器首包目標上限為 `70KB`。
- 列表 Resource 不返回詳情字段、完整長文本、完整配置 schema、完整關聯樹、完整日誌上下文或第三方原始響應。
- 大文本列表字段返回 `excerpt`，完整 `content`、`body`、`html` 只在詳情接口返回。
- 圖片、二維碼、附件、視頻返回 `url` 或 `asset_id`，禁止把 base64 放入普通 JSON 響應。
- 嵌套層級默認不超過 3 層；超過 3 層必須拆為子資源、分頁或獨立詳情接口。

### 11.6 第三方錯誤與回調

- 支付、實名、短信、上游、郵件、對象存儲等第三方錯誤統一經過 `ProviderErrorMapper` 或等價映射層轉換為簡體中文業務提示。
- 第三方原始 `msg/message/error` 僅允許寫入脫敏日誌，不能進入 API `message`、`data.errors` 或前端提示。
- 回調入口必須先通過簽名中間件；涉及自研回調時優先使用 HMAC-SHA256、timestamp、nonce 和 replay cache。
- 業務 Service 層仍需保留冪等、金額/狀態校驗、事務鎖和審計日誌，不能只依賴路由層驗籤。

## 12. 新增接口落地規則

新增 API 時遵循以下實現方式：

1. 控制器保持薄層，只做參數接收、鑑權、調用服務、返回結果。
2. 成功響應優先使用：
   - `$this->success(...)`
   - `$this->paginate(...)`
3. 失敗響應優先使用：
   - `$this->error(...)`
   - `throw new BusinessException(...)`
4. 參數校驗優先使用 `FormRequest`。
5. 列表接口默認提供 `page`、`page_size`。
6. 不要手寫一套與統一外層不同的 JSON 結構。

7. 請求歸一化優先放在 FormRequest 或 Request DTO。
8. 響應字段優先通過 Resource 或 Response DTO 管理。
9. 第三方請求/響應不直接穿透到 Controller 或前端。
10. v2 新接口必須列表與詳情 Resource 分離。
11. v2 新接口不得複用舊寬 Resource 作為默認輸出。
12. v2 新接口必須有 Feature 測試覆蓋鑑權、權限、校驗失敗、成功響應和字段白名單。

### 12.1 推薦控制器寫法

```php
public function index(ListProductsRequest $request)
{
    $paginator = $this->productService->paginate($request->validated());

    return $this->paginate($paginator, ProductResource::class);
}

public function show(int $id)
{
    $product = $this->productService->findVisible($id);

    if (! $product) {
        return $this->error(40400, '商品不存在或已下架');
    }

    return $this->success(new ProductResource($product));
}
```

## 13. 聯調注意事項

- 前端成功判斷以 `code === 0` 為準，不要只看 HTTP 200
- 前端失敗提示優先讀取 `message`，並按簡體中文展示
- 表單校驗錯誤優先讀取 `data.errors`
- 新接口變更字段名或嵌套層級時，必須同步更新前端調用
- 自動化或第三方回調接口如果要覆蓋 HTTP 狀態碼，必須保持業務碼和 `message` 仍可讀

## 14. 維護建議

- 返回格式發生變化時，優先更新本文，再更新前後端調用
- 路由清單變化後，重刷 `docs/develop/api/backend-api-catalog.md`
- 若新增特殊錯誤碼區間，應同步補充本文第 4 節
