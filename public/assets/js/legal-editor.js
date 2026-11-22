// Format bar for the imprint and privacy editor. It only writes the small Markdown subset
// that the server understands (LegalText): ## and ### headings, **bold**, *italic*,
// - lists, [links](…) and --- lines. The server still checks and escapes everything.

import { api } from './backend/api.js';
import { guardDraft } from './backend/unsaved.js';

const editor = document.querySelector('[data-md-editor]');

if (editor) {
  const textarea = editor.querySelector('textarea');
  const toolbar = editor.querySelector('[data-md-toolbar]');
  const output = editor.querySelector('[data-md-output]');
  const previewButton = toolbar.querySelector('[data-md-preview]');
  const dialog = document.querySelector('[data-md-link-dialog]');
  const linkText = dialog.querySelector('[data-md-link-text]');
  const linkUrl = dialog.querySelector('[data-md-link-url]');
  const linkError = dialog.querySelector('[data-md-link-error]');
  const placeholder = editor.dataset.placeholder || 'Text';

  toolbar.hidden = false;

  // Draft like on "Page" and "General": nothing is live until "Publish".
  const form = textarea.form;
  const unsaved = form.querySelector('[data-unsaved]');
  const publishButton = form.querySelector('[data-legal-publish]');
  const modes = [...form.querySelectorAll('[data-legal-mode]')];
  const urlInput = form.querySelector('#f-url');
  const linkField = form.querySelector('[data-legal-link]');
  const textField = form.querySelector('[data-legal-text]');
  const mode = () => modes.find((input) => input.checked)?.value ?? 'text';
  const published = { text: textarea.value, mode: mode(), url: urlInput.value };
  // After a rejected publish the form shows the draft, not what is online.
  const rejectedDraft = form.dataset.legalDraft === 'true';
  const dirty = () => rejectedDraft || textarea.value !== published.text || mode() !== published.mode || urlInput.value.trim() !== published.url;
  // Own text or external link: only the fields of the chosen way are shown.
  const showMode = () => {
    linkField.hidden = mode() !== 'link';
    textField.hidden = mode() === 'link';
  };
  modes.forEach((input) => input.addEventListener('change', () => { showMode(); showState(); }));
  urlInput.addEventListener('input', () => showState());
  showMode();
  const guard = guardDraft({ bar: unsaved, isDirty: dirty, onUpdate: (isDirty) => { publishButton.disabled = !isDirty; } });
  const showState = guard.update;
  textarea.addEventListener('input', showState);
  showState();
  // Privacy page: copy the Bioly section, or add it to the end of the own text.
  const block = document.querySelector('[data-legal-block-text]');
  const status = document.querySelector('[data-legal-block-status]');
  document.querySelector('[data-legal-block-copy]')?.addEventListener('click', async () => {
    try {
      await navigator.clipboard.writeText(block.value);
    } catch {
      block.select();
      document.execCommand('copy');
    }
    status.textContent = status.dataset.copied;
  });
  form.querySelector('[data-legal-block-insert]')?.addEventListener('click', () => {
    textarea.value = `${textarea.value.trimEnd()}${textarea.value.trim() ? '\n\n' : ''}${block.value}\n`;
    textarea.dispatchEvent(new Event('input', { bubbles: true }));
    textarea.focus();
  });

  form.querySelector('[data-action="discard"]').addEventListener('click', (event) => {
    if (!window.confirm(event.currentTarget.dataset.confirm)) {
      return;
    }
    if (rejectedDraft) {
      // Load what is online again.
      guard.allowLeave();
      window.location.assign(form.action);
      return;
    }
    textarea.value = published.text;
    urlInput.value = published.url;
    modes.forEach((input) => { input.checked = input.value === published.mode; });
    showMode();
    showState();
    if (!output.hidden) {
      previewButton.click();
    }
    textarea.focus();
  });
  form.addEventListener('submit', guard.allowLeave);

  // Replaces a part of the text so that Ctrl+Z still works where the browser allows it.
  const replace = (from, to, text, selectFrom = null, selectTo = null) => {
    textarea.focus();
    textarea.setSelectionRange(from, to);
    const done = document.queryCommandSupported?.('insertText') && document.execCommand('insertText', false, text);
    if (!done) {
      textarea.setRangeText(text, from, to, 'end');
      textarea.dispatchEvent(new Event('input', { bubbles: true }));
    }
    if (selectFrom !== null) {
      textarea.setSelectionRange(selectFrom, selectTo ?? selectFrom);
    }
  };

  const lineRange = () => {
    const { value, selectionStart, selectionEnd } = textarea;
    const start = value.lastIndexOf('\n', selectionStart - 1) + 1;
    const endIndex = value.indexOf('\n', Math.max(selectionEnd - (selectionEnd > selectionStart && value[selectionEnd - 1] === '\n' ? 1 : 0), selectionStart));
    return [start, endIndex === -1 ? value.length : endIndex];
  };

  // Headings and lists work on whole lines; a second click removes the format again.
  const prefixLines = (prefix, pattern) => {
    const [start, end] = lineRange();
    const lines = textarea.value.slice(start, end).split('\n');
    const all = lines.every((line) => line.startsWith(prefix) || line.trim() === '');
    const changed = lines.map((line) => {
      if (line.trim() === '') {
        return line;
      }
      const bare = line.replace(pattern, '');
      return all ? bare : prefix + bare;
    }).join('\n');
    replace(start, end, changed, start, start + changed.length);
  };

  const wrap = (marker) => {
    const { value, selectionStart: start, selectionEnd: end } = textarea;
    const selected = value.slice(start, end);
    const before = value.slice(start - marker.length, start);
    const after = value.slice(end, end + marker.length);

    // Already formatted: remove the markers.
    if (selected !== '' && before === marker && after === marker && !(marker === '*' && value.slice(start - 2, start) === '**' && value.slice(end, end + 2) === '**')) {
      replace(start - marker.length, end + marker.length, selected, start - marker.length, end - marker.length);
      return;
    }
    const text = selected || placeholder;
    replace(start, end, marker + text + marker, start + marker.length, start + marker.length + text.length);
  };

  const insertLine = () => {
    const { value, selectionStart } = textarea;
    const [, lineEnd] = lineRange();
    const at = value.slice(0, selectionStart).trim() === '' ? 0 : lineEnd;
    const head = value.slice(0, at);
    const tail = value.slice(at);
    const before = head === '' || head.endsWith('\n\n') ? '' : (head.endsWith('\n') ? '\n' : '\n\n');
    // At the very end the cursor lands on a fresh line, ready for the next paragraph.
    const after = tail.startsWith('\n\n') ? '' : (tail.startsWith('\n') ? '\n' : '\n\n');
    const text = `${before}---${after}`;
    replace(at, at, text, at + text.length);
  };

  // "Text": back to plain paragraphs. Removes headings, list markers, bold and italic
  // from the selected lines; links stay, they are content and not styling.
  const plain = () => {
    const [start, end] = lineRange();
    const text = textarea.value.slice(start, end).split('\n').map((line) => line
      .replace(/^#{1,6}\s+/, '')
      .replace(/^[-*]\s+/, '')
      .replace(/\*\*(.+?)\*\*/g, '$1')
      .replace(/(^|[^*])\*(?!\s)(.+?)\*(?!\*)/g, '$1$2')).join('\n');
    replace(start, end, text, start, start + text.length);
  };

  const validUrl = (url) => /^(https?:\/\/[^\s)]+|mailto:[^\s)]+|tel:\+?[0-9 ()/-]+)$/i.test(url);

  const openLinkDialog = () => {
    const { value, selectionStart: start, selectionEnd: end } = textarea;
    linkText.value = value.slice(start, end);
    linkUrl.value = '';
    linkError.hidden = true;
    linkUrl.removeAttribute('aria-invalid');
    dialog.dataset.start = String(start);
    dialog.dataset.end = String(end);
    dialog.showModal();
    (linkText.value ? linkUrl : linkText).focus();
  };

  dialog.addEventListener('submit', (event) => {
    if (event.submitter?.value !== 'ok') {
      return;
    }
    let url = linkUrl.value.trim().replace(/\s+/g, '');
    if (/^www\./i.test(url)) {
      url = `https://${url}`;
    }
    if (!validUrl(url)) {
      event.preventDefault();
      linkError.hidden = false;
      linkUrl.setAttribute('aria-invalid', 'true');
      linkUrl.focus();
      return;
    }
    const text = linkText.value.trim().replace(/[[\]]/g, '') || url.replace(/^(mailto:|tel:|https?:\/\/)/i, '');
    const start = Number(dialog.dataset.start);
    const markdown = `[${text}](${url})`;
    // Wait until the dialog is closed, so focus can go back to the text field.
    window.setTimeout(() => replace(start, Number(dialog.dataset.end), markdown, start + markdown.length), 0);
  });

  const actions = {
    plain,
    h2: () => prefixLines('## ', /^#{1,6}\s+/),
    h3: () => prefixLines('### ', /^#{1,6}\s+/),
    bold: () => wrap('**'),
    italic: () => wrap('*'),
    list: () => prefixLines('- ', /^[-*]\s+/),
    link: openLinkDialog,
    line: insertLine,
  };

  toolbar.querySelectorAll('[data-md]').forEach((button) => {
    // Keep the selection in the text field when the button is clicked.
    button.addEventListener('mousedown', (event) => event.preventDefault());
    button.addEventListener('click', () => actions[button.dataset.md]());
  });

  textarea.addEventListener('keydown', (event) => {
    if (!(event.ctrlKey || event.metaKey) || event.altKey) {
      return;
    }
    const key = event.key.toLowerCase();
    if (key === 'b' || key === 'i') {
      event.preventDefault();
      actions[key === 'b' ? 'bold' : 'italic']();
    }
  });

  // Preview: rendered by the server, so it shows exactly what the page will show.
  previewButton.addEventListener('click', async () => {
    const show = previewButton.getAttribute('aria-pressed') !== 'true';
    previewButton.setAttribute('aria-pressed', String(show));
    toolbar.querySelectorAll('[data-md]').forEach((button) => {
      button.disabled = show;
    });
    if (!show) {
      output.hidden = true;
      textarea.hidden = false;
      textarea.focus();
      return;
    }
    try {
      const { data } = await api('/admin/api/legal-preview', { content: textarea.value });
      output.innerHTML = data.html ?? ''; // escaped and built by the server (LegalText)
    } catch {
      output.textContent = '…';
    }
    output.style.minHeight = `${textarea.offsetHeight}px`;
    textarea.hidden = true;
    output.hidden = false;
    output.focus();
  });
}
