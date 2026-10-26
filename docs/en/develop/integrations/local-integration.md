---
source: develop/integrations/local-integration.md
sourceHash: bb8d06afeaba7e733a4d03cbe0e43e85e65dad968cfa352a811a140b530be252
translatedAt: 2026-10-26
---

# Local Integration Guide

## Purpose of This Document

- Covers only the third-party upstreams that the **project actually integrates with**; the exhaustive lists found in official reference documentation are out of scope
- Alignment date: `2026-07-02`
- Companion references:
  - The historical official ZJMF finance API reference is no longer stored in the repository; obtain it from the controlled upstream source when needed
  - `docs/develop/architecture.md` - architectural placement and entry points

## 1. Upstream Suppliers

Upstreams currently enabled in the live `suppliers` table:

| Supplier     | `code`       | `interface_type`    | Integration protocol  |
| ------------ | ------------ | ------------------- | --------------------- |
| Jidian Cloud | `supplier_1` | `hosting_panel_api` | Hosting panel OpenAPI |
| Meide Cloud  | `supplier_2` | `hosting_panel_api` | Hosting panel OpenAPI |

Notes:

- Both enabled upstreams share the same hosting panel API protocol underneath and are adapted locally through the provider driver system; `hosting_panel_api` uses `HostingPanelApiDriver`
- The ZJMF finance upstream is carried entirely by the `backend/plugins/servers/zjmf_finance/` plugin; `zjmf_finance_api` uses `Lib\ZjmfFinanceDriver` + `Lib\ZjmfFinanceAdapter` inside the plugin
- `zjmf_finance_api` is a standalone provider key and keeps its distinct identity across resolution, provisioning, synchronization, and service binding instead of being normalized to `hosting_panel_api`
- Adding another upstream of the same kind only requires creating a `suppliers` record in the admin panel and maintaining its API address, account, and key

### 1.1 Middle-Layer Boundaries

| Layer               | Responsibility                                                                                               | ZJMF placement                                                                                                                                |
| ------------------- | ------------------------------------------------------------------------------------------------------------ | --------------------------------------------------------------------------------------------------------------------------------------------- |
| Provider resolution | Resolves the real provider key from service binding, product binding, and supplier configuration             | `ProviderResolver`, `ProviderRegistry`                                                                                                        |
| Plugin              | Declares `domain=upstream`, `slug=zjmf_finance`, `key=zjmf_finance_api`                                      | `backend/plugins/servers/zjmf_finance/config.php`, `ZjmfFinancePlugin.php`                                                                    |
| Driver              | Exposes the supplier key, capability list, and capability objects                                            | `backend/plugins/servers/zjmf_finance/lib/ZjmfFinanceDriver.php`                                                                              |
| Adapter             | Explicitly declares the ZJMF capability methods that platform business services may call                     | `backend/plugins/servers/zjmf_finance/lib/ZjmfFinanceAdapter.php`                                                                             |
| Plugin Services     | Protocol actions such as login, product, provisioning, renewal, status, console, network, and security group | `backend/plugins/servers/zjmf_finance/lib/Zjmf*Service.php`                                                                                   |
| Plugin Transport    | ZJMF finance request entry, JWT parsing, 401 invalidation handling, and text/JSON request wrapping           | `backend/plugins/servers/zjmf_finance/lib/ZjmfFinanceTransport.php`, `ZjmfAuthManager.php`                                                    |
| Shared Transport    | Shared transport helpers only: underlying HTTP, DNS/TLS security, timeouts, log redaction                    | `HostingPanelApiTransport.php`                                                                                                                |
| Legacy Zjmf Support | Non-data-plane compatibility such as legacy password compatibility and billing restore                       | `backend/plugins/servers/zjmf_finance/lib/ZjmfLegacyPasswordVerifier.php`, `ZjmfBillingRestoreService.php`, registered by the plugin provider |

Extension rules:

