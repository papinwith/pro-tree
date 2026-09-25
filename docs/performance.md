# Why pages were slow, and what fixed it

**Cause: the database is far from the app.** Every database statement is a
network round trip (~100 ms from Thailand/Railway to Supabase in Tokyo), and
every request opens a fresh connection (~0.7 s: TCP + TLS + auth). Pages were
slow because they ran *dozens* of statements one after another:

| Page | Statements before | After | Time before -> after (measured) |
|---|---|---|---|
| dashboard | ~87 | ~5 | 8.1 s -> 1.4 s |
| species list | ~74 | ~13 | 6.5 s -> 1.0 s |
| public tree page | ~50 | ~15 | 5.9 s -> 2.1 s |
| QR list | ~38 | ~6 | 4.3 s -> 1.3 s |
| settings | ~24 | ~8 | 3.5 s -> 0.9 s |
| login form (GET) | ~5 | 0 | 0.9 s -> 0.0 s |
| login (POST) | ~6 | ~2 | 1.5 s -> 1.1 s |

What was wasting round trips (found by diffing `pg_stat_statements` around a
page load):

1. **`can()` ran one query per permission check** — the dashboard asked "does
   this role have X?" 15 times. Now one query per request
   (`rolePermissionKeys()` in `includes/auth.php`), still fresh on every request.
2. **`getSetting()` ran one query per key.** Now the small settings table is
   read once per request (`includes/functions.php`).
3. **Each prepared statement cost 3 round trips** (prepare, execute,
   `DEALLOCATE`). `PDO::PGSQL_ATTR_DISABLE_PREPARES` (`config/db.php`) sends
   query + parameters in one call — parameters are still bound server-side,
   not interpolated, so it is just as safe.
4. The login form connected to the database only to draw an empty form.
5. Static files (CSS/JS/images) are now cacheable for a while and text is
   gzip-compressed (`docker/vhost.conf.template`); QR images are never cached.

## What is left, and the real fix

Each request still pays the connection (~0.7 s) plus ~0.1 s per remaining
statement, because app and database are ~100 ms apart. **Putting them in the
same region is the biggest remaining win** (~1-2 ms round trips): e.g. create
the Supabase project in Singapore and run the Railway service in Singapore, or
move the Railway service to the region closest to the existing database.
