---
source: develop/backend-structure.md
sourceHash: 6ab362a484c0c922a100c0afd10890e6c7313a0e320da3c65f1429908c40eb05
translatedAt: 2026-10-26
---

# Backend Directory Classification Standard

- Document nature: current direction
- Alignment date: `2026-07-02`
- Audience: backend developers, code cleanup, and documentation maintainers
- Scope: the directories under `backend/` and the placement rules for new PHP files

## 1. Goals

This standard establishes stable directory classification and file placement rules for `backend`, reducing problems such as files of the same kind scattered across many places, where a new file goes being decided by ad hoc judgement every time, and one directory crammed with cross-domain classes.

The execution principles of this standard:

1. Converge incrementally on top of the real current code structure.
2. Standardize the placement of new files first, then deal with historical miscellany.
3. Do not move namespaces on a large scale in one pass just so that things "look tidy".
4. Existing stable reference paths take priority; do not perform unrelated refactoring.

## 2. Backend Top-Level Directory Classification

| Directory    | Classification           | Purpose                                                        | Rule                                    |
| ------------ | ------------------------ | -------------------------------------------------------------- | --------------------------------------- |
| `app/`       | Business code            | Controllers, services, models, support classes, commands, jobs | Long-lived business code goes only here |
| `bootstrap/` | Framework bootstrap      | Laravel startup configuration                                  | No business logic                       |
| `config/`    | Configuration layer      | System configuration, driver configuration                     | No runtime data                         |
| `database/`  | Data structure layer     | migrations, seeders, factories                                 | Database-related content only           |
| `public/`    | Web entry layer          | Public entry points, public static assets                      | No business implementation              |
| `routes/`    | Routing layer            | admin/client/site/web/console routes                           | Route registration only                 |
| `scripts/`   | Maintenance scripts      | One-off export, diagnosis, and repair tools                    | No long-lived business classes          |
| `storage/`   | Runtime output           | Logs, cache, uploads, temporary files                          | No hand-written business source         |
| `tests/`     | Test layer               | Feature, Unit, test base classes                               | All test files live here                |
| `vendor/`    | Third-party dependencies | Composer dependencies                                          | Do not modify by hand                   |

### 2.1 Top-Level Prohibitions

- Do not put new business PHP files directly in the `backend/` root directory.
- Do not put long-lived business logic in `scripts/`.
- Do not mix endpoints, services, and models into `routes/`, `config/`, or `public/`.
- Do not keep temporary debugging artifacts as part of the formal structure.

## 3. `app/` Directory Classification

The current `app/` directory is divided into the following fixed layers:

| Directory     | Classification     | Purpose                                                             |
| ------------- | ------------------ | ------------------------------------------------------------------- |
| `Http/`       | Delivery layer     | Controllers, middleware, request validation, response resources     |
| `Services/`   | Business layer     | Business flows, domain services, upstream wrappers                  |
| `Models/`     | Entity layer       | Eloquent models                                                     |
| `Support/`    | Support layer      | Cross-domain utility classes, generators, sanitizers, value objects |
| `Constants/`  | Constants layer    | Status enums, permission codes, error code constants                |
| `Exceptions/` | Exception layer    | Business exceptions, custom exceptions                              |
| `Jobs/`       | Async job layer    | Queue jobs                                                          |
| `Console/`    | Command layer      | Artisan commands                                                    |
| `Providers/`  | Registration layer | Laravel providers                                                   |
| `Traits/`     | Reuse layer        | Lightweight shared traits                                           |
| `Casts/`      | Cast layer         | Custom field casts                                                  |

## 4. `Http/` Directory Classification Rules

### 4.1 Controllers

Current controller classification:

- `app/Http/Controllers/Admin/`
  - Admin-side endpoints
- `app/Http/Controllers/Client/`
  - User-side endpoints
- `app/Http/Controllers/`
  - Keep only the following two kinds
  - Base controller classes
  - Public site / public entry controllers, such as `SiteConfigController.php`, `SiteHomeController.php`, `SecureAssetController.php`

### 4.2 Controllers Placement Rules

