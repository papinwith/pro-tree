-- A photo for the species itself (admin/species_form.php), as opposed to
-- trees.image_path which is a photo of one specific planted tree. The public
-- tree page falls back to this when the individual tree has no photo of its
-- own, so a bulk-created batch of trees still shows a picture.
--
-- Run against an existing tree_qr_system database that predates this
-- change (a fresh install via docs/install.sql already includes it).
USE tree_qr_system;

ALTER TABLE species
  ADD COLUMN image_path VARCHAR(255) NULL AFTER name_scientific;
