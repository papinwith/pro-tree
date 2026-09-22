-- Introduces `zones` and `species` as their own master-data tables, matching
-- the pilot project proposal's data model (Zone / Species / Plant Asset are
-- separate entities). Previously `trees` held name/description/characteristics/
-- etc. directly per individual plant, which meant the same species' content
-- had to be re-typed for every individual — and a plant's classification_id
-- lived on the individual rather than the species it belongs to.
--
-- After this migration:
--   - `zones`   holds physical-area info (name, description).
--   - `species` holds everything that's shared by every individual of that
--     species: name/description/care instructions/characteristics/
--     properties/benefits/cautions/part_uses (TH default + EN/ZH), and the
--     10-digit classification_id.
--   - `trees` (the individual Plant Asset / "Tree ID") keeps only what's
--     specific to that one physical specimen: species_id, zone_id, an
--     optional staff-facing label, status, image, location, QR code, etc.
--
-- This migration best-effort-backfills one species per existing tree row
-- (preserving all of that tree's old content), assigns every tree to a
-- placeholder "UNZONED" zone, and drops the now-species-level columns from
-- `trees`. Review the generated species afterward in the admin Species page
-- and merge duplicates (multiple trees of the same species) by hand — the
-- migration has no way to know which existing trees were the same species.
--
-- Run against an existing tree_qr_system database that predates zones/species
-- (a fresh install via docs/install.sql already includes them).
USE tree_qr_system;

CREATE TABLE IF NOT EXISTS zones (
    id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    zone_code    VARCHAR(50) NOT NULL UNIQUE,
    name         VARCHAR(150) NOT NULL, -- Thai (default)
    name_en      VARCHAR(150) NULL,
    name_zh      VARCHAR(150) NULL,
    description    TEXT NULL,
    description_en TEXT NULL,
    description_zh TEXT NULL,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS species (
    id                 BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    classification_id  CHAR(10) NULL UNIQUE,
    name               VARCHAR(150) NOT NULL, -- Thai (default)
    name_en            VARCHAR(150) NULL,
    name_zh            VARCHAR(150) NULL,
    name_common        VARCHAR(150) NULL, -- ชื่อสามัญ
    name_scientific    VARCHAR(150) NULL, -- ชื่อวิทยาศาสตร์ (Latin binomial, not localized)
    description        TEXT NULL,
    description_en     TEXT NULL,
    description_zh     TEXT NULL,
    care_instructions    TEXT NULL, -- วิธีดูแล
    care_instructions_en TEXT NULL,
    care_instructions_zh TEXT NULL,
    characteristics     TEXT NULL,
    characteristics_en  TEXT NULL,
    characteristics_zh  TEXT NULL,
    properties          TEXT NULL,
    properties_en       TEXT NULL,
    properties_zh       TEXT NULL,
    benefits            TEXT NULL,
    benefits_en         TEXT NULL,
    benefits_zh         TEXT NULL,
    cautions            TEXT NULL,
    cautions_en         TEXT NULL,
    cautions_zh         TEXT NULL,
    part_uses           TEXT NULL,
    part_uses_en        TEXT NULL,
    part_uses_zh        TEXT NULL,
    created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

INSERT IGNORE INTO zones (zone_code, name, name_en, name_zh) VALUES
  ('UNZONED', 'ยังไม่ระบุโซน', 'Unzoned (assign a real zone)', '未分区');

-- One species per existing tree, tagged with the source tree's id so the
-- next step can match them back up deterministically.
ALTER TABLE species ADD COLUMN _legacy_tree_id BIGINT UNSIGNED NULL;

INSERT INTO species (
    classification_id, name, name_en, name_zh, description, description_en, description_zh,
    characteristics, characteristics_en, characteristics_zh, properties, properties_en, properties_zh,
    benefits, benefits_en, benefits_zh, cautions, cautions_en, cautions_zh,
    part_uses, part_uses_en, part_uses_zh, _legacy_tree_id
)
SELECT
    classification_id, name, name_en, name_zh, description, description_en, description_zh,
    characteristics, characteristics_en, characteristics_zh, properties, properties_en, properties_zh,
    benefits, benefits_en, benefits_zh, cautions, cautions_en, cautions_zh,
    part_uses, part_uses_en, part_uses_zh, id
FROM trees;

ALTER TABLE trees
  ADD COLUMN species_id BIGINT UNSIGNED NULL AFTER slug,
  ADD COLUMN zone_id    BIGINT UNSIGNED NULL AFTER species_id,
  ADD COLUMN label      VARCHAR(150) NULL AFTER zone_id, -- optional staff-facing nickname for this individual
  ADD COLUMN status     ENUM('healthy','needs_attention','removed') NOT NULL DEFAULT 'healthy' AFTER label;

UPDATE trees t JOIN species s ON s._legacy_tree_id = t.id SET t.species_id = s.id;
UPDATE trees SET zone_id = (SELECT id FROM zones WHERE zone_code = 'UNZONED') WHERE zone_id IS NULL;

ALTER TABLE species DROP COLUMN _legacy_tree_id;

ALTER TABLE trees
  MODIFY COLUMN species_id BIGINT UNSIGNED NOT NULL,
  MODIFY COLUMN zone_id    BIGINT UNSIGNED NOT NULL,
  ADD CONSTRAINT fk_trees_species FOREIGN KEY (species_id) REFERENCES species(id),
  ADD CONSTRAINT fk_trees_zone    FOREIGN KEY (zone_id)    REFERENCES zones(id);

ALTER TABLE trees
  DROP COLUMN name, DROP COLUMN name_en, DROP COLUMN name_zh,
  DROP COLUMN description, DROP COLUMN description_en, DROP COLUMN description_zh,
  DROP COLUMN characteristics, DROP COLUMN characteristics_en, DROP COLUMN characteristics_zh,
  DROP COLUMN properties, DROP COLUMN properties_en, DROP COLUMN properties_zh,
  DROP COLUMN benefits, DROP COLUMN benefits_en, DROP COLUMN benefits_zh,
  DROP COLUMN cautions, DROP COLUMN cautions_en, DROP COLUMN cautions_zh,
  DROP COLUMN part_uses, DROP COLUMN part_uses_en, DROP COLUMN part_uses_zh,
  DROP COLUMN classification_id;
