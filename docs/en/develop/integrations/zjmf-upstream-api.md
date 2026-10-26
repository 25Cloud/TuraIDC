---
source: develop/integrations/zjmf-upstream-api.md
sourceHash: 191333e84adb12d145e628dfcf9e641386c31805574d0d3b7eaf4d05b26e5775
translatedAt: 2026-10-26
---

# ZJMF Finance Integration Reference (TuraIDC as Upstream)

TuraIDC implements the ZJMF Finance upstream provider protocol: ZJMF Finance configures TuraIDC as an API-type upstream under "Upstream", and this protocol carries its customers when they purchase, renew, or control products on the TuraIDC side. Routes are defined in `backend/routes/v2-zjmf-upstream.php`; the implementation lives in `backend/app/Http/Controllers/ZjmfUpstream/` and `backend/app/Services/ZjmfUpstream/`.

## Downstream Configuration (ZJMF Finance Admin Side)

- Upstream API address (hostname): `https://你的域名/api/v2/zjmf`. **The `/api/v2/zjmf` prefix is mandatory**; without it the login request matches no route (HTTP 404, which ZJMF Finance shows as "request failed, HTTP status code: 404").
- Account: an ordinary TuraIDC customer account. **The customer enables it in the console, on the "API Credentials" page, in the "ZJMF Finance Integration" section** (`/client/api-keys?tab=upstream`, API `/api/v2/client/upstream-api/*`). Once enabled, the system generates a dedicated `api_username` and `api_password` (the password is displayed only once and can be reset at any time), and the account must keep `status=1`. This credential is independent of the account login password and of the keys in the "Open API Keys" section on the same page; neither affects the other.
- Credential governance follows the same rules as open API keys: you can set an **IP allowlist** (only allowlisted IPs may exchange for a JWT) and an **expiry** (once expired, JWT exchange is rejected), and usage is counted in the call audit log (`api_key_usage_logs.channel = zjmf_upstream`; the console section lists the 50 most recent entries). Leaving either field empty means no restriction.
- Account conditions are enforced identically at login and at authentication: if the account is disabled, upstream API access is turned off, the credential has expired, or the IP is not allowlisted, login returns `status=400` and business requests return `status=405`, so the dead loop of "login succeeds but every request returns 405" never happens.
- **Linked revocation**: turning off ZJMF Finance integration in the console disables every open API key of the same account as well. The reverse does not hold (disabling one open API key does not affect the ZJMF Finance path) — this avoids leaving behind a forgotten full-privilege credential.

