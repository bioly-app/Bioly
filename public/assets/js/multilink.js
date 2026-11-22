// Opens a multilink. Its links are never part of the page: they are loaded
// only here, and only outside in-app browsers of social apps.

// The list of apps comes from the server (InAppBrowser::PATTERN), so it exists only once.
const isInApp = () => {
  try {
    return new RegExp(document.body.dataset.inApp || '(?!)', 'i').test(navigator.userAgent);
  } catch {
    return false;
  }
};

// Texts come from the page as one JSON block (see page.twig), read once.
let cachedTexts;
const texts = () => {
  if (!cachedTexts) {
    try {
      cachedTexts = JSON.parse(document.getElementById('site-texts')?.textContent || '{}');
    } catch {
      cachedTexts = {};
    }
  }
  return cachedTexts;
};

const element = (tag, className, text) => {
  const node = document.createElement(tag);

  if (className) {
    node.className = className;
  }

  if (text) {
    node.textContent = text;
  }

  return node;
};

const closeIcon = () => {
  const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
  svg.setAttribute('viewBox', '0 0 24 24');
  svg.setAttribute('aria-hidden', 'true');
  const path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
  path.setAttribute('d', 'M18 6 6 18M6 6l12 12');
  svg.append(path);

  return svg;
};

// A <dialog> with title and close button; focus trap and Escape come with showModal().
const sheet = (title, opener) => {
  const t = texts();
  const dialog = element('dialog', 'sheet');
  const head = element('div', 'sheet__head');
  const heading = element('h2', 'sheet__title', title);
  const close = element('button', 'sheet__close');

  heading.id = `sheet-title-${Date.now()}`;
  dialog.setAttribute('aria-labelledby', heading.id);
  close.type = 'button';
  close.setAttribute('aria-label', t.close);
  close.append(closeIcon());
  close.addEventListener('click', () => dialog.close());
  dialog.addEventListener('click', (event) => {
    if (event.target === dialog) {
      dialog.close();
    }
  });
  dialog.addEventListener('close', () => {
    dialog.remove();
    opener.focus();
  });

  head.append(heading, close);
  dialog.append(head);
  document.body.append(dialog);

  return dialog;
};

const openInBrowserUrl = () => {
  const url = window.location.href;

  if (/Android/i.test(navigator.userAgent)) {
    return `intent://${url.replace(/^https?:\/\//, '')}#Intent;scheme=https;end`;
  }

  return `x-safari-${url}`;
};

const showInAppHint = (opener) => {
  const t = texts();
  const dialog = sheet(t.inAppTitle, opener);
  const link = element('a', 'sheet__button', t.inAppOpen);

  link.href = openInBrowserUrl();
  dialog.append(element('p', 'sheet__text', t.inAppText), link, element('p', 'sheet__note', t.inAppManual));
  dialog.showModal();
};

// ---------------------------------------------------------------- age check

const AGE_KEY = 'bioly-age-confirmed';

// Remembered for this visit only, and only as "confirmed": the birth date is never stored
// and never sent. The server just gets the confirmation header.
const ageConfirmed = (minimum) => {
  try {
    return window.sessionStorage.getItem(AGE_KEY) === String(minimum);
  } catch {
    return false;
  }
};

const rememberAge = (minimum) => {
  try {
    window.sessionStorage.setItem(AGE_KEY, String(minimum));
  } catch {
    // Private mode: the check simply comes again next time.
  }
};

// Full years between the birth date and today; null for a date that does not exist
// or lies in the future.
const ageOf = (day, month, year) => {
  const birth = new Date(year, month - 1, day);
  const today = new Date();
  if (birth.getFullYear() !== year || birth.getMonth() !== month - 1 || birth.getDate() !== day || birth > today) {
    return null;
  }
  const hadBirthday = today.getMonth() > birth.getMonth() || (today.getMonth() === birth.getMonth() && today.getDate() >= birth.getDate());
  return today.getFullYear() - year - (hadBirthday ? 0 : 1);
};

// One part of the date: digits only, also when pasted or filled in by the browser.
const numberField = (id, label, autocomplete, length, min, max) => {
  const wrap = element('div', 'age__field');
  const caption = element('label', 'age__label', label);
  const input = element('input', 'age__input');
  caption.htmlFor = id;
  Object.assign(input, { id, type: 'text', inputMode: 'numeric', autocomplete, required: true, spellcheck: false });
  input.size = length;
  input.addEventListener('input', () => {
    const digits = input.value.replace(/\D/g, '').slice(0, length);
    if (digits !== input.value) {
      input.value = digits;
    }
  });
  wrap.append(caption, input);
  // The value as a number, or null when it is missing or out of range.
  const value = () => {
    const number = input.value.length === length || (length === 2 && input.value.length === 1) ? Number(input.value) : NaN;
    return Number.isInteger(number) && number >= min && number <= max ? number : null;
  };
  return { wrap, input, value, length };
};

