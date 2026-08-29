# Tree QR Code Information System

A web-based system where visitors scan a QR Code on a tree and land on that tree's public information page — no login required. The system tracks scans/visitors (without relying on IP alone), collects "interested" emails, supports Previous/Next tree browsing, and shows a clickable map banner.

## Contents

- [`SETUP.md`](SETUP.md) — how to install the database and run the app locally

- [`docs/architecture.md`](docs/architecture.md) — system architecture, components, request flow
- [`docs/system-architecture.md`](docs/system-architecture.md) — expanded subsystem overview, folder structure, scalability & security notes
- [`docs/database.md`](docs/database.md) — full schema design + rationale
- [`docs/install.sql`](docs/install.sql) — **run this one** — full schema + full demo dataset (103 trees) in a single file
- [`docs/migrations/`](docs/migrations) — historical upgrade scripts for pre-existing databases
- [`docs/page-flow.md`](docs/page-flow.md) — page/route map and page structure
- [`docs/user-flow.md`](docs/user-flow.md) — visitor and admin user flows (with diagrams)
- [`docs/rbac.md`](docs/rbac.md) — role hierarchy, permission taxonomy, and access-control design (Programmer / Executive / Tree Admin / Visitor)
- [`docs/tree-identity-and-relationships.md`](docs/tree-identity-and-relationships.md) — Tree ID vs. location vs. zone vs. relationship vs. scan-location, QR flow, GPS scan tracking
- [`docs/multilingual-and-ai-translation.md`](docs/multilingual-and-ai-translation.md) — TH/EN/ZH content model + Google Gemini AI translation review flow
- [`docs/map-system.md`](docs/map-system.md) — map banner (existing) vs. interactive zone/tree map (new)
- [`docs/visitor-identity.md`](docs/visitor-identity.md) — how visitor identity, scan dedup, and repeat-scan detection works

## Stack

- **Backend**: plain PHP (no framework)
- **Database**: MySQL
- **Frontend**: server-rendered PHP views + a small amount of vanilla JS
- **Auth**: none for visitors (cookie-based identity only). Session-based login for a separate admin panel.

## Project Layout

```text
config/     DB + app configuration
includes/   shared PHP helpers (visitor identity, scan logging, admin auth)
public/     visitor-facing pages — point your web server document root here
  tree.php       tree information page
  interest.php   "I'm Interested" email submission handler
  index.php      redirects to the first active tree
admin/      admin-only pages (session auth), kept separate from public/
  dashboard.php / tree_form.php   manage individual plant assets ("Tree ID")
  zones.php / zone_form.php       manage zones (physical areas)
  species.php / species_form.php  manage species (shared name/care/content)
docs/       design docs, install.sql (run this), migrations/
```

See [`SETUP.md`](SETUP.md) to get it running.

user: 
PASS:ChangeMe123!


