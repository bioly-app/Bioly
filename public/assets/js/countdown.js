// Countdown module: keeps the countdown current. The page is a static file, so the state
// published on the server (before, during, after the event) can be old; this script sets it
// from the visitor's clock. Times are absolute (ISO with offset), so every time zone fits.

const MINUTE = 60000;

const fill = (text, values) => Object.entries(values).reduce((out, [key, value]) => out.replaceAll(`{${key}}`, String(value)), text);

const update = (box, now) => {
  const start = Date.parse(box.dataset.start);
  const end = Date.parse(box.dataset.end);
  if (Number.isNaN(start) || Number.isNaN(end)) {
    return;
  }
  const state = now < start ? 'upcoming' : now < end ? 'live' : 'over';

  if (state === 'over' && box.dataset.after === 'hide') {
    box.hidden = true;
    return;
  }
  box.querySelectorAll('[data-part]').forEach((part) => { part.hidden = part.dataset.part !== state; });
  if (state !== 'upcoming') {
    return;
  }

  let texts = {};
  try {
    texts = JSON.parse(box.dataset.texts || '{}');
  } catch {
    return;
  }
  const left = Math.max(0, start - now);
  const days = Math.floor(left / (24 * 60 * MINUTE));
  const hours = Math.floor(left / (60 * MINUTE)) % 24;
  const minutes = Math.floor(left / MINUTE) % 60;

  const set = (unit, value) => {
    const target = box.querySelector(`[data-unit="${unit}"]`);
    if (target) {
      target.textContent = value;
    }
  };
  set('days', String(days));
  set('hours', String(hours).padStart(2, '0'));
  set('minutes', String(minutes).padStart(2, '0'));
  const dayLabel = box.querySelector('[data-label="days"]');
  if (dayLabel) {
    dayLabel.textContent = days === 1 ? texts.day : texts.days;
  }
  const timer = box.querySelector('[role="timer"]');
  if (timer && texts.timer) {
    timer.setAttribute('aria-label', fill(texts.timer, { days, hours, minutes }));
  }

  const pill = box.querySelector('[data-in]');
  if (pill) {
    pill.textContent = days >= 2 ? fill(texts.inDays, { n: days })
      : days === 1 ? texts.inDay
        : hours >= 1 ? fill(texts.inHours, { n: hours })
          : fill(texts.inMinutes, { n: Math.max(1, minutes) });
  }
};

// Compact view: details that do not fit on one line run slowly from right to left in a loop.
// A hidden copy follows the text, so the line never runs empty.
const fitDetails = (line) => {
  line.querySelector('[data-copy]')?.remove();
  line.classList.remove('is-scrolling');
  if (line.scrollWidth - line.clientWidth <= 2) {
    return;
  }
  // Pages published before this version have no inner span yet: wrap the text.
  let text = line.querySelector('.countdown__meta-text');
  if (!text) {
    text = document.createElement('span');
    text.className = 'countdown__meta-text';
    text.append(...line.childNodes);
    line.append(text);
  }
  const copy = text.cloneNode(true);
  copy.dataset.copy = '';
  copy.setAttribute('aria-hidden', 'true');
  line.append(copy);
  line.classList.add('is-scrolling');
  // One text width (with its gap) per round, about 30 pixels per second.
  const distance = text.offsetWidth;
  line.style.setProperty('--scroll-distance', `${distance}px`);
  line.style.setProperty('--scroll-time', `${Math.max(4, distance / 30)}s`);
};

const watchDetails = (boxes) => {
  const lines = boxes.flatMap((box) => [...box.querySelectorAll('.countdown__meta')])
    .filter((line) => line.closest('.countdown--compact'));
  if (lines.length === 0) {
    return;
  }
  lines.forEach(fitDetails);
  if ('ResizeObserver' in window) {
    const observer = new ResizeObserver((entries) => entries.forEach((entry) => fitDetails(entry.target)));
    lines.forEach((line) => observer.observe(line));
  }
};

export const setupCountdowns = () => {
  const boxes = [...document.querySelectorAll('[data-countdown]')];
  watchDetails(boxes);
  const tick = () => {
    const now = Date.now();
    boxes.forEach((box) => update(box, now));
  };
  tick();
  // Minutes are the smallest unit shown, so twice a minute is enough.
  window.setInterval(tick, MINUTE / 2);
};
