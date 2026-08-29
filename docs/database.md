# Database Design

Runnable DDL + seed data: [`install.sql`](install.sql)

## Entity Overview

```text
admins            (separate identity system — admin login only)

zones             (1) ── (many) trees
species           (1) ── (many) trees

trees             (1) ── (many) tree_scans
trees             (1) ── (many) tree_interests
trees             (many) ── (1) settings  [map_url is global, not per-tree, unless overridden]

visitors          (1) ── (many) tree_scans
```

`trees` is the individual plant specimen — the "Plant Asset" / "Tree ID" —
and holds nothing about what species it is or what its care/characteristics
content says; that content lives once on `species` and is shared by every
tree that references it. `zones` is likewise its own table so a physical
area's name/description is edited in one place, not copy-pasted per tree.

## 1. `admins`

Kept fully separate from visitor tables — no foreign keys pointing at visitor data, no shared session mechanism.

| Column | Type | Notes |
|---|---|---|
| id | BIGINT PK AI | |
| username | VARCHAR(100) UNIQUE | |
| password_hash | VARCHAR(255) | bcrypt/argon2 |
| created_at | DATETIME | |

## 2. `zones`

A physical area (e.g. one exhibit zone at the expo). Every tree belongs to exactly one zone.

| Column | Type | Notes |
|---|---|---|
| id | BIGINT PK AI | |
| zone_code | VARCHAR(50) UNIQUE | short admin-assigned code, e.g. `Z1` |
| zone_number | CHAR(3) UNIQUE | 3-digit numeric code — the "โซน" segment of every tree's `plant_code` (§4c) in this zone |
| name / name_en / name_zh | VARCHAR(150) | Thai is the required default |
| description / description_en / description_zh | TEXT NULL | |
| created_at / updated_at | DATETIME | |

## 3. `species`

Master data shared by every individual plant of that species — this is where
name/description/care instructions/characteristics content is entered
*once*, not per specimen.

| Column | Type | Notes |
|---|---|---|
| id | BIGINT PK AI | |
| category_code | CHAR(3) FK → categories.code | the "ประเภทพืช" segment of every tree's `plant_code` (§4c) for this species |
| species_code | CHAR(3) | the "รหัสชนิดพืช" segment; unique together with category_code (`uq_species_category_species`) |
| classification_id | CHAR(10) UNIQUE NULL | legacy/separate code, see §3a — not part of `plant_code` |
| name / name_en / name_zh | VARCHAR(150) | Thai is the required default; EN/ZH fall back to it when blank |
| name_common | VARCHAR(150) NULL | ชื่อสามัญ |
| name_scientific | VARCHAR(150) NULL | ชื่อวิทยาศาสตร์ — Latin binomial, not localized |
| description / description_en / description_zh | TEXT | |
| care_instructions / _en / _zh | TEXT NULL | วิธีดูแล — watering/sunlight/soil etc. |
| characteristics / _en / _zh | TEXT NULL | shown as its own section on the plant page, hidden if blank |
| properties / _en / _zh | TEXT NULL | " |
| benefits / _en / _zh | TEXT NULL | " |
| cautions / _en / _zh | TEXT NULL | drawbacks / risks / who should avoid it |
| part_uses / _en / _zh | TEXT NULL | free-text per-part usage notes (e.g. "flowers: ...; fruit: ...; stem: ...") — not a fixed column per part, since not every plant has every part |
| created_at / updated_at | DATETIME | |

### 3a. `classification_id`

A 10-digit code: the first 3 digits are a `categories.code` (e.g. `001` =
Orchid, `002` = Tree, `003` = Flowering Plant — see the `categories` table),
and the remaining 7 digits identify species/origin/sequence within that
category, admin-assigned. Validated and looked up in application code
(`isValidClassificationIdFormat`, `classificationCategoryCode` in
`includes/functions.php`), not a DB foreign key, since it's a substring of a
fixed-width code rather than its own column. Lives on `species`, not `trees`
— every individual of a species shares the same classification.

### 3b. `category_code` / `species_code` vs. `classification_id`

These are two separate, independently-maintained codes on the same row —
`classification_id` (§3a) is the older 10-digit category+species code shown
as the "Classification ID" badge on the public page; `category_code` +
`species_code` (6 digits total) is newer and exists specifically to feed the
first half of every one of this species' trees' 15-digit `plant_code`
(§4c). They aren't derived from each other and can disagree — admins fill
in both when relevant.

## 4. `trees` (the individual Plant Asset / "Tree ID")

