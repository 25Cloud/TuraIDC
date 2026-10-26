---
source: guide/operate/finance.md
sourceHash: 017234702a2e8679fa2ce3b962ffec09581987fd1ef3b3c5f7d806f368d3d3d2
generated: zh-tw
---

<!-- 本檔案由 docs-i18n.mjs trad zh-tw 從 docs/guide/operate/finance.md 產生，請勿手動編輯。 -->
# 財務與賬單

本文面向運營 TuraIDC 的站長與財務人員，說明資金從哪進、賬單怎麼生成、退款走哪條通道、賬對不上時按什麼順序查。所有金額與狀態判定都以服務端程式碼為準，後臺頁面只是這些規則的入口。

## 1. 三層賬務模型

系統把「錢」拆成三張表，後面所有操作都在這個結構上做。

餘額表 `user_accounts` 以 `user_id` 為主鍵，回答「這個人現在有多少錢」。欄位：

- `cash_balance`（現金餘額）、`credit_limit`（信用額度）；
- `referral_frozen_balance`、`referral_available_balance`、`referral_pending_withdrawal_balance`、`referral_withdrawn_balance`（分銷獎勵的四個子賬戶，見[使用者與分銷](users.md)）；
- 以上金額預設值全部是 `0.00`。

表上還有 `version` 做樂觀鎖：併發寫入版本不一致時直接報 40900「賬戶資料已被併發修改，請重試」，不會靜默覆蓋。

流水錶 `account_transactions` 回答「每一筆變動從哪來、變動後是多少」。

- `account_type` 取 `cash`、`referral_frozen`、`referral_available`、`referral_pending_withdrawal`、`referral_withdrawn`；
- `change_amount` 帶正負號，`balance_after` 是該筆之後的餘額快照；
- `source_type` / `source_id` 與 `origin_type` / `origin_id` 指回來源單據，`trace_id` 貫穿同一筆業務的全部記錄。

`event_type` 在讀取時會被歸一，篩選時按歸一後的值理解：`consume` → `invoice_payment`、`refund` → `invoice_refund`、`admin_deduct` 與 `deduct` → `manual_deduction`、`adjust` → `system_adjustment`、`referral_withdraw_approved` → `referral_credit_cash`。中文標籤依次是：賬單支付、賬單退款、充值到賬、手動充值、手動扣款、獎勵轉餘額、系統調賬、實名認證費用。

賬單表 `invoices` 回答「該收多少、收了多少、退了多少」，是唯一的對外財務憑證。核心欄位：`amount`（應付額）、`discount`（優惠券抵扣）、`paid_amount`（已付）、`refund_amount`（已退）、`due_date`（賬期）、`status`。

`Order`（訂單）只是開通側的內部投影，使用者在控制台看到的是賬單；充值、扣款、返利三類賬單根本沒有對應的訂單。

**檢視入口**：管理端「使用者 → 使用者列表 → 使用者詳情 → `资金流水`」標籤頁，列為 時間、型別、變動、變動後餘額、備註、操作人；相鄰的「賬單」標籤頁按狀態與型別篩選該使用者的賬單明細。使用者側在控制台的餘額流水頁檢視同一批資料。

## 2. 賬單的生成與狀態

**型別與狀態**：

- **型別** `invoices.type`：`new`（新購）、`renew`（續費）、`recharge`（充值）、`upgrade`（附加配置）、`deduction`（扣款）、`refund`（退款紅字賬單）、`referral_credit`（推薦獎勵）、`manual`（手工賬單）。歷史值 `normal` 等同 `new`。
- **狀態** `invoices.status`：`0` 未付、`1` 已付、`2` 已取消、`3` 逾期、`5` 已退款、`6` 部分退款。後臺狀態標籤顯示為 待支付 / 已支付 / 已取消 / 已逾期 / 已退款 / 部分退款。
- 逾期不等於作廢：`3` 只是標記，賬單仍可支付，也可以被人工取消。

**生成與金額口徑**：

- 使用者下單時由 `CheckoutService::create` 直接建立賬單，隨後才補一條 `projection_type = provisioning` 的投影訂單。
- 預設賬期是下單後 **7 天**（`due_date`）。
- **金額一律服務端重算**：下單請求必須帶報價憑證 `quote_token`，服務端拿它重新校驗折後金額與券後應付額。`amount` 存的是券後應付額，代理折扣在下單報價階段已經算進 `amount`；原價、代理折扣率、成本金額都寫進 `config_pricing_snapshot` 快照，事後可回溯。
- 賬單有 `(user_id, idempotency_key)` 唯一索引，重複提交不會重複建單。

管理端入口：

