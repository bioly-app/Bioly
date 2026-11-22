// Small helpers for all backend, login and installer forms:
// show/hide password, live password rules, no double submit, login lock countdown.
// Everything works without this script too; it only makes the forms nicer.

const busyText = document.documentElement.dataset.busyText || '…';

// ---------------------------------------------------------------- show password

document.querySelectorAll('[data-password-toggle]').forEach((button) => {
  const input = document.getElementById(button.dataset.passwordToggle);
  if (!input) {
    return;
  }
  button.hidden = false;
  button.addEventListener('click', () => {
    const show = input.type === 'password';
    input.type = show ? 'text' : 'password';
    button.setAttribute('aria-pressed', String(show));
    button.setAttribute('aria-label', show ? button.dataset.labelHide : button.dataset.labelShow);
    input.focus();
  });
  // Never send or keep the password as visible text.
  input.form?.addEventListener('submit', () => {
    input.type = 'password';
  });
});

// ---------------------------------------------------------------- password length (limits from PasswordPolicy)

const RULES = {
  length: (v, list) => [...v.normalize('NFC')].length >= Number(list.dataset.min) && [...v].length <= Number(list.dataset.max),
};

document.querySelectorAll('[data-password-rules]').forEach((list) => {
  const input = document.getElementById(list.dataset.passwordRules);
  if (!input) {
    return;
  }
  const items = [...list.querySelectorAll('[data-rule]')];
  const metText = document.documentElement.dataset.ruleMet || '';
  const update = () => {
    items.forEach((item) => {
      const met = RULES[item.dataset.rule]?.(input.value, list) ?? false;
      item.classList.toggle('password-rules__item--met', met);
      const state = item.querySelector('[data-rule-state]');
      if (state) {
        state.textContent = met ? ` (${metText})` : '';
      }
    });
  };
  input.addEventListener('input', update);
  update();
});

// ---------------------------------------------------------------- ask first

// Forms with data-confirm (install an update, restore a backup) ask before they are sent.
document.querySelectorAll('form[data-confirm]').forEach((form) => {
  form.addEventListener('submit', (event) => {
    if (!window.confirm(form.dataset.confirm)) {
      event.preventDefault();
    }
  });
});

// ---------------------------------------------------------------- no double submit

document.querySelectorAll('form[method="post"]:not([data-no-busy])').forEach((form) => {
  form.addEventListener('submit', (event) => {
    if (event.defaultPrevented) {
      return;
    }
    if (form.dataset.submitting === 'true') {
      event.preventDefault();
      return;
    }
    form.dataset.submitting = 'true';

    // A disabled button is not sent with the form, so keep its name and value.
    const submitter = event.submitter;
    if (submitter?.name) {
      const copy = document.createElement('input');
      copy.type = 'hidden';
      copy.name = submitter.name;
      copy.value = submitter.value;
      copy.dataset.busyCopy = '';
      form.append(copy);
    }

    window.setTimeout(() => {
      form.querySelectorAll('button[type="submit"], button:not([type])').forEach((button) => {
        button.disabled = true;
        if (button === submitter || (!submitter && button.classList.contains('button--primary'))) {
          button.dataset.label = button.textContent;
          button.textContent = busyText;
          button.setAttribute('aria-busy', 'true');
        }
      });
    }, 0);
  });
});

// Back button (page from the browser cache): make the form usable again.
window.addEventListener('pageshow', (event) => {
  if (!event.persisted) {
    return;
  }
  document.querySelectorAll('form[data-submitting="true"]').forEach((form) => {
    delete form.dataset.submitting;
    form.querySelectorAll('[data-busy-copy]').forEach((copy) => copy.remove());
    form.querySelectorAll('button[disabled]').forEach((button) => {
      button.disabled = false;
      if (button.dataset.label) {
        button.textContent = button.dataset.label;
        button.removeAttribute('aria-busy');
      }
    });
  });
});

// ---------------------------------------------------------------- login lock countdown

const lock = document.querySelector('[data-lock]');
if (lock) {
  const until = Date.now() + Number(lock.dataset.lock) * 1000;
  const fieldset = document.querySelector('[data-locked]');
  const tick = () => {
    const seconds = Math.ceil((until - Date.now()) / 1000);
    if (seconds <= 0) {
      lock.textContent = lock.dataset.textFree;
      lock.classList.replace('notice--error', 'notice--info');
      if (fieldset) {
        fieldset.disabled = false;
      }
      return;
    }
    const minutes = Math.ceil(seconds / 60);
    const text = seconds < 60 ? lock.dataset.textSoon : (minutes === 1 ? lock.dataset.textOne : lock.dataset.textMany.replace('{minutes}', String(minutes)));
    // Only touch the text when it changes, so screen readers do not repeat it every second.
    if (lock.textContent.trim() !== text) {
      lock.textContent = text;
    }
    window.setTimeout(tick, 1000);
  };
  tick();
}
