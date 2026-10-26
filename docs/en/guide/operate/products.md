---
source: guide/operate/products.md
sourceHash: 949f286881635dcdb970bd01febe4ee84cbe91de8958f81753ccccc42367d69b
translatedAt: 2026-10-26
---

# Products and Billing

A product is the configurable unit this site sells: it is here that you decide what is sold, at what price, with how much stock, and whether provisioning is automatic. This document follows the order "create groups first, then products, then set prices and stock", and closes with a verification checklist. Every conclusion comes from the current code implementation; for field defaults and validation, the code is authoritative.

## 1. Configuration Order

1. **Product catalog**: create a first-level category, then create second- and third-level groups beneath it; products hang under third-level groups.
2. **Product type**: the `code` and `product_type` of the first-level category decide whether the product belongs to a business type such as cloud server, game cloud, CDN, or virtual host; this determines the entry category on the official website and which set of UI the user console renders.
3. **Product**: fill in the group, specs, price, config options, and stock.
4. **Billing cycles and prices**: give a price for at least one cycle; the remaining cycles can be derived proportionally by the system (see section 4).
5. **Discount group**: when agents need tiered pricing, first create a "discount group" under "Agent Discount", then bind it on the product (see section 5).
6. **Upstream and stock**: bind the supplier and upstream product, turn on automatic provisioning, and leave stock to the scheduled sync (see section 6).
7. **Listing**: set `status` to enabled; only then does the official website sell it.

## 2. Three-Level Product Group Structure

The official website drills down level by level through first, second, and third level; products **can only** hang under third-level groups.

| Level  | Table                   | Key fields                                                                        | Purpose                                                                                               |
| ------ | ----------------------- | --------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------- |
| First  | `first_product_groups`  | `code`, `product_type`, `name`, `icon`, `banner_image`, `is_visible`, `is_system` | Top-level category on the official website; `code` participates in deriving the product business type |
| Second | `second_product_groups` | `first_product_group_id`, `name`, `is_visible`                                    | Middle navigation level                                                                               |
| Third  | `third_product_groups`  | `second_product_group_id`, `name`, `is_visible`                                   | The level products actually belong to (`products.product_group_id` points here)                       |

- `is_visible = 0` hides the whole level from the official website. Official website queries require **all three levels** (third, second, and first) to be visible; if any one level is turned off, the products along that entire path no longer appear on official website pages.
- Creating a product requires `third_product_group_id`; otherwise saving fails with "The product must be mounted under a third-level group". A second-level group without a first-level owner reports "The second-level group is missing its first-level owner". When the explicitly passed first/second-level `id` does not match the third-level group's actual ownership, it reports "The first-level group and the second-level group do not match".
- **Submitting with only the first- and second-level groups filled in and no third-level group** is the most common failure point when revamping a compressed site: the product is not created, and no partial write happens either.
- The product type is not selected directly on the product row; it is derived from the first-level group's `product_type` (or from `code` through a legacy mapping) and then written into `products.product_type` and `service_type_code`. The order "create the right first-level category first, then create the product" therefore cannot be reversed; if you change the type of the first-level category after products exist, the type of every product under that category is rewritten along with it, and both the official website entry and the user console template change accordingly.

## 3. Product Field Reference

Create/edit entry: Admin panel → Product Catalog → Create Product. The form is split into sections such as "Basic", "Pricing", "Interface", and "Product Configuration".

**Basic**

| Field                       | Required     | Default   | Effect                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                  |
| --------------------------- | ------------ | --------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `custom_display_name`       | Optional     | Empty     | The name shown to users for the product. By default the system fills it with a normed spec summary (such as `2核2G`, `2核2G30Mbps`); when left empty it is generated automatically from the specs (CPU/memory and so on); when neither is available it shows "Unconfigured spec `#<id>`". The group hierarchy is not part of the product name; it is displayed separately by the product path (`product_full_path`) — if this string shows up in the product list, that product's `config_options` or `purchase_requires.upstream_default_config` is not configured yet |
| `third_product_group_id`    | **Required** | None      | Determines which official website path the product hangs under                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                          |
| `console_template`          | Optional     | `compute` | Determines which set of UI the user console renders: `compute` (cloud host semantics: power, reinstall, VNC, security groups), `port_mapping` (port mapping), `cdn` (traffic/acceleration region/protection), `virtualhost` (panel-based hosting). A value outside the enum silently falls back to `compute` — the typical symptom of a misconfiguration is "power on/off buttons showing up in the console of a virtual host product"                                                                                                                                  |
| `product_discount_group_id` | Optional     | Empty     | Agent discount group; see section 5                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                     |
| `cpu_model` / `cpu_turbo`   | Optional     | Empty     | Display-only specs; not used in pricing                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                 |
| `description`               | Optional     | Empty     | Rich text for the product details. It passes whitelist sanitization before being stored, to prevent injection, and is rendered directly on the front end once saved                                                                                                                                                                                                                                                                                                                                                                                                     |
| `remark`                    | Optional     | Empty     | Internal note visible only in the admin panel                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                           |
| `sort_order`                | Optional     | `0`       | Ordering within the same group; drag-sortable on the list page                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                          |
| `status`                    | Optional     | `1`       | Listing switch; see section 7                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                           |

