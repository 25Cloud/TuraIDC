---
status: current
updated: 2026-09-13
owner: backend-platform
source: develop/backend.md
sourceHash: 8f5b9f6107c37132433e79a5e2845d79f04fef76d0b8ed6a95eea5e022c33ad7
translatedAt: 2026-10-26
---

# Backend Engineering Standards

This document defines the long-term standard for subsequent manual development, review, refactoring, and third-party integration governance of the TuraIDC Laravel 12 backend. The goal is to make backend code readable, testable, replaceable, and traceable, and to reduce the cost of subsequent manual maintenance.

Important constraint: the current development documents, development standards, and other binding documents are not completely truthful and are not guaranteed to be entirely accurate. Subsequent governance must be based primarily on current code inspection, real routes, observable runtime behavior, and necessary controlled verification; when a document is found to conflict with the current code, record the conflict first, and then update or supplement the document according to the inspection conclusions.

Governance execution principle: an audit must cover the key directories and risk domains, but changes may land only on files that are worth changing; do not mechanically add comments, mechanically reformat, or manufacture meaningless changes just to "cover" something. The auto-generated `docs/develop/api/backend-api-catalog.md` is not edited by hand.

## 1. Governance Goals

1. Controllers stay thin; business logic converges into a Service, an Action, a Domain Service, or a dedicated integration client.
2. Parameter validation, response transformation, business flows, third-party protocols, and log auditing each have clear boundaries.
3. API requests and responses are engineered, so that parameter normalization, response field transformation, and error mapping do not scatter across Controllers or third-party drivers.
4. Third-party capabilities such as payment, real-name verification, ZJMF Finance/ZJMF Cloud, SMS, email, and object storage are integrated as plugins/drivers, and the business layer depends on project-internal interfaces instead of a concrete vendor.
5. Every high-risk business path has a testable entry point, including payment callbacks, balance deduction, service provisioning, upstream failure, and permission checks.
6. Necessary comments explain business reasons, boundary conditions, third-party protocol differences, and historical compatibility; do not write meaningless line-by-line translation.
7. Backend comments, API error `message`, field validation errors, and frontend user-visible prompts must use Simplified Chinese.
8. Establish a review loop that prevents misreview, missed review, and reviewing without reporting.
9. New or modified code must follow the existing naming, directory, layering, exception handling, response structure, and test organization of the same module; when a new pattern really has to be introduced, the reason must be stated in the corresponding design or execution plan, and the related standards must be updated at the same time.
10. Rule changes must be synchronized to the project documentation and the TuraIDC-related skills, so that later agents and manual development do not keep following old conventions.

## 2. Non-Goals

1. No one-off large rewrite.
2. Do not break the currently stable payment, bill, service instance, and callback chains for the sake of abstraction.
3. Do not run database initialization, migration, or historical migration re-runs without confirmation.
4. Do not hand-edit the auto-generated `docs/develop/api/backend-api-catalog.md`.
5. Do not cram all third-party capabilities into one universal interface.

## 3. Backend Layering Standards

### Controller

- Only responsible for receiving the request, the authentication context, calling application services, and returning the response.
- Do not assemble third-party requests directly.
- Do not call `Http::*` directly.
- Do not write complex business branches.
- Do not scatter transactions across a Controller.

### FormRequest

- Request parameters, types, ranges, enum values, and business preconditions belong in the FormRequest first.
- Complex validation may be delegated to a Rule or a Service, but a Controller should not hand-write validate.

### Resource

- Responsible for converting the API output structure.
- Does not carry business decisions.
- Does not access third parties directly or trigger writes.

### Service / Action

- Services are aggregated by business domain, for example bills, payment, balance, service instances, and upstream integration.
- A single clear business action can be extracted as an Action, so that one Service does not grow without bound.
- Services that involve finance, audit, or state transitions must consider transactions, idempotency, audit fields, and concurrency locks.

### Model

