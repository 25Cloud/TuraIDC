---
source: develop/security.md
sourceHash: 216d06d27af5a4fa28611f41cdd0d242fc3a0f3ffd367334f6a97a73a8484860
translatedAt: 2026-10-26
---

# Security Development Guidelines

These guidelines govern how security is implemented in the TuraIDC backend. They are not a checklist of "things to watch out for": every item maps to a defect that actually occurred in this repository and comes with an executable self-check command.

Division of labor with other documents: the "Key constraint entry points" section of `AGENTS.md` states the one-line, non-negotiable conclusion, while this article gives the reasoning, the boundaries, and the verification method. When they conflict, the running code and tests prevail — then come back and update this article.

> **On the landing status of the WAF layer**: the inbound WAF described in section 2 has landed — `backend/config/waf.php`, `App\Support\Waf\PayloadScanner`, and the `WebApplicationFirewall` middleware (prepended at the front of the `api` middleware group). The rationale for each rule and each exemption entry is written inside `config/waf.php`; right after release you can first enable observe mode (`WAF_OBSERVE_ONLY=true`) to confirm there are no false positives, then switch to blocking. This article's **constraint and boundary judgments** on it are unchanged — those conclusions come from the structural fact that "regular-expression payload matching cannot identify identity", and authentication problems must never be pushed onto the WAF just because it is now live.

---

## 1. Layered Model

Security is not a property of any single layer; it is the result of stacking several layers whose **responsibilities cannot substitute for one another**. Any reasoning of the form "one layer already covers this, so this other layer can be a bit looser" is wrong.

| Layer                          | Responsible for                                         | Implementation                                                                                      | Cannot replace                                 |
| ------------------------------ | ------------------------------------------------------- | --------------------------------------------------------------------------------------------------- | ---------------------------------------------- |
| Inbound WAF                    | Blocking obvious attack payloads and automated scanning | `backend/config/waf.php`, `App\Support\Waf\PayloadScanner`, the `WebApplicationFirewall` middleware | Identity, permissions, credential authenticity |
| Authentication                 | Who you are                                             | `auth:sanctum`, the token/signature services of each protocol                                       | What you can do                                |
| Authorization                  | What you can do                                         | RBAC, scope checks, Policy                                                                          | Whether input is valid                         |
| Input validation               | Parameter structure and business validity               | `FormRequest`                                                                                       | Whether output is safe                         |
| Output sanitization / encoding | Producing no executable content at render time          | `RichHtmlSanitizer`, `TextSanitizer`, `htmlspecialchars`                                            | Whether the data layer was injected            |
| Data access                    | SQL is not concatenated                                 | Eloquent / query builder parameter binding                                                          | Every layer above                              |

---

## 2. WAF: What It Can Do, and What You Must Never Expect From It

### What it can do

- Block automated scanners and bulk probing (UA signatures, sensitive-path probing, guessing backup archives);
- Block injection payloads with a prominent shape (`union select`, `information_schema`, `into outfile`, names of code-execution functions);
- Reduce the chance of being hit by an unknown vulnerability and reduce log noise.

### What it cannot do

The WAF is **regular-expression payload matching**: `PayloadScanner::inspect()` only looks at the string shape of path / query / body / cookie / UA and **has no concept of identity**.

This is not a theoretical inference. In the 2026-08-28 audit, the `zjmf_bridge` plugin was found to be able to forge a JWT for any user (`ZjmfTokenService` used an empty string as the HMAC key), and a PoC hitting `/zjmf/v1/user` returned 200 — **while the WAF was active on that route** (the middleware is prepended to the `api` group, and the bridge routes go through `middleware(['api', ...])`; the `api/v2/zjmf/*` entry in `waf.except` exempts the upstream protocol paths, whereas the bridge sits at `zjmf/v1` and is not on the exemption list). The reason is simple: a forged JWT is byte-for-byte isomorphic to a legitimate one, and no regular expression can tell them apart.

### Prohibited

- **Do not** hand authentication, authorization, privilege-escalation, or credential-forgery problems "to the WAF to solve". Such defects must be fixed in the authentication / authorization layer.
- **Do not** relax parameter binding, `FormRequest` validation, or rich-text allowlist sanitization just because a WAF exists.
- **Do not** use WAF rules as a backstop for business validation (for example, "use a rule to block negative amounts") — business constraints belong to `FormRequest` and the domain services.

### Discipline for Rules and Exemptions

- Every entry added to the exemption list (`waf.except`) is a hole. The entry itself must state **why the legitimate content carried by this path is falsely flagged by the rules**; the path alone is not enough.
- Run rule-set changes in observe mode first (log only, do not block), and only switch to blocking once you have confirmed there are no false positives.
- Rules target attack signatures only, never business vocabulary. Overly broad rules (a bare `substr(`, a bare `user(`) cost more in false positives than they gain; do not add them.
- Rich-text fields (article bodies, ticket content) legitimately contain HTML, and blocking `<div` at the inbound layer breaks the business outright; XSS is the responsibility of allowlist sanitization at the output layer.

---

## 3. Secret Discipline: Reading an Empty Value Must Reject Service

