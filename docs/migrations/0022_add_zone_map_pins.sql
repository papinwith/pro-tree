-- Lets an admin drop one pin per zone on the public map banner image
-- (position stored as a percentage of the image, not real GPS — there are
-- too many individual trees to pin one-by-one, so pins are per-zone).
--
-- Run against an existing tree_qr_system database that predates this
-- change (a fresh install via docs/install.sql already includes it).
USE tree_qr_system;

ALTER TABLE zones
  ADD COLUMN map_pin_x DECIMAL(5,2) NULL COMMENT 'Pin X position, % from left of the map banner image',
  ADD COLUMN map_pin_y DECIMAL(5,2) NULL COMMENT 'Pin Y position, % from top of the map banner image';
