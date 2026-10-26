---
source: guide/operate/orders.md
sourceHash: c441c85312f751fd97a0f85089d519518c46f23552ffe38b6c0026958a2c7c55
translatedAt: 2026-10-26
---

# Orders and Automatic Provisioning

The chain between a user paying and receiving a machine: quote → create invoice → generate internal order → payment → upstream provisioning → service instance. This document follows the site operator's troubleshooting order: first understand what each status means, then remember where the money and the user stop respectively when something fails. The transaction record on the user side is the **invoice**; the order is the internal provisioning projection.

## 1. Flow and Statuses

```text
报价(quote_token，10 分钟有效)
   → 建账单 Invoice（用户侧凭证，due_date = 建单 + 7 天）
   → 生成影子订单 Order（内部开通链路）
   → 支付（网关回调或余额）
   → 上游开通 / 续费
   → 服务实例 Service + 账单 fulfillment_pending 清除
```

**Order statuses** (`OrderStatus`):

| Value | Meaning         | Notes                                                                                                                              |
| ----- | --------------- | ---------------------------------------------------------------------------------------------------------------------------------- |
| `0`   | Pending payment | Can be cancelled; renewal shadow orders and admin on-account orders are not affected by "timed-out invoices are always closed"     |
| `1`   | Paid            | Payment received, provisioning has not started yet                                                                                 |
| `2`   | Provisioning    | Submitted to upstream or waiting for manual provisioning; **stuck in this status means the money arrived but the machine did not** |
| `3`   | Completed       | Service activated (or the product was determined not to need automatic provisioning)                                               |
| `4`   | Cancelled       | Unpaid timeout or manual cancellation                                                                                              |
| `5`   | Refunded        | Refund process has been started                                                                                                    |

**Order types** (`OrderType`): `new` (new purchase), `renew` (renewal), `upgrade` (config option, covering the two `kind` values traffic package and host upgrade).

**Service statuses** (`ServiceStatus`): `0` provisioning, `1` active, `2` suspended, `3` expired, `4` cancelled. **The order detail page is read-only**: it only has the three links "View invoice", "View user", and "View service", with no re-order, retry, or refund buttons; to push the flow forward, go through the service detail page and the invoice management page (see sections 5 and 7).

Where to find it in the Admin panel: All Orders (`/admin/finance/orders`), Regular Orders, Renewal Orders, Config Options, and Order Detail (`/admin/finance/orders/:id`). The invoice side is Admin panel → Invoice Management, where unpaid invoices can be cancelled.

User side: website / user portal → My Orders, My Invoices (`/client/orders`, `/client/invoices`), My Services (`/client/services`).

## 2. What the Server Validates When Placing an Order

Placing an order takes only these inputs: `product_id`, `billing_cycle`, `quantity`, `config`, `quote_token`, `user_coupon_id`, and an idempotency key. The amount is not decided by the client: the server recalculates it from the product price + config option markups + setup fee, then applies the agent discount and the coupon, and finally validates it against the amount inside `quote_token`; on a mismatch it reports "The order configuration does not match the quote, please request a new quote".

These cases are blocked in order:

1. The product does not exist or is delisted → "The product has been delisted".
2. Insufficient product stock → see section 6 of [Products and Billing](products.md).
3. `purchase_requires` enables real-name verification while the user is not verified → "This product requires real-name verification before purchase"; enables the mobile number requirement while none is bound → "This product requires a bound mobile number before purchase".
4. `quantity` is not 1 → "Purchasing multiple service instances at once is not supported yet, please place separate orders". **Ordering multiple instances requires separate orders**; buying several machines in one request does not work on this chain.
5. The quote token is invalid or expired → "The quote token has expired, please refresh the configuration and retry" (valid for 10 minutes); the idempotency key is missing → "The request is missing an idempotency key, please refresh the page and retry"; the billing cycle has no price → "Invalid billing cycle".

**Duplicate order prevention** has three layers: the idempotency key mapping (valid for 15 minutes), the request fingerprint (the same product, cycle, configuration, and coupon within 180 seconds reuses the same invoice), and the `invoices(user_id, idempotency_key)` unique index as a backstop. Repeated clicks on submit do not create multiple invoices.

## 3. Payment and Callbacks

All payment entry points are unauthenticated routes (gateway callbacks require it):

- Alipay: `POST /api/v2/payment/alipay/notify`, with a dedicated signature-verification middleware + a rate limit of 60 requests per minute per IP.
- Other gateways: `GET|POST /api/v2/payment/notify/{gateway}`, with the signature-verification middleware + a rate limit of 60 requests per minute per IP.