- A Model keeps the data model, relations, scopes, casts, and lightweight domain state checks.
- Do not put complex cross-table flows in it.
- `$fillable` must be precise; sensitive fields are not opened to mass assignment.

### Job / Listener / Command

- Only perform async scheduling and flow orchestration; delegate the actual business logic to a Service.
- A Job must declare `$tries`, `$timeout`, `$backoff`, or explain why they are not needed.
- A scheduled task that involves concurrency uses `withoutOverlapping()`.

## 4. Third-Party Plugin Standards

Third-party capabilities are governed as "capability-domain interface + Provider driver + DTO + unified exception + configuration registration + fake provider test double".

### Recommended directories

```text
backend/app/Contracts/Integrations/
backend/app/Services/Integrations/
backend/app/Services/Integrations/Payments/
backend/app/Services/Integrations/Identity/
backend/app/Services/Integrations/Notifications/
backend/app/Services/Integrations/Storage/
backend/app/Services/Integrations/Drivers/
backend/app/Services/Upstream/            # 上游驱动独立领域
backend/app/Services/Upstream/Contracts/  # UpstreamDriver 基接口 + Provides* 能力接口
backend/app/Services/Upstream/Drivers/    # 应用内驱动实现（HostingPanelApi）
backend/config/integrations.php
backend/tests/Fakes/Integrations/
```

### Recommended interfaces

- `PaymentGatewayInterface`: payment creation, query, closing, callback signature verification, callback parsing.
- `RefundGatewayInterface`: refund request, refund query, refund callback parsing.
- `IdentityVerificationProviderInterface`: two-factor real-name verification, three-factor real-name verification, or the real-name capabilities the vendor supports.
- Upstream driver contracts live in `app/Services/Upstream/Contracts/`: the base interface `UpstreamDriver` (`key()`/`label()`/`capabilities()`/`supports()`/`resolve()`), while capabilities such as provisioning, renewal, status sync, supplier balance, console, network, security group, and billing recovery are declared one by one through `Provides*` interfaces (such as `ProvidesProvisioning`, `ProvidesRenewal`, `ProvidesStatusSync`, `ProvidesSupplierBalance`); a driver implements them as needed and is not required to be all-capable.
- `SmsProviderInterface`: SMS sending, template validation, send result query.
- `ObjectStorageProviderInterface`: upload, download, temporary link, delete.

### Mandatory requirements

1. Business Services depend on project-internal interfaces, not on a concrete SDK or vendor fields.
2. Provider raw responses must be converted into project-internal DTOs.
3. Provider exceptions must be mapped to unified business exceptions.
4. Callbacks must first go through the unified entry point that handles signature, logging, and idempotency, and only then be dispatched to the concrete provider.
5. Logs must contain trace_id, provider, capability type, resource ID, and result status, and must mask sensitive fields.
6. Every Provider must declare its capabilities, for example whether refunds, three-factor real-name verification, service suspension, renewal, and retry are supported.
7. A fake provider or mock gateway must be provided for automated testing and local development.
8. Old and new Providers are allowed to coexist: historical records are interpreted by their original provider, and new requests can be switched by configuration.

### Current implementation progress

