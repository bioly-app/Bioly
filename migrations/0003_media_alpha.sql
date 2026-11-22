-- Images with transparent parts (for example logos as PNG) are shown without a
-- background color and are never cropped.
ALTER TABLE media ADD COLUMN has_alpha INTEGER NOT NULL DEFAULT 0;