- Business services keep ZJMF request assembly and official field differences inside the plugin; any new difference lands in the plugin service/adapter first.
- Reusing hosting panel transport capability goes through the `ZjmfFinanceTransport` wrapper only; the provider key and capability objects keep the independent ZJMF identity.
- Extend the ZJMF cloud product config option template inside the plugin via `ZjmfCloudConfigTemplate`; only generic fallbacks belong in `Services/Upstream/Support/CloudConfigTemplate`.
- Legacy ZJMF password compatibility goes through the `LegacyPasswordVerifier` aggregator, with concrete format detection placed in `ZjmfLegacyPasswordVerifier`.
- When adding a provider or capability, add the minimal tests at the same time: provider resolution, explicit adapter methods, key field mapping, and failure degradation.
- New code and dependencies keep `ZjmfFinanceAdapter::__call()` dynamic forwarding out; a new capability extends the plugin-internal service first, then adds an explicit method on the adapter.

## 2. Entry Points and Key Files

| Responsibility                           | Entry point                                                                                                          |
| ---------------------------------------- | -------------------------------------------------------------------------------------------------------------------- |
| Upstream resolution entry                | `backend/app/Services/Upstream/ProviderResolver.php`                                                                 |
| Driver registry                          | `backend/app/Services/Upstream/ProviderRegistry.php`                                                                 |
| Hosting panel driver                     | `backend/app/Services/Upstream/Drivers/HostingPanelApi/HostingPanelApiDriver.php`                                    |
| Hosting panel transport layer            | `backend/app/Services/Upstream/Drivers/HostingPanelApi/HostingPanelApiTransport.php`                                 |
| ZJMF finance plugin                      | `backend/plugins/servers/zjmf_finance/`                                                                              |
| ZJMF finance driver                      | `backend/plugins/servers/zjmf_finance/lib/ZjmfFinanceDriver.php`                                                     |
| ZJMF finance adapter                     | `backend/plugins/servers/zjmf_finance/lib/ZjmfFinanceAdapter.php`                                                    |
| ZJMF finance transport entry             | `backend/plugins/servers/zjmf_finance/lib/ZjmfFinanceTransport.php`                                                  |
| ZJMF finance login and JWT management    | `backend/plugins/servers/zjmf_finance/lib/ZjmfAuthManager.php`                                                       |
| ZJMF cloud config option template        | `backend/plugins/servers/zjmf_finance/lib/ZjmfCloudConfigTemplate.php`                                               |
| ZJMF product type mapping                | `backend/plugins/servers/zjmf_finance/lib/ZjmfProductTypeMapper.php`                                                 |
| Legacy ZJMF password compatibility       | `backend/plugins/servers/zjmf_finance/lib/ZjmfLegacyPasswordVerifier.php`                                            |
| Legacy password compatibility aggregator | `backend/app/Services/Auth/LegacyPasswordVerifier.php`                                                               |
| Upstream protocol helpers                | `backend/app/Services/Upstream/Drivers/HostingPanelApi/Concerns/`                                                    |
| Product config option normalization      | `backend/plugins/servers/zjmf_finance/lib/ZjmfCatalogService.php`, `ZjmfCloudConfigTemplate.php`                     |
| ZJMF billing restore tool                | `backend/plugins/servers/zjmf_finance/lib/ZjmfBillingRestoreService.php`                                             |
| Alipay F2F plugin                        | `backend/plugins/gateways/ali_pay/`                                                                                  |
| Alipay F2F client                        | `backend/plugins/gateways/ali_pay/lib/AlipayClient.php`                                                              |
| Alipay F2F capability service            | `backend/plugins/gateways/ali_pay/lib/AlipayService.php`                                                             |
| Alipay callback                          | `backend/app/Http/Controllers/Client/PaymentCallbackController.php`                                                  |
| Real-name verification callback          | `backend/app/Http/Controllers/Client/VerificationController@callback`, goes through the `verify.callback` middleware |
| Callback signature middleware            | `backend/app/Http/Middleware/VerifyCallbackSignature.php`                                                            |

