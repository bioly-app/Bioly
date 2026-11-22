# Patterns

Patterns for the header. One SVG per pattern plus an entry in `patterns.json`:

```json
[{ "id": "dots", "size": 24 }]
```

- `id`: file name without `.svg`, only `a-z`, `0-9` and `-`
- `size`: edge length of the tile in px (the same as `width` and `height` in the SVG)
- Name in the backend: `design.pattern.<id>` in `lang/*.json`

Requirements: one color (any), seamless square tile, no text, no embedded images.
Bioly colors them with CSS `mask-image`, so the color in the file does not matter.
Shapes that cross the edge are drawn on the opposite side as well; the viewBox cuts them.
