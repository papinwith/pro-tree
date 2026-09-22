-- Adds multilingual tree content (English/Chinese), the category lookup
-- table, and the 10-digit classification_id field.
-- Run against an existing tree_qr_system database that predates these
-- columns (a fresh install via docs/install.sql already includes them).
USE tree_qr_system;

CREATE TABLE IF NOT EXISTS categories (
    code       CHAR(3) PRIMARY KEY,
    name_th    VARCHAR(100) NOT NULL,
    name_en    VARCHAR(100) NOT NULL,
    name_zh    VARCHAR(100) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

INSERT IGNORE INTO categories (code, name_th, name_en, name_zh) VALUES
  ('001', 'กล้วยไม้', 'Orchid', '兰花'),
  ('002', 'ต้นไม้', 'Tree', '树'),
  ('003', 'ไม้ดอก', 'Flowering Plant', '开花植物');

ALTER TABLE trees
  ADD COLUMN classification_id CHAR(10) NULL UNIQUE AFTER slug,
  ADD COLUMN name_en        VARCHAR(150) NULL AFTER name,
  ADD COLUMN name_zh        VARCHAR(150) NULL AFTER name_en,
  ADD COLUMN description_en TEXT NULL AFTER description,
  ADD COLUMN description_zh TEXT NULL AFTER description_en;
