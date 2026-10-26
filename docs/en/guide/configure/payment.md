---
source: guide/configure/payment.md
sourceHash: 980cc9898f8ce4c4f95cf77013bfe42609dd7f0dd4f82bd36a96d1c9b4ee0f59
translatedAt: 2026-10-26
---

# Payment Channel Integration

The two third-party channels through which users pay in are Alipay Face-to-Face Payment and YiPay, both installed and enabled as integration plugins; balance payment is a built-in capability and needs no plugin. This document describes the entry points, the fields, the callback URL requirements, and the troubleshooting path for "the user paid but nothing was credited".

## 1. Admin Panel Entry Points

| Page                 | Path                                                     | Permission                                                                                                                                  |
| -------------------- | -------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------- |
| Payment Channels     | System Settings → Integration Plugins → Payment Channels | List `integration_plugin.view`; install / edit config / enable or disable `integration_plugin.manage`; test order `integration_plugin.test` |
| Order Management     | Finance Management → Order Management → Orders           | Order-related permissions                                                                                                                   |
| Payment Gateway Logs | Log Center → Log Management → Gateway Logs               | `log.list`                                                                                                                                  |

The plugin list page scans the directories under `backend/plugins/gateways/`; after adding plugin files locally, the plugin appears only after you click "Scan Plugins", then click "Install" → "Enable". Test endpoints (including "Create a 0.01 CNY test order") are rate limited to 10 calls per minute.

## 2. Available Channels and System Codes

The system books transactions internally by gateway code (the constants are defined in `PaymentGatewayCode`):

| Code              | Display Name                     | Notes                                                                                                                 |
| ----------------- | -------------------------------- | --------------------------------------------------------------------------------------------------------------------- |
| `balance`         | Balance Payment                  | Built in; pays with the account balance and does not involve a third party                                            |
| `alipay`          | Alipay Payment                   | Requires the `ali_pay` plugin (slug matches `alipay` / `ali_pay`)                                                     |
| `epay`            | YiPay                            | Requires the `epay` plugin; the legacy codes `yipay` and `yi_pay` are normalized to this code                         |
| `wechat`          | WeChat Pay                       | The system has the code and display name, but there is no matching plugin directory under `backend/plugins/gateways/` |
| `stripe`          | Stripe Payment                   | Same as above; no matching plugin directory                                                                           |
| `manual` / `free` | Admin manual / Free provisioning | Used for internal bookkeeping; not third-party channels                                                               |

`wechat` and `stripe` currently exist only in the constant table, and there is no matching plugin directory under `backend/plugins/gateways/` (the code does not confirm whether filling this gap is planned), so they never appear on the frontend.

How gateways map to plugins: the "available payment methods" on the frontend come from the gateways that **enabled plugins** register into the runtime — each one is called with `isEnabled()`, and only a true result is shown; the callback route, by contrast, resolves back to a plugin by taking the first row ordered by status priority and then by ascending id, without requiring the plugin to be enabled. So after you disable a plugin the channel is no longer shown on the frontend, but an old callback URL already handed out to the channel still reaches the same gateway and does not return 404.

## 3. Alipay Face-to-Face Payment (`ali_pay`)

Fields (`backend/plugins/gateways/ali_pay/config.php`):

| Field                                 | Default | Consequence If Left Empty                                                                                                                                      |
| ------------------------------------- | ------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `alipay_enabled` Enable               | On      | When off, the channel is not offered as an available payment method                                                                                            |
| `app_id` App ID                       | Empty   | `isEnabled()` is false and the channel is not listed                                                                                                           |
| `private_key` Application private key | Empty   | Same as above; you must enter the **application private key**, not the Alipay public key                                                                       |
| `alipay_public_key` Alipay public key | Empty   | `isEnabled()` only looks at app_id and the private key, but callback signature verification requires the public key — without it verification fails every time |