| Column | Type | Notes |
|---|---|---|
| id | BIGINT PK AI | the stable "Tree ID" — see §4a |
| slug | VARCHAR(150) UNIQUE NULL | optional human-friendly URL segment |
| species_id | BIGINT FK → species.id | required |
| zone_id | BIGINT FK → zones.id | required |
| area_code | CHAR(2) DEFAULT '01' | พื้นที่ในสวน — sub-location within the zone; the 4th segment of `plant_code` |
| plant_code | CHAR(15) UNIQUE NULL | printed 15-digit code — see §4c. **Not** the same thing as the Tree ID (§4a); this one changes on a move |
| plant_code_updated_at | DATETIME NULL | stamped whenever plant_code changes — admin UI uses this to prompt a reprint |
| label | VARCHAR(150) NULL | optional staff-facing nickname to tell individuals of the same species apart |
| status | ENUM('healthy','needs_attention','removed') | plant-asset status, independent of `is_active` (public visibility) |
| image_path | VARCHAR(255) | path/URL to this specimen's photo |
| map_image_path | VARCHAR(255) NULL | optional per-tree map override |
| map_url | VARCHAR(255) NULL | optional per-tree map link override |
| latitude / longitude | DECIMAL(10,7) NULL | current physical location; see §4b |
| location_updated_at | DATETIME NULL | set whenever latitude/longitude change |
| display_order | INT | drives Previous/Next navigation; unique, indexed |
| is_active | TINYINT(1) DEFAULT 1 | soft-disable without deleting |
| created_at / updated_at | DATETIME | |

### 4a. Tree ID

The proposal calls for a Tree ID that never changes when the plant's
species/zone/position changes (so a printed QR sticker never goes stale).
The database `id` already has that property; `assetCode()` in
`includes/functions.php` formats it as a human-readable code (default
`NN-UD-000001`, prefix configurable via the `asset_code_prefix` setting) for
display in the admin UI and on printed QR sheets.

### 4b. Location tracking (`latitude`/`longitude`)

