-- Planting plans — a future/intended planting recorded before any actual
-- tree row exists for it: target zone/species, quantity, target date, an
-- optional GPS point and a reference photo, plus free-text notes. Turning a
-- plan into real trees still means using "เพิ่มต้นไม้" separately; a plan
-- only tracks the intent to plant, not the planted asset itself.
--
-- Run against an existing tree_qr_system database that predates this
-- change (a fresh install via docs/install.sql already includes it).
USE tree_qr_system;

CREATE TABLE planting_plans (
    id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    zone_id           BIGINT UNSIGNED NOT NULL,
    species_id        BIGINT UNSIGNED NULL, -- nullable: species may not be decided yet when the plan is first recorded
    planned_quantity  INT UNSIGNED NOT NULL DEFAULT 1,
    target_date       DATE NULL,
    latitude          DECIMAL(10,7) NULL,
    longitude         DECIMAL(10,7) NULL,
    image_path        VARCHAR(255) NULL, -- reference photo/sketch of the planned spot
    status            ENUM('pending','in_progress','completed','cancelled') NOT NULL DEFAULT 'pending',
    notes             TEXT NULL,
    created_by        VARCHAR(150) NULL,
    created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_planting_plans_zone    FOREIGN KEY (zone_id)    REFERENCES zones(id),
    CONSTRAINT fk_planting_plans_species FOREIGN KEY (species_id) REFERENCES species(id),
    INDEX idx_planting_plans_zone (zone_id),
    INDEX idx_planting_plans_status (status)
) ENGINE=InnoDB;

INSERT INTO permissions (permission_key, module, description) VALUES
    ('plan.manage', 'master_data', 'จัดการแผนการปลูกต้นไม้ล่วงหน้า');

-- install.sql grants every permission to 'programmer' with one bulk INSERT
-- that only ran once, at initial install — a plain re-run of that same
-- SELECT here would try to re-insert every OTHER already-granted pairing
-- too and fail on the primary key, so this migration grants the new
-- permission explicitly instead, one row per role that should have it.
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r, permissions p
WHERE r.role_key = 'programmer' AND p.permission_key = 'plan.manage';

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r, permissions p
WHERE r.role_key = 'tree_admin' AND p.permission_key = 'plan.manage';