## 3. List of Integrated Upstream Capabilities

Only what the project actually calls is listed; this is not the official full set:

| Capability                                 | Method                      | Path                                                                         | Local call entry                                                                                                               |
| ------------------------------------------ | --------------------------- | ---------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------ |
| ZJMF login and JWT exchange                | `POST`                      | `/zjmf_api_login`                                                            | `ZjmfAuthManager::login()` / `ZjmfFinanceTransport::login()`                                                                   |
| ZJMF get user profile                      | `GET`                       | `/v1/user`                                                                   | `ZjmfFinanceTransport::getUserProfile()`                                                                                       |
| ZJMF get account balance                   | `GET`                       | `/cart/credit`                                                               | `ZjmfFinanceTransport::getBalance()`                                                                                           |
| ZJMF product catalog                       | `GET`                       | `/cart/all`                                                                  | `ZjmfCatalogService::getProductCatalog()`                                                                                      |
| ZJMF product configuration and inventory   | `GET`                       | `/cart/get_product_config?pid={productId}`                                   | `ZjmfCatalogService`                                                                                                           |
| ZJMF host details on the same system       | `GET`                       | `/host/header?host_id={hostId}&source=API`                                   | `ZjmfFinanceTransport::getHostDetail()`; normalizes `data.host_data` to `data.host`.                                           |
| ZJMF provisioning                          | Multiple official endpoints | Cart, checkout, upstream payment, host re-query                              | `ZjmfProvisionService::provisionOrder()`                                                                                       |
| ZJMF renewal                               | `POST`                      | `/host/renew`; `/pay?action=billing&pay=true`; `/check_order`                | `ZjmfRenewService`; automatic renewal runs only from this site's billing and scheduler, leaving the upstream switch untouched. |
| ZJMF status synchronization                | `GET`                       | `/host/header?host_id={hostId}&source=API`, plus the runtime status endpoint | `ZjmfStatusService`                                                                                                            |
| ZJMF VNC, power, reinstall, password reset | Multiple official endpoints | host action / console endpoints                                              | `ZjmfConsoleService`                                                                                                           |
| ZJMF traffic packages, upgrade/downgrade   | Multiple official endpoints | upgrade / flowpacket endpoints                                               | `ZjmfNetworkService`                                                                                                           |
| ZJMF NAT, security groups, custom modules  | `GET/POST`                  | servicedetail custom module pages and submit endpoints                       | `ZjmfSecurityService`, `ZjmfConsoleService`                                                                                    |

Plugin services call the upstream internally through `ZjmfFinanceTransport::get/post/put/delete/parallelGet`. Platform business services call only the high-level methods exposed by the capability; ZJMF request paths, request parameters, and official response fields stay inside the plugin service.

## 4. Signing and Authentication Constraints

- ZJMF JWTs are cached in isolation per `supplier_id + provider_key`, and `provider_key` stays `zjmf_finance_api`
- Before each ZJMF plugin call, `ZjmfAuthManager` performs `login` automatically; a failure or a 401 clears the cache and triggers a refresh or re-login
- The regular ZJMF data plane uses `Authorization: Bearer <token>` after obtaining the JWT; same-system custom module pages and actions use `Authorization: JWT <token>`, and page requests additionally pass the `id`, `key`, and `jwt` query parameters; `/zjmf_api_login` and the official reference `/v1/login_api` are separate endpoints and are never interchangeable
- Failed upstream requests are recorded as redacted logs by the plugin transport entry and the shared underlying transport, and the logs must distinguish `zjmf_finance_api` from `hosting_panel_api`
- Upstream callbacks (supplier-initiated push to us) expose **no** standard webhook yet; business relies mainly on polling

## 5. Payment Integration: Alipay F2F

### 5.1 Capabilities

