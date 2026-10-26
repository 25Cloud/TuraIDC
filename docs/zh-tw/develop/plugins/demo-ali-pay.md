---
source: develop/plugins/demo-ali-pay.md
sourceHash: 679346386143ee08d2928c3f6ebe8c92e12fc0610d1a68951aa246accedf8a61
generated: zh-tw
---

<!-- 本檔案由 docs-i18n.mjs trad zh-tw 從 docs/develop/plugins/demo-ali-pay.md 產生，請勿手動編輯。 -->
# Demo：支付寶當面付支付外掛

本文以支付寶當面付外掛為示例，說明一個支付外掛的實際目錄結構、配置、回撥與聯調方式；通用開發規範見[外掛開發指南](index.md)。

當前實現路徑：

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

## 1. 適用場景

支付外掛用於第三方真實資金流入，例如使用者充值、商品訂單支付寶付款。餘額支付、管理員手動開通和免費訂單不建立 Payment 記錄。

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

關鍵約定：

- `slug=ali_pay` 必須和目錄名一致。
- `key=alipay` 必須保持歷史業務 key，不要改為 `ali_pay`，否則歷史 `payments.gateway=alipay` 無法相容。
- 私鑰、公鑰必須 `secret=true`。

## 3. 入口類 demo

```php
<?php

declare(strict_types=1);

namespace TuraIDC\Plugins\Gateways\AliPay;

use TuraIDC\Plugins\Gateways\AliPay\Lib\AlipayService;

class AliPayPlugin extends AlipayService {}
```

入口類可以很薄，真實能力放在 `lib/AlipayService.php`，支付寶 SDK/簽名、預下單、查詢、退款細節放在 `lib/AlipayClient.php`。

## 4. 業務類 demo

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

## 5. 支付邊界

外掛負責：

- 支付寶預下單。
- 支付寶交易查詢。
- 支付寶退款。
- 支付寶非同步通知驗籤。
- 構造支付寶要求的回撥響應。

平臺負責：

- 通過 `PluginPaymentGateway` 把 `payment.*` action 轉為平臺 `PaymentGatewayInterface` 呼叫。
- 建立和更新 `payments`。
- 金額、商戶號、訂單號校驗。
- 回撥冪等、入賬、訂單履約。
- 關閉其它待支付記錄。
- 審計日誌和支付日誌。

## 6. 測試要點

- 外掛能被掃描、安裝、啟用。
- 未配置私鑰/公鑰不能啟用。
- 啟用後 `PaymentGatewayManager` 優先註冊外掛閘道器。
- 支付回撥必須通過平臺統一入口處理，不能讓外掛 Controller 直接改賬務狀態。

推薦命令：

```bash
cd backend
php artisan test tests/Unit/PaymentGatewayRegistryTest.php tests/Feature/PluginRuntimeRegistryIntegrationTest.php
```
