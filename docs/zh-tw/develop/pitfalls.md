---
source: develop/pitfalls.md
sourceHash: 02fd4ead22323b2dfdd5880efa7f4a4131b5c52a18c2bc7627e8f94accce6728
generated: zh-tw
---

<!-- 本檔案由 docs-i18n.mjs trad zh-tw 從 docs/develop/pitfalls.md 產生，請勿手動編輯。 -->
# 工程踩坑記錄

按「症狀 → 根因 → 對策」記錄在真實任務中踩過、且容易重犯的坑。
部署側的問題見[部署與排程指南](../guide/deploy/scheduling.md#35-增量更新的实测坑)。

## 一、領域建模

### 1.1 不要從欄位名推斷實體層級

**症狀**：給商品分層建模時，看到 `product_type` / `ProductType`，就認定它是商品分類樹的頂層，
於是產出「產品型別 = 第一層、一級分組 = 第二層…」的四層模型，還照此寫了術語表。

**根因**：`product_types` 這張表**根本不存在**。`ProductType` 只是 `App\Constants\ProductType`
裡的常量列舉（`cloud_server`、`web_hosting` …），它作為 `first_product_groups.product_type`
**欄位**掛在分組上，是屬性不是層級。真實結構只有三層：`first/second/third_product_groups`。

**對策**：動手建模前先用一條只讀 SQL 看真實資料（`SHOW TABLES LIKE '%product%'` +
逐表取幾行），別從欄位名、類名或註釋推層級。錯誤建模的代價是整份術語表要重寫，
且會帶著錯誤結論去改幾十個檔案。

### 1.2 程式碼註釋可能片面甚至過時，以 UI 和實庫為準

`SiteProductGroupQuery` 的類註釋寫「一級選單 = first_product_groups.code」，這句話本身沒錯；
但管理端把同一個實體的頂部條叫「一級選單」、把分類樹樹根叫「一級分類」，兩者是同一張表。
只讀註釋會得出「一級選單指代兩個實體」的結論。

**對策**：註釋用來理解意圖，**不用來判定事實**。看到術語衝突時，把後臺介面截圖/實際渲染與實庫
資料擺在一起看——真實呈現優先於任何一方註釋。

## 二、批次文本替換

術語統一這類活兒天然想用指令碼批次替換，但本專案踩過三次坑。**結論：逐處用編輯工具改，
批次只用於「先預覽影響範圍」。**

### 2.1 PowerShell hashtable 的巢狀陣列會被展平

```powershell
$map = @{ 'a.vue' = @( @('旧文案','新文案') ) }   # 期望 $r 是数组
foreach ($r in $map['a.vue']) { $c.Replace($r[0], $r[1]) }
# 实际 $r 是字符串 → $r[0]/$r[1] 取到的是「第一个/第二个字符」
```

真實後果：`Replace("按","产")`、`Replace("用","户")`，把 `按旧端` 改成 `产旧端`、
`复用 syncSelectionRoute` 改成 `户户 syncSelectionRoute`。

**對策**：不要在 hashtable 裡放替換對陣列。要批次就用扁平陣列
`$pairs = @(@('a','b'), @('c','d'))`，或者乾脆逐處 edit。

### 2.2 副檔名要一次掃全

掃了 `.vue/.ts/.js` 卻漏了 `.less`，`layout.less` 裡留了一處舊術語，直到部署後 grep 產物才發現。

**對策**：`$ext = @('*.vue','*.ts','*.js','*.less','*.scss')` 一次列全；`docs/` 下的
`.md` 往往也要一起掃。

### 2.3 部分匹配會產出語義殘缺的句子

原文 `下方为二级 / 三级分类`，替換詞是 `三级分类` → `三级分组`，結果變成
`下方为二级 / 三级分组`——「二級」後面缺了對應詞，句子讀起來是壞的，但 grep 查不出問題。

**對策**：替換後必須做**殘留檢查 + 逐行 diff 複核**，兩者都要：

```powershell
# 1) 残留检查
Select-String -Path <文件> -Pattern '旧术语'
# 2) 找出「不像预期」的改动行：所有改动都应含新术语，不含的就是意外
git diff -U0 | Where-Object { $_ -match '^[+-]' -and $_ -notmatch '^(---|\+\+\+)' -and $_ -notmatch '新术语' }
```

### 2.4 中文替換後要確認檔案編碼沒壞

判斷檔案是否被寫壞，用 `.NET` 按 UTF-8 顯式讀回來檢查目標詞是否存在，不要靠肉眼看 diff。

### 2.5 跨盤移動到系統臨時目錄會 EXDEV

倉庫在 `E:`、系統臨時目錄在 `C:` 時，`renameSync('E:\\TuraIDC\\docs\\x.md', '%TEMP%\\x.md')`
直接失敗：`EXDEV: cross-device link not permitted`，跨卷不能 rename。

要「把廢棄檔案移出倉庫」時用**複製後刪除**（`copyFileSync` + `rmSync`）。臨時目錄適合新建中間
產物，不適合承載需要原地搬移的成品。

## 三、驗證與測試

### 3.1 跑全量測試前先確認資料庫可達

`php artisan test` 在資料庫不可達時不會立刻報錯，而是每個用例卡 4–20 秒連線超時，
整套跑十幾分鍾且因為輸出緩衝看不到任何進度。

**對策**：先單獨測連通性，再決定跑哪個子集。本機 `.env` 與 `phpunit.xml.dist` 指向的
資料庫通常都不可達，此時只有繼承裸 `PHPUnit\Framework\TestCase`（不啟動 Laravel、不連庫）
的用例能跑，其餘失敗全是 `QueryException`/`PDOException`，與程式碼改動無關——要明確報告為未覆蓋風險。

### 3.2 確認 e2e 失敗是不是既有問題：`git stash` 對比基線

看到 e2e 失敗時，先分清「我改壞的」和「本來就紅的」：

```bash
git stash push -m verify      # 回到 HEAD
pnpm run test:e2e -- <spec>   # 基线上跑同一个用例
git stash pop                 # 恢复改动
```

基線失敗資訊逐字相同 → 既有問題；基線通過 → 是本次引入的。

### 3.3 Playwright 報告檔案的亂碼不可信

中文 Windows 環境下，Playwright 寫出的 `test-results/**/error-context.md` 會出現
`鍟嗗搧鍒嗙被` 這類亂碼（UTF-8 被按 GBK 解讀）。**那是報告檔案的編碼問題，不代表測試程式碼裡有亂碼。**
判斷真實失敗要看終端輸出（`Select-String` 過濾的那份），或直接對原始檔按 UTF-8 讀回校驗。

### 3.4 `playwright.config.ts` 的 webServer 不能用 Unix 環境變數字首

`'VITE_API_BASE_URL=... pnpm exec vite ...'` 是 Unix shell 寫法，Windows 的 cmd 不認，
webServer 直接啟動失敗，整個 e2e 套件在 Windows 上跑不起來。正確寫法是走 Playwright 的 `env` 欄位
（`frontend-admin-v3/playwright.config.ts` 是正確樣板）。

另外 `projects` 裡的 `tablet`（`devices['iPad (gen 7)']`）用的是 WebKit，沒裝瀏覽器時
**只有 tablet project 全掛**，desktop/mobile 正常。`pnpm exec playwright install webkit` 可補。

### 3.5 SFC 裡的游離 import 會讓整個元件在執行時崩掉

v0.4.1 國際化時，批次指令碼把 `import { t } from '@/locales'` 插到了**檔案第 1 行**，
也就是 `<template>` 標籤**外面**。Vue 的 SFC 解析器會直接忽略它，於是：

- 模板與 script 裡的裸 `t('...')` 在 setup 階段拋 `ReferenceError: t is not defined`
- 報錯發生在 `setup()`，整個元件掛載失敗，**頁面全白且控制台只有一條難定位的警告**
- `pnpm run build`、型別檢查、`vue-tsc` **全部通過**——它們不校驗「指令碼塊之外不能有頂層語句」

當時受害的是官網布局 `src/layout/WebsiteLayout.vue`（整站）、
`src/views/website/Home/components/HomeHeroCarousel.vue` 與 `HomeProductTabs.vue`（首頁）。

**怎麼發現**：`playwright test` 的失敗截圖是一片純白（`APP_LEN=0`），
再用一個 Playwright 探針指令碼掛 `pageerror` 監聽把完整堆疊打出來，堆疊直接指到 `setup (...)`。

**怎麼防**：

```powershell
# 扫所有 .vue，找出「第一行就是 import/export」的文件
Get-ChildItem -Recurse -Include *.vue -Path <app>\src | ForEach-Object {
  $first = Get-Content $_.FullName -Encoding UTF8 -TotalCount 1
  if ($first -match '^\s*(import|export)\s') { "$($_.Name) :: $first" }
}
```

這類錯誤**只能靠執行時驗證發現**，任何靜態檢查和構建都不會報。改完 UI 一定要跑一遍 e2e 或真看一眼頁面。

### 3.6 `preg_match_all` 返回的是條數，寫 `=== 1` 會讓守衛靜默失效

`I18nLocaleConsistencyTest::test_templates_have_no_hardcoded_chinese` 裡有兩處正則掃描，
其中一處寫的是：

```php
if (preg_match_all('/>([^<>{}]*中文[^<>{}]*)</u', $tpl, $m) === 1) {
```

`preg_match_all` 返回的是**匹配條數**，不是布林。真實頁面有幾十處硬編碼中文時它返回幾十，
`=== 1` 恆為假——**這道「模板層不得有硬編碼中文」的守衛從寫下那天起就沒執行過**。

同一段程式碼裡還有個配套問題：命中後用 `str_contains($text, "\n") => continue` 跳過含換行的文本，
本意是排除跨多行的內容塊，但 Vue 模板裡文本節點**幾乎總是帶縮排換行**，
於是真命中也被放過去。正確做法是**按行拆開逐行判斷**。

**教訓**：寫自動守衛時，先造一個**必定該失敗的輸入**驗證它真的會紅。
「測試一直綠」在這裡不是優點，是它根本沒在工作。判定條件要用 `> 0` 而不是 `=== 1`。

#### 3.6.1 修這道守衛時又踩了三個坑，規模數字反覆變

把上述兩處修好之後，同一道守衛又暴露三個新問題，命中數一路從 `0 → 171 → 12 → 1005 → 482`：

| 做法 | 結果 | 問題 |
| --- | --- | --- |
| 每份 `.vue` 只取**第一個** `<template>` 段 | 171 | 3000+ 行的 SFC 從第二段起全在盲區 |
| **逐段收集但只返回標籤之間的內容** | 12 | 錯得最隱蔽：純文本判據是 `>文本<`，去掉外層標籤後 `<template #title>等级列表</template>` 只剩「等級列表」，前後沒有 `>` `<`，永遠匹配不上 |
| 「每遇到一個閉標籤就記一段」 | 1005（虛高） | 巢狀時內外層重複計數 |
| **棧式取根 `<template>` 塊（含標籤）** | 482 | 正確口徑 |

要點：

1. **SFC 只有一個根 `<template>` 塊**，巢狀的 `<template #slot>` 都在它裡面。
   用棧求根塊（第一個 `<template>` 到使深度歸零的那個 `</template>`）既覆蓋全部巢狀內容，又不會重複計數。
   「開標籤 → 其後第一個閉標籤」的線性配對會在**內層**閉合處提前截斷。
2. **返回的片段必須含標籤**。正則靠 `>文本<` 定位文本節點，剝掉外層標籤後單行段落就無從匹配。
3. **每換一次口徑都要拿獨立實現交叉核對**。本輪最終用一份等價的 JS 掃描器獨立算出同樣的 482，才敢基線化。

#### 3.6.2 存量太大時用「債務棘輪」，不要清零也不要放行

482 處逐條清理不是一次能做完的體量，而把守衛留在錯誤口徑等於沒有守衛。落地方式是
按「app → 檔案 → 處數」凍結存量（`TEMPLATE_CHINESE_BASELINE`）：

- 清單外的檔案出現中文 → 紅
- 清單內檔案處數**超過**登記值 → 紅（訊息帶 `89 → 90` 差值）
- 處數變少 → 綠，順手把基線數字改小，進度就體現在 diff 裡

寫完之後**必須**驗證它會紅：基線態綠 → 把某檔案基線調低 1 → 紅且訊息精確 → 還原後綠。
本輪的實測輸出：`frontend-admin-v3 有 1 个文件的硬编码中文超出基线：pages/products/edit.vue（89 → 90）`。

### 3.7 應用支援按瀏覽器語言自適應之後，e2e 必須在配置裡釘住語言

三端首屏語言走 `shared/locale` 的 `resolveInitialLocale`（顯式選擇 > 瀏覽器偏好 > 預設 zh_CN）。
Playwright 的預設上下文跟隨宿主環境，本機/CI 是 `en-US`，於是**頁面渲染英文，而各 spec 的斷言全部寫的是中文**——
一整批「看起來像迴歸、其實是環境差異」的紅燈。

實測規模：控制台一次跑 **13 failed / 7 passed**，全是這個原因。

**正確做法**：在各 app 的 `playwright.config.ts` 頂層 `use` 裡釘一次，覆蓋全部 spec。

```ts
use: {
  locale: 'zh-CN',   // 应用会按浏览器偏好自适应，不钉的话断言里的中文匹配不上
  baseURL: 'http://127.0.0.1:5176',
},
```

釘完之後控制台 **20 passed / 0 failed**。

**不要**在每個 spec 裡用 `page.addInitScript` 寫 `localStorage['turaidc-locale:<app>']`——
那是第二套機制。配置層能覆蓋的，就不要下沉到用例裡重複一遍。
（兩者還不同源：配置層釘的是 `navigator.language`，走「探測」分支；spec 裡寫 storage 走的是「顯式選擇」分支。）

注意 `devices['Desktop Chrome']` 不含 `locale`，所以專案級 `use` 不會蓋掉頂層的這個設定。

### 3.8 Vite 新增依賴後 dev server 會一直 504

給前端引入了新的依賴（本例是把語言基建抽到 `shared/locale`）之後，dev server 會持續返回
`504 (Outdated Optimize Dep)`，頁面只剩 `#app` 空殼。原因是依賴預構建快取的 hash 變了，
但瀏覽器仍在請求舊 hash 的模組。

處理：刪掉 `node_modules/.vite` 再啟動 dev server（不是重啟就行，必須刪快取）。
注意 e2e 的 `webServer` 只等埠就緒、不等依賴最佳化完成，所以**第一次跑必紅、第二次跑會綠**，
容易誤判成 flaky——見到這種「一跑就紅、重跑就好」的，先懷疑 504，別急著改程式碼。

### 3.9 `git grep` 看不到未跟蹤檔案，重排後的核查會靜默漏檢

批次搬遷、改名或新增檔案之後，用 `git grep`／`git status`／`git diff` 做全倉核查會**漏掉全部新
檔案**——它們此時還是未跟蹤狀態。文件站重排時踩過一次：`git grep 'docs/references'` 在 90 篇
文件裡報 0 命中，而正文實際有 53 處舊路徑。

對策：核查走檔案系統級全文搜尋（編輯器搜尋或 `rg`），或者先 `git add -A` 把它變成已跟蹤再搜。
判定「還有沒有殘留引用」時，後者更接近提交後的真實狀態。

### 3.10 語言包裡一個裸 `@` 能讓整個應用白屏，而且構建/型別檢查全綠

vue-i18n 的訊息語法裡 `@` 是 **linked message** 字首（`@:key`、`@.lower:key`）。
把 `@` 當普通字元寫進譯文，編譯階段就拋：

```text
SyntaxError: Message compilation error: Invalid linked format
1  |  完整邮箱地址，例如 no-reply@example.com
   |                     ^
```

報錯發生在元件渲染時（`$t()` 首次編譯該訊息），**整個元件掛載失敗、頁面全白**，
而 `vue-tsc`、`eslint`、`vite build` 全部通過——它們都只把 JSON 當資料，不編譯訊息。

實測後果：管理端外掛配置頁（SMTP 賬號佔位符裡寫了 `no-reply@example.com`）整頁空白，
`#app` 的 `innerHTML` 長度為 0，只有 `pageerror` 裡能看到真因。

**對策**：訊息裡的字面量 `@` 必須寫成 `{'@'}`（本專案既有鍵 `plugins.smtp` 的
「字母、數字、下劃線、點、橫線或 `{'@'}`」就是這麼寫的）。同理要注意 `|`（複數分支）
與未配對的 `{`/`}`。

**怎麼查**：

```js
// 扫所有语言 JSON，找出仍然裸用 @ 的值
for (const [k, v] of flatten(JSON.parse(readFileSync(file, 'utf8')))) {
  if (/@(?![.:])/.test(v.replace(/\{'@'\}/g, ''))) console.log(k, v);
}
```

**怎麼發現**：e2e 失敗截圖是一片純白時，別急著看斷言，先掛 `pageerror` 監聽把堆疊打出來。

### 3.11 PowerShell 的 `Get-Content -Raw` + `Set-Content` 會把 UTF-8 原始碼寫成亂碼

想「批次把某個字串換成另一個」時，下面這段在 Windows PowerShell 5.1 上會把**整個檔案的中文
全部變成 `浜戞湇鍔″櫒` 這種亂碼**：

```powershell
$c = Get-Content $p -Raw          # 5.1 默认按 ANSI 解码，UTF-8 中文立刻变成乱码字符
$c = $c.Replace('旧', '新')
Set-Content -Path $p -Value $c -Encoding UTF8
```

一次就改壞了 1090 行內容（`git diff` 顯示 1090 insertions / 1091 deletions），
而且**測試仍然能跑**（只是中文斷言全掛），很容易被誤當成業務迴歸。

**對策**：

1. 逐處用編輯工具（`edit` 工具 / 編輯器替換）改，不要在 PowerShell 裡做整檔案讀寫；
2. 確實要指令碼來做，用 Node 以 buffer 讀寫：`execFileSync('git', ['show', 'HEAD:' + p])` 取原始位元組、
   `fs.writeFileSync(p, buf)` 寫回；
3. 已經寫壞了：`git checkout -- <file>` 會被安全網攔住，用 `git show HEAD:<path>` 取位元組寫回，
   再逐處重新應用改動（**注意會同時丟掉這段時間裡別人對該檔案的改動**，重放前先看清楚要補哪些）。

### 3.12 別直接 `new finfo()`：別人的機器沒裝 ext-fileinfo，上傳介面就是 500

`StoreMediaFileRequest` 曾在校驗的 `after()` 裡直接 `new finfo(FILEINFO_MIME_TYPE)` 做二次型別校驗。
`ext-fileinfo` 並不是 PHP 必裝擴充套件，缺它時 `new finfo()` 拋的是 **`Error` 而不是 `Exception`**，
框架不會把它翻成 422，直接兜成 500「伺服器內部錯誤」+ 請求編號——客戶完全看不出發生了什麼。
實測表現：上傳 20ms 就 500，且檔案已經落到 `php` 的臨時目錄裡。

**對策**：用 `class_exists(finfo::class)` 判斷後再用；缺擴充套件時跳過這層二次校驗即可——
Laravel 的 `mimetypes` 規則本身走 Symfony 的 MimeTypeGuesser，fileinfo 缺失時會退化為副檔名推斷，
仍然擋得住明顯不對的型別。

同一類「基礎設施問題偽裝成 500」的地方還有：磁碟不足以前拋 `RuntimeException`、
不支援的媒體型別以前拋 `InvalidArgumentException`。這些都應該拋 `BusinessException`，
讓前端拿到 422 + 一句能照著做的中文，而不是一個請求編號。

**排查手法**：拿到客戶報的請求編號時，先看是不是 500 且耗時極短（幾十毫秒）——
那通常是「校驗/入口階段就拋了」，而不是業務邏輯跑掛，優先懷疑擴充套件缺失、目錄許可權、磁碟空間。

### 3.13 「引數驗證失敗」這種兜底文案，會把 422 的真實原因吃掉

`bootstrap/app.php` 把所有 `ValidationException` 統一成
`{code: 42200, message: '参数验证失败', data: {errors: {...}}}`，真實原因在 `data.errors` 裡。
管理端的 `errorMessage()` 取到欄位錯誤後還要過一道「必須是中文」的過濾——本意是不讓英文堆疊漏到介面上。
可部署方的 `APP_LOCALE` 若是 `en`，Laravel 的欄位文案就是
`The third product group id field is required.`，被這道過濾擋掉，管理員端只剩「引數驗證失敗」。

**對策**：欄位級校驗錯誤來自校驗器，是 name → [message] 的結構化資料，不會夾帶堆疊，
所以只對「頂層 message」保留中文過濾，對 `errors` 對映**不要求語言**（見 `firstNonEmptyMessage`）。
真正該做的是把關鍵欄位的文案寫在 FormRequest 的 `messages()` 裡，四語言環境都不會缺。

### 3.14 上游開通失敗不能當「可重試錯誤」處理，否則一次購買會在上游開出 4 筆訂單

支付後履約是「同步跑一次 + `provision` 佇列 `$tries=3`」。只要失敗被拋成普通異常，佇列就會
把同一次購買再走一遍「清空購物車 → 加購 → 結算」，上游於是多出 3 筆一模一樣的訂單。
對接魔方財務的客戶看到的是「一個新訂單，沒成功自動發了 4 筆相同訂單」。

真正的坑在於**結算成功之後**：`/cart/settle` 一旦返回成功，上游訂單就已經存在了，
此刻只要本地解析不出賬單號、或請求在返回途中斷掉，`provision_data` 裡就不會有
`upstream_invoice_id`，下一輪重試的閘門（`assertNoUnresolvedUpstreamProvisionInvoice`）
讀到的仍是 0，於是放行——重複下單就是這麼來的。

**現在的三道防線**（`ProvisionService` + `ZjmfProvisionService`）：

1. 結算成功後按主機名回查上游例項，找到就直接複用，絕不重新下單；
   找不到或查不動，就落 `upstream_provision_uncertain_at` 標記並拋
   `ProvisionRetryNotAllowedException`，**停止**佇列重試，轉人工核實。
2. 閘門只認兩類終止態：`upstream_invoice_id > 0` 卻沒有例項標識、或存在未確認標記。
   兩者都要求管理員先在上游核實，確認沒有訂單後由「一鍵上游開通」勾選強制重開。
3. `ensureLocalService` 按訂單加鎖並按 `order_id + product_id` 複查，避免併發履約
   給同一個訂單建出兩條服務（截圖裡同一臺上遊例項掛著兩行服務就是這麼來的）。

**對策**：任何「會產生上游訂單」的失敗路徑都必須落一個可冪等的檢查點（賬單號、例項號或
主機名），並用不可重試的異常型別終止自動重試。切不要把這類失敗混進通用異常讓佇列重試。

## 四、提交
### 4.1 必須 `--no-verify`

根 `package.json` 的 lint-staged 有 `*.{css,scss,json,md}` → `prettier --write`，
會把 `backend/public/media/console-panel/bootstrap.min.css` 這類 minified 單行資產拆成上萬行，
產生幾萬行無意義 diff。用 `git commit --no-verify` 提交。

### 4.2 提交資訊別帶 BOM

`Out-File -Encoding utf8` 在 Windows PowerShell 5.1 會寫入 UTF-8 BOM（`git commit` 輸出裡能看到
`﻿Fix:`）。Git 會自動剝離，功能不受影響，但標題會顯示雜字元。用無 BOM 寫法：

```powershell
[System.IO.File]::WriteAllText($p, $msg, (New-Object System.Text.UTF8Encoding($false)))
git commit --no-verify -F $p
```

### 4.3 先 rebase 再 push

遠端可能已有他人提交，直接 push 會被拒。先 `git pull --rebase origin main`，
衝突時手工處理——**不要**用 `git checkout origin/main -- <file>` 去「取單個檔案」，
那會讓本地 HEAD 長期落後於遠端，見部署文件裡的說明。