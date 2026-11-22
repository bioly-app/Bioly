// The icon picker: library icons, platform logos and own icons.

import { el } from '../backend/dom.js';
import { iconLabel, paintIcon } from './drawer.js';
import { announce, fileButton, iconDialog, store, t, upload } from './state.js';

// ---------------------------------------------------------------- icon picker

// noOriginals: the favicon can use library and own icons, but no brand icons.
// onlyOwn: just the own icons of the media library (favicon "own SVG").
export const openIconPicker = (current, onSelect, { noOriginals = false, onlyOwn = false, style = '' } = {}) => {
  const picker = iconDialog.querySelector('[data-icon-picker]');
  // Tabs: library icons, platform logos (brand color or matching the design) and own icons.
  // A logo exists twice: orig:<name> in its brand color and std:<name> in a color folder.
  const currentIcon = store.meta.icons.find((item) => item.id === current);
  const startSource = onlyOwn || current.startsWith('own:') ? 'own'
    : (!noOriginals && (current.startsWith('orig:') || currentIcon?.brand) ? 'brand' : 'std');
  const view = { source: startSource, variant: currentIcon?.brand && current.startsWith('std:') ? 'design' : 'color', color: '', query: '', selected: current, uploadColor: '' };
  const sources = onlyOwn ? [['own', t('editor.icon.tab.own')]]
    : [['std', t('editor.icon.tab.std')], ...(noOriginals ? [] : [['brand', t('editor.icon.tab.brand')]]), ['own', t('editor.icon.tab.own')]];
  const inTab = (icon) => {
    if (view.source === 'brand') {
      return view.variant === 'color' ? icon.source === 'orig' : icon.brand && icon.source === 'std';
    }
    if (view.source === 'std') {
      return icon.source === 'std' && !icon.brand;
    }
    return icon.source === view.source;
  };
  // Switching the variant keeps the chosen logo selected in its other version.
  const switchVariant = (variant) => {
    const name = view.selected.replace(/^(std|orig):/, '');
    view.variant = variant;
    const twin = store.meta.icons.find((icon) => icon.brand && icon.id === `${variant === 'color' ? 'orig' : 'std'}:${name}`);
    if (twin) {
      view.selected = twin.id;
    }
    render();
  };

  // Upload in the "own" tab: SVG file, one palette color or its own colors.
  const ownUpload = () => {
    const message = el('p', { class: 'field__error', role: 'alert', hidden: true });
    const color = el('select', { class: 'input', id: 'picker-upload-color', onchange: (event) => { view.uploadColor = event.target.value; } },
      el('option', { value: '', text: t('media.icon.original') }),
      ...Object.keys(store.meta.palette).map((name) => el('option', { value: name, selected: view.uploadColor === name, text: t(`color.${name}`) })));
    const button = fileButton(t('media.icon.upload'), '.svg,image/svg+xml', async (file) => {
      message.hidden = true;
      const result = await upload('icon', file, { color: view.uploadColor });
      if (!result.ok) {
        message.textContent = result.error;
        message.hidden = false;
        return;
      }
      store.meta.icons = result.icons;
      view.selected = result.icon;
      view.query = '';
      render();
      announce(t('media.icon.uploaded'));
    });
    return el('div', { class: 'stack stack--tight' },
      el('div', { class: 'picker-upload' },
        el('div', { class: 'field' },
          el('label', { class: 'field__label', for: 'picker-upload-color', text: t('media.icon.color') }), color),
        button),
      el('p', { class: 'field__help' }, t('media.icon.color.help'), ' ',
        el('a', { href: '/admin/media?tab=icons', target: '_blank', rel: 'noopener', text: t('media.manage') })),
      message);
  };

  const render = () => {
    const tabs = el('div', { class: 'tabs', role: 'tablist', 'aria-label': t('editor.icon.title') },
      ...sources.map(([source, label]) => el('button', {
        class: 'tabs__tab', type: 'button', role: 'tab', 'aria-selected': String(view.source === source),
        onclick: () => { view.source = source; view.color = ''; render(); },
      }, label)));

    const search = el('input', {
      class: 'input', type: 'search', value: view.query, 'aria-label': t('editor.icon.search'), placeholder: t('editor.icon.search'),
      oninput: (event) => { view.query = event.target.value; renderGrid(); },
    });

    const filters = view.source === 'std' ? el('div', { class: 'icon-picker__filters', role: 'group', 'aria-label': t('editor.icon.color') },
      el('span', { class: 'muted small', text: t('editor.icon.color') }),
      el('button', { class: 'chip', type: 'button', 'aria-pressed': String(view.color === ''), onclick: () => { view.color = ''; render(); } }, t('editor.icon.all')),
      ...Object.entries(store.meta.palette).map(([name, hex]) => {
        const dot = el('span', { class: 'chip__dot', 'aria-hidden': 'true' });
        dot.style.background = hex;
        return el('button', { class: 'chip', type: 'button', 'aria-pressed': String(view.color === name), 'aria-label': name, onclick: () => { view.color = name; render(); } }, dot);
      })) : view.source === 'own' ? ownUpload() : el('div', { class: 'stack stack--tight' },
      el('div', { class: 'segmented segmented--small', role: 'group', 'aria-label': t('editor.icon.variant') },
        ...[['color', t('editor.icon.variant.color')], ['design', t('editor.icon.variant.design')]].map(([variant, label]) => el('button', {
          class: 'segmented__item', type: 'button', 'aria-pressed': String(view.variant === variant), onclick: () => switchVariant(variant),
        }, label))),
      el('p', { class: 'muted small', text: view.variant === 'color' ? t('editor.icon.variant.color.help') : t('editor.icon.variant.design.help', { style: t(`design.icon_style.${store.draft.theme.icon_style}`) }) }));

    const grid = el('ul', { class: 'icon-grid' });
    const chosen = el('span', { class: 'muted', text: iconLabel(view.selected) });
    const renderGrid = () => {
      const visible = store.meta.icons.filter((icon) => inTab(icon)
        && (view.color === '' || icon.color === view.color)
        && icon.label.toLowerCase().includes(view.query.toLowerCase()));
      const empty = view.source === 'own' && view.query === '' ? t('editor.icon.own_empty') : t('editor.icon.empty');
      grid.replaceChildren(...(visible.length === 0 ? [el('li', { class: 'muted', text: empty })] : visible.map((icon) => {
        const glyph = el('span', { class: 'icon-tile__glyph' });
        paintIcon(glyph, icon.id, style);
        return el('li', {}, el('button', {
          class: 'icon-tile', type: 'button', 'aria-pressed': String(view.selected === icon.id),
          onclick: () => { view.selected = icon.id; chosen.textContent = icon.label; renderGrid(); },
          ondblclick: () => { onSelect(icon.id); iconDialog.close(); },
        }, glyph, icon.label));
      })));
    };
    renderGrid();

    const footer = el('div', { class: 'icon-picker__footer' },
      chosen,
      el('span', { class: 'row' },
        el('button', { class: 'button button--ghost', type: 'button', onclick: () => iconDialog.close() }, t('common.cancel')),
        el('button', { class: 'button button--primary', type: 'button', onclick: () => { onSelect(view.selected); iconDialog.close(); } }, t('editor.icon.select'))));

    picker.replaceChildren(tabs, search, filters, grid, footer);
  };

  render();
  iconDialog.showModal();
};
