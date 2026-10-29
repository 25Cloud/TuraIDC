---
source: develop/plugins/index.md
sourceHash: 04ce3a49a438d7cd0d82b60faa48ba2d608d65d6fba3f36053c2272707ddac10
generated: zh-tw
---

<!-- 本檔案由 docs-i18n.mjs trad zh-tw 從 docs/develop/plugins/index.md 產生，請勿手動編輯。 -->
# TuraIDC 外掛開發指南

本文件說明當前專案的外掛目錄規範、載入規則、配置規則和真實外掛包。外掛系統採用“ZJMF 財務風格目錄 + Laravel 受控載入”方案：外掛上傳到固定目錄後，由管理員在後臺掃描、安裝、配置、啟用。

## 1. 目錄對映

外掛統一放在 `backend/plugins` 下，目錄按能力域劃分：

| 能力域        | 管理端 domain  | 物理目錄                        | 平臺介面卡/契約                                    |
| ------------- | -------------- | ------------------------------- | -------------------------------------------------- |
| 支付渠道      | `payment`      | `backend/plugins/gateways`      | `PluginPaymentGateway` → `PaymentGatewayInterface` |
| 實名認證      | `verification` | `backend/plugins/certification` | `PluginVerificationDriver` → `VerificationDriver`  |
| 人機驗證      | `captcha`      | `backend/plugins/captcha`       | `GeeTestService` / 登入風控驗證碼呼叫鏈            |
| 郵件傳送      | `mail`         | `backend/plugins/mail`          | `PluginMailDriver` → `MailDriver`                  |
| 簡訊傳送      | `sms`          | `backend/plugins/sms`           | `PluginSmsDriver` → `SmsDriver`                    |
| 上游開通/控制 | `upstream`     | `backend/plugins/servers`       | `PluginUpstreamDriver` → `UpstreamDriver`          |
| 功能擴充套件      | `addons`       | `backend/plugins/addons`        | 受控 addon action、排程任務和 hook                 |

`domain` 是後臺 API 和資料庫裡使用的領域名；物理目錄沿用ZJMF 財務的 `gateways/certification/mail/sms/servers` 風格，並按當前外掛體系擴充套件了 `captcha/addons`。外掛入口類不直接實現平臺契約，入口類提供 `execute(array $request): array`，平臺通過 `PluginRuntimeRegistry` 和領域 adapter 轉換為內部契約。

## 2. 單外掛結構

推薦結構：

```text
backend/plugins/{domain-directory}/{slug}/
├── {Name}Plugin.php       # 入口类，必须存在
├── config.php             # 元信息和配置 schema，必须存在
├── lib/                   # SDK、协议封装、适配服务
├── logic/                 # 较重业务逻辑
├── controller/            # 回调适配类；不会自动注册 Laravel 路由
├── vendor/                # 插件自带依赖，可选
└── *.png                  # 后台图标，可选
```

當前專案不使用ZJMF 財務的 `.tpl` 模板機制，外掛 demo 和真實外掛都不需要 `template/` 目錄。管理頁面統一由 `frontend-admin-v3` 的外掛管理頁渲染。

載入順序由 `PluginFileLoader` 控制：

1. 遞迴載入 `lib/`、`logic/`、`controller/` 下 PHP 檔案。
2. 如果存在 `vendor/autoload.php`，載入外掛依賴。
3. 載入外掛根目錄 PHP 檔案，跳過 `config.php`。
4. 遞迴載入 `src/`：大外掛按 PSR-4 組織程式碼的首選位置（`AiAssistant`、`zjmf_bridge` 都用它）。

### 大外掛必須自註冊 PSR-4

上面前三步是「按目錄順序 `require` 每個檔案」。外掛只有幾個類時沒問題；一旦有幾十個類、
且其中存在被多處 `extends`/`implements` 的介面（`AiTool`、`AiProviderInterface` 這類），
`require` 順序一旦讓子類先於父類/接口出現，PHP 在**宣告階段**就直接 fatal
（`Interface "..." not found`），而且報錯位置和真實原因（順序）看起來毫無關係。

`AiAssistant` 的做法是加一個 `logic/autoload.php`，只給本外掛名稱空間註冊
`spl_autoload_register`，讓父類與介面按需載入：

```php
spl_autoload_register(static function (string $class): void {
    $prefix = 'TuraIDC\\Plugins\\Addons\\AiAssistant\\';
    if (! str_starts_with($class, $prefix)) {
        return;
    }
    $relativePath = str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
    foreach (['/src/', '/logic/'] as $segment) {
        $path = dirname(__DIR__).$segment.$relativePath;
        if (is_file($path)) {
            require_once $path;

            return;
        }
    }
});
```

