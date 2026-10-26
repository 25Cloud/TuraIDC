---
source: guide/operate/users.md
sourceHash: 1939e2558c48445bc15b483e0ded92ee914ffcf0b172c25a18e514ddc1648907
translatedAt: 2026-10-26
---

# Users and Referral

This document is for site operators running TuraIDC. It explains how users come in, how accounts are protected, how real-name verification is reviewed, how membership tiers and agent discounts are configured, how referral commission is paid out, and how withdrawals are approved. Field names and default values come from the backend code; admin panel paths come from the admin panel routes.

## 1. Registration and Account Ownership

- **Registration fields**: `account` (required, email address or phone number; when the type cannot be determined it reports "Please enter a valid email address or phone number", maximum 100), `code` (required, 6-digit verification code), `password` (required, at least 8 characters, requires a confirmation field), `nickname` (optional, maximum 50), `email` / `phone` (optional), `referral_code` (optional, maximum 24).
- **A verification code is mandatory**: the `account` decides whether the SMS code or the email code is used, and failures report "The SMS verification code is incorrect or has expired" and "The email verification code is incorrect or has expired" respectively.
- **Registration creates the account and the referral code together**: it writes the `users` record (`status` defaults to `1`, normal) and a 6-character uppercase alphanumeric `referral_code`; the balance account in `user_accounts` is created on demand and every amount defaults to `0.00` (creating a user in the admin panel creates the account row immediately). Registration also writes `last_login_at` and `last_login_ip`.
- **The referral relationship is bound exactly once, inside registration**: it happens in the same transaction as account creation. When the referral code is empty, the referrer does not exist, or the referrer is the user themselves, the request passes silently (no error and no binding); a successful binding writes a `client.register.referral_bound` operation log entry. Nothing is bound either when the global referral switch is off.
- **Creating a user in the admin panel**: the entry point is New User in the user list, with the form fields email, nickname, phone number, and password; the service layer also supports an initial `balance` and `credit_limit` (default `0`), which the current form does not expose. A duplicate email reports "Email already exists", and the phone number has a uniqueness check.

## 2. Sign-in and Security Policy

- **Two sign-in methods**: password sign-in and verification-code sign-in (email code or SMS code). Under both methods, `users.status !== 1` is rejected with 40300 "This account has been disabled".
- **Account status** has exactly one switch: `users.status`, `1` normal and `0` disabled, toggled in the Edit dialog on the user detail page.
- **Token lifetime**: by default a token expires after `1440` minutes (24 hours) and idle-times out after `10800` seconds (3 hours); whichever comes first invalidates it. When an administrator changes a user's password, every token already issued to that user is revoked immediately — this is the main entry point for handling a hijacked account.
- **CAPTCHA scene switches**: the entry point is System → Plugin Management → CAPTCHA (`/admin/integration-plugins/captcha`). What can be turned off is "user sign-in" and "administrator sign-in"; what is **forced on and cannot be turned off** is "user registration", "send email verification code", and "send SMS verification code". A switch that has never been saved is treated as on by default.
- **Emergency channel**: `php artisan captcha:scene --list` shows the status of every scene, `php artisan captcha:scene admin_login off` turns off CAPTCHA for administrator sign-in, and `php artisan captcha:scene --disable-plugin` disables the CAPTCHA plugin as a whole (an explicit site-wide decision). Forced scenes do not accept being switched off by configuration, and editing the database has no effect either.
- **Failure-count soft lock** (effective only while the CAPTCHA plugin is **not enabled**, as a fallback):
  - Account plus IP dimension: `5` failures lock it, with a counting window of 900 seconds;
  - Account dimension: it locks only after more than `3` distinct IPs and `10` failures on the account, with a counting window of 1800 seconds. This restriction exists so that a third party cannot lock out someone else's account simply by retrying the target account name from a single IP;
  - A successful sign-in resets all the counters above to zero.
- **The soft lock is not persisted**: it lives in the rate-limit counters, so the admin panel **has no "unlock" button**. Either wait for the window to expire, or have the user sign in successfully once through the verification-code channel (the counters of the verification-code channel and of the password channel are independent of each other).
- **CAPTCHA trigger thresholds** (while the plugin is enabled): `1` failure on the account plus IP requires it, `1` failure on the account requires it, and `8` failures from a single IP require it. The admin side and the client side use counting scopes isolated from each other, so an administrator and a customer with the same name neither accumulate onto each other nor unlock each other.

## 3. Real-Name Verification

