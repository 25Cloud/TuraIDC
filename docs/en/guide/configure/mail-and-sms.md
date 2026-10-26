---
source: guide/configure/mail-and-sms.md
sourceHash: 479b11396e64a7b741ce83f49a806030be36e05d88f1abcf5771597b2dcd783e
translatedAt: 2026-10-26
---

# Email and SMS

Email and SMS both go through "integration plugins": first install and enable a channel plugin on the plugin page, then fill in the credentials, and finally turn on the notification master switch in site settings. If any one of the three steps is missing, verification codes are not delivered externally. This document follows that order and explains the entry points, fields, consequences of leaving a field empty, and verification methods.

## 1. Admin Entry Points

| Page                        | Path                                                                           | Permissions                                                                                                                               |
| --------------------------- | ------------------------------------------------------------------------------ | ----------------------------------------------------------------------------------------------------------------------------------------- |
| Email sending (plugin list) | System Settings → Integration Plugins → Email Sending                          | List `integration_plugin.view`; install / change config / enable-disable `integration_plugin.manage`; test send `integration_plugin.test` |
| SMS sending (plugin list)   | System Settings → Integration Plugins → SMS Sending                            | Same as above                                                                                                                             |
| Notification master switch  | Site Content → Site Configuration → Site Settings → Notification Master Switch | Page `site.view`, write `settings.manage`                                                                                                 |
| Email templates             | Site Content → Notifications and APIs → Email Templates                        | `settings.view` / `settings.manage`                                                                                                       |
| SMS templates               | Site Content → Notifications and APIs → SMS Templates                          | Same as above                                                                                                                             |
| Email logs / SMS logs       | Log Center → Log Management → Email Logs / SMS Logs                            | `log.list`                                                                                                                                |

The plugin list page scans the directories under `backend/plugins/{domain}/`; plugin files added locally appear only after you click "Scan Plugins". Installation and enabling are two steps: click "Install" to write the record, then click "Enable" to activate the runtime.

"Send Test Email / Send Test SMS" are test endpoints (`integration_plugin.test`) rate-limited to 10 requests/minute — they really do send, just through the direct-send channel. An account without this permission gets a "no permission" prompt when clicking test on the plugin detail page.

## 2. Only One Plugin per Function Domain

`mail` and `sms`, together with `captcha` and `verification`, all belong to a "single-enable domain". If a plugin in the domain is already enabled and you try to enable a second one, it fails outright with "the current function domain already has 'XXX' enabled; disable it first before enabling another plugin" (error code 42200). Payments and upstreams are not subject to this limit and may have several plugins at once.

Enabling also writes a driver binding record, which the system uses to decide which driver is current; only when the binding cannot be found does it fall back to the environment variables `MAIL_DRIVER_DEFAULT` (default `smtp`) and `SMS_PROVIDER_DEFAULT` (default `aliyun`). So the standard way to change SMS providers is: disable the old plugin first, then enable the new one; no `.env` change is needed.

## 3. Email Channels

Available plugins: `smtp` (Single SMTP; use this one in production), `multi_smtp_round_robin` (multi-SMTP round-robin), `demo_mail` (demo, does not connect to a real SMTP server).

`smtp` plugin fields:

| Field                                                             | Default | Consequence of leaving it empty                                                               |
| ----------------------------------------------------------------- | ------- | --------------------------------------------------------------------------------------------- |
| `host` SMTP host                                                  | Empty   | Sending throws "email interface configuration incomplete"                                     |
| `port` SMTP port                                                  | `465`   | A port ≤ 0 reports the same incomplete configuration                                          |
| `username` SMTP account                                           | Empty   | Reports incomplete configuration; also doubles as the default sender address                  |
| `password` SMTP password                                          | Empty   | Reports incomplete configuration (not echoed back after saving; saving empty means unchanged) |
| `from_address` sender address                                     | Empty   | Falls back to the SMTP account, preserving legacy configuration behavior                      |
| `from_name` sender name                                           | Empty   | Falls back to the current site name                                                           |
| `encryption` encryption method                                    | "Auto"  | With Auto, the value is inferred from the port: 465 → SSL, 25 → none, everything else → TLS   |
| `timeout_seconds` timeout in seconds                              | `8`     | An invalid value is also treated as 8 seconds                                                 |
| `rate_limit_enabled` enable email verification code rate limiting | On      | Once off, verification code requests are no longer limited per IP                             |
| `ip_minute_limit` per-IP limit per minute                         | `6`     | 0 means no limit                                                                              |

`from_address` and the SMTP account may differ; most corporate mailboxes require the two to match or to be authorized, and a mismatch is rejected by the other side. That is the peer's policy, and the system does not validate it.

`multi_smtp_round_robin` keeps multiple accounts in an "SMTP account list" (`accounts`, JSON type, marked as a secret), maintained through the account manager; after a failure it automatically rotates to the next available account, and `cooldown_seconds` defaults to 60 seconds, i.e. an account that failed to send cools down for 60 seconds before it rejoins the rotation. Its rate-limiting fields are the same as `smtp`.

Email templates are maintained on the "Email Templates" page and stored in the `notification_templates` table, with the key prefixes `email_template_subject_`, `email_template_content_`, and `email_template_enabled_`. A template whose content or enable switch has been changed in the admin console is marked as customized (`is_custom=1`), and a system upgrade **does not** overwrite it with the new default template; templates that were not touched are refreshed by the upgrade. If the styling of some email did not update after an upgrade, first check whether that template has been modified.

## 4. SMS Channels

Four plugins are available, and they are not equally capable:

