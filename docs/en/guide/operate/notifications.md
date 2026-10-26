---
source: guide/operate/notifications.md
sourceHash: 9f681bb6570c9817316cfd218c4128a99f475a7c1f32809e87f0f74f2c759331
translatedAt: 2026-10-26
---

# Notifications and In-site Messages

This document is written for site operators and operations leads who run a support team. It explains how each of the three notification channels — in-site messages, email, and SMS — is switched on and off, how templates are configured, which business actions actually send a message, and which admin entry point to check when a message does not go out. The variable syntax, template codes, and trigger points are all taken from the current code; wherever a capability "is defined in the backend but has no caller in the code", that is stated outright.

## 1. Division of Work and Master Switches of the Three Channels

| Channel         | Storage and delivery                                                                                     | Master switch location                                                | Default |
| --------------- | -------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------- | ------- |
| In-site message | Written to the `user_notifications` table; the user reads it in the console                              | No global switch; governed by the user's own notification preferences | On      |
| Email           | Sent synchronously or enqueued through the email driver (integration plugin), recorded in `message_logs` | "Enable Email Notifications" under "Site Content → Site Settings"     | Off     |
| SMS             | Sent through the SMS driver (integration plugin), recorded in `message_logs`                             | "Enable SMS Notifications" under "Site Content → Site Settings"       | Off     |

The stored names of the two master switches are `notification.email_enabled` and `notification.sms_enabled`; they count as enabled only when the value is `1`, `true`, or `on`. They only block "the system sending on its own initiative", not two bypasses:

- Test sending on the "Integration Plugins" page goes straight through the driver and is not bound by the master switch — a successful test does not mean external sending works.
- With the master switch off, the system still writes a `pending` `message_logs` record first, then sets that record to `failed` with "Email notifications are not enabled" or "SMS notifications are not enabled". So the log does contain a failed record and the error message is exactly that sentence; this is the fastest way to tell "the switch is off, not the channel is broken".

Whether a channel "can actually send" also depends on whether a driver can be resolved: both email and SMS first pick an available driver from the currently enabled bindings, and when none can be picked they report "Email driver [xxx] is not registered" or "SMS driver [xxx] is not registered". In other words, with the switch on and templates configured, an uninstalled or unbound plugin still sends nothing. For configuration steps see [Email and SMS](../configure/mail-and-sms.md).

## 2. In-site Messages

In-site messages are the only notification that does not depend on an external channel; they are delivered even when the plugins are not configured. What the user sees in the console notification center is **a merged stream of two kinds of content**: announcements (content articles plus read records) and personal notifications (`user_notifications`). The unread count also adds up both kinds.

Each personal notification contains a type, a title, a body, a jump link, and a block of structured data. Four types are ticket-related:

| Type constant             | Trigger                                                                                                     | Jump                   |
| ------------------------- | ----------------------------------------------------------------------------------------------------------- | ---------------------- |
| `ticket_staff_reply`      | A staff member replies to the ticket, or an upstream administrator's reply is written in through a callback | `/client/tickets/{id}` |
| `ticket_pending_reminder` | No customer follow-up for a long time after a staff reply; a reminder before closing                        | `/client/tickets/{id}` |
| `ticket_auto_closed`      | The ticket is auto-closed on timeout                                                                        | `/client/tickets/{id}` |
| `ticket_reopened`         | The ticket is reopened (triggered from the admin panel or by the customer)                                  | `/client/tickets/{id}` |

Operations notes:

- **Writes are defensive.** When the table does not exist or the write throws, only a warning log is recorded; main flows such as creating a ticket and replying are not blocked. So "in-site messages are missing" may mean the write was swallowed — search `storage/logs/laravel.log` for `[站内信] 写入失败`.
- **Marketing-type messages are governed by user preferences.** A notification written with a marketing flag is persisted only when the user has `marketing_alert` enabled; business-critical reminders such as tickets and billing are not affected.
- **The title is truncated** to 191 characters; the body is stored exactly as written and is not truncated.
- Users can mark messages as read one by one or all at once in the console; the read state is stored in `read_at`, and the case "marked read but the unread count does not drop" does not exist — the unread count is simply the number of rows where `read_at` is empty.

## 3. Email Templates and Variables

The template entry point is "Site Content → Notifications and APIs → Email Templates", with the permission code `settings.view`; clicking a single template opens the "Email Template Detail" page, and the same page also serves SMS templates. A template is not a pure settings item but a row in the `notification_templates` table, unique by `channel` (`email` / `sms`) + `code` (the number).