| 頁面         | 路徑                                       | 許可權             |
| ------------ | ------------------------------------------ | ---------------- |
| 全部訂單     | `/admin/finance/orders`                    | `order.list`     |
| 普通訂單     | `/admin/finance/orders/normal`             | `order.list`     |
| 續費訂單     | `/admin/finance/orders/renewals`           | `order.list`     |
| 附加配置訂單 | `/admin/finance/orders/upgrades`           | `order.list`     |
| 賬單管理     | `/admin/finance/invoices`                  | `invoice.list`   |
| 充值管理     | `/admin/finance/recharges`                 | `invoice.list`   |
| 新客戶       | `/admin/finance/new-customers`（選單隱藏） | `finance.report` |

賬單管理頁可按關鍵詞、型別、狀態、起始日期、結束日期篩選，行內只有「詳情」和「取消」兩個動作。

**取消**僅在賬單狀態為 `0` 或 `3`、且當前管理員擁有 `invoice.manage` 時出現。取消會：把該賬單下待支付的支付單置為失敗、取消關聯的投影訂單、釋放佔用的優惠券、按庫存預扣快照回補庫存。取消是改狀態，不是刪除。

## 3. 餘額與充值

**充值鏈路**：使用者控制台選擇支付渠道 → 服務端建立一條待支付的支付記錄（此時 `invoice_id` 為空）→ 使用者完成付款 → 網關回調或主動查詢 → 校驗金額 → 入賬並補建 `recharge` 型別賬單、寫充值流水與充值記錄。

入賬後的落點：`user_accounts.cash_balance` 增加、`account_transactions` 寫一條 `event_type = recharge` 的流水、`recharge_records` 寫一條充值記錄（`scene` 為 `new_purchase` 或 `renewal`，`entry_type = third_party_payment`）。

- **支付會話有效期 300 秒**：超時未支付的充值單會被置為「已取消」，並在 `callback_raw` 裡記錄 `closed_reason = payment_window_expired`。使用者在有效期內可在控制台輪詢狀態。
- **金額校驗**：主動查詢到閘道器已成功時，如果閘道器實收金額與支付單金額差額超過 `0.0001`，一律拒絕入賬並寫 warning 日誌，頁面提示「支付金額與充值金額不一致，請核對後重試」。這是防止同一筆閘道器流水重複入賬的關卡。
- 支付記錄模型在建立時就限制「僅允許記錄第三方真實資金流入，餘額、手工、免費流程請使用賬單和賬戶流水錶達」，唯一例外是管理員手工入賬時的審計記錄（見第 7 節）。

**充值須實名**：設定項 `finance.recharge_requires_verification` 預設開啟（值為 `1`）。開啟時未實名使用者充值會被拒（錯誤碼 40301「請先完成實名認證後再繼續操作」）。

配置入口：設定頁的「賬戶與財務」標籤頁 —— 開啟 `/admin/automation?tab=finance`，或從任一設定頁頂部切到「賬戶與財務」；儲存需要 `settings.manage`。

**充值管理頁**：`/admin/finance/recharges` 是**只讀**列表：列為 支付單號、使用者、支付記錄、金額、已付金額、狀態、建立時間、支付時間；篩選支援關鍵詞、狀態、起止日期；只有「詳情」按鈕，沒有改狀態或刪除的入口。詳情抽屜展示賬單金額、優惠金額、已付金額、支付記錄與操作日誌。

## 4. 退款的兩條通道

入口在「使用者 → 使用者詳情 → `账单` 標籤頁 → 賬單行 `退款`」，對應介面 `POST /v2/admin/users/{user}/invoices/{invoice}/refunds`。彈窗裡退款金額是隻讀的（取該賬單的 `paid_amount`，沒有就取 `amount`），**不支援部分金額**；退款方式與退款原因都必填。

兩條通道怎麼選：

- **退回餘額**（`refund_method = balance`）：只要賬單滿足前置條件就可用。把金額加到使用者 `cash_balance`。
- **原路退款**（`refund_method = original`）：只在主支付渠道是 `alipay` 或 `balance` 時可選。賬單是混付（餘額加第三方）時一律停用，頁面提示「該賬單包含餘額支付，無法全額原路退款，請使用『退回餘額』」。該通道要求賬單必須關聯投影訂單，未關聯時提示「賬單未關聯訂單，暫不支援原路退款」。

前置條件（任一條不滿足，整條退款通道就不可用，頁面會給出對應文案）：

1. 賬單狀態必須是 `1` 已付，否則「僅已支付賬單支援退款」；
2. 必須能找到可退款的支付記錄，否則「未找到可退款的支付記錄」；
3. 支付記錄已是「已退款」→「該賬單已完成退款」；
4. 賬單已開通服務（`service_id` 有值且服務狀態不是 `0`）→「賬單已開通服務，請先在服務控制台處理資源後再退款」；
5. 該賬單款項已通過重複支付轉入餘額 →「該賬單款項已通過重複支付轉入餘額，無需再退款」。

