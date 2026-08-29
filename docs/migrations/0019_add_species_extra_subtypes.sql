-- Some species genuinely belong to more than one "ชนิด" (subtype) — e.g.
-- both ไม้ผล and ไม้ดอก. species.subtype_id stays the required "primary"
-- subtype (still what plant_code/organization uses); this junction table
-- holds additional subtypes, admin-side only. See admin/species_form.php.
--
-- Run against an existing tree_qr_system database that predates this
-- change (a fresh install via docs/install.sql already includes it).
USE tree_qr_system;

CREATE TABLE species_subtypes (
    species_id BIGINT UNSIGNED NOT NULL,
    subtype_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (species_id, subtype_id),
    CONSTRAINT fk_species_subtypes_species FOREIGN KEY (species_id) REFERENCES species(id) ON DELETE CASCADE,
    CONSTRAINT fk_species_subtypes_subtype FOREIGN KEY (subtype_id) REFERENCES subtypes(id) ON DELETE CASCADE
) ENGINE=InnoDB;