This is the most dangerous defect pattern this repository has ever had.

```php
// 错误：密钥为空时 HMAC 用空串当 key，任何人都能算出"正确"签名
return hash_hmac('sha256', $input, (string) config('x.secret', ''), true);
```

### Rules

1. **Empty means reject.** When any signing, HMAC, or token key reads as an empty value, it must **both** refuse to sign and refuse to verify. Degrading to "compute as usual with an empty key" is never acceptable.
2. **Signing and verification share the same source and the same verdict.** When one key is used by several services, the way it is read (including `trim()`) and the judgment of "is it configured or not" must be consistent. The `zjmf_bridge` vulnerability existed precisely because the signature service guarded against empty values while the token service did not.
3. **It must be written into `backend/.env.example`**, with a note on the consequences of leaving it empty. At the time this key was absent from the example file entirely, so operations had no hint at all when enabling the feature.
4. **Compare with `hash_equals()`**; `==` / `===` must not be used (timing side channel + type juggling).
5. **Validate the `alg` allowlist.** Even though a JWT-style token implementation always recomputes the signature with a fixed algorithm, the algorithm in the header should still be explicitly validated, so that a later addition of algorithm dispatch does not degrade into `alg=none`.

### Current State of This Repository (Full Audit on 2026-08-28)

There are 14 signing points, all compliant except the already-fixed `ZjmfTokenService`. Reference forms of compliance:

- An entry condition carrying `$secret === ''`: `demo_verification`, `stay33`, `demo_pay`, `VerifyTicketUpstreamCallbackSignature`;
- Throwing inside the accessor method: `LeafFaceClient::appSecret()`;
- An entry `isConfigured()` check: `GeetestCaptchaService`;
- `throw_if($secret === '', ...)`: `TicketUpstreamCallbackService`.

---

## 4. XSS: Allowlist, Not Blocklist

### Do Not Hand-Roll Tag Stripping

```php
// 错误：单遍剥离可以被重组绕过
return preg_replace('/<\/?script\b[^>]*>/iu', '', $value);
```

In actual testing this code let 8 out of 9 common payloads through (`<img onerror>`, `<svg onload>`, `<iframe>`, `<object>`, `<embed>`, `<body onload>`, and the `javascript:` scheme all passed through unchanged). The worst case:

```
输入: <scr<script>ipt>alert(1)</scr</script>ipt>
输出: <script>alert(1)</script>
```

The input did not originally contain a complete `script` tag; after the inner part was stripped, the remainder **reassembled into an executable tag** — the sanitization made things worse. That is the inherent flaw of any blocklist approach, not a badly written regular expression.

Likewise, `strip_tags($html, $allowed)` **must not** be used for security sanitization: its second argument only allowlists tag names and leaves every attribute untouched, so for `<img src=x onerror=...>` the `img` tag is on the allowlist and the whole thing passes through carrying `onerror`.

### Division of Labor Between the Three Tools

| Scenario                                                 | Use                                         | Notes                                                                                                                                                                                                                                                          |
| -------------------------------------------------------- | ------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Rich text must be preserved (article bodies, etc.)       | `RichHtmlSanitizer::sanitize()`             | A three-level DOM allowlist: tags, attributes, URL schemes; `iframe/object/embed/script/svg/style/form/base/link/meta` are removed together with their content; on a parse failure the whole fragment is escaped rather than falling back to the original text |
| Plain text only (nicknames, remarks, summaries)          | `TextSanitizer::clean()` / `nullable()`     | Strips every tag                                                                                                                                                                                                                                               |
| Inserting a variable while assembling HTML on the server | `htmlspecialchars($v, ENT_QUOTES, 'UTF-8')` | Output encoding, not sanitization                                                                                                                                                                                                                              |

Using `strip_tags(Str::markdown($content))` to **generate a plain-text summary** is a legitimate content transformation and is outside the scope of this section — but its output must never afterwards be treated as "sanitized HTML".

### Special Care for Template-Type Fields

If some code path emits content written by an administrator **verbatim as HTML** (for example a notification template body), applying `htmlspecialchars` only to the parameter values substituted in is not enough — the template body is itself untrusted input and must go through `RichHtmlSanitizer`.

---

## 5. SQL Injection: Parameter Binding Is the Only Mechanism

- Always use Eloquent / the query builder and let the driver perform parameter binding.
- If `whereRaw` / `selectRaw` / `havingRaw` / `orderByRaw` / `DB::raw` is genuinely necessary, **variables must go through bound parameters** and string concatenation is not allowed; the column name and direction of `orderBy` must pass an allowlist enumeration, because bound parameters do not apply to identifiers.
- `DB::statement` / `DB::unprepared` is allowed only in migrations and the installation flow, and must contain no value that originates from a request.

Current state of this repository: under `backend/plugins/` the hit count for the APIs above is **0**; everything goes through parameter binding. This is the baseline to maintain.

---

## 6. Command Execution and Deserialization