| Capability                                           | Alibaba method name      | Local entry                                    |
| ---------------------------------------------------- | ------------------------ | ---------------------------------------------- |
| Pre-create an order and generate the payment QR code | `alipay.trade.precreate` | `AlipayService` action `payment.precreate`     |
| Actively query order status                          | `alipay.trade.query`     | `AlipayService` action `payment.query`         |
| Trade refund                                         | `alipay.trade.refund`    | `AlipayService` action `payment.refund`        |
| Verify asynchronous notification signature           | -                        | `AlipayService` action `payment.verify_notify` |

### 5.2 Configuration Entry

Set the following `settings` entries through the admin panel under "Notification Settings / Payment Settings":

- `alipay_app_id`
- `alipay_private_key`
- The remaining Alipay-related `alipay_*` entries

### 5.3 Callback Paths

- Alipay asynchronous notification: `POST /api/v2/client/payment/alipay/notify`
- Local callback URL convention: `{APP_URL}/api/v2/client/payment/alipay/notify`
- Inside the callback, the payment plugin runtime executes `payment.verify_notify` for signature verification, while `PaymentService` handles idempotency, status persistence, and dispatch of the follow-up provisioning or renewal

## 6. Real-Name Verification Integration: Alipay Real-Name

### 6.1 Client Endpoints (for the user-side SPA)

- `GET /api/v2/client/verification/fee-config` - Get the real-name verification fee configuration
- `POST /api/v2/client/verification/init` - Initialize real-name verification (obtain `certify_id`)
- `POST /api/v2/client/verification/qrcode` - Get the scan QR code
- `GET /api/v2/client/verification/status` - Query the current status
- `GET /api/v2/client/verification/scan` - Scan redirect relay

### 6.2 Real-Name Asynchronous Callback

- Path: `GET|POST /api/v2/client/verification/callback`
- Middleware: `verify.callback` (backed by `VerifyCallbackSignature`)
- Controller: `Client\V2\VerificationController::callback`
- On success it updates `users.verification_*` and the corresponding `user_accounts` fields, and writes `verification_histories`

### 6.3 Admin Panel Visibility

- `GET /api/v2/admin/verifications` - Real-name verification list
- `GET /api/v2/admin/verifications/{user}/history` - History
- `POST /api/v2/admin/verifications/{user}/unbindings` - Admin-side unbinding (permission-gated)

## 7. Mapping to Official Documentation

| Official documentation                                  | Corresponding local file                                                                                            |
| ------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------- |
| ZJMF finance API reference from the controlled upstream | `backend/plugins/servers/zjmf_finance/lib/ZjmfFinanceAdapter.php` + `Zjmf*Service.php` + `ZjmfFinanceTransport.php` |

Suggested reading order:

1. This document (project integration scope)
2. The corresponding official reference (field semantics)
3. The corresponding local service implementation

## 8. Baseline Checklist for New Integrations

When adding an upstream or a payment channel, meet at minimum:

- Create a record in the `suppliers` table (or wire it into the matching config table)
- Add or reuse a `Service` client (keep `Http::post` out of controllers)
- Log inputs, outputs, and exceptions (`trace_id` + `supplier_id` + call context)
- Cache JWT / Token with an expiry fallback mechanism
- Make call failures observable (queryable from the admin log page)
- Route callbacks through `verify.callback` or an equivalent signature middleware
- Keep callback handling idempotent
- Keep sensitive configuration such as callback paths, public keys/secret keys, and merchant IDs in `settings` or `.env`, **never hardcoded**

## 9. Official Capabilities Awaiting Integration

The following exist in the official documentation but receive **no** active call from the project yet:

- Generic webhook push receiving (the project relies mainly on polling)
- Order-level callbacks (order status relies on queries plus internal state-machine self-healing)
- One-click full product catalog synchronization (currently only `batch-sync` and on-demand fetching are implemented)

When integration is needed, add a section to this directory first, then update the `Upstream` driver and transport layer.