只註冊自身字首、不接管其它名稱空間、不主動載入業務類——它只是讓 `require` 順序不再是正確性
前提。`logic/` 按字母序先於 `src/` 被載入，所以這個檔案本身一定先於任何業務類生效。

## 3. config.php 規範

`config.php` 同時宣告外掛元資訊和後臺配置表單：

```php
<?php

declare(strict_types=1);

use TuraIDC\Plugins\Example\ExamplePlugin;

return [
    'info' => [
        'domain' => 'sms',
        'slug' => 'example',
        'key' => 'example',
        'name' => '示例短信',
        'version' => '1.0.0',
        'entry' => ExamplePlugin::class,
        'capabilities' => ['verify_code'],
        'driver_binding' => [
            'binding_type' => 'notification',
            'binding_key' => 'verify_code',
        ],
    ],
    'config' => [
        'access_key' => ['title' => 'Access Key', 'type' => 'text', 'required' => true, 'secret' => true],
    ],
];
```

必填欄位：

- `info.domain`：必須是 `payment`、`verification`、`captcha`、`mail`、`sms`、`upstream`、`addons` 之一。
- `info.slug`：必須和外掛目錄名一致。
- `info.key`：業務註冊 key，同領域內唯一。
- `info.name`：後臺展示名。
- `info.entry`：入口類，必須存在，並提供 `execute(array $request): array`。

配置欄位支援：

| 欄位                | 說明                                                     |
| ------------------- | -------------------------------------------------------- |
| `title` / `label`   | 後臺展示標籤                                             |
| `type`              | `text`、`textarea`、`number`、`switch`、`select`、`json` |
| `required`          | 啟用前是否必須填寫                                       |
| `secret`            | 是否加密儲存且不明文回顯                                 |
| `value` / `default` | 預設值                                                   |
| `options`           | `select` 選項                                            |

## 4. 配置元件協議

外掛配置頁採用 schema 驅動渲染。外掛只能宣告受控元件型別，由管理端統一渲染、校驗、儲存；外掛不能注入任意 Vue、HTML、JavaScript、遠端元件或 iframe。

### 當前已支援元件

當前管理端已支援以下型別：

| 元件       | `type`         | 說明                             |
| ---------- | -------------- | -------------------------------- |
| 單行文本   | `text`         | API 標識、賬號、名稱等普通字串 |
| 多行文本   | `textarea`     | 備註、模板內容等長文本           |
| 數字輸入   | `number`       | 金額、埠、次數等數值           |
| 開關       | `switch`       | 是否啟用某項能力                 |
| 下拉單選   | `select`       | 環境、渠道、區域等列舉值         |
| 下拉多選   | `multi_select` | 支援能力、可用區域               |
| 單選按鈕組 | `radio`        | 少量互斥選項                     |
| 核取方塊組   | `checkbox`     | 多項開關配置                     |
| JSON 編輯  | `json`         | 高階配置、欄位對映、陣列配置     |
| 密碼/金鑰  | `password`     | Secret、Token、密碼              |
| URL 輸入   | `url`          | 回撥地址、介面地址               |
| 電子郵件輸入   | `email`        | 發件電子郵件、通知電子郵件               |
| 手機號輸入 | `phone`        | 測試手機號、聯絡人手機號         |
| 提示文本   | `notice`       | 配置說明、風險提示；不參與儲存   |
| 分割線     | `divider`      | 表單分組分隔；不參與儲存         |
| 只讀文本   | `readonly`     | 展示系統生成值；不參與儲存       |

金鑰欄位有兩種等價寫法：宣告 `type=password`，或在任意文本型別上宣告 `secret=true`。兩者都會按密碼框展示，後端加密儲存且不明文回顯。

條件顯示 `visible_when` 已實現，支援 `eq`、`neq`、`in`、`not_in` 四個運算子（見"分組和條件顯示"一節）；欄位級 `visible: false` 直接隱藏。

示例：

```php
'config' => [
        'app_id' => [
                'title' => 'API 标识',
                'type' => 'text',
                'required' => true,
        ],
        'app_secret' => [
                'title' => '接口密钥',
                'type' => 'text',
                'required' => true,
                'secret' => true,
        ],
        'charge_enabled' => [
                'title' => '插件收费',
                'type' => 'switch',
                'default' => false,
        ],
        'charge_amount' => [
                'title' => '收费金额',
                'type' => 'number',
                'default' => 0,
        ],
        'environment' => [
                'title' => '运行环境',
                'type' => 'select',
                'default' => 'production',
                'options' => [
                        'production' => '正式环境',
                        'sandbox' => '测试环境',
                ],
        ],
];
```

