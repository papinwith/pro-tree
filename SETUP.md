# Setup

## Requirements
- PHP 8.0+ with `pdo_mysql` extension
- MySQL 8.0+

## 1. Create the database

One file, one command — creates all 12 tables and seeds an admin login plus
a full demo dataset (10 categories, 10 zones, 27 species, 103 trees, every
`plant_code` already computed, no duplicates):

```bash
mysql -u root -p --default-character-set=utf8mb4 < docs/install.sql
```

Seed admin login: **admin** / **ChangeMe123!** — change this immediately in production
(generate a new hash: `php -r "echo password_hash('YourPassword', PASSWORD_DEFAULT);"`
and update the `admins` row).

`docs/install.sql` is the single source of truth for a fresh install —
structure and data both live in this one file (previously split across
`schema.sql` + `seed.sql`, and later a separate `seed_demo_100.sql` for a
bigger dataset; all merged in, so there's no longer a choice of which file
to run). `docs/migrations/` still holds the migration-by-migration history
for upgrading a database that predates `install.sql`.

## 2. Configure environment

Copy/adjust `config/config.php`, or set environment variables before starting PHP:

```bash
export DB_HOST=127.0.0.1
export DB_NAME=tree_qr_system
export DB_USER=root
export DB_PASS=yourpassword
export APP_BASE_URL=http://localhost:8000
```

## 3. Run locally

```bash
php -S localhost:8000 -t public
```

- Visitor pages: `http://localhost:8000/tree.php?id=1`
- Admin panel: `http://localhost:8000/../admin/login.php`
  (when using the built-in server, run it from the project root instead so both
  `public/` and `admin/` are reachable, e.g. `php -S localhost:8000` from the
  project root, then visit `http://localhost:8000/public/tree.php?id=1` and
  `http://localhost:8000/admin/login.php`)

## 4. Apache/production

Point the document root at `public/` for visitor-facing pretty URLs
(`.htaccess` rewrites `/tree/{id}` → `tree.php?id={id}`), and expose `admin/`
either as a subdirectory or a separate vhost — it's intentionally decoupled
from the public visitor code path.

## 5. Generating QR codes

Each tree's public URL is `{APP_BASE_URL}/tree.php?id={tree_id}` (or `/tree/{id}`
with the rewrite rule). Feed that URL into any QR code generator library/service
per tree once trees exist in the admin panel.

## 6. Running the e2e tests

Playwright smoke tests (`tests/e2e/full.spec.js`) exercise the public site,
admin login, CSRF protection, and per-role permission gating against a
**live, running instance** of the app (not a mock server) — install the app
per steps 1–4 above first, then:

```bash
npm install
npx playwright install chromium   # first run only
npm run test:e2e
```

Points at `http://localhost/tree-siam-main` by default; override with
`APP_BASE_URL_ROOT` if your app lives elsewhere. Uses the seed `admin` /
`ChangeMe123!` login by default — override with `TEST_ADMIN_USER` /
`TEST_ADMIN_PASSWORD` if you've changed it (you should have, per step 1).
The executive/tree_admin role tests are skipped unless you set
`TEST_EXEC_USER`/`TEST_EXEC_PASSWORD` and/or `TEST_TREE_ADMIN_USER`/
`TEST_TREE_ADMIN_PASSWORD` — those roles aren't part of the standard seed,
so create them via the admin panel first if you want that coverage.
