# Tree Identity, Location, Relationships & Scan Location

Design only — no application code yet. This is the core data-separation
document the rest of the system depends on: five concepts that must never
be conflated, per the project's own stated principle:

```text
Tree Identity  ≠  Tree Location  ≠  Tree Zone  ≠  Tree Relationship  ≠  Scan Location
```

## 1. The Five Concepts

| Concept | What it answers | Stable across a move? | Where it lives |
|---|---|---|---|
| **Tree Identity** | "Which individual plant is this, forever?" | Yes — never changes | `trees.id` (internal), QR/URL target |
| **Classification code** (display label) | "What category/species/zone/origin/sequence does this tree currently read as?" | **No** — recomputed on move | `trees.plant_code` (§2) |
| **Tree Location** | "Where does this specimen physically stand right now, and where did it used to stand?" | Current value changes; history preserved | `trees.latitude/longitude` (current) + `plant_location_history` (append-only past) |
| **Tree Zone** | "Which managed area does this tree currently belong to?" | Current value changes; history preserved | `trees.zone_id` (current) + `plant_location_history` |
| **Tree Relationship** | "Which tree is Previous/Next from here, for on-site walking navigation?" | Editable independently of location | `tree_relationships` (§4) — **not** derived from location/order automatically |
| **Scan Location** | "Where was the visitor, and where was this tree registered, at the moment of this specific scan?" | Frozen at scan time, never retroactively changed | `tree_scans` GPS + snapshot columns (§6) |

## 2. Tree ID vs. Classification Code — resolving the QR contradiction

Two rules from the brief are both correct but can't both apply to the same
value:

- "The Tree ID must remain permanently associated with the individual tree
  even if the tree is moved" / "QR Code should remain valid even if the
  tree is moved."
- "Tree ID: `CCC-SSS-ZZ-LL-NNNNN`" — but Zone (`ZZ`) and Origin (`LL`) are
  exactly the two things that change on a move.

**Resolution (confirmed):** these are two different identifiers, exactly
as the current system already separates them —

- **Stable Tree ID** — `trees.id` (an internal integer). This is what the
  QR code encodes and what routes the public page:
  `https://example.com/tree.php?id={trees.id}`. Never changes, never
  reused, so a printed/laminated QR sticker is never invalidated by a
  zone or origin change.
