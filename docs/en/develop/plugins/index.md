---
source: develop/plugins/index.md
sourceHash: 6fe02ad608a8c8215148ead2a5f400719ab10b673c6e4eec7e9b401dd0d84e6d
translatedAt: 2026-10-26
---

# TuraIDC Plugin Development Guide

This document describes the plugin directory conventions, loading rules, configuration rules, and real plugin packages of the current project. The plugin system uses the "ZJMF Finance-style directory + Laravel controlled loading" approach: after a plugin is uploaded to a fixed directory, an administrator scans, installs, configures, and enables it in the admin panel.

## 1. Directory Mapping

All plugins are placed under `backend/plugins`, with directories split by capability domain:

| Capability domain             | Admin-side domain | Physical directory              | Platform adapter/contract                                |
| ----------------------------- | ----------------- | ------------------------------- | -------------------------------------------------------- |
| Payment channel               | `payment`         | `backend/plugins/gateways`      | `PluginPaymentGateway` → `PaymentGatewayInterface`       |
| Real-name verification        | `verification`    | `backend/plugins/certification` | `PluginVerificationDriver` → `VerificationDriver`        |
| Human verification            | `captcha`         | `backend/plugins/captcha`       | `GeeTestService` / login risk-control CAPTCHA call chain |
| Email delivery                | `mail`            | `backend/plugins/mail`          | `PluginMailDriver` → `MailDriver`                        |
| SMS delivery                  | `sms`             | `backend/plugins/sms`           | `PluginSmsDriver` → `SmsDriver`                          |
| Upstream provisioning/control | `upstream`        | `backend/plugins/servers`       | `PluginUpstreamDriver` → `UpstreamDriver`                |
| Feature extension             | `addons`          | `backend/plugins/addons`        | Controlled addon actions, scheduled tasks, and hooks     |

`domain` is the domain name used in the admin API and the database; the physical directories follow the ZJMF Finance `gateways/certification/mail/sms/servers` style and are extended with `captcha/addons` for the current plugin system. The plugin entry class does not implement the platform contract directly: the entry class provides `execute(array $request): array`, and the platform converts it into the internal contract through `PluginRuntimeRegistry` and the domain adapter.

## 2. Single Plugin Structure

Recommended structure:

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

The current project does not use the ZJMF Finance `.tpl` template mechanism; neither plugin demos nor real plugins need a `template/` directory. Admin pages are all rendered by the plugin management page of `frontend-admin-v3`.

The loading order is controlled by `PluginFileLoader`:

1. Recursively load PHP files under `lib/`, `logic/`, and `controller/`.
2. If `vendor/autoload.php` exists, load the plugin dependencies.
3. Load PHP files in the plugin root directory, skipping `config.php`.
4. Load `src/` for compatibility, to ease the transition from legacy structures.

## 3. config.php Specification

`config.php` declares both the plugin metadata and the admin config form:

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

Required fields:

- `info.domain`: must be one of `payment`, `verification`, `captcha`, `mail`, `sms`, `upstream`, `addons`.
- `info.slug`: must match the plugin directory name.
- `info.key`: business registration key, unique within the same domain.
- `info.name`: display name in the admin panel.
- `info.entry`: entry class, must exist and provide `execute(array $request): array`.

Supported config fields:

| Field               | Description                                                        |
| ------------------- | ------------------------------------------------------------------ |
| `title` / `label`   | Display label in the admin panel                                   |
| `type`              | `text`, `textarea`, `number`, `switch`, `select`, `json`           |
| `required`          | Whether it must be filled in before enabling                       |
| `secret`            | Whether it is stored encrypted and never echoed back in plain text |
| `value` / `default` | Default value                                                      |
| `options`           | `select` options                                                   |

## 4. Config Component Protocol

The plugin config page is rendered in a schema-driven way. Plugins can only declare controlled component types, which the admin side renders, validates, and saves uniformly; plugins cannot inject arbitrary Vue, HTML, JavaScript, remote components, or iframes.

