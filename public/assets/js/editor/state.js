// Shared state of the page editor: the draft, data from the server, page elements and small helpers.

import { send } from '../backend/api.js';
import { el } from '../backend/dom.js';
import { guardDraft } from '../backend/unsaved.js';

export const store = { meta: {}, draft: {}, published: '', errors: {} };

export const root = document.querySelector('[data-editor]');
// "page": modules and header; "general": design and favicon. Both publish the same store.draft.
export const mode = root.dataset.mode ?? 'page';
export const blocksList = root.querySelector('[data-blocks]');
export const unsavedBar = root.querySelector('[data-unsaved]');
export const statusBox = root.querySelector('[data-status]');
export const preview = document.querySelector('[data-preview]');
export const drawer = document.querySelector('[data-drawer]');
export const drawerForm = drawer.querySelector('[data-drawer-form]');
export const addDialog = document.querySelector('[data-add-dialog]');
export const iconDialog = document.querySelector('[data-icon-dialog]');
export const imageDialog = document.querySelector('[data-image-dialog]');

export let counter = 0;

// ---------------------------------------------------------------- helpers

export const t = (key, params = {}) => {
  let text = store.meta.texts?.[key] ?? key;
  Object.entries(params).forEach(([name, value]) => {
    text = text.replaceAll(`{${name}}`, value);
  });
  return text;
};

// Backend icons come from <template data-ui-icon> in editor.twig (Lucide, resources/ui-icons).
export const svgIcon = (name) => document.querySelector(`template[data-ui-icon="${name}"]`)?.content.firstElementChild?.cloneNode(true) ?? document.createTextNode('');

export const upload = async (kind, file, extra = {}) => {
  const limit = store.meta.uploadLimits?.[kind === 'image' ? 'image' : 'svg'];
  if (limit && file.size > limit) {
    return { ok: false, error: t(kind === 'image' ? 'upload.too_large' : 'upload.favicon.too_large') };
  }
  const body = new FormData();
  body.append('file', file);
  Object.entries(extra).forEach(([key, value]) => body.append(key, value));
  try {
    const { ok, data } = await send(`/admin/api/upload/${kind}`, body);
    return ok ? { ...data, ok: true } : { ok: false, error: data.error === 'upload' && data.message ? data.message : t('upload.failed') };
  } catch {
    return { ok: false, error: t('upload.failed') };
  }
};

// A hidden file input opened by a normal button, so it looks and works like the other buttons.
export const fileButton = (label, accept, onFile) => {
  const input = el('input', { type: 'file', accept, class: 'visually-hidden', tabindex: '-1', 'aria-hidden': 'true' });
  const button = el('button', { class: 'button', type: 'button', onclick: () => input.click() }, svgIcon('upload'), label);
  input.addEventListener('change', async () => {
    const [file] = input.files;
    input.value = '';
    if (!file) {
      return;
    }
    button.disabled = true;
    button.setAttribute('aria-busy', 'true');
    try {
      await onFile(file);
    } finally {
      button.disabled = false;
      button.removeAttribute('aria-busy');
    }
  });
  return el('span', {}, input, button);
};

export const snapshot = () => JSON.stringify({ profile: store.draft.profile, theme: store.draft.theme, blocks: store.draft.blocks });
export const isDirty = () => snapshot() !== store.published;
export const guard = guardDraft({ bar: unsavedBar, isDirty });
export const nextKey = () => `n${Date.now().toString(36)}${(counter += 1)}`;

export const announce = (message) => {
  statusBox.textContent = '';
  window.setTimeout(() => {
    statusBox.textContent = message;
  }, 50);
};

export const listAt = (path) => (path.length === 1 ? store.draft.blocks : store.draft.blocks[path[0]].children);
export const blockAt = (path) => listAt(path)[path[path.length - 1]];

export const blockTitle = (block) => {
  const module = store.meta.modules[block.type];
  if (!module) {
    return t('editor.unknown');
  }
  if (block.type === 'image' && !block.data.title) {
    let count = 0;
    try {
      count = JSON.parse(block.data.images || '[]').length;
    } catch {
      count = 0;
    }
    return count < 2 ? t('module.image.name') : t('module.image.count', { n: count });
  }
  return block.data.title || (block.type === 'link' ? block.data.url : '') || t('editor.untitled');
};


export const focusLater = (id) => window.requestAnimationFrame(() => root.querySelector(`[data-focus="${id}"]`)?.focus());

export const readableOn = (hex) => {
  const [r, g, b] = [1, 3, 5].map((i) => parseInt(hex.slice(i, i + 2), 16) / 255)
    .map((c) => (c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4));
  return 0.2126 * r + 0.7152 * g + 0.0722 * b > 0.179 ? '#18181B' : '#FFFFFF';
};
