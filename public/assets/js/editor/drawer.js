// The drawer with the edit form of one block, and the icon look like on the page.

import { el } from '../backend/dom.js';
import { renderBlocks } from './blocks.js';
import { openImagePicker } from './header.js';
import { openIconPicker } from './icon-picker.js';
import { changed } from './preview.js';
import { blockAt, blocksList, drawer, drawerForm, focusLater, iconDialog, imageDialog, listAt, store, svgIcon, t } from './state.js';

// ---------------------------------------------------------------- drawer (edit form)

export let drawerState = null;

export const fieldId = (name) => `d-${name}`;

export const fieldWrap = (field, control, error) => el('div', { class: `field${error ? ' field--error' : ''}` },
  el('label', { class: 'field__label', for: fieldId(field.name) }, field.label, field.required ? el('span', { class: 'field__required', 'aria-hidden': 'true', text: ' *' }) : null),
  control,
  error ? el('p', { class: 'field__error', id: `${fieldId(field.name)}-error`, text: error }) : null,
  field.help ? el('p', { class: 'field__help', id: `${fieldId(field.name)}-help`, text: field.help }) : null);

export const describedBy = (field, error) => [error ? `${fieldId(field.name)}-error` : null, field.help ? `${fieldId(field.name)}-help` : null].filter(Boolean).join(' ') || null;

export const iconLabel = (id) => store.meta.icons.find((icon) => icon.id === id)?.label ?? t('editor.icon.none');

// ---------------------------------------------------------------- icon look (same as on the page)

// The icon style that applies: the one of the module being edited, otherwise the global one.
export const drawerIconStyle = () => drawerForm.elements.icon_style?.value || store.draft.theme.icon_style;

// Original brand icons and own icons without a palette color keep their own colors.
export const keepsColors = (icon) => icon.source === 'orig' || (icon.source === 'own' && !icon.color);

// Colors of an icon tile as site.css draws it, with the page colors from the server (store.meta.look).
export const iconColors = (icon, style) => {
  const { look } = store.meta;
  if (!icon || keepsColors(icon)) {
    return { color: look.text, wash: 6, surface: look.surface };
  }
  if (style === 'mono') {
    return { color: look.text, wash: 8, surface: look.surface };
  }
  if (style === 'accent') {
    return { color: look.accent, wash: 16, surface: look.surface };
  }
  return { color: look.palette[icon.color] ?? look.accent, wash: 16, surface: look.surface };
};

// Paints node as a small piece of the page: card surface, tinted square, icon.
// styleSource: "drawer" (style of the open module), "" (global) or a fixed style.
export const paintIcon = (node, id, styleSource = '') => {
  const icon = store.meta.icons.find((item) => item.id === id);
  const style = styleSource === 'drawer' ? drawerIconStyle() : (styleSource || store.draft.theme.icon_style);
  const { color, wash, surface } = iconColors(icon, style);
  node.dataset.lookIcon = id;
  node.dataset.lookStyle = styleSource;
  node.classList.add('icon-swatch');
  node.style.background = surface;
  if (!icon) {
    node.replaceChildren();
    return;
  }
  const box = el('span', { class: 'icon-swatch__box', html: icon.svg });
  box.style.color = keepsColors(icon) ? '' : color;
  box.style.background = `color-mix(in srgb, ${color} ${wash}%, transparent)`;
  node.replaceChildren(box);
};

export const repaintIcons = () => {
  document.querySelectorAll('[data-look-icon]').forEach((node) => paintIcon(node, node.dataset.lookIcon, node.dataset.lookStyle));
};

export const iconControl = (field, value) => {
  const input = el('input', { type: 'hidden', name: field.name, value });
  const glyph = el('span', { class: 'icon-field__preview', 'aria-hidden': 'true' });
  const name = el('span', { class: 'icon-field__name', text: iconLabel(value) });
  const update = (id) => {
    input.value = id;
    name.textContent = iconLabel(id);
    paintIcon(glyph, id, 'drawer');
  };
  paintIcon(glyph, value, 'drawer');
  return el('div', { class: 'icon-field' }, input, glyph, name,
    el('button', { class: 'button', type: 'button', id: fieldId(field.name), 'aria-haspopup': 'dialog', onclick: () => openIconPicker(input.value, update, { style: drawerIconStyle() }) }, t('editor.icon.choose')),
    el('button', { class: 'button button--ghost', type: 'button', onclick: () => update('') }, t('editor.icon.remove')));
};