The processing order is "verify the signature → lock the `payments` row → check idempotency (an already successful payment returns success immediately) → update the invoice and the order projection inside a transaction". **After changing a callback URL or secret you must run one real small payment again**, because a signature failure looks exactly like a timeout to the callback side, which then keeps retrying. For payment channel configuration see [Payment Channel Integration](../configure/payment.md).

Unpaid invoices and orders are closed by a scheduled task **5 minutes after the order is created** (`paymentSessionTtlSeconds()`), with `actor_name` recorded as `schedule:invoice-cleanup`. The invoice's own `due_date` is 7 days, however — **the 7 days here is only the dunning convention, not the payment window**.

Two kinds of records are exempt from this 5-minute rule, and precisely because stock was never deducted, cancelling them does not restore stock: renewal invoices (`type = renew`) and their shadow renewal orders are billed by `due_date`, otherwise renewal reminders would never go out; and the on-account invoices and orders produced by manual admin provisioning (`config_snapshot.admin_manual = true`).

## 4. Automatic Provisioning After Payment

When a successful payment enters fulfillment, the `new` and `renew` types **run synchronously inside the callback request**, and only a synchronous failure falls back to retries on the `provision` queue. Parameters of the queue job `ProcessPaidOrderFulfillmentJob`: at most 3 attempts, a 1200-second timeout per attempt, backoff of 30/120/300 seconds, the order ID as the unique key, and `WithoutOverlapping` to prevent concurrency.

Automatic provisioning submits to upstream only when four conditions hold at once (`shouldAutoProvision`):

1. `products.auto_setup = 1`;
2. The product is bound to a supplier (the resolved supplier ID is greater than 0);
3. The product is bound to an upstream product ID;
4. The supplier's plugin declares provisioning capability.

**If any one of them fails, the service stops at "provisioning" with the reason `待人工开通` written in, and the order stops at "provisioning".** The most common case: the product has "automatic provisioning" ticked but no upstream product bound, so the user pays and the machine never appears by itself. Another case is upstream returning a status other than `active`; the order likewise stops at "provisioning" with **no automatic fallback retry**, so it can only be moved along afterwards by the "User Product Status Sync" scheduled task or handled manually (the code does not confirm that this status advances the order to "completed" automatically).

## 5. Provisioning Failure: Symptoms, Retries, and Manual Re-runs

**Where a failure is written** (inspect in this order when troubleshooting):

| Location                                                           | Content                                                                                                                                                                        |
| ------------------------------------------------------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `services.provision_data.provision_error`                          | Failure reason (a business exception keeps its original message; other exceptions are unified to "Upstream provisioning failed, check the supplier configuration or the logs") |
| `services.suspended_reason`                                        | The same message truncated to 200 characters, visible directly in the service list                                                                                             |
| `services.provision_data.last_provision_attempt_at`                | Time of the most recent attempt                                                                                                                                                |
| Provisioning attempt records in the service upstream binding table | `success` / `failed` plus the failure reason, including the order number and upstream product ID, used to trace each submission                                                |
| Logs                                                               | Keyword `[自动开通] 支付后上游开通失败`, including the order number, service ID, supplier ID, and upstream product ID                                                          |

After a failure the service status returns to `0` (provisioning) and the order stays at `2` (provisioning).

**Limits of automatic retry**: once the queue's 3 attempts are exhausted, the `failed()` hook marks the paid-but-unfulfilled new-purchase invoice with `requires_refund = true` and writes an error log. **It only sets the flag, it does not refund automatically** — the code comment explicitly requires a human to verify with upstream first. The flag is skipped when the order type is not `new`, the invoice is not paid, or the order is already completed. If the failure cause is insufficient upstream balance, that is detected and a separate notification is sent, but the order is not suspended; an operator has to top up and re-run.

**There are two manual re-run entry points — do not mix them up**:

| Entry point                    | Location                                                                                                                                                                                                                                 | Behavior                                                                                                                              |
| ------------------------------ | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------- |
| Resubmit upstream provisioning | Admin panel → User Management → User Detail → Service Instance Management → Service Detail → the "Manual Provision" button (the button is available only when the service is at `0` provisioning **and** `provision_error` is not empty) | Calls `retryFailedProvision()` and walks the upstream cart again: clear the cart → add the product → checkout → fetch the instance ID |
| Link upstream instance         | The dialog at the same entry point (titled "Manual Provision / Link Upstream"), where you fill in the "Upstream Instance ID"                                                                                                             | Binds an existing upstream instance to the local service without ordering from upstream again                                         |
| Command-line re-run            | Run `php artisan provision:retry-failed` on the server                                                                                                                                                                                   | **dry-run** by default, scanning and printing only; add `--execute` to actually retry; `--limit=50`, `--max-retries=3`                |

