# turaidc 插件架构说明

- 文档性质：已落地架构说明 / 后续扩展约束（需按运行时代码复核）
- 状态：needs-review
- 日期：2026-07-22
- 范围：支付、实名认证、验证码、邮件、短信、上游开通/控制与功能扩展插件化

## 1. 背景

参考 `zjmf-manger-decoded-main` 后，ZJMF 的插件化主要依赖目录扫描、数据库安装状态、动态类名/函数名调用、Hook 分发和插件配置 JSON。该方案扩展速度快，但在 turaidc 当前 Laravel 架构中不能直接照搬，原因是：

- turaidc 已经有领域服务、FormRequest、Resource、Service/Driver、队列调度、日志审计和幂等边界。
- 支付、账务、订单、服务开通不能由插件直接改状态，否则会破坏财务一致性。
- 管理端允许安装插件，但不应允许插件随意注入路由、菜单、权限、迁移或任意 Controller。
- 当前项目已经有 `PaymentGatewayManager`、`ProviderResolver`、`PluginRuntimeRegistry` 和各能力域 adapter，应在此基础上扩展。

因此本方案采用“固定目录上传 + 管理端安装 + 受控驱动注册”的插件机制。

## 2. 已确认决策

1. 插件开发完成后直接上传到对应目录，由管理员端扫描、安装、配置、启停。
2. 支付、实名、邮件、短信、上游服务器均通过插件目录承载真实或 demo 实现。
3. 插件入口统一提供 `execute(array $request): array`，平台 adapter 负责转换到内部契约。
4. 插件配置敏感字段加密保存，后台不明文回显。
5. 支付入账、账单、订单、服务状态、实名状态仍由平台核心服务维护，插件不直接改核心状态。

## 3. 目标

- 支持管理员在后台扫描、安装、配置、启停插件。
- 插件按领域隔离：支付、实名、邮件、短信、上游。
- 平台保留核心状态机：支付入账、订单、账单、用户实名状态、服务状态、开通重试、日志审计。
- 插件只提供外部能力：调用第三方、验签、查询、发送、开通、控制。
- 插件配置支持敏感字段加密和“有值但不回显”。
- 不做插件市场和远程下载。

## 4. 非目标

- 不做在线插件市场。
- 不做后台上传 zip 自动解压执行。
- 不允许插件自行创建管理端菜单、权限、路由和数据库表。
- 不允许插件绕过平台的支付回调、订单履约、服务开通和日志体系。
- 不重写现有ZJMF 财务业务链路，只把它纳入插件安装/配置/展示体系。

## 5. 插件目录规范

固定目录如下：

```text
backend/plugins/gateways/
backend/plugins/certification/
backend/plugins/captcha/
backend/plugins/mail/
backend/plugins/sms/
backend/plugins/servers/
backend/plugins/addons/
```

每个插件一个目录，例如：

```text
backend/plugins/gateways/ali_pay/
backend/plugins/certification/stay33/
backend/plugins/mail/multi_smtp_round_robin/
backend/plugins/servers/zjmf_finance/
```

插件目录必须包含：

```text
config.php
{Name}Plugin.php
```

插件目录可以包含：

```text
controller/   # 插件内部控制/回调适配类；不自动注册 Laravel 路由
lib/          # SDK、协议封装、网关适配
logic/        # 较重的插件业务逻辑
vendor/       # 插件自带 Composer 依赖，优先加载 vendor/autoload.php
*.png         # 管理端图标
```

`config.php` 同时承担插件元信息和配置表单定义，入口类使用 `{Name}Plugin.php` 命名。示例：