### Currently Supported Components

The admin side currently supports the following types:

| Component        | `type`         | Description                                                 |
| ---------------- | -------------- | ----------------------------------------------------------- |
| Single-line text | `text`         | Plain strings such as API identifiers, accounts, and names  |
| Multi-line text  | `textarea`     | Long text such as remarks and template content              |
| Number input     | `number`       | Numeric values such as amounts, ports, and counts           |
| Switch           | `switch`       | Whether a capability is enabled                             |
| Single select    | `select`       | Enumeration values such as environment, channel, and region |
| Multi select     | `multi_select` | Supported capabilities, available regions                   |
| Radio group      | `radio`        | A small number of mutually exclusive options                |
| Checkbox group   | `checkbox`     | Multi-item toggle configuration                             |
| JSON editor      | `json`         | Advanced config, field mapping, array config                |
| Password/secret  | `password`     | Secret, Token, password                                     |
| URL input        | `url`          | Callback URL, endpoint URL                                  |
| Email input      | `email`        | Sender email, notification email                            |
| Phone input      | `phone`        | Test phone number, contact phone number                     |
| Notice text      | `notice`       | Config notes, risk notices; not saved                       |
| Divider          | `divider`      | Form group separator; not saved                             |
| Read-only text   | `readonly`     | Displays system-generated values; not saved                 |

Secret fields have two equivalent forms: declare `type=password`, or declare `secret=true` on any text type. Both are rendered as a password box, stored encrypted on the backend, and never echoed back in plain text.

Conditional display `visible_when` is implemented and supports the four operators `eq`, `neq`, `in`, and `not_in` (see the "Groups and Conditional Display" section); field-level `visible: false` hides the field directly.

Example:

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

### Planned Components

The following components are not implemented yet and can be added according to real plugin needs while preserving the security boundary. Before extending, admin-side rendering, backend validation, and save compatibility handling must be completed first.

| Component        | Suggested `type` | Purpose                                                         |
| ---------------- | ---------------- | --------------------------------------------------------------- |
| Date             | `date`           | Validity period, start date                                     |
| Date-time        | `datetime`       | Validity configuration accurate to the time                     |
| Time             | `time`           | Scheduling rules                                                |
| Tag input        | `tags`           | IP allowlist, domain list                                       |
| Key-Value config | `key_value`      | Request headers, extension parameters                           |
| Repeatable array | `array`          | Multiple accounts, multiple rules; control complexity carefully |

### Common Field Structure

The extended schema is recommended to use the following fields uniformly. Fields that are not supported yet can be ignored, which does not affect the scanning of legacy plugins.

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

| Field               | Required | Description                                                        |
| ------------------- | -------- | ------------------------------------------------------------------ |
| `key`               | Yes      | Config field name, must be unique                                  |
| `label` / `title`   | Yes      | Display name in the admin panel                                    |
| `type`              | Yes      | Component type                                                     |
| `required`          | No       | Whether it is required                                             |
| `secret`            | No       | Whether it is stored encrypted and never echoed back in plain text |
| `default` / `value` | No       | Default value                                                      |
| `placeholder`       | No       | Input hint                                                         |
| `description`       | No       | Field description, plain text, HTML and Markdown are not supported |
| `options`           | No       | Options for `select`, `multi_select`, `radio`, and `checkbox`      |
| `width`             | No       | `full` or `half`, defaults to `full`                               |
| `disabled`          | No       | Whether it is disabled                                             |
| `visible`           | No       | Whether it is visible                                              |
| `rules`             | No       | Basic validation rules                                             |

### Option Field Format

`options` can keep using a simple key-value object:

```php
'options' => [
        'production' => '正式环境',
        'sandbox' => '测试环境',
],
```

It can also be extended to an array of objects:

```json
{
  "options": [
    { "label": "正式环境", "value": "production" },
    { "label": "测试环境", "value": "sandbox" }
  ]
}
```

### Groups and Conditional Display

When there are many config options, lightweight groups can be added:

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

Conditional display only allows controlled expressions; JavaScript is not allowed:

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

The first phase of condition operators only considers `eq`, `neq`, `in`, and `not_in`.

### Prohibited Components and Capabilities

The plugin config schema is prohibited from declaring or indirectly implementing the following capabilities:

| Prohibited item                   | Reason                                                                                        |
| --------------------------------- | --------------------------------------------------------------------------------------------- |
| `html` / arbitrary HTML           | High XSS risk, breaks the admin style boundary                                                |
| `script` / arbitrary JavaScript   | Security risk is uncontrollable                                                               |
| `iframe`                          | Permissions, sessions, clickjacking, and data boundaries are complex                          |
| `upload`                          | Involves file security, storage, permissions, and cleanup policies                            |
| `rich_text` / Markdown editor     | XSS and style pollution risks                                                                 |
| `custom_vue` / `remote_component` | Breaks build, permission, and audit boundaries                                                |
| `action_button`                   | Involves action APIs, permissions, double confirmation, and auditing; needs a separate design |

When saving on the frontend, only real config fields are submitted; display metadata such as `notice`, `divider`, `readonly`, `description`, and `groups` is not submitted.

## 5. Config and Secrets

Plugin config is stored in two tables:

- `integration_plugins`: installation status, entry class, capabilities, config schema.
- `integration_plugin_configs`: non-sensitive config and encrypted sensitive config.

Sensitive field rules:

- Fields with `secret=true` are written to `secret_json` and encrypted with Laravel `Crypt`.
- The admin detail endpoint does not return real secrets, only `has_secret_values`.
- An empty submit means keeping the old secret.
- The `accounts` field of multi SMTP supports masked preview and keeping passwords by index.

## 6. Binding Tables and Runtime Logs

A plugin installation record only expresses "which plugin the system has detected"; the choice of business scenario must be expressed through binding tables, and is no longer written back through legacy driver/provider keys in `settings`.

| Table                             | Purpose                                                                                                      |
| --------------------------------- | ------------------------------------------------------------------------------------------------------------ |
| `integration_plugin_bindings`     | Global or scenario-level default plugin bindings for payment, real-name verification, SMS, email, and so on. |
| `supplier_plugin_bindings`        | Bindings from supplier to upstream plugin account, address, secret, and runtime environment.                 |
| `product_upstream_bindings`       | Bindings from product to upstream product ID, config template, and provisioning policy.                      |
| `service_upstream_bindings`       | Bindings from service instance to upstream instance ID, supplier binding, and status snapshot.               |
| `integration_plugin_runtime_logs` | Domain, plugin, binding, actor, duration, status, and masked request/response summary of each plugin action. |

Runtime rules:

- Enabling a plugin only updates the status in `integration_plugins`; choosing "which plugin each business scenario uses" writes to the binding tables.
- Plugin global config goes into `integration_plugin_configs`, binding-level override config goes into the corresponding binding table, and the two must not be written back to each other.
- Platform services such as payment, notification, and upstream must read plugins from the binding resolver, and no new `selection_setting`, `syncLegacySettings()`, or settings fallback may be added.
- Plugin runtime logs are written uniformly by `PluginRuntimeRegistry::execute()`; plugins must not create their own business runtime log tables.
- Sensitive fields may only go into encrypted columns or masked summaries and must not be written to plaintext fields of the runtime log.

## 7. Scheduled Tasks and Scheduling Hooks

When a plugin needs scheduling capability, it can only hook into platform scheduling through the plugin manifest; do not register Laravel `Schedule`, system-level Cron, global middleware, or system-level API routes inside the plugin. The production environment still keeps only BaoTa Panel running `php artisan schedule:run` once per minute; `backend/routes/console.php` drives the heartbeat every minute, specific business tasks are still deduplicated by 15-minute slots, and are discovered by `HeartbeatTaskRegistry` and the plugin provider.

