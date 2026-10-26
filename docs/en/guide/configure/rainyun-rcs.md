---
status: current
updated: 2026-10-22
owner: integrations
source: guide/configure/rainyun-rcs.md
sourceHash: acd09ac41f6233d9b94e71eb074b082d14e623606a06757e69346c147036b9bc
translatedAt: 2026-10-26
---

# Rainyun RCS Plugin

The plugin directory is `backend/plugins/servers/rainyun_rcs/`, the admin capability domain is `upstream`, and the identifier is `rainyun_rcs`. This plugin integrates with Rainyun RCS and is reimplemented from the business features of ZJMF Finance rainyunrcs 3.0.0.

## 1. Installation Prerequisites

This version requires the matching TuraIDC core capability patch and user console build; uploading only the plugin ZIP to an older, un-upgraded TuraIDC is not enough.

The delivery package is `TuraIDC-rainyun-rcs-1.0.0.zip`, containing `rainyun_rcs-1.0.0.zip`, `TuraIDC-rainyun-rcs-integration.patch`, installation instructions, license files, and a checksum manifest. The patch baseline is TuraIDC commit `ec1afcb4b2920ae6e71fd5b92d5197341b10de19`; on a different version, check the patch first and do not overwrite the whole application directly.

The initial delivery package does not include the trial-cycle adaptation and the missing-IP error message that were added afterwards during live testing. When using that package, also apply `TuraIDC-rainyun-rcs-trial-fix.patch`; this incremental patch is based on commit `1661398`, which already has 1.0.0 integrated, and likewise requires running `git apply --check` first. Do not apply it again when the working tree already contains these changes.

The matching capabilities are: plugin-local console actions, plugin add-on order fulfilment, direct connection to the official browser console, and a plugin-specific reinstall request timeout. The platform enables the relevant branches only when the plugin bound to the current instance explicitly implements the corresponding interface; ordinary upstreams keep using the existing flow.

1. Back up the site code and confirm that the current version matches the patch baseline. In the repository root, first run `git apply --check TuraIDC-rainyun-rcs-integration.patch`, and apply it only after the check passes. The patch contains the complete plugin code; do not overwrite existing local changes a second time.
2. Deploy the backend following the site's existing release process, run `pnpm run build` in `frontend-user-v4-console`, then deploy the build output. Reload the long-running queue workers and clear the stale application cache.
3. In the admin plugin manager, scan, install, and enable "RCS" (identifier `rainyun_rcs`). No new database tables or system routes are required.
4. Create or edit a supplier, select "RCS", fill in the API address and API key, then query the balance to verify connectivity. Configure the key only in the supplier credentials; never put it into a product, the user-side panel, or documentation.
5. Sync upstream plans, config options, and stock, set this site's sales prices, then link a test instance and check the console.

If TuraIDC already includes these matching capabilities, you can unpack the plugin ZIP on its own, place the `rainyun_rcs` directory under `backend/plugins/servers/`, and then scan and install it.

## 2. Supplier and Sales Prices

The Rainyun API address is `https://api.v2.rainyun.com`, and the plugin sets the `x-api-key` request header. The official Rainyun API documentation is at <https://api.rainyun.com/>.

| Config option                   | Meaning                                                                                                                                         |
| ------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------- |
| API Key                         | API key of the Rainyun account; requests are rejected when it is absent                                                                         |
| SSD, HDD, system HDD unit price | This site's monthly sales price per GB for the corresponding cloud disk                                                                         |
| Cloud disk backup unit price    | Additional monthly sales price per GB after backup is enabled                                                                                   |
| Extra traffic unit price        | Sales price per GB                                                                                                                              |
| GPU duration unit price         | Sales price per hour                                                                                                                            |
| Plan monthly sales price JSON   | Mapping from plan ID to monthly sales price, for example `{"1001":"30.00","1002":"60.00"}`                                                      |
| IP monthly sales price JSON     | Mapping from line identifier to the monthly sales price of a single IPv4; an empty identifier means the default line, for example `{"":"8.00"}` |

Amounts have at most two decimal places. When a sales price is missing or invalid, the corresponding purchase is rejected, and the paid upstream API cannot be called free of charge. A plan upgrade requires sales prices for both the current and the target plan, and the target must appear in the upgradable list returned by Rainyun.

The import template adds no paid IP and no extra system disk capacity by default, and does not enable trials. To offer add-on config options or trials during provisioning, the site operator must explicitly set the option, default value, and price in the product configuration; the template's base plan price is not a total that covers arbitrary add-ons. Provisioning and renewal support monthly, quarterly, semi-annual, and annual billing.

