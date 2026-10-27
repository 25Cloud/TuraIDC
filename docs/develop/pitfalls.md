# 工程踩坑记录

按「症状 → 根因 → 对策」记录在真实任务中踩过、且容易重犯的坑。
部署侧的问题见[部署与调度指南](../guide/deploy/scheduling.md#35-增量更新的实测坑)。

## 一、领域建模

### 1.1 不要从字段名推断实体层级

**症状**：给商品分层建模时，看到 `product_type` / `ProductType`，就认定它是商品分类树的顶层，
于是产出「产品类型 = 第一层、一级分组 = 第二层…」的四层模型，还照此写了术语表。

**根因**：`product_types` 这张表**根本不存在**。`ProductType` 只是 `App\Constants\ProductType`
里的常量枚举（`cloud_server`、`web_hosting` …），它作为 `first_product_groups.product_type`
**字段**挂在分组上，是属性不是层级。真实结构只有三层：`first/second/third_product_groups`。

**对策**：动手建模前先用一条只读 SQL 看真实数据（`SHOW TABLES LIKE '%product%'` +
逐表取几行），别从字段名、类名或注释推层级。错误建模的代价是整份术语表要重写，
且会带着错误结论去改几十个文件。

### 1.2 代码注释可能片面甚至过时，以 UI 和实库为准

`SiteProductGroupQuery` 的类注释写「一级菜单 = first_product_groups.code」，这句话本身没错；
但管理端把同一个实体的顶部条叫「一级菜单」、把分类树树根叫「一级分类」，两者是同一张表。
只读注释会得出「一级菜单指代两个实体」的结论。

**对策**：注释用来理解意图，**不用来判定事实**。看到术语冲突时，把后台界面截图/实际渲染与实库
数据摆在一起看——真实呈现优先于任何一方注释。

## 二、批量文本替换

术语统一这类活儿天然想用脚本批量替换，但本项目踩过三次坑。**结论：逐处用编辑工具改，
批量只用于「先预览影响范围」。**

### 2.1 PowerShell hashtable 的嵌套数组会被展平

```powershell
$map = @{ 'a.vue' = @( @('旧文案','新文案') ) }   # 期望 $r 是数组
foreach ($r in $map['a.vue']) { $c.Replace($r[0], $r[1]) }
# 实际 $r 是字符串 → $r[0]/$r[1] 取到的是「第一个/第二个字符」
```

真实后果：`Replace("按","产")`、`Replace("用","户")`，把 `按旧端` 改成 `产旧端`、
`复用 syncSelectionRoute` 改成 `户户 syncSelectionRoute`。

**对策**：不要在 hashtable 里放替换对数组。要批量就用扁平数组
`$pairs = @(@('a','b'), @('c','d'))`，或者干脆逐处 edit。

### 2.2 扩展名要一次扫全

扫了 `.vue/.ts/.js` 却漏了 `.less`，`layout.less` 里留了一处旧术语，直到部署后 grep 产物才发现。

**对策**：`$ext = @('*.vue','*.ts','*.js','*.less','*.scss')` 一次列全；`docs/` 下的
`.md` 往往也要一起扫。

### 2.3 部分匹配会产出语义残缺的句子

原文 `下方为二级 / 三级分类`，替换词是 `三级分类` → `三级分组`，结果变成
`下方为二级 / 三级分组`——「二级」后面缺了对应词，句子读起来是坏的，但 grep 查不出问题。

**对策**：替换后必须做**残留检查 + 逐行 diff 复核**，两者都要：

```powershell
# 1) 残留检查
Select-String -Path <文件> -Pattern '旧术语'
# 2) 找出「不像预期」的改动行：所有改动都应含新术语，不含的就是意外
git diff -U0 | Where-Object { $_ -match '^[+-]' -and $_ -notmatch '^(---|\+\+\+)' -and $_ -notmatch '新术语' }
```

### 2.4 中文替换后要确认文件编码没坏

判断文件是否被写坏，用 `.NET` 按 UTF-8 显式读回来检查目标词是否存在，不要靠肉眼看 diff。

### 2.5 跨盘移动到系统临时目录会 EXDEV

仓库在 `E:`、系统临时目录在 `C:` 时，`renameSync('E:\\TuraIDC\\docs\\x.md', '%TEMP%\\x.md')`
直接失败：`EXDEV: cross-device link not permitted`，跨卷不能 rename。

要「把废弃文件移出仓库」时用**复制后删除**（`copyFileSync` + `rmSync`）。临时目录适合新建中间
产物，不适合承载需要原地搬移的成品。

## 三、验证与测试

### 3.1 跑全量测试前先确认数据库可达

`php artisan test` 在数据库不可达时不会立刻报错，而是每个用例卡 4–20 秒连接超时，
整套跑十几分钟且因为输出缓冲看不到任何进度。

**对策**：先单独测连通性，再决定跑哪个子集。本机 `.env` 与 `phpunit.xml.dist` 指向的
数据库通常都不可达，此时只有继承裸 `PHPUnit\Framework\TestCase`（不启动 Laravel、不连库）
的用例能跑，其余失败全是 `QueryException`/`PDOException`，与代码改动无关——要明确报告为未覆盖风险。

### 3.2 确认 e2e 失败是不是既有问题：`git stash` 对比基线

看到 e2e 失败时，先分清「我改坏的」和「本来就红的」：

```bash
git stash push -m verify      # 回到 HEAD
pnpm run test:e2e -- <spec>   # 基线上跑同一个用例
git stash pop                 # 恢复改动
```

基线失败信息逐字相同 → 既有问题；基线通过 → 是本次引入的。

### 3.3 Playwright 报告文件的乱码不可信

中文 Windows 环境下，Playwright 写出的 `test-results/**/error-context.md` 会出现
`鍟嗗搧鍒嗙被` 这类乱码（UTF-8 被按 GBK 解读）。**那是报告文件的编码问题，不代表测试代码里有乱码。**
判断真实失败要看终端输出（`Select-String` 过滤的那份），或直接对源文件按 UTF-8 读回校验。

### 3.4 `playwright.config.ts` 的 webServer 不能用 Unix 环境变量前缀

`'VITE_API_BASE_URL=... pnpm exec vite ...'` 是 Unix shell 写法，Windows 的 cmd 不认，
webServer 直接启动失败，整个 e2e 套件在 Windows 上跑不起来。正确写法是走 Playwright 的 `env` 字段
（`frontend-admin-v3/playwright.config.ts` 是正确样板）。

另外 `projects` 里的 `tablet`（`devices['iPad (gen 7)']`）用的是 WebKit，没装浏览器时
**只有 tablet project 全挂**，desktop/mobile 正常。`pnpm exec playwright install webkit` 可补。

### 3.5 SFC 里的游离 import 会让整个组件在运行时崩掉

v0.4.1 国际化时，批量脚本把 `import { t } from '@/locales'` 插到了**文件第 1 行**，
也就是 `<template>` 标签**外面**。Vue 的 SFC 解析器会直接忽略它，于是：

- 模板与 script 里的裸 `t('...')` 在 setup 阶段抛 `ReferenceError: t is not defined`
- 报错发生在 `setup()`，整个组件挂载失败，**页面全白且控制台只有一条难定位的警告**
- `pnpm run build`、类型检查、`vue-tsc` **全部通过**——它们不校验「脚本块之外不能有顶层语句」

当时受害的是官网布局 `src/layout/WebsiteLayout.vue`（整站）、
`src/views/website/Home/components/HomeHeroCarousel.vue` 与 `HomeProductTabs.vue`（首页）。

**怎么发现**：`playwright test` 的失败截图是一片纯白（`APP_LEN=0`），
再用一个 Playwright 探针脚本挂 `pageerror` 监听把完整堆栈打出来，堆栈直接指到 `setup (...)`。

**怎么防**：

```powershell
# 扫所有 .vue，找出「第一行就是 import/export」的文件
Get-ChildItem -Recurse -Include *.vue -Path <app>\src | ForEach-Object {
  $first = Get-Content $_.FullName -Encoding UTF8 -TotalCount 1
  if ($first -match '^\s*(import|export)\s') { "$($_.Name) :: $first" }
}
```

这类错误**只能靠运行时验证发现**，任何静态检查和构建都不会报。改完 UI 一定要跑一遍 e2e 或真看一眼页面。

### 3.6 `preg_match_all` 返回的是条数，写 `=== 1` 会让守卫静默失效

`I18nLocaleConsistencyTest::test_templates_have_no_hardcoded_chinese` 里有两处正则扫描，
其中一处写的是：

```php
if (preg_match_all('/>([^<>{}]*中文[^<>{}]*)</u', $tpl, $m) === 1) {
```

`preg_match_all` 返回的是**匹配条数**，不是布尔。真实页面有几十处硬编码中文时它返回几十，
`=== 1` 恒为假——**这道「模板层不得有硬编码中文」的守卫从写下那天起就没执行过**。

同一段代码里还有个配套问题：命中后用 `str_contains($text, "\n") => continue` 跳过含换行的文本，
本意是排除跨多行的内容块，但 Vue 模板里文本节点**几乎总是带缩进换行**，
于是真命中也被放过去。正确做法是**按行拆开逐行判断**。

**教训**：写自动守卫时，先造一个**必定该失败的输入**验证它真的会红。
「测试一直绿」在这里不是优点，是它根本没在工作。判定条件要用 `> 0` 而不是 `=== 1`。

#### 3.6.1 修这道守卫时又踩了三个坑，规模数字反复变

把上述两处修好之后，同一道守卫又暴露三个新问题，命中数一路从 `0 → 171 → 12 → 1005 → 482`：

| 做法 | 结果 | 问题 |
| --- | --- | --- |
| 每份 `.vue` 只取**第一个** `<template>` 段 | 171 | 3000+ 行的 SFC 从第二段起全在盲区 |
| **逐段收集但只返回标签之间的内容** | 12 | 错得最隐蔽：纯文本判据是 `>文本<`，去掉外层标签后 `<template #title>等级列表</template>` 只剩「等级列表」，前后没有 `>` `<`，永远匹配不上 |
| 「每遇到一个闭标签就记一段」 | 1005（虚高） | 嵌套时内外层重复计数 |
| **栈式取根 `<template>` 块（含标签）** | 482 | 正确口径 |

要点：

1. **SFC 只有一个根 `<template>` 块**，嵌套的 `<template #slot>` 都在它里面。
   用栈求根块（第一个 `<template>` 到使深度归零的那个 `</template>`）既覆盖全部嵌套内容，又不会重复计数。
   「开标签 → 其后第一个闭标签」的线性配对会在**内层**闭合处提前截断。
2. **返回的片段必须含标签**。正则靠 `>文本<` 定位文本节点，剥掉外层标签后单行段落就无从匹配。
3. **每换一次口径都要拿独立实现交叉核对**。本轮最终用一份等价的 JS 扫描器独立算出同样的 482，才敢基线化。

#### 3.6.2 存量太大时用「债务棘轮」，不要清零也不要放行

482 处逐条清理不是一次能做完的体量，而把守卫留在错误口径等于没有守卫。落地方式是
按「app → 文件 → 处数」冻结存量（`TEMPLATE_CHINESE_BASELINE`）：

- 清单外的文件出现中文 → 红
- 清单内文件处数**超过**登记值 → 红（消息带 `89 → 90` 差值）
- 处数变少 → 绿，顺手把基线数字改小，进度就体现在 diff 里

写完之后**必须**验证它会红：基线态绿 → 把某文件基线调低 1 → 红且消息精确 → 还原后绿。
本轮的实测输出：`frontend-admin-v3 有 1 个文件的硬编码中文超出基线：pages/products/edit.vue（89 → 90）`。

### 3.7 应用支持按浏览器语言自适应之后，e2e 必须在配置里钉住语言

三端首屏语言走 `shared/locale` 的 `resolveInitialLocale`（显式选择 > 浏览器偏好 > 默认 zh_CN）。
Playwright 的默认上下文跟随宿主环境，本机/CI 是 `en-US`，于是**页面渲染英文，而各 spec 的断言全部写的是中文**——
一整批「看起来像回归、其实是环境差异」的红灯。

实测规模：控制台一次跑 **13 failed / 7 passed**，全是这个原因。

**正确做法**：在各 app 的 `playwright.config.ts` 顶层 `use` 里钉一次，覆盖全部 spec。

```ts
use: {
  locale: 'zh-CN',   // 应用会按浏览器偏好自适应，不钉的话断言里的中文匹配不上
  baseURL: 'http://127.0.0.1:5176',
},
```

钉完之后控制台 **20 passed / 0 failed**。

**不要**在每个 spec 里用 `page.addInitScript` 写 `localStorage['turaidc-locale:<app>']`——
那是第二套机制。配置层能覆盖的，就不要下沉到用例里重复一遍。
（两者还不同源：配置层钉的是 `navigator.language`，走「探测」分支；spec 里写 storage 走的是「显式选择」分支。）

注意 `devices['Desktop Chrome']` 不含 `locale`，所以项目级 `use` 不会盖掉顶层的这个设置。

### 3.8 Vite 新增依赖后 dev server 会一直 504

给前端引入了新的依赖（本例是把语言基建抽到 `shared/locale`）之后，dev server 会持续返回
`504 (Outdated Optimize Dep)`，页面只剩 `#app` 空壳。原因是依赖预构建缓存的 hash 变了，
但浏览器仍在请求旧 hash 的模块。

处理：删掉 `node_modules/.vite` 再启动 dev server（不是重启就行，必须删缓存）。
注意 e2e 的 `webServer` 只等端口就绪、不等依赖优化完成，所以**第一次跑必红、第二次跑会绿**，
容易误判成 flaky——见到这种「一跑就红、重跑就好」的，先怀疑 504，别急着改代码。

### 3.9 `git grep` 看不到未跟踪文件，重排后的核查会静默漏检

批量搬迁、改名或新增文件之后，用 `git grep`／`git status`／`git diff` 做全仓核查会**漏掉全部新
文件**——它们此时还是未跟踪状态。文档站重排时踩过一次：`git grep 'docs/references'` 在 90 篇
文档里报 0 命中，而正文实际有 53 处旧路径。

对策：核查走文件系统级全文搜索（编辑器搜索或 `rg`），或者先 `git add -A` 把它变成已跟踪再搜。
判定「还有没有残留引用」时，后者更接近提交后的真实状态。

### 3.10 语言包里一个裸 `@` 能让整个应用白屏，而且构建/类型检查全绿

vue-i18n 的消息语法里 `@` 是 **linked message** 前缀（`@:key`、`@.lower:key`）。
把 `@` 当普通字符写进译文，编译阶段就抛：

```text
SyntaxError: Message compilation error: Invalid linked format
1  |  完整邮箱地址，例如 no-reply@example.com
   |                     ^
```

报错发生在组件渲染时（`$t()` 首次编译该消息），**整个组件挂载失败、页面全白**，
而 `vue-tsc`、`eslint`、`vite build` 全部通过——它们都只把 JSON 当数据，不编译消息。

实测后果：管理端插件配置页（SMTP 账号占位符里写了 `no-reply@example.com`）整页空白，
`#app` 的 `innerHTML` 长度为 0，只有 `pageerror` 里能看到真因。

**对策**：消息里的字面量 `@` 必须写成 `{'@'}`（本项目既有键 `plugins.smtp` 的
「字母、数字、下划线、点、横线或 `{'@'}`」就是这么写的）。同理要注意 `|`（复数分支）
与未配对的 `{`/`}`。

**怎么查**：

```js
// 扫所有语言 JSON，找出仍然裸用 @ 的值
for (const [k, v] of flatten(JSON.parse(readFileSync(file, 'utf8')))) {
  if (/@(?![.:])/.test(v.replace(/\{'@'\}/g, ''))) console.log(k, v);
}
```

**怎么发现**：e2e 失败截图是一片纯白时，别急着看断言，先挂 `pageerror` 监听把堆栈打出来。

### 3.11 PowerShell 的 `Get-Content -Raw` + `Set-Content` 会把 UTF-8 源码写成乱码

想「批量把某个字符串换成另一个」时，下面这段在 Windows PowerShell 5.1 上会把**整个文件的中文
全部变成 `浜戞湇鍔″櫒` 这种乱码**：

```powershell
$c = Get-Content $p -Raw          # 5.1 默认按 ANSI 解码，UTF-8 中文立刻变成乱码字符
$c = $c.Replace('旧', '新')
Set-Content -Path $p -Value $c -Encoding UTF8
```

一次就改坏了 1090 行内容（`git diff` 显示 1090 insertions / 1091 deletions），
而且**测试仍然能跑**（只是中文断言全挂），很容易被误当成业务回归。

**对策**：

1. 逐处用编辑工具（`edit` 工具 / 编辑器替换）改，不要在 PowerShell 里做整文件读写；
2. 确实要脚本来做，用 Node 以 buffer 读写：`execFileSync('git', ['show', 'HEAD:' + p])` 取原始字节、
   `fs.writeFileSync(p, buf)` 写回；
3. 已经写坏了：`git checkout -- <file>` 会被安全网拦住，用 `git show HEAD:<path>` 取字节写回，
   再逐处重新应用改动（**注意会同时丢掉这段时间里别人对该文件的改动**，重放前先看清楚要补哪些）。

### 3.12 别直接 `new finfo()`：别人的机器没装 ext-fileinfo，上传接口就是 500

`StoreMediaFileRequest` 曾在校验的 `after()` 里直接 `new finfo(FILEINFO_MIME_TYPE)` 做二次类型校验。
`ext-fileinfo` 并不是 PHP 必装扩展，缺它时 `new finfo()` 抛的是 **`Error` 而不是 `Exception`**，
框架不会把它翻成 422，直接兜成 500「服务器内部错误」+ 请求编号——客户完全看不出发生了什么。
实测表现：上传 20ms 就 500，且文件已经落到 `php` 的临时目录里。

**对策**：用 `class_exists(finfo::class)` 判断后再用；缺扩展时跳过这层二次校验即可——
Laravel 的 `mimetypes` 规则本身走 Symfony 的 MimeTypeGuesser，fileinfo 缺失时会退化为扩展名推断，
仍然挡得住明显不对的类型。

同一类「基础设施问题伪装成 500」的地方还有：磁盘不足以前抛 `RuntimeException`、
不支持的媒体类型以前抛 `InvalidArgumentException`。这些都应该抛 `BusinessException`，
让前端拿到 422 + 一句能照着做的中文，而不是一个请求编号。

**排查手法**：拿到客户报的请求编号时，先看是不是 500 且耗时极短（几十毫秒）——
那通常是「校验/入口阶段就抛了」，而不是业务逻辑跑挂，优先怀疑扩展缺失、目录权限、磁盘空间。

### 3.13 「参数验证失败」这种兜底文案，会把 422 的真实原因吃掉

`bootstrap/app.php` 把所有 `ValidationException` 统一成
`{code: 42200, message: '参数验证失败', data: {errors: {...}}}`，真实原因在 `data.errors` 里。
管理端的 `errorMessage()` 取到字段错误后还要过一道「必须是中文」的过滤——本意是不让英文堆栈漏到界面上。
可部署方的 `APP_LOCALE` 若是 `en`，Laravel 的字段文案就是
`The third product group id field is required.`，被这道过滤挡掉，管理员端只剩「参数验证失败」。

**对策**：字段级校验错误来自校验器，是 name → [message] 的结构化数据，不会夹带堆栈，
所以只对「顶层 message」保留中文过滤，对 `errors` 映射**不要求语言**（见 `firstNonEmptyMessage`）。
真正该做的是把关键字段的文案写在 FormRequest 的 `messages()` 里，四语言环境都不会缺。

### 3.14 上游开通失败不能当「可重试错误」处理，否则一次购买会在上游开出 4 笔订单

支付后履约是「同步跑一次 + `provision` 队列 `$tries=3`」。只要失败被抛成普通异常，队列就会
把同一次购买再走一遍「清空购物车 → 加购 → 结算」，上游于是多出 3 笔一模一样的订单。
对接魔方财务的客户看到的是「一个新订单，没成功自动发了 4 笔相同订单」。

真正的坑在于**结算成功之后**：`/cart/settle` 一旦返回成功，上游订单就已经存在了，
此刻只要本地解析不出账单号、或请求在返回途中断掉，`provision_data` 里就不会有
`upstream_invoice_id`，下一轮重试的闸门（`assertNoUnresolvedUpstreamProvisionInvoice`）
读到的仍是 0，于是放行——重复下单就是这么来的。

**现在的三道防线**（`ProvisionService` + `ZjmfProvisionService`）：

1. 结算成功后按主机名回查上游实例，找到就直接复用，绝不重新下单；
   找不到或查不动，就落 `upstream_provision_uncertain_at` 标记并抛
   `ProvisionRetryNotAllowedException`，**停止**队列重试，转人工核实。
2. 闸门只认两类终止态：`upstream_invoice_id > 0` 却没有实例标识、或存在未确认标记。
   两者都要求管理员先在上游核实，确认没有订单后由「一键上游开通」勾选强制重开。
3. `ensureLocalService` 按订单加锁并按 `order_id + product_id` 复查，避免并发履约
   给同一个订单建出两条服务（截图里同一台上游实例挂着两行服务就是这么来的）。

**对策**：任何「会产生上游订单」的失败路径都必须落一个可幂等的检查点（账单号、实例号或
主机名），并用不可重试的异常类型终止自动重试。切不要把这类失败混进通用异常让队列重试。

## 四、提交
### 4.1 必须 `--no-verify`

根 `package.json` 的 lint-staged 有 `*.{css,scss,json,md}` → `prettier --write`，
会把 `backend/public/media/console-panel/bootstrap.min.css` 这类 minified 单行资产拆成上万行，
产生几万行无意义 diff。用 `git commit --no-verify` 提交。

### 4.2 提交信息别带 BOM

`Out-File -Encoding utf8` 在 Windows PowerShell 5.1 会写入 UTF-8 BOM（`git commit` 输出里能看到
`﻿Fix:`）。Git 会自动剥离，功能不受影响，但标题会显示杂字符。用无 BOM 写法：

```powershell
[System.IO.File]::WriteAllText($p, $msg, (New-Object System.Text.UTF8Encoding($false)))
git commit --no-verify -F $p
```

### 4.3 先 rebase 再 push

远程可能已有他人提交，直接 push 会被拒。先 `git pull --rebase origin main`，
冲突时手工处理——**不要**用 `git checkout origin/main -- <file>` 去「取单个文件」，
那会让本地 HEAD 长期落后于远程，见部署文档里的说明。