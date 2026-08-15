# TuraIDC 插件开发指南

本文档说明当前项目的插件目录规范、加载规则、配置规则和真实插件包。插件系统采用“ZJMF 财务风格目录 + Laravel 受控加载”方案：插件上传到固定目录后，由管理员在后台扫描、安装、配置、启用。

## 目录映射

插件统一放在 `backend/plugins` 下，目录按能力域划分：

| 能力域        | 管理端 domain  | 物理目录                        | 平台适配器/契约                                    |
| ------------- | -------------- | ------------------------------- | -------------------------------------------------- |
| 支付渠道      | `payment`      | `backend/plugins/gateways`      | `PluginPaymentGateway` → `PaymentGatewayInterface` |
| 实名认证      | `verification` | `backend/plugins/certification` | `PluginVerificationDriver` → `VerificationDriver`  |
| 人机验证      | `captcha`      | `backend/plugins/captcha`       | `GeeTestService` / 登录风控验证码调用链            |
| 邮件发送      | `mail`         | `backend/plugins/mail`          | `PluginMailDriver` → `MailDriver`                  |
| 短信发送      | `sms`          | `backend/plugins/sms`           | `PluginSmsDriver` → `SmsDriver`                    |
| 上游开通/控制 | `upstream`     | `backend/plugins/servers`       | `PluginUpstreamDriver` → `UpstreamDriver`          |
| 功能扩展      | `addons`       | `backend/plugins/addons`        | 受控 addon action、调度任务和 hook                 |

`domain` 是后台 API 和数据库里使用的领域名；物理目录沿用ZJMF 财务的 `gateways/certification/mail/sms/servers` 风格，并按当前插件体系扩展了 `captcha/addons`。插件入口类不直接实现平台契约，入口类提供 `execute(array $request): array`，平台通过 `PluginRuntimeRegistry` 和领域 adapter 转换为内部契约。

## 单插件结构

推荐结构：

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

当前项目不使用ZJMF 财务的 `.tpl` 模板机制，插件 demo 和真实插件都不需要 `template/` 目录。管理页面统一由 `frontend-admin-v3` 的插件管理页渲染。

加载顺序由 `PluginFileLoader` 控制：

1. 递归加载 `lib/`、`logic/`、`controller/` 下 PHP 文件。
2. 如果存在 `vendor/autoload.php`，加载插件依赖。
3. 加载插件根目录 PHP 文件，跳过 `config.php`。
4. 兼容加载 `src/`，用于旧结构过渡。

## config.php 规范

`config.php` 同时声明插件元信息和后台配置表单：

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

必填字段：

- `info.domain`：必须是 `payment`、`verification`、`captcha`、`mail`、`sms`、`upstream`、`addons` 之一。
- `info.slug`：必须和插件目录名一致。
- `info.key`：业务注册 key，同领域内唯一。
- `info.name`：后台展示名。
- `info.entry`：入口类，必须存在，并提供 `execute(array $request): array`。

配置字段支持：

| 字段                | 说明                                                     |
| ------------------- | -------------------------------------------------------- |
| `title` / `label`   | 后台展示标签                                             |
| `type`              | `text`、`textarea`、`number`、`switch`、`select`、`json` |
| `required`          | 启用前是否必须填写                                       |
| `secret`            | 是否加密保存且不明文回显                                 |
| `value` / `default` | 默认值                                                   |
| `options`           | `select` 选项                                            |

## 配置组件协议

插件配置页采用 schema 驱动渲染。插件只能声明受控组件类型，由管理端统一渲染、校验、保存；插件不能注入任意 Vue、HTML、JavaScript、远程组件或 iframe。

### 当前已支持组件

当前管理端已支持以下类型：

