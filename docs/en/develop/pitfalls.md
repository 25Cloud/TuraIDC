---
source: develop/pitfalls.md
sourceHash: 0ee78d8480b082b9c177898e01a9ea9c6036aac2f7e9e277cdf00b88cc8301da
translatedAt: 2026-10-26
---

# Engineering Pitfalls Log

Records the pitfalls actually hit in real tasks that are easy to repeat, in the form symptom → root cause → countermeasure. For deployment-side problems see the [deployment and scheduling guide](../guide/deploy/scheduling.md#35-增量更新的实测坑).

## 1. Domain Modeling

### 1.1 Do not infer entity hierarchy from field names

**Symptom**: While modeling product tiers, seeing `product_type` / `ProductType` and taking it as the top level of the product category tree yields a four-layer model of "product type = first layer, first-level group = second layer…", and the glossary is written the same way.

**Root cause**: The `product_types` table **does not exist at all**. `ProductType` is merely a constant enum in `App\Constants\ProductType` (`cloud_server`, `web_hosting` …); it hangs on the group as the **field** `first_product_groups.product_type`, so it is an attribute, not a hierarchy level. The real structure has three levels only: `first/second/third_product_groups`.

**Countermeasure**: Before modeling, look at the real data with one read-only SQL statement (`SHOW TABLES LIKE '%product%'` plus a few rows from each table); do not infer hierarchy from field names, class names, or comments. The cost of wrong modeling is a full glossary rewrite, and wrong conclusions get carried into dozens of files.

### 1.2 Code comments can be one-sided or stale; the UI and the live database prevail

The class comment of `SiteProductGroupQuery` says "first-level menu = first_product_groups.code", which is not wrong in itself; but the admin side calls the top bar of that same entity the "first-level menu" and calls the root of the category tree the "first-level category", and both are the same table. Reading only the comment leads to the conclusion that "first-level menu refers to two entities".

**Countermeasure**: Comments are for understanding intent, **not for deciding facts**. When terms conflict, put the admin UI screenshot or actual rendering next to the live database data — the real presentation outranks any comment on either side.

## 2. Bulk Text Replacement

Terminology unification naturally invites scripted bulk replacement, but this project has been bitten three times. **Conclusion: change each place with an editing tool; use bulk only to "preview the blast radius first".**

### 2.1 Nested arrays in a PowerShell hashtable get flattened

```powershell
$map = @{ 'a.vue' = @( @('旧文案','新文案') ) }   # 期望 $r 是数组
foreach ($r in $map['a.vue']) { $c.Replace($r[0], $r[1]) }
# 实际 $r 是字符串 → $r[0]/$r[1] 取到的是「第一个/第二个字符」
```

Real consequence: `Replace("按","产")` and `Replace("用","户")` turned `按旧端` into `产旧端` and `复用 syncSelectionRoute` into `户户 syncSelectionRoute`.

**Countermeasure**: Do not put arrays of replacement pairs inside a hashtable. For bulk work use a flat array `$pairs = @(@('a','b'), @('c','d'))`, or simply edit each place.

### 2.2 Scan all extensions in one pass

Scanning `.vue/.ts/.js` but missing `.less` left one old term in `layout.less`, found only by grepping the build output after deployment.

**Countermeasure**: `$ext = @('*.vue','*.ts','*.js','*.less','*.scss')` in one list; the `.md` files under `docs/` usually have to be scanned as well.

### 2.3 Partial matches produce semantically broken sentences

The original text `下方为二级 / 三级分类` with the replacement pair `三级分类` → `三级分组` becomes `下方为二级 / 三级分组` — the counterpart that belonged after the second-level label is gone, the sentence reads broken, yet grep reports nothing.

**Countermeasure**: After replacing, you must run **both a leftover check and a line-by-line diff review**:

```powershell
# 1) 残留检查
Select-String -Path <文件> -Pattern '旧术语'
# 2) 找出「不像预期」的改动行：所有改动都应含新术语，不含的就是意外
git diff -U0 | Where-Object { $_ -match '^[+-]' -and $_ -notmatch '^(---|\+\+\+)' -and $_ -notmatch '新术语' }
```

### 2.4 After a Chinese replacement, confirm the file encoding is intact

To decide whether a file was written badly, read it back explicitly as UTF-8 with `.NET` and check that the target term is present; do not judge by eye from the diff.

### 2.5 Moving across drives into the system temp directory hits EXDEV

When the repository is on `E:` and the system temp directory is on `C:`, `renameSync('E:\\TuraIDC\\docs\\x.md', '%TEMP%\\x.md')` fails outright: `EXDEV: cross-device link not permitted` — rename cannot cross volumes.

To "move a retired file out of the repository", use **copy then delete** (`copyFileSync` + `rmSync`). The temp directory suits newly created intermediate artifacts, not finished files that have to be relocated.

## 3. Verification and Testing

### 3.1 Confirm the database is reachable before running the full suite

When the database is unreachable, `php artisan test` does not report an error immediately: every test case stalls 4–20 seconds on a connection timeout, the whole suite runs for ten-plus minutes, and output buffering hides any progress.

**Countermeasure**: Test connectivity on its own first, then decide which subset to run. The databases referenced by the local `.env` and `phpunit.xml.dist` are usually both unreachable; then only test cases extending the bare `PHPUnit\Framework\TestCase` (no Laravel bootstrap, no database connection) can run, and every other failure is a `QueryException`/`PDOException` unrelated to the code change — report that explicitly as uncovered risk.

### 3.2 Decide whether an e2e failure is pre-existing: baseline with `git stash`

When an e2e run goes red, first separate "broken by my change" from "already red":

```bash
git stash push -m verify      # 回到 HEAD
pnpm run test:e2e -- <spec>   # 基线上跑同一个用例
git stash pop                 # 恢复改动
```

Identical failure text on the baseline → pre-existing problem; baseline passes → introduced by this change.

### 3.3 Mojibake in Playwright report files is not trustworthy

On a Chinese-language Windows environment, the `test-results/**/error-context.md` files Playwright writes show mojibake such as `鍟嗗搧鍒嗙被` (UTF-8 decoded as GBK). **That is an encoding problem in the report file; it does not mean the test code contains mojibake.** Judge the real failure from the terminal output (the part filtered by `Select-String`), or read the source file back as UTF-8 to verify.

### 3.4 The webServer in `playwright.config.ts` cannot use a Unix environment-variable prefix

`'VITE_API_BASE_URL=... pnpm exec vite ...'` is Unix shell syntax that Windows cmd does not accept, so the webServer fails to start and the whole e2e suite cannot run on Windows. The correct form is Playwright's `env` field (`frontend-admin-v3/playwright.config.ts` is the working reference).

Also, `tablet` in `projects` (`devices['iPad (gen 7)']`) uses WebKit, and with the browser not installed **only the tablet project fails wholesale**, while desktop/mobile pass. `pnpm exec playwright install webkit` installs it.

### 3.5 A stray import in an SFC crashes the whole component at runtime

During the v0.4.1 internationalization work, a bulk script inserted `import { t } from '@/locales'` on **line 1 of the file**, that is, **outside** the `<template>` tag. Vue's SFC parser ignores it outright, so:

- Bare `t('...')` calls in the template and in the script throw `ReferenceError: t is not defined` during setup
- The error fires in `setup()`, the whole component fails to mount, **the page goes fully blank and the console holds only one hard-to-locate warning**
- `pnpm run build`, type checking, and `vue-tsc` **all pass** — none of them validates that no top-level statements appear outside the script block

The victims at the time were the website layout `src/layout/WebsiteLayout.vue` (the whole site), plus `src/views/website/Home/components/HomeHeroCarousel.vue` and `HomeProductTabs.vue` (the home page).

**How it was found**: the `playwright test` failure screenshots were pure white (`APP_LEN=0`); a small Playwright probe script then attached a `pageerror` listener and printed the full stack, which pointed straight at `setup (...)`.

**How to prevent it**:

```powershell
# 扫所有 .vue，找出「第一行就是 import/export」的文件
Get-ChildItem -Recurse -Include *.vue -Path <app>\src | ForEach-Object {
  $first = Get-Content $_.FullName -Encoding UTF8 -TotalCount 1
  if ($first -match '^\s*(import|export)\s') { "$($_.Name) :: $first" }
}
```

This class of error **surfaces only through runtime verification**; no static check or build reports it. After any UI change, run the e2e suite or actually look at the page.

### 3.6 `preg_match_all` returns a count, so `=== 1` silently disables the guard

`I18nLocaleConsistencyTest::test_templates_have_no_hardcoded_chinese` contains two regex scans, one of which was written as:

```php
if (preg_match_all('/>([^<>{}]*中文[^<>{}]*)</u', $tpl, $m) === 1) {
```

`preg_match_all` returns the **number of matches**, not a boolean. With dozens of hardcoded Chinese strings on a real page it returns dozens, so `=== 1` is always false — **the "no hardcoded Chinese in the template layer" guard never executed from the day it was written**.

The same snippet had a companion problem: on a hit it skipped text containing newlines via `str_contains($text, "\n") => continue`, intending to exclude multi-line content blocks, but text nodes in Vue templates **almost always carry indentation newlines**, so real hits were waived through as well. The correct approach is to **split by line and judge line by line**.

**Lesson**: when writing an automated guard, first build an input that **must fail** and verify that it really goes red. "Tests always green" is not a virtue here — it means the test is not working at all. Use `> 0` rather than `=== 1` as the condition.

#### 3.6.1 Fixing this guard hit three more pitfalls and the count kept changing

After fixing those two spots, the same guard exposed three new problems, and the hit count moved `0 → 171 → 12 → 1005 → 482`:

| Approach                                                         | Result          | Problem                                                                                                                                                                                                                              |
| ---------------------------------------------------------------- | --------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| Take only the **first** `<template>` block of each `.vue`        | 171             | Everything from the second block onward in a 3000+ line SFC is blind                                                                                                                                                                 |
| **Collect every block but return only the content between tags** | 12              | The most deceptive error: the plain-text criterion is `>文本<`, and after the outer tags are stripped `<template #title>等级列表</template>` is left with only its text content, with no `>` or `<` around it, so it can never match |
| "Record a block at every closing tag"                            | 1005 (inflated) | Nested blocks are counted twice, inner and outer                                                                                                                                                                                     |
| **Pop the root `<template>` block with a stack (tags included)** | 482             | Correct count                                                                                                                                                                                                                        |

Key points:

1. **An SFC has exactly one root `<template>` block**, and the nested `<template #slot>` blocks all live inside it. Popping the root block with a stack (from the first `<template>` to the `</template>` that returns the depth to zero) covers all nested content without double counting. Linear pairing of "open tag → the first close tag after it" truncates early at an **inner** close.
2. **The returned fragment must include the tags**. The regex locates text nodes via `>文本<`, and after the outer tags are stripped a single-line paragraph has nothing to match against.
3. **Cross-check every new criterion against an independent implementation**. This round was only baselined after an equivalent JS scanner independently computed the same 482.

#### 3.6.2 With a large backlog, use a debt ratchet — neither clear it to zero nor wave it through

Clearing 482 occurrences one by one is not a single-sitting volume of work, and leaving the guard on a wrong criterion is the same as having no guard. The landing pattern is to freeze the backlog as app → file → occurrence count (`TEMPLATE_CHINESE_BASELINE`):

- Chinese appears in a file outside the list → red
- Occurrence count in a listed file **exceeds** the registered value → red (the message carries the `89 → 90` delta)
- Count goes down → green; lower the baseline number along the way, so the progress shows in the diff

After writing it you **must** verify that it goes red: green at baseline → lower one file's baseline by 1 → red with an exact message → green again after restoring. The actual output this round: `frontend-admin-v3 有 1 个文件的硬编码中文超出基线：pages/products/edit.vue（89 → 90）`.

### 3.7 Once the app adapts to the browser language, e2e must pin the locale in the config

Initial locale on first paint in all three frontends goes through `resolveInitialLocale` in `shared/locale` (explicit choice > browser preference > default zh_CN). Playwright's default context follows the host environment, which is `en-US` locally and in CI, so **pages render in English while every spec asserts on Chinese** — a whole batch of red runs that "look like regressions but are environment differences".

Measured scale: one console run gave **13 failed / 7 passed**, all from this cause.

**Correct approach**: pin it once in the top-level `use` of each app's `playwright.config.ts`, which covers every spec.

```ts
use: {
  locale: 'zh-CN',   // 应用会按浏览器偏好自适应，不钉的话断言里的中文匹配不上
  baseURL: 'http://127.0.0.1:5176',
},
```

After pinning, the console run gave **20 passed / 0 failed**.

**Do not** write `localStorage['turaidc-locale:<app>']` from `page.addInitScript` in every spec — that is a second mechanism. Anything the config layer can cover should not be pushed down into test cases a second time. (The two do not even share a source: the config layer pins `navigator.language` and takes the detection branch, while writing storage in a spec takes the explicit-choice branch.)

Note that `devices['Desktop Chrome']` does not include `locale`, so a project-level `use` will not override this top-level setting.

### 3.8 After a new Vite dependency the dev server keeps returning 504

After a new frontend dependency is introduced (in this case, extracting the locale infrastructure into `shared/locale`), the dev server keeps returning `504 (Outdated Optimize Dep)` and the page is only an empty `#app` shell. The cause is that the dependency pre-bundling cache hash changed, while the browser still requests modules under the old hash.

Fix: delete `node_modules/.vite` and start the dev server again (a restart alone is not enough; the cache must go). Note that the e2e `webServer` only waits for the port, not for dependency optimization to finish, so **the first run always fails and the second passes**, which is easily misread as flakiness — when a run fails first and passes on retry, suspect 504 before changing code.

### 3.9 `git grep` cannot see untracked files, so post-reorganization checks silently miss them

After bulk relocations, renames, or new files, a repository-wide check with `git grep` / `git status` / `git diff` **misses every new file** — they are still untracked at that point. This happened once during a docs-site reorganization: `git grep 'docs/references'` reported 0 hits across 90 documents, while the body actually contained 53 old paths.

Countermeasure: run the check with a filesystem-level full-text search (editor search or `rg`), or `git add -A` first so the files become tracked before searching. For deciding whether leftover references remain, the latter is closer to the real post-commit state.

## 4. Committing

### 4.1 `--no-verify` is mandatory

The lint-staged config in the root `package.json` maps `*.{css,scss,json,md}` → `prettier --write`, which explodes single-line minified assets such as `backend/public/media/console-panel/bootstrap.min.css` into tens of thousands of lines and produces a meaningless tens-of-thousands-line diff. Commit with `git commit --no-verify`.

### 4.2 Keep BOMs out of commit messages

`Out-File -Encoding utf8` writes a UTF-8 BOM under Windows PowerShell 5.1 (visible as `﻿Fix:` in `git commit` output). Git strips it automatically and functionality is unaffected, but the subject line shows a stray character. Use the BOM-free form:

```powershell
[System.IO.File]::WriteAllText($p, $msg, (New-Object System.Text.UTF8Encoding($false)))
git commit --no-verify -F $p
```

### 4.3 Rebase before pushing

The remote may already have commits from others, and a direct push is rejected. Run `git pull --rebase origin main` first and resolve conflicts by hand — **do not** use `git checkout origin/main -- <file>` to "grab a single file", because that leaves the local HEAD permanently behind the remote; see the note in the deployment documentation.
