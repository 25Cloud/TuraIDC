---
source: develop/plugins/demo-ali-pay.md
sourceHash: 679346386143ee08d2928c3f6ebe8c92e12fc0610d1a68951aa246accedf8a61
translatedAt: 2026-10-26
---

# Demo: Alipay Face-to-Face Payment Plugin

This document uses the Alipay Face-to-Face payment plugin as an example to describe the actual directory structure, configuration, callbacks, and end-to-end integration of a payment plugin; for the general development standard, see the [Plugin Development Guide](index.md).

Current implementation path:

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

## 1. Use cases

A payment plugin handles real third-party fund inflow, such as user top-ups and Alipay payment for product orders. Balance payment, manual provisioning by an administrator, and free orders do not create Payment records.

## 2. config.php demo

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

Key conventions:

- `slug=ali_pay` must match the directory name.
- `key=alipay` must keep the legacy business key; do not change it to `ali_pay`, otherwise existing `payments.gateway=alipay` records will no longer be compatible.
- The private key and the public key must both set `secret=true`.

## 3. Entry class demo

```php
<?php

declare(strict_types=1);

namespace TuraIDC\Plugins\Gateways\AliPay;

use TuraIDC\Plugins\Gateways\AliPay\Lib\AlipayService;

class AliPayPlugin extends AlipayService {}
```

The entry class can be very thin: the real capability lives in `lib/AlipayService.php`, while the Alipay SDK and signature handling, pre-order creation, query, and refund details live in `lib/AlipayClient.php`.

## 4. Business class demo

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

## 5. Payment boundaries

The plugin is responsible for:

- Creating the Alipay pre-order.
- Querying Alipay transactions.
- Alipay refunds.
- Verifying the signature of Alipay asynchronous notifications.
- Building the callback response that Alipay expects.

The platform is responsible for:

- Translating `payment.*` actions into platform `PaymentGatewayInterface` calls through `PluginPaymentGateway`.
- Creating and updating `payments`.
- Validating the amount, merchant ID, and order number.
- Callback idempotency, crediting funds, and order fulfillment.
- Closing other pending payment records.
- Audit logs and payment logs.

## 6. Key test points

- The plugin can be scanned, installed, and enabled.
- It cannot be enabled before the private key and public key are configured.
- Once enabled, `PaymentGatewayManager` registers the plugin gateway with priority.
- Payment callbacks must be handled through the platform's single entry point; the plugin controller must not change billing state directly.

Recommended command:

```bash
cd backend
php artisan test tests/Unit/PaymentGatewayRegistryTest.php tests/Feature/PluginRuntimeRegistryIntegrationTest.php
```
