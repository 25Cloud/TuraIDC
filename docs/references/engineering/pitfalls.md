# 工程踩坑记录

按「症状 → 根因 → 对策」记录在真实任务中踩过、且容易重犯的坑。
部署侧的问题见[部署与调度指南](../operations/deployment-and-scheduling.md#35-增量更新的实测坑)。

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