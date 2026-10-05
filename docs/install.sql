-- ============================================================================
-- Living Plant Asset & Nursery Stock Management System — ONE-FILE INSTALL
-- Everything in a single runnable script: full table structure (12 tables)
-- + a full demo dataset — admin login, 10 categories, 10 zones, 27 species,
-- 103 trees, every plant_code already computed, no duplicates.
--
-- Run:
--   mysql -u root -p --default-character-set=utf8mb4 < docs/install.sql
--
-- This used to be split across schema.sql + seed.sql (and later a separate
-- seed_demo_100.sql for a bigger dataset) — all merged into this one file
-- so a fresh setup can never end up with tables but no data, or have to
-- guess which of several SQL files to run.
--
-- MySQL 8.0+ / MariaDB 10.2+ (needs window functions for the plant_code
-- backfill at the bottom). InnoDB, utf8mb4.
-- ============================================================================

CREATE DATABASE IF NOT EXISTS tree_qr_system
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

USE tree_qr_system;

-- ---------------------------------------------------------------
-- Roles & Permissions (RBAC) — normalized/data-driven, not a hardcoded
-- ENUM, so new roles/permissions can be added later with INSERTs only.
-- See docs/rbac.md for the full design and role -> permission matrix.
-- ---------------------------------------------------------------
CREATE TABLE roles (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    role_key    VARCHAR(40) NOT NULL UNIQUE,
    name_th     VARCHAR(100) NOT NULL,
    name_en     VARCHAR(100) NOT NULL,
    description TEXT NULL,
    is_system   TINYINT(1) NOT NULL DEFAULT 0,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE permissions (
    id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    permission_key VARCHAR(60) NOT NULL UNIQUE,
    module         VARCHAR(40) NOT NULL,
    description    TEXT NULL
) ENGINE=InnoDB;

CREATE TABLE role_permissions (
    role_id       BIGINT UNSIGNED NOT NULL,
    permission_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (role_id, permission_id),
    CONSTRAINT fk_rp_role       FOREIGN KEY (role_id)       REFERENCES roles(id)       ON DELETE CASCADE,
    CONSTRAINT fk_rp_permission FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------
-- Admins (separate from visitor identity system entirely)
-- ---------------------------------------------------------------
CREATE TABLE admins (
    id                     BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username               VARCHAR(100) NOT NULL UNIQUE,
    password_hash          VARCHAR(255) NOT NULL,
    role_id                BIGINT UNSIGNED NOT NULL,
    failed_login_attempts  INT UNSIGNED NOT NULL DEFAULT 0,
    locked_until           DATETIME NULL,
    created_at             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_admins_role FOREIGN KEY (role_id) REFERENCES roles(id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------
-- Global admin-configurable settings (e.g. default map banner/url)
-- ---------------------------------------------------------------
CREATE TABLE settings (
    setting_key   VARCHAR(100) PRIMARY KEY,
    setting_value TEXT NULL,
    updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ---------------------------------------------------------------
-- Categories (the 3-digit prefix of a species' classification_id, and the
-- first segment of every tree's plant_code). Codes start at 101 (not 001)
-- so plant_code reads as a fixed-width 15-digit asset code from digit one.
-- ---------------------------------------------------------------
CREATE TABLE categories (
    code       CHAR(3) PRIMARY KEY,
    name_th    VARCHAR(100) NOT NULL,
    name_en    VARCHAR(100) NULL, -- nullable: new categories are Thai-only on creation, EN/ZH auto-translated on first public view (see includes/translation.php)
    name_zh    VARCHAR(100) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ---------------------------------------------------------------
-- Subtypes ("ชนิด") — an organizational grouping nested under one
-- category (e.g. "ไม้ผล" under "ไม้ยืนต้น"). Purely for the admin-facing
-- ประเภท -> ชนิด -> ชื่อต้นไม้ hierarchy; it does NOT feed trees.plant_code
-- (that stays category+species_code+zone+area+sequence, unchanged).
-- ---------------------------------------------------------------
CREATE TABLE subtypes (
    id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    category_code CHAR(3) NULL, -- nullable: "เพิ่มชนิด" only asks for a name; the category link is made later (edit, or the first time it's used for a species)
    name_th       VARCHAR(100) NOT NULL,
    name_en       VARCHAR(100) NULL,
    name_zh       VARCHAR(100) NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_subtypes_category FOREIGN KEY (category_code) REFERENCES categories(code)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------
-- Zones — physical areas. Every plant asset belongs to exactly one zone;
-- the zone can change if a plant is moved between areas without affecting
-- the plant's own identity (trees.id / QR link stays fixed).
-- ---------------------------------------------------------------
CREATE TABLE zones (
    id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    zone_code    VARCHAR(50) NOT NULL UNIQUE,
    zone_number  CHAR(3) NOT NULL UNIQUE, -- 3-digit numeric zone code used in trees.plant_code
    name         VARCHAR(150) NOT NULL, -- Thai (default)
    name_en      VARCHAR(150) NULL,
    name_zh      VARCHAR(150) NULL,
    description    TEXT NULL,
    description_en TEXT NULL,
    description_zh TEXT NULL,
    map_pin_x    DECIMAL(5,2) NULL, -- pin position on the public map banner image, % from left
    map_pin_y    DECIMAL(5,2) NULL, -- pin position on the public map banner image, % from top
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ---------------------------------------------------------------
-- Species — master data shared by every individual plant of that species
-- (name, care instructions, characteristics/properties/benefits/cautions/
-- part uses). category_code/species_code feed trees.plant_code; the
-- separate classification_id is an older, independently-maintained code.
-- ---------------------------------------------------------------
CREATE TABLE species (
    id                 BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    category_code      CHAR(3) NOT NULL,
    subtype_id         BIGINT UNSIGNED NULL, -- ชนิด — organizational only, category_code (above) is still the source of truth for plant_code
    species_code       CHAR(3) NOT NULL,
    classification_id  CHAR(10) NULL UNIQUE,
    name               VARCHAR(150) NOT NULL, -- Thai (default) name
    name_en            VARCHAR(150) NULL,
    name_zh            VARCHAR(150) NULL,
    name_common        VARCHAR(150) NULL, -- ชื่อสามัญ
    name_scientific    VARCHAR(150) NULL, -- ชื่อวิทยาศาสตร์ (Latin binomial, not localized)
    image_path         VARCHAR(255) NULL, -- photo of the species itself; public page falls back to it when a tree has no photo of its own
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
    updated_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_species_category FOREIGN KEY (category_code) REFERENCES categories(code),
    CONSTRAINT fk_species_subtype  FOREIGN KEY (subtype_id)    REFERENCES subtypes(id),
    UNIQUE KEY uq_species_category_species (category_code, species_code)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------
-- Extra subtypes for a species that genuinely belongs to more than one
-- "ชนิด" (e.g. both ไม้ผล and ไม้ดอก) — species.subtype_id above stays the
-- one, required "primary" subtype (still what plant_code/organization uses);
-- this table is purely additional tags, admin-side only. Deleting either
-- side cleans these up automatically.
-- ---------------------------------------------------------------
CREATE TABLE species_subtypes (
    species_id BIGINT UNSIGNED NOT NULL,
    subtype_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (species_id, subtype_id),
    CONSTRAINT fk_species_subtypes_species FOREIGN KEY (species_id) REFERENCES species(id) ON DELETE CASCADE,
    CONSTRAINT fk_species_subtypes_subtype FOREIGN KEY (subtype_id) REFERENCES subtypes(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------
-- Trees — individual plant specimens ("Plant Asset" / "Tree ID").
-- ---------------------------------------------------------------
CREATE TABLE trees (
    id                 BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    slug               VARCHAR(150) NULL UNIQUE,
    species_id         BIGINT UNSIGNED NOT NULL,
    zone_id            BIGINT UNSIGNED NOT NULL,
    area_code          CHAR(2) NOT NULL DEFAULT '01', -- พื้นที่ในสวน
    plant_code             CHAR(15) NULL UNIQUE, -- 15-digit printed code; NOT stable across a move — see database.md §4c
    plant_code_updated_at  DATETIME NULL,
    label              VARCHAR(150) NULL,
    status             ENUM('healthy','needs_attention','removed') NOT NULL DEFAULT 'healthy',
    data_status        ENUM('draft','verified','published') NOT NULL DEFAULT 'published',
    image_path         VARCHAR(255) NULL,
    map_image_path     VARCHAR(255) NULL,
    map_url            VARCHAR(255) NULL,
    qr_code_path       VARCHAR(255) NULL,
    latitude            DECIMAL(10,7) NULL,
    longitude           DECIMAL(10,7) NULL,
    location_updated_at DATETIME NULL,
    map_pin_x          DECIMAL(5,2) NULL, -- pin position on the public map banner image, % from left
    map_pin_y          DECIMAL(5,2) NULL, -- pin position on the public map banner image, % from top
    display_order      INT NOT NULL,
    is_active          TINYINT(1) NOT NULL DEFAULT 1,
    created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_trees_display_order (display_order),
    CONSTRAINT fk_trees_species FOREIGN KEY (species_id) REFERENCES species(id),
    CONSTRAINT fk_trees_zone    FOREIGN KEY (zone_id)    REFERENCES zones(id),
    INDEX idx_trees_species (species_id),
    INDEX idx_trees_zone (zone_id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------
-- Planting plans — a future/intended planting recorded before any actual
-- tree row exists for it: target zone/species, quantity, target date, an
-- optional GPS point and a reference photo, plus free-text notes.
-- ---------------------------------------------------------------
CREATE TABLE planting_plans (
    id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    zone_id           BIGINT UNSIGNED NOT NULL,
    species_id        BIGINT UNSIGNED NULL, -- nullable: species may not be decided yet when the plan is first recorded
    planned_quantity  INT UNSIGNED NOT NULL DEFAULT 1,
    target_date       DATE NULL,
    latitude          DECIMAL(10,7) NULL,
    longitude         DECIMAL(10,7) NULL,
    map_pin_x         DECIMAL(5,2) NULL, -- pin position on the public map banner image, % from left
    map_pin_y         DECIMAL(5,2) NULL, -- pin position on the public map banner image, % from top
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

-- ---------------------------------------------------------------
-- Visitors (identified by cookie-issued UUID, not login)
-- ---------------------------------------------------------------
CREATE TABLE visitors (
    id               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    visitor_uuid     CHAR(36) NOT NULL UNIQUE,
    first_seen_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_seen_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    last_ip_address  VARCHAR(45) NULL,
    last_user_agent  VARCHAR(255) NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------------
-- Tree Scans (one row per scan/view event)
-- ---------------------------------------------------------------
CREATE TABLE tree_scans (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tree_id     BIGINT UNSIGNED NOT NULL,
    visitor_id  BIGINT UNSIGNED NOT NULL,
    ip_address  VARCHAR(45) NULL,
    user_agent  VARCHAR(255) NULL,
    scanned_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    -- Visitor's GPS at scan time — optional, never blocks the scan if denied.
    scan_lat           DECIMAL(10,7) NULL,
    scan_lng           DECIMAL(10,7) NULL,
    gps_accuracy_m     DECIMAL(6,2)  NULL,
    gps_available      TINYINT(1) NOT NULL DEFAULT 0,
    -- Snapshot of the tree's registered zone/location AT SCAN TIME, so a
    -- later tree move never rewrites historical scan records.
    registered_zone_id BIGINT UNSIGNED NULL,
    registered_lat     DECIMAL(10,7) NULL,
    registered_lng     DECIMAL(10,7) NULL,
    CONSTRAINT fk_scans_tree    FOREIGN KEY (tree_id)    REFERENCES trees(id)    ON DELETE CASCADE,
    CONSTRAINT fk_scans_visitor FOREIGN KEY (visitor_id) REFERENCES visitors(id) ON DELETE CASCADE,
    INDEX idx_scans_tree_visitor (tree_id, visitor_id),
    INDEX idx_scans_tree_time (tree_id, scanned_at)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------
-- Tree Interests / "Interest Event"
-- ---------------------------------------------------------------
CREATE TABLE tree_interests (
    id               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tree_id          BIGINT UNSIGNED NOT NULL,
    email            VARCHAR(255) NOT NULL,
    activity_type    ENUM('interest_click','price_request') NOT NULL DEFAULT 'interest_click',
    contact_channel  VARCHAR(100) NULL,
    lead_status      ENUM('new','contacted','converted','closed') NOT NULL DEFAULT 'new',
    lead_updated_at  DATETIME NULL,
    consent_at       DATETIME NULL,     -- PDPA: required whenever contact info is captured
    purge_after      DATE NULL,         -- auto-purge date, default 90 days after the event
    session_hash     CHAR(64) NULL,     -- SHA-256(ip+user_agent+daily_salt), never a raw IP
    visitor_id    BIGINT UNSIGNED NULL,
    submitted_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_interests_tree    FOREIGN KEY (tree_id)    REFERENCES trees(id)    ON DELETE CASCADE,
    CONSTRAINT fk_interests_visitor FOREIGN KEY (visitor_id) REFERENCES visitors(id) ON DELETE SET NULL,
    INDEX idx_interests_tree (tree_id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------
-- Observation — a point-in-time growth/health reading for one tree.
-- ---------------------------------------------------------------
CREATE TABLE observations (
    id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tree_id        BIGINT UNSIGNED NOT NULL,
    observed_at    DATE NOT NULL,
    height_cm      DECIMAL(7,1) NULL,
    canopy_cm      DECIMAL(7,1) NULL,
    health         ENUM('good','fair','poor') NOT NULL DEFAULT 'good',
    notes          TEXT NULL,
    recorded_by    VARCHAR(150) NULL,
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
    activity       VARCHAR(100) NOT NULL,
    performed_at   DATE NOT NULL,
    performed_by   VARCHAR(150) NULL,
    notes          TEXT NULL,
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_maintenance_tree FOREIGN KEY (tree_id) REFERENCES trees(id) ON DELETE CASCADE,
    INDEX idx_maintenance_tree_date (tree_id, performed_at)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------
-- Stock sizes — the "เล็ก/กลาง/ใหญ่" picker in admin/species_form.php reads
-- this table (with EN/ZH names for the public tree page) instead of a
-- hardcoded list, so adding/renaming/reordering a size is a data change,
-- not a code change.
-- ---------------------------------------------------------------
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

-- ---------------------------------------------------------------
-- Nursery Stock — sales-side info, linked to a SPECIES.
-- ---------------------------------------------------------------
CREATE TABLE nursery_stock (
    id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    species_id     BIGINT UNSIGNED NOT NULL,
    size_id        INT UNSIGNED NULL,
    quantity       INT UNSIGNED NOT NULL DEFAULT 0,
    price          DECIMAL(10,2) NULL,
    sale_status    ENUM('available','reserved','sold_out','not_for_sale') NOT NULL DEFAULT 'not_for_sale',
    sales_channel  VARCHAR(150) NULL,
    updated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_stock_species FOREIGN KEY (species_id) REFERENCES species(id) ON DELETE CASCADE,
    CONSTRAINT fk_stock_size FOREIGN KEY (size_id) REFERENCES stock_sizes(id),
    INDEX idx_stock_species (species_id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------
-- Sale transactions — an actual completed sale (quantity, price paid,
-- when), as opposed to nursery_stock (current on-hand listing/asking price)
-- or tree_interests (a lead that may or may not have bought anything).
-- Recording one here decrements the matching nursery_stock row so the
-- listed quantity stays accurate. species_id/size_id are kept even if the
-- stock row is later deleted, so historical revenue reporting never loses
-- rows to an unrelated stock cleanup.
-- ---------------------------------------------------------------
CREATE TABLE sale_transactions (
    id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    species_id     BIGINT UNSIGNED NOT NULL,
    size_id        INT UNSIGNED NULL,
    quantity       INT UNSIGNED NOT NULL DEFAULT 1,
    unit_price     DECIMAL(10,2) NOT NULL,
    total_price    DECIMAL(10,2) NOT NULL,
    interest_id    BIGINT UNSIGNED NULL, -- optional: which lead this sale closed out
    sold_by        VARCHAR(100) NULL,    -- admin username who recorded it
    notes          TEXT NULL,
    sold_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_sales_species  FOREIGN KEY (species_id)  REFERENCES species(id) ON DELETE CASCADE,
    CONSTRAINT fk_sales_size     FOREIGN KEY (size_id)     REFERENCES stock_sizes(id),
    CONSTRAINT fk_sales_interest FOREIGN KEY (interest_id) REFERENCES tree_interests(id) ON DELETE SET NULL,
    INDEX idx_sales_species (species_id),
    INDEX idx_sales_sold_at (sold_at)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------
-- Plant Location History — append-only move log. A tree's zone/area_code
-- change on the trees row itself, but the *previous* location is preserved
-- here rather than being overwritten, so a plant's movement history can be
-- reconstructed later.
-- ---------------------------------------------------------------
CREATE TABLE plant_location_history (
    id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tree_id        BIGINT UNSIGNED NOT NULL,
    from_zone_id   BIGINT UNSIGNED NULL,
    to_zone_id     BIGINT UNSIGNED NULL,
    from_area_code CHAR(2) NULL,
    to_area_code   CHAR(2) NULL,
    reason         VARCHAR(255) NULL,
    changed_by     BIGINT UNSIGNED NULL,
    changed_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_plh_tree      FOREIGN KEY (tree_id)      REFERENCES trees(id)  ON DELETE CASCADE,
    CONSTRAINT fk_plh_from_zone FOREIGN KEY (from_zone_id) REFERENCES zones(id)  ON DELETE SET NULL,
    CONSTRAINT fk_plh_to_zone   FOREIGN KEY (to_zone_id)   REFERENCES zones(id)  ON DELETE SET NULL,
    CONSTRAINT fk_plh_admin     FOREIGN KEY (changed_by)   REFERENCES admins(id) ON DELETE SET NULL,
    INDEX idx_plh_tree (tree_id, changed_at)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------
-- QR Tags — a secondary lookup for printed physical labels. The canonical
-- public URL stays /tree/{id} (trees.id never changes); this table exists
-- for print-run/install tracking (which physical stickers are installed,
-- damaged, or awaiting a tree), not for routing.
-- ---------------------------------------------------------------
CREATE TABLE qr_tags (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tag_code        VARCHAR(30) NOT NULL UNIQUE,
    tree_id         BIGINT UNSIGNED NULL,
    status          ENUM('printed','installed','damaged','retired') NOT NULL DEFAULT 'printed',
    installed_at    DATETIME NULL,
    last_checked_at DATETIME NULL,
    scan_count      INT UNSIGNED NOT NULL DEFAULT 0,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_qrtags_tree FOREIGN KEY (tree_id) REFERENCES trees(id) ON DELETE SET NULL,
    INDEX idx_qrtags_status (status, last_checked_at)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------
-- Audit Logs — append-only record of admin changes, for traceability.
-- ---------------------------------------------------------------
CREATE TABLE audit_logs (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    admin_id    BIGINT UNSIGNED NULL,
    action      VARCHAR(60) NOT NULL,
    entity_type VARCHAR(40) NOT NULL,
    entity_id   BIGINT UNSIGNED NOT NULL,
    before_json JSON NULL,
    after_json  JSON NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_audit_admin FOREIGN KEY (admin_id) REFERENCES admins(id) ON DELETE SET NULL,
    INDEX idx_audit_entity (entity_type, entity_id, created_at),
    INDEX idx_audit_admin (admin_id, created_at)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------
-- Highlights — daily/scheduled expo event items shown on the home page.
-- ---------------------------------------------------------------
CREATE TABLE highlights (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title       VARCHAR(200) NOT NULL,
    title_en    VARCHAR(200) NULL,
    title_zh    VARCHAR(200) NULL,
    event_date  DATE NOT NULL,
    event_time  VARCHAR(20) NULL,
    zone_id     BIGINT UNSIGNED NULL,
    image_path  VARCHAR(255) NULL,
    sort_order  INT NOT NULL DEFAULT 0,
    is_active   TINYINT(1) NOT NULL DEFAULT 1,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_highlights_zone FOREIGN KEY (zone_id) REFERENCES zones(id) ON DELETE SET NULL,
    INDEX idx_highlights_date (event_date, is_active)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------
-- News Posts — expo news/announcements shown on the home and news pages.
-- ---------------------------------------------------------------
CREATE TABLE news_posts (
    id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title        VARCHAR(200) NOT NULL,
    title_en     VARCHAR(200) NULL,
    title_zh     VARCHAR(200) NULL,
    excerpt      VARCHAR(500) NULL,
    excerpt_en   VARCHAR(500) NULL,
    excerpt_zh   VARCHAR(500) NULL,
    body         TEXT NULL,
    body_en      TEXT NULL,
    body_zh      TEXT NULL,
    image_path   VARCHAR(255) NULL,
    published_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    is_active    TINYINT(1) NOT NULL DEFAULT 1,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_news_published (published_at, is_active)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------
-- Shops / Products — marketplace catalog for the expo frontend. No
-- payment/checkout, no stock decrement: sold_count/compare_at_price are
-- display-only, not derived from any real order system.
-- ---------------------------------------------------------------
CREATE TABLE shops (
    id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name         VARCHAR(150) NOT NULL,
    zone_id      BIGINT UNSIGNED NULL,
    rating       DECIMAL(2,1) NULL,
    review_count INT UNSIGNED NOT NULL DEFAULT 0,
    about        TEXT NULL,
    banner_path  VARCHAR(255) NULL,
    is_active    TINYINT(1) NOT NULL DEFAULT 1,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_shops_zone FOREIGN KEY (zone_id) REFERENCES zones(id) ON DELETE SET NULL,
    INDEX idx_shops_zone (zone_id, is_active)
) ENGINE=InnoDB;

CREATE TABLE products (
    id               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    shop_id          BIGINT UNSIGNED NOT NULL,
    species_id       BIGINT UNSIGNED NULL,
    name             VARCHAR(200) NOT NULL,
    price            DECIMAL(10,2) NOT NULL,
    compare_at_price DECIMAL(10,2) NULL,
    image_path       VARCHAR(255) NULL,
    sold_count       INT UNSIGNED NOT NULL DEFAULT 0,
    tag              VARCHAR(50) NULL,
    is_active        TINYINT(1) NOT NULL DEFAULT 1,
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_products_shop    FOREIGN KEY (shop_id)    REFERENCES shops(id)   ON DELETE CASCADE,
    CONSTRAINT fk_products_species FOREIGN KEY (species_id) REFERENCES species(id) ON DELETE SET NULL,
    INDEX idx_products_shop (shop_id, is_active)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------
-- Market Interests — leads captured from the marketplace UI, kept separate
-- from tree_interests so existing admin interest reports are unaffected.
-- ---------------------------------------------------------------
CREATE TABLE market_interests (
    id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_id    BIGINT UNSIGNED NULL,
    shop_id       BIGINT UNSIGNED NULL,
    email         VARCHAR(255) NOT NULL,
    consent_at    DATETIME NULL,
    purge_after   DATE NULL,
    session_hash  CHAR(64) NULL,
    lead_status   ENUM('new','contacted','converted','closed') NOT NULL DEFAULT 'new',
    submitted_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_market_interests_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE SET NULL,
    CONSTRAINT fk_market_interests_shop    FOREIGN KEY (shop_id)    REFERENCES shops(id)    ON DELETE SET NULL,
    INDEX idx_market_interests_product (product_id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------
-- Translation Drafts — pending Gemini-generated EN/ZH text, held here
-- until an admin reviews/edits and approves it into species.*_en/*_zh.
-- ---------------------------------------------------------------
CREATE TABLE translation_drafts (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    entity_type VARCHAR(30)  NOT NULL,
    entity_id   BIGINT UNSIGNED NOT NULL,
    field_name  VARCHAR(60)  NOT NULL,
    lang        CHAR(2)      NOT NULL,
    source_text TEXT         NOT NULL,
    draft_text  TEXT         NOT NULL,
    source      VARCHAR(20)  NOT NULL DEFAULT 'gemini',
    status      ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    reviewed_by BIGINT UNSIGNED NULL,
    reviewed_at DATETIME NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_drafts_entity (entity_type, entity_id, status)
) ENGINE=InnoDB;

-- ============================================================================
-- Seed data
-- ============================================================================

-- Demo dataset: 10 categories, 10 zones, 27 species, 103 trees — no
-- duplicate plant_codes or species+zone+area combos. plant_code is
-- already computed for every row (no follow-up PHP step needed).
-- Default admin: admin / ChangeMe123! — CHANGE THIS in production.

-- settings
INSERT INTO `settings` (`setting_key`, `setting_value`, `updated_at`) VALUES ('default_map_image', '/assets/map/default-map.jpg', '2026-08-15 17:10:59');
INSERT INTO `settings` (`setting_key`, `setting_value`, `updated_at`) VALUES ('default_map_url', 'https://maps.example.com/park-overview', '2026-08-15 17:10:59');

-- categories
INSERT INTO `categories` (`code`, `name_th`, `name_en`, `name_zh`, `created_at`) VALUES ('101', 'กล้วยไม้', 'Orchid', '兰花', '2026-08-15 17:10:59');
INSERT INTO `categories` (`code`, `name_th`, `name_en`, `name_zh`, `created_at`) VALUES ('102', 'ต้นไม้', 'Tree', '树', '2026-08-15 17:10:59');
INSERT INTO `categories` (`code`, `name_th`, `name_en`, `name_zh`, `created_at`) VALUES ('103', 'ไม้ดอก', 'Flowering Plant', '开花植物', '2026-08-15 17:10:59');
INSERT INTO `categories` (`code`, `name_th`, `name_en`, `name_zh`, `created_at`) VALUES ('104', 'ไม้พุ่ม', 'Shrub', '灌木', '2026-08-15 17:12:31');
INSERT INTO `categories` (`code`, `name_th`, `name_en`, `name_zh`, `created_at`) VALUES ('105', 'ไม้เลื้อย', 'Vine', '藤本植物', '2026-08-15 17:12:31');
INSERT INTO `categories` (`code`, `name_th`, `name_en`, `name_zh`, `created_at`) VALUES ('106', 'ไม้น้ำ', 'Aquatic Plant', '水生植物', '2026-08-15 17:12:31');
INSERT INTO `categories` (`code`, `name_th`, `name_en`, `name_zh`, `created_at`) VALUES ('107', 'ปาล์ม', 'Palm', '棕榈', '2026-08-15 17:12:31');
INSERT INTO `categories` (`code`, `name_th`, `name_en`, `name_zh`, `created_at`) VALUES ('108', 'เฟิร์น', 'Fern', '蕨类', '2026-08-15 17:12:31');
INSERT INTO `categories` (`code`, `name_th`, `name_en`, `name_zh`, `created_at`) VALUES ('109', 'ไม้ผล', 'Fruit Tree', '果树', '2026-08-15 17:12:31');
INSERT INTO `categories` (`code`, `name_th`, `name_en`, `name_zh`, `created_at`) VALUES ('110', 'สมุนไพร', 'Herb', '草本植物', '2026-08-15 17:12:31');

-- roles
INSERT INTO roles (role_key, name_th, name_en, description, is_system) VALUES
('programmer', 'โปรแกรมเมอร์', 'Programmer', 'สิทธิ์สูงสุด เข้าถึงได้ทุกส่วนของระบบ CRUD ทุกอย่าง', 1),
('executive', 'ผู้บริหาร', 'Executive', 'ดูแดชบอร์ดและรายงานเพื่อการตัดสินใจ อ่านอย่างเดียว แก้ไข/ลบข้อมูลไม่ได้', 1),
('tree_admin', 'ผู้ดูแลข้อมูลต้นไม้', 'Tree Admin', 'จัดการข้อมูลต้นไม้ ชนิดพันธุ์ โซน แหล่งที่มา และ QR Code ประจำวัน', 1);

-- permissions (see docs/rbac.md §3 for the full taxonomy)
INSERT INTO permissions (permission_key, module, description) VALUES
('tree.view', 'tree', 'ดูรายการ/รายละเอียดต้นไม้ในระบบแอดมิน'),
('tree.create', 'tree', 'เพิ่มต้นไม้ใหม่'),
('tree.update', 'tree', 'แก้ไขข้อมูลต้นไม้ที่มีอยู่'),
('tree.status.manage', 'tree', 'สลับสถานะ Active/Inactive (ใช้แทนการลบถาวร)'),
('tree.image.manage', 'tree', 'อัปโหลด/เปลี่ยนรูปต้นไม้'),
('tree.order.manage', 'tree', 'จัดลำดับการแสดงผล (Previous/Next)'),
('tree.map.manage', 'tree', 'ตั้งค่าแผนที่เฉพาะต้น (ภาพ/ลิงก์)'),
('tree.relationship.manage', 'tree', 'จัดการความสัมพันธ์ต้นก่อนหน้า/ถัดไป'),
('tree.location.manage', 'tree', 'ย้ายต้นไม้ไปตำแหน่ง/โซน/แหล่งที่มาใหม่'),
('category.manage', 'master_data', 'จัดการประเภทพืช'),
('species.manage', 'master_data', 'จัดการชนิดพันธุ์พืช'),
('zone.manage', 'master_data', 'จัดการโซน/ตำแหน่ง'),
('origin.manage', 'master_data', 'จัดการแหล่งที่มาของต้นไม้'),
('qrcode.manage', 'qr', 'สร้าง/พิมพ์ QR Code'),
('translation.request', 'translation', 'สั่งให้ AI แปลข้อความ'),
('translation.review', 'translation', 'ตรวจสอบ/อนุมัติคำแปลของ AI'),
('admin.manage', 'admin_accounts', 'จัดการบัญชีผู้ดูแลระบบและบทบาท'),
('gemini.config.manage', 'admin_accounts', 'ตั้งค่า Gemini API key/model'),
('scan.view', 'visitor_data', 'ดูประวัติ/สถิติการสแกน'),
('visitor.view', 'visitor_data', 'ดูข้อมูลผู้เข้าชม (Visitor ID, IP, User-Agent)'),
('interest.view', 'visitor_data', 'ดูรายชื่ออีเมลผู้สนใจ'),
('interest.manage', 'visitor_data', 'อัปเดตสถานะการติดตามผู้สนใจ (Lead)'),
('dashboard.view', 'reporting', 'เข้าถึงแดชบอร์ดผู้บริหาร'),
('stats.tree.view', 'reporting', 'สถิติรายต้น'),
('stats.category.view', 'reporting', 'สถิติรายประเภทพืช'),
('stats.zone.view', 'reporting', 'สถิติรายโซน'),
('trends.view', 'reporting', 'แนวโน้มผู้เข้าชม/การสแกนตามช่วงเวลา'),
('revenue.view', 'reporting', 'ข้อมูลราคา/ยอดขาย (ถ้ามี)'),
('reports.export', 'reporting', 'ส่งออกรายงานเป็น CSV'),
('map.settings.manage', 'system', 'ตั้งค่าแผนที่เริ่มต้นของทั้งระบบ'),
('settings.manage', 'system', 'ตั้งค่าระดับระบบ (โลโก้ ฯลฯ)'),
('plan.manage', 'master_data', 'จัดการแผนการปลูกต้นไม้ล่วงหน้า');

-- role_permissions grants (see docs/rbac.md §4 matrix)
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r, permissions p WHERE r.role_key = 'programmer';

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r, permissions p
WHERE r.role_key = 'executive'
  AND p.permission_key IN (
    'scan.view', 'visitor.view', 'interest.view',
    'dashboard.view', 'stats.tree.view', 'stats.category.view', 'stats.zone.view',
    'trends.view', 'revenue.view', 'reports.export'
  );

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r, permissions p
WHERE r.role_key = 'tree_admin'
  AND p.permission_key IN (
    'tree.view', 'tree.create', 'tree.update', 'tree.status.manage', 'tree.image.manage',
    'tree.order.manage', 'tree.map.manage', 'tree.relationship.manage', 'tree.location.manage',
    'category.manage', 'species.manage', 'zone.manage', 'origin.manage', 'qrcode.manage',
    'translation.request', 'translation.review', 'plan.manage',
    'scan.view', 'stats.tree.view'
  );

-- admins
INSERT INTO `admins` (`id`, `username`, `password_hash`, `role_id`, `created_at`)
VALUES ('1', 'admin', '$2y$12$cm9oXThdYnPgXPMAagOkkOflGT1pRmmrTdzhgCxJtJXHZ9zMQM8ba', (SELECT id FROM roles WHERE role_key = 'programmer'), '2026-08-15 17:10:59');

-- zones
INSERT INTO `zones` (`id`, `zone_code`, `zone_number`, `name`, `name_en`, `name_zh`, `description`, `description_en`, `description_zh`, `created_at`, `updated_at`) VALUES ('1', 'Z1', '001', 'โซนสวนหน้า', 'Front Garden Zone', '前花园区', 'พื้นที่จัดแสดงหลักใกล้ทางเข้า', 'Main display area near the entrance.', '入口附近的主要展示区。', '2026-08-15 17:10:59', '2026-08-15 17:10:59');
INSERT INTO `zones` (`id`, `zone_code`, `zone_number`, `name`, `name_en`, `name_zh`, `description`, `description_en`, `description_zh`, `created_at`, `updated_at`) VALUES ('2', 'Z2', '002', 'โซน 2', 'Zone 2', '区域2', NULL, NULL, NULL, '2026-08-15 17:12:31', '2026-08-15 17:12:31');
INSERT INTO `zones` (`id`, `zone_code`, `zone_number`, `name`, `name_en`, `name_zh`, `description`, `description_en`, `description_zh`, `created_at`, `updated_at`) VALUES ('3', 'Z3', '003', 'โซน 3', 'Zone 3', '区域3', NULL, NULL, NULL, '2026-08-15 17:12:31', '2026-08-15 17:12:31');
INSERT INTO `zones` (`id`, `zone_code`, `zone_number`, `name`, `name_en`, `name_zh`, `description`, `description_en`, `description_zh`, `created_at`, `updated_at`) VALUES ('4', 'Z4', '004', 'โซน 4', 'Zone 4', '区域4', NULL, NULL, NULL, '2026-08-15 17:12:31', '2026-08-15 17:12:31');
INSERT INTO `zones` (`id`, `zone_code`, `zone_number`, `name`, `name_en`, `name_zh`, `description`, `description_en`, `description_zh`, `created_at`, `updated_at`) VALUES ('5', 'Z5', '005', 'โซน 5', 'Zone 5', '区域5', NULL, NULL, NULL, '2026-08-15 17:12:31', '2026-08-15 17:12:31');
INSERT INTO `zones` (`id`, `zone_code`, `zone_number`, `name`, `name_en`, `name_zh`, `description`, `description_en`, `description_zh`, `created_at`, `updated_at`) VALUES ('6', 'Z6', '006', 'โซน 6', 'Zone 6', '区域6', NULL, NULL, NULL, '2026-08-15 17:12:31', '2026-08-15 17:12:31');
INSERT INTO `zones` (`id`, `zone_code`, `zone_number`, `name`, `name_en`, `name_zh`, `description`, `description_en`, `description_zh`, `created_at`, `updated_at`) VALUES ('7', 'Z7', '007', 'โซน 7', 'Zone 7', '区域7', NULL, NULL, NULL, '2026-08-15 17:12:31', '2026-08-15 17:12:31');
INSERT INTO `zones` (`id`, `zone_code`, `zone_number`, `name`, `name_en`, `name_zh`, `description`, `description_en`, `description_zh`, `created_at`, `updated_at`) VALUES ('8', 'Z8', '008', 'โซน 8', 'Zone 8', '区域8', NULL, NULL, NULL, '2026-08-15 17:12:31', '2026-08-15 17:12:31');
INSERT INTO `zones` (`id`, `zone_code`, `zone_number`, `name`, `name_en`, `name_zh`, `description`, `description_en`, `description_zh`, `created_at`, `updated_at`) VALUES ('9', 'Z9', '009', 'โซน 9', 'Zone 9', '区域9', NULL, NULL, NULL, '2026-08-15 17:12:31', '2026-08-15 17:12:31');
INSERT INTO `zones` (`id`, `zone_code`, `zone_number`, `name`, `name_en`, `name_zh`, `description`, `description_en`, `description_zh`, `created_at`, `updated_at`) VALUES ('10', 'Z10', '010', 'โซน 10', 'Zone 10', '区域10', NULL, NULL, NULL, '2026-08-15 17:12:31', '2026-08-15 17:12:31');

-- species
INSERT INTO `species` (`id`, `category_code`, `species_code`, `classification_id`, `name`, `name_en`, `name_zh`, `name_common`, `name_scientific`, `description`, `description_en`, `description_zh`, `care_instructions`, `care_instructions_en`, `care_instructions_zh`, `characteristics`, `characteristics_en`, `characteristics_zh`, `properties`, `properties_en`, `properties_zh`, `benefits`, `benefits_en`, `benefits_zh`, `cautions`, `cautions_en`, `cautions_zh`, `part_uses`, `part_uses_en`, `part_uses_zh`, `created_at`, `updated_at`) VALUES ('1', '102', '001', '1020000001', 'ต้นราชพฤกษ์', 'Golden Rain Tree', '腊肠树', 'Golden Shower', 'Cassia fistula', 'ไม้ให้ร่มเงาที่มีดอกสีเหลืองสดในฤดูร้อน', 'A shade tree known for its bright yellow summer blossoms.', '一种以夏季鲜黄色花朵闻名的遮荫树。', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-08-15 17:10:59', '2026-08-15 17:10:59');
INSERT INTO `species` (`id`, `category_code`, `species_code`, `classification_id`, `name`, `name_en`, `name_zh`, `name_common`, `name_scientific`, `description`, `description_en`, `description_zh`, `care_instructions`, `care_instructions_en`, `care_instructions_zh`, `characteristics`, `characteristics_en`, `characteristics_zh`, `properties`, `properties_en`, `properties_zh`, `benefits`, `benefits_en`, `benefits_zh`, `cautions`, `cautions_en`, `cautions_zh`, `part_uses`, `part_uses_en`, `part_uses_zh`, `created_at`, `updated_at`) VALUES ('2', '102', '002', '1020000002', 'ต้นหางนกยูงฝรั่ง', 'Royal Poinciana', '凤凰木', 'Flame Tree', 'Delonix regia', 'มีชื่อเสียงจากดอกสีแดงส้มสดใสในฤดูแล้ง', 'Famous for its brilliant red-orange flowers in the dry season.', '以旱季鲜艳的橙红色花朵而闻名。', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-08-15 17:10:59', '2026-08-15 17:10:59');
INSERT INTO `species` (`id`, `category_code`, `species_code`, `classification_id`, `name`, `name_en`, `name_zh`, `name_common`, `name_scientific`, `description`, `description_en`, `description_zh`, `care_instructions`, `care_instructions_en`, `care_instructions_zh`, `characteristics`, `characteristics_en`, `characteristics_zh`, `properties`, `properties_en`, `properties_zh`, `benefits`, `benefits_en`, `benefits_zh`, `cautions`, `cautions_en`, `cautions_zh`, `part_uses`, `part_uses_en`, `part_uses_zh`, `created_at`, `updated_at`) VALUES ('3', '102', '003', '1020000003', 'ต้นก้ามปู', 'Rain Tree', '雨树', 'Monkey Pod', 'Samanea saman', 'ไม้ทรงพุ่มขนาดใหญ่ที่ให้ร่มเงาทั่วสวน', 'A large canopy tree that provides shade across the park.', '一种为公园提供大面积遮荫的大型树冠树。', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-08-15 17:10:59', '2026-08-15 17:10:59');
INSERT INTO `species` (`id`, `category_code`, `species_code`, `classification_id`, `name`, `name_en`, `name_zh`, `name_common`, `name_scientific`, `description`, `description_en`, `description_zh`, `care_instructions`, `care_instructions_en`, `care_instructions_zh`, `characteristics`, `characteristics_en`, `characteristics_zh`, `properties`, `properties_en`, `properties_zh`, `benefits`, `benefits_en`, `benefits_zh`, `cautions`, `cautions_en`, `cautions_zh`, `part_uses`, `part_uses_en`, `part_uses_zh`, `created_at`, `updated_at`) VALUES ('4', '101', '101', NULL, 'กล้วยไม้แวนด้า', 'Vanda Orchid', '万代兰', NULL, 'Vanda sp.', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-08-15 17:12:31', '2026-08-15 17:12:31');
INSERT INTO `species` (`id`, `category_code`, `species_code`, `classification_id`, `name`, `name_en`, `name_zh`, `name_common`, `name_scientific`, `description`, `description_en`, `description_zh`, `care_instructions`, `care_instructions_en`, `care_instructions_zh`, `characteristics`, `characteristics_en`, `characteristics_zh`, `properties`, `properties_en`, `properties_zh`, `benefits`, `benefits_en`, `benefits_zh`, `cautions`, `cautions_en`, `cautions_zh`, `part_uses`, `part_uses_en`, `part_uses_zh`, `created_at`, `updated_at`) VALUES ('5', '101', '102', NULL, 'กล้วยไม้ฟาแลนอปซิส', 'Moth Orchid', '蝴蝶兰', NULL, 'Phalaenopsis amabilis', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-08-15 17:12:31', '2026-08-15 17:12:31');
INSERT INTO `species` (`id`, `category_code`, `species_code`, `classification_id`, `name`, `name_en`, `name_zh`, `name_common`, `name_scientific`, `description`, `description_en`, `description_zh`, `care_instructions`, `care_instructions_en`, `care_instructions_zh`, `characteristics`, `characteristics_en`, `characteristics_zh`, `properties`, `properties_en`, `properties_zh`, `benefits`, `benefits_en`, `benefits_zh`, `cautions`, `cautions_en`, `cautions_zh`, `part_uses`, `part_uses_en`, `part_uses_zh`, `created_at`, `updated_at`) VALUES ('6', '101', '103', NULL, 'กล้วยไม้แคทลียา', 'Cattleya Orchid', '卡特兰', NULL, 'Cattleya labiata', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-08-15 17:12:31', '2026-08-15 17:12:31');
INSERT INTO `species` (`id`, `category_code`, `species_code`, `classification_id`, `name`, `name_en`, `name_zh`, `name_common`, `name_scientific`, `description`, `description_en`, `description_zh`, `care_instructions`, `care_instructions_en`, `care_instructions_zh`, `characteristics`, `characteristics_en`, `characteristics_zh`, `properties`, `properties_en`, `properties_zh`, `benefits`, `benefits_en`, `benefits_zh`, `cautions`, `cautions_en`, `cautions_zh`, `part_uses`, `part_uses_en`, `part_uses_zh`, `created_at`, `updated_at`) VALUES ('7', '102', '004', NULL, 'ต้นประดู่', 'Burma Padauk', '缅甸紫檀', NULL, 'Pterocarpus macrocarpus', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-08-15 17:12:31', '2026-08-15 17:12:31');
INSERT INTO `species` (`id`, `category_code`, `species_code`, `classification_id`, `name`, `name_en`, `name_zh`, `name_common`, `name_scientific`, `description`, `description_en`, `description_zh`, `care_instructions`, `care_instructions_en`, `care_instructions_zh`, `characteristics`, `characteristics_en`, `characteristics_zh`, `properties`, `properties_en`, `properties_zh`, `benefits`, `benefits_en`, `benefits_zh`, `cautions`, `cautions_en`, `cautions_zh`, `part_uses`, `part_uses_en`, `part_uses_zh`, `created_at`, `updated_at`) VALUES ('8', '102', '005', NULL, 'ต้นสัก', 'Teak', '柚木', NULL, 'Tectona grandis', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-08-15 17:12:31', '2026-08-15 17:12:31');
INSERT INTO `species` (`id`, `category_code`, `species_code`, `classification_id`, `name`, `name_en`, `name_zh`, `name_common`, `name_scientific`, `description`, `description_en`, `description_zh`, `care_instructions`, `care_instructions_en`, `care_instructions_zh`, `characteristics`, `characteristics_en`, `characteristics_zh`, `properties`, `properties_en`, `properties_zh`, `benefits`, `benefits_en`, `benefits_zh`, `cautions`, `cautions_en`, `cautions_zh`, `part_uses`, `part_uses_en`, `part_uses_zh`, `created_at`, `updated_at`) VALUES ('9', '103', '103', NULL, 'เฟื่องฟ้า', 'Bougainvillea', '叶子花', NULL, 'Bougainvillea glabra', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-08-15 17:12:31', '2026-08-15 17:12:31');
INSERT INTO `species` (`id`, `category_code`, `species_code`, `classification_id`, `name`, `name_en`, `name_zh`, `name_common`, `name_scientific`, `description`, `description_en`, `description_zh`, `care_instructions`, `care_instructions_en`, `care_instructions_zh`, `characteristics`, `characteristics_en`, `characteristics_zh`, `properties`, `properties_en`, `properties_zh`, `benefits`, `benefits_en`, `benefits_zh`, `cautions`, `cautions_en`, `cautions_zh`, `part_uses`, `part_uses_en`, `part_uses_zh`, `created_at`, `updated_at`) VALUES ('10', '103', '104', NULL, 'ดาวเรือง', 'Marigold', '万寿菊', NULL, 'Tagetes erecta', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-08-15 17:12:32', '2026-08-15 17:12:32');
INSERT INTO `species` (`id`, `category_code`, `species_code`, `classification_id`, `name`, `name_en`, `name_zh`, `name_common`, `name_scientific`, `description`, `description_en`, `description_zh`, `care_instructions`, `care_instructions_en`, `care_instructions_zh`, `characteristics`, `characteristics_en`, `characteristics_zh`, `properties`, `properties_en`, `properties_zh`, `benefits`, `benefits_en`, `benefits_zh`, `cautions`, `cautions_en`, `cautions_zh`, `part_uses`, `part_uses_en`, `part_uses_zh`, `created_at`, `updated_at`) VALUES ('11', '103', '105', NULL, 'ทานตะวัน', 'Sunflower', '向日葵', NULL, 'Helianthus annuus', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-08-15 17:12:32', '2026-08-15 17:12:32');
INSERT INTO `species` (`id`, `category_code`, `species_code`, `classification_id`, `name`, `name_en`, `name_zh`, `name_common`, `name_scientific`, `description`, `description_en`, `description_zh`, `care_instructions`, `care_instructions_en`, `care_instructions_zh`, `characteristics`, `characteristics_en`, `characteristics_zh`, `properties`, `properties_en`, `properties_zh`, `benefits`, `benefits_en`, `benefits_zh`, `cautions`, `cautions_en`, `cautions_zh`, `part_uses`, `part_uses_en`, `part_uses_zh`, `created_at`, `updated_at`) VALUES ('12', '104', '101', NULL, 'โมก', 'Wrightia', '倒吊笔', NULL, 'Wrightia religiosa', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-08-15 17:12:32', '2026-08-15 17:12:32');
INSERT INTO `species` (`id`, `category_code`, `species_code`, `classification_id`, `name`, `name_en`, `name_zh`, `name_common`, `name_scientific`, `description`, `description_en`, `description_zh`, `care_instructions`, `care_instructions_en`, `care_instructions_zh`, `characteristics`, `characteristics_en`, `characteristics_zh`, `properties`, `properties_en`, `properties_zh`, `benefits`, `benefits_en`, `benefits_zh`, `cautions`, `cautions_en`, `cautions_zh`, `part_uses`, `part_uses_en`, `part_uses_zh`, `created_at`, `updated_at`) VALUES ('13', '104', '102', NULL, 'เข็มแดง', 'Ixora', '龙船花', NULL, 'Ixora coccinea', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-08-15 17:12:32', '2026-08-15 17:12:32');
INSERT INTO `species` (`id`, `category_code`, `species_code`, `classification_id`, `name`, `name_en`, `name_zh`, `name_common`, `name_scientific`, `description`, `description_en`, `description_zh`, `care_instructions`, `care_instructions_en`, `care_instructions_zh`, `characteristics`, `characteristics_en`, `characteristics_zh`, `properties`, `properties_en`, `properties_zh`, `benefits`, `benefits_en`, `benefits_zh`, `cautions`, `cautions_en`, `cautions_zh`, `part_uses`, `part_uses_en`, `part_uses_zh`, `created_at`, `updated_at`) VALUES ('14', '105', '101', NULL, 'พลูด่าง', 'Golden Pothos', '绿萝', NULL, 'Epipremnum aureum', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-08-15 17:12:32', '2026-08-15 17:12:32');
INSERT INTO `species` (`id`, `category_code`, `species_code`, `classification_id`, `name`, `name_en`, `name_zh`, `name_common`, `name_scientific`, `description`, `description_en`, `description_zh`, `care_instructions`, `care_instructions_en`, `care_instructions_zh`, `characteristics`, `characteristics_en`, `characteristics_zh`, `properties`, `properties_en`, `properties_zh`, `benefits`, `benefits_en`, `benefits_zh`, `cautions`, `cautions_en`, `cautions_zh`, `part_uses`, `part_uses_en`, `part_uses_zh`, `created_at`, `updated_at`) VALUES ('15', '105', '102', NULL, 'เถาวัลย์เปรียง', 'Grewia Vine', '扁担藤', NULL, 'Derris scandens', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-08-15 17:12:32', '2026-08-15 17:12:32');
INSERT INTO `species` (`id`, `category_code`, `species_code`, `classification_id`, `name`, `name_en`, `name_zh`, `name_common`, `name_scientific`, `description`, `description_en`, `description_zh`, `care_instructions`, `care_instructions_en`, `care_instructions_zh`, `characteristics`, `characteristics_en`, `characteristics_zh`, `properties`, `properties_en`, `properties_zh`, `benefits`, `benefits_en`, `benefits_zh`, `cautions`, `cautions_en`, `cautions_zh`, `part_uses`, `part_uses_en`, `part_uses_zh`, `created_at`, `updated_at`) VALUES ('16', '106', '101', NULL, 'บัวหลวง', 'Lotus', '莲花', NULL, 'Nelumbo nucifera', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-08-15 17:12:32', '2026-08-15 17:12:32');
INSERT INTO `species` (`id`, `category_code`, `species_code`, `classification_id`, `name`, `name_en`, `name_zh`, `name_common`, `name_scientific`, `description`, `description_en`, `description_zh`, `care_instructions`, `care_instructions_en`, `care_instructions_zh`, `characteristics`, `characteristics_en`, `characteristics_zh`, `properties`, `properties_en`, `properties_zh`, `benefits`, `benefits_en`, `benefits_zh`, `cautions`, `cautions_en`, `cautions_zh`, `part_uses`, `part_uses_en`, `part_uses_zh`, `created_at`, `updated_at`) VALUES ('17', '106', '102', NULL, 'บัวผัน', 'Water Lily', '睡莲', NULL, 'Nymphaea sp.', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-08-15 17:12:32', '2026-08-15 17:12:32');
INSERT INTO `species` (`id`, `category_code`, `species_code`, `classification_id`, `name`, `name_en`, `name_zh`, `name_common`, `name_scientific`, `description`, `description_en`, `description_zh`, `care_instructions`, `care_instructions_en`, `care_instructions_zh`, `characteristics`, `characteristics_en`, `characteristics_zh`, `properties`, `properties_en`, `properties_zh`, `benefits`, `benefits_en`, `benefits_zh`, `cautions`, `cautions_en`, `cautions_zh`, `part_uses`, `part_uses_en`, `part_uses_zh`, `created_at`, `updated_at`) VALUES ('18', '107', '101', NULL, 'หมากเหลือง', 'Golden Cane Palm', '散尾葵', NULL, 'Dypsis lutescens', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-08-15 17:12:32', '2026-08-15 17:12:32');
INSERT INTO `species` (`id`, `category_code`, `species_code`, `classification_id`, `name`, `name_en`, `name_zh`, `name_common`, `name_scientific`, `description`, `description_en`, `description_zh`, `care_instructions`, `care_instructions_en`, `care_instructions_zh`, `characteristics`, `characteristics_en`, `characteristics_zh`, `properties`, `properties_en`, `properties_zh`, `benefits`, `benefits_en`, `benefits_zh`, `cautions`, `cautions_en`, `cautions_zh`, `part_uses`, `part_uses_en`, `part_uses_zh`, `created_at`, `updated_at`) VALUES ('19', '107', '102', NULL, 'ตาลโตนด', 'Palmyra Palm', '糖棕', NULL, 'Borassus flabellifer', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-08-15 17:12:32', '2026-08-15 17:12:32');
INSERT INTO `species` (`id`, `category_code`, `species_code`, `classification_id`, `name`, `name_en`, `name_zh`, `name_common`, `name_scientific`, `description`, `description_en`, `description_zh`, `care_instructions`, `care_instructions_en`, `care_instructions_zh`, `characteristics`, `characteristics_en`, `characteristics_zh`, `properties`, `properties_en`, `properties_zh`, `benefits`, `benefits_en`, `benefits_zh`, `cautions`, `cautions_en`, `cautions_zh`, `part_uses`, `part_uses_en`, `part_uses_zh`, `created_at`, `updated_at`) VALUES ('20', '108', '101', NULL, 'เฟิร์นข้าหลวง', 'Bird\'s Nest Fern', '鸟巢蕨', NULL, 'Asplenium nidus', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-08-15 17:12:32', '2026-08-15 17:12:32');
INSERT INTO `species` (`id`, `category_code`, `species_code`, `classification_id`, `name`, `name_en`, `name_zh`, `name_common`, `name_scientific`, `description`, `description_en`, `description_zh`, `care_instructions`, `care_instructions_en`, `care_instructions_zh`, `characteristics`, `characteristics_en`, `characteristics_zh`, `properties`, `properties_en`, `properties_zh`, `benefits`, `benefits_en`, `benefits_zh`, `cautions`, `cautions_en`, `cautions_zh`, `part_uses`, `part_uses_en`, `part_uses_zh`, `created_at`, `updated_at`) VALUES ('21', '108', '102', NULL, 'เฟิร์นก้านดำ', 'Maidenhair Fern', '铁线蕨', NULL, 'Adiantum capillus-veneris', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-08-15 17:12:32', '2026-08-15 17:12:32');
INSERT INTO `species` (`id`, `category_code`, `species_code`, `classification_id`, `name`, `name_en`, `name_zh`, `name_common`, `name_scientific`, `description`, `description_en`, `description_zh`, `care_instructions`, `care_instructions_en`, `care_instructions_zh`, `characteristics`, `characteristics_en`, `characteristics_zh`, `properties`, `properties_en`, `properties_zh`, `benefits`, `benefits_en`, `benefits_zh`, `cautions`, `cautions_en`, `cautions_zh`, `part_uses`, `part_uses_en`, `part_uses_zh`, `created_at`, `updated_at`) VALUES ('22', '109', '101', NULL, 'มะพร้าว', 'Coconut', '椰子', NULL, 'Cocos nucifera', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-08-15 17:12:32', '2026-08-15 17:12:32');
INSERT INTO `species` (`id`, `category_code`, `species_code`, `classification_id`, `name`, `name_en`, `name_zh`, `name_common`, `name_scientific`, `description`, `description_en`, `description_zh`, `care_instructions`, `care_instructions_en`, `care_instructions_zh`, `characteristics`, `characteristics_en`, `characteristics_zh`, `properties`, `properties_en`, `properties_zh`, `benefits`, `benefits_en`, `benefits_zh`, `cautions`, `cautions_en`, `cautions_zh`, `part_uses`, `part_uses_en`, `part_uses_zh`, `created_at`, `updated_at`) VALUES ('23', '109', '102', NULL, 'ขนุน', 'Jackfruit', '菠萝蜜', NULL, 'Artocarpus heterophyllus', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-08-15 17:12:32', '2026-08-15 17:12:32');
INSERT INTO `species` (`id`, `category_code`, `species_code`, `classification_id`, `name`, `name_en`, `name_zh`, `name_common`, `name_scientific`, `description`, `description_en`, `description_zh`, `care_instructions`, `care_instructions_en`, `care_instructions_zh`, `characteristics`, `characteristics_en`, `characteristics_zh`, `properties`, `properties_en`, `properties_zh`, `benefits`, `benefits_en`, `benefits_zh`, `cautions`, `cautions_en`, `cautions_zh`, `part_uses`, `part_uses_en`, `part_uses_zh`, `created_at`, `updated_at`) VALUES ('24', '109', '103', NULL, 'มะม่วง', 'Mango', '芒果', NULL, 'Mangifera indica', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-08-15 17:12:32', '2026-08-15 17:12:32');
INSERT INTO `species` (`id`, `category_code`, `species_code`, `classification_id`, `name`, `name_en`, `name_zh`, `name_common`, `name_scientific`, `description`, `description_en`, `description_zh`, `care_instructions`, `care_instructions_en`, `care_instructions_zh`, `characteristics`, `characteristics_en`, `characteristics_zh`, `properties`, `properties_en`, `properties_zh`, `benefits`, `benefits_en`, `benefits_zh`, `cautions`, `cautions_en`, `cautions_zh`, `part_uses`, `part_uses_en`, `part_uses_zh`, `created_at`, `updated_at`) VALUES ('25', '110', '101', NULL, 'กระเพรา', 'Holy Basil', '圣罗勒', NULL, 'Ocimum tenuiflorum', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-08-15 17:12:32', '2026-08-15 17:12:32');
INSERT INTO `species` (`id`, `category_code`, `species_code`, `classification_id`, `name`, `name_en`, `name_zh`, `name_common`, `name_scientific`, `description`, `description_en`, `description_zh`, `care_instructions`, `care_instructions_en`, `care_instructions_zh`, `characteristics`, `characteristics_en`, `characteristics_zh`, `properties`, `properties_en`, `properties_zh`, `benefits`, `benefits_en`, `benefits_zh`, `cautions`, `cautions_en`, `cautions_zh`, `part_uses`, `part_uses_en`, `part_uses_zh`, `created_at`, `updated_at`) VALUES ('26', '110', '102', NULL, 'ขมิ้นชัน', 'Turmeric', '姜黄', NULL, 'Curcuma longa', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-08-15 17:12:32', '2026-08-15 17:12:32');
INSERT INTO `species` (`id`, `category_code`, `species_code`, `classification_id`, `name`, `name_en`, `name_zh`, `name_common`, `name_scientific`, `description`, `description_en`, `description_zh`, `care_instructions`, `care_instructions_en`, `care_instructions_zh`, `characteristics`, `characteristics_en`, `characteristics_zh`, `properties`, `properties_en`, `properties_zh`, `benefits`, `benefits_en`, `benefits_zh`, `cautions`, `cautions_en`, `cautions_zh`, `part_uses`, `part_uses_en`, `part_uses_zh`, `created_at`, `updated_at`) VALUES ('27', '110', '103', NULL, 'ฟ้าทะลายโจร', 'Andrographis', '穿心莲', NULL, 'Andrographis paniculata', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-08-15 17:12:32', '2026-08-15 17:12:32');

