-- Images uploaded before 0.1.5 already had an average color, so the transparency check
-- of 0003 skipped them. Clearing the color makes the media library check every image once more.
UPDATE media SET color = NULL WHERE usage = 'content' AND has_alpha = 0;
