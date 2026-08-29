-- Adds a 15-digit human-readable "plant code" that combines category,
-- species, zone, garden area, and a sequence number:
--
--   ประเภทพืช(3) / รหัสชนิดพืช(3) / โซน(3) / พื้นที่ในสวน(2) / ลำดับ(4)
--   e.g. 101 / 001 / 001 / 01 / 0001
--
-- Unlike the internal trees.id (which stays fixed for the plant's whole
-- life so links never break), this code is a printed/display label that
-- DELIBERATELY changes when a plant moves to a new zone or garden area —
-- by explicit decision, admins are expected to reprint the QR/plant tag
-- when that happens. trees.plant_code_updated_at is set whenever it
-- changes, so the admin UI can flag "needs reprint".
--
-- Run against an existing tree_qr_system database that predates these
-- columns (a fresh install via docs/install.sql already includes them).
USE tree_qr_system;

ALTER TABLE species
  ADD COLUMN category_code CHAR(3) NULL AFTER classification_id,
  ADD COLUMN species_code  CHAR(3) NULL AFTER category_code,
  ADD CONSTRAINT fk_species_category FOREIGN KEY (category_code) REFERENCES categories(code),
  ADD UNIQUE KEY uq_species_category_species (category_code, species_code);

ALTER TABLE zones
  ADD COLUMN zone_number CHAR(3) NULL UNIQUE AFTER zone_code;

ALTER TABLE trees
  ADD COLUMN area_code CHAR(2) NOT NULL DEFAULT '01' AFTER zone_id,
  ADD COLUMN plant_code CHAR(15) NULL UNIQUE AFTER area_code,
  ADD COLUMN plant_code_updated_at DATETIME NULL AFTER plant_code;

-- Best-effort backfill for existing rows: derive category_code from the
-- first 3 digits of each species' existing classification_id (falls back
-- to the first known category if classification_id was never set), assign
-- a fresh per-category species_code sequence, and number zones/areas/
-- sequences in id order. Review the result in the admin Species/Zones
-- pages afterward — this is a starting point, not a guarantee the numbers
-- match any pre-existing paper records.
SET @cat_fallback = (SELECT code FROM categories ORDER BY code ASC LIMIT 1);

UPDATE species
SET category_code = COALESCE(
  (SELECT code FROM categories WHERE code = LEFT(species.classification_id, 3)),
  @cat_fallback
)
WHERE category_code IS NULL;

-- Per-category sequential species_code (001, 002, ... within each category_code).
SET @cc = '';
SET @seq = 0;
UPDATE species
JOIN (
  SELECT id,
         @seq := IF(@cc = category_code, @seq + 1, 1) AS rn,
         @cc := category_code AS cc
  FROM species
  ORDER BY category_code, id
) ranked ON ranked.id = species.id
SET species.species_code = LPAD(ranked.rn, 3, '0')
WHERE species.species_code IS NULL;

SET @zn = 0;
UPDATE zones
JOIN (
  SELECT id, @zn := @zn + 1 AS rn FROM zones ORDER BY id
) ranked ON ranked.id = zones.id
SET zones.zone_number = LPAD(ranked.rn, 3, '0')
WHERE zones.zone_number IS NULL;

ALTER TABLE species MODIFY COLUMN category_code CHAR(3) NOT NULL;
ALTER TABLE species MODIFY COLUMN species_code CHAR(3) NOT NULL;
ALTER TABLE zones MODIFY COLUMN zone_number CHAR(3) NOT NULL;

-- plant_code itself is computed and backfilled in application code
-- (recomputeTreePlantCode() in includes/functions.php) since its sequence
-- component depends on insertion order within each category+species+zone+
-- area combination — run that once per existing tree after this migration.
