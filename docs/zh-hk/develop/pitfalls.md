---
source: develop/pitfalls.md
sourceHash: 0ee78d8480b082b9c177898e01a9ea9c6036aac2f7e9e277cdf00b88cc8301da
generated: zh-hk
---

<!-- 本檔案由 docs-i18n.mjs trad zh-hk 從 docs/develop/pitfalls.md 產生，請勿手動編輯。 -->
# 工程踩坑記錄

按「症狀 → 根因 → 對策」記錄在真實任務中踩過、且容易重犯的坑。
部署側的問題見[部署與調度指南](../guide/deploy/scheduling.md#35-增量更新的实测坑)。

## 一、領域建模

### 1.1 不要從字段名推斷實體層級

**症狀**：給商品分層建模時，看到 `product_type` / `ProductType`，就認定它是商品分類樹的頂層，
於是產出「產品類型 = 第一層、一級分組 = 第二層…」的四層模型，還照此寫了術語表。

**根因**：`product_types` 這張表**根本不存在**。`ProductType` 只是 `App\Constants\ProductType`
裡的常量枚舉（`cloud_server`、`web_hosting` …），它作為 `first_product_groups.product_type`
**字段**掛在分組上，是屬性不是層級。真實結構只有三層：`first/second/third_product_groups`。

**對策**：動手建模前先用一條只讀 SQL 看真實數據（`SHOW TABLES LIKE '%product%'` +
逐表取幾行），別從字段名、類名或註釋推層級。錯誤建模的代價是整份術語表要重寫，
且會帶着錯誤結論去改幾十個文件。

### 1.2 代碼註釋可能片面甚至過時，以 UI 和實庫為準

`SiteProductGroupQuery` 的類註釋寫「一級菜單 = first_product_groups.code」，這句話本身沒錯；
但管理端把同一個實體的頂部條叫「一級菜單」、把分類樹樹根叫「一級分類」，兩者是同一張表。
只讀註釋會得出「一級菜單指代兩個實體」的結論。

**對策**：註釋用來理解意圖，**不用來判定事實**。看到術語衝突時，把後台界面截圖/實際渲染與實庫
數據擺在一起看——真實呈現優先於任何一方註釋。

## 二、批量文本替換

術語統一這類活兒天然想用腳本批量替換，但本項目踩過三次坑。**結論：逐處用編輯工具改，
批量只用於「先預覽影響範圍」。**

### 2.1 PowerShell hashtable 的嵌套數組會被展平

```powershell
$map = @{ 'a.vue' = @( @('旧文案','新文案') ) }   # 期望 $r 是数组
foreach ($r in $map['a.vue']) { $c.Replace($r[0], $r[1]) }
# 实际 $r 是字符串 → $r[0]/$r[1] 取到的是「第一个/第二个字符」
```

真實後果：`Replace("按","产")`、`Replace("用","户")`，把 `按旧端` 改成 `产旧端`、
`复用 syncSelectionRoute` 改成 `户户 syncSelectionRoute`。

**對策**：不要在 hashtable 裡放替換對數組。要批量就用扁平數組
`$pairs = @(@('a','b'), @('c','d'))`，或者乾脆逐處 edit。

### 2.2 擴展名要一次掃全

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

### 2.4 中文替換後要確認文件編碼沒壞

判斷文件是否被寫壞，用 `.NET` 按 UTF-8 顯式讀回來檢查目標詞是否存在，不要靠肉眼看 diff。

### 2.5 跨盤移動到系統臨時目錄會 EXDEV

倉庫在 `E:`、系統臨時目錄在 `C:` 時，`renameSync('E:\\TuraIDC\\docs\\x.md', '%TEMP%\\x.md')`
直接失敗：`EXDEV: cross-device link not permitted`，跨卷不能 rename。

要「把廢棄文件移出倉庫」時用**複製後刪除**（`copyFileSync` + `rmSync`）。臨時目錄適合新建中間
產物，不適合承載需要原地搬移的成品。

## 三、驗證與測試

### 3.1 跑全量測試前先確認數據庫可達

`php artisan test` 在數據庫不可達時不會立刻報錯，而是每個用例卡 4–20 秒連接超時，
整套跑十幾分鍾且因為輸出緩衝看不到任何進度。

**對策**：先單獨測連通性，再決定跑哪個子集。本機 `.env` 與 `phpunit.xml.dist` 指向的
數據庫通常都不可達，此時只有繼承裸 `PHPUnit\Framework\TestCase`（不啟動 Laravel、不連庫）
的用例能跑，其餘失敗全是 `QueryException`/`PDOException`，與代碼改動無關——要明確報告為未覆蓋風險。

### 3.2 確認 e2e 失敗是不是既有問題：`git stash` 對比基線

看到 e2e 失敗時，先分清「我改壞的」和「本來就紅的」：

```bash
git stash push -m verify      # 回到 HEAD
pnpm run test:e2e -- <spec>   # 基线上跑同一个用例
git stash pop                 # 恢复改动
```

基線失敗信息逐字相同 → 既有問題；基線通過 → 是本次引入的。

### 3.3 Playwright 報告文件的亂碼不可信

中文 Windows 環境下，Playwright 寫出的 `test-results/**/error-context.md` 會出現
`鍟嗗搧鍒嗙被` 這類亂碼（UTF-8 被按 GBK 解讀）。**那是報告文件的編碼問題，不代表測試代碼裡有亂碼。**
判斷真實失敗要看終端輸出（`Select-String` 過濾的那份），或直接對源文件按 UTF-8 讀回校驗。

### 3.4 `playwright.config.ts` 的 webServer 不能用 Unix 環境變量前綴

`'VITE_API_BASE_URL=... pnpm exec vite ...'` 是 Unix shell 寫法，Windows 的 cmd 不認，
webServer 直接啟動失敗，整個 e2e 套件在 Windows 上跑不起來。正確寫法是走 Playwright 的 `env` 字段
（`frontend-admin-v3/playwright.config.ts` 是正確樣板）。

另外 `projects` 裡的 `tablet`（`devices['iPad (gen 7)']`）用的是 WebKit，沒裝瀏覽器時
**只有 tablet project 全掛**，desktop/mobile 正常。`pnpm exec playwright install webkit` 可補。

### 3.5 SFC 裡的游離 import 會讓整個組件在運行時崩掉

v0.4.1 國際化時，批量腳本把 `import { t } from '@/locales'` 插到了**文件第 1 行**，
也就是 `<template>` 標籤**外面**。Vue 的 SFC 解析器會直接忽略它，於是：

- 模板與 script 裡的裸 `t('...')` 在 setup 階段拋 `ReferenceError: t is not defined`
- 報錯發生在 `setup()`，整個組件掛載失敗，**頁面全白且控制台只有一條難定位的警告**
- `pnpm run build`、類型檢查、`vue-tsc` **全部通過**——它們不校驗「腳本塊之外不能有頂層語句」

當時受害的是官網布局 `src/layout/WebsiteLayout.vue`（整站）、
`src/views/website/Home/components/HomeHeroCarousel.vue` 與 `HomeProductTabs.vue`（首頁）。

**怎麼發現**：`playwright test` 的失敗截圖是一片純白（`APP_LEN=0`），
再用一個 Playwright 探針腳本掛 `pageerror` 監聽把完整堆棧打出來，堆棧直接指到 `setup (...)`。

**怎麼防**：

```powershell
# 扫所有 .vue，找出「第一行就是 import/export」的文件
Get-ChildItem -Recurse -Include *.vue -Path <app>\src | ForEach-Object {
  $first = Get-Content $_.FullName -Encoding UTF8 -TotalCount 1
  if ($first -match '^\s*(import|export)\s') { "$($_.Name) :: $first" }
}
```

這類錯誤**只能靠運行時驗證發現**，任何靜態檢查和構建都不會報。改完 UI 一定要跑一遍 e2e 或真看一眼頁面。

### 3.6 `preg_match_all` 返回的是條數，寫 `=== 1` 會讓守衞靜默失效

`I18nLocaleConsistencyTest::test_templates_have_no_hardcoded_chinese` 裡有兩處正則掃描，
其中一處寫的是：

```php
if (preg_match_all('/>([^<>{}]*中文[^<>{}]*)</u', $tpl, $m) === 1) {
```

`preg_match_all` 返回的是**匹配條數**，不是布爾。真實頁面有幾十處硬編碼中文時它返回幾十，
`=== 1` 恆為假——**這道「模板層不得有硬編碼中文」的守衞從寫下那天起就沒執行過**。

同一段代碼裡還有個配套問題：命中後用 `str_contains($text, "\n") => continue` 跳過含換行的文本，
本意是排除跨多行的內容塊，但 Vue 模板裡文本節點**幾乎總是帶縮進換行**，
於是真命中也被放過去。正確做法是**按行拆開逐行判斷**。

**教訓**：寫自動守衞時，先造一個**必定該失敗的輸入**驗證它真的會紅。
「測試一直綠」在這裡不是優點，是它根本沒在工作。判定條件要用 `> 0` 而不是 `=== 1`。

#### 3.6.1 修這道守衞時又踩了三個坑，規模數字反覆變

把上述兩處修好之後，同一道守衞又暴露三個新問題，命中數一路從 `0 → 171 → 12 → 1005 → 482`：

| 做法 | 結果 | 問題 |
| --- | --- | --- |
| 每份 `.vue` 只取**第一個** `<template>` 段 | 171 | 3000+ 行的 SFC 從第二段起全在盲區 |
| **逐段收集但只返回標籤之間的內容** | 12 | 錯得最隱蔽：純文本判據是 `>文本<`，去掉外層標籤後 `<template #title>等级列表</template>` 只剩「等級列表」，前後沒有 `>` `<`，永遠匹配不上 |
| 「每遇到一個閉標籤就記一段」 | 1005（虛高） | 嵌套時內外層重複計數 |
| **棧式取根 `<template>` 塊（含標籤）** | 482 | 正確口徑 |

要點：

1. **SFC 只有一個根 `<template>` 塊**，嵌套的 `<template #slot>` 都在它裡面。
   用棧求根塊（第一個 `<template>` 到使深度歸零的那個 `</template>`）既覆蓋全部嵌套內容，又不會重複計數。
   「開標籤 → 其後第一個閉標籤」的線性配對會在**內層**閉合處提前截斷。
2. **返回的片段必須含標籤**。正則靠 `>文本<` 定位文本節點，剝掉外層標籤後單行段落就無從匹配。
3. **每換一次口徑都要拿獨立實現交叉核對**。本輪最終用一份等價的 JS 掃描器獨立算出同樣的 482，才敢基線化。

#### 3.6.2 存量太大時用「債務棘輪」，不要清零也不要放行

482 處逐條清理不是一次能做完的體量，而把守衞留在錯誤口徑等於沒有守衞。落地方式是
按「app → 文件 → 處數」凍結存量（`TEMPLATE_CHINESE_BASELINE`）：

- 清單外的文件出現中文 → 紅
- 清單內文件處數**超過**登記值 → 紅（消息帶 `89 → 90` 差值）
- 處數變少 → 綠，順手把基線數字改小，進度就體現在 diff 裡

寫完之後**必須**驗證它會紅：基線態綠 → 把某文件基線調低 1 → 紅且消息精確 → 還原後綠。
本輪的實測輸出：`frontend-admin-v3 有 1 个文件的硬编码中文超出基线：pages/products/edit.vue（89 → 90）`。

### 3.7 應用支持按瀏覽器語言自適應之後，e2e 必須在配置裡釘住語言

三端首屏語言走 `shared/locale` 的 `resolveInitialLocale`（顯式選擇 > 瀏覽器偏好 > 默認 zh_CN）。
Playwright 的默認上下文跟隨宿主環境，本機/CI 是 `en-US`，於是**頁面渲染英文，而各 spec 的斷言全部寫的是中文**——
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

注意 `devices['Desktop Chrome']` 不含 `locale`，所以項目級 `use` 不會蓋掉頂層的這個設置。

### 3.8 Vite 新增依賴後 dev server 會一直 504

給前端引入了新的依賴（本例是把語言基建抽到 `shared/locale`）之後，dev server 會持續返回
`504 (Outdated Optimize Dep)`，頁面只剩 `#app` 空殼。原因是依賴預構建快取的 hash 變了，
但瀏覽器仍在請求舊 hash 的模塊。

處理：刪掉 `node_modules/.vite` 再啟動 dev server（不是重啟就行，必須刪快取）。
注意 e2e 的 `webServer` 只等端口就緒、不等依賴優化完成，所以**第一次跑必紅、第二次跑會綠**，
容易誤判成 flaky——見到這種「一跑就紅、重跑就好」的，先懷疑 504，別急着改代碼。

### 3.9 `git grep` 看不到未跟蹤文件，重排後的核查會靜默漏檢

批量搬遷、改名或新增文件之後，用 `git grep`／`git status`／`git diff` 做全倉核查會**漏掉全部新
文件**——它們此時還是未跟蹤狀態。文檔站重排時踩過一次：`git grep 'docs/references'` 在 90 篇
文檔裡報 0 命中，而正文實際有 53 處舊路徑。

對策：核查走文件系統級全文搜尋（編輯器搜尋或 `rg`），或者先 `git add -A` 把它變成已跟蹤再搜。
判定「還有沒有殘留引用」時，後者更接近提交後的真實狀態。

## 四、提交

### 4.1 必須 `--no-verify`

根 `package.json` 的 lint-staged 有 `*.{css,scss,json,md}` → `prettier --write`，
會把 `backend/public/media/console-panel/bootstrap.min.css` 這類 minified 單行資產拆成上萬行，
產生幾萬行無意義 diff。用 `git commit --no-verify` 提交。

### 4.2 提交信息別帶 BOM

`Out-File -Encoding utf8` 在 Windows PowerShell 5.1 會寫入 UTF-8 BOM（`git commit` 輸出裡能看到
`﻿Fix:`）。Git 會自動剝離，功能不受影響，但標題會顯示雜字符。用無 BOM 寫法：

```powershell
[System.IO.File]::WriteAllText($p, $msg, (New-Object System.Text.UTF8Encoding($false)))
git commit --no-verify -F $p
```

### 4.3 先 rebase 再 push

遠程可能已有他人提交，直接 push 會被拒。先 `git pull --rebase origin main`，
衝突時手工處理——**不要**用 `git checkout origin/main -- <file>` 去「取單個文件」，
那會讓本地 HEAD 長期落後於遠程，見部署文檔裡的説明。