The command-line re-run treats an order as an "orphan" only when three conditions hold at once: the service is at `0` provisioning, the order is at `2` provisioning, and `provision_data.provision_error` is not empty. Records that reached the `retry_count` limit (3 by default) are skipped with a note that manual intervention is required. On failure it accumulates `provision_data.retry_count` and writes `last_retry_at` and `last_retry_error`.

**Verify upstream before touching anything**: the upstream provisioning flow is "clear the cart → add to cart → checkout → fetch the instance ID", with no upstream idempotency key lookup. If the previous failure happened after a successful checkout but before the instance ID was fetched, re-running directly **provisions another machine**. So log in to upstream first and confirm whether the instance already exists; if it does, use "Link upstream instance" and do not click resubmit again. This limitation is written into the command's source comments, and it is also why the command defaults to dry-run.

**The money was collected but provisioning genuinely cannot succeed**: after the invoice is flagged `requires_refund`, verify manually and then start the refund. For how refunds relate to stock restoration see section 6 of [Products and Billing](products.md).

**Another common cause of provisioning failure is a problem on the upstream supplier side**; for credentials, connectivity, and whether the upstream product binding is correct, see [Upstream Supplier Integration](../configure/upstream.md).

## 6. Automatic Renewal and Expiry Reminders

**Automatic renewal** (`AutoRenewService`, the "Service Auto Renewal" scheduled task) runs **3 days** before expiry (taken from the config `idc.auto_renew_days_before`, default 3), and scans records that satisfy all of: `services.auto_renew = 1`, status active **or** expired, and `expires_at` falling on the target day.

Processing logic:

1. Compute the amount due from the service's current billing cycle.
2. If a renewal order that the user paid but fulfillment has not completed already exists for this expiry date, **skip the charge and wait for it to be fulfilled**, avoiding a duplicate order.
3. Insufficient balance or an amount due not greater than 0 → no charge and **no executed marker written**, so the next schedule run retries (this shows up as "auto renewal succeeds by itself once the balance is topped up").
4. Sufficient balance → create a renewal order and pay it from the balance, then follow the same fulfillment chain as a manual renewal.
5. An exception is thrown → write an error log, clear the executed marker, and wait for a retry.

A given service is processed only once per expiry date (the unique rule key of the execution log includes the expiry date and the lead days). In the insufficient-balance and exception cases the marker is cleared, so it retries repeatedly instead of trying only once.

**The renewal amount uses the locked price stored on the service, not the product's current price**. When a service is created it writes `locked_pricing` (a snapshot taken at provisioning time of the standard renewal price for each cycle, based on the **undiscounted original price**), and renewal then stacks the agent discount and the coupon on top of that. This exists so agent users are not discounted twice on every renewal round. If `locked_pricing` is empty, the per-cycle prices are derived from "renewal base original price ÷ months in the current cycle". **So changing a product price does not immediately affect the renewal amount of existing services**; to renew an existing service at the new price you must adjust that service's renewal price setting separately (the backend supports the operation, but the admin panel entry point is not confirmed in the code).

**Pre-expiry reminders** are sent as an email and an in-site message 7 / 3 / 1 days before expiry (these three days are the hardcoded `[7, 3, 1]` in the code; there is no admin configuration entry to change them). **Automatic renewal invoices**: within the 7-day window before expiry, a pending invoice is created for services with automatic renewal enabled, executed by the "Invoice Automation Maintenance" scheduled task.

## 7. Automatic Suspension and Release

All four switches live on the **Admin panel → Automation Policy** (`/admin/automation`) page, with defaults taken from `SettingService::defaultAutomationConfig()`:

| Config option                   | Default | Effect                                                                   |
| ------------------------------- | ------- | ------------------------------------------------------------------------ |
| `expire_suspend_enabled`        | On      | Master switch for automatic suspension on expiry                         |
| `expire_suspend_after_days`     | `0`     | How many days after expiry to suspend; 0 means from the day after expiry |
| `expire_suspend_notify_enabled` | On      | Send a notification after suspension (email + in-site message)           |
| `expire_terminate_enabled`      | **Off** | Master switch for automatic release after suspension                     |
| `expire_terminate_after_days`   | `7`     | How many days after suspension to release                                |

**Suspension** (`ServiceLifecycleAutomationService::suspendExpiredServices`) runs when: `expire_suspend_enabled = 1`, the service status is active, and `expires_at` is earlier than "current time − `expire_suspend_after_days`". The comparison is **strictly less than**, so with the default value the expiry day itself is not suspended; it is caught from the first run on the following day.

