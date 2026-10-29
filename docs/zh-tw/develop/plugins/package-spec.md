---
source: develop/plugins/package-spec.md
sourceHash: dcd124dd8b3511781a65ef4366afb088d49efadfefa874cb93f9b48db6b9f630
generated: zh-tw
---

<!-- 本檔案由 docs-i18n.mjs trad zh-tw 從 docs/develop/plugins/package-spec.md 產生，請勿手動編輯。 -->
# 外掛包規範（獨立倉庫分發與外掛市場）

> 補充文件：執行期契約（config.php / 入口 execute / 配置 schema / 定時任務 / 安全邊界）詳見
> [plugins/README.md](index.md)。本文聚焦「第三方外掛如何獨立開發、打包、分發」，併為外掛市場打底。

## 1. 背景

主倉是 AGPL 開源專案，外掛長期與主倉耦合在 `backend/plugins/` 下：第三方開發者只能 fork 主倉、加外掛、PR 合入，迭代與維護成本隨外掛數量上升而不可持續。

目標形態是**外掛市場**：開發者按統一格式建立獨立外掛倉庫，使用者通過管理端安裝/升級。分三步走：

| 階段            | 內容                                                                         | 狀態   |
| --------------- | ---------------------------------------------------------------------------- | ------ |
| M1 模板與腳手架 | `php artisan plugin:make` 生成骨架 + 獨立模板倉庫 + 本文件                   | 已落地 |
| M2 外掛市場     | GitHub 索引 + tag/sha 稽核鎖定下載 + `plugin:market:install` + 手動 zip 載入 | 已落地 |
| M3 市場頁與簽名 | 管理端市場頁一鍵安裝/升級、包簽名校驗                                        | 規劃中 |

無論哪一步，**執行期契約不變**：外掛仍是 `backend/plugins/{domain-dir}/{slug}/` 下的一組檔案，`config.php` 宣告 manifest，入口類提供 `execute()`。變化的只是「程式碼如何進入伺服器」這一層。

## 2. 快速開始：腳手架

主倉提供骨架生成命令：

```bash
cd backend
php artisan plugin:make sms my_sms --name="我的短信" --provider --task
```

引數：

| 引數/選項    | 說明                                                                                    |
| ------------ | --------------------------------------------------------------------------------------- |
| `domain`     | 外掛域：`payment` / `verification` / `captcha` / `mail` / `sms` / `upstream` / `addons` |
| `slug`       | 外掛標識，snake_case（如 `my_sms`），與目錄名一致                                       |
| `--name`     | 顯示名，預設取 slug 的可讀形式                                                          |
| `--provider` | 生成 `src/Providers/{Studly}ServiceProvider.php` 並在 manifest 宣告                     |
| `--task`     | 生成心跳定時任務並在 manifest 宣告                                                      |
| `--force`    | 目錄已存在時覆蓋                                                                        |

生成結果：

```text
backend/plugins/sms/my_sms/
├── config.php            # manifest（info + config schema）
├── MySmsPlugin.php       # 统一执行入口（execute）
├── lib/MySmsScheduledTask.php   # 仅 --task
├── src/Providers/MySmsServiceProvider.php  # 仅 --provider
└── README.md
```

生成的骨架立即可用：`php -l` 通過、pint 通過、可被 `PluginInstaller` 安裝啟用。

## 3. 獨立外掛倉庫規範

第三方外掛以**獨立 Git 倉庫**分發，倉庫佈局與生成骨架一致，額外約定：

```text
my-sms-plugin/
├── config.php
├── MySmsPlugin.php
├── lib/                 # 业务逻辑（运行时自动加载）
├── logic/               # 较重业务逻辑（可选）
├── logic/autoload.php   # 类多时的 PSR-4 自注册（可选，见下）
├── src/                 # 大插件的 PSR-4 代码根（可选）
├── src/Providers/       # ServiceProvider（可选）
├── tests/               # 插件自测（可选，建议）
├── README.md            # 插件说明、配置、动作表、许可
├── LICENSE              # 插件自己的许可文件
└── plugin.json          # （可选）与 config.php 等价的 JSON manifest，二选一
```

約定：