Trial provisioning accepts the order cycle `ontrial` (after the platform's unified normalisation, `trial` and `on_trial` are also recognised), or an explicit `try=true`; the plugin sends the trial switch to Rainyun, and `duration` still uses the month count the API allows. This adaptation does not change the TuraIDC shop's cycle whitelist and does not add trials to the renewal cycles.

Some regions require a standalone IP. In testing, plan 1344 returned `70033` with a count of 0, and trial creation succeeded after setting `with_eip_num=1` and `with_eip_flags=native`. This plan also offers a `home_ip` line; the IP count, line, and corresponding price must be configured explicitly in the product, and the "no extra IP" default cannot be reused. The IP line identifier and the IPv4/IPv6 address type are different parameters.

## 3. Console Features

| Feature                              | Behaviour                                                                                                                                                                                            |
| ------------------------------------ | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Power on/off, reboot, password reset | Calls the Rainyun API of the currently bound instance after TuraIDC authentication                                                                                                                   |
| Reinstall                            | Validates the image, powers off automatically, waits for the stop to be confirmed, then submits the reinstall; an invalid image does not power off, and a stop timeout does not submit the reinstall |
| VNC                                  | Fetches the official Rainyun page link and opens it in a new window; signature parameters are not rewritten and no TuraIDC WebSocket relay is used                                                   |
| Terminal                             | Fetches the official xtermjs entry point, then reaches the upstream directly on clicking "Open Terminal"                                                                                             |
| Monitoring                           | CPU, memory, disk, and network time series                                                                                                                                                           |
| Backup and snapshot                  | Create, delete, restore, and automatic schedules; backups support cancelling a task                                                                                                                  |
| NAT                                  | NAT instances show the port forwarding list, add, and delete; the resource ID must belong to the current instance                                                                                    |
| Cloud disk                           | Create, expand, adjust backup, and delete data disks; shrinking and deleting the system disk are prohibited                                                                                          |
| Traffic and GPU                      | Traffic packages, daily traffic cap, rate limiting, GPU switch, and duration purchase                                                                                                                |
| Plan and IP                          | Plan upgrade, extra IPv4 purchase                                                                                                                                                                    |

The VNC and terminal pages and the WebSocket are all reached by the browser directly from Rainyun. The TuraIDC backend only requests the entry links and the ordinary management APIs. If Rainyun does not return `VNCProxyURL`, the plugin states clearly that the feature is unavailable; the legacy `RequestURL/PVEAuth` forwarding approach is not offered. Fetch the link again when it expires.

The plugin display name, operation hints, plan configuration, terminal button, and newly generated add-on resource bills and refund descriptions use neutral wording that does not contain "Rainyun". The plugin identifier, API address, and binding checks keep their original values; existing product configurations and historical accounting records are not rewritten automatically.

A Rainyun reinstall request lets the frontend wait 90 seconds, the plugin's internal budget for the preceding steps is 55 seconds in total, and the flow is protected by the platform instance operation lock. When deploying, the PHP request time limit and the reverse proxy read timeout must allow this request to complete. A successful reinstall submission means Rainyun has accepted the job, not that installation has finished; confirm the instance is running again by refreshing the status.

## 4. Add-on Resource Billing and Retries

1. The user first requests a quote and confirms it. The quote is valid for ten minutes and is bound to the service, supplier, plugin, upstream instance, and resource configuration.
2. Create a TuraIDC upgrade bill and wait for payment; resubmitting the same confirmed quote reuses the original bill, and no separate order is created after payment completes either. When another unpaid upgrade bill already exists, pay or cancel that bill first.
3. After payment, re-check the instance binding and resource state, then perform the Rainyun operation once.
4. The monthly renewal difference for cloud disks, plans, and IPs updates the service amount and the corresponding renewal cycle configuration in sync. A cloud disk refund produces a balance transaction and a refund document after the upstream call succeeds, and is not recorded as a manual top-up.

Following the original plugin's convention, a month is 31 days when prorating the remaining service period; extra traffic and GPU duration are billed by the quantity purchased. Negative differences from deleting a cloud disk or disabling backup are returned at the configured sales price. Before migrating an instance in or changing a supplier's sales prices, the site operator should verify whether the instance's existing cloud disk charges are already included in the TuraIDC renewal amount, so that historical pricing and the current configuration do not diverge.

Order snapshot states: `ready` pending execution, `submitted` request sent, `applied` accepted by the upstream, `uncertain` outcome unknown, `rejected` rejected by the upstream. `submitted/uncertain/rejected` do not automatically resend a billable request. After re-binding an instance, changing upstream resources, or renewing, an old quote can no longer be executed. Verify the real upstream outcome and the bill, then handle it through the platform's existing manual accounting flow; do not clear the execution state and retry blindly.

## 5. Verification Scope

On 2026-10-21, details, plans, images, monitoring, balance, and both official console entry points were verified on an authorised blank instance, and "running → automatic power-off → Debian 11 reinstall" was executed through the new plugin. The browser confirmed that connections go straight to the Rainyun WebSocket.

The same day, the plugin's real provisioning entry point was used to verify plan 1344, image 535 (Debian 12), a trial cycle, and one `native` IPv4: the instance was created and reached the running state, the trial flag, image, and IP line were correct, and the account balance decreased by 1 yuan; the SSH port was reachable and returned the Debian service banner. Calling the provisioning entry point again only reused the stored instance ID and sent no write request upstream. This run used an isolated local order fixture together with the real upstream API; it did not cover order placement in the TuraIDC frontend, the payment gateway, or integration with a real business database.

Paid new purchases, actual renewals, plan upgrades, and after-sales purchases of extra IP, cloud disk, traffic, or GPU were not charged for real; `home_ip` was only checked for its quote and parameters, and no second machine was created for verification. The user asked for disk upgrade and downgrade not to be tested in this round. Resource operations such as backup restore and snapshots were covered mainly by contract regression and should be verified on the corresponding test resources before being enabled in production. The original plugin has no real arrears suspension capability, and this plugin does not disguise a plain power-off as a suspension either; do not treat automatic handling in the suspended state as a verified upstream capability.

Before going live, the site operator still needs to provide the plans and prices planned for sale and verify the "payment → upstream charge → resource change → renewal price" flow with an explicit spending budget.

## Related Documents

- [Upstream Supplier Integration](upstream.md)
- [Plugin Development Guide](../../develop/plugins/index.md)
- [Open Source and Commercial Licensing](../licensing.md)
