-- Fills in the remaining data-model gaps from the pilot proposal (§5):
-- Observation (growth/health history), Maintenance Log (care activity),
-- Nursery Stock (sales qty/price/status), and a richer Interest Event
-- (activity type, contact channel, lead status) on top of tree_interests.
--
-- Run against an existing tree_qr_system database that predates these
-- tables (a fresh install via docs/install.sql already includes them).
USE tree_qr_system;

-- ---------------------------------------------------------------
-- Observation — a point-in-time growth/health reading for one tree.
-- ---------------------------------------------------------------
CREATE TABLE observations (
    id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tree_id        BIGINT UNSIGNED NOT NULL,
    observed_at    DATE NOT NULL,
    height_cm      DECIMAL(7,1) NULL,
    canopy_cm      DECIMAL(7,1) NULL, -- ขนาดทรงพุ่ม
    health         ENUM('good','fair','poor') NOT NULL DEFAULT 'good',
    notes          TEXT NULL,
    recorded_by    VARCHAR(150) NULL, -- ผู้บันทึก — free text, no staff-account system yet
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_observations_tree FOREIGN KEY (tree_id) REFERENCES trees(id) ON DELETE CASCADE,
    INDEX idx_observations_tree_date (tree_id, observed_at)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------
-- Maintenance Log — a care activity performed on one tree.
-- ---------------------------------------------------------------
CREATE TABLE maintenance_logs (
    id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tree_id        BIGINT UNSIGNED NOT NULL,
    activity       VARCHAR(100) NOT NULL, -- e.g. รดน้ำ/ตัดแต่ง/ใส่ปุ๋ย/กำจัดศัตรูพืช — free text, not a fixed enum
    performed_at   DATE NOT NULL,
    performed_by   VARCHAR(150) NULL,
    notes          TEXT NULL,
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_maintenance_tree FOREIGN KEY (tree_id) REFERENCES trees(id) ON DELETE CASCADE,
    INDEX idx_maintenance_tree_date (tree_id, performed_at)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------
-- Nursery Stock — sales-side info, linked to a SPECIES (stock/pricing is
-- normally tracked per plant type, not per individual specimen).
-- ---------------------------------------------------------------
CREATE TABLE nursery_stock (
    id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    species_id     BIGINT UNSIGNED NOT NULL,
    size_label     VARCHAR(50) NULL, -- e.g. เล็ก/กลาง/ใหญ่, or a pot size
    quantity       INT UNSIGNED NOT NULL DEFAULT 0,
    price          DECIMAL(10,2) NULL,
    sale_status    ENUM('available','reserved','sold_out','not_for_sale') NOT NULL DEFAULT 'not_for_sale',
    sales_channel  VARCHAR(150) NULL, -- ช่องทางการขาย, e.g. "เรือนเพาะชำสวนนงนุช", "ออนไลน์"
    updated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_stock_species FOREIGN KEY (species_id) REFERENCES species(id) ON DELETE CASCADE,
    INDEX idx_stock_species (species_id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------
-- Richer Interest Event fields on the existing tree_interests table:
-- activity type (what kind of interest), contact channel, and a lead
-- status the sales team can move through a simple pipeline.
-- ---------------------------------------------------------------
ALTER TABLE tree_interests
  ADD COLUMN activity_type  ENUM('interest_click','price_request') NOT NULL DEFAULT 'interest_click' AFTER email,
  ADD COLUMN contact_channel VARCHAR(100) NULL AFTER activity_type, -- e.g. "Line", "โทรศัพท์" — free text, admin-entered on follow-up
  ADD COLUMN lead_status ENUM('new','contacted','converted','closed') NOT NULL DEFAULT 'new' AFTER contact_channel,
  ADD COLUMN lead_updated_at DATETIME NULL AFTER lead_status;