- **名稱空間**固定 `TuraIDC\Plugins\{StudlyDomain}\{StudlySlug}`，目錄名 = slug（snake_case）。
- **slug 全域性唯一**：在外掛市場註冊前先檢索是否已被佔用；與主倉內建外掛衝突的 slug 不允許安裝。
- **不提交** `vendor/` 與執行時產物；自帶依賴（如 SDK）隨包分發時提供 `composer.json` 或鎖檔案。
- **版本號**遵循語義化版本 `MAJOR.MINOR.PATCH`，manifest `info.version` 與 Git tag 保持一致。
- **許可**：外掛獨立授權。隨主倉分發的內建外掛遵循主倉協議；獨立倉庫外掛可自選許可，但不得使用主倉程式碼的派生實現而不履行主倉協議義務（見第 7 節）。
- **類多就走 PSR-4**：核心 `PluginFileLoader` 是按目錄順序 `require` 的，幾十個類、且有被多處
  `extends`/`implements` 的介面時，子類先於父類被載入會直接 fatal。此時把類放進 `src/` 並加
  `logic/autoload.php` 只為本外掛名稱空間註冊 `spl_autoload_register`（完整寫法見
  [開發指南](index.md) 第 2 節，`ai_assistant` 是現成例子）。
- **週期能力只能走清單**：`info.extra.scheduled_tasks` / `schedule_hooks`，不要在外掛裡註冊
  Laravel `Schedule`；同樣**不要指望主倉 `routes/console.php` 去 schedule 你這個外掛的命令**——
  外掛解除安裝後那條 Schedule 會留下每天一次「命令不存在」的失敗記錄。

## 4. 外掛包格式

市場分發採用 zip 安裝包（GitHub archive 與手動 zip 均為此形態），佈局：

```text
{slug}-{version}.zip
└── {slug}/                # 顶层即插件目录，目录名 = slug
    ├── config.php         # 或 plugin.json
    ├── {Studly}Plugin.php
    ├── lib/
    ├── logic/
    ├── src/               # 可选
    └── README.md
```

安裝時校驗順序（與 `PluginInstaller` 現有校驗一致）：

1. 解壓路徑穿越防護：所有檔案必須落在 `{slug}/` 字首內。
2. manifest 必填欄位：`domain` / `slug` / `key` / `name` / `version` / `entry`，且 `slug` 與目錄名一致、`domain` 合法。
3. 入口類存在且有 `execute()`，所有宣告的類檔案必須位於外掛目錄內。
4. `scheduled_tasks` / `schedule_hooks` 宣告逐一例項化校驗契約。
5. 目標 slug 未與內建外掛及已安裝外掛衝突。

**簽名預留**：M3 階段對安裝包做簽名校驗（釋出者金鑰對 + 包清單雜湊），校驗失敗拒絕安裝。外掛清單的 `manifest_hash` 篡改檢測已存在（`PluginScanner::assertManifestHash`），簽名在此基礎上補齊來源可信。

## 5. 外掛市場：索引與分發（M2 已落地）

市場**不建任何自建邏輯**，索引與分發全部依賴 GitHub，配合國內加速映象使用。

### 5.1 索引倉庫

