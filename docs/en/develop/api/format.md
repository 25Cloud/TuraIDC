---
source: develop/api/format.md
sourceHash: ef915f39a3975177dd951681106956e42b5544f34703fa753593f664bfefa98b
translatedAt: 2026-10-26
---

# API Format Specification

- Document status: current direction
- Aligned on: `2026-09-13`
- Audience: backend developers, frontend integrators, API testers, documentation maintainers
- Scope: all HTTP APIs under `backend` that return JSON, mainly covering `/api/v2/admin/*`, `/api/v2/client/*`, `/api/v2/site/*`, `/api/v2/open/*` (open API), `/api/v2/zjmf/*` (ZJMF upstream protocol), and a small number of standalone public endpoints.

## 1. Document Goals

This document defines the unified request handling, unified response format, error code ranges, pagination structure, validation failure structure, and implementation constraints for new endpoints of the backend API in this repository.

When conflicts occur, resolve them in the following order:

1. Actual behavior of the current code, actual routes, results of controlled verification
2. `AGENTS.md`
3. `docs/internal/designs/backend/direct-api-refactor.md`
4. This document
5. Legacy endpoint conventions or historical blueprints

Note: the current development documents, development standards, and other binding documents are not fully authoritative. API governance must rely primarily on inspection of the current code; when this document is found to conflict with the current code, record the conflict first, then update this document or the relevant implementation according to the inspection findings.

During API review, historical endpoint snapshots serve only as review evidence; the current target structure is defined by `docs/internal/designs/backend/direct-api-refactor.md`, this document, and the auto-generated `后端API清单.md`.

### 1.1 Direct Refactor Boundaries

The direct API refactor is complete; subsequent work follows these V2 boundaries:

- Legacy endpoints: `/api/admin/*`, `/api/client/*`, and `/api/site/*` have been removed, with no structural, field, or parameter compatibility layer kept.
- Current endpoints: uniformly located under `/api/v2/admin/*`, `/api/v2/client/*`, and `/api/v2/site/*`, adopting the new specification in one step per this document and `docs/internal/designs/backend/direct-api-refactor.md`.
- Frontend calls: consume V2 endpoints completely per page or business domain, never mixing historical structures.
- Documentation maintenance: routes, requests, responses, tests, and field boundaries follow the V2 endpoints.

## 2. Unified Response Envelope

Except for non-JSON responses such as file streams and controlled resource previews, APIs uniformly return the following envelope:

```json
{
  "code": 0,
  "message": "操作成功",
  "data": {},
  "timestamp": 1760000000
}
```

Field descriptions:

- `code`
  - Business code
  - Fixed to `0` on success
  - A non-`0` integer on failure
- `message`
  - Readable message for the frontend and integration
  - Written in Simplified Chinese; English, pinyin, Traditional Chinese, and raw third-party error text are out of place here
- `data`
  - Business data
  - May be an object, array, scalar, or `null`
- `timestamp`
  - Unix timestamp in seconds

Current implementation sources:

- `backend/app/Support/ApiResponseBuilder.php`
- `backend/app/Traits/ApiResponse.php`

## 3. Success Response Specification

### 3.1 Plain Success

```json
{
  "code": 0,
  "message": "操作成功",
  "data": {
    "id": 123,
    "name": "示例"
  },
  "timestamp": 1760000000
}
```

### 3.2 No Response Data

Used for delete, logout, pure state transitions, and similar operations:

```json
{
  "code": 0,
  "message": "已退出登录",
  "data": null,
  "timestamp": 1760000000
}
```

### 3.3 List and Pagination Success

The standard pagination structure sits inside `data`:

```json
{
  "code": 0,
  "message": "操作成功",
  "data": {
    "list": [],
    "total": 0,
    "page": 1,
    "page_size": 20
  },
  "timestamp": 1760000000
}
```

Pagination field meanings:

- `list`: data on the current page
- `total`: total number of records
- `page`: current page number
- `page_size`: current page size

