-- PDPA-conscious fields on the existing tree_interests table: consent must
-- be recorded whenever contact info is captured, and interest records get
-- an automatic purge date rather than being kept indefinitely. session_hash
-- lets analytics dedupe/aggregate without storing a raw IP as identity.
--
-- Run against an existing tree_qr_system database that predates these
-- columns (a fresh install via docs/install.sql already includes them).
USE tree_qr_system;

ALTER TABLE tree_interests
  ADD COLUMN consent_at   DATETIME NULL AFTER lead_updated_at,
  ADD COLUMN purge_after  DATE NULL AFTER consent_at,
  ADD COLUMN session_hash CHAR(64) NULL AFTER purge_after; -- SHA-256(ip+user_agent+daily_salt), never a raw IP
