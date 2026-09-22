-- Adds storage for the server-generated QR code image path.
-- Run against an existing tree_qr_system database that predates this column
-- (a fresh install via docs/install.sql already includes it).
USE tree_qr_system;

ALTER TABLE trees
  ADD COLUMN qr_code_path VARCHAR(255) NULL AFTER map_url;