- New admin-side endpoint: put it in `Controllers/Admin/`
- New user-side endpoint: put it in `Controllers/Client/`
- New public site endpoint: put it at the top level of `Controllers/`, and name the file with `Site` or an explicit public-resource meaning
- Do not add admin-side or user-side controllers at the top level of `Controllers/`

### 4.3 Requests

`Requests/` is already split by side:

- `app/Http/Requests/Admin/`
- `app/Http/Requests/Client/`

Subsequent rules:

- Request objects within a side continue to go into business-domain subdirectories
- For example:
  - `Admin/Content/`
  - `Admin/Product/`
  - `Admin/User/`
  - `Client/Auth/`
  - `Client/Invoice/`
  - `Client/Service/`

### 4.4 Resources

`app/Http/Resources/` still has a relatively large number of flat files.

Convergence rules:

- Existing resource classes are not forcibly migrated as a whole for now
- New resource classes get business-domain subdirectories so that the flat layout does not keep growing
- Recommended directories:
  - `Resources/Admin/`
  - `Resources/Client/`
  - `Resources/Content/`
  - `Resources/Product/`
  - `Resources/User/`

## 5. `Services/` Directory Classification Rules

### 5.1 Current State

`app/Services/` now mixes two kinds of content:

1. Domain directories that have already taken shape
   - `ClientServiceConsole/`
   - `ProductCatalog/`
   - `Order/`
   - `Upstream/`
   - `User/`
2. A large number of service classes still flat at the top level

This shows that `Services/` is currently the area most likely to turn messy.

### 5.2 Reserved Use of the Top-Level `Services/`

From now on the top-level `Services/` keeps only:

- Cross-domain orchestration services
- Classes left over from the old structure that have not been migrated yet
- Single-file system services that will not expand into a subdomain in the short term

For example, these kinds are currently acceptable to stay at the top level:

- `AuthService.php`
- `PaymentService.php`
- `NotificationService.php`
- `ScheduleTaskService.php`

### 5.3 Placement Rules for New Services

New services go into subdirectories by business domain first; they are no longer flat under the `Services/` root by default.

Recommended convergence directories:

| Directory                        | What goes there                                                                                           |
| -------------------------------- | --------------------------------------------------------------------------------------------------------- |
| `Services/User/`                 | Services for user profiles, user balance changes, user management                                         |
| `Services/Order/`                | Order placement, invoices, payment projections, payment orchestration                                     |
| `Services/ProductCatalog/`       | Products, categories, supplier product mapping, catalog capabilities                                      |
| `Services/ClientServiceConsole/` | Service console, monitoring, NAT, security groups, VNC                                                    |
| `Services/Upstream/`             | Supplier driver foundation, capability contracts, provider resolution and concrete driver implementations |
| `Services/Content/`              | Articles, categories, media library, site content                                                         |
| `Services/Referral/`             | Referrals, commission, withdrawals, levels                                                                |
| `Services/Site/`                 | Site aggregation, home page, SEO, public product reads                                                    |
| `Services/Automation/`           | Automation jobs, invoice cleanup, service lifecycle automation                                            |
| `Services/Integrations/Plugins/` | Plugin scanning, installation, configuration, runtime registration, and domain adapters                   |

Placement of real third-party implementations:

- Real provider implementations for payment, real-name verification, SMS, email, and upstream servers go into `backend/plugins/{domain}/{slug}/` first.
- The platform-side `app/Services/Integrations/Plugins/` keeps only plugin infrastructure and adapters; it does not carry supplier-specific protocol details.
- Generic upstream provider resolution, capability contracts, and shared transport still go in `Services/Upstream/`.
- Do not add new singular `Services/Integration/` directories; historical references are checked against the real current directories.

### 5.4 When to Create a New Subdirectory

Create a new directory, instead of staying flat, when any one of the following is true:

1. The same business domain is expected to have 3 or more service classes.
2. The same business domain has "read service + write service + upstream adaptation" at the same time.
3. Service names have already started to share a prefix, for example `Site*Service`, `Referral*Service`.
4. One service needs accompanying DTOs, parsers, and dedicated helper classes.