> Note: ZJMF Finance integration (this page's protocol, `/api/v2/zjmf`) and the built-in open API (`/api/v2/open`) use **two separate authentication schemes at the protocol layer**, and one credential does not cover both: ZJMF Finance only posts a username + password to `/zjmf_api_login` to obtain a JWT and never sends a Bearer API key (as the `zjmf376/app/zjmf.php` `zjmfCurl` call site shows). A single credential serving both paths is therefore not possible at the protocol level, so the console instead **manages both in sections of the same page with aligned governance**. ZJMF Finance only uses the protocol on this page; open API keys are used only when integrating another TuraIDC instance (with the `tura_open_api` plugin).

## Login and Authentication

1. ZJMF Finance calls `POST /zjmf_api_login` (form-encoded `username` / `password`); on success it returns `{"jwt": "...", "status": 200}`.
2. Subsequent business requests carry `Authorization: Bearer <jwt>`.
3. The JWT is HS256, signed and verified locally, valid for 7200 seconds (matching ZJMF Finance's `createJwt`). ZJMF Finance caches the JWT long-term and forces exactly one re-login when it receives `status=405`; if the re-login still returns 405 it reports "API account or password incorrect" — so one in-protocol 405 self-healing request appears roughly every 2 hours, which is normal.

## Response Conventions

The HTTP layer always returns 200; the business status sits in the body's `status`:

| status | Meaning                                                                 |
| ------ | ----------------------------------------------------------------------- |
| 200    | Success                                                                 |
| 1001   | Payment-type operation "already paid" (ZJMF Finance maps it to success) |
| 400    | Business failure (msg holds a user-readable reason)                     |
| 405    | JWT invalid, triggers a forced re-login in ZJMF Finance                 |

Note: ZJMF Finance's `commonCurl` converts every non-200 HTTP response to `code=500` and does not pass the HTTP status code through; if an integration error mentions "HTTP status code: 404/429", check the hostname prefix and the login rate limit (20 requests/minute) first.

## Endpoint List

- Login: `POST /zjmf_api_login` (unauthenticated, separate rate limit)
- Product: `GET /cart/all`, `GET /api/product/proinfo`, `GET /api/product/prodetail`, `GET /cart/get_product_config`, `GET /cart/ontrialmax`
- Purchase and provisioning: `GET /user_info`, `POST /cart/clear`, `POST /cart/add_to_shop`, `POST /cart/settle`, `POST /provision/default`, `POST /provision/custom/{id}`
- Host: `GET /host/header`, `POST /host/renew`, `POST /host/cancel`, `POST /provision/button` (parameterless control buttons, `id` + `func`)
- Control: `POST /dcim/on|off|reboot|hard 相关`, `POST /dcim/novnc|kvm|ikvm|bmc|rescue|crack_pass|reinstall|cancel_task|refresh_power_status|refresh_all_power_status|hide_result|check_reinstall|buy_reinstall_times|buy_flow_packet`, `GET /dcim/traffic_usage|/host/trafficusage`, `GET /dcim/detail|/dcim/resintall_status`
- Upgrade: `POST /upgrade/upgrade_config_post|checkout_config_upgrade|upgrade_product_post|checkout_upgrade_product`
- Balance: `POST /apply_credit` (balance payment; on success returns 1001 with `data.hostid[0]` = service ID), `POST /apply_credit_limit` (credit line not yet supported, returns 400)
- Push: `POST /api/ticket_reply/sync`, `POST /upload_image`

## Custom Tabs (Panel-Type Products)

Panel-type products such as CDN and shared hosting carry their console and login details through custom tabs on the ZJMF Finance side, with this flow:

1. `GET /host/header` returns `module_client_area` (`[{key, name}]`), which declares the available tabs, and `module_client_main_area` (`[{name, value}]`), which the downstream renders as login details (panel address, username, password, port).
2. When the downstream clicks a tab, it calls `POST /zjmf_api/provision/custom/content` (parameters `id`=upstream host id, `key`=tab identifier, `api_url`, `now_jwt`) and gets back `{status:200, data:{html}}`.
3. Actions inside the page are submitted to `POST /provision/custom/{id}` and forwarded for execution.

The middle layer (when TuraIDC itself connects to a provider) handles requests in the same order as ZJMF Finance:

1. Service bound to a controllable provider → **pass through** the provider's `module_client_area` / `module_client_main_area` / `module_button`; when fetching content, keep passing the downstream's `api_url` down, and forward actions unchanged;
2. Panel-type product without a controllable provider → render the panel from the local connection credentials (panel address, username, password, port);
3. Datacenter-type products (cloud servers / bare metal) get no custom area; every entry point lives under `/dcim/*`.

`host_data` also carries `show_traffic_usage` (true when a traffic quota exists), which the downstream uses to decide whether to render traffic usage.

Note: content must be fetched from the API protocol endpoint above (authenticated with the API JWT). ZJMF Finance's client-area route `GET /provision/custom/content` only accepts a client-area login session (`client_user_login_token_` cache); calling it with an API JWT returns no content.

## Troubleshooting

On persistent 405s, check the `[zjmf-upstream] 鉴权拒绝` records in the TuraIDC log, in the `reason` field:

- `disabled`: the upstream API is not enabled server-side (`services.zjmf_upstream.enabled`).
- `jwt_invalid`: the JWT is missing, the signature does not match, or it has expired — check whether the ZJMF Finance side sends the Bearer header and whether the clocks on both ends drift too far apart.
- `account_unavailable`: the integration account no longer exists.
- `policy_rejected`: the account policy check failed (`msg` gives the specific reason: account disabled / upstream API access not enabled / upstream API credential expired / current IP not in the credential allowlist). The allowlist and expiry are evaluated on **every request**, not only once at login — so after a policy change, already-issued JWTs stop working immediately instead of waiting for the natural 2-hour expiry.

When a login is rejected by policy, a `[zjmf-upstream] 登录被拒` record is written as well (`status=400`, with `msg` like `鉴权失败：当前 IP 不在凭据白名单内`). Both successful and rejected logins go into the call audit log (channel `zjmf_upstream`, login records use `api_key_id = 0`).

**When ZJMF Finance reports "authentication failed" (the login itself fails)**, check in this order:

1. Whether the upstream address includes the `/api/v2/zjmf` prefix (`https://域名/api/v2/zjmf`);
2. Whether the customer has enabled it in the console under "API Credentials → ZJMF Finance Integration" and is using the **username and one-time password** shown on that page;
3. Whether the account is disabled (`status ≠ 1`), whether the credential has expired, and whether the ZJMF server's egress IP is inside the customer's IP allowlist;
4. If the password was reset, update it on the ZJMF Finance side as well (the old password stops working immediately).

All remaining protocol details (field names, idempotency semantics, degradation behavior) are defined by the route file and by the comments in each Service; for the ZJMF Finance call sites, refer to the `app/zjmf.php` `zjmfCurl` and `app/common/logic/Host.php`.
