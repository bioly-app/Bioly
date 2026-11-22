// The general area: patterns, background, colors, favicon.

import { el } from '../backend/dom.js';
import { iconColors, iconLabel, repaintIcons } from './drawer.js';
import { openImagePicker } from './header.js';
import { openIconPicker } from './icon-picker.js';
import { changed } from './preview.js';
import { focusLater, readableOn, root, store, svgIcon, t } from './state.js';

// Pattern picker, shared by the header and the background so both look the same:
// tiles like the icon picker, each with a preview on its ground color, then strength,
// size and fade. Steps come from the server (store.meta.patternLevels), the tiles show them too.
// `build` returns a fresh field, so only this field is rebuilt and the focus stays put.
export const renderPatternPicker = ({ key, label, help, ground, pattern, build }) => {
  const labelId = `${key}-pattern-label`;
  const title = el('h3', { class: 'field__label', id: labelId, text: label });
  if (store.meta.patterns.length === 0) {
    return el('div', { class: 'field', 'data-pattern-field': key }, title, el('p', { class: 'muted small', text: t('design.patterns.empty') }));
  }
  const levels = store.meta.patternLevels;
  const zoom = levels.size[pattern.size] ?? 1;
  const strength = levels.strength[pattern.strength] ?? levels.strength.medium;
  const options = [{ id: null }, ...store.meta.patterns];
  const rerender = (focus) => {
    changed({ list: false });
    root.querySelector(`[data-pattern-field="${key}"]`)?.replaceWith(build());
    focusLater(focus);
  };

  const grid = el('div', { class: 'pattern-grid', role: 'radiogroup', 'aria-labelledby': labelId },
    ...options.map((option) => {
      const swatch = el('span', { class: 'pattern-tile__swatch', 'aria-hidden': 'true' });
      swatch.style.backgroundColor = ground;
      swatch.style.color = readableOn(ground);
      if (option.id) {
        const fade = levels.fade[pattern.fade] ?? null;
        const mask = el('span', { class: `pattern-tile__mask${fade ? ' pattern-tile__mask--fade' : ''}` });
        mask.style.setProperty('--pattern', `url(/assets/patterns/${option.id}.svg?v=${option.version})`);
        mask.style.setProperty('--pattern-size', `${Math.round(option.size * 0.75 * zoom)}px`);
        if (fade) {
          mask.style.setProperty('--pattern-fade', fade);
        }
        mask.style.opacity = String(strength / 100);
        swatch.append(mask);
      }
      const focusId = `${key}-pattern-${option.id ?? 'none'}`;
      return el('label', { class: 'pattern-tile' },
        el('input', {
          class: 'visually-hidden', type: 'radio', name: `${key}_pattern`, value: option.id ?? '',
          checked: (pattern.id ?? null) === option.id, 'data-focus': focusId,
          onchange: () => { pattern.id = option.id; rerender(focusId); },
        }),
        swatch,
        el('span', { class: 'pattern-tile__label', text: t(option.id ? `design.pattern.${option.id}` : 'design.pattern.none') }));
    }));

  const choice = (name, values, fallback = 'medium') => el('div', { class: 'pattern-option' },
    el('span', { class: 'pattern-option__label', id: `${key}-pattern-${name}`, text: t(`design.pattern_${name}`) }),
    el('div', { class: 'segmented segmented--small', role: 'group', 'aria-labelledby': `${key}-pattern-${name}` },
      ...values.map((value) => el('button', {
        class: 'segmented__item', type: 'button', 'aria-pressed': String((pattern[name] ?? fallback) === value),
        text: t(`design.pattern_${name}.${value}`), 'data-focus': `${key}-pattern-${name}-${value}`,
        onclick: () => { pattern[name] = value; rerender(`${key}-pattern-${name}-${value}`); },
      }))));

  const settings = pattern.id ? el('div', { class: 'pattern-options' },
    choice('strength', Object.keys(levels.strength)),
    choice('size', Object.keys(levels.size)),
    choice('fade', Object.keys(levels.fade), 'none')) : null;

  return el('div', { class: 'field', 'data-pattern-field': key }, title, el('p', { class: 'field__help', text: help }), grid, settings);
};

