---
source: develop/security.md
sourceHash: 216d06d27af5a4fa28611f41cdd0d242fc3a0f3ffd367334f6a97a73a8484860
generated: zh-tw
---

<!-- 本檔案由 docs-i18n.mjs trad zh-tw 從 docs/develop/security.md 產生，請勿手動編輯。 -->
# 安全開發規範

本規範約束 TuraIDC 後端的安全實現方式。它不是清單式的“注意事項”，每一條都對應本倉庫真實發生過的缺陷，並給出可執行的自檢命令。

與其他文件的分工：`AGENTS.md`「關鍵約束入口」給出不可違反的一句話結論，本文給出理由、邊界與驗證方法。衝突時以執行程式碼和測試為準，並回來更新本文。

> **關於 WAF 層的落地狀態**：第 2 節描述的入站 WAF 已落地——`backend/config/waf.php`、`App\Support\Waf\PayloadScanner`、`WebApplicationFirewall` 中介軟體（prepend 於 `api` 中介軟體組最前）。規則庫與豁免名單的逐條理由寫在 `config/waf.php` 內；上線初期可先開觀察模式（`WAF_OBSERVE_ONLY=true`）確認無誤傷後再切攔截。本文對它的**約束與邊界判斷**不變——那些結論來自“正則載荷匹配無法識別身份”這一結構性事實，絕不能因為 WAF 已上線就把鑑權問題推給它。

---

## 1. 分层模型

安全不是某一层的属性，而是若干**职责不可互相替代**的层叠加的结果。任何“某层已经防了，这层可以松一点”的推理都是错的。

| 层 | 负责 | 实现位置 | 不能替代的 |
| --- | --- | --- | --- |
| 入站 WAF | 拦明显攻击载荷、自动化扫描 | `backend/config/waf.php`、`App\Support\Waf\PayloadScanner`、`WebApplicationFirewall` 中间件 | 身份、权限、凭据真伪 |
| 认证 | 你是谁 | `auth:sanctum`、各协议的令牌/签名服务 | 你能做什么 |
| 授权 | 你能做什么 | RBAC、scope 校验、Policy | 输入是否合法 |
| 输入校验 | 参数结构与业务合法性 | `FormRequest` | 输出是否安全 |
| 输出净化 / 编码 | 渲染时不产生可执行内容 | `RichHtmlSanitizer`、`TextSanitizer`、`htmlspecialchars` | 数据层是否被注入 |
| 数据访问 | SQL 不被拼接 | Eloquent / 查询构造器参数绑定 | 以上任何一层 |

---

## 2. WAF：能做什麼，絕不能指望它做什麼

### 能做

- 擋自動化掃描器與批次探測（UA 特徵、敏感路徑探測、備份包猜測）；
- 擋形態明顯的注入載荷（`union select`、`information_schema`、`into outfile`、程式碼執行函式名）；
- 降低被未知漏洞踩中的機率與日誌噪音。

### 不能做

WAF 是**正則載荷匹配**，`PayloadScanner::inspect()` 只看 path / query / body / cookie / UA 的字串形態，**沒有身份概念**。

這不是理論推斷。2026-08-28 的審計中，`zjmf_bridge` 外掛被發現可偽造任意使用者 JWT（`ZjmfTokenService` 以空串作 HMAC 金鑰），PoC 打 `/zjmf/v1/user` 返回 200 —— **而當時 WAF 正在這條路由上生效**（該中介軟體 prepend 在 `api` 組，橋接路由走 `middleware(['api', ...])`；`waf.except` 中的 `api/v2/zjmf/*` 豁免的是上游協議路徑，橋接在 `zjmf/v1`，不在豁免名單）。原因很簡單：偽造的 JWT 與合法 JWT 逐位元組同構，沒有任何正則能區分。

### 禁止

- **禁止**把鑑權、授權、越權、憑據偽造問題“交給 WAF 解決”。這類缺陷必須在認證 / 授權層修。
- **禁止**因為有 WAF 就放鬆引數繫結、`FormRequest` 校驗或富文本白名單淨化。
- **禁止**用 WAF 規則兜底業務校驗（例如“用規則擋住負數金額”）——業務約束屬於 `FormRequest` 與領域服務。

### 規則與豁免的紀律

- 豁免名單（`waf.except`）每加一條都是一個洞。必須在該條目旁寫明**為什麼這條路徑承載的合法內容會被規則誤傷**，不能只寫路徑。
- 規則庫改動先以觀察模式（只記錄不攔截）執行，確認無誤傷後再切攔截。
- 規則只針對攻擊特徵，不針對業務詞彙。過於寬泛的規則（裸 `substr(`、裸 `user(`）誤傷代價大於收益，不要加。
- 富文本欄位（文章正文、工單內容）合法包含 HTML，入站層攔 `<div` 會直接打斷業務；XSS 由輸出層白名單淨化負責。

---

## 3. 金鑰紀律：讀到空值必須拒絕服務

這是本倉庫出現過的最高危缺陷模式。

