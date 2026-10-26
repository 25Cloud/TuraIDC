---
source: guide/operate/tickets.md
sourceHash: b51d986c084417cf4b3ec7a62ab546c3878159eaad9e352856120a4136c97927
translatedAt: 2026-10-26
---

# Tickets and Customer Support

This document is for site operators and operations leads who run a support team. It explains the complete flow of a ticket from submission to closure, the permission and visibility boundaries, the behavioral boundaries of automatic pre-replies and timeout closure, and the current state of sending and receiving after a ticket is transferred to an upstream provider. Every field name, status value, and menu path here is taken from the current code; the admin panel pages are `frontend-admin-v3`, and "Admin entry" means the actual route on that panel.

## 1. Departments, Categories, and Statuses

Ticket departments are fixed to four kinds, with the values `sales`, `support`, `billing`, and `abuse`, and the page labels "Sales", "Technical Support", "Billing", and "Complaints" in that order. A department is only a classification label and does not decide who handles the ticket — the assignee does.

Priorities take the values `1` to `4`, labeled "Low", "Medium", "High", and "Urgent"; when a client creates a ticket without submitting a priority, it is stored as `2` (Medium). When a ticket is transferred upstream, the priority is mapped onto three levels, `low` / `medium` / `high`: `4` maps to `high`, both `3` and `2` map to `medium`, and `1` maps to `low`.

Status is the only basis on which a conversation advances. The values are as follows.

| Value | Label          | Action that produces this status                                                 |
| ----- | -------------- | -------------------------------------------------------------------------------- |
| 0     | Open           | The client creates the ticket successfully                                       |
| 1     | Customer reply | The client adds a reply                                                          |
| 2     | Staff reply    | An admin replies; when a pre-reply matches, the ticket is created in this status |
| 3     | Closed         | An admin closes, the client closes, or timeout auto-close closes it              |

The close reason is stored in `close_reason`, with the system supplying the label: `admin` for closed by an admin, `client` for closed by the user, and `auto` for automatic closure. A successful reopen clears it.

## 2. Permissions and Visibility Scope

There are 5 ticket-related permission codes, and all of them must be granted explicitly; apart from the implications noted below, `ticket.manage` implies neither the transfer nor the pre-reply permission.

| Permission code           | What it allows                                                                       | Admin entry                                                     |
| ------------------------- | ------------------------------------------------------------------------------------ | --------------------------------------------------------------- |
| `ticket.list`             | View the ticket list, ticket details, reply list, upstream delivery status and logs  | Ticket Handling → Ticket Management → Ticket List               |
| `ticket.reply`            | Reply to a ticket as staff, upload images, recall your own messages within 2 minutes | Actions on the ticket conversation page                         |
| `ticket.manage`           | Close, reopen, assign an assignee, re-register the upstream callback                 | Actions on the ticket conversation page                         |
| `ticket.delivery_manage`  | Ticket transfer rules, upstream department list, upload protection configuration     | Ticket Handling → Ticket Management → Ticket Transfer Settings  |
| `ticket.pre_reply_manage` | Ticket pre-reply settings                                                            | Ticket Handling → Ticket Management → Ticket Pre-reply Settings |

`ticket.manage` implies `ticket.list` and `ticket.reply`; a built-in default role cannot obtain any ticket permission beyond `ticket.list`, and a "Guest" type role has only `ticket.list`.

Three points about the visibility boundary must be kept firmly in mind:

- The list endpoint cannot filter by department or assignee, so anyone holding `ticket.list` sees every ticket. To make someone see only the tickets they handle, the only way is to withhold `ticket.list` from them.
- Client email addresses are masked in subsequent API responses by the permission code `privacy.view_raw`, rendered as `首字母***@域名` (first letter, three asterisks, and the domain); an account lacking that permission sees the mask and cannot use it to contact the client. Ticket notification emails themselves are unaffected and still go to the real address.
- The "Select assignee" dropdown lists only admins who are **enabled** (`status=1`) and hold `ticket.reply`, returning at most 100 people per call; an account that is disabled or lacks reply permission neither appears as a candidate nor can be assigned (the endpoint reports "This admin has no ticket handling permission" outright).

## 3. Creating Tickets, Replying, and Recalling

Creating a ticket as a client requires a non-empty title (at most 200 characters) and at least one of content or images (content at most 10000 characters); a single message carries at most 9 images, the upload endpoint accepts only `image/jpeg`, `image/png`, and `image/webp`, each image is at most 5 MB, files land in `storage/app/private/tickets/`, and they can be read only through a signed short link. A ticket can be linked to one service instance belonging to that client; picking another user's service is rejected.

