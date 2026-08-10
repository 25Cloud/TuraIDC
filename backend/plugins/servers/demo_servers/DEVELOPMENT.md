# 上游服务插件开发文档

## 目标边界

servers 域采用“平台业务编排 + 插件上游协议”的边界：

- 平台层负责供应商配置、商品绑定、订单、账单、支付、余额、服务实例生命周期、开通幂等、续费履约、用户服务归属、控制台限流、权限和审计。
- 上游插件负责读取自身配置或供应商配置、调用上游 API、处理鉴权、把商品、开通、续费、状态和控制台结果标准化返回。
- 插件不要直接修改订单、账单、支付、余额或服务实例核心状态，也不要在 Controller 中直接发起上游 HTTP 调用。

`demo_servers` 只模拟上游能力，用来演示 upstream 插件契约；真实插件应在插件内部封装第三方 SDK/API、签名、鉴权缓存、错误映射和字段归一化。

## 目录职责

- `config.php`：声明 `domain=upstream`、`slug=demo_servers`、`key=demo_servers`、入口类、能力和配置项。
- `DemoServersPlugin.php`：插件入口类，继承具体逻辑实现。
- `logic/DemoServers.php`：实现 `UpstreamDriver`、`server.*` 控制动作和模拟 capability 方法。
- `DEVELOPMENT.md`：当前 servers 域插件开发说明。

## 必须实现的控制动作

### `server.metadata`

后台或运行时读取插件元信息时调用。

返回数据应包含：

- `key`：provider key。
- `label`：展示名。
- `capabilities`：支持的 capability class 列表。

### `server.supports`

运行时判断插件是否支持某个 capability。

请求 `payload`：

- `capability`：完整接口类名，如 `App\Services\Upstream\Contracts\ProvidesConsoleCatalog`。

返回：

```php
['supported' => true]
```

### `server.resolve_capability`

`ProviderRegistry` 通过该动作拿到真正的 capability 对象。

请求 `payload`：

- `capability`：完整接口类名。

返回：

```php
['resolved' => $capabilityObject]
```

### `server.health_check`

后台检测插件时调用。真实插件可在这里做轻量配置检查，但不要执行会创建资源、扣费或改变远端状态的动作。

## 常用 capability 方法

上游接口当前采用 marker interface 加显式方法约定。真实插件应按平台实际调用面实现以下代表方法。

### 商品目录

- `getProductCatalog(Supplier $supplier): array`
- `fetchRealConfigOptions(Supplier $supplier, int $productId): array`
- `fetchBatchProductConfigOptions(Supplier $supplier, array $productIds, int $chunkSize = 8): array`
- `fetchBatchProductStocks(Supplier $supplier, array $productIds, int $chunkSize = 8): array`
- `getProductConfigTemplate(Supplier $supplier, int $productId): array`

商品目录返回应包含 `groups` 和 `products`，商品字段至少包含 `id`、`name`、`type`、`type_label`、`billingcycle`、`product_price`、`stock`。

### 开通

- `provisionOrder(Order $order, Supplier $supplier, ?Service $existingService = null): array`
- `getProductProvisionConfig(Supplier $supplier, int $productId): array`

开通返回应包含 `requested_host`、`upstream_invoice_id`、`upstream_host_ids`、`upstream_host_id`、`host_detail`。插件只封装上游动作，平台仍负责本地服务创建、订单状态推进和失败重试。

### 续费

- `getHostRenewInfo(Supplier $supplier, int $hostId, ?string $billingCycle = null): array`
- `renewHost(Supplier $supplier, int $hostId, string $billingCycle): array`
- `setHostAutoRenew(Supplier $supplier, int $hostId, int $initiativeRenew): array`
- `renewServiceInvoice(Supplier $supplier, int $hostId, string $billingCycle): array`
- `recoverRenewInvoice(Supplier $supplier, int $hostId, int $upstreamInvoiceId): ?array`

续费动作必须幂等友好，不要由插件改本地账单或服务到期时间。

### 状态同步和控制台

- `syncServiceStatuses(Supplier $supplier, array $items, int $chunkSize = 10): array`
- `getHostDetail(Supplier $supplier, int $hostId, ?string $jwt = null): array`
- `getVncUrl(Supplier $supplier, int $hostId, ?string $jwt = null): array`
- `powerAction(Supplier $supplier, int $hostId, string $action, ?string $jwt = null): array`
- `getReinstallOptions(Supplier $supplier, int $hostId, ?string $jwt = null): array`
- `resetPassword(Supplier $supplier, int $hostId, string $password, ?string $jwt = null): array`
- `reinstall(Supplier $supplier, int $hostId, string $osId, ?string $jwt = null): array`

所有控制台动作必须先由平台完成用户身份、服务归属、权限、限流和服务状态检查，再进入插件。

## 配置约定

配置项在 `config.php` 中声明，敏感字段设置 `secret => true`。系统会负责加密保存和展示脱敏预览，插件通过 runtime 请求中的 `config` 读取已合并配置。

servers 域常见配置：

- `api_url`：上游 API 地址。
- `username` / `app_id`：上游账号或应用标识。
- `password` / `secret_key`：上游密钥，必须标记为敏感字段。
- `enabled`：插件内部启用开关。

ZJMF 财务真实插件的 API 地址、账号、密钥目前来自供应商配置；`demo_servers` 自身只演示插件配置和能力解析。

## 开发真实上游插件

1. 复制 `demo_servers` 目录并修改 `slug`、`key`、命名空间和入口类。
2. 在 `config.php` 中声明真实供应商需要的配置。
3. 在 `logic/` 或 `lib/` 内实现供应商 API 客户端、鉴权、重试、超时、TLS 校验、日志脱敏和错误映射。
4. 保持 `server.*` 控制动作返回结构稳定。
5. 按实际能力声明 capability，未实现的方法不要声明对应能力。
6. 第三方异常要转换为中文业务错误或受控失败结果，不要暴露密钥、JWT、密码、VNC token、完整请求体或第三方敏感响应。

## 验证

```bash
cd backend
php artisan test tests\Feature\PluginSimulationTest.php --filter=demo_servers
```