### 擴充套件目標元件

以下元件尚未實現，可按真實外掛需求在保持安全邊界的前提下擴充套件。擴充套件前必須先完成管理端渲染、後端校驗和儲存相容處理。

| 元件           | 建議 `type` | 用途                             |
| -------------- | ----------- | -------------------------------- |
| 日期           | `date`      | 有效期、開始日期                 |
| 日期時間       | `datetime`  | 準確到時間的有效期配置           |
| 時間           | `time`      | 定時規則                         |
| 標籤輸入       | `tags`      | IP 白名單、域名列表              |
| Key-Value 配置 | `key_value` | 請求頭、擴充套件引數                 |
| 可重複陣列     | `array`     | 多賬號、多規則；需謹慎控制複雜度 |

### 通用欄位結構

擴充套件後的 schema 建議統一採用以下欄位。當前不支援的欄位可以先忽略，不影響舊外掛掃描。

```json
{
  "key": "callback_url",
  "label": "回调地址",
  "type": "url",
  "required": true,
  "default": "",
  "placeholder": "请输入 HTTPS 回调地址",
  "description": "服务商回调会发送到该地址。",
  "width": "full",
  "disabled": false,
  "visible": true,
  "rules": {
    "max": 255,
    "pattern": "^https://",
    "message": "回调地址必须使用 HTTPS"
  }
}
```

| 欄位                | 必填 | 說明                                                 |
| ------------------- | ---- | ---------------------------------------------------- |
| `key`               | 是   | 配置欄位名，必須唯一                                 |
| `label` / `title`   | 是   | 後臺展示名稱                                         |
| `type`              | 是   | 元件型別                                             |
| `required`          | 否   | 是否必填                                             |
| `secret`            | 否   | 是否加密儲存且不明文回顯                             |
| `default` / `value` | 否   | 預設值                                               |
| `placeholder`       | 否   | 輸入提示                                             |
| `description`       | 否   | 欄位說明，純文本，不支援 HTML 和 Markdown            |
| `options`           | 否   | `select`、`multi_select`、`radio`、`checkbox` 的選項 |
| `width`             | 否   | `full` 或 `half`，預設 `full`                        |
| `disabled`          | 否   | 是否停用                                             |
| `visible`           | 否   | 是否顯示                                             |
| `rules`             | 否   | 基礎校驗規則                                         |

### 選項欄位格式

`options` 可繼續使用簡單鍵值物件：

```php
'options' => [
        'production' => '正式环境',
        'sandbox' => '测试环境',
],
```

也可擴充套件為陣列物件：

```json
{
  "options": [
    { "label": "正式环境", "value": "production" },
    { "label": "测试环境", "value": "sandbox" }
  ]
}
```

### 分組和條件顯示

配置項較多時，可擴充套件輕量分組：

```json
{
  "groups": [
    {
      "key": "basic",
      "title": "基础配置",
      "description": "配置服务商接口访问参数。"
    },
    { "key": "billing", "title": "计费配置" }
  ],
  "config_schema": [
    { "group": "basic", "key": "app_id", "label": "API 标识", "type": "text" },
    {
      "group": "billing",
      "key": "charge_enabled",
      "label": "插件收费",
      "type": "switch"
    }
  ]
}
```

條件顯示只允許使用受控表示式，不允許 JavaScript：

```json
{
  "key": "charge_amount",
  "label": "收费金额",
  "type": "number",
  "visible_when": {
    "field": "charge_enabled",
    "operator": "eq",
    "value": true
  }
}
```

首期條件運算子只考慮 `eq`、`neq`、`in`、`not_in`。

### 禁止元件和能力

外掛配置 schema 禁止宣告或變相實現以下能力：

| 禁止項                            | 原因                                             |
| --------------------------------- | ------------------------------------------------ |
| `html` / 任意 HTML                | XSS 風險高，破壞後臺樣式邊界                     |
| `script` / 任意 JavaScript        | 安全風險不可控                                   |
| `iframe`                          | 許可權、會話、點選劫持和資料邊界複雜               |
| `upload`                          | 涉及檔案安全、儲存、許可權和清理策略               |
| `rich_text` / Markdown 編輯器     | 存在 XSS 和樣式汙染風險                          |
| `custom_vue` / `remote_component` | 破壞構建、許可權和審計邊界                         |
| `action_button`                   | 會牽涉動作 API、許可權、二次確認和審計，需單獨設計 |

前端儲存時只提交真實配置欄位；`notice`、`divider`、`readonly`、`description`、`groups` 等展示類元資訊不參與提交。

## 5. 配置和金鑰

