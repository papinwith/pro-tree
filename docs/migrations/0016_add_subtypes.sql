-- Adds a "ชนิด" (subtype) level between category and species, so the
-- admin-facing hierarchy becomes ประเภท (category) -> ชนิด (subtype) ->
-- ชื่อต้นไม้ (species). A subtype belongs to exactly one category (e.g.
-- "ไม้ผล" under "ไม้ยืนต้น"); a species belongs to exactly one subtype,
-- from which its category_code is derived — species.category_code is kept
-- (not dropped) because it still feeds trees.plant_code's 15-digit format,
-- which intentionally does NOT change to include the subtype (see
-- docs/rbac.md-adjacent discussion — subtype is organizational only).
--
-- Run against an existing tree_qr_system database that predates this
-- change (a fresh install via docs/install.sql already includes it).
USE tree_qr_system;

CREATE TABLE subtypes (
    id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    category_code CHAR(3) NOT NULL,
    name_th       VARCHAR(100) NOT NULL,
    name_en       VARCHAR(100) NULL,
    name_zh       VARCHAR(100) NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_subtypes_category FOREIGN KEY (category_code) REFERENCES categories(code)
) ENGINE=InnoDB;

ALTER TABLE species
  ADD COLUMN subtype_id BIGINT UNSIGNED NULL AFTER category_code,
  ADD CONSTRAINT fk_species_subtype FOREIGN KEY (subtype_id) REFERENCES subtypes(id);

-- permission reused from categories (same "manage the plant taxonomy"
-- responsibility) — see admin/subtypes.php / subtype_form.php, gated by
-- requirePermission('category.manage') same as categories.php.