const askAge = (button, minimum) => new Promise((resolve) => {
  const t = texts();
  const dialog = sheet(t.ageTitle, button);
  const form = element('form', 'age');
  const legendId = `age-legend-${Date.now()}`;
  const group = element('fieldset', 'age__group');
  const legend = element('legend', 'age__legend', t.ageLegend);
  const thisYear = new Date().getFullYear();
  const day = numberField(`${legendId}-d`, t.ageDay, 'bday-day', 2, 1, 31);
  const month = numberField(`${legendId}-m`, t.ageMonth, 'bday-month', 2, 1, 12);
  const year = numberField(`${legendId}-y`, t.ageYear, 'bday-year', 4, thisYear - 120, thisYear);
  const fields = [day, month, year];
  const error = element('p', 'age__error');
  const submit = element('button', 'sheet__button age__submit', t.ageConfirm);
  let passed = false;

  legend.id = legendId;
  error.id = `${legendId}-error`;
  error.setAttribute('role', 'alert');
  error.hidden = true;
  submit.type = 'submit';
  group.append(legend, element('div', 'age__fields'));
  group.lastChild.append(day.wrap, month.wrap, year.wrap);
  form.noValidate = true;
  form.append(element('p', 'sheet__text', t.ageText.replace('{age}', String(minimum))), group, error, submit, element('p', 'sheet__note', t.ageNote));

  const clear = () => {
    error.hidden = true;
    error.textContent = '';
    fields.forEach(({ input }) => {
      input.removeAttribute('aria-invalid');
      input.removeAttribute('aria-describedby');
    });
  };

  // Marks only the fields at fault and puts the focus on the first of them.
  const fail = (message, wrong) => {
    error.textContent = message;
    error.hidden = false;
    wrong.forEach(({ input }) => {
      input.setAttribute('aria-invalid', 'true');
      input.setAttribute('aria-describedby', error.id);
    });
    wrong[0].input.focus();
  };

  // Jump to the next field once a part is complete, and back with backspace in an empty one.
  [[day, month], [month, year]].forEach(([from, to]) => {
    from.input.addEventListener('input', () => {
      if (from.input.value.length === from.length) {
        to.input.focus();
      }
    });
    to.input.addEventListener('keydown', (event) => {
      if (event.key === 'Backspace' && to.input.value === '') {
        from.input.focus();
      }
    });
  });
  fields.forEach(({ input }) => input.addEventListener('input', () => {
    if (!error.hidden) {
      clear();
    }
  }));

  form.addEventListener('submit', (event) => {
    event.preventDefault();
    clear();
    const wrong = fields.filter((field) => field.value() === null);
    if (wrong.length > 0) {
      const messages = new Map([[day, t.ageDayInvalid], [month, t.ageMonthInvalid], [year, t.ageYearInvalid]]);
      fail(messages.get(wrong[0]), wrong);
      return;
    }
    const age = ageOf(day.value(), month.value(), year.value());
    if (age === null) {
      fail(t.ageInvalid, fields);
      return;
    }
    if (age < minimum) {
      fail(t.ageYoung.replace('{age}', String(minimum)), [year]);
      return;
    }
    passed = true;
    rememberAge(minimum);
    dialog.close();
  });

  dialog.addEventListener('close', () => resolve(passed));
  dialog.append(form);
  dialog.showModal();
  day.input.focus();
});

export const openMultilink = async (button) => {
  // In-app browsers always get the hint only, with or without age check.
  if (isInApp()) {
    showInAppHint(button);
    return;
  }

  const minimum = Number(button.dataset.ageGate || 0);
  if (minimum > 0 && !ageConfirmed(minimum) && !(await askAge(button, minimum))) {
    return;
  }

  const t = texts();
  const title = button.querySelector('.tile__title')?.textContent ?? '';
  const dialog = sheet(title, button);
  const body = element('div', 'sheet__body', t.loading);

  body.setAttribute('aria-live', 'polite');
  dialog.append(body);
  dialog.showModal();

  try {
    const endpoint = document.body.dataset.multilinkEndpoint;
    const headers = { Accept: 'application/json' };
    if (minimum > 0) {
      headers['X-Bioly-Age-Confirmed'] = String(minimum);
    }
    const response = await fetch(endpoint + encodeURIComponent(button.dataset.multilink), { headers, credentials: 'same-origin' });

    if (!response.ok) {
      // Only known codes get their own handling; anything else shows the neutral error.
      const answer = await response.json().catch(() => ({}));
      if (answer.error === 'in_app') {
        dialog.close();
        showInAppHint(button);
        return;
      }
      if (answer.error === 'age_required' && Number(answer.min_age) > 0) {
        dialog.close();
        // The page did not know about the check (old cache): ask now and try once more.
        try {
          window.sessionStorage.removeItem(AGE_KEY);
        } catch {
          // nothing stored
        }
        button.dataset.ageGate = String(answer.min_age);
        if (await askAge(button, Number(answer.min_age))) {
          openMultilink(button);
        }
        return;
      }
      if (answer.error === 'too_many') {
        body.textContent = t.tooMany;
        return;
      }
      throw new Error(answer.error ?? String(response.status));
    }

    const { html } = await response.json();
    // Rendered and escaped by the server's Twig templates.
    body.innerHTML = html;
  } catch {
    body.textContent = t.error;
  }
};