外掛配置儲存在兩張表：

- `integration_plugins`：安裝狀態、入口類、能力、配置 schema。
- `integration_plugin_configs`：非敏感配置和加密敏感配置。

敏感欄位規則：

- `secret=true` 的欄位寫入 `secret_json`，通過 Laravel `Crypt` 加密。
- 後臺詳情不回傳真實金鑰，只回傳 `has_secret_values`。
- 空提交表示保留舊金鑰。
- 多 SMTP 的 `accounts` 欄位支援脫敏預覽和按索引保留密碼。

## 6. 繫結表與執行日誌

外掛安裝記錄只表達“系統識別到了哪個外掛”，業務場景選擇必須通過繫結表表達，不再通過 `settings` 中的舊 driver/provider key 反寫。

| 表                                | 用途                                                                               |
| --------------------------------- | ---------------------------------------------------------------------------------- |
| `integration_plugin_bindings`     | 支付、實名、簡訊、郵件等全域性或場景級預設外掛繫結。                                 |
| `supplier_plugin_bindings`        | 供應商到上游外掛賬號、地址、金鑰和執行環境的繫結。                                 |
| `product_upstream_bindings`       | 商品到上游商品 ID、配置模板和開通策略的繫結。                                      |
| `service_upstream_bindings`       | 服務例項到上游例項 ID、供應商繫結和狀態快照的繫結。                                |
| `integration_plugin_runtime_logs` | 每次外掛 action 的 domain、plugin、binding、actor、耗時、狀態和脫敏請求/響應摘要。 |

執行規則：

- 啟用外掛只更新 `integration_plugins` 狀態；選擇“哪個業務場景使用哪個外掛”寫入繫結表。
- 外掛全域性配置進入 `integration_plugin_configs`，繫結級覆蓋配置進入對應繫結表，二者不能互相反寫。
- 支付、通知、上游等平臺服務必須從繫結解析器讀取外掛，不再新增 `selection_setting`、`syncLegacySettings()` 或 settings fallback。
- 外掛執行日誌由 `PluginRuntimeRegistry::execute()` 統一寫入，外掛內部不要自建業務執行日誌表。
- 敏感欄位只允許進入加密列或脫敏摘要，禁止寫入 runtime log 的明文欄位。

## 7. 定時任務與排程 Hooks

外掛需要定時能力時只能通過外掛清單接入平臺排程，不要在外掛裡註冊 Laravel `Schedule`、系統級 Cron、全域性中介軟體或系統級 API 路由。生產環境仍只保留寶塔每分鐘執行一次 `php artisan schedule:run`；`backend/routes/console.php` 每分鐘驅動心跳，具體業務任務仍由 15 分鐘槽位去重，並由 `HeartbeatTaskRegistry` 和外掛 provider 發現。

**反向同樣成立：核心不得 `Schedule::command()` 外掛命令。** 這不是「外掛不該註冊排程」的另一面，
而是同一條邊界上的另一個漏洞——核心一旦 schedule 了外掛命令，外掛停用/解除安裝後那條 Schedule
還在，每天必然產出「命令不存在」的失敗記錄，而且核心被迫知道「這個功能裝沒裝」。走清單宣告
就沒有這個問題：只有已啟用的外掛才會被 `PluginScheduledTaskProvider` 掃到，外掛不在，任務
自然消失，核心一行都不用改。`AiAssistant` 的會話清理原先正是反例（核心
`routes/console.php` 裡 `Schedule::command('ai:prune-conversations')`），現已改為清單宣告。

外掛有兩種接入方式：

| 方式             | 清單欄位                     | 適用場景                                               | 執行入口                                              |
| ---------------- | ---------------------------- | ------------------------------------------------------ | ----------------------------------------------------- |
| 監聽排程 Hook    | `info.extra.schedule_hooks`  | 輕量擴充套件、跟隨內建任務前後置邏輯、自定義外掛 hook 監聽 | `ScheduleHookService`                                 |
| 註冊獨立定時任務 | `info.extra.scheduled_tasks` | 外掛自己的週期性同步、重新整理、清理、巡檢                 | `PluginScheduledTaskProvider` → `RunHeartbeatTaskJob` |

### 方式一：監聽排程 Hook

在 `config.php` 的 `info.extra.schedule_hooks` 中宣告 hook 名和監聽器類：

```php
use App\Services\Automation\ScheduleHookService;
use TuraIDC\Plugins\Example\Lib\ExampleScheduleHook;

return [
    'info' => [
        // ...
        'extra' => [
            'schedule_hooks' => [
                ScheduleHookService::HOOK_TASK_AFTER => [
                    ExampleScheduleHook::class,
                ],
                'plugins.example.refresh' => [
                    ['class' => ExampleScheduleHook::class, 'method' => 'handle'],
                ],
            ],
        ],
    ],
];
```

