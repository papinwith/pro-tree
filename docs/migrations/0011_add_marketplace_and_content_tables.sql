-- Net-new tables backing the expo frontend's home-page highlights/news and
-- the marketplace catalog — none of this existed in either prior schema.
-- Deliberately minimal: no payment/checkout, no stock decrement. `sold` and
-- `compare_at_price` on products are display-only counters, not derived
-- from any real order system (there isn't one in Phase 1).
--
-- Run against an existing tree_qr_system database that predates these
-- tables (a fresh install via docs/install.sql already includes them).
USE tree_qr_system;

CREATE TABLE highlights (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title       VARCHAR(200) NOT NULL,
    title_en    VARCHAR(200) NULL,
    title_zh    VARCHAR(200) NULL,
    event_date  DATE NOT NULL,
    event_time  VARCHAR(20) NULL,      -- free text e.g. "10:00" — not every highlight has a fixed time
    zone_id     BIGINT UNSIGNED NULL,
    image_path  VARCHAR(255) NULL,
    sort_order  INT NOT NULL DEFAULT 0,
    is_active   TINYINT(1) NOT NULL DEFAULT 1,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_highlights_zone FOREIGN KEY (zone_id) REFERENCES zones(id) ON DELETE SET NULL,
    INDEX idx_highlights_date (event_date, is_active)
) ENGINE=InnoDB;

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

CREATE TABLE shops (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(150) NOT NULL,
    zone_id     BIGINT UNSIGNED NULL,
    rating      DECIMAL(2,1) NULL,
    review_count INT UNSIGNED NOT NULL DEFAULT 0,
    about       TEXT NULL,
    banner_path VARCHAR(255) NULL,
    is_active   TINYINT(1) NOT NULL DEFAULT 1,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_shops_zone FOREIGN KEY (zone_id) REFERENCES zones(id) ON DELETE SET NULL,
    INDEX idx_shops_zone (zone_id, is_active)
) ENGINE=InnoDB;

CREATE TABLE products (
    id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    shop_id           BIGINT UNSIGNED NOT NULL,
    species_id        BIGINT UNSIGNED NULL, -- optional link back to a real species
    name              VARCHAR(200) NOT NULL,
    price             DECIMAL(10,2) NOT NULL,
    compare_at_price  DECIMAL(10,2) NULL,   -- display-only "was" price, not a real discount engine
    image_path        VARCHAR(255) NULL,
    sold_count        INT UNSIGNED NOT NULL DEFAULT 0, -- display counter, not decremented by any order flow
    tag               VARCHAR(50) NULL,     -- e.g. "New", "Best Seller" — free text, admin-set
    is_active         TINYINT(1) NOT NULL DEFAULT 1,
    created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_products_shop    FOREIGN KEY (shop_id)    REFERENCES shops(id)   ON DELETE CASCADE,
    CONSTRAINT fk_products_species FOREIGN KEY (species_id) REFERENCES species(id) ON DELETE SET NULL,
    INDEX idx_products_shop (shop_id, is_active)
) ENGINE=InnoDB;