There are two ways for plugins to hook in:

| Approach                            | Manifest field               | Suitable scenario                                                                       | Runtime entry                                         |
| ----------------------------------- | ---------------------------- | --------------------------------------------------------------------------------------- | ----------------------------------------------------- |
| Listen to scheduling hooks          | `info.extra.schedule_hooks`  | Lightweight extensions, logic before/after built-in tasks, custom plugin hook listeners | `ScheduleHookService`                                 |
| Register standalone scheduled tasks | `info.extra.scheduled_tasks` | The plugin's own periodic sync, refresh, cleanup, and inspection                        | `PluginScheduledTaskProvider` → `RunHeartbeatTaskJob` |

### Option 1: Listen to Scheduling Hooks

In `config.php`, declare the hook name and listener class in `info.extra.schedule_hooks`:

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

Listeners go under the plugin's `lib/`, `logic/`, or `controller/`, and are recommended to implement `App\Services\Automation\Contracts\ScheduleHook`:

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

Supported listener forms are the same as in `backend/config/schedule_hooks.php`:

- `ExampleScheduleHook::class`
- `[ExampleScheduleHook::class, 'handle']`
- `['class' => ExampleScheduleHook::class, 'method' => 'handle']`
- Callable objects or closures (closures in the plugin manifest are not recommended; they serialize poorly across processes and read badly)

Currently built-in hook names:

| Constant                                                | Actual value                  | Trigger semantics                                                                                            |
| ------------------------------------------------------- | ----------------------------- | ------------------------------------------------------------------------------------------------------------ |
| `ScheduleHookService::HOOK_BEFORE_CRON`                 | `before_cron`                 | Before each recorded scheduling task runs                                                                    |
| `ScheduleHookService::HOOK_AFTER_CRON`                  | `after_cron`                  | After each recorded scheduling task succeeds or fails                                                        |
| `ScheduleHookService::HOOK_TASK_BEFORE`                 | `task.before`                 | Before a single task runs, with `task_key` / `task_name`                                                     |
| `ScheduleHookService::HOOK_TASK_AFTER`                  | `task.after`                  | After a single task succeeds, with `summary`                                                                 |
| `ScheduleHookService::HOOK_TASK_FAILED`                 | `task.failed`                 | After a single task fails, with the exception summary                                                        |
| `ScheduleHookService::HOOK_EVERY_MINUTE`                | `tick.every_minute`           | Legacy naming kept for compatibility; currently triggered on the 15-minute heartbeat                         |
| `ScheduleHookService::HOOK_EVERY_FIVE_MINUTES`          | `tick.every_five_minutes`     | Legacy naming kept for compatibility; currently triggered on the 15-minute heartbeat                         |
| `ScheduleHookService::HOOK_HOURLY`                      | `tick.hourly`                 | Hourly hook                                                                                                  |
| `ScheduleHookService::HOOK_DAILY`                       | `tick.daily`                  | Daily hook                                                                                                   |
| `ScheduleHookService::HOOK_BEFORE_DAILY_CRON`           | `before_daily_cron`           | Legacy daily pre-hook kept for compatibility                                                                 |
| `ScheduleHookService::HOOK_AFTER_DAILY_CRON`            | `after_daily_cron`            | Legacy daily post-hook kept for compatibility                                                                |
| `ScheduleHookService::HOOK_AFTER_FIVE_MINUTE_CRON`      | `after_five_minute_cron`      | Legacy five-minute post-hook kept for compatibility; it does not represent a real 5-minute granularity today |
| `ScheduleHookService::HOOK_AFTER_HALF_HOUR_MINUTE_CRON` | `after_half_hour_minute_cron` | Legacy half-hour post-hook kept for compatibility                                                            |