The admin panel offers no "create a ticket on behalf of the client" entry: tickets can only be submitted by the client in the member center. The ticket-creation capability an admin sees from the client's point of view exists only in the service console's issue-report entry (Service Instance → Submit Ticket), and the ticket is still created under that client's identity.

Staff replies and client replies share one message channel: the body may be empty but images are then required, and the body is sanitized as plain text before storage. Two conventions are easy to overlook:

- **There are no internal notes.** The system has no "internal only" reply type; every reply a staff member sends is visible to the client in the member center and triggers a client email and an in-site message. To keep an internal communication record, do not use a ticket reply.
- **Recalling has a time limit.** Only the sender can recall their own message, and only within 120 seconds of sending; after a recall the body and attachments are cleared, leaving only a "Message recalled" placeholder. Neither clients nor staff can recall the other side's messages.

A client reply sets the status to "Customer reply"; a staff reply or an upstream callback sets the status to "Staff reply". A closed ticket rejects further replies from either side, and the client must reopen it first. When a close and a reply race, whichever gets the row lock first wins, and the later side receives "The ticket is closed".

## 4. Assignment and Reassignment

The assignment entry is the "Assignee" dropdown in the right column of the ticket conversation page; saving writes `assignee_id`, through the endpoint `PUT /api/v2/admin/tickets/{ticket}/assignment`, which requires `ticket.manage`. Reassignment is simply assigning again: submit another admin ID and there is no need to unassign first.

Assignment affects the notification recipient scope, which is the linkage most prone to errors:

- When a ticket has **no** assignee, the reminder emails for client ticket creation and client replies go to **all** admins who are enabled, hold `ticket.reply`, and have an email address on file (deduplicated by email, sent once).
- When a ticket **has** an assignee and that assignee is themselves on the receivable list, the reminder goes to that one person only and the other support staff receive nothing. Assignment is therefore both a division of labor and a mute switch.

The ticket list defaults to the "In progress" filter (status 0, 1, 2) and supports filtering by status, priority, department, and keyword (fuzzy title match or exact ticket ID), with 20 rows per page by default and a maximum of 100.

### 4.1 Ticket Conversation Page and Endpoint Rate Limits

The ticket conversation page (`/admin/ticket-conversations/{id}`) is split into two columns: the left column holds the "Conversation" history and the reply input box, and the right column holds a description list ("Submitted by, Category, Priority, Assignee, Linked instance, Created at, Close reason") plus the assignee selector; the assignment area is shown only for tickets that are not closed. Clicking a title row in the list page opens this page, and ticket IDs and client IDs can also be jumped to from other modules (such as the log center).

Rate limits on admin endpoints are declared on the routes; know these numbers before bulk operations, so you do not run into "Too many requests":

| Action                                 | Endpoint                                                                                              | Rate limit                                  |
| -------------------------------------- | ----------------------------------------------------------------------------------------------------- | ------------------------------------------- |
| Admin reply                            | `POST /api/v2/admin/tickets/{ticket}/replies`                                                         | No separate limit (requires `ticket.reply`) |
| Recall a message                       | `POST /api/v2/admin/tickets/{ticket}/replies/{replyId}/recalls`                                       | No separate limit                           |
| Reopen a ticket                        | `POST /api/v2/admin/tickets/{ticket}/reopen`                                                          | 10 times per 10 minutes                     |
| Client create / reply / upload / close | `/api/v2/client/tickets`, `/tickets/{id}/replies`, `/tickets/upload-images`, `/tickets/{id}/closures` | 10 / 10 / 12 / 10 times per minute          |

## 5. Pre-reply (Automatic First Response)

A pre-reply makes the client receive a staff reply the moment the ticket is created, avoiding "submitted and nobody answers". Configure it in "Ticket Handling → Ticket Management → Ticket Pre-reply Settings" with the permission code `ticket.pre_reply_manage` and the corresponding endpoint `GET/POST /api/v2/admin/ticket-pre-reply-settings`; the save action writes an admin operation log (action `ticket.pre_reply.update`).

| Field              | Meaning                                                               | Constraints and default                                                                  |
| ------------------ | --------------------------------------------------------------------- | ---------------------------------------------------------------------------------------- |
| `enabled`          | Whether pre-reply is enabled                                          | Required; off by default; the fallback value in `config/ticket_pre_reply.php` is `false` |
| `admin_user_id`    | The name the reply is sent under                                      | Required when enabled; must be an enabled admin holding `ticket.reply`                   |
| `content`          | Pre-reply body for ordinary tickets                                   | When enabled, must be non-empty after sanitization, at most 5000 characters              |
| `upstream_content` | Dedicated body for tickets that match a transfer rule and go upstream | Optional; falls back to `content` when empty, at most 5000 characters                    |

The behavioral boundaries must be agreed with the team up front:

- The pre-reply is committed in the same transaction as ticket creation and **takes effect once, for newly created tickets only**; it is not triggered on every client reply.
- It immediately flips the ticket status to "Staff reply" (status 2), which means the auto-close countdown starts running from the moment the ticket is created (see the next section).
- It **does not forward upstream**: a `pre_reply_skipped` record is left in the delivery log. If the ticket matches a transfer rule, the upstream receives the client's first message rather than the pre-reply.
- It **does not trigger notifications** (the client does not receive a "new reply on your ticket" email because of it).
- Whether the ticket matches a transfer rule decides which body is used: when `upstream_content` is empty there is nothing to decide and `content` is always used.
- An incomplete configuration stays silent: not enabled, no admin selected, content empty after sanitization, admin disabled or having lost `ticket.reply` — none of these produce a pre-reply and none raise an error; only the first three are stopped by validation at save time.

## 6. Timeout Auto-Close and Reminders

Auto-close is controlled by the "Automation Policy" page, reached through "System Settings → Automation Policy" (direct route `/admin/automation`), with the permission `schedule.view`; the parameters live in the `automation` config group.

| Field                             | Meaning                            | Default    | Range/notes                                           |
| --------------------------------- | ---------------------------------- | ---------- | ----------------------------------------------------- |
| `ticket_auto_close_enabled`       | Whether auto-close is enabled      | On         | Once off, tickets can only be closed manually         |
| `ticket_auto_close_after_hours`   | Hours after a staff reply to close | `48`       | 1 to 720; the lower bound is forced to 1 in code      |
| `ticket_auto_close_schedule_mode` | Task execution period              | Every hour | Decides the run time together with the execution time |
| `ticket_auto_close_schedule_time` | Task execution time                | `00:00:00` | Used for alignment when the period is not hourly      |

The selection rule is a single sentence: **scan tickets whose status is "Staff reply" (2) and whose `updated_at` is earlier than `now() - 小时数` (now minus the configured hours)**. Four practical consequences follow:

- Tickets in "Open" and "Customer reply" are never auto-closed, even if they sit for half a year.
- Every client reply refreshes `updated_at` and resets the countdown; the same applies to staff replies.
- On closure, attachments only get a soft-delete marker and the files are not deleted; after a reopen the historical attachments become visible again.
- The notification is sent only after a successful closure: email template `100025` (ticket auto-close notification) plus the in-site message `ticket_auto_closed`. If someone else has already closed the ticket first, no duplicate message is sent.

The reminder goes out once when half of the closing window has elapsed, as an in-site message only and not as an email: the threshold is half of `ticket_auto_close_after_hours` (rounded down, at least 1 hour) and the close threshold has not yet been reached. Each waiting round reminds only once (the idempotency key is `idle_since:` plus the timestamp at the start of that round); as soon as the client replies, the timestamp changes and the next round is computed afresh. The in-site message type is `ticket_pending_reminder`, with the title "Ticket awaiting your attention".

The task itself is registered as `ticket-auto-close` (category "Ticket Management"); its execution records are in "Log Center → Log Management → Automated Task Logs", and you can also search `storage/logs/laravel.log` on the server for `[定时任务] 工单自动关闭` to check which tickets were closed.

## 7. Upstream Ticket Transfer and Callbacks

The transfer switch has three layers, and if any layer is missing the whole ticket is not forwarded: first, the product used by the linked service must be bound to an **enabled** ZJMF Finance interface supplier; second, "Ticket Transfer Settings" must contain an **enabled** rule that matches the ticket's department and product scope; third, neither the title nor the first reply may match that rule's mask keywords. When any layer is unmet, the ticket still flows normally on this site, it simply never appears upstream.

Rule fields (endpoint `GET/POST/PUT/DELETE /api/v2/admin/ticket-delivery-rules`, permission `ticket.delivery_manage`):

| Field                    | Description                                                                                              |
| ------------------------ | -------------------------------------------------------------------------------------------------------- |
| `name`                   | Rule name, at most 120 characters                                                                        |
| `department`             | Which ticket department the rule matches, one of the four                                                |
| `supplier_id`            | Upstream supplier, which must be enabled and have an enabled ZJMF Finance interface binding              |
| `provider_key`           | Not filled in by the front end; the endpoint always writes ZJMF Finance interface                        |
| `product_scope_mode`     | `all` for all products / `selected` for specified products                                               |
| `product_ids`            | At least one when `selected`, and every product must have an enabled ZJMF upstream binding               |
| `upstream_department_id` | Upstream department ID, selected after being pulled from upstream, at most 64 characters                 |
| `enabled`                | Whether the rule is enabled                                                                              |
| `sync_admin_replies`     | Whether **admin** replies are synced upstream; when off, only client messages are forwarded              |
| `mask_keywords`          | One keyword per line; a match on the title or the first reply stops forwarding, at most 10000 characters |