兩條通道共用同一把賬單級鎖，防止同時操作造成超額退款。

退款落庫結果：以「退回餘額」為例，一次退款會產生四類記錄：

- `refunds`：`status = 1` 已完成、`refund_method = balance`、記錄操作人與原因；
- 一張 `type = refund` 的紅字賬單：`amount` 與 `paid_amount` 為負數、`status = 1` 已付、通過 `origin_invoice_id` 指回原賬單；
- 一條 `event_type = invoice_refund` 的入賬流水；
- 若原賬單有充值記錄，按剩餘可衝抵額度生成一條負向的 `refund_offset` 充值記錄，通過 `origin_recharge_record_id` 指回原記錄。

## 5. 對賬口徑與不一致排查

所有對賬命令都不會刪除或改寫資金資料，只做檢測與必要的補建。

主要口徑：

| 命令                                             | 口徑與退出碼                                                                                                                                                                                            |
| ------------------------------------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `php artisan reconcile:account-balance`          | 比對每個 `(user_id, account_type)` 在流水裡最新一條的 `balance_after` 與 `user_accounts` 對應餘額欄位；差超過 0.01 元列為差異、寫 warning 日誌並返回非零退出碼，`--json` 輸出明細                       |
| `php artisan finance:audit-ledger-consistency`   | 比對 `cash_balance` 與流水 `account_type = cash` 的 `change_amount` 合計，並統計缺 `source_type` / `source_id` / `trace_id` 的流水條數與「充值已成功但賬單橋接缺失」條數；`--strict` 時有異常即非零退出 |
| `php artisan trade:reconcile-invoice-order`      | 審計並修復 `orders` 與 `invoices` 的繫結關係與支付狀態投影                                                                                                                                              |
| `php artisan payment:audit-third-party-boundary` | 審計支付記錄是否只承載第三方真實資金流入，歷史非第三方記錄只保留審計口徑                                                                                                                                |
| `php artisan account:check-projections`          | 檢查 `users` 與 `user_accounts` 的賬戶投影完整性與餘額一致性                                                                                                                                            |

`reconcile:account-balance` 的賬戶欄位對映：`cash` → `cash_balance`、`referral_frozen` → `referral_frozen_balance`、`referral_available` → `referral_available_balance`、`referral_pending_withdrawal` → `referral_pending_withdrawal_balance`、`referral_withdrawn` → `referral_withdrawn_balance`。

補數命令，只在確認缺口後使用：

- `php artisan payment:compensate-recharge-invoices`：為充值已成功但 `invoice_id` 為空的支付記錄補建充值賬單；
- `php artisan finance:backfill-refund-records`：回填已退款但未落 `refunds` 表的記錄，並同步賬單的退款標記；
- `php artisan finance:backfill-ledger-sources`：高置信回填流水的 `source_type` 與充值橋接。

建議的排查順序：

1. 先跑 `reconcile:account-balance --json`，定位到具體使用者與 `account_type`；
2. 到該使用者詳情的「資金流水」標籤頁按時間看明細，用 `trace_id` 把賬單、支付、流水串起來；
3. 流水本身缺來源資訊時，跑 `finance:audit-ledger-consistency --strict` 看缺口規模；
4. 賬單側金額或狀態對不上時跑 `trade:reconcile-invoice-order`。注意其中的金額漂移（`amount_mismatch`）與「訂單已完成但賬單已取消」只報告、**不自動修復**，因為無法判斷哪一側才是真實意圖；
5. 需要留檔時把命令輸出交給日誌歸檔流程，見[日誌歸檔](../maintain/log-archive.md)。

常見現象對照：

| 現象                      | 先看哪裡                                                                                        |
| ------------------------- | ----------------------------------------------------------------------------------------------- |
| 使用者說餘額少了            | `reconcile:account-balance` 定位賬戶型別，再到該使用者「資金流水」看 `balance_after` 序列         |
| 充值付了錢餘額沒到        | 充值管理頁該筆的狀態；狀態成功但 `invoice_id` 為空時用 `payment:compensate-recharge-invoices`   |
| 賬單已付但訂單未開通      | [訂單與自動開通](orders.md) 的重試路徑；賬單側跑 `trade:reconcile-invoice-order` 看投影是否漂移 |
| 退款按鈕點了沒反應 / 置灰 | 對照第 4 節的五條前置條件，頁面會給出具體原因                                                   |
| 上游餘額告警              | 見第 6 節，這是供應商側餘額，不是本站使用者餘額                                                   |

