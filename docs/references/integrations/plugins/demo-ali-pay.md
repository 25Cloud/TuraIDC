# Demo：支付宝当面付支付插件

当前实现路径：

```text
backend/plugins/gateways/ali_pay/
├── AliPayPlugin.php
├── config.php
├── AliPay.png
├── controller/
│   └── IndexController.php
└── lib/
    ├── AlipayClient.php
    └── AlipayService.php
```

## 适用场景

支付插件用于第三方真实资金流入，例如用户充值、商品订单支付宝付款。余额支付、管理员手动开通和免费订单不创建 Payment 记录。

## config.php demo

```php
<?php

declare(strict_types=1);

use TuraIDC\Plugins\Gateways\AliPay\AliPayPlugin;

return [
    'info' => [
        'domain' => 'payment',
        'slug' => 'ali_pay',
        'key' => 'alipay',
        'name' => '支付宝当面付',
        'version' => '1.0.0',
        'entry' => AliPayPlugin::class,
        'capabilities' => ['precreate', 'query', 'refund', 'notify_verify'],
        'extra' => [
            'legacy_settings' => [
                'group' => 'payment',
                'map' => [
                    'alipay_enabled' => 'alipay_enabled',
                    'app_id' => 'alipay_app_id',
                    'private_key' => 'alipay_private_key',
                    'alipay_public_key' => 'alipay_public_key',
                ],
            ],
        ],
    ],
    'config' => [
        'alipay_enabled' => ['title' => '启用', 'type' => 'switch', 'value' => true, 'required' => true],
        'app_id' => ['title' => 'App ID', 'type' => 'text', 'value' => '', 'required' => true],
        'private_key' => ['title' => '应用私钥', 'type' => 'textarea', 'value' => '', 'required' => true, 'secret' => true],
        'alipay_public_key' => ['title' => '支付宝公钥', 'type' => 'textarea', 'value' => '', 'required' => true, 'secret' => true],
    ],
];
```

关键约定：

- `slug=ali_pay` 必须和目录名一致。
- `key=alipay` 必须保持历史业务 key，不要改为 `ali_pay`，否则历史 `payments.gateway=alipay` 无法兼容。
- 私钥、公钥必须 `secret=true`。

## 入口类 demo

```php
<?php

declare(strict_types=1);

namespace TuraIDC\Plugins\Gateways\AliPay;

use TuraIDC\Plugins\Gateways\AliPay\Lib\AlipayService;

class AliPayPlugin extends AlipayService {}
```

入口类可以很薄，真实能力放在 `lib/AlipayService.php`，支付宝 SDK/签名、预下单、查询、退款细节放在 `lib/AlipayClient.php`。

## 业务类 demo

```php
<?php

declare(strict_types=1);

namespace TuraIDC\Plugins\Gateways\AliPay\Lib;

class AlipayService
{
    private ?AlipayClient $client = null;

    public function key(): string
    {
        return 'alipay';
    }

    public function name(): string
    {
        return '支付宝当面付';
    }

    public function execute(array $request): array
    {
        $action = (string) ($request['action'] ?? '');
        $payload = is_array($request['payload'] ?? null) ? $request['payload'] : [];
        $config = is_array($request['config'] ?? null) ? $request['config'] : [];

        return match ($action) {
            'payment.precreate' => $this->success($action, $this->client($config)->precreate(/* ... */)),
            'payment.query' => $this->success($action, $this->client($config)->query(/* ... */)),
            'payment.refund' => $this->success($action, $this->client($config)->refund(/* ... */)),
            'payment.verify_notify' => $this->success($action, ['verified' => $this->client($config)->verifyNotify($payload)]),
            default => ['success' => false, 'action' => $action, 'message' => 'Unsupported plugin action', 'data' => []],
        };
    }

    private function client(array $config): AlipayClient
    {
        return $this->client ??= new AlipayClient($config);
    }

    private function success(string $action, array $data): array
    {
        return ['success' => true, 'action' => $action, 'data' => $data];
    }
}
```

## 支付边界

插件负责：

- 支付宝预下单。
- 支付宝交易查询。
- 支付宝退款。
- 支付宝异步通知验签。
- 构造支付宝要求的回调响应。

平台负责：

- 通过 `PluginPaymentGateway` 把 `payment.*` action 转为平台 `PaymentGatewayInterface` 调用。
- 创建和更新 `payments`。
- 金额、商户号、订单号校验。
- 回调幂等、入账、订单履约。
- 关闭其它待支付记录。
- 审计日志和支付日志。

## 测试要点

- 插件能被扫描、安装、启用。
- 未配置私钥/公钥不能启用。
- 启用后 `PaymentGatewayManager` 优先注册插件网关。
- 支付回调必须通过平台统一入口处理，不能让插件 Controller 直接改账务状态。

推荐命令：

```bash
cd backend
php artisan test tests/Unit/PaymentGatewayRegistryTest.php tests/Feature/PluginRuntimeRegistryIntegrationTest.php
```
