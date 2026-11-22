-- Whether an image is decoration is decided by the place it is used in (template),
-- not per image. Images that were marked as decoration have no alt text and show
-- the "no alt text yet" hint again where an alt text is needed.
ALTER TABLE media DROP COLUMN is_decorative;
