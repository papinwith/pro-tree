-- Adds a real completed-sale record, as opposed to nursery_stock (current
-- on-hand listing/asking price) or tree_interests (a lead that may or may
-- not have bought anything). This intentionally goes beyond the "no real
-- order system in Phase 1" scope noted in docs/rbac.md and migration 0011
-- — added on explicit request, not a silent scope change.
--
-- Run against an existing tree_qr_system database that predates this
-- change (a fresh install via docs/install.sql already includes it).
USE tree_qr_system;

CREATE TABLE sale_transactions (
    id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    species_id     BIGINT UNSIGNED NOT NULL,
    size_id        INT UNSIGNED NULL,
    quantity       INT UNSIGNED NOT NULL DEFAULT 1,
    unit_price     DECIMAL(10,2) NOT NULL,
    total_price    DECIMAL(10,2) NOT NULL,
    interest_id    BIGINT UNSIGNED NULL,
    sold_by        VARCHAR(100) NULL,
    notes          TEXT NULL,
    sold_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_sales_species  FOREIGN KEY (species_id)  REFERENCES species(id) ON DELETE CASCADE,
    CONSTRAINT fk_sales_size     FOREIGN KEY (size_id)     REFERENCES stock_sizes(id),
    CONSTRAINT fk_sales_interest FOREIGN KEY (interest_id) REFERENCES tree_interests(id) ON DELETE SET NULL,
    INDEX idx_sales_species (species_id),
    INDEX idx_sales_sold_at (sold_at)
) ENGINE=InnoDB;
