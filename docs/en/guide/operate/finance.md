---
source: guide/operate/finance.md
sourceHash: 3f04dc902fe8f8755abeddb704cc1125f14e1459c4587d142966d5db8ab04e0a
translatedAt: 2026-10-26
---

# Finance and Statements

This document is for the site operators and finance staff who run TuraIDC. It explains where funds come in, how invoices are generated, which channel a refund takes, and in what order to investigate when the books do not match. All amount and status decisions follow the server-side code; the admin panel pages are only entry points to those rules.

## 1. Three-Layer Accounting Model

The system splits "money" across three tables, and every later operation builds on that structure.

The balance table `user_accounts` uses `user_id` as its primary key and answers "how much money does this person have right now". Fields:

- `cash_balance` (cash balance), `credit_limit` (credit limit);
- `referral_frozen_balance`, `referral_available_balance`, `referral_pending_withdrawal_balance`, `referral_withdrawn_balance` (the four sub-accounts for referral rewards, see [Users and Referral](users.md));
- all of the amounts above default to `0.00`.

The table also carries `version` for optimistic locking: when concurrent writes leave the versions inconsistent, the request returns error 40900 "Account data was modified concurrently, please retry" and is never overwritten silently.

The ledger table `account_transactions` answers "where each change came from and what the balance was afterwards".

- `account_type` is one of `cash`, `referral_frozen`, `referral_available`, `referral_pending_withdrawal`, `referral_withdrawn`;
- `change_amount` carries a sign, and `balance_after` is the balance snapshot after that entry;
- `source_type` / `source_id` and `origin_type` / `origin_id` point back to the source document, and `trace_id` runs through every record of the same business operation.

`event_type` is normalized on read, so when you filter, work with the normalized values: `consume` → `invoice_payment`, `refund` → `invoice_refund`, `admin_deduct` and `deduct` → `manual_deduction`, `adjust` → `system_adjustment`, `referral_withdraw_approved` → `referral_credit_cash`. The matching display labels, in order, are: invoice payment, invoice refund, recharge credited, manual recharge, manual deduction, reward converted to balance, system adjustment, real-name verification fee.

The invoice table `invoices` answers "how much should be charged, how much was charged, how much was refunded" and is the only external financial document. Core fields: `amount` (amount due), `discount` (coupon deduction), `paid_amount` (paid), `refund_amount` (refunded), `due_date` (payment term), `status`.

`Order` is only an internal projection on the provisioning side; what the user sees in the console is the invoice. Top-up, deduction, and reward invoices have no corresponding order at all.

**Where to look**: Admin panel → Users → User list → User details → the `资金流水` (Balance history) tab, whose columns are time, type, change, balance after change, note, and operator; the adjacent `账单` (Invoices) tab filters that user's invoices by status and type. On the user side, the same data is on the balance ledger page in the console.

## 2. Invoice Generation and Status

**Types and statuses**:

- **Type** `invoices.type`: `new` (new purchase), `renew` (renewal), `recharge` (top-up), `upgrade` (add-on configuration), `deduction` (deduction), `refund` (refund red-letter invoice), `referral_credit` (referral reward), `manual` (manual invoice). The legacy value `normal` is equivalent to `new`.
- **Status** `invoices.status`: `0` unpaid, `1` paid, `2` cancelled, `3` overdue, `5` refunded, `6` partially refunded. The admin panel status label shows Pending payment / Paid / Cancelled / Overdue / Refunded / Partially refunded.
- Overdue does not mean void: `3` is only a marker, and the invoice can still be paid or cancelled by hand.

**Generation and amount rules**:

- When the user places an order, `CheckoutService::create` creates the invoice directly and only afterwards appends a projection order with `projection_type = provisioning`.
- The default payment term is **7 days** after ordering (`due_date`).
- **Amounts are always recomputed server-side**: the order request must carry the quote token `quote_token`, and the server uses it to re-validate the discounted amount and the amount due after the coupon. `amount` holds the amount due after the coupon, the agent discount has already been folded into `amount` at the quoting stage of ordering, and the original price, agent discount rate, and cost amount are all written into the `config_pricing_snapshot` snapshot so they can be traced later.
- Invoices have a unique index on `(user_id, idempotency_key)`, so a repeated submission never creates a duplicate invoice.

Admin panel entry points:

| Page                        | Path                                         | Permission       |
| --------------------------- | -------------------------------------------- | ---------------- |
| All orders                  | `/admin/finance/orders`                      | `order.list`     |
| New orders                  | `/admin/finance/orders/normal`               | `order.list`     |
| Renewal orders              | `/admin/finance/orders/renewals`             | `order.list`     |
| Add-on configuration orders | `/admin/finance/orders/upgrades`             | `order.list`     |
| Invoice management          | `/admin/finance/invoices`                    | `invoice.list`   |
| Recharge management         | `/admin/finance/recharges`                   | `invoice.list`   |
| New customers               | `/admin/finance/new-customers` (menu hidden) | `finance.report` |

The invoice management page filters by keyword, type, status, start date, and end date; each row offers only two actions, Details and Cancel.

**Cancel** is available only when the invoice status is `0` or `3` and the current administrator holds `invoice.manage`. Cancelling sets the pending payment records under that invoice to failed, cancels the linked projection order, releases the held coupon, and restores stock from the stock hold snapshot. Cancelling changes status; it never deletes.

## 3. Balance and Recharge

**Recharge flow**: the user picks a payment channel in the console → the server creates a pending payment record (`invoice_id` is empty at this point) → the user completes payment → the gateway callback or an active query arrives → the amount is validated → the balance is credited, an invoice of type `recharge` is backfilled, and the ledger entry plus the recharge record are written.

Where the credit lands: `user_accounts.cash_balance` increases, `account_transactions` gets one ledger entry with `event_type = recharge`, and `recharge_records` gets one recharge record (`scene` is `new_purchase` or `renewal`, `entry_type = third_party_payment`).

- **Payment session lifetime is 300 seconds**: a recharge that is not paid before the timeout is set to Cancelled, and `closed_reason = payment_window_expired` is recorded in `callback_raw`. While the session is still valid the user can poll the status in the console.
- **Amount validation**: when an active query reports the gateway as successful, any difference greater than `0.0001` between the amount the gateway actually received and the payment record amount rejects the credit and writes a warning log, and the page shows "The paid amount does not match the recharge amount, please check and retry". This is the checkpoint that keeps one gateway transaction from being credited twice.
- The payment record model enforces at creation that "only real third-party fund inflows may be recorded here; express balance, manual, and free flows through invoices and account ledger entries", with the single exception of the audit record an administrator writes when crediting funds by hand (see section 7).

**Recharge requires real-name verification**: the setting `finance.recharge_requires_verification` is enabled by default (value `1`). While it is enabled, a recharge by a user without real-name verification is rejected (error code 40301 "Please complete real-name verification before continuing").

Where to configure: the "Account top-up" section of Marketing → Referral → Referral & top-up (`/admin/referral-settings`) in the admin panel; saving requires `settings.manage`.

**Recharge management page**: `/admin/finance/recharges` is a **read-only** list: the columns are payment record number, user, payment record, amount, paid amount, status, created at, and paid at; filtering supports keyword, status, and start/end date; there is only a Details button, with no entry point to change status or delete. The details drawer shows the invoice amount, discount amount, paid amount, payment record, and operation log.

## 4. The Two Refund Channels

The entry point is Users → User details → `账单` (Invoices) tab → `退款` (Refund) on the invoice row, backed by the API `POST /v2/admin/users/{user}/invoices/{invoice}/refunds`. In the dialog the refund amount is read-only (it takes the invoice `paid_amount`, or `amount` when that is empty) and **partial amounts are not supported**; both the refund method and the refund reason are required.

How to choose between the two channels:

- **Refund to balance** (`refund_method = balance`): available whenever the invoice meets the preconditions. The amount is added to the user's `cash_balance`.
- **Refund to original payment** (`refund_method = original`): selectable only when the primary payment channel is `alipay` or `balance`. It is always disabled for mixed payments (balance plus third party), and the page shows "This invoice contains a balance payment, so a full refund to the original payment channel is not possible; use Refund to balance instead". This channel also requires the invoice to be linked to a projection order; when it is not, the page shows "The invoice is not linked to an order, so refunding to the original payment channel is not supported yet".

Preconditions (if any one of them is not met, that whole refund channel is unavailable and the page shows the matching message):

1. The invoice status must be `1` paid, otherwise "Only paid invoices can be refunded";
2. A refundable payment record must exist, otherwise "No refundable payment record was found";
3. The payment record is already refunded → "This invoice has already been refunded";
4. The service has already been provisioned for the invoice (`service_id` has a value and the service status is not `0`) → "The service for this invoice is already provisioned; handle the resource in the service console before refunding";
5. The invoice payment has already been moved to the balance as a duplicate payment → "The payment for this invoice has already been moved to the balance as a duplicate payment, so no refund is needed".

Both channels share the same invoice-level lock, which prevents concurrent operations from over-refunding.