### 5.5 Disallowed Ways of Adding Services

- Do not keep adding `XxxService.php.new`, `XxxService_v2.php`, `NewXxxService.php`
- Do not leave 5 to 10 services of the same business domain flat in the root directory for a long time
- Do not pollute generic service classes with "supplier-specific logic"

## 6. `Models/` Directory Classification Rules

`app/Models/` currently stays flat.

This is an acceptable state for now, because:

- Laravel models are referenced at high frequency by nature
- Splitting models into namespaces brings a higher migration cost
- Although there are many models right now, their semantics are still clear

The rules for the model layer are therefore:

- At this stage models keep staying flat; no large directory-based migration
- New models keep one file per entity first
- Only when a model needs helper objects tightly bound to the same domain, put the helper objects in `Support/` or `Casts/` first rather than rushing to split `Models/`

## 7. `Support/` Directory Classification Rules

`app/Support/` holds cross-domain support classes only, not business flows.

Types that may go here:

- Response builders
- Serial number generators
- Pure utility value objects
- Sanitizers
- URL and file helper classes
- Site configuration readers

Types that must not go here:

- Services containing large amounts of business queries
- Flow classes that directly rewrite business state
- "Pseudo utility classes" used by one single business domain only

If a class serves only one single business domain, put it back into that business domain's directory instead of `Support/`.

## 8. Rules for `Constants` / `Exceptions` / `Traits`

### 8.1 Constants

- Hold status enums, permission codes, and error code constants
- Do not put logic that depends on the database at runtime here

### 8.2 Exceptions

- Hold business exceptions and custom exception types
- Do not write ordinary flow control as exception classes

### 8.3 Traits

- Hold lightweight reuse snippets only
- When a trait costs more to understand than one business domain, fold it back into a Service or Support class

## 9. Quick Reference for New File Placement

| File to add                         | Directory                                              |
| ----------------------------------- | ------------------------------------------------------ |
| Admin-side controller               | `app/Http/Controllers/Admin/`                          |
| User-side controller                | `app/Http/Controllers/Client/`                         |
| Public site controller              | `app/Http/Controllers/`                                |
| Admin-side request validation       | `app/Http/Requests/Admin/<业务域>/`                    |
| User-side request validation        | `app/Http/Requests/Client/<业务域>/`                   |
| Business resource class             | `app/Http/Resources/<业务域>/`, or split by side first |
| Cross-domain response/utility class | `app/Support/`                                         |
| Domain service                      | `app/Services/<业务域>/`                               |
| Queue job                           | `app/Jobs/`                                            |
| Artisan command                     | `app/Console/Commands/`                                |
| Migration                           | `database/migrations/`                                 |
| One-off maintenance script          | `scripts/`                                             |
| Test                                | `tests/Feature/` or `tests/Unit/`                      |

## 10. Priority Areas to Keep From Getting Messy

Based on the current state of the repository, prioritize keeping the following areas from getting messier:

1. `app/Services/`
2. `app/Http/Resources/`
3. `scripts/`
4. Temporary files in the `backend/` root directory

## 11. Execution Recommendations

### 11.1 Rules to Enforce Now

- New business services go into subdirectories first and are no longer flat by default
- New request objects must be split by side
- New public site controllers may only go at the top level of `Controllers/`
- New explanatory documents do not go in the `backend/` root directory

### 11.2 Things Not Done for Now

- No wholesale migration of `Models/` for now
- No batch renaming of existing service namespaces for now
- Do not move all historical files in one pass just because of this documentation standard

### 11.3 Optional Convergence Order Afterwards

If the cleanup continues later, do it in this order:

1. Converge `Services/` first
2. Then converge `Http/Resources/`
3. Finally clean up the historical miscellany in the root directory and `scripts/`

## 12. Prohibitions

- Do not drop new business files into the `backend/` root directory
- Do not distinguish versions with filename suffixes such as `.new`, `_v2`, `final`
- Do not keep "temporary diagnosis scripts" as formal business implementations for a long time
- Do not mix controllers across admin/client/site
- Do not stuff heavy business flows into `Support/`