```php
return [
    'info' => [
        'domain' => 'payment',
        'slug' => 'ali_pay',
        'key' => 'alipay',
        'name' => '支付宝当面付',
        'version' => '1.0.0',
        'entry' => \TuraIDC\Plugins\Gateways\AliPay\AliPayPlugin::class,
        'capabilities' => ['precreate', 'query', 'refund', 'notify_verify'],
    ],
    'config' => [
        'app_id' => ['title' => 'App ID', 'type' => 'text', 'required' => true],
        'private_key' => ['title' => '应用私钥', 'type' => 'textarea', 'required' => true, 'secret' => true],
        'alipay_public_key' => ['title' => '支付宝公钥', 'type' => 'textarea', 'required' => true, 'secret' => true],
    ],
];
```

## 6. 插件安装流程

后台安装流程：

1. 管理端请求扫描插件目录。
2. 后端读取 `config.php`，校验目录、domain、slug、key、entry。
3. 校验插件目录必须在固定目录下，禁止 `..`、软链逃逸和跨目录加载。
4. 校验 PHP 类存在且提供 `execute(array $request): array`。
5. 写入插件元数据表，状态为“已安装/未启用”。
6. 管理员填写配置并保存。
7. 启用插件后，由 `PluginRuntimeRegistry` 和领域 adapter 暴露给平台业务。

安装阶段只登记元数据和配置，不执行插件自带迁移，不注册任意路由，不注入菜单。

## 7. 数据表设计

建议新增两张核心表。

### 7.1 `integration_plugins`

保存插件安装状态和元数据。

| 字段                 | 说明                                                                                                            |
| -------------------- | --------------------------------------------------------------------------------------------------------------- |
| `id`                 | 主键                                                                                                            |
| `domain`             | `payment` / `verification` / `mail` / `sms` / `upstream`                                                        |
| `slug`               | 插件目录名，如 `ali_pay`                                                                                        |
| `key`                | 业务 key，如支付继续用 `alipay`                                                                                 |
| `name`               | 展示名称                                                                                                        |
| `version`            | 插件版本                                                                                                        |
| `provider_class`     | 插件目录内声明的受控 Provider；只能由插件运行时按 manifest 加载，不得写入系统级配置或注册全局路由、调度、中间件 |
| `entry_class`        | 插件入口类                                                                                                      |
| `capabilities_json`  | 能力声明                                                                                                        |
| `config_schema_json` | 配置 schema                                                                                                     |
| `status`             | `0` 禁用，`1` 启用                                                                                              |
| `installed_at`       | 安装时间                                                                                                        |
| `updated_at`         | 更新时间                                                                                                        |

唯一约束：

- `domain + slug` 唯一
- `domain + key` 在单实例领域唯一；上游可按业务需要允许实例表扩展

### 7.2 `integration_plugin_configs`

保存插件配置。

| 字段              | 说明                                   |
| ----------------- | -------------------------------------- |
| `id`              | 主键                                   |
| `plugin_id`       | 插件 ID                                |
| `config_json`     | 非敏感配置                             |
| `secret_json`     | 敏感配置，加密保存                     |
| `has_secret_json` | 哪些敏感字段已有值，用于管理端回显状态 |
| `updated_by`      | 操作管理员                             |
| `updated_at`      | 更新时间                               |

敏感字段规则：

- 管理端不回显真实 secret。
- 空提交表示保留旧值。
- 只有用户明确填写新值时才覆盖。

## 8. 公共后端模块

当前已落地：

```text
backend/app/Services/Integrations/Plugins/PluginManifest.php
backend/app/Services/Integrations/Plugins/PluginScanner.php
backend/app/Services/Integrations/Plugins/PluginInstaller.php
backend/app/Services/Integrations/Plugins/PluginConfigRepository.php
backend/app/Services/Integrations/Plugins/PluginRuntimeRegistry.php
backend/app/Services/Integrations/Plugins/Adapters/
backend/app/Models/IntegrationPlugin.php
backend/app/Models/IntegrationPluginConfig.php
```

公共插件入口约定：

```php
class ExamplePlugin
{
    public function execute(array $request): array
    {
        // 根据 $request['action'] 分发插件能力。
    }
}
```