export const renderHeaderPattern = () => renderPatternPicker({
  key: 'header',
  label: t('design.header_pattern'),
  help: t('design.header_pattern.help'),
  ground: store.draft.theme.header_color ?? store.draft.theme.accent,
  pattern: store.draft.theme.header_pattern,
  build: renderHeaderPattern,
});

export const renderBackgroundPattern = () => renderPatternPicker({
  key: 'background',
  label: t('design.background_pattern'),
  help: t('design.background_pattern.help'),
  ground: store.draft.theme.background.color,
  pattern: store.draft.theme.background.pattern,
  build: renderBackgroundPattern,
});

// Background: a color (optionally with a pattern) or a photo from the media library.
export const renderBackground = () => {
  const bg = store.draft.theme.background;
  const rebuild = (focus) => {
    root.querySelector('[data-background-field]')?.replaceWith(renderBackground());
    focusLater(focus);
  };

  const types = el('div', { class: 'pattern-option' },
    el('span', { class: 'pattern-option__label', id: 'bg-type-label', text: t('design.background_type') }),
    el('div', { class: 'segmented segmented--small', role: 'group', 'aria-labelledby': 'bg-type-label' },
      ...['color', 'image'].map((type) => el('button', {
        class: 'segmented__item', type: 'button', 'aria-pressed': String(bg.type === type),
        text: t(`design.background_type.${type}`), 'data-focus': `bg-type-${type}`,
        onclick: () => { bg.type = type; changed({ list: false }); rebuild(`bg-type-${type}`); },
      }))));

  const body = bg.type === 'image' ? [renderBackgroundImage(rebuild)] : [
    colorInput('c-bg', t('design.background'), bg.color, (hex) => {
      bg.color = hex;
      changed({ list: false });
      root.querySelector('[data-pattern-field="background"]')?.replaceWith(renderBackgroundPattern());
    }, el('p', { class: 'field__help', text: t('design.background.help') })),
    renderBackgroundPattern(),
  ];

  return el('div', { class: 'background-field', 'data-background-field': '' },
    el('h3', { class: 'background-field__title', text: t('design.background_section') }), types, ...body);
};

export const renderBackgroundImage = (rebuild) => {
  const bg = store.draft.theme.background;
  const image = (store.meta.images ?? []).find((item) => item.file === bg.image);
  const preview = image
    ? el('img', { class: 'background-field__preview', src: image.url, alt: '', width: '160', height: '100' })
    : el('p', { class: 'muted small', text: t('design.background_image.none') });
  const choose = el('button', {
    class: 'button', type: 'button', 'aria-haspopup': 'dialog', 'data-focus': 'bg-image-choose',
    onclick: () => openImagePicker(bg.image ?? '', (file) => {
      if (!file) {
        return;
      }
      bg.image = file;
      bg.image_tone = (store.meta.images ?? []).find((item) => item.file === file)?.color || null;
      changed({ list: false });
      rebuild('bg-image-choose');
    }),
  }, t('design.background_image.choose'));
  const remove = image ? el('button', {
    class: 'button button--ghost', type: 'button',
    onclick: () => { bg.image = null; bg.image_tone = null; changed({ list: false }); rebuild('bg-image-choose'); },
  }, svgIcon('trash'), t('common.remove')) : null;

  return el('div', { class: 'field' },
    el('span', { class: 'field__label', text: t('design.background_image') }),
    preview,
    el('div', { class: 'row' }, choose, remove),
    el('p', { class: 'field__help', text: t('design.background_image.help') }));
};

export const colorInput = (id, label, value, onChange, extra = null) => {
  const picker = el('input', { class: 'input', type: 'color', id, value });
  const hex = el('input', { class: 'input', type: 'text', value, 'aria-label': `${label} (Hex)`, maxlength: '7', spellcheck: 'false' });
  picker.addEventListener('input', () => { hex.value = picker.value.toUpperCase(); onChange(hex.value); });
  hex.addEventListener('input', () => {
    if (/^#[0-9a-fA-F]{6}$/.test(hex.value)) {
      picker.value = hex.value;
      onChange(hex.value.toUpperCase());
    }
  });
  return el('div', { class: 'field' },
    el('label', { class: 'field__label', for: id, text: label }),
    el('div', { class: 'color-field' }, picker, hex),
    extra);
};