// Image list of the image module: library images in order, each with an optional link.
// The value travels as JSON in a hidden field, like the server stores it.
export const imagesControl = (field, value) => {
  let list = [];
  try {
    list = JSON.parse(value || '[]');
  } catch {
    list = [];
  }
  const input = el('input', { type: 'hidden', name: field.name });
  const items = el('ol', { class: 'images-field__list' });
  const sync = () => { input.value = list.length ? JSON.stringify(list) : ''; };

  const render = () => {
    sync();
    items.replaceChildren(...list.map((entry, index) => {
      const image = (store.meta.images ?? []).find((item) => item.file === entry.file);
      const linkId = `${fieldId(field.name)}-link-${index}`;
      return el('li', { class: 'images-field__item' },
        image ? el('img', { class: `images-field__thumb${image.transparent ? ' is-transparent' : ''}`, src: image.url, alt: '', width: '64', height: '64' }) : el('span', { class: 'images-field__thumb images-field__thumb--missing', text: '?' }),
        el('div', { class: 'images-field__body' },
          el('span', { class: 'images-field__name', text: image ? (image.label || image.file) : t('module.image.missing') }),
          image && !image.alt ? el('span', { class: 'images-field__alt images-field__alt--missing' },
            t('module.image.no_alt'), ' ',
            el('a', { href: '/admin/media', target: '_blank', rel: 'noopener', text: t('module.image.alt_edit') })) : null,
          el('label', { class: 'visually-hidden', for: linkId, text: t('module.image.link') }),
          el('input', {
            class: 'input', id: linkId, type: 'text', inputmode: 'url', autocomplete: 'off', placeholder: t('module.image.link'), value: entry.url ?? '',
            oninput: (event) => { entry.url = event.target.value.trim(); sync(); },
          })),
        el('span', { class: 'images-field__actions' },
          el('button', { class: 'icon-button', type: 'button', 'aria-label': `${t('editor.up')} (${index + 1})`, disabled: index === 0, onclick: () => { list.splice(index - 1, 0, ...list.splice(index, 1)); render(); } }, svgIcon('up')),
          el('button', { class: 'icon-button', type: 'button', 'aria-label': `${t('editor.down')} (${index + 1})`, disabled: index === list.length - 1, onclick: () => { list.splice(index + 1, 0, ...list.splice(index, 1)); render(); } }, svgIcon('down')),
          el('button', { class: 'icon-button', type: 'button', 'aria-label': `${t('module.image.remove')} (${index + 1})`, onclick: () => { list.splice(index, 1); render(); } }, svgIcon('trash'))));
    }));
  };

  const add = el('button', {
    class: 'button', type: 'button', id: fieldId(field.name), 'aria-haspopup': 'dialog', disabled: list.length >= 12,
    onclick: () => openImagePicker('', (file) => {
      if (file && list.length < 12) {
        list.push({ file, url: '' });
        render();
        add.disabled = list.length >= 12;
      }
    }),
  }, svgIcon('image'), t('module.image.add'));

  render();
  return el('div', { class: 'images-field' }, input, items, add);
};

export const control = (field, value, error) => {
  const common = { id: fieldId(field.name), name: field.name, 'aria-invalid': error ? 'true' : null, 'aria-describedby': describedBy(field, error) };
  switch (field.type) {
    case 'toggle':
      return el('label', { class: 'check' }, el('input', { ...common, type: 'checkbox', checked: Boolean(value) }), field.label);
    case 'textarea':
      return el('textarea', { ...common, class: 'input', rows: '4', maxlength: field.max ?? null, text: value ?? '' });
    case 'icon':
      return iconControl(field, value ?? '');
    case 'images':
      return imagesControl(field, value ?? '');
    case 'date':
    case 'time':
      // The browser's own pickers; both send the format the validator expects (YYYY-MM-DD, HH:MM).
      return el('input', { ...common, class: 'input input--short', type: field.type, value: value ?? '', required: field.required ?? null });
    case 'select': {
      if (field.optionLabels) {
        const own = el('select', { ...common, class: 'input' },
          ...field.options.map((option) => el('option', { value: option, text: field.optionLabels[option] })));
        own.value = value || field.default || field.options[0];
        return own;
      }
      const global = t(`design.icon_style.${store.draft.theme.icon_style}`);
      const select = el('select', { ...common, class: 'input', onchange: repaintIcons },
        el('option', { value: '', text: t('editor.icon_style.inherit', { style: global }) }),
        ...field.options.map((option) => el('option', { value: option, text: t(`design.icon_style.${option}`) })));
      select.value = value ?? '';
      return select;
    }
    default:
      return el('input', {
        ...common, class: 'input', type: 'text', value: value ?? '', maxlength: field.max ?? null,
        required: field.required ?? null, inputmode: field.type === 'url' ? 'url' : null, autocomplete: 'off',
      });
  }
};