- **Fields**: `is_verified`, `verification_status`, `verification_message`, `verification_certify_id`, `real_name`, `id_card`, `verified_at`.
- **"Passed" follows exactly one rule**: `is_verified` equals `1`, or `verification_status` equals `2`.
- **Status values**: `0` not verified, `1` pending verification, `2` verified, `3` verification failed, `4` pending verification, `5` rejected. `5` is produced when an administrator clicks Unbind in the admin panel; storing it also writes the rejection reason (default "Rejected by administrator"), the UI shows "Rejected", and the data effect is that the binding is removed.
- **Admin panel entry points**: Users → Verification List (`/admin/users/verification`) and Verification Management (`/admin/users/verification/manage`), permission `verification.list`. Rejecting/unbinding requires the additional permission `verification.unbind`, and the rejection reason is required; correcting data by hand goes through the `PUT /v2/admin/verifications/{user}` endpoint.
- **Verification plugins**: the directory is `backend/plugins/certification/`, with the built-in `alipay_certify` (Alipay), `baidu_face`, `leaf_face` (face recognition), `smapi`, `stay33`, and `demo_verification`. Enable and configure them at System → Plugin Management → Real-Name Verification (`/admin/integration-plugins/verification`).
- **How real-name verification hooks into business flows**:
  1. Once a product's `purchase_requires.require_verification` is on, orders from users without real-name verification are rejected (40301 "This product requires real-name verification before purchase, please complete real-name verification first"); `require_phone` in the same switch group forces phone number binding (40302).
  2. Applying for a withdrawal requires real-name verification first (see section 7).
  3. Whether a top-up requires real-name verification is decided by the setting `finance.recharge_requires_verification`, on by default; the entry point is the "Account top-up" section of Marketing → Referral → Referral & top-up (`/admin/referral-settings`) in the admin panel, see [Finance and Statements](finance.md).

## 4. Membership Tiers and Reward Rates

A membership tier **does not affect the product price**; it only decides how much referral commission the referrer receives.

- **Table structure** `member_levels`: `name`, `code`, `sales_amount_min`, `sales_amount_max`, `reward_rate`, `status`, `sort_order`, `remark`.
- **Admin panel entry point**: Marketing → Membership Tiers (`/admin/member-levels`), permissions `member_level.list` / `member_level.manage`.
- **Fields and limits**: `等级名称` (tier name; cannot be empty and cannot be duplicated), `等级编码` (tier code; generated from the name when left empty, also cannot be duplicated), `累计销售额下限` (cumulative sales lower bound; default `0`, cannot be less than 0), `累计销售额上限` (cumulative sales upper bound; empty means no cap), `返利比例（%）` (reward rate in percent; `0`–`100`), `排序值` (sort order), `状态` (status; enabled by default).
- **Tier assignment**: it is decided by which **enabled** tier's range the user's cumulative sales amount falls into, and when more than one matches, the tier with the largest `sales_amount_min` wins. Ranges may not overlap; saving validates this and reports "The tier sales amount range overlaps an existing tier".
- **When the sales amount grows**: when a referrer's downline completes a new purchase, the order's paid amount is added into the referrer's `total_sales_amount`, and the **updated** amount is then used to resolve the tier and read that tier's `reward_rate`. The tier upgrade and the commission happen in the same transaction.
- **The cost of changing the rules**: after a tier is saved, a globally unique asynchronous job is dispatched to recompute the tiers of existing users (in batches, 200 users per batch). The job is idempotent, its result depends only on the cumulative sales amount and the current rules, and rerunning it after an interruption converges.
- **Delete restriction**: deleting a tier that still has users is rejected ("Users still belong to the current tier, deletion is not possible"). The tier list is cached for 5 minutes, and the cache is invalidated automatically after a save.

## 5. Agent Groups and the Discount Matrix

Agent discounts are configured as an "agent group × product discount group" matrix, and they are the main way to lower prices for channel customers.

- **Table structure**:
  - `agent_groups`: `name`, `code`, `status`, `default_discount_rate`, `sort_order`, `remark`; a user belongs to a group through `users.agent_group_id`.
  - `product_discount_groups`: `name`, `code`, `min_discount_rate` (lowest discount rate, a floor protection), `cost_rate` (cost rate), `status`, `sort_order`.
  - `agent_group_discounts`: the matrix formed by the three columns `agent_group_id`, `product_discount_group_id`, and `discount_rate`.