The current standard pagination is produced by `ApiResponseBuilder::pagination(...)`.

## 4. Failure Response Specification

On failure the envelope is unchanged; only `code != 0`.

```json
{
  "code": 40400,
  "message": "商品不存在或已下架",
  "data": null,
  "timestamp": 1760000000
}
```

### 4.1 Business Code to HTTP Status Code Mapping

Default mapping rules:

| Business code range | Default HTTP status |
| ------------------- | ------------------- |
| `50000+`            | `500`               |
| `42200+`            | `422`               |
| `40900+`            | `409`               |
| `40400+`            | `404`               |
| `40300+`            | `403`               |
| `40100+`            | `401`               |
| `40000+`            | `400`               |

Notes:

- The current mapping logic lives in `ApiResponseBuilder::httpStatusForErrorCode(...)`
- Individual endpoints may override the HTTP status code explicitly, for example a callback whose signature verification fails currently returns `code=40001` with HTTP status `401`

### 4.2 Common Business Codes

| Business code | Meaning                                                           | Common current source                           |
| ------------- | ----------------------------------------------------------------- | ----------------------------------------------- |
| `0`           | Success                                                           | Global and uniform                              |
| `40001`       | Signature verification failed                                     | `VerifyCallbackSignature`                       |
| `40100`       | Not logged in, not authenticated, or session expired              | Authentication exception, permission middleware |
| `40300`       | No permission, role mismatch, or account disabled                 | `ensure.admin` / `ensure.client` / `permission` |
| `40301`       | Email verification or real-name verification precondition not met | `EnsureEmailIsVerified`                         |
| `40400`       | Resource does not exist                                           | Resource lookup failed inside the controller    |
| `42200`       | Parameter validation failed or general business validation failed | `ValidationException`, `BusinessException`      |
| `50000`       | Generic server-side error                                         | Unclassified service exception                  |

## 5. Parameter Validation Failure Format

Laravel `ValidationException` is uniformly rewritten into JSON:

```json
{
  "code": 42200,
  "message": "参数验证失败",
  "data": {
    "errors": {
      "email": ["邮箱不能为空"],
      "page_size": ["page size 必须大于 0"]
    }
  },
  "timestamp": 1760000000
}
```

Conventions:

- The HTTP status code is fixed to `422`
- Detailed field errors live in `data.errors`
- `errors` values keep Laravel's native array structure; the frontend must not assume a single message

Current implementation source: `backend/bootstrap/app.php`

## 6. Authentication and Permission Formats

### 6.1 Token Authentication

Both the admin side and the user side pass a Bearer token through a header:

```http
Authorization: Bearer <token>
Accept: application/json
```

### 6.2 Authentication Failure

Example:

```json
{
  "code": 40100,
  "message": "未登录或登录已过期",
  "data": null,
  "timestamp": 1760000000
}
```

### 6.3 Insufficient Permission or Role Mismatch

Example:

```json
{
  "code": 40300,
  "message": "无操作权限",
  "data": null,
  "timestamp": 1760000000
}
```

### 6.4 Unmet Preconditions

For example, an unverified email or incomplete real-name verification:

```json
{
  "code": 40301,
  "message": "请先完成实名认证",
  "data": null,
  "timestamp": 1760000000
}
```

## 7. Pagination and Query Parameter Specification

### 7.1 Standard Pagination Parameters

New endpoints uniformly use:

- `page`
- `page_size`

Example:

```http
GET /api/v2/admin/products?page=1&page_size=20
```

### 7.2 Legacy Parameter Boundaries

Legacy endpoints once had compatibility parameters such as `per_page`; current V2 routes no longer accept or return these fields.

Rules:

- V2 endpoints do not accept `per_page`, `pageSize`, or `limit/offset` as standard pagination parameters, unless the endpoint is not a paginated list but an explicit business limit parameter, such as a monitor point count cap.
- V2 pagination responses use only `page_size` and never return `per_page` in addition.
- Frontend requests uniformly use `page` and `page_size`.