These identify where a plant *currently* stands — they play no role in
identifying *which* plant was scanned (that's the Tree ID, fixed for the
plant's whole life). If a plant is physically moved, the admin edits these
two fields (and `zone_id`) on the same row; the Tree ID and QR code never
change. See [architecture.md §4](architecture.md#4-identifying-a-nearby-plant-and-tracking-one-thats-moved).

### 4c. `plant_code` — the printed 15-digit code

Format: `ประเภทพืช(3) / รหัสชนิดพืช(3) / โซน(3) / พื้นที่ในสวน(2) / ลำดับ(4)`,
concatenated with no separators in storage (e.g. `002001001010001`),
displayed with slashes on the admin/public pages.

Unlike the Tree ID (§4a), which is deliberately stable so a printed QR
sticker is never invalidated, `plant_code` is **explicitly designed to
change** when a plant's species, zone, or garden area changes — this was a
deliberate choice (confirmed with the project owner) over keeping location
out of the ID, trading QR/tag reprints-on-move for a code that reads its
current classification+location directly off the label. `recomputeTreePlantCode()`
in `includes/functions.php`:

1. Builds the first 11 characters from the tree's current `species.category_code`
   + `species.species_code` + `zones.zone_number` + `trees.area_code`.
2. If that matches the stored `plant_code`'s first 11 characters, does nothing
   (no spurious reprint prompts on unrelated edits).
3. If it differs (first save, or species/zone/area actually changed), assigns
   a fresh 4-digit sequence — one past the count of other trees already
   sharing that exact 11-character combination — and stamps
   `plant_code_updated_at`.

`admin/tree_form.php` calls this after every save and, if the code changed,
redirects to `dashboard.php?reprint={id}`, which shows a banner linking to
that tree's QR page. `admin/species_form.php` and `admin/zone_form.php` each
also recompute every affected tree's `plant_code` when `category_code`/
`species_code`/`zone_number` change, since those feed into every tree that
references them.

Previous/Next tree query:
```sql
-- previous
SELECT id, display_order FROM trees WHERE is_active = 1 AND display_order < :current ORDER BY display_order DESC LIMIT 1;
-- next
SELECT id, display_order FROM trees WHERE is_active = 1 AND display_order > :current ORDER BY display_order ASC LIMIT 1;
```

## 5. `settings`

Simple global key/value table for admin-configurable values (e.g. default map banner image + URL, the Tree ID display prefix).

| Column | Type | Notes |
|---|---|---|
| setting_key | VARCHAR(100) PK | e.g. `default_map_url`, `default_map_image`, `asset_code_prefix` |
| setting_value | TEXT | |
| updated_at | DATETIME | |

## 6. `visitors`

Represents a *browser*, identified by a cookie-issued UUID — not tied to login.

| Column | Type | Notes |
|---|---|---|
| id | BIGINT PK AI | |
| visitor_uuid | CHAR(36) UNIQUE | value stored in the visitor's cookie |
| first_seen_at | DATETIME | |
| last_seen_at | DATETIME | |
| last_ip_address | VARCHAR(45) | IPv4/IPv6; informational only, not identity |
| last_user_agent | VARCHAR(255) | |

## 7. `tree_scans`

Every scan/page view is logged as an event row. Uniqueness of visitors is derived via `DISTINCT visitor_id`, not by constraining this table to one row per pair.

| Column | Type | Notes |
|---|---|---|
| id | BIGINT PK AI | |
| tree_id | BIGINT FK → trees.id | indexed |
| visitor_id | BIGINT FK → visitors.id | indexed |
| ip_address | VARCHAR(45) | captured at scan time |
| user_agent | VARCHAR(255) | captured at scan time |
| scanned_at | DATETIME | indexed |

Indexes:
- `INDEX (tree_id, visitor_id)` — fast "has this visitor scanned this tree" lookups
- `INDEX (tree_id, scanned_at)` — fast per-tree reporting/time-series

Derived metrics (computed via query, not stored redundantly):
```sql
-- total scans for a tree
SELECT COUNT(*) FROM tree_scans WHERE tree_id = :id;

-- unique visitors for a tree
SELECT COUNT(DISTINCT visitor_id) FROM tree_scans WHERE tree_id = :id;

-- repeated scans for a tree
SELECT COUNT(*) - COUNT(DISTINCT visitor_id) FROM tree_scans WHERE tree_id = :id;

-- has THIS visitor scanned THIS tree before (admin reporting only —
-- never shown to the visitor themselves as a "duplicate scan" notice)
SELECT COUNT(*) FROM tree_scans WHERE tree_id = :id AND visitor_id = :visitor_id;
```

## 8. `tree_interests`

| Column | Type | Notes |
|---|---|---|
| id | BIGINT PK AI | |
| tree_id | BIGINT FK → trees.id | indexed |
| email | VARCHAR(255) | validated server-side |
| submitted_at | DATETIME | |
| visitor_id | BIGINT FK → visitors.id NULL | optional link back to the scan session, not required |

No account creation — this table stands alone from any auth system. (The
project proposal's richer "Interest Event" concept — activity type, contact
channel, lead status — is not implemented yet; this table only covers the
email-capture case.)

## 9. Design Notes

- **IP is not identity.** `ip_address` is stored on both `visitors` (last known) and `tree_scans` (per-event) purely for auditing/abuse analysis. The unique-visitor count is always based on `visitor_uuid`, never IP.
- **Admin/visitor separation.** `admins` has no foreign key relationship to `visitors`, `tree_scans`, or `tree_interests`. Admin session middleware and visitor cookie logic are entirely independent code paths.
- **Scan vs. visit semantics.** "Scan" and "page view" are treated as the same event here — each `GET /tree/{id}` writes a `tree_scans` row. If future requirements need to distinguish QR-scan-origin traffic from prev/next-navigation traffic, add a `source` ENUM(`qr`,`nav`,`direct`) column to `tree_scans`.
- **Species/zone content vs. plant-asset data.** Anything true of *every* individual of a species (name, care instructions, characteristics) lives on `species`; anything true of *this one specimen* (photo, location, status) lives on `trees`. Editing a species' description updates every tree that references it at once — this is deliberate, not a duplication bug.
- **Not yet implemented** (see the project proposal): `observations` (growth/health history), `maintenance_logs` (care activity log), `nursery_stock` (sales qty/price/status), and a richer `tree_interests` with lead status/activity type. These are separate future migrations, not stubbed out here.
- **Two deliberately different ID schemes coexist on `trees`.** `id`/Tree ID (§4a) is the routing/QR-link identity and never changes. `plant_code` (§4c) is a printed classification+location label and is expected to change (with a reprint) when the plant moves. Don't conflate them when reading the code — a function named `assetCode()` is about the former, `computePlantCode()`/`recomputeTreePlantCode()` about the latter.

## 10. Expo-integration tables (added in migrations 0007–0012)

Added to support the JSON API + React frontend integration. All additive — no existing column was renamed or removed, and every new/changed default preserves current site behavior (e.g. `trees.data_status` defaults to `published`, so all pre-existing trees stay publicly visible).

| Table/column | Purpose |
|---|---|
| `admins.role` | ENUM `admin`/`staff`/`sales`, default `admin`. Gates admin pages via `requireRole()` in `includes/auth.php`. No parallel `users` table — reuses the existing session mechanism. |
| `trees.data_status` | ENUM `draft`/`verified`/`published`, default `published`. Public API endpoints only return `published` trees. |
| `plant_location_history` | Append-only move log (`from_zone_id`/`to_zone_id`/`from_area_code`/`to_area_code`, `changed_by`, `changed_at`). A tree's *current* zone/area still live on `trees` itself; this table preserves what it was before, never overwritten. |
| `qr_tags` | Secondary lookup for printed physical labels (`tag_code`, `status`, `scan_count`, `last_checked_at`). **Not** the canonical routing scheme — the public URL is still `/tree/{id}` via `trees.id`. Exists for print-run/install tracking. |
| `audit_logs` | Append-only admin change log (`admin_id`, `action`, `entity_type`/`entity_id`, `before_json`/`after_json`). |
| `tree_interests.consent_at` / `purge_after` / `session_hash` | PDPA fields: consent timestamp required whenever contact info is captured, an auto-purge date, and a SHA-256 session hash instead of storing a raw IP as identity. |
| `highlights` | Daily/scheduled expo event items for the frontend's home-page carousel. Optional `zone_id` link. |
| `news_posts` | Expo news/announcements shown on the home and news pages. |
| `shops` / `products` | Marketplace catalog for the frontend's ecommerce-style pages. Deliberately minimal — no payment/checkout table, no stock decrement logic. `products.sold_count`/`compare_at_price` are display-only counters, not derived from any real order system. `products.species_id` optionally links a product back to a real `species` row. |
| `market_interests` | Marketplace lead capture (mirrors `tree_interests`' shape), kept as its own table rather than folded into `tree_interests` so existing admin interest reports/queries are unaffected. |

## 11. Tree identity, relationships, GPS scan location & AI translation tables

Design only — not yet migrated. Full rationale in the dedicated docs linked
per row; this table is an index into those, not a duplicate of them.

| Table/column | Purpose | Detail |
|---|---|---|
| `origins` (new) | Managed lookup of plant provenance/origin, like `categories` | [`tree-identity-and-relationships.md`](tree-identity-and-relationships.md) §5 |
| `trees.origin_id` (new FK) | Per-tree origin assignment; feeds the `LL` segment of `plant_code` | same |
| `zones.zone_number` (narrowed CHAR(3)→CHAR(2)) | `plant_code`'s `ZZ` segment shrinks from 3 to 2 digits — **breaking format change**, needs a data migration | [`tree-identity-and-relationships.md`](tree-identity-and-relationships.md) §3 |
| `trees.plant_code` (format change) | `CCC-SSS-ZZ-LL-NNNNN`, 15 digits (category+species+zone+origin+5-digit sequence), replacing the current 3+3+3+2+4 layout | same |
| `tree_relationships` (new) | Explicit, admin-editable Previous/Next pointers per tree — replaces `display_order` as the source of public Prev/Next navigation | [`tree-identity-and-relationships.md`](tree-identity-and-relationships.md) §4 |
| `plant_location_history.from_origin_id`/`to_origin_id` (new columns) | Extends the existing move-history table to also snapshot origin changes | [`tree-identity-and-relationships.md`](tree-identity-and-relationships.md) §5 |
| `tree_scans.scan_lat`/`scan_lng`/`gps_accuracy_m`/`gps_available` (new columns) | Visitor's GPS position at scan time, opt-in, never blocks the scan if denied | [`tree-identity-and-relationships.md`](tree-identity-and-relationships.md) §6 |
| `tree_scans.registered_zone_id`/`registered_lat`/`registered_lng` (new columns) | **Snapshot** of the tree's registered location *at scan time* — keeps historical scan records accurate after a later tree move, per this project's explicit "tree movement must not destroy historical scan location records" requirement | same |
| `translation_drafts` (new) | Holds pending AI-generated (Gemini) translations for admin review before they overwrite `species.*_en`/`*_zh` | [`multilingual-and-ai-translation.md`](multilingual-and-ai-translation.md) §2 |
| `zones.center_lat`/`center_lng`/`boundary_geojson` (new columns) | Interactive map zone boundaries/center points, distinct from the existing simple map-banner settings | [`map-system.md`](map-system.md) §1 |