| 组件       | `type`         | 说明                             |
| ---------- | -------------- | -------------------------------- |
| 单行文本   | `text`         | API 标识、账号、名称等普通字符串 |
| 多行文本   | `textarea`     | 备注、模板内容等长文本           |
| 数字输入   | `number`       | 金额、端口、次数等数值           |
| 开关       | `switch`       | 是否启用某项能力                 |
| 下拉单选   | `select`       | 环境、渠道、区域等枚举值         |
| 下拉多选   | `multi_select` | 支持能力、可用区域               |
| 单选按钮组 | `radio`        | 少量互斥选项                     |
| 复选框组   | `checkbox`     | 多项开关配置                     |
| JSON 编辑  | `json`         | 高级配置、字段映射、数组配置     |
| 密码/密钥  | `password`     | Secret、Token、密码              |
| URL 输入   | `url`          | 回调地址、接口地址               |
| 邮箱输入   | `email`        | 发件邮箱、通知邮箱               |
| 手机号输入 | `phone`        | 测试手机号、联系人手机号         |
| 提示文本   | `notice`       | 配置说明、风险提示；不参与保存   |
| 分割线     | `divider`      | 表单分组分隔；不参与保存         |
| 只读文本   | `readonly`     | 展示系统生成值；不参与保存       |

密钥字段有两种等价写法：声明 `type=password`，或在任意文本类型上声明 `secret=true`。两者都会按密码框展示，后端加密保存且不明文回显。

条件显示 `visible_when` 已实现，支持 `eq`、`neq`、`in`、`not_in` 四个操作符（见"分组和条件显示"一节）；字段级 `visible: false` 直接隐藏。

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

### 扩展目标组件

以下组件尚未实现，可按真实插件需求在保持安全边界的前提下扩展。扩展前必须先完成管理端渲染、后端校验和保存兼容处理。

| 组件           | 建议 `type` | 用途                             |
| -------------- | ----------- | -------------------------------- |
| 日期           | `date`      | 有效期、开始日期                 |
| 日期时间       | `datetime`  | 准确到时间的有效期配置           |
| 时间           | `time`      | 定时规则                         |
| 标签输入       | `tags`      | IP 白名单、域名列表              |
| Key-Value 配置 | `key_value` | 请求头、扩展参数                 |
| 可重复数组     | `array`     | 多账号、多规则；需谨慎控制复杂度 |

### 通用字段结构

扩展后的 schema 建议统一采用以下字段。当前不支持的字段可以先忽略，不影响旧插件扫描。

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

| 字段                | 必填 | 说明                                                 |
| ------------------- | ---- | ---------------------------------------------------- |
| `key`               | 是   | 配置字段名，必须唯一                                 |
| `label` / `title`   | 是   | 后台展示名称                                         |
| `type`              | 是   | 组件类型                                             |
| `required`          | 否   | 是否必填                                             |
| `secret`            | 否   | 是否加密保存且不明文回显                             |
| `default` / `value` | 否   | 默认值                                               |
| `placeholder`       | 否   | 输入提示                                             |
| `description`       | 否   | 字段说明，纯文本，不支持 HTML 和 Markdown            |
| `options`           | 否   | `select`、`multi_select`、`radio`、`checkbox` 的选项 |
| `width`             | 否   | `full` 或 `half`，默认 `full`                        |
| `disabled`          | 否   | 是否禁用                                             |
| `visible`           | 否   | 是否显示                                             |
| `rules`             | 否   | 基础校验规则                                         |

### 选项字段格式

`options` 可继续使用简单键值对象：

```php
'options' => [
        'production' => '正式环境',
        'sandbox' => '测试环境',
],
```

也可扩展为数组对象：

```json
{
  "options": [
    { "label": "正式环境", "value": "production" },
    { "label": "测试环境", "value": "sandbox" }
  ]
}
```

### 分组和条件显示

配置项较多时，可扩展轻量分组：

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

条件显示只允许使用受控表达式，不允许 JavaScript：

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

首期条件操作符只考虑 `eq`、`neq`、`in`、`not_in`。

### 禁止组件和能力

插件配置 schema 禁止声明或变相实现以下能力：