- The plugin runtime is in place: `IntegrationPluginService`, `PluginInstaller`, `PluginScanner`, `PluginRuntimeRegistry`, `PluginConfigRepository`, and the capability-domain adapters.
- Plugin files live in `backend/plugins/{domain}/{slug}/`; the current capability domains include payment gateway, real-name verification, SMS, email, and upstream server.
- Plugin configuration is managed by `integration_plugins` and `integration_plugin_configs`; sensitive configuration must be stored encrypted, and the API only returns whether it is configured plus a masked preview.
- Payment gateways are already integrated through `PaymentGatewayInterface`, `PaymentGatewayManager`, and the plugin payment adapters; `payments.gateway` still keeps its historical business values, and the gateway plugin key must not be mixed with the business gateway value.
- Any new payment method must add a plugin or driver and register it through the plugin runtime; a concrete provider must not be injected directly in `PaymentService` or a Controller.
- Real-name verification is integrated through the plugin real-name adapter; initialization, the scan-code link, result query, callback signature verification, and charge configuration must be expressed through internal DTOs/interfaces, and the business layer must not depend on vendor raw arrays.
- SMS and email are integrated through plugin adapters; English or technical vendor errors must be mapped to Simplified Chinese business prompts.
- Upstream supplier drivers are resolved uniformly through `PluginRuntimeRegistry` from the enabled plugins in the upstream domain, and summarized by `ProviderRegistry` by `key()`; plugin drivers are uniformly wrapped as `PluginUpstreamDriver`, and at runtime the capability object is obtained via `server.resolve_capability`. Current provider keys: `hosting_panel_api` (the `ProviderKey` constant, in-app transport), `zjmf_finance_api` (the ZJMF Finance plugin), `tura_open_api` (the TuraIDC Open API plugin).
- Upstream providers already declare capabilities such as service provisioning, renewal, console, network, security group, and status sync through capability objects; `zjmf_finance_api` must keep resolving to the ZJMF plugin adapter and must not be folded into the shared hosting panel transport.
- ZJMF Finance/ZJMF Cloud and hosting panel cache keys must be provider-aware; whether to keep reading the old key can only be stated explicitly by a currently approved execution plan, and normalizing `zjmf_finance_api` into `hosting_panel_api` is prohibited.
- The Open API (`/api/v2/open/*`) is an independent gateway layer: `api_keys` stores only hashes (the plaintext is returned once at creation), and the `api.key` middleware performs key authentication + scope authorization + IP allowlist + call auditing (`api_key_usage_logs`, pruned weekly by `open-api:prune-usage-logs`); Open controllers stay thin and reuse the existing Services.
- Before capability domains that are not yet pluginized, such as object storage, are integrated, the internal contract, DTO, error mapping, and a fake provider or plugin test double must be added first.

### Prohibited practices

- A Controller selecting a provider such as Alipay, WeChat, or ZJMF Finance directly.
- A business table storing an unexplainable third-party raw response as the only source of state.
- One universal `PluginInterface` covering all capabilities of payment, real-name verification, upstream, SMS, and storage.
- Switching to a real vendor without callback idempotency and regression tests.

## 5. API Request and Response Engineering

API governance must cover the request entry and the response exit at the same time. When adding or refactoring endpoints, take `docs/develop/api/format.md` as the current API standard document, and back-fill that document with the current code inspection results.

### Request side

- Complex endpoints must use a FormRequest; a Controller should not hand-write a large amount of parameter reading and type conversion.
- Request fields are uniformly `snake_case`.
- Pagination is uniformly `page` and `page_size`.
- Sorting, filtering, booleans, enums, amounts, and date ranges must be normalized in the FormRequest or the Request DTO.
- Amounts must have an explicit unit; payment/balance code must not use float implicitly.
- Request parameters passed across Services should preferably be converted into a Command/DTO.

### Response side

- A Controller uses the unified response method or throws a unified business exception.
- A Service does not return an HTTP Response directly.
- Frontend-facing fields are controlled by the Resource or the Response DTO.
- Third-party raw responses must not pass through to the Controller or the frontend directly.
- Sensitive fields are not returned by default.
- Error responses must unify the business code, the HTTP status code, and `message`.
- Compatibility fields must state why they are kept and under what condition they will be removed.

### Documentation sync

- When API request/response rules change, update `docs/develop/api/format.md` first.
- When the route list changes, refresh `docs/develop/api/backend-api-catalog.md` with the script; hand-editing that file is prohibited.
- When frontend fields are affected, the frontend calls and test suggestions must be synchronized.

## 6. Comment Standards

### Required comments