官方索引倉庫 [25Cloud/turaidc-plugin-index](https://github.com/25Cloud/turaidc-plugin-index) 維護一個 `plugins.json`：

```json
{
  "schema": 1,
  "updated_at": "2026-08-29T00:00:00+08:00",
  "plugins": [
    {
      "slug": "my_sms",
      "domain": "sms",
      "name": "我的短信",
      "description": "对接 xx 短信服务商",
      "developer": "25Cloud",
      "repo": "25Cloud/my-sms-plugin",
      "tag": "v1.2.0",
      "sha": "67cd977fe6a714fe3f78f9a9f58785675ea0492c",
      "released_at": "2026-08-29",
      "license": "MIT",
      "homepage": "https://github.com/25Cloud/my-sms-plugin"
    }
  ]
}
```

條目欄位：`slug`（全域性唯一）、`domain`（合法外掛域）、`name`、`description`、`developer`、`repo`（`owner/repo`）、`tag`（Git tag，如 `v1.2.0`）、`sha`（該版本鎖定 commit，可選）、`released_at`、`license`、`homepage`。

### 5.2 開發者釋出流程（PR 稽核）

1. 用模板倉庫（或 `plugin:make`）建立外掛獨立倉庫，開發、自測、完成 `README.md` 與 `LICENSE`。
2. 發版本：打 `vMAJOR.MINOR.PATCH` tag 併發布 GitHub Release（tag 與 `config.php` 的 `info.version` 一致）。
3. 向 [25Cloud/turaidc-plugin-index](https://github.com/25Cloud/turaidc-plugin-index) 提 PR，在 `plugins.json` 追加條目，附上 tag（建議同時附該 tag 的 commit sha）。
4. 平臺稽核 PR：核對包內容、許可、安全邊界後合入，外掛即上架。

**稽核鎖定原理**：安裝器只下載索引條目鎖定的版本——

- `sha` 模式：下載 `https://github.com/{repo}/archive/{sha}.zip`，解壓後強校驗頂層目錄名必須為 `{repo-basename}-{sha}`（GitHub archive 命名規則），確保下載的就是稽核過的 commit，**稽核後修改倉庫內容無法影響已上架版本**；
- `tag` 模式：下載 `{repo}/archive/refs/tags/{tag}.zip`。若 tag 被 force-push 指向新 commit，不會自動升級到新內容（重灌才更新）；新版本必須走新的 PR 重新稽核。

### 5.3 主倉安裝命令

```bash
cd backend
php artisan plugin:market:list              # 同步索引并列出可安装插件（缓存 5 分钟）
php artisan plugin:market:list --force      # 强制重新拉取索引
php artisan plugin:market:install my_sms    # 按索引条目下载审核锁定版本并安装
php artisan plugin:market:install my_sms --zip=./my_sms-v1.2.0.zip  # 手动加载本地插件包
php artisan plugin:market:install my_sms --force  # 目标目录已存在时覆盖
```

`--zip` 手動載入的 slug 取包內 manifest 並與引數比對；解壓有路徑穿越防護與 100MB 體積上限。

### 5.4 國內訪問

索引與程式碼下載地址均可加加速映象字首，見 `config/plugins.php` 的 `plugins.market`（`PLUGIN_MARKET_RAW_MIRROR` / `PLUGIN_MARKET_DOWNLOAD_MIRROR`，預設 `https://ghfast.top/`，留空則直連）。

## 6. 腳手架與模板的關聯

- `php artisan plugin:make` 生成的就是「可釋出為獨立倉庫」的最小骨架：開發完成後刪除 `--provider`/`--task` 之外的空殼，補齊業務邏輯即可推送。
- **獨立模板倉庫**：<https://github.com/25Cloud/turaidc-plugin-template>（已啟用 Template 屬性）。開發者點 **Use this template** 一鍵複製，再執行 `php scripts/rename.php <slug> [<显示名>]` 全域性替換（類名/名稱空間/動作），含 CI（pint / php -l / manifest 校驗）與 MIT 許可模板。
- 模板與 `plugin:make` 二選一即可，產物契約完全一致：模板用 addons/my_plugin 作佔位，命令按實際 domain/slug 直接生成。
- **模板本身有迴歸**：`backend/stubs/plugin/*.stub` 是主倉這一側的模板產物，
  `tests/Feature/PluginMakeCommandTest.php` 會生成一份骨架並校驗「檔案齊全 / 清單能被 require /
  `--task` 真的寫進 `extra.scheduled_tasks` / README 不留 `{{...}}` 佔位符」。改模板必須讓這條
  用例保持綠，獨立模板倉庫同步時以它的產物為對照。
- 模板生成的 `README.md` 自帶三塊約定：配置在清單裡（管理端按 schema 渲染表單，不用自己寫頁面）、
  週期任務走 `extra.scheduled_tasks`、以及「不要做的事」（不註冊系統級路由/排程/中介軟體、
  不建錶帶遷移、不讓核心無條件引用外掛）。新增段落時別把它們刪掉——它們正是踩過的坑。

## 7. 協議與安全邊界

- **AGPL 邊界**：主倉 AGPL-3.0。外掛若獨立倉庫分發、獨立授權，必須避免直接複製主倉程式碼；通過 manifest / `execute()` 契約介面整合（介面本身是主倉公開的整合點）。外掛是否被認定為與主倉構成聯合作品、從而受 AGPL 約束，屬於法律判斷；第三方外掛應自行評估並取得必要授權。官方外掛市場可提供「隨主倉同授權」與「獨立授權」兩種檔位明示。
- **安全邊界**沿用 [plugins/README.md](index.md) 第 9 節：外掛不能自行註冊管理端選單、許可權、遷移和任意路由；回撥、賬務、開通等仍由平臺服務層控制；外掛程式碼在伺服器執行，安裝即視為信任——市場通過 PR 人工稽核（M2 已落地）與後續的包簽名（M3）設定門檻。

## 8. 驗證命令

```bash
# 生成骨架并检查
cd backend
php artisan plugin:make sms demo_check --force
php -l plugins/sms/demo_check/DemoCheckPlugin.php
php vendor/bin/pint --test plugins/sms/demo_check

# 模板契约（改过 stubs/plugin/*.stub 必跑）
php vendor/bin/phpunit tests/Feature/PluginMakeCommandTest.php

# 插件自己的用例
php vendor/bin/phpunit plugins/addons/ai_assistant/tests

# 插件市场
php artisan plugin:market:list --force
php artisan plugin:market:install <slug>
php artisan plugin:market:install <slug> --zip=<本地插件包.zip>
```