| Plugin slug  | Name                              | Upstream product and API                                                                                        |
| ------------ | --------------------------------- | --------------------------------------------------------------------------------------------------------------- |
| `aliyun`     | Alibaba Cloud Number Verification | Number Verification (PNVS) / `SendSmsVerifyCode`, no qualification review required, mainland China numbers only |
| `aliyun_sms` | Alibaba Cloud SMS                 | SMS / `SendSms`, V3 signature, requires qualification filing plus signature and template review                 |
| `stay33`     | MC Cloud SMS                      | Fill in the username and user key according to the platform API information                                     |
| `demo_sms`   | Demo SMS                          | Simulates only the sending result, used to validate the plugin flow                                             |

`aliyun` and `aliyun_sms` are two independent Alibaba Cloud products: their consoles, APIs, resource packages, and signature and template systems are not interoperable, and the templates cannot be swapped between them. For `aliyun`, the verification code templates are selected internally by the plugin per business scenario (the 100001–100005 templates provided free in the console), so there is **no template ID field**; using the wrong product produces mismatches such as "the number verification package was charged but the SMS template reports missing parameters".

`aliyun_sms` has more fields, in four groups:

- Credentials: `access_key`, `secret_key` (AccessKey Secret), `sign_name` (the SMS signature, which must be a name approved under "Signature Management" in the console), `endpoint` (default `dysmsapi.aliyuncs.com`; change it only for dedicated or international-site endpoints).
- Verification code template: `template_code` default template ID (required, in the form `SMS_123456789`; every scenario falls back to it); `code_variable` verification code variable name (default `code`, which must match the `${...}` placeholder in the template body); `expire_variable` validity-period variable name (leave it empty when the template has no such variable; filling it in when the template lacks it is rejected by Alibaba Cloud).
- Per-scenario templates: `template_login`, `template_change_phone`, `template_reset_password`, `template_bind_phone`, `template_verify_bound_phone`; all may be left empty, and empty means the default template is used. If only one template is filed, fill in only the default template.
- Rate limiting and advanced: `rate_limit_enabled` (on by default), `ip_minute_limit` (default `6`; 0 means unlimited), `request_timeout` (default 10 seconds, range 3–30).

A wrong `code_variable` is the most common class of failure: Alibaba Cloud returns "template parameter missing", which is visible in the logs, but the SMS simply does not go out.

`stay33` needs `username`, `api_key` (user key), and `sign_name` (signature name, without the surrounding `【】`); before sending, complete the signature and real-name verification on the platform and confirm the account has quota.

## 5. SMS Templates

The template ID inside the "SMS Sending" plugin is the channel-side number; on the system side, each notification scenario also has its own template record, maintained on the "SMS Templates" page and stored in the `notification_templates` table. The corresponding setting keys are `sms_template_content_{code}`, `sms_template_provider_template_id_{code}`, and `sms_template_enabled_{code}`.

`provider_template_id` is the mapping for "which channel template this system scenario uses", and it resolves in this order: the value passed explicitly in this call → the `provider_template_id` stored on the template row → the legacy verification code template ID → falling back to the system template code itself. So configuring only the default template of `aliyun_sms` and leaving the rest empty is enough to get verification codes working; only when different scenarios must send different SMS messages do you need to specify a channel template ID per scenario.

## 6. Verification and Troubleshooting

Verify in the order below; each step narrows the problem down to a specific stage:

1. Click "Check" on the plugin detail page (`health_check`): it validates the manifest file and whether the required config options are complete, and instantiates the entry class; if the entry class implements its own health check, its result is returned as well. It **does not contact the upstream and does not send anything**; it only answers "can this plugin load normally".
2. The "Check" button in the Alibaba Cloud SMS plugin configuration: it performs a one-off read-only validation of the credentials, signature, and template, and compares the variable names in the template body; it sends no SMS and incurs no charge. A signature or template that has not passed review is exposed here.
3. "Send Test Email" on the email plugin detail page (enter the recipient email; account index 0 is always used) or "Send Test SMS" on the SMS plugin detail page (enter the phone number). This step uses direct driver sending and **bypasses the notification master switch**; if the master switch is off, the success message explicitly states "but 'Enable Email Notifications' in site settings is currently off, so no email will be sent externally" — do not read that as "it works now".
4. Turn on `email_enabled` / `sms_enabled` in site settings.
5. Walk a real user through registration or password recovery, then check the records under "Email Logs" or "SMS Logs": each record carries the recipient, template code, channel template ID, status, and error message, and the failure reason is right there.

```bash
# 出问题时先确认队列与调度在跑，通知类任务由心跳驱动
cd /你的项目/backend
php artisan schedule:run
```

Common failure reference:

- The test succeeds but the user receives nothing: the notification master switch is off, or the corresponding template is disabled (`is_enabled` is 0).
- SMS reports "template parameter missing": `code_variable` does not match the variable name in the channel template body.
- Reports "SMS driver [xxx] is not registered": the plugin was enabled but there is no binding record; disable it and enable it once more.
- Email reports "email interface configuration incomplete": one of `host`, `port`, `username`, or `password` is empty.

## Related Documents

- [Site and Brand Settings](site-settings.md): the notification master switch is on this page.
- [Initial Configuration](index.md): the order of the four configuration steps and the go-live checklist.
- [Deployment and Scheduling](../deploy/scheduling.md): notification scheduled tasks depend on the heartbeat and the queue.
- [Engineering Pitfalls](../../develop/pitfalls.md): measured pitfalls when editing copy, running tests, and doing incremental deployments.