- Third-party protocol differences and non-obvious field mappings.
- Key business constraints in the state transitions of finance, bills, payment, balance, and service instances.
- The reasons behind the design of idempotency keys, locks, and transaction boundaries.
- Special logic kept to stay compatible with historical data.
- The business purpose and side effects of public interfaces, DTOs, key Service public methods, and Jobs.
- All new comments use Simplified Chinese first, to ease later manual maintenance.

### Discouraged comments

- Content the code already states clearly, such as "assign a value to a variable" or "call some method".
- Long background explanations that drift out of sync with the code.
- Content containing secrets, tokens, real accounts, or vendor private parameters.
- Unexplained English business abbreviations mixed into Chinese comments written for maintainers.

### Recommended comment forms

- Use DocBlock on interfaces and DTOs to describe input/output semantics.
- Use 1 to 3 lines in front of a complex business branch to explain "why this is done this way".
- Mark the trigger conditions and the removal precondition of historical compatibility logic.

## 7. Errors, Logs, and Auditing

- API responses use a unified error structure.
- A successful response fixes `code = 0`.
- The `message` returned by the API and the user-visible errors in `data.errors` must use Simplified Chinese.
- Third-party raw errors are not exposed to users directly; they must be mapped to project-internal Simplified Chinese prompts.
- Key business logs must be correlatable by trace_id, actor, resource, provider, and operation.
- Logs must not output secrets, tokens, passwords, private keys, or full ID card numbers.
- Finance- and audit-related writes must record the actor, source, IP, trace_id, or equivalent fields.
- Configuration and credential output for settings, suppliers, payment, real-name verification, and upstream must use allowlist Resources/DTOs; `api_key`, secret, token, and private key must not appear in API responses.
- Sensitive configuration updates keep the value on empty and replace it on non-empty; a real configuration must not be overwritten by a masked value or an empty string.
- Sensitive items in the plugin configuration must go through encrypted storage and masked preview; on update, an empty value means keeping the old value, and must not be overwritten with a mask or an empty string.
- The `payments` table is a financial audit boundary: no maintenance command, cleanup command, or test may physically delete Payment records; they may only output a read-only report, mark an anomaly, or skip the record.
- Financial ledgers such as `balance_logs` are also part of the audit chain; physical deletion must not be used to hide a reversal, restoration, or refund — use reverse ledger entries, state projection, or an explicit compensating state instead.
- Alipay original-route refunds, balance refunds, and balance restoration for mixed payments must update the bill refund projection fields at the same time, and revenue statistics must exclude or deduct refunded bills.
- Payment and real-name callbacks must pass the signature middleware at the route layer first, while the Service layer keeps secondary signature verification, amount/state validation, idempotency, and the transaction lock.
- Logs for VNC, payment, real-name verification, and upstream must use the unified masking utility or a dedicated `mask*` method to handle token, sign, password, and secret.
- A public VNC token must be consumed once, have a short TTL, and be bound to the necessary context; the VNC password, the raw token, or the full URL must not be returned in the public resolution response.

## 8. Review Loop and Missed-Review Prevention

Backend health checks, refactoring reviews, and third-party integration reviews must produce an explicit report; checking internally and then not reporting is not acceptable.

Mandatory requirements:

- Output a review scope matrix listing directories, modules, endpoints, risk domains, and whether each is covered.
- Every Blocker / High must carry evidence: a file path, method name, route, or command.
- Every review dimension must be labeled "checked / not covered / needs further verification".
- Uncovered items must state the reason, the risk, and a follow-up check suggestion.
- Review findings must be sorted by severity with a fix priority.
- A dimension where nothing was found must still state "no issue found" and the basis of the check.
- The report must end with a completeness self-check table.

The completeness self-check table checks at least:

- Whether `AGENTS.md` was read.
- Whether the current code and the real routes were taken as the primary basis.
- Whether HARD-GATE was checked.
- Whether API request/response engineering was checked.
- Whether third-party pluginization was checked.
- Whether the Simplified Chinese user experience was checked.
- Whether the uncovered items are listed.
- Whether a fix priority is given.
- Whether documentation/skills sync suggestions are given.

