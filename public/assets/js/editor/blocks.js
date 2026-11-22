// The block list: order, drag and drop, adding modules.

import { el } from '../backend/dom.js';
import { openDrawer } from './drawer.js';
import { changed } from './preview.js';
import { addDialog, blockAt, blockTitle, blocksList, focusLater, listAt, nextKey, root, store, svgIcon, t } from './state.js';

// ---------------------------------------------------------------- block list

export const moveBlock = (path, to) => {
  const list = listAt(path);
  const from = path[path.length - 1];
  if (to < 0 || to >= list.length || to === from) {
    return;
  }
  const [block] = list.splice(from, 1);
  list.splice(to, 0, block);
  changed();
};

// Only links can live inside a multilink; everything can live on the page.
export const canEnter = (block, parentIndex) => parentIndex === null
  || (block.type === 'link' && store.meta.modules[store.draft.blocks[parentIndex]?.type]?.acceptsChildren === true);

// Moves a block into any list: parentIndex null is the page, a number is that multilink.
// Links move into and out of multilinks with all their data.
export const moveTo = (path, parentIndex, index) => {
  const block = blockAt(path);
  if (!canEnter(block, parentIndex)) {
    return false;
  }
  const source = listAt(path);
  const target = parentIndex === null ? store.draft.blocks : store.draft.blocks[parentIndex].children;
  const from = path[path.length - 1];
  let to = index;
  if (source === target && from < to) {
    to -= 1;
  }
  if (source === target && from === to) {
    return false;
  }
  source.splice(from, 1);
  target.splice(Math.max(0, Math.min(to, target.length)), 0, block);
  changed();
  return true;
};

// Arrow buttons walk through multilinks: a link enters a multilink it passes and leaves it
// at either end, so moving in and out also works without a mouse.
export const stepBlock = (path, direction) => {
  const block = blockAt(path);
  const index = path[path.length - 1];
  if (path.length === 2) {
    const children = listAt(path);
    const parent = path[0];
    if (direction < 0 && index === 0) {
      return moveTo(path, null, parent);
    }
    if (direction > 0 && index === children.length - 1) {
      return moveTo(path, null, parent + 1);
    }
    moveBlock(path, index + direction);
    return true;
  }
  const neighbour = index + direction;
  if (neighbour < 0 || neighbour >= store.draft.blocks.length) {
    return false;
  }
  if (canEnter(block, neighbour) && neighbour !== index) {
    return moveTo(path, neighbour, direction < 0 ? store.draft.blocks[neighbour].children.length : 0);
  }
  moveBlock(path, neighbour);
  return true;
};

export let dragPath = null;

export const clearDropMarks = () => document.querySelectorAll('.block--drop-before, .block--drop-after, .blocks__drop--active').forEach((node) => {
  node.classList.remove('block--drop-before', 'block--drop-after', 'blocks__drop--active');
});

export const dragHandlers = (row, path) => {
  row.draggable = true;
  row.addEventListener('dragstart', (event) => {
    event.stopPropagation();
    dragPath = path;
    row.classList.add('block--dragging');
    // Show the drop zones only after the drag has started: they push the rows below a multilink
    // down, and Chrome cancels a drag whose element moves away under the pointer in dragstart.
    window.setTimeout(() => dragPath && root.classList.add('is-dragging'));
    event.dataTransfer.effectAllowed = 'move';
    event.dataTransfer.setData('text/plain', path.join('.'));
  });
  row.addEventListener('dragend', () => {
    dragPath = null;
    root.classList.remove('is-dragging');
    document.querySelectorAll('.block--dragging').forEach((node) => node.classList.remove('block--dragging'));
    clearDropMarks();
  });
  const parentIndex = path.length === 2 ? path[0] : null;
  const allowed = () => dragPath && dragPath.join() !== path.join() && canEnter(blockAt(dragPath), parentIndex)
    // A multilink cannot be dropped into its own children.
    && !(dragPath.length === 1 && parentIndex === dragPath[0]);
  const after = (event) => event.clientY > row.getBoundingClientRect().top + row.offsetHeight / 2;
  row.addEventListener('dragover', (event) => {
    if (!allowed()) {
      return;
    }
    event.preventDefault();
    event.stopPropagation();
    row.classList.toggle('block--drop-after', after(event));
    row.classList.toggle('block--drop-before', !after(event));
  });
  row.addEventListener('dragleave', () => row.classList.remove('block--drop-before', 'block--drop-after'));
  row.addEventListener('drop', (event) => {
    if (!allowed()) {
      return;
    }
    event.preventDefault();
    event.stopPropagation();
    const moving = dragPath;
    clearDropMarks();
    moveTo(moving, parentIndex, path[path.length - 1] + (after(event) ? 1 : 0));
  });
};