| 禁止项                            | 原因                                             |
| --------------------------------- | ------------------------------------------------ |
| `html` / 任意 HTML                | XSS 风险高，破坏后台样式边界                     |
| `script` / 任意 JavaScript        | 安全风险不可控                                   |
| `iframe`                          | 权限、会话、点击劫持和数据边界复杂               |
| `upload`                          | 涉及文件安全、存储、权限和清理策略               |
| `rich_text` / Markdown 编辑器     | 存在 XSS 和样式污染风险                          |
| `custom_vue` / `remote_component` | 破坏构建、权限和审计边界                         |
| `action_button`                   | 会牵涉动作 API、权限、二次确认和审计，需单独设计 |

前端保存时只提交真实配置字段；`notice`、`divider`、`readonly`、`description`、`groups` 等展示类元信息不参与提交。

## 配置和密钥

插件配置保存在两张表：

- `integration_plugins`：安装状态、入口类、能力、配置 schema。
- `integration_plugin_configs`：非敏感配置和加密敏感配置。

敏感字段规则：

- `secret=true` 的字段写入 `secret_json`，通过 Laravel `Crypt` 加密。
- 后台详情不回传真实密钥，只回传 `has_secret_values`。
- 空提交表示保留旧密钥。
- 多 SMTP 的 `accounts` 字段支持脱敏预览和按索引保留密码。

## 绑定表与运行日志

插件安装记录只表达“系统识别到了哪个插件”，业务场景选择必须通过绑定表表达，不再通过 `settings` 中的旧 driver/provider key 反写。

| 表                                | 用途                                                                               |
| --------------------------------- | ---------------------------------------------------------------------------------- |
| `integration_plugin_bindings`     | 支付、实名、短信、邮件等全局或场景级默认插件绑定。                                 |
| `supplier_plugin_bindings`        | 供应商到上游插件账号、地址、密钥和运行环境的绑定。                                 |
| `product_upstream_bindings`       | 商品到上游商品 ID、配置模板和开通策略的绑定。                                      |
| `service_upstream_bindings`       | 服务实例到上游实例 ID、供应商绑定和状态快照的绑定。                                |
| `integration_plugin_runtime_logs` | 每次插件 action 的 domain、plugin、binding、actor、耗时、状态和脱敏请求/响应摘要。 |

运行规则：

- 启用插件只更新 `integration_plugins` 状态；选择“哪个业务场景使用哪个插件”写入绑定表。
- 插件全局配置进入 `integration_plugin_configs`，绑定级覆盖配置进入对应绑定表，二者不能互相反写。
- 支付、通知、上游等平台服务必须从绑定解析器读取插件，不再新增 `selection_setting`、`syncLegacySettings()` 或 settings fallback。
- 插件运行日志由 `PluginRuntimeRegistry::execute()` 统一写入，插件内部不要自建业务运行日志表。
- 敏感字段只允许进入加密列或脱敏摘要，禁止写入 runtime log 的明文字段。

## 定时任务与调度 Hooks

插件需要定时能力时只能通过插件清单接入平台调度，不要在插件里注册 Laravel `Schedule`、系统级 Cron、全局中间件或系统级 API 路由。生产环境仍只保留宝塔每分钟执行一次 `php artisan schedule:run`；`backend/routes/console.php` 每分钟驱动心跳，具体业务任务仍由 15 分钟槽位去重，并由 `HeartbeatTaskRegistry` 和插件 provider 发现。

插件有两种接入方式：

| 方式             | 清单字段                     | 适用场景                                               | 运行入口                                              |
| ---------------- | ---------------------------- | ------------------------------------------------------ | ----------------------------------------------------- |
| 监听调度 Hook    | `info.extra.schedule_hooks`  | 轻量扩展、跟随内置任务前后置逻辑、自定义插件 hook 监听 | `ScheduleHookService`                                 |
| 注册独立定时任务 | `info.extra.scheduled_tasks` | 插件自己的周期性同步、刷新、清理、巡检                 | `PluginScheduledTaskProvider` → `RunHeartbeatTaskJob` |

### 方式一：监听调度 Hook

在 `config.php` 的 `info.extra.schedule_hooks` 中声明 hook 名和监听器类：

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

监听器放在插件 `lib/`、`logic/` 或 `controller/` 下，推荐实现 `App\Services\Automation\Contracts\ScheduleHook`：

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

