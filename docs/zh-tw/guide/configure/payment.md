---
source: guide/configure/payment.md
sourceHash: 980cc9898f8ce4c4f95cf77013bfe42609dd7f0dd4f82bd36a96d1c9b4ee0f59
generated: zh-tw
---

<!-- 本檔案由 docs-i18n.mjs trad zh-tw 從 docs/guide/configure/payment.md 產生，請勿手動編輯。 -->
# 支付渠道接入

使用者付錢進來的兩個第三方通道是支付寶當面付與易支付，都以「整合外掛」形態安裝啟用；餘額支付是系統內建能力，不需要外掛。本文說明入口、欄位、回撥地址要求與「付了錢沒到賬」的排查路徑。

## 1. 後臺入口

| 頁面           | 路徑                                    | 許可權                                  |
| -------------- | --------------------------------------- | ------------------------------------- |
| 支付渠道       | 系統設定 → 整合外掛 → 支付渠道          | 列表 `integration_plugin.view`；安裝/改配置/啟停 `integration_plugin.manage`；測試下單 `integration_plugin.test` |
| 訂單管理       | 財務管理 → 訂單管理 → 訂單              | 訂單相關許可權                          |
| 支付閘道器日誌   | 日誌中心 → 日誌管理 → 閘道器日誌          | `log.list`                            |

外掛列表頁會掃描 `backend/plugins/gateways/` 下的目錄，本地新增外掛檔案後點「掃描外掛」才會出現，再點「安裝」→「啟用」。測試類介面（含「建立 0.01 元測試訂單」）限流 10 次/分鐘。

## 2. 可用渠道與系統編碼

系統內部按閘道器編碼記賬（常量定義在 `PaymentGatewayCode`）：

| 編碼      | 展示名     | 說明                                                     |
| --------- | ---------- | -------------------------------------------------------- |
| `balance` | 餘額支付   | 內建，用賬戶餘額支付，不涉及第三方                      |
| `alipay`  | 支付寶支付 | 需要 `ali_pay` 外掛（slug 匹配 `alipay` / `ali_pay`）    |
| `epay`    | 易支付     | 需要 `epay` 外掛；歷史編碼 `yipay`、`yi_pay` 會歸一到這裡 |
| `wechat`  | 微信支付   | 系統有編碼與展示名，但 `backend/plugins/gateways/` 下沒有對應外掛目錄 |
| `stripe`  | Stripe 支付 | 同上，沒有對應外掛目錄                                   |
| `manual` / `free` | 管理員手動 / 免費開通 | 內部記賬用，不是第三方通道          |

`wechat` 與 `stripe` 目前只存在於常量表裡，`backend/plugins/gateways/` 下沒有對應外掛目錄（未在程式碼中確認是否有計劃補齊），因此它們不會出現在前臺。

閘道器與外掛的對應關係：前臺「可用支付方式」取自**已啟用外掛**註冊進執行時的閘道器，逐個呼叫 `isEnabled()`，只有為真才展示；而回調路由反查外掛時按狀態優先、id 升序取第一條，不強制要求啟用。所以停用外掛後前臺不再展示該渠道，但已下發給渠道的舊回撥地址仍能走到同一閘道器，不會 404。

## 3. 支付寶當面付（`ali_pay`）

欄位（`backend/plugins/gateways/ali_pay/config.php`）：

| 欄位                    | 預設值 | 留空後果                                             |
| ----------------------- | ------ | ---------------------------------------------------- |
| `alipay_enabled` 啟用   | 開     | 關閉後該渠道不作為可用支付方式                       |
| `app_id` App ID         | 空     | `isEnabled()` 為假，渠道不上架                       |
| `private_key` 應用私鑰  | 空     | 同上；必須填**應用私鑰**，不是支付寶公鑰             |
| `alipay_public_key` 支付寶公鑰 | 空 | `isEnabled()` 只看 app_id 與私鑰，但回撥驗籤必須用公鑰，缺了會一直驗籤失敗 |