Hook failures only write warning logs and do not interrupt the main scheduling flow. Listeners must handle idempotency, rate limiting, and sensitive-field masking themselves.

**Declared cadence vs. effective cadence**: names such as `tick.every_minute`, `tick.every_five_minutes`, and `after_five_minute_cron` only express a "declared cadence kept for legacy naming compatibility"; the platform's real execution granularity is 15-minute slots, and missed slots are not re-run automatically. The schedule overview (`GET /api/v2/admin/schedules/overview`) outputs both `declared_cadence` (the task's declared cadence, `null` when not declared) and `effective_cadence` (the real cadence inferred from 15-minute slots, such as `15分钟`, `60分钟`, `cron 0 3 * * *`) for each task. Plugins must not treat compatibility names as evidence of real 1/5-minute execution; if a business SLA needs standalone execution below 15 minutes, a separate scheduling-entry solution must be defined, and the hook cadence must not be changed directly.

### Option 2: Register Standalone Scheduled Tasks

The plugin's own periodic tasks declare task classes through `info.extra.scheduled_tasks`:

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

Task classes must implement `App\Services\Automation\Heartbeat\Contracts\ScheduledTask`:

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

Trigger rules:

- `ScheduleRule::everyTicks(1)`: runs once per 15-minute heartbeat.
- `ScheduleRule::everyTicks(4)`: runs once every 4 heartbeats, about 1 hour.
- `ScheduleRule::cron('0 3 * * *')`: matches heartbeat slots by cron expression; minutes can only be `00`, `15`, `30`, or `45`.
- `ScheduleRule::automation(...)`: only used when reusing the system automation config mode; ordinary plugins should prefer `everyTicks()` or `cron()`.

Runtime rules:

- Only "enabled" plugins are scanned by `PluginScheduledTaskProvider`.
- Task `key()` must be unique in the global scheduling registry and must not be renamed after being enabled; if a rename is required, a controlled historical data migration must be provided.
- `key()` and `queue()` must return canonical values without leading or trailing spaces; `queue()` must be a configured business queue consumed by a Worker or `TURAIDC_SCHEDULE_QUEUE` (default `automation`), and must not be an arbitrary unconsumed queue.
- Tasks run through `RunHeartbeatTaskJob`, with queue, retry, and `WithoutOverlapping` mutual exclusion; `lockTtlSeconds()` must be at least 60 seconds longer than `timeout()`, and the queue `retry_after` must also leave a safe margin.
- Plugin installation/enabling and runtime registration both validate task contracts; a single bad task or bad manifest is only isolated and logged, and must not block system tasks. Listener methods of scheduling hooks must be callable `public` methods.
- The heartbeat only handles the current 15-minute slot; missed slots are not replayed automatically by default and must be recovered by the task's own idempotent compensation logic or a manual trigger from the admin side.
- `handle()` only returns a recordable summary array, not third-party raw responses, tokens, secrets, or large objects.
- When extension points need to be split inside a task, the task can call `ScheduleHookService::run('plugins.{slug}.{action}', $context)` and then register listeners through `extra.schedule_hooks`. The `demo_style` and `zjmf_finance` plugins already follow this pattern.

### Debugging and Verification

Prefer running the minimal tests when developing plugin scheduling capabilities:

```bash
cd backend
php artisan test tests/Unit/ScheduleHookServiceTest.php tests/Feature/PluginSimulationTest.php
```

Trigger a heartbeat manually on a local machine:

```bash
cd backend
php artisan scheduler:heartbeat --at="2026-07-08 03:15:00"
```

Inspect the only current Laravel schedule source:

```bash
cd backend
php artisan schedule:list
```

Do not use `php artisan serve` in place of the project's `php artisan app:serve`; when integration testing is needed, use `app:serve` or `app:serve --with-schedule` according to the repository startup guide.

## 8. Lifecycle