支持的监听器写法与 `backend/config/schedule_hooks.php` 一致：

- `ExampleScheduleHook::class`
- `[ExampleScheduleHook::class, 'handle']`
- `['class' => ExampleScheduleHook::class, 'method' => 'handle']`
- 可调用对象或闭包（不建议插件清单里用闭包，跨进程序列化和可读性都差）

当前内置 hook 名：

| 常量                                                    | 实际值                        | 触发语义                                             |
| ------------------------------------------------------- | ----------------------------- | ---------------------------------------------------- |
| `ScheduleHookService::HOOK_BEFORE_CRON`                 | `before_cron`                 | 每个被记录的调度任务执行前                           |
| `ScheduleHookService::HOOK_AFTER_CRON`                  | `after_cron`                  | 每个被记录的调度任务成功或失败后                     |
| `ScheduleHookService::HOOK_TASK_BEFORE`                 | `task.before`                 | 单个任务执行前，带 `task_key` / `task_name`          |
| `ScheduleHookService::HOOK_TASK_AFTER`                  | `task.after`                  | 单个任务成功后，带 `summary`                         |
| `ScheduleHookService::HOOK_TASK_FAILED`                 | `task.failed`                 | 单个任务失败后，带异常摘要                           |
| `ScheduleHookService::HOOK_EVERY_MINUTE`                | `tick.every_minute`           | 兼容旧命名，当前按 15 分钟心跳触发                   |
| `ScheduleHookService::HOOK_EVERY_FIVE_MINUTES`          | `tick.every_five_minutes`     | 兼容旧命名，当前按 15 分钟心跳触发                   |
| `ScheduleHookService::HOOK_HOURLY`                      | `tick.hourly`                 | 每小时 hook                                          |
| `ScheduleHookService::HOOK_DAILY`                       | `tick.daily`                  | 每日 hook                                            |
| `ScheduleHookService::HOOK_BEFORE_DAILY_CRON`           | `before_daily_cron`           | 旧系统每日前置兼容 hook                              |
| `ScheduleHookService::HOOK_AFTER_DAILY_CRON`            | `after_daily_cron`            | 旧系统每日后置兼容 hook                              |
| `ScheduleHookService::HOOK_AFTER_FIVE_MINUTE_CRON`      | `after_five_minute_cron`      | 旧系统五分钟后置兼容 hook，当前不代表真实 5 分钟粒度 |
| `ScheduleHookService::HOOK_AFTER_HALF_HOUR_MINUTE_CRON` | `after_half_hour_minute_cron` | 旧系统半小时后置兼容 hook                            |

Hook 失败只会写警告日志，不会中断调度主流程。监听器必须自己控制幂等、限流和敏感字段脱敏。

**声明频率与有效频率**：`tick.every_minute`、`tick.every_five_minutes`、`after_five_minute_cron` 等名称只表示“兼容旧命名的声明频率”，平台真实执行粒度为 15 分钟槽位，错过槽位不自动补跑。调度总览（`GET /api/v2/admin/schedules/overview`）对每个任务同时输出 `declared_cadence`（任务声明频率，未声明为 `null`）与 `effective_cadence`（按 15 分钟槽位推断的真实频率，如 `15分钟`、`60分钟`、`cron 0 3 * * *`）。插件不得把兼容名称当作真实 1/5 分钟执行依据；若业务 SLA 需要低于 15 分钟的独立执行，需要另立调度入口方案，不能直接改 Hook 频率。

### 方式二：注册独立定时任务

插件自己的周期任务通过 `info.extra.scheduled_tasks` 声明任务类：

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

任务类必须实现 `App\Services\Automation\Heartbeat\Contracts\ScheduledTask`：

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

触发规则：

- `ScheduleRule::everyTicks(1)`：每个 15 分钟心跳执行一次。
- `ScheduleRule::everyTicks(4)`：每 4 个心跳执行一次，约 1 小时。
- `ScheduleRule::cron('0 3 * * *')`：按 cron 表达式匹配心跳槽位；分钟只能使用 `00`、`15`、`30` 或 `45`。
- `ScheduleRule::automation(...)`：仅在复用系统自动化配置模式时使用，普通插件优先用 `everyTicks()` 或 `cron()`。

