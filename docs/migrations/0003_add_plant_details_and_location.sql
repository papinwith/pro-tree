-- Adds the plant detail sections shown on the tree info page after scanning
-- (characteristics, properties, benefits, cautions, part-specific uses —
-- each trilingual TH/EN/ZH like name/description already were), plus
-- optional latitude/longitude so admins can record where a plant currently
-- stands and update it if the plant is physically moved (the classification_id
-- / QR code stays the same — only the location metadata changes).
-- Run against an existing tree_qr_system database that predates these
-- columns (a fresh install via docs/install.sql already includes them).
USE tree_qr_system;

ALTER TABLE trees
  ADD COLUMN characteristics    TEXT NULL AFTER description_zh,
  ADD COLUMN characteristics_en TEXT NULL AFTER characteristics,
  ADD COLUMN characteristics_zh TEXT NULL AFTER characteristics_en,
  ADD COLUMN properties         TEXT NULL AFTER characteristics_zh,
  ADD COLUMN properties_en      TEXT NULL AFTER properties,
  ADD COLUMN properties_zh      TEXT NULL AFTER properties_en,
  ADD COLUMN benefits           TEXT NULL AFTER properties_zh,
  ADD COLUMN benefits_en        TEXT NULL AFTER benefits,
  ADD COLUMN benefits_zh        TEXT NULL AFTER benefits_en,
  ADD COLUMN cautions           TEXT NULL AFTER benefits_zh,
  ADD COLUMN cautions_en        TEXT NULL AFTER cautions,
  ADD COLUMN cautions_zh        TEXT NULL AFTER cautions_en,
  ADD COLUMN part_uses          TEXT NULL AFTER cautions_zh,
  ADD COLUMN part_uses_en       TEXT NULL AFTER part_uses,
  ADD COLUMN part_uses_zh       TEXT NULL AFTER part_uses_en,
  ADD COLUMN latitude           DECIMAL(10,7) NULL AFTER part_uses_zh,
  ADD COLUMN longitude          DECIMAL(10,7) NULL AFTER latitude,
  ADD COLUMN location_updated_at DATETIME NULL AFTER longitude;