## 9. Testing Standards

Priority items to fill in:

- Payment creation, callback, duplicate callback, and failed callback.
- Balance deduction, concurrent deduction, refund or reversal.
- Real-name verification success, failure, vendor exception, field exception.
- ZJMF Finance/ZJMF Cloud upstream provisioning, renewal, suspension, restart, and sync failure.
- Missing permission code, horizontal privilege escalation, user resource ownership.
- Locally repeatable tests under the Provider fake driver.
- Reading/editing sensitive configuration does not leak the original value.
- Supplier list, detail, create, and update responses do not contain the original `api_key`.
- A public VNC token does not return the password, becomes invalid on repeated consumption and on expiry, and is masked in logs.
- The user-side bill detail does not return admin-internal fields or third-party transaction numbers.
- Maintenance commands do not delete `payments`.
- Balance restoration and financial reversal do not delete the original `balance_logs`; reverse ledger entries or compensating audit records are kept.
- A payment original-route refund covers success, failure, duplicate refund, amount mismatch, and transaction rollback.
- Revenue statistics do not count refunded bills as positive revenue.
- A key Job failure does not swallow the exception and can enter retry, failed_jobs, or an explicit compensating state.

Backend changes run by default:

```cmd
cd backend
php artisan test
```

If the full test run has historical baseline failures, add a targeted test and state explicitly whether this change introduces new failures.

Before running backend tests, confirm that test database isolation is in effect: `APP_ENV=testing`, the test connection must not point at a real business database, and a non-sqlite test database name should contain `test`. The full test run must not start before isolation is in place.

## 10. Documentation and Skills Sync

When any of the following changes occurs, the project documentation and the TuraIDC-related skills must be synchronized:

- Adding or adjusting backend layering rules.
- Adding a third-party provider or changing how providers are integrated.
- Changing integration boundaries such as payment, real-name verification, ZJMF Finance/ZJMF Cloud, SMS, and object storage.
- Changing the API response format, error codes, pagination structure, or permission codes.
- Changing API request parameters, response fields, Resources, FormRequests, Request DTOs, or Response DTOs.
- Changing the Simplified Chinese experience rules such as the API error `message`, frontend user-visible prompts, and field validation errors.
- Changing the rules of the review loop, missed-review prevention, and no-report-after-review prevention.
- Changing queue, scheduling, callback, or deployment assumptions.
- Adding comment, logging, or testing rules that must be followed.
- The current code inspection result proving that an existing development document, development standard, or binding document is already out of date.

Sync locations:

- Project rules: `AGENTS.md`, updated only when the rule is upgraded and confirmed as a repository-level hard constraint.
- Backend documentation: `docs/develop/backend.md`.
- API format: `docs/develop/api/format.md`.
- API group navigation: `docs/develop/api/catalog-navigation.md`.
- The TuraIDC skills under the local `.agents/skills/turaidc/` (`SKILL.md`, `code-review/SKILL.md`, and `references/11-backend-engineering-governance.md`); the path differs per machine and is not committed to the repository.

Hand-editing prohibited:

- `docs/develop/api/backend-api-catalog.md`; this file is generated by a script.

Sync principles:

1. The current code inspection conclusion takes precedence over the old document description.
2. A document update must state the new real constraint, the scope where it applies, and the old statements that are no longer trustworthy.
3. Mark uncertain content as "needs further verification"; do not invent rules just to fill the documentation.
4. Updates to rule documents must stay small and precise, to avoid writing a temporary implementation into the long-term standard.

## Related Documents

- [Architecture Overview](architecture.md): system structure and runtime model.
- [Backend Directory Classification Standards](backend-structure.md): where new files go.
- [API Format Standards](api/format.md): request and response contracts.
- [Working Rules](https://github.com/25Cloud/TuraIDC/blob/main/docs/internal/governance/WORKING_RULES.md): verification and commit requirements.