- Every variable part of `exec` / `popen` / `shell_exec` must go through `escapeshellarg()`, and numeric values must be formatted with `%d`. The two existing sites (`SyncUpstreamsCommand`, `VncRelayEnsureCommand`) both comply.
- `unserialize()` must carry an `['allowed_classes' => [...]]` allowlist. Calling it without options is prohibited.
- `eval` / `assert` / `create_function` / `system` / `passthru`: **prohibited**; the current hit count across the repository is 0.

---

## 7. Additional Constraints for Plugins

Plugins are where this repository's security boundary is easiest to bypass (the `zjmf_bridge` vulnerability came from exactly here).

- All plugin-specific logic converges into `backend/plugins/{domain}/{slug}/`;
- **No system-level routes, schedules, or global middleware may be registered**;
- **Do not build your own authentication middleware to bypass the unified authentication entry point.** When a plugin genuinely needs authentication for its own protocol, it must reuse the platform's secret discipline and comparison utilities, and the same credential must not end up with two different judgment rules inside the plugin;
- Every callback entry point must verify the signature (all three payment gateways already do);
- Plugins do not need SSL or CA. This is a settled trade-off, not an oversight: an upstream provider may only offer a plain HTTP endpoint, or present an incomplete certificate chain, and mandatory verification would break the integration outright. The cost is that the transport layer may be observed or tampered with by a man in the middle, so **the compensating controls must be in place** — HMAC signing on both the request and the callback side (secret discipline in section 3), a timestamp and nonce on callbacks to prevent replay, and key fields such as amounts taken from local records rather than trusting the upstream payload unconditionally. **Do not** turn around and relax these checks on the grounds that "there is no TLS anyway".

---

## 8. Self-Check Checklist

When a change touches authentication, signing, rendering, or data access, run through this list:

```bash
cd backend

# 签名密钥是否有空值守卫（每个命中点都要能指出它的守卫在哪一行）
grep -rn "hash_hmac" app plugins --include=*.php | grep -v /tests/

# 是否有用 == 比对签名的（应为空）
grep -rnE "(signature|sign|token|expected|hmac)[^;]*\s(===?|!==?)\s*[^;]*(signature|sign|token|expected|hmac)" \
  app plugins --include=*.php | grep -v /tests/ | grep -v hash_equals

# 插件内原始 SQL（应为空）
grep -rnE "DB::(raw|statement|unprepared)|whereRaw|selectRaw|havingRaw|orderByRaw" plugins --include=*.php | grep -v /tests/

# 危险函数（应只剩已知的 exec/popen/unserialize 三处，且均已加固）
grep -rnE "\b(eval|assert|exec|shell_exec|system|passthru|popen|proc_open|create_function|unserialize)\s*\(" \
  app plugins --include=*.php | grep -v /tests/

# 自制的标签剥离 / 注入过滤（命中即需判断是否应改用白名单净化器）
grep -rniE "preg_(match|replace)[^;]*(script|onerror|onload|javascript:|iframe|union|select)" app plugins --include=*.php | grep -v /tests/

# 新增的密钥类配置项是否已写入样例。必须逐个键名精确检查 ——
# 统计匹配行数（grep -c）毫无意义：仓库里本来就有别的 _SECRET/_KEY，
# 少写一个新键照样得到非零结果，自检会假阳性通过。
for key in ZJMF_BRIDGE_SECRET; do
  grep -q "^${key}=" .env.example || echo "缺少样例配置：${key}"
done
```

When adding a signing / token / callback chain, the tests must cover:

1. Service is rejected when the key is not configured;
2. Forged credentials are rejected (**use credentials assembled by hand, not ones produced by this project's signing service** — the attacker does not have your code);
3. A positive control in which legitimate credentials still pass.

### Reverse Verification

Security test cases must be reverse-verified: apply **only the test files** on top of the unfixed code and confirm they fail as expected. A security test that is always green is more dangerous than no test at all.

Take care that a test case does not reference "a constant introduced only by the fix", otherwise reverse verification errors out on an undefined symbol and masks the behavioral difference you set out to prove — assert **observable behavior** instead (the string actually written to the database, the status code actually returned).

---

## 9. The Evidence Behind These Guidelines

The conclusions above come from a full audit of `backend/app` and `backend/plugins` (7 plugin domains) on 2026-08-28, carried out by tracing the key source and guard location of every signing point one by one, exhaustively enumerating the comparison methods, grepping the whole repository for raw SQL and dangerous functions, and judging the purpose of every XSS sanitization point individually; the bypass of the blocklist filter was confirmed by actually executing the 9 payloads with local PHP.

Found and already fixed: `zjmf_bridge` token forgery.
Found and awaiting a fix: the blocklist-style sanitization of the notification template field (the counterexample in section 4 is taken from there).

## Related Documents

- [Backend engineering guidelines](backend.md): the layering constraints for controllers, services, and plugins.
- [Plugin development](plugins/index.md): the plugin directory, extension points, and where the constraints in section 7 land.
- [API format specification](api/format.md): the unified conventions for responses, authentication, and validation.
- [Testing guide](testing.md): the test environment and commands that the reverse verification in section 8 relies on.
- [Working rules](https://github.com/25Cloud/TuraIDC/blob/main/docs/internal/governance/WORKING_RULES.md): cross-cutting mandatory rules and the verification and commit requirements.