各领域继续使用独立平台契约，不做万能业务接口；插件入口统一由平台 adapter 包装为对应契约。

## 9. 支付插件化方案

当前真实支付插件为支付宝当面付，demo 支付插件仅用于本地开发和测试。

### 9.1 key 约定

- 插件目录：`gateways/ali_pay`
- 插件 slug：`ali_pay`
- 支付业务 key：`alipay`
- `payments.gateway` 继续写 `alipay`

不得把历史支付数据从 `alipay` 改成 `ali_pay`。

### 9.2 职责边界

支付插件负责：

- `precreate`
- `query`
- `refund`
- `verifyNotify`
- `buildNotifyResponse`

平台 `PaymentService` 负责：

- 创建和更新 `payments`
- 金额校验
- 商户号校验
- 回调幂等锁
- 入账
- 关闭其他待支付记录
- 触发订单履约和服务开通
- 记录支付回调和审计日志

### 9.3 回调

支付宝专用回调路由：

```text
POST /api/v2/client/payment/alipay/notify
```

通用路由：

```text
POST /api/v2/client/payment/notify/{gateway}
```

通用路由不能绑定支付宝专用中间件。应改为网关中立的回调入口，由 `PaymentGatewayInterface::verifyNotify()` 做实际验签。

## 10. 实名认证插件化方案

公共接口不设置全局收费。插件可通过配置 schema 声明收费：

```json
{
  "charge_enabled": true,
  "amount": "2.00",
  "free_times": 1
}
```

实名插件负责：

- 初始化认证
- 生成认证链接或二维码
- 查询认证状态
- 回调验签
- 返回外部认证结果

平台负责：

- 用户认证状态
- 认证申请记录
- 认证历史记录
- 身份证/企业信息去重
- 插件收费账单创建与支付后继续认证
- 认证通过后的解封、购买限制解除、通知

建议扩展实名契约：

```php
interface VerificationDriver
{
    public function key(): string;

    public function label(): string;

    public function supportsPersonal(): bool;

    public function supportsCompany(): bool;

    public function customFields(string $type): array;

    public function initialize(VerificationInitializeRequest $request): VerificationInitializeResult;

    public function generateScanUrl(string $certifyId): VerificationScanUrlResult;

    public function queryStatus(string $certifyId): VerificationStatusResult;
}
```

## 11. 邮件插件化方案

默认保留单 SMTP，作为平台内置驱动。

新增邮件插件：

```text
backend/plugins/mail/multi_smtp_round_robin/
```

插件能力：

- 多 SMTP 账号配置
- 轮询发送
- 失败账号冷却
- 可选按权重发送
- 每次发送记录实际账号标识，但不记录密码

邮件插件只负责最终发送。`NotificationService` 继续负责：

- 模板查找
- 模板变量渲染
- 邮件 HTML 包装
- 验证码内容脱敏
- `notification_logs` / `email_logs` 写入
- 发送失败状态更新

新增邮件驱动契约：

```php
interface MailDriver
{
    public function key(): string;

    public function label(): string;

    public function send(MailSendRequest $request): MailSendResult;
}
```

## 12. 短信插件化方案

短信已纳入插件体系，当前包含阿里云短信插件和 demo 短信插件。

后续短信插件负责：

- 模板短信发送
- 国内/国际能力声明
- 返回请求 ID
- 返回供应商错误码

平台继续负责：

- 验证码生成
- 频控
- 模板变量
- 日志脱敏
- `notification_logs` / `sms_logs`
- 用户关闭通知偏好

## 13. 上游插件化方案

当前真实上游插件为ZJMF 财务，demo 上游插件仅用于本地开发和测试。

### 13.1 key 约定

- 管理端 domain：`upstream`
- 插件目录：`backend/plugins/servers/zjmf_finance/`
- 插件 slug：`zjmf_finance`
- provider key：`zjmf_finance_api`
- 管理端供应商类型只展示ZJMF 财务