監聽器放在外掛 `lib/`、`logic/` 或 `controller/` 下，推薦實現 `App\Services\Automation\Contracts\ScheduleHook`：

```php
<?php

declare(strict_types=1);

namespace TuraIDC\Plugins\Example\Lib;

use App\Services\Automation\Contracts\ScheduleHook;

final class ExampleScheduleHook implements ScheduleHook
{
    public function handle(string $hook, array $context = []): array
    {
        return [
            'handled' => true,
            'hook' => $hook,
            'task_key' => $context['task_key'] ?? null,
        ];
    }
}
```

支援的監聽器寫法與 `backend/config/schedule_hooks.php` 一致：

- `ExampleScheduleHook::class`
- `[ExampleScheduleHook::class, 'handle']`
- `['class' => ExampleScheduleHook::class, 'method' => 'handle']`
- 可呼叫物件或閉包（不建議外掛清單裡用閉包，跨程序序列化和可讀性都差）

當前內建 hook 名：

| 常量                                                    | 實際值                        | 觸發語義                                             |
| ------------------------------------------------------- | ----------------------------- | ---------------------------------------------------- |
| `ScheduleHookService::HOOK_BEFORE_CRON`                 | `before_cron`                 | 每個被記錄的排程任務執行前                           |
| `ScheduleHookService::HOOK_AFTER_CRON`                  | `after_cron`                  | 每個被記錄的排程任務成功或失敗後                     |
| `ScheduleHookService::HOOK_TASK_BEFORE`                 | `task.before`                 | 單個任務執行前，帶 `task_key` / `task_name`          |
| `ScheduleHookService::HOOK_TASK_AFTER`                  | `task.after`                  | 單個任務成功後，帶 `summary`                         |
| `ScheduleHookService::HOOK_TASK_FAILED`                 | `task.failed`                 | 單個任務失敗後，帶異常摘要                           |
| `ScheduleHookService::HOOK_EVERY_MINUTE`                | `tick.every_minute`           | 相容舊命名，當前按 15 分鐘心跳觸發                   |
| `ScheduleHookService::HOOK_EVERY_FIVE_MINUTES`          | `tick.every_five_minutes`     | 相容舊命名，當前按 15 分鐘心跳觸發                   |
| `ScheduleHookService::HOOK_HOURLY`                      | `tick.hourly`                 | 每小時 hook                                          |
| `ScheduleHookService::HOOK_DAILY`                       | `tick.daily`                  | 每日 hook                                            |
| `ScheduleHookService::HOOK_BEFORE_DAILY_CRON`           | `before_daily_cron`           | 舊系統每日前置相容 hook                              |
| `ScheduleHookService::HOOK_AFTER_DAILY_CRON`            | `after_daily_cron`            | 舊系統每日後置相容 hook                              |
| `ScheduleHookService::HOOK_AFTER_FIVE_MINUTE_CRON`      | `after_five_minute_cron`      | 舊系統五分鐘後置相容 hook，當前不代表真實 5 分鐘粒度 |
| `ScheduleHookService::HOOK_AFTER_HALF_HOUR_MINUTE_CRON` | `after_half_hour_minute_cron` | 舊系統半小時後置相容 hook                            |

Hook 失敗只會寫警告日誌，不會中斷排程主流程。監聽器必須自己控制冪等、限流和敏感欄位脫敏。

**宣告頻率與有效頻率**：`tick.every_minute`、`tick.every_five_minutes`、`after_five_minute_cron` 等名稱只表示“相容舊命名的宣告頻率”，平臺真實執行粒度為 15 分鐘槽位，錯過槽位不自動補跑。排程總覽（`GET /api/v2/admin/schedules/overview`）對每個任務同時輸出 `declared_cadence`（任務宣告頻率，未宣告為 `null`）與 `effective_cadence`（按 15 分鐘槽位推斷的真實頻率，如 `15分钟`、`60分钟`、`cron 0 3 * * *`）。外掛不得把相容名稱當作真實 1/5 分鐘執行依據；若業務 SLA 需要低於 15 分鐘的獨立執行，需要另立排程入口方案，不能直接改 Hook 頻率。

### 方式二：註冊獨立定時任務

外掛自己的週期任務通過 `info.extra.scheduled_tasks` 宣告任務類：

```php
use TuraIDC\Plugins\Example\Lib\ExampleScheduledTask;

return [
    'info' => [
        // ...
        'extra' => [
            'scheduled_tasks' => [
                ExampleScheduledTask::class,
            ],
        ],
    ],
];
```