```php
// 错误：密钥为空时 HMAC 用空串当 key，任何人都能算出"正确"签名
return hash_hmac('sha256', $input, (string) config('x.secret', ''), true);
```

### 規則

1. **空即拒絕。** 任何簽名、HMAC、令牌金鑰讀到空值時必須**同時**拒絕簽發與拒絕校驗。絕不允許降級為“空金鑰照常計算”。
2. **簽發與校驗同源同判。** 同一個金鑰若被多個服務使用，取值方式（含 `trim()`）與“算不算已配置”的判斷必須一致。`zjmf_bridge` 的漏洞正是因為簽名服務擋了空值、令牌服務沒擋。
3. **必須寫入 `backend/.env.example`**，並註明留空的後果。該鍵當時完全不在樣例檔案裡，運維開啟功能時沒有任何提示。
4. **比對用 `hash_equals()`**，不得用 `==` / `===`（時序側通道 + 型別混淆）。
5. **校驗 `alg` 白名單。** JWT 類令牌即使實現上恆按固定演算法重算簽名，也應顯式校驗 header 中的演算法，防止後續有人加演算法分派時退化成 `alg=none`。

### 本倉庫現狀（2026-08-28 全量核對）

14 個簽名點，除已修復的 `ZjmfTokenService` 外全部合規。合規形態可參照：

- 入口條件裡帶 `$secret === ''`：`demo_verification`、`stay33`、`demo_pay`、`VerifyTicketUpstreamCallbackSignature`；
- 取值方法內拋異常：`LeafFaceClient::appSecret()`；
- 入口 `isConfigured()` 檢查：`GeetestCaptchaService`；
- `throw_if($secret === '', ...)`：`TicketUpstreamCallbackService`。

---

## 4. XSS：白名單，不是黑名單

### 禁止自制標籤剝離

```php
// 错误：单遍剥离可以被重组绕过
return preg_replace('/<\/?script\b[^>]*>/iu', '', $value);
```

實測這段程式碼對 9 個常見載荷放行 8 個（`<img onerror>`、`<svg onload>`、`<iframe>`、`<object>`、`<embed>`、`<body onload>`、`javascript:` 協議全部原樣通過）。最嚴重的一條：

```
输入: <scr<script>ipt>alert(1)</scr</script>ipt>
输出: <script>alert(1)</script>
```

輸入原本不含完整 `script` 標籤，剝掉內層後剩餘部分**重組成了一個可執行標籤**——淨化反而使情況變壞。這就是黑名單方案的固有缺陷，不是這條正則寫得不好。

同理，`strip_tags($html, $allowed)` **不可**用於安全淨化：它的第二個引數只做標籤名白名單，屬性一律原樣留下，`<img src=x onerror=...>` 的 `img` 在白名單裡於是整條帶著 `onerror` 通過。

### 三個工具的分工

| 場景 | 用 | 說明 |
| --- | --- | --- |
| 需要保留富文本（文章正文等） | `RichHtmlSanitizer::sanitize()` | DOM 白名單三層：標籤、屬性、URL 協議；`iframe/object/embed/script/svg/style/form/base/link/meta` 連內容一起刪；解析失敗整段轉義而非回退原文 |
| 只要純文本（暱稱、備註、摘要） | `TextSanitizer::clean()` / `nullable()` | 剝掉全部標籤 |
| 服務端拼 HTML 時插入變數 | `htmlspecialchars($v, ENT_QUOTES, 'UTF-8')` | 輸出編碼，非淨化 |

`strip_tags(Str::markdown($content))` 用於**生成純文本摘要**是正當的內容轉換，不屬於本節約束範圍——但它的產物不得再當作“已淨化的 HTML”使用。

### 模板類欄位的特殊注意

若某處會把管理員撰寫的內容**作為 HTML 原樣輸出**（例如通知模板正文），僅對替換進去的引數值做 `htmlspecialchars` 是不夠的——模板本體同樣是不可信輸入，必須過 `RichHtmlSanitizer`。

---

## 5. SQL 注入：引數繫結是唯一手段

- 一律使用 Eloquent / 查詢建構子，讓驅動做引數繫結。
- `whereRaw` / `selectRaw` / `havingRaw` / `orderByRaw` / `DB::raw` 若確有必要，**變數必須走繫結引數**，不得字串拼接；`orderBy` 的列名與方向必須過白名單列舉，繫結引數不適用於識別符號。
- `DB::statement` / `DB::unprepared` 只允許出現在遷移與安裝流程中，且不得含任何請求來源的值。

本倉庫現狀：`backend/plugins/` 下上述 API 命中數為 **0**，全部走引數繫結。這是要維持的基線。

---

## 6. 命令執行與反序列化

- `exec` / `popen` / `shell_exec` 的所有變數部分必須 `escapeshellarg()`，數值用 `%d` 格式化。現有 2 處（`SyncUpstreamsCommand`、`VncRelayEnsureCommand`）均符合。
- `unserialize()` 必須帶 `['allowed_classes' => [...]]` 白名單。禁止無參呼叫。
- `eval` / `assert` / `create_function` / `system` / `passthru`：**禁止**，當前全倉命中數為 0。

