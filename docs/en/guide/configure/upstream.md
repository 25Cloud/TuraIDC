---
source: guide/configure/upstream.md
sourceHash: a705dce4ce699183db83da57b1c2982584beb9dd6a9aaac113a768efafb0b4d1
translatedAt: 2026-10-26
---

# Upstream Provider Integration

Machines are provisioned from an upstream only after three things are in place: the supplier record, the credentials, and the product binding. This document describes the entry points, how to choose among the three supplier protocols, what to enter in the credential fields, how to self-check connectivity, and where to look first when provisioning fails.

## 1. Admin Entry Points

| Page                         | Path                                                            | Permission                                                                                                            |
| ---------------------------- | --------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------- |
| Upstream Providers           | Product Configuration → Upstream Supply → Upstream Providers    | `supplier.list` / `supplier.detail`                                                                                   |
| Upstream Provisioning Plugin | System Settings → Integration Plugins → Upstream Provisioning   | List: `integration_plugin.view`; install/enable/disable: `integration_plugin.manage`; test: `integration_plugin.test` |
| Product Management           | Product Configuration → Product Management → Product Management | `product.list` / `product.manage`                                                                                     |
| Service Instances            | Service Management → Service Instances                          | Service-related permissions                                                                                           |
| Upload/Cleanup Logs          | Log Center → Log Management → Upload/Cleanup Logs               | `log.list`                                                                                                            |

`supplier.manage` is required to add, edit, or delete a supplier; `supplier.sync` is required to run sync tasks and bulk connect; `supplier.secret_reveal` is required to view stored credentials in plain text (rate-limited to 10 times per minute and written to the operation log).

## 2. Available Upstream Protocols

The fields in the supplier form **depend on the provider type**; not every type has the same fields. Currently available options:

| Provider Type          | provider_key        | Credential Fields                                                                                                                         | Protocol Notes                                                                                                              |
| ---------------------- | ------------------- | ----------------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------- |
| Hosting Panel API      | `hosting_panel_api` | `api_url` endpoint URL, `api_username` endpoint account, `api_key` endpoint key                                                           | Generic hosting panel protocol, built into the core, no plugin required                                                     |
| ZJMF Finance API       | `zjmf_finance_api`  | The same three credentials as above (this plugin declares no custom form fields)                                                          | Connects to a ZJMF Finance upstream; requires the `zjmf_finance` plugin to be enabled                                       |
| Kangle Virtual Hosting | `kanghostx`         | Endpoint URL: the panel root address; API key: the accesshash                                                                             | Kangle WHM API; requires the `kanghostx` plugin to be enabled                                                               |
| TuraIDC Open API       | `tura_open_api`     | Endpoint URL: the upstream site root address; API key: the full key created on the upstream "API Key" page (shown only once, at creation) | A native TuraIDC protocol that does not use the ZJMF compatibility chain; requires the `tura_open_api` plugin to be enabled |
| Demo Upstream Service  | `demo_servers`      | No real credentials; simulates an upstream only                                                                                           | For validating the flow only                                                                                                |

The candidates in the supplier type dropdown come from the plugin registry plus the core drivers; a plugin that is not installed does not appear in the dropdown. If you pick one and cannot save, a parameter validation error is reported. When a type is not selectable, install and enable it on the Upstream Provisioning plugin page first.

`hosting_panel_api` (hosting panel API) is a built-in driver, but the dropdown is driven by the plugin registry, so it appears only when a matching upstream plugin record exists. If you confirm that the driver exists in the code but it is missing from the dropdown, click Scan Plugins on the Upstream Provisioning page first; if it is still missing, the driver has no registrable plugin entry in the current deployment (the exact registration mechanism was not confirmed in the code).

The configuration page of the `zjmf_finance` plugin has only one read-only field, the Upstream Identifier `zjmf_finance_api`, and explicitly requires the supplier binding's `provider_key` to keep that value and not be aliased to `hosting_panel_api` — entering the alias sends the request down the wrong adapter chain.

`tura_open_api` requires the upstream key to have read/write permission on `products/orders/services/finance` and the upstream account to have sufficient balance; the key is shown only once when it is created on the upstream, and if it is lost it can only be recreated.

## 3. Adding a Supplier

1. On the Upstream Providers page, click Add Provider and first choose the Plugin Provider (that is, the provider type described above).
2. Interface Name is the supplier name shown on this site only.
3. The credential fields that appear depend on the selected type: a required field left empty cannot be saved; a key field that already holds a value shows "Configured", and leaving it empty while editing means **keep the existing value** rather than clear it.
4. Upstream Balance Alert section: the `low_balance_alert_enabled` switch and the `low_balance_threshold` threshold. An empty threshold means the default value 20 is used; it does **not** mean the alert is turned off.
5. After saving, the supplier exists in the enabled state (`status=1`); toggle it with the enable/disable button in its row. Disabling does not affect services that are already provisioned, but new provisioning will not use it.

