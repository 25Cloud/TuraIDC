---
source: develop/integrations/zjmf-upstream-api.md
sourceHash: 191333e84adb12d145e628dfcf9e641386c31805574d0d3b7eaf4d05b26e5775
generated: zh-hk
---

<!-- 本檔案由 docs-i18n.mjs trad zh-hk 從 docs/develop/integrations/zjmf-upstream-api.md 產生，請勿手動編輯。 -->
# 魔方財務對接參考（TuraIDC 作為上游）

TuraIDC 實現了魔方財務的上游服務商協議：魔方財務在「上游」裡把 TuraIDC 配置為 API 類型上游，其客戶購買/續費/控制 TuraIDC 側產品時由本協議承載。路由定義見 `backend/routes/v2-zjmf-upstream.php`，實現位於 `backend/app/Http/Controllers/ZjmfUpstream/` 與 `backend/app/Services/ZjmfUpstream/`。

## 下游側配置（魔方財務管理端）

- 上游 API 地址（hostname）：`https://你的域名/api/v2/zjmf`。**必須帶 `/api/v2/zjmf` 前綴**，漏掉前綴時登錄請求會命中不到路由（HTTP 404，魔方財務側表現為「請求失敗,HTTP狀態碼:404」）。
- 賬號：使用 TuraIDC 的一個普通客戶賬號。**由客戶自己在控制台「API 憑據」頁的「魔方財務對接」分區開啟**（`/client/api-keys?tab=upstream`，接口 `/api/v2/client/upstream-api/*`），開啟後系統生成獨立的 `api_username` 與 `api_password`（密碼僅顯示一次，可隨時重置），並確保賬號 `status=1`。該憑據與賬號登錄密碼、與同頁「開放接口密鑰」分區的密鑰都是獨立的，互不影響。
- 憑據治理與開放接口密鑰同口徑：可設 **IP 白名單**（只允許白名單內 IP 換取 JWT）與**有效期**（到期即拒絕換 JWT），並同樣計入調用審計（`api_key_usage_logs.channel = zjmf_upstream`，控制台分區可查最近 50 條）。兩個字段留空即不限制。
- 賬號條件在登錄與鑑權兩處強制一致：賬號被停用、關閉上游 API 接入、憑據過期或 IP 不在白名單，登錄直接返回 `status=400`，業務請求返回 `status=405`，不會出現「登錄成功但每次請求 405」的死循環。
- **聯動失效**：在控制台關閉魔方財務對接時，同賬號下所有開放接口密鑰一併停用。反向不成立（停用某把開放接口密鑰不影響魔方鏈路）——這是為了避免遺留一把被遺忘的全權憑據。

> 注意：魔方財務對接（本頁協議 `/api/v2/zjmf`）與自有開放接口（`/api/v2/open`）在**協議層是兩套獨立鑑權**，無法用同一份密鑰同時工作：魔方財務只會向 `/zjmf_api_login` 提交用戶名 + 密碼換 JWT，從不發送 Bearer API 密鑰（`zjmf376/app/zjmf.php` 的 `zjmfCurl` 調用點可證）。因此「一套憑據通吃兩條鏈路」在協議上不成立，控制台改為把兩套憑據**合併到同一頁分區管理、治理能力對齊**。魔方財務只走本頁協議；另一個 TuraIDC 實例對接才用開放接口密鑰（配 `tura_open_api` 插件）。

## 登錄與鑑權

1. 魔方財務調用 `POST /zjmf_api_login`（form 編碼的 `username` / `password`），成功返回 `{"jwt": "...", "status": 200}`。
2. 後續業務請求攜帶 `Authorization: Bearer <jwt>`。
3. JWT 為 HS256 自籤自驗，有效期 7200 秒（對齊魔方財務 `createJwt`）。魔方財務會長期快取 JWT，僅在收到 `status=405` 時強制重登一次，重登仍 405 則報「API賬號密碼錯誤」——因此每約 2 小時會出現一次協議內的 405 自愈請求，屬正常現象。

## 響應約定

HTTP 層固定 200，業務狀態放在 body 的 `status`：

| status | 語義                                           |
| ------ | ---------------------------------------------- |
| 200    | 成功                                           |
| 1001   | 支付類操作「已支付完成」（魔方財務映射為成功） |
| 400    | 業務失敗（msg 為用戶可讀原因）                 |
| 405    | JWT 失效，觸發魔方財務強制重登                 |

注意：魔方財務的 `commonCurl` 對 HTTP 非 200 的響應統一轉換為 `code=500`，不會透傳 HTTP 狀態碼；若對接報錯中帶「HTTP狀態碼:404/429」字樣，先檢查 hostname 前綴與登錄限流（20 次/分鐘）。

## 接口清單