// Drop zone at the end of a multilink, also for empty ones.
export const dropZone = (parentIndex) => {
  const zone = el('li', { class: 'blocks__drop', 'aria-hidden': 'true', text: t('editor.drop_here') });
  const allowed = () => dragPath !== null && canEnter(blockAt(dragPath), parentIndex) && !(dragPath.length === 1 && dragPath[0] === parentIndex);
  zone.addEventListener('dragover', (event) => {
    if (!allowed()) {
      return;
    }
    event.preventDefault();
    zone.classList.add('blocks__drop--active');
  });
  zone.addEventListener('dragleave', () => zone.classList.remove('blocks__drop--active'));
  zone.addEventListener('drop', (event) => {
    if (!allowed()) {
      return;
    }
    event.preventDefault();
    const moving = dragPath;
    clearDropMarks();
    moveTo(moving, parentIndex, store.draft.blocks[parentIndex].children.length);
  });
  return zone;
};

export const blockRow = (block, path, count) => {
  const module = store.meta.modules[block.type];
  const index = path[path.length - 1];
  const title = blockTitle(block);
  const hasError = Boolean(store.errors[block.key]);
  const row = el('div', { class: `block${block.is_active ? '' : ' block--hidden'}${hasError ? ' block--error' : ''}` },
    el('span', { class: 'block__handle', title: t('editor.drag'), 'aria-hidden': 'true' }, svgIcon('grip')),
    el('span', { class: 'block__icon', html: module?.icon ?? '' }),
    el('span', { class: 'block__text' },
      el('span', { class: 'block__title', text: title }),
      el('span', { class: 'block__type', text: (module?.name ?? block.type) + (block.data.age_gate ? ` · ${t('module.multilink.age_badge')}` : '') + (hasError ? ` · ${t('editor.has_errors')}` : '') })),
    el('span', { class: 'block__actions' },
      el('button', { class: 'icon-button', type: 'button', 'aria-label': `${title}: ${t('editor.up')}`, disabled: path.length === 1 && index === 0, 'data-focus': `up-${block.key}`, onclick: () => { stepBlock(path, -1); focusLater(`up-${block.key}`); } }, svgIcon('up')),
      el('button', { class: 'icon-button', type: 'button', 'aria-label': `${title}: ${t('editor.down')}`, disabled: path.length === 1 && index === count - 1, 'data-focus': `down-${block.key}`, onclick: () => { stepBlock(path, 1); focusLater(`down-${block.key}`); } }, svgIcon('down')),
      el('button', {
        class: 'switch', type: 'button', role: 'switch', 'aria-checked': String(block.is_active), 'aria-label': `${title}: ${t('editor.visible')}`, 'data-focus': `vis-${block.key}`,
        onclick: () => { block.is_active = !block.is_active; changed(); focusLater(`vis-${block.key}`); },
      }),
      module ? el('button', { class: 'button', type: 'button', 'data-focus': `edit-${block.key}`, onclick: () => openDrawer(path, false) }, t('editor.edit')) : null));
  return row;
};


export const renderBlocks = () => {
  if (!blocksList) {
    return;
  }
  blocksList.replaceChildren();
  if (store.draft.blocks.length === 0) {
    blocksList.append(el('li', { class: 'empty' }, el('p', { text: t('editor.empty') })));
  }
  store.draft.blocks.forEach((block, i) => {
    const item = el('li');
    const row = blockRow(block, [i], store.draft.blocks.length);
    dragHandlers(row, [i]);
    item.append(row);
    if (store.meta.modules[block.type]?.acceptsChildren) {
      const children = el('ol', { class: 'blocks__children', 'aria-label': t('editor.children') });
      if (block.children.length === 0) {
        children.append(el('li', { class: 'muted small blocks__empty', text: t('editor.no_children') }));
      }
      block.children.forEach((child, j) => {
        const childRow = blockRow(child, [i, j], block.children.length);
        dragHandlers(childRow, [i, j]);
        children.append(el('li', {}, childRow));
      });
      children.append(dropZone(i), el('li', {}, el('button', {
        class: 'add-button add-button--small', type: 'button',
        onclick: () => addBlock('link', block.children, [i]),
      }, t('module.multilink.add_child'))));
      item.append(children);
    }
    blocksList.append(item);
  });
};

// ---------------------------------------------------------------- adding modules

export const addBlock = (type, list, parentPath = []) => {
  const module = store.meta.modules[type];
  list.push({ key: nextKey(), type, is_active: true, data: { ...module.defaults }, children: [] });
  renderBlocks();
  openDrawer([...parentPath, list.length - 1], true);
};

export const openAddDialog = () => {
  const items = Object.values(store.meta.modules).map((module) => el('li', {},
    el('button', {
      class: 'module-list__button', type: 'button',
      onclick: () => { addDialog.close(); addBlock(module.type, store.draft.blocks); },
    },
    el('span', { class: 'block__icon', html: module.icon }),
    el('span', { class: 'block__text' },
      el('span', { class: 'block__title', text: module.name }),
      el('span', { class: 'block__type', text: module.description })))));
  addDialog.querySelector('[data-module-list]').replaceChildren(...items);
  addDialog.showModal();
};
