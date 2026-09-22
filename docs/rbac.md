# Roles & Permissions (RBAC)

**Implemented** — `docs/migrations/0014_add_roles_permissions_rbac.sql`
(also folded into `docs/install.sql` for fresh installs), `includes/auth.php`
(`can()`/`requirePermission()`), and every `admin/*.php` script gated
accordingly. This doc is the design record + reference; see [§12](#12-open-questions-to-confirm-before-building)
for items that were resolved with a reasonable default rather than an
explicit confirmation.

## 1. Role Hierarchy

```text
                    Programmer
                (full system access)
                         │
            ┌────────────┴────────────┐
            │                         │
        Executive                 Tree Admin
   (dashboard / reports,      (tree data management,
      read-only)                full CRUD on its scope)


                    Public User
              (no login — QR scan only)
```

**This is an org-authority diagram, not a permission-inheritance chain.**
Executive and Tree Admin are siblings with **disjoint, non-overlapping**
capability sets — Executive cannot create/edit anything, Tree Admin cannot
see cross-tree analytics dashboards or manage other admin accounts. Neither
role's permission set is a subset of the other's. Only Programmer's set is
a superset of both. A naive "role level number ≥ N" check would be wrong
here (it would wrongly imply Executive > Tree Admin or vice versa) — the
enforcement model in §8 checks discrete permissions, not a rank.

## 2. Roles at a Glance

| Role | Login required | Purpose |
|---|---|---|
| **Programmer** | Yes (admin session) | Full system owner — everything below, plus admin/role management and system settings |
| **Executive** | Yes (admin session) | Management visibility — dashboards, statistics, reports. No write access anywhere |
| **Tree Admin** | Yes (admin session) | Day-to-day plant-asset data management — trees, species, zones, categories, origins, QR codes |
| **Public User (Visitor)** | **No** | Scans a QR code, views one tree's public page, optionally leaves an email. Never touches admin data |

## 3. Permission Taxonomy

Atomic, independently-grantable permissions, grouped by module. Each has a
stable `permission_key` used in code and in the `role_permissions` table
(§6) — this is the unit everything else is built from.

| Module | Permission key | Meaning |
|---|---|---|
| **Tree data** | `tree.view` | View tree list/detail in admin |
| | `tree.create` | Add a new tree |
| | `tree.update` | Edit an existing tree |
| | `tree.status.manage` | Toggle active/inactive (see §11 — prefer this over delete) |
| | `tree.image.manage` | Upload/replace tree photos |
| | `tree.order.manage` | Set/reorder `display_order` (Prev/Next sequencing) |
| | `tree.map.manage` | Set per-tree map image/URL override |
| **Master data** | `category.manage` | CRUD tree categories |
| | `species.manage` | CRUD plant species (name, care, characteristics, etc.) |
| | `zone.manage` | CRUD zones/locations |
| | `origin.manage` | CRUD plant origins/provenance — **new concept, see §12** |
| | `plan.manage` | CRUD planting plans — future/intended plantings recorded before a tree row exists |
| | `tree.relationship.manage` | Set/rewire a tree's Previous/Next neighbor links (`tree_relationships` — see [`tree-identity-and-relationships.md`](tree-identity-and-relationships.md) §4) |
| | `tree.location.manage` | Move a tree to a new location/zone/origin (writes `plant_location_history`, triggers `plant_code` recompute — see [`tree-identity-and-relationships.md`](tree-identity-and-relationships.md) §5) |
| **QR** | `qrcode.manage` | Generate/regenerate QR codes, view/download QR sheets |
| **Translation** | `translation.request` | Trigger an AI (Gemini) translation for a species field |
| | `translation.review` | Approve/edit/reject a pending AI translation draft |
| **Admin accounts** | `admin.manage` | Create/edit/deactivate admin accounts; assign roles |
| | `gemini.config.manage` | Configure the Gemini API key/model — deliberately separate from `settings.manage` per the spec's explicit "Tree Admin must not modify Gemini API credentials" |
| **Visitor data** | `scan.view` | View scan records/history (per-tree and aggregate) |
| | `visitor.view` | View visitor records (visitor ID, IP, UA, first/last seen) |
| | `interest.view` | View interested-visitor emails / lead list |
| | `interest.manage` | Update lead status / follow-up on an interest record |
| **Reporting** | `dashboard.view` | Access the Executive-style dashboard |
| | `stats.tree.view` | Per-tree statistics |
| | `stats.category.view` | Per-category statistics |
| | `stats.zone.view` | Per-zone statistics |
| | `trends.view` | Visitor/scan trend charts over time |
| | `revenue.view` | Sales/stock pricing info, if enabled (see §12) |
| | `reports.export` | Export CSV/report downloads |
| **System** | `map.settings.manage` | Global default map image/URL (distinct from `tree.map.manage`, which is per-tree) |
| | `settings.manage` | System-level settings (site config, security-relevant options) |

Deny-by-default: a permission not explicitly granted to a role is denied.
There is no implicit "everyone gets read access" — even `tree.view` must be
granted.

## 4. Role → Permission Matrix

✓ = granted · — = not granted

| Permission | Programmer | Executive | Tree Admin |
|---|:---:|:---:|:---:|
| `tree.view` | ✓ | — | ✓ |
| `tree.create` | ✓ | — | ✓ |
| `tree.update` | ✓ | — | ✓ |
| `tree.status.manage` | ✓ | — | ✓ |
| `tree.image.manage` | ✓ | — | ✓ |
| `tree.order.manage` | ✓ | — | ✓ |
| `tree.map.manage` | ✓ | — | ✓ |
| `tree.relationship.manage` | ✓ | — | ✓ |
| `tree.location.manage` | ✓ | — | ✓ |
| `category.manage` | ✓ | — | ✓ |
| `species.manage` | ✓ | — | ✓ |
| `zone.manage` | ✓ | — | ✓ |
| `origin.manage` | ✓ | — | ✓ |
| `plan.manage` | ✓ | — | ✓ |
| `qrcode.manage` | ✓ | — | ✓ |
| `translation.request` | ✓ | — | ✓ |
| `translation.review` | ✓ | — | ✓ |
| `admin.manage` | ✓ | — | — |
| `gemini.config.manage` | ✓ | — | — |
| `scan.view` | ✓ | ✓ | ✓ *(own tree scope)* |
| `visitor.view` | ✓ | ✓ | — |
| `interest.view` | ✓ | ✓ *(aggregate — see §12)* | — |
| `interest.manage` | ✓ | — | — |
| `dashboard.view` | ✓ | ✓ | — |
| `stats.tree.view` | ✓ | ✓ | ✓ *(own tree scope)* |
| `stats.category.view` | ✓ | ✓ | — |
| `stats.zone.view` | ✓ | ✓ | — |
| `trends.view` | ✓ | ✓ | — |
| `revenue.view` | ✓ | ✓ | — |
| `reports.export` | ✓ | ✓ | — |
| `map.settings.manage` | ✓ | — | — |
| `settings.manage` | ✓ | — | — |

Notes:
- Tree Admin's `scan.view`/`stats.tree.view` are scoped to the trees it
  manages, not cross-system analytics — that distinction (dashboards vs.
  per-record stats) is what keeps Tree Admin out of `dashboard.view`.
- Tree Admin has **no** access to `interest.view`/`visitor.view` under this
  matrix — the user's spec only lists "view tree scan statistics" for Tree
  Admin, not visitor PII or lead emails. This is a deliberate PDPA-leaning
  default, not an oversight — flagged in §12 in case the client wants it
  loosened.
- `admin.manage`, `map.settings.manage`, `settings.manage` are
  Programmer-exclusive, matching "Tree Admin should NOT have permission to
  manage Programmer/Executive accounts or change system-level settings."

## 5. Public User / Visitor

No role row, no login, no session. Already fully specified by the existing
docs — this design doesn't change that flow, just confirms it satisfies the
spec:

- Identity, dedup, and audit fields: [`visitor-identity.md`](visitor-identity.md)
  (`visitor_uuid` cookie, `visitors` table, IP/UA stored for audit only —
  never identity).
- Full request flow: [`user-flow.md` §1](user-flow.md).
- Scan-history/dedup detection: `SELECT 1 FROM tree_scans WHERE tree_id = ? AND visitor_id = ?`
  (already implemented, see `visitor-identity.md`).

**Why a visitor structurally cannot reach admin data:** the visitor cookie
(`tree_visitor_id`) and the admin session (`tree_admin_sess`) are two
independent, non-overlapping mechanisms — see `includes/auth.php` vs.
`includes/functions.php`'s visitor helpers. There's no code path where a
visitor cookie grants `$_SESSION['admin_id']`, so "admin dashboard,
management dashboard, database, other users' data, scan records, system
settings" are unreachable by construction, not by a permission check that
could be bypassed. `public/*.php` scripts never call `requireAdmin()`, and
`admin/*.php` scripts never read the visitor cookie.

## 6. Data Model

Rejects a hardcoded `ENUM('admin','staff','sales')` on `admins.role` (which
migration `0007_add_roles_and_data_status.sql` added earlier in this
project) in favor of a normalized, data-driven model — **because the spec
explicitly requires adding roles/permissions later without redeploying
code.** An ENUM requires an `ALTER TABLE` + code change per new role; the
tables below only require new rows.

```text
roles                    permissions                role_permissions
─────                    ───────────                ─────────────────
id PK                    id PK                       role_id FK ─┐
role_key UNIQUE           permission_key UNIQUE       permission_id FK ─┤
name_th / name_en                        module      (composite PK)    │
description                              description                  │
is_system (bool)                                                       │
                                                                        │
admins                                                                 │
──────                                                                 │
id PK                                                                  │
username                                                               │
password_hash                                                         │
role_id FK ────────────────────────────────────────────────────────────┘
```

Illustrative schema (design sketch — **not yet migrated**; would replace
`0007`'s `admins.role` ENUM in a future `0013` migration):

```sql
CREATE TABLE roles (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    role_key    VARCHAR(40) NOT NULL UNIQUE,   -- 'programmer', 'executive', 'tree_admin'
    name_th     VARCHAR(100) NOT NULL,
    name_en     VARCHAR(100) NOT NULL,
    description TEXT NULL,
    is_system   TINYINT(1) NOT NULL DEFAULT 0, -- built-in roles can't be deleted via admin UI
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE permissions (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    permission_key  VARCHAR(60) NOT NULL UNIQUE, -- 'tree.create', 'admin.manage', ...
    module          VARCHAR(40) NOT NULL,        -- groups §3's table for admin UI rendering
    description     TEXT NULL
) ENGINE=InnoDB;

CREATE TABLE role_permissions (
    role_id       BIGINT UNSIGNED NOT NULL,
    permission_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (role_id, permission_id),
    CONSTRAINT fk_rp_role       FOREIGN KEY (role_id)       REFERENCES roles(id)       ON DELETE CASCADE,
    CONSTRAINT fk_rp_permission FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
) ENGINE=InnoDB;

ALTER TABLE admins
  ADD COLUMN role_id BIGINT UNSIGNED NULL AFTER password_hash,
  ADD CONSTRAINT fk_admins_role FOREIGN KEY (role_id) REFERENCES roles(id);
-- role_id nullable during migration; backfilled from the old `role` ENUM,
-- then made NOT NULL and the old column dropped once verified.
```

## 7. Backend Enforcement Architecture

**Every check happens server-side, on every request, before any data is
touched.** Frontend button-hiding is UX polish only — it is never the
security boundary, per the spec's explicit requirement.

```text
Request arrives at admin/*.php or api/*.php
        │
        ▼
requireAdmin()          — is there a valid admin session at all?
        │  no → redirect to login / 401 JSON, exit
        ▼ yes
requirePermission('tree.create')   — does this admin's role include this permission?
        │  no → 403 Forbidden (page or JSON envelope), exit
        ▼ yes
... proceed with the actual action ...
```

Pseudocode for the two building-block functions (illustrative — not a full
implementation):

```php
// Loaded once per session at login, cached in $_SESSION to avoid a
// role_permissions JOIN on every single request.
function loadAdminPermissions(PDO $pdo, int $adminId): array {
    // SELECT p.permission_key FROM admins a
    // JOIN role_permissions rp ON rp.role_id = a.role_id
    // JOIN permissions p ON p.id = rp.permission_id
    // WHERE a.id = :adminId
    // → cache as $_SESSION['admin_permissions'] = ['tree.view', 'tree.create', ...]
}

function can(string $permissionKey): bool {
    return in_array($permissionKey, $_SESSION['admin_permissions'] ?? [], true);
}

function requirePermission(string $permissionKey): void {
    requireAdmin(); // existing function in includes/auth.php
    if (!can($permissionKey)) {
        http_response_code(403);
        exit('Forbidden');
    }
}
```

Key properties:
- **Deny-by-default** — an admin with no matching `role_permissions` row
  for a key is refused, not implicitly allowed.
- **Session-cached, not re-derived from client input** — the permission set
  is loaded server-side at login and never trusted from a request
  parameter, cookie value, or hidden form field.
- **Invalidate on role change** — if `admin.manage` changes an account's
  `role_id`, that admin's *next* request must reload permissions (e.g. a
  `permissions_version` counter bumped on role change, checked against a
  cached value in session; mismatch triggers a reload).
- **Same function for the future JSON API** — `api/*.php` endpoints
  (from the separate Udon-integration plan) reuse `requirePermission()`
  exactly as admin pages do, so there's one source of truth, not a
  duplicated check per surface.

## 8. Endpoint → Permission Mapping

Concrete enough to test "can a Tree Admin get in by typing the URL
directly?" Based on the actual files in `admin/` (not the aspirational REST
table in `page-flow.md`, which doesn't match the real file-based routes).

| Script | Required permission |
|---|---|
| `admin/dashboard.php` | `tree.view` |
| `admin/tree_form.php` (create/edit) | `tree.create` *or* `tree.update` depending on whether `?id=` is present |
| `admin/tree_delete.php` | `tree.status.manage` *(should become a deactivate action, not a hard delete — see §11)* |
| `admin/tree_qr.php`, `admin/qr_all.php` | `qrcode.manage` |
| `admin/zones.php`, `admin/zone_form.php`, `admin/zone_delete.php` | `zone.manage` |
| `admin/species.php`, `admin/species_form.php`, `admin/species_delete.php` | `species.manage` |
| `admin/categories.php`, `admin/category_form.php`, `admin/category_delete.php` | `category.manage` |
| *(planned)* `admin/origins.php` / `admin/origin_form.php` | `origin.manage` |
| `admin/plans.php`, `admin/plan_form.php`, `admin/plan_delete.php` | `plan.manage` |
| `admin/observation_add.php`, `admin/maintenance_add.php`, `admin/stock_add.php` + their `*_delete.php` | `tree.update` |
| `admin/interests.php`, `admin/interest_update.php` | `interest.view` (list) / `interest.manage` (status update) |
| `admin/reports.php` | `reports.export` + relevant `stats.*.view` |
| `admin/settings.php` | `settings.manage` (system) / `map.settings.manage` (map fields specifically — may split into two sections gated separately) |
| `admin/backup.php` | `settings.manage` (Programmer-only — full DB export is system-level) |
| *(planned)* `admin/tree_relationships.php` (prev/next rewiring) | `tree.relationship.manage` |
| *(planned)* `admin/map_admin.php` (zone boundaries + marker drag) | `zone.manage` (boundary edits) / `tree.location.manage` (marker drag) — see [`map-system.md`](map-system.md) §3 |
| *(planned)* `admin/translations.php` (AI translation review) | `translation.request` (trigger) / `translation.review` (approve/reject) |
| *(planned)* `admin/users.php` (admin/role management) | `admin.manage` |
| *(planned)* Gemini API key/model config section | `gemini.config.manage` |
| *(planned)* Executive dashboard page | `dashboard.view` |
| *(planned)* `public/map.php` | none — public, no auth (see [`map-system.md`](map-system.md) §2) |

A request to any of these from a session lacking the listed permission
returns 403 regardless of how the URL was reached (typed directly, bookmarked,
or scripted) — the check is identical to the one a rendered link would have
respected.

## 9. Extensibility Walkthrough

Adding a role or permission later requires **no code deployment**, only
data:

1. **New permission** (e.g. a future `market.manage` for a marketplace
   module): insert one row into `permissions`. Wire exactly one
   `requirePermission('market.manage')` call at the top of the new
   script(s) that need it. Existing roles are unaffected until explicitly
   granted.
2. **New role** (e.g. a future `Sales` role — see §12 for why this isn't
   one of the 4 roles yet): insert one row into `roles`, then insert its
   grants into `role_permissions` (e.g. `interest.view`, `interest.manage`,
   `revenue.view`, `stats.tree.view` — no `tree.create`/`species.manage`).
   No PHP changes required; `admin/users.php` can immediately assign the
   new role to an account.
3. **Changing an existing role's grants**: add/remove rows in
   `role_permissions` — e.g. if the client later wants Tree Admin to see
   `interest.view`, that's a single INSERT, not a code change, confirming
   the deny-by-default matrix in §4 is a data question, not a hardcoded one.

## 10. Audit Trail

Every permission-gated write action should log to the `audit_logs` table
(already designed in the earlier schema-integration pass —
`docs/database.md` §10) with `admin_id`, `action`, `entity_type`/`entity_id`,
and before/after JSON. This matters specifically for RBAC: it's how "who
changed this admin's role" or "who deactivated this tree" stays answerable,
which is itself part of the access-control requirement ("must not be able
to access unauthorized functions") — an audit trail is how you detect if
that guarantee is ever violated.

## 11. Prefer Deactivate Over Delete

Per the spec: for tree records, `tree.status.manage` should drive an
active/inactive toggle (`trees.is_active`, which already exists), not a
hard `DELETE`. `admin/tree_delete.php` should be reconsidered as a
deactivate action gated by `tree.status.manage`, reserving actual row
deletion (if ever exposed) for a stricter, Programmer-only, audit-logged
path. Species/zone/category deletion can stay blocked-while-referenced as
today (see `page-flow.md`), which already has similar effect.

## 12. Open Questions to Confirm Before Building

1. ~~**`origin.manage`**~~ — resolved: `origins` is a new managed master
   table (like `categories`), referenced per-tree via `trees.origin_id`
   (per-specimen provenance, not a species trait). Full design in
   [`tree-identity-and-relationships.md`](tree-identity-and-relationships.md) §5.
2. **Executive's "view interested visitors"** — this matrix assumes
   *aggregate counts/stats* for Executive (`interest.view` = read-only
   dashboard number), not the raw email/lead list (that stays
   Programmer-only via the same permission key, scoped by UI). If the
   client actually wants Executive to see raw contact emails, that's a
   one-line change to who gets `interest.view`, but worth confirming given
   PDPA sensitivity noted elsewhere in this project's docs.
3. **`revenue.view`** — "Sales/revenue information if available" suggests
   this may not exist yet. Current schema only has `nursery_stock.price`
   (per-species asking price), not actual transaction/revenue records — is
   this permission for that pricing data, or does it depend on the
   marketplace tables from the separate Udon-integration plan?
4. **Where does "Sales" (from the earlier Udon-integration plan's
   `admin/staff/sales` roles) fit now?** This 4-role spec doesn't mention a
   Sales role. Options: (a) Tree Admin absorbs nursery-stock/interest
   duties, (b) a 5th role is added later via §9's extensibility path once
   the marketplace module is built, (c) Programmer handles it directly for
   now. Doesn't block this design (roles are additive), just flagging so
   the two planning threads don't silently diverge.
5. **Migration sequencing** — this design supersedes `0007`'s
   `admin/staff/sales` ENUM (written before this spec arrived, in the same
   project). Recommend a follow-up migration that adds `roles` /
   `permissions` / `role_permissions`, backfills `admins.role_id` from the
   existing `role` column (`admin`→Programmer, `staff`→Tree Admin, `sales`→
   *unassigned, pending Q4 above*), then drops the old ENUM column — held
   until this design is confirmed, per "design only, no code yet."
