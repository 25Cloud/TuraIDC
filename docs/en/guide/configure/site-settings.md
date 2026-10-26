---
source: guide/configure/site-settings.md
sourceHash: a274c4f93271a1789d715ff7b31f95b50f4c20f9ead27daac43881d9acca3a63
translatedAt: 2026-10-26
---

# Site and Brand Settings

The site name, logo, theme color, and other information visible on all three frontends are maintained on one page in the admin panel; on save they are written to the `settings` table and delivered immediately. This page explains what to enter in each field, what happens when a field is left empty, and how to verify a change.

## 1. Admin Entry

| Page                              | Path                                                    | Page permission | Read/write API permission                            |
| --------------------------------- | ------------------------------------------------------- | --------------- | ---------------------------------------------------- |
| Site settings (basic information) | Site content → Site configuration → Site settings       | `site.view`     | Read `settings.view`, write `settings.manage`        |
| Notification master switches      | "Notification master switches" section on the same page | `site.view`     | Same as above                                        |
| Homepage Banner                   | Site content → Site configuration → Homepage Banner     | `site.view`     | Same as above (homepage decoration uses its own API) |

Visibility of the site settings page is governed by `site.view`, but the form reads from `GET /api/v2/admin/settings` and writes to `POST /api/v2/admin/settings`, and these two APIs require `settings.view` and `settings.manage` respectively. An account granted only `site.view` can open the page, but clicking save shows a no-permission message — this is not a page fault, the permissions are simply incomplete. The permission constant `site.manage` is defined in the system, but no route currently uses it (its purpose has not been confirmed in the code).

## 2. Basic Information Fields

The config group is `basic`; the key name of each item below is the inline code next to it.

| Field                                                      | Default value                                             | Scope                                                                           |
| ---------------------------------------------------------- | --------------------------------------------------------- | ------------------------------------------------------------------------------- |
| `site_name` Site name                                      | `图拉云`                                                  | Official site, user console, admin panel, email                                 |
| `browser_title` Browser title                              | Empty (falls back to the site name)                       | Official site first screen, browser tab, share card                             |
| `site_logo` Site logo                                      | Empty (falls back to `/branding/logo.png`)                | Official site, console navigation                                               |
| `site_favicon` Site favicon                                | Empty (falls back to `/branding/favicon-32.png`)          | Browser tab icon                                                                |
| `client_console_icon` User console icon                    | Empty (falls back to the favicon)                         | Console sidebar, login page                                                     |
| `brand_primary_color` Brand primary color                  | `#165DFF` (TDesign brand blue)                            | Accent color on all three frontends                                             |
| `brand_radius` UI corner radius                            | `6`                                                       | Control and container corner radius on all three frontends                      |
| `product_display_mode` Product display mode                | `list` (list view)                                        | Default form of the storefront product list (visitors can switch it themselves) |
| `service_phone` Official QQ group                          | Empty (falls back to the copy "Group number to be added") | Official site footer, console contact details                                   |
| `service_email` Business contact email                     | Empty (the row is not displayed)                          | Official site footer, console contact details                                   |
| `service_hours` Service hours                              | Empty (the row is not displayed)                          | Official site footer, "About us" page                                           |
| `support_group_qr` Official group chat QR code             | Empty                                                     | Contact entry on the official site and console                                  |
| `support_group_link` Group join link                       | Empty                                                     | Same as above                                                                   |
| `icp_record` ICP filing number                             | Empty (the row is hidden in the footer)                   | Official site footer                                                            |
| `value_added_license` Value-added telecom business license | Empty                                                     | Official site footer                                                            |
| `terms_url` Terms of service link                          | Empty                                                     | Terms of service entry on the registration and order pages                      |
| `privacy_url` Privacy policy link                          | Empty                                                     | Same as above                                                                   |

The brand primary color and corner radius are strictly validated; a wrong value does not just look bad, it falls back to the default silently:

- `brand_primary_color` accepts only `#RRGGBB` or `#RGB`; the three-digit form is expanded to six digits and lowercased. Input containing semicolons, curly braces, commas, or `url()` makes the whole style rule invalid and can even become an injection point, so the system falls back to `#165DFF` directly. The admin form itself also validates with the regex `#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})`, but the server does not trust the frontend and validates once more before persisting.
- `brand_radius` accepts only integer pixels from 0–24; anything above 24 falls back to `6`. The project convention is that containers take this value and controls take half of it, that is the default 6px / 3px; entering a value such as 30 makes the corners visibly smaller — that is the fallback, not a failed save.
- `service_qq_group` and `service_phone` are two candidate keys for the same display slot: the value is read from `service_qq_group` first, then from `service_phone` when that is empty, and when both are empty the placeholder copy "Group number to be added" is shown. If that line in the official site footer says "Group number to be added", this field has not been filled in.

`client_console_icon` takes effect only in the user console; the official site header and footer use the site logo plus the site name. Image fields accept the address produced by a media library upload, and you can also enter the static file name under `/branding/` directly.

`product_display_mode` determines only the form a visitor sees **the first time** they open the ordering page. Visitors can switch between "card view / list view" in the top right of the ordering page (mobile has the same switch bar), and can also choose a fixed width (1200 / 1280 / 1440 / 1600 / 1920) or full screen in the "page width" panel; the two preferences are stored separately in that browser's `localStorage` (`turaidc:shop-display-mode`, `turaidc:shop-page-width`), and switching browsers or devices or clearing the cache returns to the site default. To confirm the site default actually takes effect, open the ordering page in a private window, or clear these two keys first.

## 3. Notification Master Switches

The "Notification master switches" section on the same page belongs to the `notification` group:

- `email_enabled` Enables email notifications, off by default.
- `sms_enabled` Enables SMS notifications, off by default.

These two switches are the only source of truth for whether outbound messages are actually sent. When they are off, outbound sends such as registration verification codes and password recovery return "Email service is temporarily unavailable", while the "Send test email / Send test SMS" buttons on the admin plugin detail page send directly through the driver and are not bound by the master switches — a successful test does not mean outbound sending works, because the test result also returns the master switch state and warns about exactly this. For the channel configuration itself, see [Email and SMS](mail-and-sms.md).

## 4. How Long Changes Take to Apply

- The read API `GET /api/v2/site/config` is not cached; refresh the official site or console to see the new site name.
- Saving the `basic` group bumps the content version number, so the SEO render output and sitemap cache of the official site are invalidated immediately; there is no need to wait for a TTL to expire, and no manual cache purge.
- The browser tab icon and title are first rendered by the first-screen script from the `localStorage` cache left by the previous visit, then overwritten by the API result. **Renaming does not change the title immediately in the same tab**; a newly opened tab behaves correctly.
- Images referenced statically on the official site (for example a favicon written directly in Nginx) may be cached by a CDN or the browser; see [Frontend Site Nginx Rules](../maintain/nginx-rules.md) for that policy.

## 5. Verification

Confirm the real server-side values through the API instead of relying on what the page shows (the page reflects local form state):

```bash
curl -s https://你的官网域名/api/v2/site/config | head -c 800
```

The `site_name`, `browser_title`, `site_logo`, `site_favicon`, `brand_primary_color`, and `brand_radius` in the response are the values actually used by all three frontends. Then confirm each item manually:

1. Open the official site in a new browser tab; the tab title and icon already show the new branding.
2. Refresh the official site home page and the user console; the site name and primary color have changed, and the primary color in the admin panel is in sync after a refresh.
3. The ICP filing number, service hours, and business contact email in the official site footer display the values entered, and any field left empty hides its whole row.
4. Open the ordering page in a private window; the product list renders as list view or card view according to the value of `product_display_mode`. In a normal window a visitor's switch preference overrides the default — this is expected behavior.

If nothing changes after editing, check in this order: whether the account has `settings.manage` (the save itself was rejected), whether `brand_primary_color` / `brand_radius` were reverted by server-side validation, and whether the browser is holding an old tab cache.

## Related Documentation

- [Initial Configuration](index.md): The order of the four configuration steps and the go-live checklist.
- [Email and SMS](mail-and-sms.md): Notification channel configuration; the notification master switches in site settings are only meaningful in combination with it.
- [Deployment and Scheduling](../deploy/scheduling.md): The `settings` table cache is written to Redis; when the cache component fails, configuration falls back to the defaults.
- [Engineering Pitfalls](../../develop/pitfalls.md): Pitfalls measured in practice that configuration changes tend to hit.
