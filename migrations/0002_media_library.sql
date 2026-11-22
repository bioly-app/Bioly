-- 0.1.3: media library. Images and own icons get a row each; icons get a display name.
-- usage: 'content' = image of the library, 'icon' = own icon (color NULL = keeps its own colors).
ALTER TABLE media ADD COLUMN label TEXT;

CREATE INDEX media_usage ON media (usage, created_at);