## 8. Field and Naming Specification

### 8.1 General Naming

- URL paths: lowercase, named by resource semantics
- Request fields: `snake_case`
- Response fields: `snake_case`
- Prefer keeping table names, field names, and filter parameter names consistent

### 8.2 Boolean Parameters

When a controller reads query parameters through `booleanQuery(...)`, it accepts the common boolean expressions:

- `1` / `0`
- `true` / `false`
- `on` / `off`
- `yes` / `no`

An invalid boolean value throws a validation exception and uniformly resolves to `42200`

## 9. File Upload and Non-JSON Exceptions

### 9.1 File Upload

Upload endpoints may use `multipart/form-data` as the request body, but the response still returns the unified JSON envelope.

### 9.2 Non-JSON Exceptions

The following endpoints may skip the unified JSON envelope:

- Direct file stream responses
- Controlled resource previews
- WebSocket

Current typical exceptions:

- `/api/secure-assets/view`

Apart from these exceptions, regular HTTP APIs return neither bare arrays, bare strings, nor custom wrapper structures.

## 10. API Request Engineering Specification

API request handling must be engineered, to keep parameter reads, type casts, default values, permission context, and business preconditions from scattering across controllers.

### 10.1 Request Entry

- New write operations, complex queries, batch operations, import and export, and payment/billing/real-name/upstream endpoints must use `FormRequest`.
- Simple read-only detail endpoints may use route parameters, but resource ownership, permissions, and business visibility are handled by a Service or by Policy/permission middleware.
- Controllers do not call `$request->validate(...)` directly, except in a very narrow legacy compatibility scope that migrates to FormRequest during subsequent governance.
- Controllers do not hand-write a mass of `$request->input(...)` and `$request->query(...)` calls and then assemble business arrays; prefer `$request->validated()`.

### 10.2 Request Fields

- Request fields uniformly use `snake_case`.
- Boolean, enum, amount, date range, and pagination parameters are validated and normalized in the FormRequest.
- Amount fields must state their unit explicitly; for finance, balance, and payment cases prefer integer cents or decimal strings, never an implicit float.
- Date and time fields must state their format and time zone, and the API documentation must describe them.
- Batch IDs use arrays, for example `ids: [1, 2, 3]`; comma-separated strings are not the default format for new endpoints.

### 10.3 Query Parameter Normalization

- List endpoints uniformly use `page` and `page_size`.
- Sort fields must be mapped through a whitelist; user input never reaches `orderBy` / `orderByRaw` directly.
- Filter fields must have explicit types, so empty strings, `null`, `0`, and `false` do not carry ambiguous semantics at the business layer.
- New endpoints carry no legacy parameter compatibility; where a legacy endpoint already has compatibility logic, keep the original implementation boundary and do not spread it into v2 during the refactor.

### 10.4 Request DTOs and Context

When endpoint parameters cross multiple Services, or involve payment, real-name verification, third-party upstreams, or batch operations, introduce a request DTO or command object:

```php
final class CreatePaymentCommand
{
    public function __construct(
        public readonly int $invoiceId,
        public readonly string $gateway,
        public readonly int $amountCents,
        public readonly int $actorId,
        public readonly string $traceId,
    ) {}
}
```

Constraints:

- DTO fields use business-semantic names, not copies of third-party fields.
- DTOs do not read the Request directly.
- DTOs carry no sensitive values such as secrets, tokens, or private keys.
- Context such as `actor_id`, `trace_id`, `ip_address`, and `operator_*` is collected by the Controller or middleware and passed into the Service; deep Services do not re-read the global request.

## 11. API Response Engineering Specification

API responses must be produced by the unified response builder, a Resource, or an explicit DTO transformation, so that controllers, services, and third-party drivers do not each assemble their own JSON.

### 11.1 Response Sources