Only one rule may exist for the same supplier + department + product scope mode; a duplicate creation is rejected.

Actual forwarding and callback behavior:

- After a ticket has been delivered successfully, **subsequent historical replies are back-filled**: every reply after the ticket's first message is queued and delivered separately by sender, so the upstream loses no intermediate messages.
- Client replies and admin replies both carry a prefix marker (`[下游用户消息]` / `[下游管理员消息]`), so the upstream can tell the source apart.
- An upstream admin reply written into this site through the callback shows up as a staff message of type `upstream_admin`; the display name on the message is taken from the `admin_name` passed by the upstream and defaults to "Upstream support". Such callbacks only write locally and are never pushed back upstream, which avoids a loop.
- Callbacks are idempotent by event ID: the same event arriving repeatedly records only one reply and leaves `duplicate_event` in the delivery log. Callback attachments are limited to at most 9, each at most 5 MB, and only `image/jpeg`, `image/png`, and `image/webp`.
- A failed callback signature check, a disabled upstream supplier, or no matching local ticket each write a `failed` delivery log and return the reason in the response; when a secret reads as an empty value the service is rejected outright and never degrades into letting the request through.

The troubleshooting entries all live in the "Upstream Transfer" card in the right column of the ticket conversation page: the status labels are "Not configured / Pending send / Sending / Forwarded / Forward failed / Not forwarded", and you can also see the upstream ticket number, attempt count, last attempt time, last result, and a paginated delivery log. When a ticket is not forwarded, a reason code is given; the common ones are `rule_not_matched` (no matching enabled rule), `mask_keyword_matched` (a mask keyword matched), `upstream_product_missing` (the service has no upstream product configured), and `supplier_binding_disabled` (the supplier interface binding is not enabled). If the upstream callback address is invalid (domain changed, port changed), use the "Re-register callback" button on the card to send the registration again (endpoint `POST /api/v2/admin/tickets/{ticket}/upstream-delivery/callback-registration`, requires `ticket.manage`).

## 8. State Machine: What Each Step Allows

| Current status   | Client can                                  | Staff can                                                                        |
| ---------------- | ------------------------------------------- | -------------------------------------------------------------------------------- |
| 0 Open           | Reply, close, upload images                 | Reply, assign, close, reopen (reopen fails; only closed tickets can be reopened) |
| 1 Customer reply | Continue replying, close, upload images     | Reply, assign, close                                                             |
| 2 Staff reply    | Reply (switches to 1), close, upload images | Reply, assign, close                                                             |
| 3 Closed         | Reopen                                      | Reopen                                                                           |

In the closed state the reply endpoints reject both the client and the staff, and the page shows no reply box either; "reopen" is the only way out. After a reopen the status returns to "Open", the close reason is cleared, attachments soft-deleted during the closure become visible again, and the client receives an in-site message `ticket_reopened`. Reopening on the admin side is rate-limited to 10 times per 10 minutes on the endpoint, and the client side is limited in the same way.

## 9. Check Here First When Something Goes Wrong

1. **The client says they did not receive the email**: first confirm whether ticket creation or the reply actually triggered a notification (whether the ticket already has an assignee decides whether the recipients are all support staff or a single person), then go to "Log Center → Log Management → Email Logs" and check the send status by recipient and template number; see [Notifications and In-site Messages](notifications.md).
2. **A support agent says a certain ticket is invisible**: the ticket list does not filter by person, so not seeing it means `ticket.list` is missing. Check that role's permissions in "System Settings → Permission Management".
3. **The assignment dropdown is empty**: this means the system has no enabled admin holding `ticket.reply`. The dropdown itself does not depend on email, but when a recipient has no email the notification fails silently — the assignee receives no reminder either.
4. **The ticket was not pushed upstream**: check the last result and reason code on the "Upstream Transfer" card against the three preconditions in section 7; for upload-related problems, also check the upload protection switch in "Ticket Transfer Settings".
5. **The ticket was closed automatically**: verify the auto-close switch and hour count in "Automation Policy"; the decision looks only at status 2 and `updated_at`, and has nothing to do with whether the client has read the ticket.

## Related Documents

- [Products and Billing](products.md), [Orders and Automatic Provisioning](orders.md), [Finance and Statements](finance.md), [Users and Referral](users.md): other areas that belong to day-to-day operations.
- [Notifications and In-site Messages](notifications.md): templates, variables, and send logs for ticket emails and in-site messages.
- [Email and SMS](../configure/mail-and-sms.md), [Site and Brand Settings](../configure/site-settings.md): when the channel or the site name is not configured properly, ticket notifications also fail to go out.
- [Daily Operations](index.md): the operations map and the recommended daily review.
