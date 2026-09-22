-- Moves the "เล็ก/กลาง/ใหญ่" nursery_stock size picker from a hardcoded PHP
-- list (admin/species_form.php, includes/lang.php) into a real table, so
-- adding/renaming/reordering a size is a data change, not a code change.
-- Existing nursery_stock.size_label text values are matched to the new
-- rows and backfilled into a new size_id column; size_label is then
-- renamed to legacy_size_label (kept, not dropped — see below) since
-- size_id is the sole source of truth going forward.
--
-- Run against an existing tree_qr_system database that predates this
-- change (a fresh install via docs/install.sql already includes it).
USE tree_qr_system;

CREATE TABLE stock_sizes (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name_th        VARCHAR(50) NOT NULL,
    name_en        VARCHAR(50) NULL,
    name_zh        VARCHAR(50) NULL,
    display_order  INT UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB;

INSERT INTO stock_sizes (name_th, name_en, name_zh, display_order) VALUES
('เล็ก', 'Small', '小', 1),
('กลาง', 'Medium', '中', 2),
('ใหญ่', 'Large', '大', 3);

ALTER TABLE nursery_stock
  ADD COLUMN size_id INT UNSIGNED NULL AFTER species_id,
  ADD CONSTRAINT fk_stock_size FOREIGN KEY (size_id) REFERENCES stock_sizes(id);

-- Backfill from the old free-text column. Any existing size_label that
-- doesn't match one of the three seeded names (e.g. old free-typed text
-- from before the dropdown existed) is left as size_id = NULL rather than
-- guessed at — reassign those rows a size manually in the stock UI.
UPDATE nursery_stock ns
  JOIN stock_sizes ss ON ss.name_th = ns.size_label
  SET ns.size_id = ss.id;

-- Rename rather than drop: any size_label that didn't match a seeded name
-- (size_id still NULL) holds free-typed text with no other record of it —
-- dropping the column here would destroy that text permanently instead of
-- leaving it for manual reassignment. Safe to drop legacy_size_label in a
-- later migration once every row has a size_id.
ALTER TABLE nursery_stock CHANGE COLUMN size_label legacy_size_label VARCHAR(50) NULL;