export const followColor = (key, label) => {
  const id = `c-${key}`;
  const value = store.draft.theme[key] ?? store.draft.theme.accent;
  const field = colorInput(id, label, value, (hex) => { store.draft.theme[key] = hex; changed({ list: false }); });
  const colorRow = field.querySelector('.color-field');
  const follow = el('input', { type: 'checkbox', checked: store.draft.theme[key] === null });
  colorRow.hidden = store.draft.theme[key] === null;
  follow.addEventListener('change', () => {
    store.draft.theme[key] = follow.checked ? null : colorRow.querySelector('input[type="text"]').value.toUpperCase();
    colorRow.hidden = follow.checked;
    changed({ list: false });
  });
  field.append(el('label', { class: 'check' }, follow, t('design.follow_accent')));
  field.dataset.warning = key;
  return field;
};

// ---------------------------------------------------------------- favicon


export const faviconGlyph = (size) => {
  const fav = store.draft.theme.favicon;
  const box = el('span', { class: 'favicon-preview__tile' });
  box.style.width = `${size}px`;
  box.style.height = `${size}px`;
  if (fav.type === 'upload' && fav.file) {
    // Own icons come with their SVG (one-color ones in the icon style); older favicon files as image.
    const own = fav.file.startsWith('icon-') ? store.meta.icons.find((item) => item.id === `own:${fav.file.slice(5, -4)}`) : null;
    if (own) {
      const glyph = el('span', { class: 'favicon-preview__own', html: own.svg });
      glyph.style.color = own.color ? iconColors(own, store.draft.theme.icon_style).color : '';
      box.append(glyph);
    } else {
      box.append(el('img', { src: `/media/${fav.file}`, alt: '', width: String(size), height: String(size) }));
    }
    return box;
  }
  const icon = fav.type === 'icon' ? store.meta.icons.find((item) => item.id === fav.icon) : null;
  if (icon) {
    // Same look as an icon tile on the page, following the global icon style.
    const { color, wash, surface } = iconColors(icon, store.draft.theme.icon_style);
    box.style.background = surface;
    const glyph = el('span', { class: 'favicon-preview__glyph', html: icon.svg });
    glyph.style.color = color;
    glyph.style.background = `color-mix(in srgb, ${color} ${wash}%, transparent)`;
    box.append(glyph);
    return box;
  }
  const accent = store.draft.theme.accent;
  box.style.background = accent;
  box.style.color = readableOn(accent);
  box.append(el('span', { class: 'favicon-preview__letter', text: (store.draft.profile.name.trim()[0] ?? 'B').toUpperCase() }));
  return box;
};

export let redrawFavicon = () => {};

export const renderFavicon = () => {
  const fav = store.draft.theme.favicon;
  const section = el('section', { class: 'panel', 'aria-labelledby': 'favicon-title' });
  const preview = el('div', { class: 'favicon-preview', 'aria-hidden': 'true' });
  const redraw = () => {
    preview.replaceChildren(
      el('span', { class: 'favicon-preview__tab' }, faviconGlyph(16), el('span', { text: store.draft.profile.name || 'Bioly' })),
      faviconGlyph(48));
    iconRow.hidden = fav.type !== 'icon';
    uploadRow.hidden = fav.type !== 'upload';
    iconName.textContent = fav.icon ? iconLabel(fav.icon) : t('editor.icon.none');
    fileName.textContent = ownId() ? iconLabel(ownId()) : (fav.file ? t('favicon.upload.current') : t('favicon.upload.none'));
  };
  const update = () => { redraw(); changed({ list: false }); };
  redrawFavicon = redraw;

  const choices = el('fieldset', { class: 'radio-cards' },
    el('legend', { class: 'field__label', text: t('favicon.type') }),
    ...['auto', 'icon', 'upload'].map((type) => el('label', { class: 'radio-card' },
      el('input', { type: 'radio', name: 'favicon_type', value: type, checked: fav.type === type, onchange: () => { fav.type = type; update(); } }),
      el('span', { class: 'radio-card__text' },
        el('strong', { text: t(`favicon.type.${type}`) }),
        el('span', { class: 'radio-card__desc', text: t(`favicon.type.${type}.desc`) })))));

  const iconName = el('span', { class: 'icon-field__name' });
  const iconRow = el('div', { class: 'stack stack--tight' },
    el('div', { class: 'icon-field' }, iconName,
      el('button', { class: 'button', type: 'button', 'aria-haspopup': 'dialog', onclick: () => openIconPicker(fav.icon ?? '', (id) => { if (id) { fav.icon = id; update(); } }, { noOriginals: true }) }, t('editor.icon.choose'))),
    el('p', { class: 'field__help', text: t('favicon.icon.help') }));

  // Own SVG: an own icon from the media library, shown as it is (no tile).
  const ownId = () => (fav.file?.startsWith('icon-') ? `own:${fav.file.slice(5, -4)}` : '');
  const fileName = el('span', { class: 'icon-field__name' });
  const uploadRow = el('div', { class: 'stack stack--tight' },
    el('div', { class: 'icon-field' }, fileName,
      el('button', {
        class: 'button', type: 'button', 'aria-haspopup': 'dialog',
        onclick: () => openIconPicker(ownId(), (id) => {
          if (id?.startsWith('own:')) {
            fav.file = `icon-${id.slice(4)}.svg`;
            fav.type = 'upload';
            update();
          }
        }, { onlyOwn: true }),
      }, svgIcon('image'), t('favicon.upload.choose'))),
    el('p', { class: 'field__help', text: t('favicon.upload.help') }));

  section.append(
    el('h2', { class: 'panel__title', id: 'favicon-title', text: t('favicon.title') }),
    el('p', { class: 'muted small', text: t('favicon.intro') }),
    preview, choices, iconRow, uploadRow);
  redraw();
  return section;
};