**Pricing and Stock**

| Field        | Required     | Default | Effect                                                                            |
| ------------ | ------------ | ------- | --------------------------------------------------------------------------------- |
| `pricing`    | **Required** | None    | Billing cycle prices, structure `{ "monthly": "30.00", ... }`                     |
| `setup_fee`  | Optional     | `0`     | Setup fee, charged once per instance, added straight into the invoice amount      |
| `stock`      | Optional     | `-1`    | `-1` means unlimited; see section 6                                               |
| `auto_setup` | Optional     | `0`     | Automatic provisioning switch; see [Orders and Automatic Provisioning](orders.md) |

**Purchase Conditions and Provisioning Parameters**

| Field                                    | Default                        | Effect                                                                                                                                                                                                                              |
| ---------------------------------------- | ------------------------------ | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `purchase_requires.require_verification` | Off                            | When enabled, orders from users without real-name verification are rejected: "This product requires real-name verification before purchase"                                                                                         |
| `purchase_requires.require_phone`        | Off                            | When enabled, orders from users who have not bound a phone number are rejected                                                                                                                                                      |
| `purchase_requires.provision_hostname`   | Empty (equivalent to `system`) | Naming rule for the machine at provisioning time: `system` generated by the system, `fixed` a fixed hostname, `prefix` a letter prefix plus a random suffix (`length` limited to 4–63). Can be set in bulk from the product catalog |

> Although both the API and the database support `setup_fee` (`min:0`), the admin product edit page has no such input, so it can only be written through the API or another bulk product entry point. (No entry point for setting a setup fee was confirmed in the admin UI)

## 4. Billing Cycles and Prices

Billing cycles the system supports (the `BillingCycle` enum): `monthly`, `quarterly`, `semiannually`, `annually`, `biennially`, `triennially`, `one_time`, `free`, and `ontrial` (trial, returned by the upstream only). The legacy aliases `yearly`, `onetime`, and `biannually` are normalized automatically.

- **The admin product edit page only provides four price inputs: monthly, quarterly, semiannual, and annual**; the other cycles are not on the form. When you need more cycles (no such entry point was confirmed in the admin UI), the API is the only route.
- On save the system derives: if a cycle price is missing and the monthly price is greater than 0, it is filled in as "monthly × number of months in the cycle" (1/3/6/12). **If monthly is left empty or set to 0, the other cycles are not generated either**.
- Validation: `pricing` must be a non-empty array and at least one cycle amount must be greater than 0; otherwise it reports "Please configure a price for at least one billing cycle". Cycles whose amount is 0 or negative are dropped outright.
- There is one more fallback when prices are read: if the target cycle has no price, it is computed as "monthly × months"; if monthly is missing too, the amount is recorded as 0 and the order is rejected ("Invalid billing cycle").
- Invoice amount = `pricing[周期]` + config option markups + `setup_fee`. The markup breakdown and the total are written into the invoice's pricing snapshot, so changing the product price does not alter historical invoices.

## 5. Config Options, Markups, and Agent Discounts

**Config options** (`config_options` JSON): each array element contains `form_key`, `name`, `option_mode` (`select` / `range` / `text`), and `sub[]` (child items). When pricing, the field key **prefers `form_key`** and falls back to `field` only when absent; if neither is available it maps by `option_type`. This explains why multiple config options on the same product must be distinguished by `form_key` — using `field` for all of them makes them overwrite one another.

