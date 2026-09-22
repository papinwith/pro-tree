# System Architecture

## 1. High-Level Flow

```text
Physical Tree
   │
   │  (has a printed QR Code sticker)
   ▼
QR Code encodes: https://domain.com/tree/{slug-or-id}
   │
   ▼
Visitor's Phone Camera / QR Scanner App
   │
   ▼
Tree Information URL (public, no login)
   │
   ▼
PHP Web Application
   ├── Public Controller Layer   (TreeController, ScanController, InterestController)
   ├── Visitor Identity Layer    (cookie/visitor-id issuance + lookup)
   ├── Admin Controller Layer    (auth-protected: TreesAdmin, ZonesAdmin, SpeciesAdmin,
   │                              MapConfigAdmin, ReportsAdmin)
   │
   ▼
MySQL Database
   ├── zones            ─┐
   ├── species           ┼── referenced by trees.zone_id / trees.species_id
   ├── trees            ─┘   (the individual Plant Asset / "Tree ID")
   ├── tree_scans
   ├── tree_interests
   ├── visitors
   ├── admins
   └── settings (map URL, Tree ID prefix, etc.)
   │
   ▼
Response: Rendered Tree Information Page
   ├── Map banner (clickable → configured URL)
   ├── Plant image (top) → name (+ scientific/common name) → classification
   │   + zone → description
   ├── Detail sections, sourced from the plant's species: care instructions,
   │   characteristics, properties, benefits, cautions, uses by part (each
   │   shown only if the admin filled it in on that species)
   ├── Unique visitor count (repeat scans are logged silently, never
   │   flagged to the visitor as "you already scanned this")
   ├── "I'm Interested" email form
   └── Previous / Next tree navigation
```

## 2. Components

### 2.1 Public-facing (no auth)
| Component | Responsibility |
|---|---|
| `GET /tree/{id-or-slug}` | Resolve tree, issue/read visitor cookie, log a scan, render the info page |
| `POST /tree/{id}/interest` | Validate + store an email interest submission |
| `GET /tree/{id}/prev`, `GET /tree/{id}/next` | Resolve neighboring tree by `display_order` and redirect |
| Map banner click | Simple `<a href="{settings.map_url}">` — no backend needed, just reads configured URL at render time |

### 2.2 Admin-facing (auth required, separate from visitor system)
| Component | Responsibility |
|---|---|
| Admin login | Session-based auth, `admins` table, hashed passwords |
| Zone management (`admin/zones.php`, `zone_form.php`) | CRUD for zones: code, name, description |
| Species management (`admin/species.php`, `species_form.php`) | CRUD for species: name/scientific/common name, classification, care instructions, characteristics/properties/benefits/cautions/part uses — entered once, shared by every tree of that species |
| Tree management (`admin/dashboard.php`, `tree_form.php`) | CRUD for individual plant assets: species, zone, label, status, image, location, `display_order`, active/inactive; searchable by species/zone/label/Tree ID |
| Map settings | Configure the map image and the target URL it links to |
| Reporting | View scan counts, unique visitors, repeat scans, interest submissions (export as CSV) |

### 2.3 Cross-cutting
| Concern | Approach |
|---|---|
| Visitor identity | First-party cookie holding a generated `visitor_id` (UUID); see [visitor-identity.md](visitor-identity.md) |
| Rate/abuse limiting | Optional: throttle scan-logging per visitor_id+tree_id within a short window to avoid double-counting page refreshes as new scans |
| QR generation | Each tree's URL is deterministic (`/tree/{id}`), so QR images can be generated on demand or pre-generated in bulk by admin tooling |

## 3. Request Flow — Scanning a Tree

```text
1. Visitor scans QR → GET /tree/42
2. App checks for `visitor_id` cookie
     - not present → generate UUID, set cookie (long expiry, e.g. 2 years)
     - present → reuse it
3. App looks up (or creates) a `visitors` row keyed by visitor_id
     - stores/updates last_seen_ip, last_user_agent
4. App inserts a `tree_scans` row for this event regardless of whether
   (tree_id=42, visitor_id=X) has been seen before — every visit is logged,
   including repeats, but a repeat is never surfaced to the visitor as a
   "duplicate scan" notice; it only shows up later in admin reports
5. App computes:
     - unique_visitor_count = COUNT(DISTINCT visitor_id) WHERE tree_id=42
     - total_scan_count     = COUNT(*) WHERE tree_id=42
     - repeat_scan_count    = total_scan_count - unique_visitor_count
6. App resolves prev/next tree via display_order
7. App renders the Tree Information Page with all of the above
```

## 4. Identifying a Nearby Plant, and Tracking One That's Moved

The system never tries to recognize a plant from a photo, and it doesn't use
the visitor's GPS to guess which plant they're near. Identity comes entirely
from the physical QR sticker attached to the plant:

- **Identifying "this plant, right here"**: each plant has exactly one QR
  code, printed once and physically attached to it, encoding a fixed URL
  (`/tree/{id}`). Scanning that sticker is the identification step — there is
  no ambiguity about "which nearby plant" because the visitor is scanning a
  code stuck to the specific plant in front of them, not searching a list.
- **If a plant is physically relocated** (replanted, moved to a different bed,
  etc.), its `id`, `classification_id`, and QR *link* all stay exactly the
  same — nothing about the plant's identity changes, so the underlying
  `/tree/{id}` URL keeps working with no reprint needed for the scan itself.
  The admin updates the row's `zone_id`/`latitude`/`longitude` (see
  `docs/database.md` §4b) to reflect where it now stands; this keeps the
  scan history (`tree_scans`) and interest submissions (`tree_interests`)
  intact across the move, since they're keyed by the unchanged `tree_id`.
  **However**, the plant's printed `plant_code` (`docs/database.md` §4c) —
  the 15-digit ประเภทพืช/รหัสชนิดพืช/โซน/พื้นที่ในสวน/ลำดับ label — is a
  separate, deliberate exception to this stability: it encodes the current
  zone/area, so it *does* change on a move, and the admin UI prompts a
  reprint of the QR/tag when that happens. The QR pixel pattern's target URL
  doesn't need to change; only the human-readable code printed next to it does.

## 5. Scope vs. the Pilot Proposal

This codebase implements the proposal's data-model foundation (`zones`,
`species`, `trees` as Plant Asset) and the Public Plant Page / Staff
Inventory / Zone-and-Species-aware search modules. Not yet implemented:
`observations` (growth/health history over time), `maintenance_logs` (care
activity log), `nursery_stock` (sales qty/price/status), a Zone View
completeness dashboard, and a richer Interest Dashboard with lead status —
these are separate future migrations building on the same `zones`/`species`
foundation, not stubbed out here.

## 6. Why PHP + MySQL (per requirements)

- No login needed for visitors → simple stateless page renders, cookie for identity only.
- MySQL relational schema fits well: trees ⟷ scans ⟷ interests are naturally normalized, foreign-keyed tables.
- Admin auth is isolated in its own table/session namespace, fully separate from the visitor cookie mechanism — the two identity systems never overlap.