- **Admin panel entry point**: Marketing → Agent Discount (`/admin/agent-discounts`), with three tabs in order: Agent Groups, Product Discount Groups, and Discount Matrix; permissions `agent_discount.list` / `agent_discount.manage`.
- **The discount rate is a percentage, not a price factor**: `100` is the original price and `90` means paying 90% of it. The value must be between `0` and `100`, and a value below the product discount group's `min_discount_rate` is rejected ("The discount rate cannot be lower than the minimum discount rate of the product discount group").
- **Resolution order**:
  1. The user has no agent group, or the agent group's `status` is not `1` → no discount;
  2. The product is bound to a product discount group, that group's `status` is `1`, and the matrix contains this combination of agent group plus product discount group → the `discount_rate` from the matrix is used;
  3. When none of the above holds, it falls back to the agent group's `default_discount_rate`; a `null` value here means the fallback is off and the original price still applies.
- **Cost price protection**: the discounted amount may not be lower than the cost amount (original price × the product discount group's `cost_rate`), otherwise it reports "The discounted agent amount cannot be lower than the cost price". Note that the fallback branch of item 3 treats the cost rate as `0`, so that branch never triggers the cost price check.
- **Delete restrictions**: an agent group that still has users → "Users still exist under the current agent group, deletion is not possible"; a product discount group that still has products → "The current product discount group still has products, deletion is not possible"; a duplicate `code` → "The code already exists".
- **Assigning an agent group to a user**: Users → User Detail → Edit dialog → the `代理组` (agent group) dropdown (`0` in that dropdown means no agent group and is saved as `null`), which requires the `user.manage` permission.

## 6. Discount Stacking Rules

This is the rule that gets asked about most. The conclusion is **multiplication and subtraction, not picking the better of the two**. The actual calculation happens only when an order creates the invoice, in a fixed order:

1. Compute the original price from the product configuration and the billing cycle;
2. Apply the agent discount: discounted amount = `round(原价 × 代理折扣率 ÷ 100)`, with the discount rate taken from section 5 (it is `100` when there is no agent relationship, which leaves the price unchanged);
3. Compute the coupon deduction on the **discounted amount**;
4. The invoice amount due, `amount`, equals `max(折后金额 − 券抵扣, 0)`, and `discount` records the coupon deduction.

So when all three are present at once the formula is: **amount due = original price × agent discount rate ÷ 100 − coupon deduction**. The membership tier's `reward_rate` does not take part in this calculation; it only affects the referrer's commission rate.

The price-change window is before the order is placed: the quote token `quote_token` binds the discounted amount and the amount due after the coupon, and it is valid for 600 seconds; the server validates again on submit, so the client cannot alter the price.

## 7. Referral Commission, Freezing, and Withdrawal Approval

**How referral commission is calculated**:

- The preconditions are that the global reward switch `referral.enabled` is on (on by default), the order type is a new purchase, the order status is Paid / Provisioning / Completed, and the buyer has a referrer.
- The calculation base is the order's paid amount (`paid_amount`, falling back to `amount` when empty), not the product's original price.
- The rate is the `reward_rate` of the tier the referrer hits according to the **updated** cumulative sales amount; when no tier matches, the global setting `referral.reward_rate` is used (default `10` on the settings page, range 0–100).
- The reward first lands in `user_accounts.referral_frozen_balance`, and a `referral_rewards` row is written (`status` is `0`, frozen); `available_at` is decided by `referral.reward_freeze_days` (default `4` days on the settings page).
- When it matures, `php artisan referral:release-rewards` turns the frozen amount into withdrawable funds (`status` becomes `1`, released), and a release is also triggered once when a user applies for a withdrawal.
- Invoices without an order go through a different payout path, which only accepts invoices of type `new` or `normal` whose status is paid.
- A refund reverses the reward (`status` becomes `2`, reversed), deducting it from the frozen balance or from the withdrawable balance respectively; if the reward has already been released and the withdrawable balance is insufficient, the system logs a warning instead of swallowing it silently.
- Admin panel entry points: Marketing → Referral Overview (`/admin/referral`) and Reward Records (`/admin/referral/rewards`), permission `referral.list`.
- **The binding cannot be changed**: the referral relationship is bound once at registration, and passing a referral code again when a referrer already exists does not overwrite it. When the referrer's and the referred user's most recent sign-in IPs are the same, the binding is rejected and a `referral.bind.blocked_same_ip` operation log entry is written.

**How withdrawals are approved**:

- The user's request entry point is the console at `/client/referral`, with the fields `amount`, `method`, and `remark`; `method` has only two values: `alipay` (Alipay, also the default) and `balance` (withdraw into the on-site balance).
- The submission gates are checked in order: real-name verification done → amount greater than `0` → not below `referral.withdraw_min_amount` (default `20` on the settings page, message "Rewards must reach X yuan before withdrawal") → withdrawable balance sufficient → no pending request already exists ("A withdrawal request is already pending, please wait for the review to finish"). Choosing Alipay also requires a bound Alipay account, otherwise "Please bind Alipay first".
- The money has already moved at request time: the withdrawable balance decreases, the pending-withdrawal balance increases by the same amount, and a `withdraw_apply` ledger entry is written; the withdrawal record's status is `0`, pending.
- Approval entry point: Marketing → Withdrawal Review (`/admin/referral/withdrawals`), permission `referral_withdrawal.list`. Only pending records carry the two buttons Approve and Reject; in the dialog the approval remark may be left empty, while the rejection reason is required.
  - **Approve**: the pending-withdrawal balance turns into the withdrawn balance, and the status becomes `1`, approved. If the method is `balance`, the same transaction also adds the same amount to `cash_balance` and writes a cash ledger entry with `account_type` `cash` and `event_type` `referral_withdraw_approved`.
  - **Reject**: the amount returns to the withdrawable balance, and the status becomes `2`, rejected.
- **Payout confirmation**: the backend provides `POST /v2/admin/referral-withdrawals/{withdrawal}/payment-confirmations`, which accepts only records that are already approved (status `1`) and whose method is not `balance`; the payout reference number is required, and on success the status advances to `3`, paid, with the payout time filled in. **The admin panel currently only has the Approve and Reject buttons, and no payout confirmation entry point was confirmed in the code**, so when you need it, call the endpoint directly and keep a record.
- The meaning of the four reward balances on the user side: `referral_frozen_balance` frozen, `referral_available_balance` withdrawable, `referral_pending_withdrawal_balance` withdrawal pending, `referral_withdrawn_balance` withdrawn. The "Referral Info" tab on the user detail page shows the referral code, the current tier, the number of direct referrals, the cumulative reward, and the withdrawable amount.

## 8. Admin Panel User Operations and Boundaries

- **User list** (`/admin/users`, permission `user.list`): the columns are ID, account, nickname, balance, provisioned service count, agent group, status, and registration time; filters support keyword and status.
- **User detail** (`/admin/users/:id`, permission `user.detail`) tabs: Basic Info, Referral Info, Products/Services, Invoices, Fund Ledger, Tickets, Operation Log, and Notification Records. The button next to the balance card at the top opens the "Fund Management" dialog directly; for its rules and risks see [Finance and Statements](finance.md).
- **Editable fields**: `nickname`, `status` (normal/disabled), `phone`, `password` (leave empty to keep it unchanged, at least 8 characters), `agent_group_id` (requires `user.manage`). When the phone number is echoed back as a masked value it is treated as unchanged and is not corrupted.
- **Sign in as user**: the user detail page can start "Sign in as User" for the target user; an account that is disabled (`status` not equal to `1`) is rejected ("This customer account has been disabled, signing in on its behalf is not possible"). This is an explicit action on someone else's behalf, so use it only when you are certain you need to.
- **What not to do**:
  - Do not physically delete users: the user model uses soft deletes, so a delete does not make historical invoices, ledger entries, and reward records disappear.
  - Do not change the user status or balance columns in the database directly: the former bypasses the real-name verification and risk control chain, the latter bypasses the optimistic lock and the ledger write.
  - Do not treat "Reject" as the everyday wording for removing real-name verification: rejecting equals unbinding, it also writes the rejection reason, and it only works on users who are currently verified.
  - Do not change the referral relationship: the binding is fixed after registration, and no admin rebinding entry point was confirmed in the code.
  - Do not add to the reward balance by hand: the reward balance only changes through the commission and withdrawal paths; when you need to make up a difference, use a cash balance adjustment and state the reason.

## Related Documents

- [Daily Operations](index.md): the operations map and the recommended daily checks.
- [Finance and Statements](finance.md): balance, invoices, refunds, and reconciliation rules.
- [Orders and Automatic Provisioning](orders.md): the order placement, payment, and provisioning chain.
- [Payment Channel Integration](../configure/payment.md): top-up channels and payment plugins.
- [Email and SMS](../configure/mail-and-sms.md): the verification-code channels that registration, sign-in, and withdrawals depend on.
- [Architecture Overview](../../develop/architecture.md): the overall background on how users, invoices, and orders relate to one another.
