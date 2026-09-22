-- Marketplace lead capture, kept separate from tree_interests so existing
-- admin interest reports are unaffected. Depends on 0011's shops/products
-- tables.
--
-- Run against an existing tree_qr_system database that predates this table
-- (a fresh install via docs/install.sql already includes it).
USE tree_qr_system;

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
