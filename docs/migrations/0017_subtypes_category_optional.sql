-- The "เพิ่มชนิด" (add subtype) admin form now only asks for a name — no
-- category picker — so a subtype can exist before it's linked to a
-- category. The link gets made the first time it's actually used for a
-- species (species_form.php assigns it then) or set explicitly by editing
-- the subtype. category_code must therefore be nullable.
--
-- Run against an existing tree_qr_system database that predates this
-- change (a fresh install via docs/install.sql already includes it).
USE tree_qr_system;

ALTER TABLE subtypes
  MODIFY COLUMN category_code CHAR(3) NULL;
