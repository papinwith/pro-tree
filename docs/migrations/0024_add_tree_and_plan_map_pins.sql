-- Click-to-place % pins on the same banner image zones already use
-- (docs/migrations/0022_add_zone_map_pins.sql), extended to individual trees
-- and planting plans. This is purely a visual "point on the banner" — it
-- is NOT a replacement for trees.latitude/longitude or
-- planting_plans.latitude/longitude (real surveyed GPS, still entered
-- manually); a click on a static, non-georeferenced banner image can only
-- ever produce a position on that image, never real GPS degrees. Both
-- stay optional and independent.
--
-- Run against an existing tree_qr_system database that predates this
-- change (a fresh install via docs/install.sql already includes it).
USE tree_qr_system;

ALTER TABLE trees
  ADD COLUMN map_pin_x DECIMAL(5,2) NULL COMMENT 'Pin X position, % from left of the map banner image',
  ADD COLUMN map_pin_y DECIMAL(5,2) NULL COMMENT 'Pin Y position, % from top of the map banner image';

ALTER TABLE planting_plans
  ADD COLUMN map_pin_x DECIMAL(5,2) NULL COMMENT 'Pin X position, % from left of the map banner image',
  ADD COLUMN map_pin_y DECIMAL(5,2) NULL COMMENT 'Pin Y position, % from top of the map banner image';
