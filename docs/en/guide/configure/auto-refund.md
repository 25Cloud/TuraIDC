---
status: current
updated: 2026-11-03
owner: integrations
source: guide/configure/auto-refund.md
sourceHash: 9dde0ce74d6b1b020ef210f381392947d243f87c34b470265a4839989b1b0ec4
translatedAt: 2026-11-03
---

# Self-Service Refunds

The plugin directory is `backend/plugins/addons/auto_refund/`, the admin capability domain is `addons`, and the identifier is `auto_refund`. It moves "a user wants a machine refunded" from a ticket into the console: the user picks the machine, writes a reason, and submits; the plugin evaluates the refund policy configured **for that product**, routes the request to the matching channel, keeps a ledger row, and an administrator verifies the upstream side before settling the amount to the user's balance.

Behaviour follows the ZJMF Finance AutoRefund plugin (v1.3.0): four refund types, three refund rules, three refund requirements, all configured **per product**. How much is actually refunded still depends on the upstream and the settlement; the amount shown in this site is an estimate.

## 1. Four concepts

| Concept            | Values                                                       | Meaning                                                                                                                                              |
| ------------------ | ------------------------------------------------------------ | ---------------------------------------------------------------------------------------------------------------------------------------------------- |
| Refund type        | Manual review / Automatic / API ticket / Plugin interconnect | Determines the path after submission: ledger only, refund immediately, open a ticket upstream, or call the upstream `api.php`                        |
| Refund rule        | By duration / By month / Full                                | Determines how the estimate is computed (formula in section 6)                                                                                       |
| Refund requirement | Product first / Category first / Within hours                | Determines who may still apply: first refund for that product, first refund within the same top-level product group, or within N hours of activation |
| Time window        | Hours                                                        | Only used by "within hours"; `0` means unlimited                                                                                                     |

A product has exactly one type, one rule, and one requirement at a time — same as upstream, so do not expect "by duration and by month" together.

## 2. Install and enable

1. Deploy the backend and run `php artisan migrate` once. Table creation and registration are core migrations (a plugin cannot register its own migrations):
   - `2026_11_01_000001_create_auto_refund_applications_table`: the application ledger;
   - `2026_11_01_000002_register_auto_refund_plugin`: registers the plugin, **installed but not enabled**;
   - `2026_11_03_000001_create_auto_refund_product_rules_table`: per-product refund policies;
   - `2026_11_03_000002_add_channel_and_ticket_to_auto_refund_applications`: adds channel and ticket fields to the ledger.
2. In the admin plugin manager, open Self-Service Refunds, fill in the configuration (section 3), then enable it.
3. In "Finance → Self-Service Refunds → Refund Product Rules", configure each product (section 4). **Products without a policy never appear in the user's candidate list.**
4. Rebuild and deploy both frontends (`frontend-user-v4-console`, `frontend-admin-v3`).

After disabling the plugin both entries collapse through the `auto_refund` key in `OptionalFeature::map()` and the endpoints disappear; existing ledger rows and policies are kept.

## 3. Configuration

**Upstream API channel (required only for the plugin-interconnect type)**

| Setting                     | Default                    | Meaning                                                                                                            |
| --------------------------- | -------------------------- | ------------------------------------------------------------------------------------------------------------------ |
| Enable self-service refunds | On                         | When off, the console shows "not available"                                                                        |
| Upstream endpoint           | The upstream `api.php` URL | Must be the POST endpoint; only used by the plugin-interconnect type. Empty means that channel is not ready        |
| Upstream API key            | Empty                      | The full key issued by the upstream; the masked value on their page does not work. Encrypted at rest, never echoed |
| Request timeout (seconds)   | 15                         | A timeout is treated as an unknown outcome and keeps that machine's submission gate closed                         |
| Verify upstream host first  | On                         | Runs `query` before submitting                                                                                     |
| Console notice text         | Empty                      | Shown at the top of the refund center                                                                              |
| Settlement remark template  | Built-in default           | Prefix written into the refund record; the application number is appended                                          |

**Upstream ticket channel (required only for the API-ticket type)**

| Setting                  | Default          | Meaning                                                                                                                                                                      |
| ------------------------ | ---------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Upstream ticket protocol | `zjfm`           | `zjfm`: native ZJMF Finance (`/v1/login_api` for a JWT, then `/submitticket`); `tura`: the upstream is also TuraIDC, reusing the existing `/ticket/create` delivery pipeline |
| Upstream site URL        | Empty            | Required for `zjfm`                                                                                                                                                          |
| Upstream account         | Empty            | Login account for `zjfm`                                                                                                                                                     |
| Upstream API key         | Empty            | Key used by the `zjfm` login, encrypted at rest                                                                                                                              |
| Upstream department ID   | 2                | The `dptid` of the upstream finance/refund department; a wrong value sends the ticket to the wrong department                                                                |
| Ticket title template    | Built-in default | Supports `{product_name}`, `{host_id}`, `{amount}`, `{reason}`                                                                                                               |
| Ticket content template  | Built-in default | Same placeholders                                                                                                                                                            |

For `zjfm`, success means the upstream answers **302 with a Location containing `supporttickets`** (that is how the native API behaves); a JSON response with `code=200` is also accepted as a variant. When neither matches, the ledger records the failure reason and the first 300 characters of the response.

## 4. Configure a product policy

Admin → "Finance → Self-Service Refunds → Refund Product Rules":