export const openDrawer = (path, isNew) => {
  const block = blockAt(path);
  const module = store.meta.modules[block.type];
  const blockErrors = store.errors[block.key] ?? {};
  drawerState = { path, isNew, opener: document.activeElement };

  drawer.querySelector('[data-drawer-type]').textContent = module.name;
  // Fields are grouped by meaning; a new group starts with a line and its name.
  // A field with "pair" sits next to the one before it (date and time), if there is room.
  const fields = module.fields.flatMap((field, index) => {
    const startsGroup = field.group && index > 0 && module.fields[index - 1].group !== field.group;
    const separator = startsGroup ? el('p', { class: 'drawer__group', text: t(`editor.group.${field.group}`) }) : null;
    const item = field.type === 'toggle'
      ? el('div', { class: 'field' }, control(field, block.data[field.name], null), field.help ? el('p', { class: 'field__help', text: field.help }) : null)
      : fieldWrap(field, control(field, block.data[field.name], blockErrors[field.name]), blockErrors[field.name]);
    return separator ? [separator, item] : [item];
  }).reduce((list, item, index, all) => {
    const field = module.fields.find((entry) => item.querySelector?.(`#${fieldId(entry.name)}`));
    const previous = list.at(-1);
    if (field?.pair && previous?.classList.contains('field') && index > 0 && all[index - 1] === previous) {
      list[list.length - 1] = el('div', { class: 'field-pair' }, previous, item);
    } else {
      list.push(item);
    }
    return list;
  }, []);

  drawerForm.replaceChildren(...fields,
    el('div', { class: 'drawer__footer' },
      el('button', { class: 'button button--danger', type: 'button', onclick: deleteFromDrawer }, t('common.delete')),
      el('span', { class: 'row' },
        el('button', { class: 'button button--ghost', type: 'button', onclick: closeDrawer }, t('common.cancel')),
        el('button', { class: 'button button--primary', type: 'submit' }, t('editor.apply')))),
    el('p', { class: 'muted small', text: t('editor.apply.note') }));

  drawer.hidden = false;
  drawerForm.querySelector('input:not([type="hidden"]), textarea, select, button')?.focus();
};

export const closeDrawer = () => {
  if (!drawerState) {
    return;
  }
  const { path, isNew, opener } = drawerState;
  drawerState = null;
  drawer.hidden = true;
  if (isNew) {
    listAt(path).splice(path[path.length - 1], 1);
    renderBlocks();
  }
  if (opener?.isConnected) {
    opener.focus();
  }
};

export const deleteFromDrawer = () => {
  if (!window.confirm(t('editor.delete.confirm'))) {
    return;
  }
  const { path } = drawerState;
  drawerState = null;
  drawer.hidden = true;
  listAt(path).splice(path[path.length - 1], 1);
  changed();
  blocksList.querySelector('button')?.focus();
};

drawerForm.addEventListener('submit', (event) => {
  event.preventDefault();
  const { path } = drawerState;
  const block = blockAt(path);
  const module = store.meta.modules[block.type];
  const form = new FormData(drawerForm);
  const data = {};
  const missing = {};

  module.fields.forEach((field) => {
    if (field.type === 'toggle') {
      data[field.name] = drawerForm.elements[field.name].checked;
      return;
    }
    data[field.name] = String(form.get(field.name) ?? '').trim();
    if (field.required && data[field.name] === '') {
      missing[field.name] = t('validation.required');
    }
  });

  if (Object.keys(missing).length > 0) {
    store.errors[block.key] = missing;
    block.data = data;
    openDrawer(path, drawerState.isNew);
    return;
  }

  block.data = data;
  delete store.errors[block.key];
  drawerState.isNew = false;
  const focusId = `edit-${block.key}`;
  drawerState = null;
  drawer.hidden = true;
  changed();
  focusLater(focusId);
});

drawer.addEventListener('click', (event) => {
  if (event.target === drawer) {
    closeDrawer();
  }
});

document.addEventListener('keydown', (event) => {
  if (event.key === 'Escape' && !drawer.hidden && !iconDialog.open && !imageDialog?.open) {
    closeDrawer();
  }
});
