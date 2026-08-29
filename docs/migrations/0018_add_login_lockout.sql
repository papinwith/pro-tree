-- Brute-force protection for admin login: track consecutive failed
-- attempts per account and lock it out temporarily once too many pile up.
-- See attemptAdminLogin() in includes/auth.php.
--
-- Run against an existing tree_qr_system database that predates this
-- change (a fresh install via docs/install.sql already includes it).
USE tree_qr_system;

ALTER TABLE admins
  ADD COLUMN failed_login_attempts INT UNSIGNED NOT NULL DEFAULT 0 AFTER role_id,
  ADD COLUMN locked_until DATETIME NULL AFTER failed_login_attempts;
