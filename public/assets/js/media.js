// Media library: colors the icon previews like on the page.
// The page works without this script too.

const grid = document.querySelector('[data-media-palette]');
let palette = {};
try {
  palette = JSON.parse(grid?.dataset.mediaPalette || '{}');
} catch {
  palette = {};
}

// Icons with a palette color are drawn in that color; icons with own colors stay as they are.
const paint = (preview, color) => {
  preview.dataset.color = color;
  preview.style.color = palette[color] || '';
  preview.classList.toggle('media-card__icon--original', !palette[color]);
};

document.querySelectorAll('[data-media-preview]').forEach((preview) => {
  paint(preview, preview.dataset.color || '');
  const select = preview.closest('.media-card')?.querySelector('[data-media-color]');
  // The stored file keeps its colors; the one-color look only applies after saving,
  // so the preview only follows the select while nothing would be lost.
  select?.addEventListener('change', () => {
    if (preview.dataset.original !== 'true' || select.value === '') {
      paint(preview, select.value);
    }
  });
  preview.dataset.original = preview.dataset.color === '' ? 'true' : 'false';
});