不得把 `zjmf_finance_api` 归一化或别名成 `hosting_panel_api`。

当前ZJMF 财务 driver、adapter、传输封装和能力 service 均位于插件目录：

```text
backend/plugins/servers/zjmf_finance/
├── ZjmfFinancePlugin.php
├── config.php
├── logic/ZjmfFinance.php
└── lib/
    ├── ZjmfFinanceDriver.php
    ├── ZjmfFinanceAdapter.php
    ├── ZjmfFinanceTransport.php
    ├── ZjmfAuthManager.php
    ├── ZjmfCatalogService.php
    ├── ZjmfProvisionService.php
    ├── ZjmfRenewService.php
    ├── ZjmfStatusService.php
    ├── ZjmfConsoleService.php
    ├── ZjmfNetworkService.php
    └── ZjmfSecurityService.php
```

### 13.2 职责边界

上游插件负责：

- 登录/鉴权
- JWT / Token 缓存、刷新、401 自动重登
- 拉取商品
- 拉取和归一化价格、库存、配置项、配置项价格
- 自动开通
- 续费
- 查询余额
- 查询服务状态
- VNC、电源、重装系统、重置密码、NAT、安全组、监控、流量包、升降级等控制台和网络动作
- 第三方响应字段归一化，只返回平台允许消费的字段

平台负责：

- 认证和权限
- 商品本地绑定
- 订单和账单
- 支付和余额
- 服务生命周期
- 开通幂等
- 失败重试
- 续费履约
- 操作日志
- 权限校验
- 用户服务归属校验和控制台操作限流

插件不得直接修改订单、账单、支付、余额或服务实例核心状态。平台业务 Service 不直接拼ZJMF 财务请求路径、请求参数或响应字段；新增ZJMF动作时应先扩展插件内部 service，再在 `ZjmfFinanceAdapter` 上显式声明方法。禁止新增或依赖 `ZjmfFinanceAdapter::__call()` 动态转发。

继续沿用当前 capability 结构：

```text
ProvidesConsoleCatalog
ProvidesProvisioning
ProvidesRenewal
ProvidesStatusSync
ProvidesConsoleRuntime
ProvidesConsoleAccess
ProvidesConsoleSecurity
ProvidesScheduledAuthRefresh
```

## 14. 管理端 API 设计

新增管理端接口统一放在 `routes/v2-admin.php`，并按现有 `integration_plugins` 权限码分组；插件自身不得注册系统级 API 路由。

```text
GET    /api/v2/admin/integration-plugins
POST   /api/v2/admin/integration-plugin-scans
POST   /api/v2/admin/integration-plugins
GET    /api/v2/admin/integration-plugins/{plugin}
PUT    /api/v2/admin/integration-plugins/{plugin}/config
PATCH  /api/v2/admin/integration-plugins/{plugin}/status
POST   /api/v2/admin/integration-plugins/{plugin}/tasks
```

列表响应遵循现有分页结构：

```json
{
  "code": 0,
  "message": "成功",
  "data": {
    "list": [],
    "total": 0,
    "page": 1,
    "page_size": 20
  }
}
```

配置详情响应必须包含：

- 配置 schema
- 当前非敏感配置
- 敏感字段 `has_value`
- 插件启停状态
- 插件能力
- 健康检查结果

## 15. 安全要求

- 插件扫描只允许固定目录。
- 禁止路径穿越、软链逃逸、隐藏文件作为插件入口。
- `config.php` 必须做结构校验，插件目录必须和 `domain`、`slug` 声明一致。
- 插件入口类必须提供 `execute(array $request): array`；平台 adapter 负责实现领域契约。
- 插件不能声明任意路由、菜单、权限和迁移。
- 敏感配置加密存储，管理端不回显。
- 插件启用、禁用、配置修改、健康检查都写管理员操作日志。
- 第三方返回内容按不可信数据处理，必须经过 DTO/Result 归一化。
- 支付回调原始参数写入前必须脱敏。
- 邮件和短信验证码内容必须脱敏。