---

## 7. 外掛的額外約束

外掛是本倉庫安全邊界最容易被繞開的地方（`zjmf_bridge` 的漏洞即出自此）。

- 全部特有邏輯收斂到 `backend/plugins/{domain}/{slug}/`；
- **不得註冊系統級路由、排程或全域性中介軟體**；
- **不得自建鑑權中介軟體繞過統一認證入口**。外掛確需自有協議鑑權時，必須複用平臺的金鑰紀律與比對工具，且不得讓同一憑據在外掛內出現兩套判斷口徑；
- 所有回撥入口必須驗籤（三個支付閘道器均已實現）；
- 外掛不需要 SSL 與 CA。這是既定取捨而非疏漏：上游服務商可能只提供純 HTTP 介面，或證書鏈不完整，強制校驗會直接打斷對接。代價是傳輸層可能被中間人窺視或篡改，因此**補償手段必須到位**——請求與回撥兩側都要有 HMAC 簽名（金鑰紀律見第 3 節）、回撥要有時間戳與 nonce 防重放、金額等關鍵欄位以本地記錄為準而不是無條件信任上游報文。**不得**反過來以“反正沒有 TLS”為由放鬆這些校驗。

---

## 8. 自檢清單

改動涉及認證、簽名、渲染或資料訪問時，跑一遍：

```bash
cd backend

# 签名密钥是否有空值守卫（每个命中点都要能指出它的守卫在哪一行）
grep -rn "hash_hmac" app plugins --include=*.php | grep -v /tests/

# 是否有用 == 比对签名的（应为空）
grep -rnE "(signature|sign|token|expected|hmac)[^;]*\s(===?|!==?)\s*[^;]*(signature|sign|token|expected|hmac)" \
  app plugins --include=*.php | grep -v /tests/ | grep -v hash_equals

# 插件内原始 SQL（应为空）
grep -rnE "DB::(raw|statement|unprepared)|whereRaw|selectRaw|havingRaw|orderByRaw" plugins --include=*.php | grep -v /tests/

# 危险函数（应只剩已知的 exec/popen/unserialize 三处，且均已加固）
grep -rnE "\b(eval|assert|exec|shell_exec|system|passthru|popen|proc_open|create_function|unserialize)\s*\(" \
  app plugins --include=*.php | grep -v /tests/

# 自制的标签剥离 / 注入过滤（命中即需判断是否应改用白名单净化器）
grep -rniE "preg_(match|replace)[^;]*(script|onerror|onload|javascript:|iframe|union|select)" app plugins --include=*.php | grep -v /tests/

# 新增的密钥类配置项是否已写入样例。必须逐个键名精确检查 ——
# 统计匹配行数（grep -c）毫无意义：仓库里本来就有别的 _SECRET/_KEY，
# 少写一个新键照样得到非零结果，自检会假阳性通过。
for key in ZJMF_BRIDGE_SECRET; do
  grep -q "^${key}=" .env.example || echo "缺少样例配置：${key}"
done
```

新增簽名 / 令牌 / 回撥鏈路時，測試必須包含：

1. 金鑰未配置時拒絕服務；
2. 偽造憑據被拒絕（**用手工拼裝的憑據，不要經由本專案的簽發服務**——攻擊者手裡沒有你的程式碼）；
3. 合法憑據仍然通過的陽性對照。

### 反向驗證

安全用例必須做反向驗證：把**僅測試檔案**打在未修復的程式碼上，確認它們如期失敗。恆真的安全測試比沒有測試更危險。

注意用例不要引用“修復才引入的常量”，否則反向驗證會因未定義符號而 Error，掩蓋真正要證明的行為差異——應當斷言**可觀測行為**（實際落庫的字串、實際返回的狀態碼）。

---

## 9. 本規範的實證依據

上述結論來自 2026-08-28 對 `backend/app` 與 `backend/plugins`（7 個外掛域）的一次全量核對，方法為：簽名點逐個追溯金鑰來源與守衛位置、比對方式全量列舉、原始 SQL 與危險函式全倉 grep、XSS 淨化點逐個判斷用途，其中黑名單過濾器的繞過以本地 PHP 實際執行 9 個載荷確認。

發現並已修復：`zjmf_bridge` 令牌偽造。
發現待修復：通知模板欄位的黑名單式淨化（第 4 節的反例即取自該處）。

## 關聯文件

- [後端工程規範](backend.md)：控制器、服務與外掛的分層約束。
- [外掛開發](plugins/index.md)：外掛目錄、擴充套件點與第 7 節約束的落地位置。
- [API 格式規範](api/format.md)：響應、鑑權與校驗的統一口徑。
- [測試指南](testing.md)：第 8 節反向驗證所依賴的測試環境與命令。
- [工作規則](https://github.com/25Cloud/TuraIDC/blob/main/docs/internal/governance/WORKING_RULES.md)：跨領域的強制規則與驗證提交要求。