The system ships 31 email templates (codes `100001` to `100031`); their default subjects and HTML bodies are all provided by `EmailNotificationTemplateDefaults`, and the command `php artisan app:seed-email-templates` restores the defaults idempotently. Fields and their scope:

| Field                  | Description                                                                                                           |
| ---------------------- | --------------------------------------------------------------------------------------------------------------------- |
| `subject`              | Email subject template; line breaks are stripped after rendering                                                      |
| `content`              | Body template; a full HTML document or a body fragment is allowed, and a fragment is wrapped in a unified outer frame |
| `is_enabled`           | When turned off, this template's email is **skipped entirely** — no log is written and no error is raised             |
| `variables`            | The list of variables available to this template, shown on the page as "Available Variables"                          |
| `provider_template_id` | SMS only: the template ID approved by the carrier                                                                     |

After you change the subject or the body, `is_custom` is marked as customized; there is no "one-click rollback of a single template" button, so restoring the default means re-running the command above (it overwrites the customized content of all email templates — use with care).

Variable placeholders have two forms, both supported by email and SMS:

- <code v-pre>{{变量名}}</code> is replaced directly. In an **email body**, replacement values inside an HTML template are escaped, while a plain-text template is turned into paragraphs automatically.
- <code v-pre>{{#变量名}}…{{/变量名}}</code> is a conditional block: when the variable has a value the content inside the block is kept, and when the value is empty (`null`, an empty string, `false`) the whole block is removed. Email <code v-pre>{{login_tip}}</code> and the optional paragraphs in SMS bodies rely on it.

In addition, SMS bodies also support single curly braces `{变量名}`, for compatibility with the carrier's own template syntax; email bodies do not support the single-brace form. The two variables `site_name` and `site_logo` are injected by the system automatically and do not need to be passed in by the caller. Any form other than <code v-pre>{{ }}</code> (a misspelled variable name, for example) is not replaced but stays in the body as-is, so **after editing a template always use test sending to see the real result**.

Preview and test: the right side of the detail page is a live preview, and "Send Test" in the top right automatically fills sample values for every variable that appears in the template (contact-type variables get sample values), then really runs one send through the sending chain and reports the number of successes and failures. Test sending requires the `settings.manage` permission and allows only one recipient at a time.

## 4. Actual Trigger Points of Ticket Notifications

The table below lists the ticket notifications that **really are sent** in the current code. The recipient scope is decided by the ticket assignee; for the rules see section 4 of [Tickets and Customer Support](tickets.md).

| Business action                                            | Email template                                        | Recipient                           | Also writes an in-site message |
| ---------------------------------------------------------- | ----------------------------------------------------- | ----------------------------------- | ------------------------------ |
| Customer creates a ticket                                  | `100026` New ticket alert for administrators          | Support staff (see the rules below) | No                             |
| Customer replies to a ticket                               | `100027` Ticket reply notification for administrators | Support staff (see the rules below) | No                             |
| Staff replies to a ticket                                  | `100024` New ticket reply notification                | Customer                            | Yes                            |
| Ticket auto-closed on timeout                              | `100025` Ticket auto-close notification               | Customer                            | Yes                            |
| Ticket reopened                                            | No email                                              | —                                   | Yes                            |
| Reminder before closing                                    | No email                                              | —                                   | Yes                            |
| Upstream administrator reply written in through a callback | No email                                              | —                                   | Yes                            |

Three easy traps:

- **On the customer side, only "staff reply" and "auto-close" send email**; reopen and the reminder only produce in-site messages. If you want the customer to be sure to see it, accept the premise that "they must log in to the console".
- **The two emails to support staff are heavily affected by the assignee**: when the ticket is unassigned they go to every enabled administrator holding `ticket.reply`, and when it is assigned they go to that one assignee only. Once it is assigned, you have effectively muted everyone else.
- **The `100023` ticket provisioning notification, and the ticket-type codes in the SMS catalog (`100010`, `100012`, `100028`), have no caller at all in the current code.** They define codes and copy templates, but changing them produces no sending behaviour whatsoever (no other call path was confirmed to exist in the code).

There is a gap between template variables and the parameters actually passed, and this is a known phenomenon: the default body of `100025` contains <code v-pre>{{ticket_closed_at}}</code> and <code v-pre>{{close_reason}}</code>, but auto-closing passes only `display_name`, `ticket_id`, `ticket_subject`, `status`, `tickets_url`, and `login_tip`, so these two variables render as empty. To have the customer see the closing time, edit the template yourself and switch to variables that are actually passed in (whether these two variables are planned to be filled in was not confirmed in the code).

## 5. What SMS Can Actually Do

One thing must be clear about the SMS channel first: **the only SMS that really goes out in the current code is the verification code message** (code `100001`), and it has exactly one entry point — the client phone verification code API `POST /api/v2/client/auth/phone-code` (no other sending entry point was confirmed to exist in the code) — which uses `purpose` to distinguish registration, login, password reset, rebinding/binding a phone number, verifying an already bound phone number, and the generic scenario when none is specified. The API is rate-limited to 3 requests per minute, and before sending there is an additional code-sending frequency control keyed on "channel + phone number + IP"; once exceeded it returns an error directly and sends no SMS; the "verify bound phone number" category additionally requires the target number to match the number already bound to the current account. The email verification code follows another chain (`POST /api/v2/client/auth/email-code`, also 3 requests per minute, email template `100001`). On the SMS side, drivers other than Alibaba Cloud use the system's built-in body, while the Alibaba Cloud driver uses the driver's own template plus the provider template ID.

The remaining SMS templates look like this:

- The `notification_templates` table holds a code catalog for `channel=sms` (`100001` to `100033`); the admin "SMS Templates" page lets you edit the body, the enabled state, and the "provider template ID", but apart from the verification code the bodies **have no built-in defaults** and must be filled in according to the provider's requirements.
- The business codes in the catalog (login alert, renewal reminder, billing reminder, ticket types, and so on) **have no caller in the current code** (no other call path was confirmed to exist in the code). Configuring them sends nothing, so do not promise customers SMS reminders on that basis.
- The verification code body of the Alibaba Cloud driver is provided by the plugin per `purpose`; the system layer keeps only an equivalent fallback text; `purpose` does not affect other system logic and does not change the template code in the logs.

Minimum verification before launch: confirm on the "Integration Plugins → SMS" page that a driver is enabled and bound, then do one test send of `100001` on the "SMS Templates" page, and finally check on "Log Center → Log Management → SMS Logs" whether `status` is `success`.

## 6. User Receiving Preferences

The switches a user can change under "Notification Preferences" in the console are stored on the user table, and the API is `GET/PUT /api/v2/client/auth/notification-preferences`. Their impact on system sending is not the same, and lumping them together leads to wrong conclusions:

| User preference         | Actual effect                                                                                                                                                 |
| ----------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `marketing_alert`       | Controls whether in-site messages with a marketing flag are persisted; **off by default**, and while it is off marketing notifications are discarded outright |
| `login_email_alert`     | Whether a login alert email is sent; when the email is empty or the value is 0 the login alert is silently skipped                                            |
| `login_notify`          | The currently effective value for login notifications; when it is not set, it falls back to `login_email_alert`                                               |
| `login_location_alert`  | Alert for login from an unusual location                                                                                                                      |
| `password_change_alert` | Password change alert                                                                                                                                         |
| `phone_change_alert`    | Phone number change alert                                                                                                                                     |
| `email_change_alert`    | Email change alert                                                                                                                                            |

In other words: **users cannot turn off business notifications such as tickets and billing** — in-site messages are still written and email is still sent. What users can turn off are marketing pushes and security alerts, and once a security alert is turned off, an account takeover leaves no email to check; renewals and ticket notifications are not affected by it.

## 7. Send Logs and Troubleshooting Entry Points

Records from all three channels land in the `message_logs` table, and the admin panel shows them on two pages by channel, both with the permission code `log.list`:

| Page                                       | Columns visible directly in the list                                                                                              | Common filters                                                                                    |
| ------------------------------------------ | --------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------- |
| "Log Center → Log Management → Email Logs" | Recipient, template code, subject, body excerpt, status, driver, Trace ID, error message, recorded time                           | Email, plugin ID, driver, Trace ID, keyword (template code/subject/body), status                  |
| "Log Center → Log Management → SMS Logs"   | Receiving number, template code, content excerpt, status, driver, Trace ID, provider request number, error message, recorded time | Phone number, plugin ID, driver, Trace ID, keyword (template code/request number/content), status |

Troubleshoot in a fixed order; it saves most of the back-and-forth:

1. **First confirm whether the action fired at all**: whether the ticket is assigned decides the recipient scope; whether the customer's phone number/email is empty decides whether the customer-side email is silently skipped (the server returns directly when the recipient is empty and writes no log).
2. **Then check the master switch**: "Email notifications are not enabled" or "SMS notifications are not enabled" in the log means a switch problem, not a channel problem.
3. **Then check the template switch**: when a template is disabled the email is **skipped entirely** — no log is written and no error is raised. When no record can be found in the log and you have confirmed that both the action and the switch are fine, go to the template page and check `is_enabled`.
4. **Then check the driver**: "driver is not registered" means the plugin is not installed, not enabled, or not bound; a driver's own error code means a problem on the provider side, and the original error text is right there in the `error_msg` field.
5. **Finally check the Trace ID**: log records carry `trace_id`; use it to align the same request when escalating to the provider.

A few admin entry points tie upstream and downstream together directly: every row in the email and SMS logs has a "Detail" action where the body, request number, and error message are visible in full; when the detail carries ID-type fields you can jump to the corresponding page by ID type (ticket ID, user ID, order ID, and so on). Sending status for a single ticket can be cross-checked back on the ticket conversation page; ticket **upstream delivery** has its own page, "Log Center → Log Management → Upstream Ticket Delivery" (the filters include ticket number, reply ID, reason code, and vendor), uploads and cleanup are recorded in "Upstream Attachment Upload", and scheduled tasks (including ticket auto-close) are in "Scheduled Task Logs".

About the log content itself: email bodies and SMS bodies are **stored in full and not redacted** in the logs, and recipient addresses are shown at their original values as well, so that operations can genuinely review what was sent out. Accordingly, this data contains customer email addresses, phone numbers, and notification bodies, so decide by your site's permission management who can see "Log Center" — `log.list` is a separate permission code, do not hand it out to everyone casually.

## 8. Failure Retry and Rate Limiting

Retry capability covers only three queued jobs, and their parameters are completely identical: at most 3 attempts, backoff intervals of 30 seconds, 120 seconds, and 300 seconds, a per-attempt timeout of 120 seconds, and after the retries are exhausted `failed()` records a warning log carrying the recipient address and context.

| Job                                   | Notifications covered           |
| ------------------------------------- | ------------------------------- |
| `SendTicketNotificationEmailJob`      | All ticket-related emails       |
| `SendClientLoginEmailAlertJob`        | User login alert emails         |
| `SendClientLoginFailureEmailAlertJob` | User login failure alert emails |

All other emails and all SMS messages are **sent synchronously with no retry**: `NotificationService::sendEmail` and `SmsService::sendTemplateSms` call the driver directly inside the request, and on failure the exception is thrown straight back to the caller (while the `message_logs` status is updated to `failed` and `error_msg` is written). Ticket emails are queued only when the queue is usable: with a `sync` queue driver they become "send after response", and with the `database` driver when the `jobs` table does not exist, or with the `redis` driver when no client is available, they likewise fall back to the no-retry path.

There is no additional notification-specific rate limiting on the sending side; what really stops you is rate limiting at the API layer — for example, creating, replying to, and closing a ticket are limited to 10 requests per minute each, and image upload to 12 requests per minute. Bulk outreach must control its own pace; only when you hit a limit do you see provider errors such as 429 in the log.

## 9. Notification Health Check Before Launch

Run through this in order; it catches the vast majority of notification problems before you scale up:

1. Turn on both master switches in "Site Settings", and replace the site name and logo with your own brand (the <code v-pre>{{site_name}}</code> and <code v-pre>{{site_logo}}</code> in templates take exactly these two values).
2. Under "Integration Plugins", enable and bind one driver each for email and SMS, and use the "Test" page to confirm the connection is fine.
3. Use "Send Test" to send one email and one SMS verification code (both are coded `100001`, distinguished by channel) and confirm they arrive.
4. Go to "Email Logs" and "SMS Logs" and confirm both records are `success`, then note their Trace IDs to get familiar with the troubleshooting entry points.
5. Walk through it with a test account: submit a ticket, have support reply, the timeout reminder (you can temporarily lower the auto-close hours to verify it), and auto-close. Check whether all four in-site messages and both customer emails arrive.
6. Confirm that templates whose bodies were edited still render: the detail page preview has no leftover <code v-pre>{{ }}</code> placeholders, and the body of the test send matches expectations.
7. The switches for security emails such as password change and login alerts are in the users' hands; when publishing an announcement, state that they can be adjusted under Notification Preferences in the console, so that support is not held responsible for a switch.

## Related Documents

- [Tickets and Customer Support](tickets.md): trigger conditions, recipient rules, and the boundaries of canned replies for ticket notifications.
- [Email and SMS](../configure/mail-and-sms.md): onboarding, binding, and connectivity self-checks for the email and SMS channels.
- [Site and Brand Settings](../configure/site-settings.md): the site name, the logo, and both notification master switches are all here.
- [Products and Billing](products.md), [Orders and Automatic Provisioning](orders.md), [Finance and Statements](finance.md), [Users and Referral](users.md): the business domains the remaining notification trigger points belong to.
- [Engineering Pitfalls](../../develop/pitfalls.md): read the measured conclusions here before changing templates, variables, or queue configuration.
- [Daily Operations](index.md): the operations map and the daily review recommendations.