任務類必須實現 `App\Services\Automation\Heartbeat\Contracts\ScheduledTask`：

```php
<?php

declare(strict_types=1);

namespace TuraIDC\Plugins\Example\Lib;

use App\Services\Automation\Heartbeat\Contracts\ScheduledTask;
use App\Services\Automation\Heartbeat\Data\TaskContext;
use App\Services\Automation\Heartbeat\ScheduleRule;

final class ExampleScheduledTask implements ScheduledTask
{
    public function key(): string
    {
        return 'example-refresh';
    }

    public function title(): string
    {
        return '示例插件刷新';
    }

    public function description(): string
    {
        return '由插件注册的心跳定时任务示例。';
    }

    public function category(): string
    {
        return '插件任务';
    }

    public function triggers(): array
    {
        return [ScheduleRule::everyTicks(1)];
    }

    public function handle(TaskContext $context): array
    {
        return [
            'processed' => 0,
            'source' => $context->source,
        ];
    }

    public function queue(): string
    {
        return (string) config('queue.TURAIDC_SCHEDULE_QUEUE', 'automation');
    }

    public function timeout(): int
    {
        return 300;
    }

    public function lockTtlSeconds(): int
    {
        return 600;
    }

    public function manualTriggerable(): bool
    {
        return true;
    }
}
```

觸發規則：

- `ScheduleRule::everyTicks(1)`：每個 15 分鐘心跳執行一次。
- `ScheduleRule::everyTicks(4)`：每 4 個心跳執行一次，約 1 小時。
- `ScheduleRule::cron('0 3 * * *')`：按 cron 表示式匹配心跳槽位；分鐘只能使用 `00`、`15`、`30` 或 `45`。
- `ScheduleRule::automation(...)`：僅在複用系統自動化配置模式時使用，普通外掛優先用 `everyTicks()` 或 `cron()`。

執行規則：

- 只有“已啟用”的外掛會被 `PluginScheduledTaskProvider` 掃描。
- 任務 `key()` 在全域性排程登錄檔中必須唯一，啟用後不要改名；如需改名必須提供受控歷史資料遷移。
- `key()` 和 `queue()` 必須返回無首尾空格的規範值；`queue()` 必須是已配置且由 Worker 消費的業務佇列或 `TURAIDC_SCHEDULE_QUEUE`（預設 `automation`），不能使用未消費的任意佇列。
- 任務通過 `RunHeartbeatTaskJob` 執行，帶佇列、重試和 `WithoutOverlapping` 互斥；`lockTtlSeconds()` 必須至少比 `timeout()` 多 60 秒，佇列 `retry_after` 也必須留出安全餘量。
- 外掛安裝/啟用和執行時註冊都會校驗任務契約；單個壞任務或壞清單隻會被隔離並記錄，不得阻斷系統任務。排程 Hook 的監聽方法必須為可呼叫的 `public` 方法。
- 心跳只處理當前 15 分鐘槽位；錯過的槽位預設不自動回放，需由任務自身的冪等補償邏輯或管理端手動觸發恢復。
- `handle()` 只返回可記錄的摘要陣列，不返回第三方原始響應、token、金鑰或大物件。
- 需要在任務內部拆分擴充套件點時，可以讓任務呼叫 `ScheduleHookService::run('plugins.{slug}.{action}', $context)`，再由 `extra.schedule_hooks` 註冊監聽器。`demo_style` 和 `zjmf_finance` 外掛已按這個模式實現。

### 除錯和驗證

開發外掛排程能力時優先跑最小測試：

```bash
cd backend
php artisan test tests/Unit/ScheduleHookServiceTest.php tests/Feature/PluginSimulationTest.php
```

本地手動觸發一次心跳：

```bash
cd backend
php artisan scheduler:heartbeat --at="2026-07-08 03:15:00"
```

檢視 Laravel 當前唯一排程源：

```bash
cd backend
php artisan schedule:list
```

不要用 `php artisan serve` 替代專案的 `php artisan app:serve`；需要聯調時按倉庫啟動指南使用 `app:serve` 或 `app:serve --with-schedule`。

## 8. 生命週期

1. 上傳外掛目錄到固定能力域目錄。
2. 後臺“外掛管理”點選掃描。
3. 點選安裝，後端校驗 `config.php`、目錄一致性、入口類和 `execute()` 方法。
4. 後臺填寫配置。
5. 點選啟用，後端校驗必填配置並更新外掛狀態。
6. 業務執行時從 `PluginRuntimeRegistry` 執行外掛動作，或通過平臺 adapter 註冊為支付、實名、簡訊、郵件、上游等內部契約。