1. Upload the plugin directory to the fixed capability domain directory.
2. Click scan in "Plugin Management" in the admin panel.
3. Click install; the backend validates `config.php`, directory consistency, the entry class, and the `execute()` method.
4. Fill in the config in the admin panel.
5. Click enable; the backend validates required config and updates the plugin status.
6. At business runtime, plugin actions are executed through `PluginRuntimeRegistry`, or the plugin is registered as an internal contract for payment, real-name verification, SMS, email, upstream, and so on through the platform adapter.

During installation and enabling, the classes declared in `info.extra.scheduled_tasks` and `info.extra.schedule_hooks` are additionally validated for existence and contract implementation; bad declarations fail directly at this step instead of being silently skipped when the heartbeat runs.

`demo_*` plugins are not shown in the admin list and cannot be installed through the admin side; for development debugging, use `PluginInstaller` or test helper methods directly.

### Uninstall

Uninstall hard-deletes the plugin's records in the four binding tables `integration_plugin_bindings`, `supplier_plugin_bindings`, `product_upstream_bindings`, and `service_upstream_bindings`, and the `payments.plugin_id` of historical payment records is also set to null by the foreign key; the operation is irreversible.

Therefore, the backend rejects by default uninstalling a plugin that is still referenced by business data, and lists the references in the error message; only after confirmation in the admin side and a second request with `force=1` is it actually executed. Plugin directory files are not deleted.

## 9. Security Boundaries

- Plugins must not register admin menus, permissions, migrations, or arbitrary routes by themselves.
- Payment callbacks, order fulfillment, ledger posting, and service provisioning are still controlled by the platform service layer.
- Third-party calls must be encapsulated in plugin services or platform drivers, not placed in Controllers.
- Callback classes can go in `controller/`, but platform routing must uniformly handle signing, idempotency, logging, and auditing.
- Plugin return values must be converted into DTOs/Results by the platform adapter; third-party raw structures must not be passed straight through to the business layer.

## 10. Runtime Call Model

Plugin entries uniformly receive the following structure:

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

Plugin return structure:

```php
[
    'success' => true,
    'action' => 'payment.precreate',
    'message' => '',
    'data' => [],
]
```

The platform side is responsible for:

- `PluginPaymentGateway`: converts `payment.*` actions into payment DTOs.
- `PluginVerificationDriver`: converts `verification.*` actions into real-name verification results.
- `PluginSmsDriver` / `PluginMailDriver`: converts send actions into notification results.
- `PluginUpstreamDriver`: converts `server.resolve_capability` into an upstream capability object.

## 11. Current Real Plugin Packages

The following plugins are placed directly under `backend/plugins/{能力域}/` and are scanned by the admin side like ordinary plugins. `demo_*` is for development research and is not recommended for production; before enabling a real plugin, config, testing, and callback boundary confirmation must be completed.