运行规则：

- 只有“已启用”的插件会被 `PluginScheduledTaskProvider` 扫描。
- 任务 `key()` 在全局调度注册表中必须唯一，启用后不要改名；如需改名必须提供受控历史数据迁移。
- `key()` 和 `queue()` 必须返回无首尾空格的规范值；`queue()` 必须是已配置且由 Worker 消费的业务队列或 `TURAIDC_SCHEDULE_QUEUE`（默认 `automation`），不能使用未消费的任意队列。
- 任务通过 `RunHeartbeatTaskJob` 执行，带队列、重试和 `WithoutOverlapping` 互斥；`lockTtlSeconds()` 必须至少比 `timeout()` 多 60 秒，队列 `retry_after` 也必须留出安全余量。
- 插件安装/启用和运行时注册都会校验任务契约；单个坏任务或坏清单只会被隔离并记录，不得阻断系统任务。调度 Hook 的监听方法必须为可调用的 `public` 方法。
- 心跳只处理当前 15 分钟槽位；错过的槽位默认不自动回放，需由任务自身的幂等补偿逻辑或管理端手动触发恢复。
- `handle()` 只返回可记录的摘要数组，不返回第三方原始响应、token、密钥或大对象。
- 需要在任务内部拆分扩展点时，可以让任务调用 `ScheduleHookService::run('plugins.{slug}.{action}', $context)`，再由 `extra.schedule_hooks` 注册监听器。`demo_style` 和 `zjmf_finance` 插件已按这个模式实现。

### 调试和验证

开发插件调度能力时优先跑最小测试：

```bash
cd backend
php artisan test tests/Unit/ScheduleHookServiceTest.php tests/Feature/PluginSimulationTest.php
```

本地手动触发一次心跳：

```bash
cd backend
php artisan scheduler:heartbeat --at="2026-07-08 03:15:00"
```

查看 Laravel 当前唯一调度源：

```bash
cd backend
php artisan schedule:list
```

不要用 `php artisan serve` 替代项目的 `php artisan app:serve`；需要联调时按仓库启动指南使用 `app:serve` 或 `app:serve --with-schedule`。

## 生命周期

1. 上传插件目录到固定能力域目录。
2. 后台“插件管理”点击扫描。
3. 点击安装，后端校验 `config.php`、目录一致性、入口类和 `execute()` 方法。
4. 后台填写配置。
5. 点击启用，后端校验必填配置并更新插件状态。
6. 业务运行时从 `PluginRuntimeRegistry` 执行插件动作，或通过平台 adapter 注册为支付、实名、短信、邮件、上游等内部契约。

安装和启用时会额外校验 `info.extra.scheduled_tasks` 和 `info.extra.schedule_hooks` 中声明的类是否存在、是否实现对应契约，坏声明会在这一步直接报错，而不是等到心跳运行时静默跳过。

`demo_*` 插件不在管理端列表展示，也不允许通过管理端安装；开发调试请直接用 `PluginInstaller` 或测试辅助方法。

### 卸载

卸载会硬删 `integration_plugin_bindings`、`supplier_plugin_bindings`、`product_upstream_bindings`、`service_upstream_bindings` 四张绑定表中该插件的记录，历史支付记录的 `payments.plugin_id` 也会被外键置空，操作不可逆。

因此后端默认拒绝仍被业务数据引用的插件卸载，并在错误信息里列出引用明细；管理端确认后带 `force=1` 再次请求才会真正执行。插件目录文件不会被删除。

## 安全边界

- 插件不能自行注册管理端菜单、权限、迁移和任意路由。
- 支付回调、订单履约、账务入账、服务开通仍由平台服务层控制。
- 第三方调用必须封装在插件服务或平台 driver 中，不放 Controller。
- 回调类可放 `controller/`，但平台路由必须统一做签名、幂等、日志和审计。
- 插件返回值必须由平台 adapter 转换为 DTO/Result，不把第三方原始结构直接透传到业务层。

