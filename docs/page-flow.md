# Page Flow / Route Map

## Public Routes (no auth)

| Route | Purpose |
|---|---|
| `GET /tree/{id}` | Tree Information Page — main entry point from QR code |
| `POST /tree/{id}/interest` | Submit "I'm Interested" email form (AJAX or full form post) |
| `GET /tree/{id}/prev` | Redirects to the previous tree's info page by `display_order` |
| `GET /tree/{id}/next` | Redirects to the next tree's info page by `display_order` |

## Admin Routes (session auth required)

| Route | Purpose |
|---|---|
| `GET /admin/login` | Admin login form |
| `POST /admin/login` | Authenticate |
| `POST /admin/logout` | End admin session |
| `GET /admin/trees` | List all trees (searchable by species/zone/label/Tree ID; shows display order, zone, species, status, active) |
| `GET /admin/trees/new` / `POST /admin/trees` | Create a tree (choose species + zone, set label/status/image/order/location) |
| `GET /admin/trees/{id}/edit` / `POST /admin/trees/{id}` | Edit a tree |
| `POST /admin/trees/{id}/delete` | Remove/deactivate a tree |
| `GET /admin/zones` | List zones |
| `GET /admin/zones/new` / `POST /admin/zones` | Create a zone |
| `GET /admin/zones/{id}/edit` / `POST /admin/zones/{id}` | Edit a zone |
| `POST /admin/zones/{id}/delete` | Delete a zone (blocked while any tree still references it) |
| `GET /admin/species` | List species |
| `GET /admin/species/new` / `POST /admin/species` | Create a species (name, classification, care instructions, characteristics/properties/benefits/cautions/part uses) |
| `GET /admin/species/{id}/edit` / `POST /admin/species/{id}` | Edit a species — updates every tree of that species at once |
| `POST /admin/species/{id}/delete` | Delete a species (blocked while any tree still references it) |
| `GET /admin/settings` | Configure default map image/URL |
| `POST /admin/settings` | Save map settings |
| `GET /admin/reports` | View scan/visitor/interest stats, export CSV |

## Tree Information Page — Layout

```text
┌───────────────────────────────────────────────┐
│  MAP BANNER IMAGE (clickable → map_url)        │
├───────────────────────────────────────────────┤
│  [ Tree / Plant Image ]                        │
│                                                 │
│  Name                                          │
│  Scientific name · Common name                 │
│  Category · Classification ID · Plant Code · Zone│
│  Description text...                           │
│                                                 │
│  Care Instructions                             │
│  ...                                           │
│  Characteristics                               │
│  ...                                           │
│  Properties                                    │
│  ...                                           │
│  Benefits                                      │
│  ...                                           │
│  Cautions                                      │
│  ...                                           │
│  Uses by Part (flower / fruit / stem / ...)    │
│  ...                                           │
│  (each section only shown if the admin filled  │
│   it in for this plant)                        │
│                                                 │
│  👁 1,204 unique visitors have scanned this tree│
│                                                 │
│  [ I'm Interested ]                            │
│   └─ (click reveals) Email: [______] [Submit]  │
│                                                 │
│  [ ← Previous Tree ]        [ Next Tree → ]    │
└───────────────────────────────────────────────┘
```

Behavior notes:
- Map banner is a plain link/image; click always navigates to the currently configured URL (per-tree override, else global default from `settings`).
- Every scan (including repeats from the same visitor) is logged server-side, but is never surfaced to the visitor as a "you already scanned this" notice — only the aggregate unique-visitor count is shown. See [visitor-identity.md](visitor-identity.md).
- Unique-visitor count is computed server-side on every request (see [database.md](database.md) derived-metrics queries) and rendered directly — no client-side polling needed.
- "I'm Interested" reveals the email form inline (no navigation); submission can be a simple form POST with a redirect back to the same tree page showing a confirmation message, or a small AJAX call — either works with the same `POST /tree/{id}/interest` endpoint.
- Previous/Next buttons are rendered disabled (not just hidden) when at the first/last active tree, per requirement.
- The Plant Code badge shows `trees.plant_code` (the 15-digit ประเภทพืช/รหัสชนิดพืช/โซน/พื้นที่ในสวน/ลำดับ code, see [database.md §4c](database.md)) — distinct from the Tree ID used in the QR link, and from `species.classification_id`. Saving a tree with a new species/zone/garden-area in the admin redirects to `dashboard.php?reprint={id}`, which banners a link to reprint that tree's QR/tag.
- The whole page (labels, section headings, translated content) re-renders in the chosen language — see the QR-scanner page notes below for why the scanner page (which has an active camera) switches languages without navigating, while this page can just reload with `?lang=`.