- Controllers return `$this->success(...)`, `$this->paginate(...)`, `$this->error(...)`, or throw a unified business exception.
- Business Services return domain objects, DTOs, arrays, or paginators, never an HTTP Response directly.
- Third-party Providers return internal DTOs or unified exceptions; raw third-party responses never reach the Controller or the frontend directly.
- Models are not exposed directly as complex endpoint responses; control fields through a Resource instead.

### 11.2 Resources and Field Governance

- Frontend-facing fields are transformed uniformly by a Resource or a dedicated Response DTO.
- Response fields uniformly use `snake_case`.
- Sensitive fields stay out of responses by default, for example password, token, secret, full ID card number, private keys, and raw third-party signatures.
- Status fields return a stable code; display text may be mapped by the frontend or output explicitly by the backend, but magic strings do not scatter across the codebase.
- Amounts must state their unit, for example `amount_cents`, or `amount` plus a documented note; the frontend never guesses.

### 11.2.1 Sensitive Fields and Credential Output

- For settings endpoints, when a sensitive key is encountered the response `value` is an empty string or a safe placeholder, never the raw value.
- Settings endpoints may return `has_value` and `masked_value`, but `masked_value` must be a real mask such as `******` and never equals the raw value.
- Editing sensitive config follows "empty keeps, non-empty replaces": an empty string means the existing secret stays unchanged; only a non-empty value replaces it and is stored encrypted per model rules.
- Provider endpoints never return the raw `api_key` value in list, detail, create, or update responses.
- Provider credentials may return only `has_api_key`, a necessary mask, or existence state, and those fields are explicitly controlled by a Resource or controller whitelist.
- Raw third-party errors go only into redacted logs or internal troubleshooting context, never straight into an API `message` or a frontend prompt.
- Records in the `payments` table belong to real fund flows or the financial audit trail; maintenance commands, cleanup commands, APIs, and tests never physically delete Payment records.
- Public VNC token endpoints never return the VNC `password`; a public token is consumed once, and any subsequent relay token is used only within a controlled short TTL.
- User-side bill details must use a dedicated Resource/DTO whitelist, must not reuse the admin-side detail, and never return internal audit fields such as `raw_status`; payment records belonging to the user may return `payment_no` (system/merchant order number) and `trade_no` (third-party payment order number) for reconciliation display.
- Financial reversals such as balance restore, mixed-payment cancellation, and refund never delete the original ledger entries; the full chain stays in reversing entries, status projections, or audit records.

### 11.3 Error Responses

- Business exceptions map uniformly to responses with `code != 0`.
- Third-party exceptions are converted to internal project error codes and a Simplified Chinese `message`, never exposing the raw provider error.
- Form validation errors uniformly use `42200` and `data.errors`.
- Authentication, authorization, and preconditions stay distinct through `40100`, `40300`, and `40301`.
- An API never signals failure with `code=0` in one place and with HTTP 500 in another.

### 11.4 Message Language Specification

- The `message` every API returns is Simplified Chinese.
- Validation errors surfaced to the frontend in `data.errors` are Simplified Chinese.
- English, Traditional Chinese, error codes, and raw exception details returned by third-party providers are mapped in the backend to internal Simplified Chinese messages.
- Logs may keep a summary of the raw third-party error, but API responses never expose the third-party text.
- Internal backend exception classes, variable names, and enum names may be English; user-visible response text is Simplified Chinese.
- Automated tests assert business codes and key fields first; where user-visible text is involved, assert that it is Simplified Chinese.
- Frontend error messages route uniformly through each client's request layer or a trusted Chinese fallback such as `toUserMessage(error, fallback)`, never displaying axios/browser/third-party `error.message` directly.
- Unless the business explicitly requires it, the admin side and the user side expose no automatic English switch; 403, 404, 500, login, form, notification, empty state, loading state, and action result messages all use Simplified Chinese.

### 11.5 Compatibility and Version Boundaries