- **Classification code** (`plant_code`, format `CCC-SSS-ZZ-LL-NNNNN`,
  **15 digits** — see confirmation below) — a human-readable label shown
  *on* the tree page and printed *alongside* the QR code, built from the
  tree's species category (`CCC`), species (`SSS`), current zone (`ZZ`),
  current origin/provenance (`LL`), and a sequence number (`NNNNN`).
  Recomputed whenever species/zone/origin changes, exactly like the
  existing `recomputeTreePlantCode()` behavior — see
  [`database.md` §4c](database.md#4c-plant_code--the-printed-15-digit-code)
  for the existing mechanism this extends.

**Digit count:** the brief states "14 digits" but its own structure
(3+3+2+2+5) and example (`001023050100001`) both total **15** — proceeding
with 15, matching the example, per your confirmation.

```text
QR code / URL           ──►  trees.id            (stable, routing identity)
Printed label on page   ──►  trees.plant_code     (CCC-SSS-ZZ-LL-NNNNN, changes on move)
```

## 3. Format Change from the Current System

The existing schema's `plant_code` is `category(3) + species(3) +
zone_number(3) + area_code(2) + sequence(4)`. This design changes two
segments to match the new spec:

| Segment | Current | New | Source |
|---|---|---|---|
| Zone | 3 digits (`zones.zone_number`) | **2 digits** | `zones.zone_number` narrowed to `CHAR(2)` |
| Origin | 2 digits, meant "sub-area in garden" (`trees.area_code`) | **2 digits, repurposed as Origin/provenance** | new `origins` table + `trees.origin_id` (§5) |
| Sequence | 4 digits | **5 digits** | more headroom (99,999 vs 9,999 per combination) |

This is a **breaking format change** to a field already populated for the
existing 103 seeded trees (`zone_number` currently stores `'001'`–`'010'`,
which narrows cleanly to `'01'`–`'10'`, so no zone-code collisions expected
at current seed scale — but this still needs an explicit migration step
that reformats `zone_number`, adds `origins`/`origin_id`, and recomputes
every existing tree's `plant_code`). Flagging as a migration, not
executing it yet, per "design only."

## 4. Tree Relationships (Previous / Next)

**Current implementation** (`trees.display_order`, a single global sort
integer — Prev/Next derived by `ORDER BY display_order`) does not satisfy
this brief's requirement: relationships must be **explicit, pairwise, admin
-editable edges**, not an automatically-derived global sequence ("do not
hard-code neighboring trees").

**New table:**

```sql
CREATE TABLE tree_relationships (
    tree_id      BIGINT UNSIGNED NOT NULL UNIQUE,
    prev_tree_id BIGINT UNSIGNED NULL,
    next_tree_id BIGINT UNSIGNED NULL,
    updated_by   BIGINT UNSIGNED NULL,  -- admins.id
    updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_tr_tree FOREIGN KEY (tree_id)      REFERENCES trees(id) ON DELETE CASCADE,
    CONSTRAINT fk_tr_prev FOREIGN KEY (prev_tree_id) REFERENCES trees(id) ON DELETE SET NULL,
    CONSTRAINT fk_tr_next FOREIGN KEY (next_tree_id) REFERENCES trees(id) ON DELETE SET NULL
) ENGINE=InnoDB;
```

**Symmetry invariant** (enforced in application code, not the DB — MySQL
can't express "B.prev must equal A" as a constraint): if `A.next_tree_id =
B`, then `B.prev_tree_id` must equal `A`. A single helper function owns
every write to this table so the invariant can never be violated by a
partial update:

```text
setNextTree(adminId, treeA, treeB):
    BEGIN TRANSACTION
      UPDATE tree_relationships SET next_tree_id = treeB, updated_by = adminId WHERE tree_id = treeA
      UPDATE tree_relationships SET prev_tree_id = treeA, updated_by = adminId WHERE tree_id = treeB
    COMMIT
```

**Rewire example from the brief** (Tree A is moved; its old next-neighbor
B should be replaced by S):

```text
Before:  A ──next──► B          (and B.prev = A)
Admin action: setNextTree(A, S)

After:   A ──next──► S          (and S.prev = A)
         B.prev_tree_id = NULL  (the broken end — admin re-links B
                                  to a new neighbor separately, or
                                  leaves it as a chain endpoint)
```

`display_order` is **kept**, but its scope narrows to admin-list sorting
only (the trees list table in `admin/dashboard.php`) — it no longer drives
public Prev/Next navigation. `admin/tree_relationships.php` (new) is where
an admin drags/selects a tree's prev/next explicitly. One-time seed: on
first migration, initialize `tree_relationships` from the current
`display_order` sequence so existing chains aren't lost, then let admins
diverge from it freely afterward.

Public Prev/Next (`tree.php`) changes from an `ORDER BY display_order`
query to a direct lookup:

```sql
SELECT prev_tree_id, next_tree_id FROM tree_relationships WHERE tree_id = :id;
```

## 5. Tree Location, Zone & Origin

Already correctly separated by the current schema's design — this section
confirms what's reused and what's added.

**Reused as-is:**
- `trees.zone_id`, `trees.latitude`/`longitude`, `trees.location_updated_at`
  — the tree's *current* location/zone.
- `plant_location_history` — append-only move log (`from_zone_id`/
  `to_zone_id`, `changed_by`, `changed_at`), already added in this
  project's schema work. Extends cleanly to also capture origin changes
  (§6 below).

**New — Origin as a managed, per-tree lookup** (confirmed: per-specimen
provenance, not a species trait):

```sql
CREATE TABLE origins (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    origin_code CHAR(2) NOT NULL UNIQUE,   -- feeds the LL segment of plant_code
    name_th     VARCHAR(120) NOT NULL,
    name_en     VARCHAR(120) NULL,
    name_zh     VARCHAR(120) NULL,
    description TEXT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

ALTER TABLE trees
  ADD COLUMN origin_id BIGINT UNSIGNED NULL AFTER zone_id,
  ADD CONSTRAINT fk_trees_origin FOREIGN KEY (origin_id) REFERENCES origins(id);
```

`trees.area_code` (existing CHAR(2), previously "sub-area in garden") is
repurposed to always mirror `origins.origin_code` for the tree's current
`origin_id` — same pattern as how `zone_number` already feeds the ZZ
segment. `recomputeTreePlantCode()` extends to include this segment,
exactly like it already reacts to species/zone changes.

**Extend `plant_location_history`** to also snapshot origin changes:

```sql
ALTER TABLE plant_location_history
  ADD COLUMN from_origin_id BIGINT UNSIGNED NULL,
  ADD COLUMN to_origin_id   BIGINT UNSIGNED NULL;
```

## 6. QR Flow

```text
Visitor scans QR ──► URL: tree.php?id={trees.id}
        │
        ▼
Resolve tree by trees.id (stable identity)
        │
        ▼
Load display data: species (name/description/category), current zone,
current origin, plant_code label, image, prev/next from tree_relationships
        │
        ▼
Record scan (see §7 for GPS/location handling) + visitor identity
(unchanged from existing visitor-identity.md flow)
        │
        ▼
Render Tree Information Page
```

QR generation/printing continues to use the existing `qr_tags`/
`generateTreeQrCode()` mechanism from this project's earlier schema work —
no change needed there, since it already targets the stable `trees.id`.

## 7. Scan Location Tracking (GPS)

**The critical rule from the brief, made concrete:** a tree's *registered*
location can change after a scan happened (the tree gets moved next
season) — but the *historical scan record* must keep showing where the
tree was registered **at the time of that scan**, not wherever it is now.
Simply joining `tree_scans → trees.latitude/longitude` live would silently
rewrite history every time a tree moves. So the registered location is
**snapshotted onto the scan row itself**, not looked up live.

```sql
ALTER TABLE tree_scans
  ADD COLUMN scan_lat           DECIMAL(10,7) NULL,      -- visitor's GPS, if granted
  ADD COLUMN scan_lng           DECIMAL(10,7) NULL,
  ADD COLUMN gps_accuracy_m     DECIMAL(6,2)  NULL,
  ADD COLUMN gps_available      TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN registered_zone_id BIGINT UNSIGNED NULL,    -- snapshot of trees.zone_id at scan time
  ADD COLUMN registered_lat     DECIMAL(10,7) NULL,      -- snapshot of trees.latitude at scan time
  ADD COLUMN registered_lng     DECIMAL(10,7) NULL;      -- snapshot of trees.longitude at scan time
```

Flow:

```text
Visitor's browser requests geolocation permission (non-blocking)
        │
   ┌────┴────┐
Granted            Denied / unavailable / times out
   │                        │
   ▼                        ▼
Capture scan_lat/lng,    gps_available = 0, scan_lat/lng = NULL
gps_accuracy_m,          (scan proceeds normally — never blocks
gps_available = 1         the visitor, per the brief)
   │                        │
   └───────────┬────────────┘
               ▼
   Insert tree_scans row, copying the tree's CURRENT
   zone_id/latitude/longitude into registered_zone_id/
   registered_lat/registered_lng at insert time
               ▼
   Row is now immutable history — a later tree move
   updates trees.zone_id/lat/lng but never this row
```

This lets an admin later compare "where the tree was registered when
scanned" vs. "where the visitor's device actually was" per scan — exactly
the comparison the brief's example illustrates — while keeping
`tree_scans` free to `COUNT`/`GROUP BY` for the existing dashboard metrics
(`visitor-identity.md`) unchanged.

**Zone/location analytics** (per the brief's §8): scans-per-zone,
visitors-per-zone, most-visited trees/zones, and scan activity by
date/time are all derivable from `tree_scans` grouped by
`registered_zone_id` (historically accurate) or `scan_lat/scan_lng`
(actual visitor position, when available) — no new table needed, these are
queries, not stored data. Belongs behind `stats.zone.view`/`trends.view`
(see [`rbac.md`](rbac.md)).