`private_key` and `alipay_public_key` are sensitive keys: they are encrypted with `APP_KEY` before being written to the database (an `enc:` prefix), the admin panel only echoes `******`, and saving them empty keeps the old value instead of clearing it. To use "reveal plaintext" you need the `settings.secret_reveal` permission, and each call is rate limited to 10 per minute and writes an operation log.

The keys themselves can also come from the environment variables `ALIPAY_APP_ID`, `ALIPAY_PRIVATE_KEY`, and `ALIPAY_PUBLIC_KEY` (`config/alipay.php`); plugin configuration takes precedence over the environment variables. In production, keep only one of the two sources so you do not mistake the source during troubleshooting.

**Callback URL** (this determines whether the money is credited):

- Route: `POST /api/v2/client/payment/alipay/notify`, plus a generic entry point `/api/v2/client/payment/notify/{gateway}` (both GET and POST are accepted). Callbacks that historically used the old code `yipay` are also normalized to the current code, so you do not need to change the channel-side configuration.
- Precedence: `ALIPAY_NOTIFY_URL` → backend base URL `APP_URL` + `/api/v2/client/payment/alipay/notify` → `FRONTEND_URL` with the same path.
- At payment time the address is passed to Alipay only if it passes validation: the scheme must be http/https, and the host must not be `localhost`, `127.0.0.1`, or `::1`, and must not be a private or reserved IP. If it fails validation, the `notify_url` parameter is **not** sent — Alipay shows success while the local order is never credited, which is the hardest class of failure to notice.
- Falling back to `FRONTEND_URL` is an unreliable last resort: the four frontend sites do not reverse-proxy the API (see [Deployment and Scheduling](../deploy/scheduling.md)), so callbacks sent to the website or console domain are silently dropped. In production, set `ALIPAY_NOTIFY_URL` explicitly to point at the API domain.

When a callback arrives, the `verify.alipay.callback` middleware verifies the signature first and business processing follows; an amount that does not match the local order is rejected. Both callback routes are rate limited to 60 calls per minute per IP, and the limit sits before signature verification — when the rate is abnormal the gateway receives a 429 and treats the callback as failed and retries it. The gateway URL defaults to `https://openapi.alipay.com/gateway.do`; the sandbox is `https://openapi-sandbox.dl.alipaydev.com/gateway.do`, switched with `ALIPAY_GATEWAY`.

## 4. YiPay (`epay`)

Fields (`backend/plugins/gateways/epay/config.php`):

| Field                                           | Default     | Consequence If Left Empty                                                                                    |
| ----------------------------------------------- | ----------- | ------------------------------------------------------------------------------------------------------------ |
| `enabled` Enable                                | On          | When off, the channel is not offered as an available payment method                                          |
| `merchant_id` Merchant ID                       | Empty       | `isEnabled()` is false; callback verification also rejects everything because the merchant number is empty   |
| `api_endpoint` API endpoint                     | Empty       | Only http/https URLs with a host name are accepted; an invalid value is treated as unconfigured              |
| `sign_type` Signature method                    | `MD5`       | An invalid value silently falls back to `MD5`                                                                |
| `merchant_key` MD5 merchant key                 | Empty       | Required in MD5 mode; also fill it in for query / refund endpoints if the provider still requires a KEY      |
| `merchant_private_key` RSA merchant private key | Empty       | Required in RSA mode, shown only when RSA is selected                                                        |
| `platform_public_key` RSA platform public key   | Empty       | Required in RSA mode and used to verify callbacks, shown only when RSA is selected                           |
| `payment_types` Payment types                   | Alipay only | When nothing is checked the channel is not shown to users; Alipay and WeChat can be checked at the same time |

`api_endpoint` accepts the platform root URL directly; if you enter a concrete endpoint such as `mapi.php`, the system resolves the other endpoints from the same directory (`mapi.php`, `api.php`, and so on).