安裝和啟用時會額外校驗 `info.extra.scheduled_tasks` 和 `info.extra.schedule_hooks` 中宣告的類是否存在、是否實現對應契約，壞宣告會在這一步直接報錯，而不是等到心跳執行時靜默跳過。

`demo_*` 外掛不在管理端列表展示，也不允許通過管理端安裝；開發除錯請直接用 `PluginInstaller` 或測試輔助方法。

### 解除安裝

解除安裝會硬刪 `integration_plugin_bindings`、`supplier_plugin_bindings`、`product_upstream_bindings`、`service_upstream_bindings` 四張繫結表中該外掛的記錄，歷史支付記錄的 `payments.plugin_id` 也會被外部索引鍵置空，操作不可逆。

因此後端預設拒絕仍被業務資料引用的外掛解除安裝，並在錯誤資訊裡列出引用明細；管理端確認後帶 `force=1` 再次請求才會真正執行。外掛目錄檔案不會被刪除。

## 9. 安全邊界

- 外掛不能自行註冊管理端選單、許可權、遷移和任意路由。
- **反過來，核心必須為「外掛提供能力、頁面託管在核心」的入口收口**。因為上一條，這類頁面只能寫在核心裡，於是核心掌握了入口卻不掌握能力：外掛停用或解除安裝後，外掛 Provider 註冊的介面整體消失，而核心那邊選單還在、排程還在。
  統一口徑是 `App\Support\OptionalFeature`（判定「已安裝且已啟用」）：管理端登入與取使用者資訊的介面下發 `features`，前端側欄分組與路由 `meta.requiresFeature` 按它隱藏，`routes/console.php` 裡引用外掛命令的 Schedule 按它決定是否登記。
  新增一個這樣的能力只需兩處改動：`OptionalFeature::map()` 加一行、前端選單分組/路由加 `requiresFeature`。**不要再讓核心無條件引用外掛的東西**——側欄留死入口、每天一條「命令不存在」的失敗記錄都是這麼來的。
- 支付回撥、訂單履約、賬務入賬、服務開通仍由平臺服務層控制。
- 第三方呼叫必須封裝在外掛服務或平臺 driver 中，不放 Controller。
- 回撥類可放 `controller/`，但平臺路由必須統一做簽名、冪等、日誌和審計。
- 外掛返回值必須由平臺 adapter 轉換為 DTO/Result，不把第三方原始結構直接透傳到業務層。

## 10. 執行時呼叫模型

外掛入口統一接收如下結構：

```php
[
    'domain' => 'payment',
    'slug' => 'ali_pay',
    'key' => 'alipay',
    'action' => 'payment.precreate',
    'payload' => [],
    'config' => [],
    'context' => [],
]
```

外掛返回結構：

```php
[
    'success' => true,
    'action' => 'payment.precreate',
    'message' => '',
    'data' => [],
]
```

平臺側負責：

- `PluginPaymentGateway`：把 `payment.*` action 轉為支付 DTO。
- `PluginVerificationDriver`：把 `verification.*` action 轉為實名結果。
- `PluginSmsDriver` / `PluginMailDriver`：把傳送 action 轉為通知結果。
- `PluginUpstreamDriver`：把 `server.resolve_capability` 轉為上游能力物件。

## 11. 當前真實外掛包

以下外掛直接放在 `backend/plugins/{能力域}/`，會像普通外掛一樣被後臺掃描到。`demo_*` 用於開發研究，不建議在生產環境啟用；真實外掛啟用前必須完成配置、測試和回撥邊界確認。

