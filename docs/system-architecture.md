# System Architecture

Design only — no application code yet. High-level structure for the
expanded system: tree management, visitor/scan tracking with GPS scan
location, multilingual content with AI-assisted translation, an interactive
map, and role-based access control. See the companion docs linked
throughout for the detail behind each subsystem.

## 1. Subsystems

| # | Subsystem | Covered in |
|---|---|---|
| 1 | Public Tree Information Website | [`user-flow.md`](user-flow.md), [`page-flow.md`](page-flow.md) |
| 2 | Programmer Management System | [`rbac.md`](rbac.md) |
| 3 | Executive Dashboard | [`rbac.md`](rbac.md) |
| 4 | Tree Admin Management System | [`rbac.md`](rbac.md), [`tree-identity-and-relationships.md`](tree-identity-and-relationships.md) |
| 5 | Tree QR Code System | [`tree-identity-and-relationships.md`](tree-identity-and-relationships.md) §4 |
| 6 | Visitor & Scan Tracking System | [`visitor-identity.md`](visitor-identity.md) |
| 7 | Scan Location Tracking System | [`tree-identity-and-relationships.md`](tree-identity-and-relationships.md) §6 |
| 8 | Map & Zone Management System | [`map-system.md`](map-system.md) |
| 9 | Multilingual Content System | [`multilingual-and-ai-translation.md`](multilingual-and-ai-translation.md) |
| 10 | AI Translation System (Google Gemini) | [`multilingual-and-ai-translation.md`](multilingual-and-ai-translation.md) |

All ten are one PHP + MySQL application, not separate services — "system"
here means a functional area, not a deployable unit. Nothing in this design
requires microservices; a single codebase with clear module boundaries
(§3) is the right scale for this project.

## 2. Request-Level Architecture

```text
                        ┌─────────────────────┐
                        │   Apache (XAMPP)     │
                        └──────────┬───────────┘
              ┌─────────────────────┼─────────────────────┐
              ▼                     ▼                     ▼
        public/*.php          admin/*.php             api/*.php
      (no auth — visitor    (admin session +      (JSON — public read
       cookie identity       role permission        endpoints, and
       only, see             check on every         session/role-gated
       visitor-identity.md)  request, see            staff endpoints,
                              rbac.md)                see rbac.md §7)
              │                     │                     │
              └─────────────────────┼─────────────────────┘
                                    ▼
                    includes/functions.php, includes/auth.php
                    (shared PDO-based business logic — the
                     single place DB access happens; controllers
                     never build SQL themselves)
                                    │
                                    ▼
                              MySQL (PDO, prepared statements only)
```

No framework, no ORM — this matches the existing codebase and keeps the
project inside its stated PHP/MySQL constraint. The one architectural rule
that matters most: **`public/`, `admin/`, and `api/` are the only three
entry-point directories**, and every file in `admin/`/`api/` starts with
the auth+permission check from [`rbac.md`](rbac.md) §7 before doing
anything else. There is no shared "God" controller — each script is small
and single-purpose, following the existing `tree_form.php`-style pattern.

## 3. Recommended PHP Project Folder Structure

Extends the current layout additively — nothing existing moves.

```text
pro tree/
├── config/
│   ├── config.php          # existing — add GEMINI_API_KEY, GEMINI_MODEL (env-based, never committed)
│   └── db.php
├── includes/
│   ├── auth.php            # requireAdmin(), requirePermission() — see rbac.md
│   ├── functions.php       # existing shared helpers
│   ├── lang.php            # existing i18n helpers
│   ├── translation.php     # NEW — Gemini API client + translation_drafts CRUD (server-only)
│   ├── geo.php             # NEW — haversine/point-in-polygon helpers for scan-location + map (see map-system.md)
│   └── vendor/
├── public/                 # visitor-facing, no auth — unchanged in shape
│   ├── index.php
│   ├── tree.php
│   ├── interest.php
│   └── map.php             # NEW — interactive public map page (see map-system.md)
├── admin/                  # session + permission gated — unchanged in shape, new files added
│   ├── tree_form.php
│   ├── tree_relationships.php   # NEW — prev/next rewiring UI (see tree-identity-and-relationships.md)
│   ├── origins.php / origin_form.php   # NEW
│   ├── translations.php         # NEW — review/approve AI translation drafts
│   ├── map_admin.php            # NEW — zone boundary + tree marker editor
│   ├── users.php                # NEW — admin accounts + role assignment (Programmer only)
│   └── ... (existing files)
├── api/                     # NEW — JSON endpoints, see rbac.md §7 for the enforcement pattern
│   ├── bootstrap.php
│   ├── public/               # no auth: tree lookup, scan logging, interest submit
│   └── staff/                 # session + permission gated
├── storage/                  # NEW — kept outside public/ webroot
│   └── translation_cache/     # optional: cache Gemini responses to control API cost
└── docs/
```

## 4. Scalability Notes

The current scale (pilot: low hundreds of trees, XAMPP/single-VPS
deployment) does not need caching layers, queues, or horizontal scaling.
Design choices that keep the door open without over-building now:

- **Read-heavy public endpoints** (`tree.php`, `api/public/*`) are single
  indexed-JOIN queries — cacheable behind `Cache-Control` headers later
  without a code change.
- **Gemini calls are synchronous but isolated** to `includes/translation.php`
  — if translation volume ever grows, this is the one place to add a job
  queue without touching the rest of the app.
- **GPS/scan-location writes are append-only** (`tree_scans`), same
  pattern as today's scan logging — no lock contention, scales the same
  way the existing scan system already does.
- **New tables are additive, not restructuring** — every table in this
  design set (see [`database.md`](database.md) §10) can be added via
  `ALTER`/`CREATE` migrations without touching existing data, consistent
  with how this project has evolved so far (`docs/migrations/0001`–`0012`).

## 5. Security Considerations

Consolidates the security-relevant points spread across the other docs, so
there's one place to check before implementation:

| Concern | Handling | Detail |
|---|---|---|
| Admin/role access control | Deny-by-default RBAC, checked server-side on every request, never just hidden in the UI | [`rbac.md`](rbac.md) §7–8 |
| Gemini API key | Server-only (`includes/translation.php`), never sent to the browser; `gemini.config.manage` permission restricted to Programmer | [`multilingual-and-ai-translation.md`](multilingual-and-ai-translation.md) §5 |
| Visitor privacy / GPS | Location permission is opt-in at the browser level; a denial must not block the scan; only coarse, purpose-limited data is stored | [`tree-identity-and-relationships.md`](tree-identity-and-relationships.md) §6 |
| Interest emails (PDPA-leaning) | Consent implied by explicit form submission; visible only to roles with `interest.view` | [`rbac.md`](rbac.md) §3–4 |
| SQL injection | PDO prepared statements only, no exceptions — already the existing convention in `functions.php` | existing code |
| CSRF | Existing admin forms have no CSRF token today (noted as a gap by prior research) — recommend adding a per-session token check on all state-changing `admin/*.php`/`api/staff/*.php` POSTs as part of this build, not deferred | new work item |
| Session fixation | Already handled — `session_regenerate_id(true)` on login (`includes/auth.php`) | existing code |
| Direct URL/endpoint access | Every admin/API script re-checks permissions itself; nothing is "secure" purely because no link points to it | [`rbac.md`](rbac.md) §8 |
