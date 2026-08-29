-- Categories had no admin "add" page — the 10 seeded rows were the only
-- ones that could ever exist. This makes name_en/name_zh nullable (like
-- species/zones already are) so a new category can be created with just a
-- Thai name — EN/ZH are generated automatically on first public view in
-- that language, same lazy-cache pattern as species/zones.
--
-- Run against an existing tree_qr_system database that predates this
-- change (a fresh install via docs/install.sql already includes it).
USE tree_qr_system;

ALTER TABLE categories
  MODIFY COLUMN name_en VARCHAR(100) NULL,
  MODIFY COLUMN name_zh VARCHAR(100) NULL;