## 16. 当前落地状态与后续扩展

### 已落地：插件基础设施

- 插件表和配置表已落地。
- manifest 扫描、校验、安装、启停、配置保存已落地。
- 后台插件管理 API 与管理端页面已落地。
- 基础单元测试和 Feature 测试已覆盖。

### 已落地：支付插件化

- 支付宝当面付已包装为 `gateways/ali_pay` 插件。
- 保持业务 key 为 `alipay`。
- 通用支付回调走平台入口与插件验签，不由插件直接改账务状态。

### 已落地：实名插件化

- Stay33 和 demo 实名插件已纳入插件体系。
- 支持插件级收费配置。
- 支持个人/企业能力声明。

### 已落地：邮件插件化

- 已抽出 `MailDriver`。
- 已新增多 SMTP 轮询插件和 demo 邮件插件。
- 日志记录实际发送 driver 和账号标识。

### 已落地：上游ZJMF 财务插件化

- 将ZJMF 财务能力完整收敛到 `backend/plugins/servers/zjmf_finance/` upstream 插件。
- 管理端供应商类型只展示 `zjmf_finance_api`。
- `ProviderResolver` 保持按真实 provider key 解析能力，不把 `zjmf_finance_api` 归一化为 `hosting_panel_api`。
- `ZjmfFinanceDriver`、`ZjmfFinanceAdapter` 和 ZJMF 能力 service 位于插件 `lib/`，平台业务 Service 只调用 capability 暴露的高层方法。
- `HostingPanelApiTransport` 只作为底层 HTTP、DNS/TLS、超时和脱敏日志等共享传输辅助，不作为ZJMF 财务业务入口。
- 禁止新增 `ZjmfFinanceAdapter::__call()` 依赖。

### 已落地：短信纳入插件体系

- 阿里云短信和 demo 短信插件已纳入插件体系。
- 后续扩展国内/国际/营销能力时继续使用插件目录 + 平台 adapter。

## 17. 验证门禁

每个阶段至少执行：

```bash
cd backend
php artisan test
```

涉及格式或大范围后端改动时增加：

```bash
cd backend
php vendor\bin\pint --test
```

涉及管理端页面时执行：

```bash
cd frontend-admin-v3
npm.cmd run build
```

支付阶段必须覆盖：

- 支付宝预下单
- 支付宝回调验签失败
- 支付宝回调金额不匹配
- 重复回调幂等
- 已支付账单重复回调转余额或拦截

实名阶段必须覆盖：

- 插件未收费直接认证
- 插件收费先生成账单
- 支付后继续认证
- 查询成功更新用户实名状态
- 查询失败记录失败原因

邮件阶段必须覆盖：

- 默认 SMTP 发送
- 多 SMTP 轮询选择
- 单账号失败冷却
- 验证码邮件日志脱敏

上游阶段必须覆盖：

- `zjmf_finance_api` 解析不被别名化
- 插件安装/启用后 registry 能解析 `zjmf_finance_api`
- 登录缓存、刷新、401 自动重登
- 商品拉取、价格、库存和配置项归一化
- 自动开通
- 续费和状态同步
- VNC、控制台动作、NAT/安全组、流量包和升降级代表用例
- 幂等回查
- 失败重试

## 18. 后续扩展清单

新增或调整插件时至少满足：

1. 后台能扫描、安装、配置、启停。
2. 插件入口实现 `execute()`，不直接实现平台状态机。
3. 平台 adapter 能把插件结果转换为内部 DTO/Result。
4. 敏感配置加密保存，空值保留旧密钥。
5. 回调、支付、实名、上游控制动作走平台统一鉴权、签名、幂等、日志和审计。
6. 补充最小 Feature/Unit 测试。