- Items with `option_mode = text` (purchase notices such as "strictly prohibited", for example) are display-only: they take no part in pricing and are not written into the configuration snapshot.
- Config options and child items with `hidden = 1` are neither displayed nor priced; options the user did not select produce no markup.
- For the `range` type, the "number of steps" is computed from `qty_minimum`, `qty_stage`, and the bounds of the matched child item, then multiplied by that child item's unit price for the cycle. If the child item's unit price lacks that cycle, it is converted the same way as "monthly × months".
- When the submitted value exceeds every tier and there is no "no upper limit" fallback tier, the system reports "Config option 'xxx' is out of the selectable range" and rejects the order instead of letting it through at 0.

**Products connected to an upstream**: config option markups go through the upstream's `/quote` real-time quote, and the `markup` on the config option is then applied on top. `markup.mode` has four values: `inherit` (follow the upstream, the default), `multiplier` (upstream price × multiplier), `fixed` (merchant-defined unit price × quantity), and `both` (defined unit price × quantity × multiplier); all of them support an `extra_amount` surcharge.

- Switching modes does not clear the stored `multiplier` / `unit_price`, but pricing branches explicitly on the current `mode`, so leftover values from the previous mode are not counted.
- If `unit_price` is forgotten in `fixed` mode, it degrades to the upstream cost price — the symptom being "the custom unit price looks like it took effect, but the actual markup is 0".
- When the upstream quote endpoint is unreachable, it degrades to "only items configured as `fixed`/`both` in the admin panel with a unit price filled in are priced", and the rest show 0.

**Agent discount**: three layers of settings, from coarse to fine.

| Setting                 | Location                                     | Fields                                                                 | Notes                                                                                               |
| ----------------------- | -------------------------------------------- | ---------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------- |
| Discount group          | Admin panel → Agent Discount                 | `name`, `min_discount_rate` (default `100`), `cost_rate` (default `0`) | The tiered pricing floor and cost ratio for a group of products                                     |
| Product binding         | Admin panel → Product Catalog → Edit Product | `product_discount_group_id`                                            | Assigns the product to a discount group                                                             |
| Agent groups and matrix | Admin panel → Agent Discount                 | `default_discount_rate`, matrix row `discount_rate`                    | The global discount rate of the agent group the user belongs to, plus per-discount-group exceptions |

Pricing rules (`AgentDiscountService`; `discount_rate` / `cost_rate` are both **percentages**):

```text
折后金额 = 原价 × discount_rate / 100
成本金额 = 原价 × cost_rate / 100
```