Suspension changes the service status to suspended, writes `suspended_reason` = `已到期` ("expired"), and records the suspension time in `provision_data.expired_suspended_at`. Before writing it re-reads the status and the expiry time once, so **a service that just renewed successfully (status or expiry time already changed) is not suspended by mistake**.

**Release** (`cancelExpiredServices`) has three steps and is off by default:

1. Time precheck: release time = **suspension time** + `expire_terminate_after_days` (`expire_suspend_after_days` is used for fallback calculation only when `expired_suspended_at` is missing). If the time has not arrived, skip and change no status in this run.
2. Call upstream to destroy the instance (aligned with the ZJMF Finance downstream `Host::terminate` protocol). If destruction fails, **keep the suspended status and retry on the next run**, making no local changes.
3. Set the local status to cancelled, clear `suspended_reason`, and remove `expired_suspended_at`. If the service is renewed and restored while upstream destruction is in progress, locally **skip the cancellation and write a warning**: `[定时任务] 上游实例已销毁但本地服务状态已被并发变更，请人工核实` — at this point the upstream machine is already gone, so manual compensation is required.

**Two judgment points you must watch**:

- The lower bound of `expire_terminate_after_days` is 1 (the configuration layer clamps it to no less than 1), and the clock starts at the **suspension time**, not the expiry time. So "suspend 30 days after expiry + release 7 days later" = release on day 37, not day 7.
- If a service expired long ago, far beyond `expire_suspend_after_days`, and `expired_suspended_at` was never written, the fallback value is "`expires_at` + `expire_suspend_after_days`", which may **already be in the past**. That means **on the run that turns the release switch on, these old services are released immediately**. Check the expiry times of existing suspended services before enabling it.

## 8. Scheduling Dependencies and Verification

All the automation above is driven by the schedule heartbeat; task names and frequencies:

| Task                           | Frequency                                           | What it does                                                                                                                     |
| ------------------------------ | --------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------- |
| Service Auto Renewal           | Every 4 heartbeat slots (about 15 minutes per slot) | Automatic renewal from balance                                                                                                   |
| Service Lifecycle Maintenance  | Every 15 minutes (configurable)                     | Suspension on expiry, suspension notifications, release on expiry                                                                |
| Invoice Automation Maintenance | Hourly (configurable)                               | Renewal reminders, automatic renewal invoice creation, invoice due reminders, overdue flagging, renewal fulfillment compensation |
| Invoice and Top-up Cleanup     | Every 15 minutes (configurable)                     | Close timed-out unpaid invoices, orders, and top-up orders                                                                       |
| User Product Status Sync       | Every heartbeat slot                                | Pull upstream instance status back to local                                                                                      |

A heartbeat slot is 15 minutes by default. When the heartbeat or the queue is not running, none of the "automatic" steps in sections 4, 6, and 7 happen, and what the user sees is "paid but stuck in provisioning". For deployment and scheduling configuration see [Deployment and Scheduling](../deploy/scheduling.md).

**Integration and verification checklist**:

1. Run one real small order through "place order → pay → automatic provisioning → receive the purchase notification", and confirm the order lands on "completed" and the service lands on "active".
2. Turn on the product's `auto_setup` without binding an upstream product, pay once, and confirm the service stops at "provisioning" with the reason `待人工开通` — this verifies whether the manual fallback path is visible. Temporarily break the upstream credentials and pay again (remember to roll back), then confirm that `provision_error`, `suspended_reason`, and the failed attempt records all contain content.
3. Set a test service's `expires_at` to yesterday, trigger "Service Lifecycle Maintenance" once manually, and confirm it is suspended with `suspended_reason` set to `已到期`.
4. Create an invoice and leave it alone; after 5 minutes confirm its status changed to cancelled and that `schedule:invoice-cleanup` is visible in the record.

## Related Documents

- [Products and Billing](products.md): groups, pricing, stock, and listing — configure products first, then talk about placing orders.
- [Upstream Supplier Integration](../configure/upstream.md): nine out of ten provisioning failures start here.
- [Payment Channel Integration](../configure/payment.md): callback URLs and secret handling discipline.
- [Deployment and Scheduling](../deploy/scheduling.md): heartbeat, queues, and scheduled tasks — look here first when automation is not running.
- [Migrating from ZJMF Finance](../migrate/zjmf-finance.md): migration conventions for existing orders and services.
- [Daily Operations](index.md): the operations map and the daily recommended review.
- [System Architecture](../../develop/architecture.md): module boundaries of orders, invoices, and the provisioning chain.
