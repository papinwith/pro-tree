-- Adds staff/sales roles to the existing admin login (no parallel `users`
-- table — reuses includes/auth.php's session mechanism as-is), and a
-- publish gate on trees so field staff can survey a plant before it's
-- shown on the public page.
--
-- Run against an existing tree_qr_system database that predates these
-- columns (a fresh install via docs/install.sql already includes them).
USE tree_qr_system;

ALTER TABLE admins
  ADD COLUMN role ENUM('admin','staff','sales') NOT NULL DEFAULT 'admin' AFTER password_hash;

-- Every existing tree stays publicly visible — 'published' is the default,
-- not 'draft', so this migration is a no-op for current site behavior.
ALTER TABLE trees
  ADD COLUMN data_status ENUM('draft','verified','published') NOT NULL DEFAULT 'published' AFTER status;