Value precedence: **matrix hit** (the user's agent group has a `discount_rate` configured for that discount group) → otherwise fall back to the agent group's `default_discount_rate` → if neither is set, no discount (equal to 100%). When the agent group's `status ≠ 1` (disabled) or the product has no discount group bound, the matrix path simply does not apply.

Three guardrails you need to know:

- `discount_rate` must be between 0 and 100; otherwise "The discount rate must be between 0 and 100".
- When a matrix discount is lower than that discount group's `min_discount_rate`, "The discount rate cannot be lower than the minimum discount rate of the product discount group" — the matrix page blocks it locally before saving and lists exactly which cells are at fault.
- When the discounted amount is lower than `cost_amount`, "The agent's discounted amount cannot be lower than the cost price". **This is the only cost protection that blocks users at checkout**; a misconfigured `cost_rate` (set too high) leaves agent users unable to place orders.

Application order (`CheckoutService::create`): the original price is first formed from product price + config option markups + setup fee → the agent discount is applied → the coupon is deducted last. In other words, the coupon applies to the **discounted price**, and the amount actually paid = discounted amount − coupon deduction, with 0 as the minimum. Orders and invoices both store the original price, the agent discount rate, the discount amount, the cost amount, and the coupon snapshot; when a dispute arises, the snapshot is authoritative.

## 6. Stock: Local Value vs. Upstream Actual Value

- `stock` ranges from `-1` upward; **`-1` means unlimited** and is also the admin default.
- Downstream systems such as ZJMF Finance read `products.stock` when placing orders; checkout on this site uses "live stock", that is, `live_stock` takes precedence and `stock` is used only when it is missing. The two readings for the same product can differ, so when diagnosing "the front end shows stock but checkout fails", check both values.
- The checkout gate completes "bind product → query upstream stock → decide" within a single request. There is only one decision rule: when the available amount is not less than 0 and less than the required quantity, it throws "Insufficient stock for this product; the order cannot continue". Unlimited stock (negative values) does not take part in the check.
- **Reservation applies only to finite positive stock**: a deduction by quantity happens only when `stock > 0`, and the actual deducted amount is written into the order/invoice snapshot as `stock_reserved`. Cancellation or refund restores exactly that snapshot amount. **Unlimited products are not deducted on order and not added back on cancellation**, so they never drift from "selling −1".
- Upstream product stock is synchronized by a scheduled task every 15 minutes, in this priority order: products never synchronized → sold out or running low (`0 ≤ stock ≤ 5`) → other limited-stock products → unlimited products. Unlimited products always come last, because the value is just a fixed marker.
- Stock returned by the upstream is frequently unreliable (the code comments explicitly record this). The system also has a fallback: when the upstream explicitly rejects at checkout with "insufficient stock / delisted", the local stock snapshot is **immediately zeroed** and an expiry override is written, without waiting for the next sync.
- What the site operator actually does: **do not treat upstream stock as an accurate ledger**. Use the stock column in "Product Catalog" to watch the trend, and edit `stock` directly when you need to cut losses (setting it to 0 stops sales immediately); manually changed values are overwritten by the upstream value on the next sync.
- Verification: pick a product that is listed, has `stock` of at least 1, has a supplier and upstream product bound, and has `auto_setup` enabled; with a test account, the quote endpoint must return a positive amount and placing an order through to order creation must not raise a stock error. Only after this step passes should you talk about scaling up.

## 7. Effect of Listing and Delisting on Existing Customers

- `status` only has `0` and `1`. `1` means on sale. Official website lists only fetch products that are on sale, and the official website cache is cleared after saving.
- **Delisting does not affect existing customers**: service instances, expiry dates, console operations, and tickets are all unaffected.
- Delisting causes quotes and orders for new purchases to be rejected ("The product has been delisted"). **The renewal path and the official website list are two separate data paths, and renewal does not check the product's listing status**, so existing customers can still renew; conversely, delisting cannot be used to stop all revenue from a product, and the service instances must be handled separately.
- Deleting a product is a soft delete; deleted products are hidden from the product list by default, and you must switch the lifecycle filter to "Deleted" to review and restore them.
- **The cost of permanent deletion**: it soft-deletes every service instance under the product along with it, nulls `orders.service_id` and `tickets.service_id`, and deletes `service_upstream_bindings` and `product_upstream_bindings`. The product can be restored; the services and upstream bindings cannot. Before executing, confirm that the product really has no instances worth keeping.
- Deletion protection: when service instances exist under the product, both soft delete and permanent delete are rejected ("This product already has service instances and cannot be deleted directly"), and bulk deletion skips it and returns the reason in the result ("service instance exists").
- **Official website cache latency**: product types and groups are cached for 15 minutes, the product list and details for 10 minutes, and **the stock field has its own 10-second cache**. Editing a product in the admin panel clears the cache proactively, but data written by editing the database directly or by a migration script is not cleared — if a change does not take effect, re-save the product in the admin panel or adjust its sort order once, then refresh the official website.

## 8. Verification Checklist

1. Official website: the product can be found under the correct third-level path, and its price and cycle labels match the admin panel.
2. Quote: request a quote while logged in and confirm the amount = cycle price + config option markups + setup fee; repeat with an agent account and confirm the discount rate and discount amount match expectations.
3. Stock: with `stock = 0`, checkout is rejected with a clear message; after changing it back to a positive number, it recovers immediately.
4. Delisting: after `status = 0`, the official website no longer shows the product and new purchases are rejected, while existing customers' renewals and console operations continue as usual.
5. Console: with a test account, buy a host or open an existing instance, and confirm the action buttons match `console_template`.
6. Cache: within 10 minutes of editing the product, re-check whether the official website has updated; if it has not, trigger a save manually.

## Related Documents

- [Orders and Automatic Provisioning](orders.md): ordering, payment, automatic provisioning, and failure handling.
- [Upstream Supplier Integration](../configure/upstream.md): supplier credentials, upstream product binding, and connectivity self-checks.
- [Payment Channel Integration](../configure/payment.md): payment methods and callback URLs.
- [Daily Operations](index.md): the operations map and the daily recommended review.
- [System Architecture](../../develop/architecture.md): module breakdown of the product catalog and the provisioning path.