## 6. 上游供應商餘額

這套系統不代上游記賬，也不向上游打款；它只**同步並留痕**上游賬戶餘額，用於在餘額耗盡前提醒你充值。

- `supplier_balances`：`balance`、`currency`、`low_balance_threshold`（預設 `20.00`）、`low_balance_alert_enabled`、`last_synced_at`、`last_attempted_at`、`last_sync_status`、`last_sync_error`、`low_balance_notified_at`。
- `supplier_balance_logs`：`balance`、`previous_balance`、`delta`、`currency`、`source`、`order_id`、`recorded_at`。`source` 取 `schedule`（定時同步）、`provision`（上游開通完成後觸發）、`manual`（管理員手動查詢）。
- **低餘額判定**：從未同步成功（`balance` 為 `null`）時不算「不足」，避免剛接入就誤報；比較走整數分而非浮點，邊界不受浮點誤差影響。
- 配置入口：商品 → 供應商（`/admin/products/suppliers`，許可權 `supplier.list`）裡按供應商設定閾值與告警開關；「上游同步日誌保留天數」（預設 `30`，範圍 1–365）與「餘額告警重複間隔」（預設 `24` 小時，範圍 1–720）在 `/admin/automation` 頁面裡。

## 7. 手工入賬與資金調整的邊界

資金調整隻有一條對管理員開放的通道：**使用者 → 使用者詳情頂部餘額卡片的「資金管理」彈窗**。

- 欄位：`用户`（只讀）、`操作类型`（增加餘額 / 扣減餘額）、`金额`（`0.01`–`999999`，兩位小數）、`备注`（必填）。
- 硬約束：金額為 `0` 直接拒絕；扣減後餘額小於 `0` 拒絕，並回顯當前餘額與扣減額；請求必須帶冪等標識（前端每次開啟彈窗重新生成），缺標識即拒絕執行；同一 `(user_id, 幂等标识)` 重放會返回首次產生的單據而不重複動賬，若標識相同但金額不同則明確拒絕；若賬單在而流水查不到，直接報錯讓你核查，而不是再動一次賬。
- 落庫效果：改 `user_accounts.cash_balance`（`version` 加一）→ 寫一條流水（增加記為 `manual_recharge`，扣減記為 `manual_deduction`）→ 建一張對應型別的賬單 → 增加餘額時再補一條 `scene = admin_recharge` 的充值記錄。賬單與流水共享同一個 `trace_id`。
- **手工把賬單標記為已付**：服務層 `InvoiceService::markPaidManually` 存在，規則是隻允許未付或逾期賬單、只能按應付金額**全額**入賬（不支援部分入賬），會補一條 `gateway = manual` 的支付審計記錄（保留 `trade_no`）供對賬追溯，並把該賬單下待支付的支付單置為失敗，可選是否同步觸發業務開通。**該能力當前沒有管理端路由與頁面入口**（該服務方法無呼叫方，訂單側的同類方法只在測試中被呼叫），因此不要把它寫進日常操作流程。

## 8. 不能做什麼

- **不物理刪除支付記錄**。支付記錄使用軟刪除，任何讓記錄消失的操作都會讓對賬口徑失去依據。
- **不物理刪除賬單**。作廢用「取消」表達，退款用紅字賬單表達，兩者都只改狀態。
- **不用前端傳來的金額**。金額一律服務端按商品配置、計費週期、代理折扣與優惠券重算，報價憑證會校驗一致性。
- **不直接改資料庫裡的餘額欄位**。繞過 `AccountService` 就繞過了 `version` 樂觀鎖與流水寫入，會讓 `reconcile:account-balance` 永久報差異，且無法還原經過。
- **不手工改賬單的 `amount` / `paid_amount` 去「平賬」**。這隻會製造金額漂移，而對賬命令按設計不會自動修復金額差異。
- **不走線下打款記退款**。系統只有「退回餘額」與「原路退款」兩條退款通道；給分銷獎勵打款是另一條鏈路，見[使用者與分銷](users.md)。
- **不把對賬命令的非零退出碼當噪音**。非零就意味著確實存在差異，按第 5 節的排查順序定位，而不是忽略。

## 關聯文件

- [日常運營](index.md)：運營地圖與每日建議複核。
- [使用者與分銷](users.md)：返佣與提現走的是另一套餘額欄位。
- [訂單與自動開通](orders.md)：賬單支付之後的開通鏈路。
- [支付渠道配置](../configure/payment.md)：渠道沒配好時充值頁會提示「當前沒有可用支付方式」。
- [日誌歸檔](../maintain/log-archive.md)：把對賬與審計輸出留檔。
- [架構概覽](../../develop/architecture.md)：賬單優先（invoice-first）結構的整體背景。