| 能力域        | 外掛目錄                                          | 說明                             |
| ------------- | ------------------------------------------------- | -------------------------------- |
| 支付渠道      | `backend/plugins/gateways/ali_pay`                | 支付寶當面付真實支付外掛         |
| 支付渠道      | `backend/plugins/gateways/demo_pay`               | 模擬支付閘道器                     |
| 支付渠道      | `backend/plugins/gateways/epay`                   | 易支付外掛                       |
| 實名認證      | `backend/plugins/certification/stay33`            | Stay33 實名認證外掛              |
| 實名認證      | `backend/plugins/certification/baidu_face`        | 百度人臉實名認證外掛             |
| 實名認證      | `backend/plugins/certification/smapi`             | 聚合實名認證外掛（小沐實名 API） |
| 實名認證      | `backend/plugins/certification/leaf_face`         | leaf 實名認證外掛                |
| 實名認證      | `backend/plugins/certification/alipay_certify`    | 支付寶身份認證外掛               |
| 實名認證      | `backend/plugins/certification/demo_verification` | 模擬實名外掛                     |
| 人機驗證      | `backend/plugins/captcha/geetest`                 | GeeTest 驗證碼外掛               |
| 人機驗證      | `backend/plugins/captcha/vaptcha`                 | Vaptcha 驗證碼外掛               |
| 人機驗證      | `backend/plugins/captcha/turnstile`               | Cloudflare Turnstile 驗證碼外掛  |
| 人機驗證      | `backend/plugins/captcha/corptcha`                | Corptcha 人機驗證外掛            |
| 人機驗證      | `backend/plugins/captcha/cap`                     | Cap 人機驗證外掛                 |
| 郵件傳送      | `backend/plugins/mail/multi_smtp_round_robin`     | 多 SMTP 輪詢郵件外掛             |
| 郵件傳送      | `backend/plugins/mail/smtp`                       | 單 SMTP 郵件外掛                 |
| 郵件傳送      | `backend/plugins/mail/demo_mail`                  | 模擬郵件外掛                     |
| 簡訊傳送      | `backend/plugins/sms/aliyun_sms`                  | 阿里雲簡訊服務外掛               |
| 簡訊傳送      | `backend/plugins/sms/aliyun`                      | 阿里雲號碼認證外掛               |
| 簡訊傳送      | `backend/plugins/sms/stay33`                      | Stay33 簡訊外掛                  |
| 簡訊傳送      | `backend/plugins/sms/demo_sms`                    | 模擬簡訊外掛                     |
| 上游開通/控制 | `backend/plugins/servers/zjmf_finance`            | ZJMF 財務上游外掛                |
| 上游開通/控制 | `backend/plugins/servers/tura_open_api`           | TuraIDC 開放介面上游驅動外掛     |
| 上游開通/控制 | `backend/plugins/servers/kanghostx`               | 康樂虛擬主機外掛                 |
| 上游開通/控制 | `backend/plugins/servers/rainyun_rcs`             | 雨雲 RCS 與附加資源賬單         |
| 上游開通/控制 | `backend/plugins/servers/demo_servers`            | 模擬上游外掛                     |
| 功能擴充套件      | `backend/plugins/addons/ai_assistant`             | 站內 AI 助手：對話、工具呼叫、導購與管理端治理   |
| 功能擴充套件      | `backend/plugins/addons/demo_style`               | Addon、排程任務和 hook 示例外掛  |
| 功能擴充套件      | `backend/plugins/addons/zjmf_bridge`              | ZJMF Bridge 相容介面外掛         |

每個 demo 包都包含：

- `config.php`
- `{Name}Plugin.php`
- `lib/` 或 `logic/`
- 可選 `controller/`
- `README.md`

## 12. 當前外掛說明文件

- [外掛包規範（獨立倉庫分發與外掛市場）](package-spec.md)
- [支付寶當面付外掛 demo](demo-ali-pay.md)
- [AI 助手外掛（對話、工具呼叫、導購與治理）](https://github.com/25Cloud/TuraIDC/blob/main/backend/plugins/addons/ai_assistant/README.md)
- [康樂虛擬主機外掛](https://github.com/25Cloud/TuraIDC/blob/main/backend/plugins/servers/kanghostx/README.md)
- [TuraIDC 開放介面上游驅動外掛](https://github.com/25Cloud/TuraIDC/blob/main/backend/plugins/servers/tura_open_api/README.md)

其他外掛以各自目錄內 `README.md` / `DEVELOPMENT.md` 和當前程式碼為準；不要在導航中保留不存在的說明文件連結。

## 13. 驗證命令

只改外掛後端邏輯：

```bash
cd backend
php artisan test tests/Feature/AdminIntegrationPluginControllerTest.php tests/Feature/PluginRuntimeRegistryIntegrationTest.php
```

涉及外掛定時任務或排程 Hook：

```bash
cd backend
php artisan test tests/Unit/ScheduleHookServiceTest.php tests/Feature/PluginSimulationTest.php
```

涉及郵件外掛：

```bash
cd backend
php artisan test tests/Feature/MultiSmtpRoundRobinPluginTest.php
```

改過腳手架模板（`backend/stubs/plugin/*.stub`，與獨立模板倉庫同源）：

```bash
cd backend
php vendor/bin/phpunit tests/Feature/PluginMakeCommandTest.php
```

外掛自己的用例跟著外掛目錄走，`phpunit.xml.dist` 的 `Plugins` 套件會掃 `plugins` 下所有
`*Test.php`：

```bash
cd backend
php vendor/bin/phpunit plugins/addons/ai_assistant/tests
```

涉及管理端頁面：

```bash
cd frontend-admin-v3
pnpm.cmd run build
```