**The conditions for listing are strict**: `isEnabled()` requires the plugin to be enabled, the merchant ID to be non-empty, the signature material to be complete, the API endpoint to be resolvable, both `notify_url` and `return_url` to be non-empty, and `payment_types` to be non-empty; if any single one of these is not satisfied, the frontend does not show the channel.

**Callback URL**: it is always `APP_URL` joined with `/api/v2/client/payment/notify/epay`, and `return_url` is taken from the user console URL `CLIENT_CONSOLE_URL` → the website `FRONTEND_URL` → `APP_URL`, falling back to `/client/recharge`. Neither address **can be changed in the admin panel**; they can only be adjusted through environment variables — when changing domains you must update `.env` accordingly and restart PHP-FPM.

The signature method is a hard constraint: in MD5 mode the callback must carry `sign` and it must match the signature computed from the merchant key; in RSA mode an empty platform public key makes verification fail outright. An empty key never degrades into "no verification".

## 5. Verification

Run the read-only checks first, then a real small-value payment:

1. Click "Check" on the plugin detail page to confirm the plugin manifest and configuration are complete and that the entry class can be instantiated (read-only, no request to Alipay).
2. "Create a 0.01 CNY test order" on the payment plugin detail page: this really creates a 0.01 CNY test order, and **the test result is returned only, never saved**. Getting a payment link back means the App ID, the private key, and the signature chain all work; if you do not get one, read the reason in the error first.
3. Take one real small-value top-up through the whole chain: the user places an order → scans and pays → the callback credits the balance.
4. Check the callback record in "Gateway Logs" and in the order detail, and confirm `out_trade_no`, `trade_no`, and the amount all match.

The callback paths are already on the inbound WAF exemption list (`api/v2/client/payment/alipay/notify`, `api/v2/client/payment/notify/*`), so there is no need to touch the WAF; conversely, if you loosen WAF rules "so the callback gets through", you are changing the wrong thing.

```bash
# 回调必须是公网可达的地址，本机验证时不要用 APP_URL=127.0.0.1 的配置去测真实回调
grep -E '^(APP_URL|FRONTEND_URL|CLIENT_CONSOLE_URL|ALIPAY_NOTIFY_URL)=' /你的项目/backend/.env
```

## 6. Troubleshooting

- **The user paid successfully but the local order stays pending payment**: nine times out of ten this is a callback URL problem. First confirm that in `.env` the `APP_URL` / `ALIPAY_NOTIFY_URL` values are public API domains, then confirm Nginx and the WAF let the request through, and finally check whether the gateway log received anything.
- **The callback arrives but signature verification fails**: the Alipay public key was filled in with the application public key; or YiPay is set to RSA but no platform public key was entered; or the YiPay merchant key does not match the one on the provider side.
- **The channel side shows "notification failed"**: the callback was stopped by rate limiting (60 per minute per IP) or returned text other than `success`; Alipay retries up to 8 times at increasing intervals, so a brief burst of rate limiting usually heals itself — for persistent failures, check the gateway log.
- **The frontend does not offer this payment method**: the plugin is not enabled, or one of the `isEnabled()` preconditions is empty (with YiPay the most common ones are `payment_types` left unchecked and `api_endpoint` not being a valid URL).
- **Configuration changes do not take effect**: the Alipay configuration has a 60-second in-process cache and takes effect within a minute at the latest; after changing the gateway or swapping a plugin, restart PHP-FPM.
- **Every channel breaks after a domain change**: `APP_URL` and `CLIENT_CONSOLE_URL` were not updated along with it; neither of these is in the admin panel, you change `.env`.

## Related Documentation

- [Initial Configuration](index.md): the order of the four configuration steps and the go-live checklist.
- [Upstream Provider Integration](upstream.md): the other half after the money comes in — where the hosts are provisioned from.
- [Deployment and Scheduling](../deploy/scheduling.md): the deployment facts for the four site domains, reverse proxying, and the queue; callback reachability depends on them.
- [Engineering Pitfalls](../../develop/pitfalls.md): pitfalls measured in practice when changing configuration and doing incremental deployments.
