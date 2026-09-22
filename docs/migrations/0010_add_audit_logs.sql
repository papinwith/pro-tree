-- Append-only change log for admin actions, so edits to trees/species/zones
-- can be traced back to who changed what and when.
--
-- Run against an existing tree_qr_system database that predates this table
-- (a fresh install via docs/install.sql already includes it).
USE tree_qr_system;

CREATE TABLE audit_logs (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    admin_id    BIGINT UNSIGNED NULL,
    action      VARCHAR(60) NOT NULL,   -- e.g. 'tree.update', 'tree.zone_change'
    entity_type VARCHAR(40) NOT NULL,   -- e.g. 'tree', 'species', 'zone'
    entity_id   BIGINT UNSIGNED NOT NULL,
    before_json JSON NULL,
    after_json  JSON NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_audit_admin FOREIGN KEY (admin_id) REFERENCES admins(id) ON DELETE SET NULL,
    INDEX idx_audit_entity (entity_type, entity_id, created_at),
    INDEX idx_audit_admin (admin_id, created_at)
) ENGINE=InnoDB;