- Legacy endpoints are frozen; the v2 refactor does not delete, rename, or add structural fields for them.
- New v2 endpoints inherit neither legacy redundant fields, historical compatibility fields, nor the mixed list/detail structure.
- When legacy endpoint behavior must change, state the impact in the report or requirement and update frontend calls, API documentation, and tests in the same change; such changes serve security vulnerabilities or clear bugs only.
- Historical compatibility fields stay inside legacy endpoints and never enter v2 endpoints.
- The same business object may have different Resources on the admin, user, and site sides, but core status codes and amount units stay consistent.

### 11.5.1 Large Responses and Field Boundaries

New endpoints must actively control response size:

- Target upper limit for a single JSON response is `100KB`.
- Target upper limit for the public site first-screen endpoint is `50KB`.
- Target upper limit for the first payload of admin-side tree selectors and directory selectors is `70KB`.
- List Resources return no detail fields, no full long text, no full config schema, no full relation tree, no full log context, and no raw third-party response.
- Large-text list fields return `excerpt`; the full `content`, `body`, and `html` appear only in detail endpoints.
- Images, QR codes, attachments, and videos return `url` or `asset_id`; base64 never goes into a regular JSON response.
- Nesting depth defaults to at most 3 levels; beyond that, split into sub-resources, pagination, or a separate detail endpoint.

### 11.6 Third-Party Errors and Callbacks

- Third-party errors from payment, real-name verification, SMS, upstreams, email, and object storage run uniformly through `ProviderErrorMapper` or an equivalent mapping layer into Simplified Chinese business messages.
- Raw third-party `msg/message/error` goes only into redacted logs, never into an API `message`, `data.errors`, or a frontend prompt.
- Callback entry points pass through signature middleware first; first-party callbacks prefer HMAC-SHA256, a timestamp, a nonce, and a replay cache.
- The business Service layer still keeps idempotency, amount/status checks, transaction locks, and audit logs; route-layer signature verification alone is not enough.

## 12. Implementation Rules for New Endpoints

Follow these practices when adding an API:

1. Keep the controller thin: receive parameters, authorize, call the service, return the result.
2. Prefer these for success responses:
   - `$this->success(...)`
   - `$this->paginate(...)`
3. Prefer these for failure responses:
   - `$this->error(...)`
   - `throw new BusinessException(...)`
4. Prefer `FormRequest` for parameter validation.
5. List endpoints provide `page` and `page_size` by default.
6. Do not hand-roll a JSON structure different from the unified envelope.

7. Put request normalization in the FormRequest or the Request DTO first.
8. Manage response fields through a Resource or a Response DTO first.
9. Third-party requests and responses never pass straight through to the Controller or the frontend.
10. New v2 endpoints separate list and detail Resources.
11. New v2 endpoints do not reuse a legacy wide Resource as their default output.
12. New v2 endpoints ship with Feature tests covering authorization, permissions, validation failures, success responses, and the field whitelist.

### 12.1 Recommended Controller Style

```php
public function index(ListProductsRequest $request)
{
    $paginator = $this->productService->paginate($request->validated());

    return $this->paginate($paginator, ProductResource::class);
}

public function show(int $id)
{
    $product = $this->productService->findVisible($id);

    if (! $product) {
        return $this->error(40400, '商品不存在或已下架');
    }

    return $this->success(new ProductResource($product));
}
```

## 13. Integration Notes

- Treat `code === 0` as the frontend success check, not HTTP 200 alone
- Frontend failure messages read `message` first and display it as Simplified Chinese
- Form validation errors read `data.errors` first
- When a new endpoint changes a field name or a nesting level, update the frontend calls in the same change
- Automated or third-party callback endpoints that override the HTTP status code must keep the business code and `message` readable

## 14. Maintenance Recommendations

- When the response format changes, update this document first, then the frontend and backend calls
- After the route list changes, regenerate `docs/develop/api/backend-api-catalog.md`
- When new special error code ranges are added, extend Section 4 of this document in the same change
