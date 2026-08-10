# 支付插件开发文档

## 目标边界

支付域采用“系统记账编排 + 插件网关调用”的边界：

- 系统层负责订单、账单、Payment 状态、事务、幂等、审计字段和回调日志。
- 支付插件负责读取自身配置、调用第三方支付 API、验签回调、返回标准化结果。
- 插件不要直接修改 `payments`、订单、发票或余额表，也不要把第三方调用委托回 `app/Services/Integrations/Payments/Drivers/*`。

`demo_pay` 只模拟支付网关，用来演示插件契约；真实插件应在 `lib/` 内完成第三方 SDK/API 调用。

## 目录职责

- `config.php`：声明 `domain=payment`、`slug=demo_pay`、入口类、能力和配置项。
- `DemoPayPlugin.php`：插件入口类，继承具体服务实现。
- `lib/DemoPayService.php`：实现支付动作分发、下单、查询、退款和验签逻辑。
- `controller/IndexController.php`：插件内部回调适配器样例，只做验签和响应构建，不直接注册 Laravel 路由。
- `DEVELOPMENT.md`：当前支付域插件开发说明。

## 必须实现的动作

### `payment.is_enabled`

系统支付 manager 判断网关是否可用时调用。

返回：

```php
['enabled' => true]
```

### `payment.matches_merchant`

系统根据第三方商户号匹配支付网关时调用。

请求 `payload`：

- `merchant_id`：第三方回调或查询中携带的商户号，可为空。

返回：

```php
['matched' => true]
```

### `payment.precreate`

系统创建第三方支付单时调用。

请求 `payload`：

- `out_trade_no`：系统交易号。
- `amount`：支付金额。
- `subject`：支付标题。
- `timeout_express`：可选超时时间。

返回数据应包含：

- `qr_code`：扫码支付链接或二维码内容。
- `out_trade_no`：系统交易号。
- `raw`：脱敏后的第三方响应摘要。

### `payment.query`

系统主动查询第三方支付状态时调用。

返回数据应包含：

- `trade_status`：第三方交易状态。
- `trade_no`：第三方交易号。
- `out_trade_no`：系统交易号。
- `total_amount`：第三方确认金额。

### `payment.refund`

系统发起退款时调用。

请求 `payload`：

- `out_trade_no`：系统交易号。
- `refund_amount`：退款金额。
- `refund_reason`：退款原因。
- `trade_no`：可选第三方交易号。
- `out_request_no`：可选退款请求号。

返回数据应包含 `trade_no`、`out_trade_no`、`refund_fee`、`fund_change`。

### `payment.verify_notify`

系统处理支付回调前调用，插件只负责验签与标准化，不负责改系统业务状态。

返回：

```php
['verified' => true]
```

## 回调要求

- 回调入口必须经过系统签名/来源中间件或由系统回调控制器统一接入。
- 插件验签必须只依赖插件配置和回调 payload，不读取业务表。
- 系统负责幂等、Payment 状态推进和回调日志；插件只返回验签结果和必要的第三方字段。

## 配置约定

配置项在 `config.php` 中声明，敏感字段设置 `secret => true`。系统会负责加密保存和展示脱敏预览，插件通过 runtime 请求中的 `config` 读取已合并配置。

常见配置：

- `merchant_id`：第三方商户号。
- `secret_key` / `private_key`：第三方密钥，必须标记为敏感字段。
- `enabled`：插件内部启用开关，供 `payment.is_enabled` 返回。

## 开发真实支付插件

1. 复制 `demo_pay` 目录并修改 `slug`、`key`、命名空间和入口类。
2. 在 `config.php` 中声明真实支付网关配置。
3. 在 `lib/` 内实现第三方 SDK/API 客户端、签名、验签和错误映射。
4. 保持六个 `payment.*` 动作返回结构稳定。
5. 所有金额以系统传入金额为准，并校验第三方回调金额，异常交给系统流程处理。

## 验证

```bash
cd backend
php artisan test tests\Feature\PluginSimulationTest.php --filter=demo_pay
```