- The list shows **every product**, including ones with no policy yet (status "not configured"). Filter by keyword or configuration state.
- Edit one row at a time: refund type, refund rule, refund requirement, time window (hours), enabled switch.
- `window_hours` is editable only when the requirement is "within hours"; `0` means unlimited.
- Turning the switch off removes the product from self-service refunds entirely.

How to choose:

| Situation                                                   | Type                | Note                                                                              |
| ----------------------------------------------------------- | ------------------- | --------------------------------------------------------------------------------- |
| Staff should review each case                               | Manual review       | Ledger only, no outbound request                                                  |
| Small amounts, simple rules                                 | Automatic           | Refunded to balance immediately, no human step                                    |
| Upstream is ZJMF Finance and you want a communication trail | API ticket          | Creates a local ticket and a ticket upstream; upstream replies land in the thread |
| Upstream runs AutoRefund and issued you an API key          | Plugin interconnect | Calls `api.php` `submit`; the user may cancel while pending                       |

## 5. User and admin flow

User side (console "Transaction Records → Refund Center"):

1. The candidate list only shows machines that are active on this site **and whose product has a policy**; each row is labelled with that machine's own "type · rule · requirement", for example "Plugin interconnect · By duration · Product first".
2. Blocked rows state why (product not open for refunds, unpaid, expired, renewed, upgraded or downgraded, free order, unsupported service status, an application already in flight, outside the window, upstream unavailable).
3. After submitting, the message depends on the type: automatic = refunded to balance; manual = waiting for an administrator; API ticket = ticket submitted upstream; plugin interconnect = submitted for upstream review.
4. Only "plugin interconnect + pending upstream review" can be cancelled by the user; other types have no upstream record to revoke and the page does not offer a cancel button.

Admin side (Finance → Self-Service Refunds → Refund Applications):

| Action                  | Effect                                                   | Note                                                                                                                      |
| ----------------------- | -------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------- |
| Settle                  | Refunds the entered amount to the user's balance         | Uses the existing refund path; amounts above the invoice's remaining refundable total are rejected. Verify upstream first |
| Reject                  | Marks the application rejected, no money moves           | Close the loop after an upstream rejection                                                                                |
| Void                    | Force-closes a stuck or unknown-outcome application      | The only manual escape hatch; irreversible and refunds nothing                                                            |
| Test upstream           | Calls `query` and reports machine count and clock offset | First stop for key, address, egress and clock-skew problems                                                               |
| Upstream reconciliation | Lists every upstream machine with its local binding      | Finds "upstream has it, we do not" and the reverse                                                                        |

The detail drawer shows the refund type, channel, local ticket, upstream ticket number and the **policy snapshot taken at submission time** — policies are editable configuration, and without a snapshot nobody can explain why a machine was refundable or how the amount was computed.

## 6. Amount and pre-checks

The estimate follows the upstream formula:

```text
周期秒数 = 到期时间 - 开通时间
每秒单价 = 首购账单实付 / 周期秒数

按时长：实付 - 每秒单价 * 已用秒数
按月：  实付 - 每秒单价 * 2592000 * ceil(已用秒数 / 2592000)
全额：  实付
结果四舍五入到分；小于等于 0 直接拒绝申请。
```

- A machine that is not fully provisioned is forced to the full rule regardless of the configured one.
- The three inputs come from this site: **activation = the service creation time**, **expiry = the service expiry time**, **first payment = the paid amount of the invoice from the activation order** (not the latest renewal invoice).
- The estimate is display only and fills the ticket template placeholders. The plugin-interconnect channel **never sends an amount upstream** (the contract forbids specifying one); the real amount comes from the administrator's settlement and is bounded by the existing refund ceiling.

Hard pre-checks (any hit rejects the application): no product policy, first invoice unpaid, an application already in flight, expired, service deleted, unsupported service status, already renewed, zero first payment, upgraded or downgraded.

## 7. Troubleshooting

| Symptom                                                        | Checks                                                                                                                                             |
| -------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------- |
| A user cannot see a machine                                    | Does the product have an enabled policy? Is the machine bound to an upstream instance (needed by the ticket and API channels)?                     |
| "This product is not open for self-service refunds"            | The policy row is missing or `enabled = 0`                                                                                                         |
| "Upstream ticket department is not configured"                 | The `zjfm` department ID is empty, or the `tura` protocol has neither a department nor a matching delivery rule                                    |
| The ticket channel returns 302 but is treated as failed        | The Location does not contain `supporttickets`; usually the account lacks ticket permission or the department ID does not exist                    |
| Plugin interconnect returns 401 / 403                          | Invalid API key / the upstream plugin licence expired                                                                                              |
| Application stuck at "unknown outcome"                         | The upstream timed out or returned non-JSON. Verify upstream whether an application exists, then settle or void; resubmission is blocked meanwhile |
| Settlement reports "exceeds refundable amount"                 | The invoice has little refundable total left; adjust the amount                                                                                    |
| The user says upstream refunded but the balance did not change | An upstream refund is not a local settlement: the ledger row must be settled here to credit the balance                                            |

## Related documents

- [Upstream supplier integration](upstream.md): upstream accounts, credentials, and catalog sync.
- [Daily operations · Finance](../operate/finance.md): manual invoice, refund, and ledger handling.
- [Plugin development guide](../../develop/plugins/index.md): plugin conventions and boundaries.
