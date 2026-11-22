// The header tab: name, bio, header image, quicklinks.

import { el } from '../backend/dom.js';
import { renderHeaderPattern } from './design.js';
import { paintIcon } from './drawer.js';
import { openIconPicker } from './icon-picker.js';
import { changed } from './preview.js';
import { announce, fileButton, imageDialog, root, store, svgIcon, t, upload } from './state.js';

// ---------------------------------------------------------------- profile and design

export const textField = (name, label, help, value, max, onInput) => {
  const id = `p-${name}`;
  return el('div', { class: 'field' },
    el('label', { class: 'field__label', for: id, text: label }),
    el('input', { class: 'input', id, type: 'text', value, maxlength: String(max), 'aria-describedby': `${id}-help`, oninput: (event) => onInput(event.target.value) }),
    el('p', { class: 'field__help', id: `${id}-help`, text: help }));
};

// Header image chooser: all images of the media library, plus upload.
export const openImagePicker = (current, onSelect) => {
  const picker = imageDialog.querySelector('[data-image-picker]');
  const view = { selected: current };
  const message = el('p', { class: 'field__error', role: 'alert', hidden: true });

  const render = () => {
    const images = store.meta.images ?? [];
    const grid = el('ul', { class: 'media-choose' }, ...(images.length === 0
      ? [el('li', { class: 'muted', text: t('media.empty.images') })]
      : images.map((image) => el('li', {}, el('button', {
        class: 'media-choose__item', type: 'button', 'aria-pressed': String(view.selected === image.file),
        onclick: () => { view.selected = image.file; render(); },
        ondblclick: () => { onSelect(image.file); imageDialog.close(); },
      }, el('img', { class: image.transparent ? 'is-transparent' : null, src: image.url, alt: '', width: '96', height: '96', loading: 'lazy' }),
      el('span', { class: 'media-choose__label', text: image.label || image.file }))))));

    const uploader = fileButton(t('editor.header.avatar.upload'), 'image/jpeg,image/png,image/webp', async (file) => {
      message.hidden = true;
      const result = await upload('image', file);
      if (!result.ok) {
        message.textContent = result.error;
        message.hidden = false;
        return;
      }
      store.meta.images = result.images;
      view.selected = result.file;
      render();
      announce(t('editor.header.avatar.done'));
    });

    const footer = el('div', { class: 'icon-picker__footer' },
      el('a', { href: '/admin/media', target: '_blank', rel: 'noopener', text: t('media.manage') }),
      el('span', { class: 'row' },
        el('button', { class: 'button button--ghost', type: 'button', onclick: () => imageDialog.close() }, t('common.cancel')),
        el('button', {
          class: 'button button--primary', type: 'button', disabled: !view.selected,
          onclick: () => { onSelect(view.selected); imageDialog.close(); },
        }, t('media.choose.select'))));

    picker.replaceChildren(el('div', { class: 'row' }, uploader), message, grid, footer);
  };

  render();
  imageDialog.showModal();
};

// Header image in the round frame. The backend account box shows the same image (or the first letter).
export const renderAvatar = () => {
  const avatarBox = el('span', { class: 'avatar-field__preview', 'aria-hidden': 'true' });
  const altInfo = el('p', { class: 'avatar-field__alt' });
  const removeButton = el('button', {
    class: 'button button--ghost', type: 'button',
    onclick: () => { store.draft.profile.avatar = ''; showAvatar(); changed({ list: false }); },
  }, svgIcon('trash'), t('common.remove'));

  const showAvatar = () => {
    const name = store.draft.profile.avatar;
    const chosen = (store.meta.images ?? []).find((item) => item.file === name);
    avatarBox.replaceChildren(name
      ? el('img', { class: chosen?.transparent ? 'is-transparent' : null, src: `/media/${name}`, alt: '', width: '72', height: '72' })
      : document.createTextNode((store.draft.profile.name.trim()[0] ?? '?').toUpperCase()));
    removeButton.hidden = !name;
    const image = (store.meta.images ?? []).find((item) => item.file === name);
    altInfo.hidden = !name;
    const text = !image ? '' : (image.alt ? t('media.alt.current', { alt: image.alt }) : t('media.alt.missing'));
    altInfo.replaceChildren(document.createTextNode(text), ' ',
      el('a', { href: '/admin/media', target: '_blank', rel: 'noopener', text: t('media.alt.edit') }));
  };

  const chooser = el('button', {
    class: 'button', type: 'button', 'aria-haspopup': 'dialog',
    onclick: () => openImagePicker(store.draft.profile.avatar, (file) => {
      if (file) {
        store.draft.profile.avatar = file;
        showAvatar();
        changed({ list: false });
      }
    }),
  }, svgIcon('image'), t('media.choose.button'));
  showAvatar();

  return el('div', { class: 'field' },
    el('span', { class: 'field__label', id: 'h-avatar-label', text: t('editor.header.avatar') }),
    el('div', { class: 'avatar-field', role: 'group', 'aria-labelledby': 'h-avatar-label' }, avatarBox,
      el('div', { class: 'stack stack--tight' },
        el('div', { class: 'row' }, chooser, removeButton),
        el('p', { class: 'field__help', text: t('editor.header.avatar.help') }),
        altInfo)));
};

