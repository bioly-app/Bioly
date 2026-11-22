// Sessions end after an hour without activity and at the latest 12 hours after login
// (Auth::user()). While someone works, the page keeps the session alive; shortly before
// the end it warns, so no draft is lost.

const box = document.querySelector('[data-session]');

if (box) {
  const idle = Number(box.dataset.idle) * 1000;
  const endsAt = Number(box.dataset.endsAt) * 1000;
  const warnBefore = 5 * 60 * 1000;
  const text = box.querySelector('[data-session-text]');
  const stay = box.querySelector('[data-session-stay]');
  // The last moment the server heard from this page; the login ends idle ms after it.
  let lastPing = Date.now();

  const ping = async () => {
    lastPing = Date.now();
    const response = await fetch('/admin/api/ping', { headers: { Accept: 'application/json' }, credentials: 'same-origin' }).catch(() => null);
    if (response?.status === 401) {
      window.location.href = '/admin/login';
    }
  };

  // Activity is passed on at most once a minute.
  ['keydown', 'pointerdown', 'input', 'scroll'].forEach((type) => document.addEventListener(type, () => {
    if (Date.now() - lastPing > 60 * 1000) {
      ping();
      if (!stay.hidden) {
        box.hidden = true;
      }
    }
  }, { passive: true, capture: true }));

  stay?.addEventListener('click', () => {
    ping();
    box.hidden = true;
  });

  const check = () => {
    const now = Date.now();
    const time = (ms) => new Date(ms).toLocaleTimeString(document.documentElement.lang, { hour: '2-digit', minute: '2-digit' });

    if (endsAt - now <= warnBefore) {
      text.textContent = box.dataset.textEnds.replace('{time}', time(endsAt));
      stay.hidden = true;
      box.hidden = false;
    } else if (now - lastPing >= idle - warnBefore) {
      text.textContent = box.dataset.textIdle.replace('{time}', time(lastPing + idle));
      stay.hidden = false;
      box.hidden = false;
    }
  };

  window.setInterval(check, 30 * 1000);
}