The endpoint URL is strictly validated: it must be `http` or `https` with a hostname, **HTTPS is enforced outside the local environment**, credentials in the URL are rejected, `localhost` and domains ending in `.localhost` are rejected, and private and reserved IP addresses are rejected. In addition, `HOSTING_PANEL_API_ALLOWED_HOSTS` (comma-separated) can narrow the allowed domains to a whitelist; once it is set, any domain not on the list is rejected.

Credentials are stored as rows in `supplier_plugin_bindings`: `base_url`, `account_name`, the API key, plus the plugin-defined `provider_config`. A single supplier can have several bindings (for example, connecting to two different upstreams at the same time); the `supplier_id` plus source pair specified in Bulk Import / Connect is what distinguishes them.

## 4. How Products Connect to the Upstream

Valid credentials alone are not enough to sell; you still have to bind local products to upstream products:

- Open the product edit page, select this supplier in the supplier dropdown, then sync or select an upstream product; selecting fetches the upstream product list in real time, and if it cannot be fetched, the credentials or the network are at fault.
- Alternatively, use Bulk Import / Connect on the supplier card on the Upstream Providers page: refresh the upstream product tree first, select the remote products to connect and the local target category, then run it. The dialog has three options — Default Listing, Auto Provisioning, and Sync Config Options — which determine the product status after import, whether automatic provisioning is used, and whether the upstream's config option templates are carried over.
- The config option template of an upstream product can be viewed per product (the `config-template` action in the card actions) to check whether the parameters are complete.

Bulk connect requires `supplier.sync`; without that permission, clicking the button only shows a notice and does not fail silently.

## 5. Self-Check and Verification

Check in order; each step isolates one layer:

1. Check (`health_check`) on the Upstream Provisioning plugin page: validates the manifest and the required configuration and instantiates the entry class, answering "can the plugin load normally"; it does not contact the upstream.
2. Connection Test on the Upstream Provisioning plugin page: resolves the first capability declared by the plugin and verifies that the plugin can obtain it at runtime.
3. Sync Balance on the supplier card: actually calls the upstream and refreshes Upstream Balance / Last Updated. If the balance stays `-` and the button is grayed out (with the notice "Incomplete interface configuration; balance cannot be synchronized yet"), then `api_url`, `api_username`, or `api_key` is missing.
4. Bulk Import / Connect on the supplier card: if the upstream product tree can be fetched, the credentials, HTTPS, the domain whitelist, and the network are all working.
5. Take one real small-value order through provisioning, then check the status under Service Instances, and check the upstream interaction records under Upload/Cleanup Logs and Ticket Push Logs.

```bash
# 后端到上游的网络连通性：上游调用不做 SSL 校验，但网络与域名白名单仍会挡住请求
curl -sS -o /dev/null -w '%{http_code}\n' --max-time 10 https://你的上游域名/
```

The command above only confirms network reachability; actual API authentication is determined by the results of steps 3 and 4.

## 6. Troubleshooting

- **The provider type you want is missing from the dropdown**: the corresponding plugin is not installed or not enabled; handle it on the Upstream Provisioning plugin page first.
- **Saving reports "The upstream endpoint URL must use HTTPS"**: HTTPS is enforced outside the local environment; for a plain HTTP upstream, either fix the domain certificate or verify in a local integration environment.
- **"The upstream endpoint domain is not in the allowed range" is reported**: `HOSTING_PANEL_API_ALLOWED_HOSTS` sets a whitelist; add the upstream domain to it or clear the variable.
- **Balance sync fails**: first determine whether the network, the domain whitelist, or the credentials are at fault — error messages are normalized by `ProviderErrorMapper` into Chinese and state explicitly whether it is "interface temporarily unavailable" or a business error.
- **The order is paid but the machine was not provisioned**: new purchases and renewals are fulfilled synchronously in the payment callback, and only when that fails does the system fall back to the `provision` queue, retried by the heartbeat every minute. First check the latest provisioning attempt record of the service instance, then check whether the queue is running (see [Deployment and Scheduling](../deploy/scheduling.md)).
- **Does disabling a supplier affect existing services**: disabling only affects routing for subsequent provisioning, and already provisioned services remain manageable; however, scheduled tasks that sync status no longer pull data from it.

## Related Documents

- [Initial Configuration](index.md): the order of the four configuration steps and the go-live checklist.
- [Payment Channel Integration](payment.md): the first half, which only makes sense once the upstream is configured — how money comes in.
- [Deployment and Scheduling](../deploy/scheduling.md): how the heartbeat, the queue, and provisioning retries run.
- [Engineering Pitfalls](../../develop/pitfalls.md): field-tested pitfalls when changing configuration and during incremental deployments.
