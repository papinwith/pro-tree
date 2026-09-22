-- Adds an append-only move log (never overwrite a tree's previous
-- location — insert a history row instead) and a secondary QR-tag lookup
-- table. The canonical public URL stays /tree/{id} (trees.id, unchanged);
-- qr_tags.tag_code is an additional physical-label reference, not a new
-- routing scheme.
--
-- Run against an existing tree_qr_system database that predates these
-- tables (a fresh install via docs/install.sql already includes them).
USE tree_qr_system;

CREATE TABLE plant_location_history (
    id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tree_id      BIGINT UNSIGNED NOT NULL,
    from_zone_id BIGINT UNSIGNED NULL,
    to_zone_id   BIGINT UNSIGNED NULL,
    from_area_code CHAR(2) NULL,
    to_area_code   CHAR(2) NULL,
    reason       VARCHAR(255) NULL,
    changed_by   BIGINT UNSIGNED NULL, -- admins.id
    changed_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_plh_tree      FOREIGN KEY (tree_id)      REFERENCES trees(id)  ON DELETE CASCADE,
    CONSTRAINT fk_plh_from_zone FOREIGN KEY (from_zone_id) REFERENCES zones(id)  ON DELETE SET NULL,
    CONSTRAINT fk_plh_to_zone   FOREIGN KEY (to_zone_id)   REFERENCES zones(id)  ON DELETE SET NULL,
    CONSTRAINT fk_plh_admin     FOREIGN KEY (changed_by)   REFERENCES admins(id) ON DELETE SET NULL,
    INDEX idx_plh_tree (tree_id, changed_at)
) ENGINE=InnoDB;

CREATE TABLE qr_tags (
    id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tag_code      VARCHAR(30) NOT NULL UNIQUE, -- printed label code, independent of trees.id
    tree_id       BIGINT UNSIGNED NULL,        -- NULL = tag printed but not yet installed on a tree
    status        ENUM('printed','installed','damaged','retired') NOT NULL DEFAULT 'printed',
    installed_at  DATETIME NULL,
    last_checked_at DATETIME NULL,             -- last time staff confirmed the physical tag scans correctly
    scan_count    INT UNSIGNED NOT NULL DEFAULT 0,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_qrtags_tree FOREIGN KEY (tree_id) REFERENCES trees(id) ON DELETE SET NULL,
    INDEX idx_qrtags_status (status, last_checked_at)
) ENGINE=InnoDB;

-- Seed one qr_tags row per existing active tree, using the same asset-code
-- format already printed on QR sheets (see assetCode() in functions.php).
INSERT INTO qr_tags (tag_code, tree_id, status, installed_at)
SELECT CONCAT('NN-UD-', LPAD(id, 6, '0')), id, 'installed', created_at
FROM trees
WHERE is_active = 1;