| Capability domain             | Plugin directory                                  | Description                                                     |
| ----------------------------- | ------------------------------------------------- | --------------------------------------------------------------- |
| Payment channel               | `backend/plugins/gateways/ali_pay`                | Real Alipay face-to-face payment plugin                         |
| Payment channel               | `backend/plugins/gateways/demo_pay`               | Simulated payment gateway                                       |
| Payment channel               | `backend/plugins/gateways/epay`                   | EPay plugin                                                     |
| Real-name verification        | `backend/plugins/certification/stay33`            | Stay33 real-name verification plugin                            |
| Real-name verification        | `backend/plugins/certification/baidu_face`        | Baidu face real-name verification plugin                        |
| Real-name verification        | `backend/plugins/certification/smapi`             | Aggregated real-name verification plugin (Xiaomu Real-name API) |
| Real-name verification        | `backend/plugins/certification/leaf_face`         | leaf real-name verification plugin                              |
| Real-name verification        | `backend/plugins/certification/alipay_certify`    | Alipay identity verification plugin                             |
| Real-name verification        | `backend/plugins/certification/demo_verification` | Simulated real-name verification plugin                         |
| Human verification            | `backend/plugins/captcha/geetest`                 | GeeTest CAPTCHA plugin                                          |
| Human verification            | `backend/plugins/captcha/vaptcha`                 | Vaptcha CAPTCHA plugin                                          |
| Human verification            | `backend/plugins/captcha/turnstile`               | Cloudflare Turnstile CAPTCHA plugin                             |
| Human verification            | `backend/plugins/captcha/corptcha`                | Corptcha human verification plugin                              |
| Human verification            | `backend/plugins/captcha/cap`                     | Cap human verification plugin                                   |
| Email delivery                | `backend/plugins/mail/multi_smtp_round_robin`     | Multi SMTP round-robin email plugin                             |
| Email delivery                | `backend/plugins/mail/smtp`                       | Single SMTP email plugin                                        |
| Email delivery                | `backend/plugins/mail/demo_mail`                  | Simulated email plugin                                          |
| SMS delivery                  | `backend/plugins/sms/aliyun_sms`                  | Alibaba Cloud SMS service plugin                                |
| SMS delivery                  | `backend/plugins/sms/aliyun`                      | Alibaba Cloud phone number verification plugin                  |
| SMS delivery                  | `backend/plugins/sms/stay33`                      | Stay33 SMS plugin                                               |
| SMS delivery                  | `backend/plugins/sms/demo_sms`                    | Simulated SMS plugin                                            |
| Upstream provisioning/control | `backend/plugins/servers/zjmf_finance`            | ZJMF Finance upstream plugin                                    |
| Upstream provisioning/control | `backend/plugins/servers/tura_open_api`           | TuraIDC open API upstream driver plugin                         |
| Upstream provisioning/control | `backend/plugins/servers/kanghostx`               | KangLe virtual host plugin                                      |
| Upstream provisioning/control | `backend/plugins/servers/rainyun_rcs`             | Rainyun RCS and add-on resource billing                         |
| Upstream provisioning/control | `backend/plugins/servers/demo_servers`            | Simulated upstream plugin                                       |
| Feature extension             | `backend/plugins/addons/demo_style`               | Addon, scheduled task, and hook example plugin                  |
| Feature extension             | `backend/plugins/addons/zjmf_bridge`              | ZJMF Bridge compatibility interface plugin                      |

Every demo package contains:

- `config.php`
- `{Name}Plugin.php`
- `lib/` or `logic/`
- optional `controller/`
- `README.md`

## 12. Current Plugin Documentation

- [Plugin package specification (standalone repository distribution and plugin marketplace)](package-spec.md)
- [Alipay face-to-face payment plugin demo](demo-ali-pay.md)
- [KangLe virtual host plugin](https://github.com/25Cloud/TuraIDC/blob/main/backend/plugins/servers/kanghostx/README.md)
- [TuraIDC open API upstream driver plugin](https://github.com/25Cloud/TuraIDC/blob/main/backend/plugins/servers/tura_open_api/README.md)

For other plugins, the `README.md` / `DEVELOPMENT.md` in their own directory and the current code are authoritative; do not keep links to non-existent documentation in the navigation.

## 13. Verification Commands

Only plugin backend logic changed:

```bash
cd backend
php artisan test tests/Feature/AdminIntegrationPluginControllerTest.php tests/Feature/PluginRuntimeRegistryIntegrationTest.php
```

Plugin scheduled tasks or scheduling hooks involved:

```bash
cd backend
php artisan test tests/Unit/ScheduleHookServiceTest.php tests/Feature/PluginSimulationTest.php
```

Email plugin involved:

```bash
cd backend
php artisan test tests/Feature/MultiSmtpRoundRobinPluginTest.php
```

Admin-side pages involved:

```bash
cd frontend-admin-v3
pnpm.cmd run build
```
