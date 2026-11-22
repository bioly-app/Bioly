// Reacting to changes: block list and live preview.

import { api } from '../backend/api.js';
import { renderBlocks } from './blocks.js';
import { redrawFavicon, renderWarnings } from './design.js';
import { repaintIcons } from './drawer.js';
import { guard, preview, store } from './state.js';

// ---------------------------------------------------------------- change handling

export let previewTimer = null;

export const changed = ({ list = true } = {}) => {
  guard.update();
  if (list) {
    renderBlocks();
  }
  window.clearTimeout(previewTimer);
  previewTimer = window.setTimeout(refreshPreview, 350);
};

export const refreshPreview = async () => {
  try {
    const { data } = await api('/admin/api/preview', { profile: store.draft.profile, theme: store.draft.theme, blocks: store.draft.blocks });
    renderWarnings(data.warnings ?? []);
    if (data.look) {
      store.meta.look = data.look;
      repaintIcons();
      redrawFavicon();
    }
    preview.src = `/admin/preview?t=${Date.now()}`;
  } catch {
    // The preview catches up with the next change.
  }
};