What a refund writes: taking Refund to balance as the example, one refund produces four kinds of record:

- `refunds`: `status = 1` completed, `refund_method = balance`, with the operator and the reason recorded;
- one red-letter invoice with `type = refund`: `amount` and `paid_amount` are negative, `status = 1` paid, and `origin_invoice_id` points back to the original invoice;
- one ledger entry with `event_type = invoice_refund`;
- if the original invoice has a recharge record, one negative `refund_offset` recharge record for the remaining offsettable amount, with `origin_recharge_record_id` pointing back to the original record.

## 5. Reconciliation Rules and Inconsistency Investigation

No reconciliation command deletes or rewrites funds data; they only detect and, where needed, backfill.

Main rules:

| Command                                          | Rule and exit code                                                                                                                                                                                                                                                                                                                  |
| ------------------------------------------------ | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `php artisan reconcile:account-balance`          | Compares the latest `balance_after` per `(user_id, account_type)` in the ledger with the corresponding balance field on `user_accounts`; a difference above 0.01 yuan is listed as a discrepancy, a warning is logged, and a non-zero exit code is returned, with `--json` printing the details                                     |
| `php artisan finance:audit-ledger-consistency`   | Compares `cash_balance` with the sum of `change_amount` over ledger entries where `account_type = cash`, and counts the ledger entries missing `source_type` / `source_id` / `trace_id` as well as the entries of "recharge succeeded but the invoice bridge is missing"; with `--strict`, any anomaly makes the exit code non-zero |
| `php artisan trade:reconcile-invoice-order`      | Audits and repairs the binding between `orders` and `invoices` and the payment status projection                                                                                                                                                                                                                                    |
| `php artisan payment:audit-third-party-boundary` | Audits whether payment records carry only real third-party fund inflows; historical non-third-party records are kept for the audit view only                                                                                                                                                                                        |
| `php artisan account:check-projections`          | Checks the account projection completeness and balance consistency between `users` and `user_accounts`                                                                                                                                                                                                                              |

`reconcile:account-balance` account field mapping: `cash` → `cash_balance`, `referral_frozen` → `referral_frozen_balance`, `referral_available` → `referral_available_balance`, `referral_pending_withdrawal` → `referral_pending_withdrawal_balance`, `referral_withdrawn` → `referral_withdrawn_balance`.

Backfill commands, to be used only after a gap is confirmed:

- `php artisan payment:compensate-recharge-invoices`: backfills a recharge invoice for payment records where the recharge succeeded but `invoice_id` is empty;
- `php artisan finance:backfill-refund-records`: backfills records that were refunded but never written to the `refunds` table, and syncs the refund flag on the invoice;
- `php artisan finance:backfill-ledger-sources`: backfills `source_type` and the recharge bridge for ledger entries where confidence is high.

Recommended investigation order:

1. Run `reconcile:account-balance --json` first to pin down the specific user and `account_type`;
2. Open the `资金流水` (Balance history) tab in that user's details and read the entries in time order, using `trace_id` to connect invoice, payment, and ledger entries;
3. When the ledger entries themselves lack source information, run `finance:audit-ledger-consistency --strict` to size the gap;
4. When the amount or the status on the invoice side does not match, run `trade:reconcile-invoice-order`. Note that amount drift (`amount_mismatch`) and "order completed but invoice cancelled" are reported only and **not repaired automatically**, because there is no way to tell which side reflects the real intent;
5. When you need a record on file, hand the command output to the log archiving process, see [Log Archiving](../maintain/log-archive.md).

Common symptoms and where to look first:

| Symptom                                               | Where to look first                                                                                                                                                 |
| ----------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| The user says the balance is short                    | Use `reconcile:account-balance` to identify the account type, then check the `balance_after` sequence in that user's `资金流水` (Balance history)                   |
| The recharge was paid but the balance did not arrive  | The status of that entry on the Recharge management page; when the status is successful but `invoice_id` is empty, run `payment:compensate-recharge-invoices`       |
| The invoice is paid but the order was not provisioned | The retry path in [Orders and Automatic Provisioning](orders.md); on the invoice side run `trade:reconcile-invoice-order` to see whether the projection has drifted |
| The refund button does nothing or is greyed out       | Compare against the five preconditions in section 4; the page shows the specific reason                                                                             |
| Upstream balance alert                                | See section 6; this is the supplier-side balance, not the balance of a user on this site                                                                            |

## 6. Upstream Supplier Balance

This system does not keep the upstream books and does not send money upstream; it only **syncs and records** the upstream account balance, so that you are reminded to recharge before the balance runs out.

