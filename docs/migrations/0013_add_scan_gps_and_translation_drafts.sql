-- Two additive features:
--  1. GPS scan-location capture on tree_scans, plus a snapshot of the
--     tree's registered zone/location AT SCAN TIME so a later tree move
--     never rewrites historical scan records.
--  2. translation_drafts — holds pending Gemini-generated EN/ZH text for
--     admin review before it's copied into species.*_en/*_zh.
--
-- Run against an existing tree_qr_system database that predates these
-- columns/table (a fresh install via docs/install.sql already includes them).
USE tree_qr_system;

ALTER TABLE tree_scans
  ADD COLUMN scan_lat            DECIMAL(10,7) NULL,      -- visitor's GPS, only if permission granted
  ADD COLUMN scan_lng            DECIMAL(10,7) NULL,
  ADD COLUMN gps_accuracy_m      DECIMAL(6,2)  NULL,
  ADD COLUMN gps_available       TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN registered_zone_id  BIGINT UNSIGNED NULL,    -- snapshot of trees.zone_id at scan time
  ADD COLUMN registered_lat      DECIMAL(10,7) NULL,      -- snapshot of trees.latitude at scan time
  ADD COLUMN registered_lng      DECIMAL(10,7) NULL;      -- snapshot of trees.longitude at scan time

CREATE TABLE translation_drafts (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    entity_type VARCHAR(30)  NOT NULL,       -- 'species' today
    entity_id   BIGINT UNSIGNED NOT NULL,
    field_name  VARCHAR(60)  NOT NULL,       -- 'name', 'description', 'care_instructions', ...
    lang        CHAR(2)      NOT NULL,       -- 'en' | 'zh'
    source_text TEXT         NOT NULL,       -- Thai text this draft was generated from (for side-by-side review)
    draft_text  TEXT         NOT NULL,
    source      VARCHAR(20)  NOT NULL DEFAULT 'gemini',
    status      ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    reviewed_by BIGINT UNSIGNED NULL,
    reviewed_at DATETIME NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_drafts_entity (entity_type, entity_id, status)
) ENGINE=InnoDB;