`private_key` 與 `alipay_public_key` 屬於敏感鍵，落庫前用 `APP_KEY` 加密（`enc:` 字首），後臺只回顯 `******`，留空儲存表示保留舊值而不是清空。要用「明文檢視」需要 `settings.secret_reveal` 許可權，且每次呼叫限流 10 次/分鐘並寫操作日誌。

金鑰本身也可以走環境變數 `ALIPAY_APP_ID`、`ALIPAY_PRIVATE_KEY`、`ALIPAY_PUBLIC_KEY`（`config/alipay.php`），外掛配置優先於環境變數。生產上建議二者只留一處，避免排查時看錯來源。

**回撥地址**（決定錢能不能到賬）：

- 路由：`POST /api/v2/client/payment/alipay/notify`，另有通用入口 `/api/v2/client/payment/notify/{gateway}`（GET/POST 都收）。舊編碼 `yipay` 的歷史回撥也會被歸一到當前編碼，不用改渠道側配置。
- 口徑優先順序：`ALIPAY_NOTIFY_URL` → 後端基址 `APP_URL` + `/api/v2/client/payment/alipay/notify` → `FRONTEND_URL` 同路徑。
- 支付時只在該地址通過校驗的情況下才把它帶給支付寶：協議必須是 http/https，主機不能是 `localhost`、`127.0.0.1`、`::1`，也不能是內網或保留 IP。不通過時**不會**帶 `notify_url` 引數——支付寶側顯示成功、本地永不入賬，這是最隱蔽的一類故障。
- 回退到 `FRONTEND_URL` 是不可靠的兜底：四個前端站點不反代 API（見[部署與排程](../deploy/scheduling.md)），發到官網或控制台域名會靜默丟回撥。生產請顯式配置 `ALIPAY_NOTIFY_URL` 指向 API 域名。

回撥到達後先由 `verify.alipay.callback` 中介軟體驗籤，再進業務處理；金額與本地訂單不一致會被拒絕。兩條回撥路由都限流 60 次/分鐘/IP，限制放在驗籤之前——頻率異常時閘道器收到的是 429，回撥會被判定為失敗重試。閘道器地址預設 `https://openapi.alipay.com/gateway.do`，沙箱是 `https://openapi-sandbox.dl.alipaydev.com/gateway.do`，用 `ALIPAY_GATEWAY` 切換。

## 4. 易支付（`epay`）

欄位（`backend/plugins/gateways/epay/config.php`）：

| 欄位                        | 預設值 | 留空後果                                             |
| --------------------------- | ------ | ---------------------------------------------------- |
| `enabled` 啟用              | 開     | 關閉後該渠道不作為可用支付方式                       |
| `merchant_id` 商戶 ID       | 空     | `isEnabled()` 為假；回撥驗籤也會因商戶號為空一律拒絕 |
| `api_endpoint` 介面地址     | 空     | 只接受 http/https 且帶主機名的地址，不合法即視為未配置 |
| `sign_type` 簽名方式        | `MD5`  | 非法值靜默回退 `MD5`                                 |
| `merchant_key` MD5 商戶金鑰 | 空     | MD5 模式下必填；查詢/退款介面若服務商仍要求 KEY 也要填 |
| `merchant_private_key` RSA 商戶私鑰 | 空 | RSA 模式下必填，僅在選擇 RSA 時顯示                |
| `platform_public_key` RSA 平臺公鑰  | 空 | RSA 模式下必填，用於驗籤回撥，僅在選擇 RSA 時顯示  |
| `payment_types` 支付方式    | 僅勾選支付寶 | 未勾選時該渠道不展示給使用者；可同時勾選支付寶與微信 |

`api_endpoint` 可以直接填平臺根地址；如果填了 `mapi.php` 這類具體介面地址，系統會按同目錄解析出其他介面（`mapi.php`、`api.php` 等）。