- `supplier_balances`: `balance`, `currency`, `low_balance_threshold` (default `20.00`), `low_balance_alert_enabled`, `last_synced_at`, `last_attempted_at`, `last_sync_status`, `last_sync_error`, `low_balance_notified_at`.
- `supplier_balance_logs`: `balance`, `previous_balance`, `delta`, `currency`, `source`, `order_id`, `recorded_at`. `source` is `schedule` (scheduled sync), `provision` (triggered after upstream provisioning completes), or `manual` (queried by an administrator by hand).
- **Low balance decision**: a supplier that has never synced successfully (`balance` is `null`) does not count as insufficient, which avoids false alerts immediately after onboarding; the comparison uses integer cents rather than floating point, so the boundary is not affected by floating-point error.
- Where to configure: set the threshold and the alert switch per supplier under Products → Suppliers (`/admin/products/suppliers`, permission `supplier.list`); "Upstream credit change retention (days)" (default `30`, range 1–365) and "Alert repeat interval (hours)" (default `24` hours, range 1–720) are on the `/admin/automation` page.

## 7. Manual Crediting and the Boundaries of Fund Adjustment

There is exactly one channel of fund adjustment open to administrators: **the `资金管理` (Fund management) dialog on the balance card at the top of Users → User details**.

- Fields: `用户` (user, read-only), `操作类型` (operation type: increase balance / deduct balance), `金额` (amount, `0.01`–`999999`, two decimal places), `备注` (note, required).
- Hard constraints: an amount of `0` is rejected outright; a deduction that would leave the balance below `0` is rejected, echoing back the current balance and the deduction amount; the request must carry an idempotency key (the front end regenerates it every time the dialog opens), and a missing key rejects the operation; replaying the same `(user_id, idempotency key)` returns the document produced the first time without touching the balance again, while the same key with a different amount is rejected explicitly; if the invoice exists but no ledger entry can be found, it fails with an error for you to investigate instead of adjusting the balance a second time.
- What gets written: update `user_accounts.cash_balance` (`version` incremented by one) → write one ledger entry (`manual_recharge` for an increase, `manual_deduction` for a deduction) → create an invoice of the matching type → for an increase, also add a recharge record with `scene = admin_recharge`. The invoice and the ledger entry share the same `trace_id`.
- **Marking an invoice as paid by hand**: the service-layer method `InvoiceService::markPaidManually` exists. Its rules are that only unpaid or overdue invoices are allowed, that only the **full** amount due can be credited (partial crediting is not supported), that it appends a payment audit record with `gateway = manual` (keeping `trade_no`) for reconciliation traceability, and that it sets the pending payment records under that invoice to failed; triggering the business provisioning at the same time is optional. **This capability currently has no admin route or page entry point** (the service method has no callers, and the equivalent method on the order side is called only in tests), so do not put it into a day-to-day operating procedure.

## 8. What Not to Do

- **Do not delete payment records physically**. Payment records use soft deletes, and any operation that makes a record disappear removes the basis of the reconciliation rules.
- **Do not delete invoices physically**. Voiding is expressed as Cancel and refunding as a red-letter invoice; both only change status.
- **Do not use an amount sent by the front end**. Amounts are always recomputed server-side from the product configuration, billing cycle, agent discount, and coupon, and the quote token verifies consistency.
- **Do not edit balance fields in the database directly**. Bypassing `AccountService` bypasses the `version` optimistic lock and the ledger write, which makes `reconcile:account-balance` report a discrepancy forever and leaves the history unrecoverable.
- **Do not hand-edit an invoice's `amount` / `paid_amount` to "square the books"**. That only creates amount drift, and by design the reconciliation commands do not repair amount discrepancies automatically.
- **Do not record a refund through an offline transfer**. The system has exactly two refund channels, Refund to balance and Refund to original payment; paying out referral rewards is a different flow, see [Users and Referral](users.md).
- **Do not treat a non-zero exit code from a reconciliation command as noise**. Non-zero means a discrepancy really exists; locate it with the investigation order in section 5 instead of ignoring it.

## Related Documents

- [Daily Operations](index.md): the operations map and the daily checks to review.
- [Users and Referral](users.md): referral commission and withdrawal use a separate set of balance fields.
- [Orders and Automatic Provisioning](orders.md): the provisioning chain that follows invoice payment.
- [Payment Channel Integration](../configure/payment.md): when the channel is not configured properly, the recharge page shows "No payment method is currently available".
- [Log Archiving](../maintain/log-archive.md): keeping reconciliation and audit output on file.
- [Architecture Overview](../../develop/architecture.md): the overall background of the invoice-first structure.