- 登錄：`POST /zjmf_api_login`（免鑑權，單獨限流）
- 商品：`GET /cart/all`、`GET /api/product/proinfo`、`GET /api/product/prodetail`、`GET /cart/get_product_config`、`GET /cart/ontrialmax`
- 購買開通：`GET /user_info`、`POST /cart/clear`、`POST /cart/add_to_shop`、`POST /cart/settle`、`POST /provision/default`、`POST /provision/custom/{id}`
- 主機：`GET /host/header`、`POST /host/renew`、`POST /host/cancel`、`POST /provision/button`（無參控制按鈕，`id` + `func`）
- 控制：`POST /dcim/on|off|reboot|hard 相关`、`POST /dcim/novnc|kvm|ikvm|bmc|rescue|crack_pass|reinstall|cancel_task|refresh_power_status|refresh_all_power_status|hide_result|check_reinstall|buy_reinstall_times|buy_flow_packet`、`GET /dcim/traffic_usage|/host/trafficusage`、`GET /dcim/detail|/dcim/resintall_status`
- 升級：`POST /upgrade/upgrade_config_post|checkout_config_upgrade|upgrade_product_post|checkout_upgrade_product`
- 餘額：`POST /apply_credit`（餘額支付，成功返回 1001 與 `data.hostid[0]`=服務 ID）、`POST /apply_credit_limit`（暫不支持信用額，返回 400）
- 推送：`POST /api/ticket_reply/sync`、`POST /upload_image`

## 自定義 Tab（面板型產品）

CDN、虛擬主機等「面板型產品」在魔方財務側靠自定義 tab 承載控制台與登錄信息，鏈路為：

1. `GET /host/header` 返回 `module_client_area`（`[{key, name}]`）聲明可用 tab，返回 `module_client_main_area`（`[{name, value}]`）供下游渲染登錄信息（面板地址、用戶名、密碼、端口）。
2. 下游點擊 tab 時 `POST /zjmf_api/provision/custom/content`（入參 `id`=上游主機 id、`key`=tab 標識、`api_url`、`now_jwt`），取回 `{status:200, data:{html}}`。
3. 頁面內動作提交到 `POST /provision/custom/{id}` 透傳執行。

中間層（TuraIDC 自身接供應商時）的處理順序對齊魔方財務：

1. 服務接入可控供應商 → **透傳**供應商的 `module_client_area` / `module_client_main_area` / `module_button`，取內容時把下游的 `api_url` 繼續下傳，動作原樣轉發；
2. 未接入可控供應商的面板型產品 → 用本地連接憑據渲染面板（面板地址、用戶名、密碼、端口）；
3. 機房型產品（雲伺服器/裸機）不下發自定義區域，功能入口統一在 `/dcim/*`。

`host_data` 同時下發 `show_traffic_usage`（有流量配額時為 true），下游據此決定是否渲染流量用量。

注意：取內容必須走上面的 API 協議端點（用 API JWT 鑑權）。魔方財務的客戶區路由 `GET /provision/custom/content` 只認客戶區登錄會話（`client_user_login_token_` 快取），API JWT 調它會取不到內容。

## 排查指引

持續 405 時查看 TuraIDC 日誌中的 `[zjmf-upstream] 鉴权拒绝` 記錄，`reason` 字段：

- `disabled`：服務端上游 API 未開放（`services.zjmf_upstream.enabled`）。
- `jwt_invalid`：JWT 缺失、簽名不符或過期——檢查魔方財務側是否攜帶 Bearer 頭、兩端時鐘是否偏差過大。
- `account_unavailable`：對接賬號已不存在。
- `policy_rejected`：賬號策略未通過（`msg` 給出具體原因：賬號已停用 / 未開啟上游 API 接入 / 上游 API 憑據已過期 / 當前 IP 不在憑據白名單內）。注意白名單與有效期是在**每個請求**上判定的，不只在登錄時判一次——因此調整策略後，已簽發的 JWT 會立即失效，無需等到 2 小時後自然過期。

登錄被策略拒絕時另有 `[zjmf-upstream] 登录被拒` 記錄（響應為 `status=400`，`msg` 形如 `鉴权失败：当前 IP 不在凭据白名单内`），登錄成功與被拒都會寫入調用審計（channel `zjmf_upstream`，登錄記錄 `api_key_id = 0`）。

**魔方側提示「鑑權失敗」（登錄即失敗）時**，按以下順序排查：

1. 上游地址是否帶 `/api/v2/zjmf` 前綴（`https://域名/api/v2/zjmf`）；
2. 客戶是否已在控制台「API 憑據 → 魔方財務對接」分區開啟，並使用了頁面上給出的**用戶名與一次性密碼**；
3. 該賬號是否被停用（`status ≠ 1`）、憑據是否已過期、魔方伺服器出口 IP 是否在客戶設置的 IP 白名單內；
4. 若曾重置密碼，魔方側需同步更新為新密碼（舊密碼立即失效）。

其餘協議細節（字段名、冪等語義、降級策略）以路由文件與各 Service 的註釋為準，魔方財務側調用點可對照其源碼 `app/zjmf.php` 的 `zjmfCurl` 與 `app/common/logic/Host.php`。