**上架條件比較嚴**：`isEnabled()` 要求啟用、商戶 ID 非空、簽名材料齊全、介面地址可解析、`notify_url` 與 `return_url` 都非空、`payment_types` 非空，任何一條不滿足前臺都不顯示這個渠道。

**回撥地址**：固定取 `APP_URL` 拼 `/api/v2/client/payment/notify/epay`，`return_url` 取「使用者控制台地址 `CLIENT_CONSOLE_URL` → 官網 `FRONTEND_URL` → `APP_URL`」回退到 `/client/recharge`。這兩個地址都**不能在後臺改**，只能通過環境變數調整——換域名時必須同步改 `.env` 並重啟 PHP-FPM。

簽名方式是硬約束：MD5 模式下回調必須帶 `sign` 且與商戶金鑰算出的簽名一致；RSA 模式下平臺公鑰為空則直接驗籤失敗。空金鑰不會降級成「不校驗」。

## 5. 驗證

先做只讀檢測，再走真實小額：

1. 外掛詳情頁點「檢測」確認外掛清單與配置齊全、入口類能例項化（只讀，不請求支付寶）。
2. 支付外掛詳情頁的「建立 0.01 元測試訂單」：真實建立一筆 0.01 元測試訂單，**測試結果只返回不儲存**。能拿到支付連結說明 App ID、私鑰、簽名鏈路都通了；拿不到就先看報錯裡的原因。
3. 用一筆真實小額充值走完整鏈路：使用者下單 → 掃碼支付 → 回撥入賬。
4. 到「閘道器日誌」與訂單詳情確認回撥記錄，`out_trade_no`、`trade_no`、金額都對得上。

回撥路徑已在入站 WAF 的豁免名單裡（`api/v2/client/payment/alipay/notify`、`api/v2/client/payment/notify/*`），不需要再動 WAF；反過來，如果你為了「讓回撥過去」去放寬 WAF 規則，那是改錯了地方。

```bash
# 回调必须是公网可达的地址，本机验证时不要用 APP_URL=127.0.0.1 的配置去测真实回调
grep -E '^(APP_URL|FRONTEND_URL|CLIENT_CONSOLE_URL|ALIPAY_NOTIFY_URL)=' /你的项目/backend/.env
```

## 6. 排障

- **使用者付款成功、本地訂單一直待支付**：九成是回撥地址問題。先確認 `.env` 裡 `APP_URL` / `ALIPAY_NOTIFY_URL` 是公網 API 域名，再確認 Nginx 與 WAF 放行，最後看閘道器日誌裡有沒有收到請求。
- **回撥收到了但驗籤失敗**：支付寶公鑰填成了應用公鑰；或易支付選了 RSA 卻沒填平臺公鑰；或易支付的商戶金鑰與服務商側不一致。
- **渠道側顯示「通知失敗」**：回撥被限流擋下（60 次/分鐘/IP）或返回了非 `success` 文本；支付寶會按遞增間隔最多重試 8 次，短時限流通常能自愈，持續失敗要看閘道器日誌。
- **前臺沒有這個支付方式**：外掛沒啟用，或 `isEnabled()` 的前置條件有一項為空（易支付最常見的是 `payment_types` 沒勾、`api_endpoint` 不是合法 URL）。
- **改完配置沒生效**：支付寶配置有 60 秒程序內快取，最遲一分鐘生效；改閘道器或換外掛後建議重啟 PHP-FPM。
- **換域名後渠道全掛**：`APP_URL`、`CLIENT_CONSOLE_URL` 沒跟著改；這兩項不在後臺，改的是 `.env`。

## 關聯文件

- [初始配置](index.md)：四步配置的順序與上線檢查清單。
- [上游供應商對接](upstream.md)：錢進來之後的另一半，機器從哪裡開出來。
- [部署與排程](../deploy/scheduling.md)：四端域名、反代與佇列的部署事實，回撥可達性依賴它。
- [工程踩坑記錄](../../develop/pitfalls.md)：改配置與增量部署時的實測坑。
