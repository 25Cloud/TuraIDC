---
status: current
updated: 2026-09-13
owner: frontend
source: develop/design.md
sourceHash: d4380e9ac146f409f44174d950c74d16aab9210af2c9eecebc1722cfee81ab36
translatedAt: 2026-10-26
---

# Visual and Interaction Guidelines

This document defines the current constraints on visual tokens, layout, and interaction across the three frontends; when an implementation conflicts with this document, follow the working rules and write the correction back into this document. The visual baseline is a light enterprise cloud service console plus a clean marketing site — not a dark large-screen admin backend.

## 1. Overall Positioning

Current frontend responsibilities:

- `frontend-admin-v3`: admin frontend, TDesign Vue Next.
- `frontend-user-v3-www`: public portal (home page, product showcase and purchase, announcements, help center), Element Plus.
- `frontend-user-v4-console`: new user console (including login, registration, and the user center), TDesign Vue Next.

## 2. Shared Visual Tokens

- Brand primary color: `#165DFF`
- Primary hover: `#0E4FCC`
- Page background: `#F5F7FB`
- Secondary background: `#F8FAFC`
- Card background: `#FFFFFF`
- Border: `#E5EAF3`
- Divider: `#EEF2F7`
- Primary text: `#1F2937`
- Secondary text: `#5B6B82`
- Placeholder text: `#94A0B2`
- Success: `#12B76A` / light background `#EAFBF3`
- Warning: `#F59E0B` / light background `#FFF6E5`
- Danger: `#F04438` / light background `#FFF1F0`

Typography uses the system font stack: `Inter, -apple-system, BlinkMacSystemFont, "Segoe UI", "PingFang SC", "Microsoft YaHei", sans-serif`.

## 3. Layout Principles

- Console pages place white content areas on a light gray background; information takes priority over decoration.
- Page section spacing follows an 8px grid; the common spacings are `16px` / `20px` / `24px`.
- Use cards only to hold standalone business content, repeated items, dialogs, and form sections; do not nest page sections inside layers of cards.
- Render status consistently with light-background tags; do not use high-saturation solid-color text directly.
- Table headers use a light gray background, row height stays stable, and amount columns may be right-aligned.
- Button height is typically `36px` - `40px`; primary actions are blue with white text, secondary actions are white with a border.

## 4. Admin Frontend `frontend-admin-v3`

- Use TDesign components, TDesign Icons, the TDesign Starter layout, and the Less system under `src/style/`.
- Do not add explanatory header cards to ordinary admin list pages, and do not use the `Hero + 三卡片` formula.
- List pages start from filters, metrics, a toolbar, or the table card; on detail pages, put Back, Save, and Refresh in a compact toolbar.
- Form pages must have validation, submit loading, disabled states, and error feedback.
- Permissions, routing, menus, breadcrumbs, and the user menu follow the existing layout/store/router patterns.
- Do not mix in Element Plus components, icons, or styles.

## 5. Public Portal `frontend-user-v3-www`

- Use Element Plus together with `src/assets/styles/variables.scss`, `global.scss`, and `element/index.scss`.
- This application is purely the public portal; login, registration, and the user center live in `frontend-user-v4-console`.
- The home page and product pages may carry stronger visual expression, but must stay readable, truthful, and business-oriented.
- Do not turn the purchase and checkout flow into a marketing page layout; prioritize clear configuration, pricing, discounts, and confirmation states.

## 6. User Console `frontend-user-v4-console`

- Use TDesign components, TDesign Icons, the Less system under `src/style/`, and the `shared/user-v3` console components.
- Console pages value scanning efficiency: clear navigation, stable lists, explicit states, and compact actions.
- Detail, billing, service console, ticket chat, and similar pages should reuse `PageScaffold`, `DataState`, `StatusTag`, and the drawer/dialog components first.
- Do not use marketing-style large heroes, dark large-screen layouts, heavy gradient backgrounds, or purely decorative cards.

## 7. Icons and Assets

- TDesign frontends: use only `tdesign-icons-vue-next`.
- Element Plus frontends: use only `@element-plus/icons-vue`.
- Do not use emoji in place of functional icons.
- Put brand and shared assets under `public/branding/` first, or group them semantically under `public/img/`.

## 8. Prohibited Patterns

- Do not build the admin backend in a dark large-screen style by default.
- Do not use marketing-style hero banners on ordinary list pages.
- Do not fill entire pages with high-saturation gradients, glassmorphism, or glowing decorations.
- Do not mix multiple border radii, shadows, primary colors, or UI frameworks on the same page.
- Do not turn business pages into marketing cards that only display descriptive copy.

## Related Documentation

- [Frontend project conventions](frontend.md): engineering constraints and verification commands for the three frontends.
- [Architecture overview](architecture.md): system structure and runtime model.