// Quicklinks: small icon links under the text in the header. The description is the
// link's accessible name and shows as a tooltip; without it a link is not store.published.
export const validLink = (url) => /^(https?:\/\/[^\s]+\.[^\s]+|mailto:[^\s@]+@[^\s]+|tel:\+?[0-9 ()/-]{3,})$/i.test(url);

export const renderQuicklinks = () => {
  store.draft.profile.quicklinks = Array.isArray(store.draft.profile.quicklinks) ? store.draft.profile.quicklinks : [];
  const links = store.draft.profile.quicklinks;
  const list = el('ol', { class: 'quicklink-list' });
  const add = el('button', {
    class: 'button', type: 'button', disabled: links.length >= store.meta.limits.quicklinks,
    onclick: () => {
      links.push({ icon: '', url: '', label: '' });
      draw();
      changed({ list: false });
      list.querySelector('li:last-child button')?.focus();
    },
  }, t('editor.quicklinks.add'));

  const draw = () => {
    add.disabled = links.length >= store.meta.limits.quicklinks;
    list.replaceChildren(...links.map((link, index) => {
      const id = `ql-${index}`;
      const glyph = el('span', { class: 'icon-field__preview', 'aria-hidden': 'true' });
      paintIcon(glyph, link.icon);
      const urlError = el('p', { class: 'field__error', id: `${id}-url-error`, hidden: true, text: t('validation.url') });
      const checkUrl = (input) => {
        const bad = input.value.trim() !== '' && !validLink(input.value.trim());
        urlError.hidden = !bad;
        input.setAttribute('aria-invalid', String(bad));
      };
      const move = (to) => { links.splice(to, 0, ...links.splice(index, 1)); draw(); changed({ list: false }); };
      return el('li', { class: 'quicklink' },
        el('button', {
          class: 'quicklink__icon', type: 'button', 'aria-haspopup': 'dialog', 'aria-label': `${t('editor.icon.choose')} (${index + 1})`,
          onclick: () => openIconPicker(link.icon, (icon) => { link.icon = icon; draw(); changed({ list: false }); }),
        }, glyph),
        el('div', { class: 'quicklink__fields' },
          el('label', { class: 'visually-hidden', for: `${id}-label`, text: t('editor.quicklinks.label') }),
          el('input', {
            class: 'input', id: `${id}-label`, type: 'text', maxlength: String(store.meta.limits.quicklinkLabel), value: link.label, placeholder: t('editor.quicklinks.label'),
            oninput: (event) => { link.label = event.target.value; changed({ list: false }); },
          }),
          el('label', { class: 'visually-hidden', for: `${id}-url`, text: t('editor.quicklinks.url') }),
          el('input', {
            class: 'input', id: `${id}-url`, type: 'text', inputmode: 'url', autocomplete: 'off', value: link.url, maxlength: String(store.meta.limits.url), placeholder: 'https://', 'aria-describedby': `${id}-url-error`,
            oninput: (event) => { link.url = event.target.value.trim(); checkUrl(event.target); changed({ list: false }); },
          }),
          urlError),
        el('span', { class: 'images-field__actions' },
          el('button', { class: 'icon-button', type: 'button', 'aria-label': `${t('editor.up')} (${index + 1})`, disabled: index === 0, onclick: () => move(index - 1) }, svgIcon('up')),
          el('button', { class: 'icon-button', type: 'button', 'aria-label': `${t('editor.down')} (${index + 1})`, disabled: index === links.length - 1, onclick: () => move(index + 1) }, svgIcon('down')),
          el('button', { class: 'icon-button', type: 'button', 'aria-label': `${t('common.remove')} (${index + 1})`, onclick: () => { links.splice(index, 1); draw(); changed({ list: false }); } }, svgIcon('trash'))));
    }));
  };

  draw();
  return el('div', { class: 'field' },
    el('h3', { class: 'field__label', text: t('editor.quicklinks') }),
    el('p', { class: 'field__help', text: t('editor.quicklinks.help') }),
    list, add);
};

export const renderHeader = () => {
  const panel = root.querySelector('[data-panel="header"]');
  if (!panel) {
    return;
  }
  panel.replaceChildren(el('section', { class: 'panel' },
    renderAvatar(),
    textField('name', t('editor.profile.name'), t('editor.profile.name.help'), store.draft.profile.name, store.meta.limits.name, (v) => { store.draft.profile.name = v; changed({ list: false }); }),
    textField('bio', t('editor.profile.bio'), t('editor.profile.bio.help'), store.draft.profile.bio, store.meta.limits.bio, (v) => { store.draft.profile.bio = v; changed({ list: false }); }),
    renderQuicklinks(),
    renderHeaderPattern()));
};