## 运行时调用模型

插件入口统一接收如下结构：

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

插件返回结构：

```php
[
    'success' => true,
    'action' => 'payment.precreate',
    'message' => '',
    'data' => [],
]
```

平台侧负责：

- `PluginPaymentGateway`：把 `payment.*` action 转为支付 DTO。
- `PluginVerificationDriver`：把 `verification.*` action 转为实名结果。
- `PluginSmsDriver` / `PluginMailDriver`：把发送 action 转为通知结果。
- `PluginUpstreamDriver`：把 `server.resolve_capability` 转为上游能力对象。

## 当前真实插件包

以下插件直接放在 `backend/plugins/{能力域}/`，会像普通插件一样被后台扫描到。`demo_*` 用于开发研究，不建议在生产环境启用；真实插件启用前必须完成配置、测试和回调边界确认。

| 能力域        | 插件目录                                          | 说明                            |
| ------------- | ------------------------------------------------- | ------------------------------- |
| 支付渠道      | `backend/plugins/gateways/ali_pay`                | 支付宝当面付真实支付插件        |
| 支付渠道      | `backend/plugins/gateways/demo_pay`               | 模拟支付网关                    |
| 支付渠道      | `backend/plugins/gateways/yi_pay`                 | 易支付插件                      |
| 实名认证      | `backend/plugins/certification/stay33`            | Stay33 实名认证插件             |
| 实名认证      | `backend/plugins/certification/baidu_face`        | 百度人脸实名认证插件            |
| 实名认证      | `backend/plugins/certification/demo_verification` | 模拟实名插件                    |
| 人机验证      | `backend/plugins/captcha/geetest`                 | GeeTest 验证码插件              |
| 人机验证      | `backend/plugins/captcha/vaptcha`                 | Vaptcha 验证码插件              |
| 邮件发送      | `backend/plugins/mail/multi_smtp_round_robin`     | 多 SMTP 轮询邮件插件            |
| 邮件发送      | `backend/plugins/mail/smtp`                       | 单 SMTP 邮件插件                |
| 邮件发送      | `backend/plugins/mail/demo_mail`                  | 模拟邮件插件                    |
| 短信发送      | `backend/plugins/sms/aliyun`                      | 阿里云短信插件                  |
| 短信发送      | `backend/plugins/sms/stay33`                      | Stay33 短信插件                 |
| 短信发送      | `backend/plugins/sms/demo_sms`                    | 模拟短信插件                    |
| 上游开通/控制 | `backend/plugins/servers/zjmf_finance`            | ZJMF 财务上游插件               |
| 上游开通/控制 | `backend/plugins/servers/kanghostx`               | 康乐虚拟主机插件                |
| 上游开通/控制 | `backend/plugins/servers/demo_servers`            | 模拟上游插件                    |
| 功能扩展      | `backend/plugins/addons/demo_style`               | Addon、调度任务和 hook 示例插件 |

每个 demo 包都包含：

- `config.php`
- `{Name}Plugin.php`
- `lib/` 或 `logic/`
- 可选 `controller/`
- `README.md`

## 当前插件说明文档

- [支付宝当面付插件 demo](./demo-ali-pay.md)
- [康乐虚拟主机插件](../../../../backend/plugins/servers/kanghostx/README.md)

其他插件以各自目录内 `README.md` / `DEVELOPMENT.md` 和当前代码为准；不要在导航中保留不存在的说明文档链接。

## 验证命令

只改插件后端逻辑：

```bash
cd backend
php artisan test tests/Feature/AdminIntegrationPluginControllerTest.php tests/Feature/PluginRuntimeRegistryIntegrationTest.php
```

涉及插件定时任务或调度 Hook：

```bash
cd backend
php artisan test tests/Unit/ScheduleHookServiceTest.php tests/Feature/PluginSimulationTest.php
```

涉及邮件插件：

```bash
cd backend
php artisan test tests/Feature/MultiSmtpRoundRobinPluginTest.php
```

涉及管理端页面：

```bash
cd frontend-admin-v3
npm.cmd run build
```
