// Builds elements: el('button', { class: 'button', onclick: fn, text: 'Save' }, child, ...).
// false, null and undefined leave an attribute out; "html" is only for trusted SVG markup.

export const el = (tag, attrs = {}, ...children) => {
  const node = document.createElement(tag);
  Object.entries(attrs).forEach(([name, value]) => {
    if (value === false || value === null || value === undefined) {
      return;
    }
    if (name === 'class') {
      node.className = value;
    } else if (name === 'text') {
      node.textContent = value;
    } else if (name === 'html') {
      node.innerHTML = value;
    } else if (name.startsWith('on')) {
      node.addEventListener(name.slice(2), value);
    } else {
      node.setAttribute(name, value === true ? '' : value);
    }
  });
  children.flat().filter(Boolean).forEach((child) => node.append(child));
  return node;
};