export const renderDesign = () => {
  if (!root.querySelector('[data-panel="design"]')) {
    return;
  }
  const theme = store.draft.theme;
  const styles = ['tinted', 'accent', 'mono'];
  const radiusOut = el('output', { for: 'd-radius', text: `${theme.radius} px` });

  const colors = el('section', { class: 'panel' },
    el('h2', { class: 'panel__title', text: t('design.colors') }),
    colorInput('c-accent', t('design.accent'), theme.accent, (hex) => { theme.accent = hex; changed({ list: false }); }, el('p', { class: 'field__help', text: t('design.accent.help') })),
    el('div', { class: 'grid' }, followColor('header_color', t('design.header_color')), followColor('heading_color', t('design.heading_color'))),
    renderBackground(),
    el('div', { class: 'stack', 'data-warnings': '' }));

  const iconStyle = el('fieldset', { class: 'radio-cards' },
    el('legend', { class: 'field__label', text: t('design.icon_style') }),
    ...styles.map((style) => el('label', { class: 'radio-card' },
      el('input', { type: 'radio', name: 'icon_style', value: style, checked: theme.icon_style === style, onchange: () => { theme.icon_style = style; changed({ list: false }); repaintIcons(); redrawFavicon(); } }),
      el('span', { class: 'radio-card__text' },
        el('strong', { text: t(`design.icon_style.${style}`) }),
        el('span', { class: 'radio-card__desc', text: t(`design.icon_style.${style}.desc`) })))));

  const radius = el('div', { class: 'field' },
    el('label', { class: 'field__label', for: 'd-radius' }, `${t('design.radius')}: `, radiusOut),
    el('input', {
      id: 'd-radius', type: 'range', min: '6', max: '28', value: String(theme.radius),
      oninput: (event) => { theme.radius = Number(event.target.value); radiusOut.textContent = `${theme.radius} px`; changed({ list: false }); },
    }));

  const icons = el('section', { class: 'panel' }, el('h2', { class: 'panel__title', text: t('design.icons') }), iconStyle, radius);

  const reset = el('div', {}, el('button', {
    class: 'button', type: 'button',
    onclick: () => {
      if (window.confirm(t('design.reset.confirm'))) {
        store.draft.theme = structuredClone(store.meta.defaultTheme);
        renderDesign();
        changed({ list: false });
      }
    },
  }, t('design.reset')));

  root.querySelector('[data-panel="design"]').replaceChildren(colors, icons, renderFavicon(), reset);
  renderWarnings(store.meta.warnings ?? []);
};

export const renderWarnings = (warnings) => {
  store.meta.warnings = warnings;
  const box = root.querySelector('[data-warnings]');
  if (!box) {
    return;
  }
  box.replaceChildren(...warnings.map((warning) => el('p', { class: 'field__error', text: t(`design.warning.${warning.field}`, { ratio: String(warning.ratio).replace('.', ',') }) })));
};
