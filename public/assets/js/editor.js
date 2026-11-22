// Bioly page editor. The draft lives only here in the browser:
// reloading or leaving discards it, "Publish" sends it to the server.
// The parts live in editor/; this file connects tabs, publishing and loading.

import { api } from './backend/api.js';
import { openAddDialog, renderBlocks } from './editor/blocks.js';
import { renderDesign } from './editor/design.js';
import { closeDrawer } from './editor/drawer.js';
import { renderHeader } from './editor/header.js';
import { changed } from './editor/preview.js';
import { announce, guard, preview, root, snapshot, store, t } from './editor/state.js';

// ---------------------------------------------------------------- tabs

const tabs = [...root.querySelectorAll('[data-tab]')];
const selectTab = (tab) => {
  tabs.forEach((other) => {
    const active = other === tab;
    other.setAttribute('aria-selected', String(active));
    other.tabIndex = active ? 0 : -1;
    root.querySelector(`[data-panel="${other.dataset.tab}"]`).hidden = !active;
  });
  tab.focus();
};
tabs.forEach((tab, index) => {
  tab.addEventListener('click', () => selectTab(tab));
  tab.addEventListener('keydown', (event) => {
    const step = { ArrowRight: 1, ArrowLeft: -1 }[event.key];
    if (step) {
      event.preventDefault();
      selectTab(tabs[(index + step + tabs.length) % tabs.length]);
    }
  });
});

// ---------------------------------------------------------------- publish, discard, load

const applyState = (state) => {
  const { profile, theme, blocks, ...rest } = state;
  store.meta = { ...store.meta, ...rest };
  store.draft = { profile, theme, blocks };
  store.published = snapshot();
  store.errors = {};
  guard.update();
  renderBlocks();
  renderHeader();
  renderDesign();
};

let publishing = false;

const publish = async () => {
  if (publishing) {
    return;
  }
  publishing = true;
  const button = root.querySelector('[data-action="publish"]');
  button.disabled = true;
  button.setAttribute('aria-busy', 'true');
  try {
    await sendPublish();
  } finally {
    publishing = false;
    button.disabled = false;
    button.removeAttribute('aria-busy');
  }
};

const sendPublish = async () => {
  const { ok, data } = await api('/admin/api/publish', { profile: store.draft.profile, theme: store.draft.theme, blocks: store.draft.blocks });
  if (ok) {
    applyState(data.state);
    preview.src = `/admin/preview?t=${Date.now()}`;
    announce(t('editor.published'));
    return;
  }
  if (data.error === 'invalid') {
    store.errors = {};
    (data.fields ?? []).forEach((error) => {
      store.errors[error.key] = { ...(store.errors[error.key] ?? {}), [error.field || '_']: error.message };
    });
    renderBlocks();
    announce(t('editor.publish_failed'));
    return;
  }
  announce(t('editor.error'));
};

root.addEventListener('click', (event) => {
  const action = event.target.closest('[data-action]')?.dataset.action;
  if (action === 'add') {
    openAddDialog();
  } else if (action === 'publish') {
    publish();
  } else if (action === 'discard' && window.confirm(t('editor.discard.confirm'))) {
    applyState({ ...JSON.parse(store.published) });
    changed();
  }
});

document.querySelector('[data-action="close-drawer"]').addEventListener('click', closeDrawer);
document.querySelectorAll('dialog [data-action="close-dialog"]').forEach((button) => {
  button.addEventListener('click', () => button.closest('dialog').close());
});


const load = async () => {
  const { ok, data } = await api('/admin/api/state');
  if (!ok) {
    announce(t('editor.error'));
    return;
  }
  store.meta.defaultTheme = data.defaultTheme;
  applyState(data);
};

load();
