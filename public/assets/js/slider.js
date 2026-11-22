// Image module: average color behind every image while it loads, and the slider controls.
// Without JavaScript the slider still works: the track scrolls and snaps by itself.


const NS = 'http://www.w3.org/2000/svg';

// Lucide chevron-left, chevron-right, pause and play (ISC, files in resources/ui-icons)
const ICONS = {
  prev: [['path', { d: 'm15 18-6-6 6-6' }]],
  next: [['path', { d: 'm9 18 6-6-6-6' }]],
  pause: [['rect', { x: '14', y: '3', width: '5', height: '18', rx: '1' }], ['rect', { x: '5', y: '3', width: '5', height: '18', rx: '1' }]],
  play: [['path', { d: 'M5 5a2 2 0 0 1 3.008-1.728l11.997 6.998a2 2 0 0 1 .003 3.458l-12 7A2 2 0 0 1 5 19z' }]],
};

const svg = (shapes) => {
  const node = document.createElementNS(NS, 'svg');
  node.setAttribute('viewBox', '0 0 24 24');
  node.setAttribute('aria-hidden', 'true');
  shapes.forEach(([tag, attributes]) => {
    const shape = document.createElementNS(NS, tag);
    Object.entries(attributes).forEach(([name, value]) => shape.setAttribute(name, value));
    node.append(shape);
  });
  return node;
};

const button = (className, label, icon) => {
  const node = document.createElement('button');
  node.type = 'button';
  node.className = className;
  node.setAttribute('aria-label', label);
  node.append(svg(icon));
  return node;
};

const setupSlider = (slider) => {
  const track = slider.querySelector('.slider__track');
  const slides = [...track.children];
  const text = slider.dataset;
  const prev = button('slider__button slider__button--prev', text.textPrev, ICONS.prev);
  const next = button('slider__button slider__button--next', text.textNext, ICONS.next);
  const bar = document.createElement('div');
  const nav = document.createElement('div');
  const dots = document.createElement('div');
  bar.className = 'slider__bar';
  nav.className = 'slider__nav';
  dots.className = 'slider__dots';

  const current = () => Math.round(track.scrollLeft / Math.max(track.clientWidth, 1));
  const go = (index) => {
    const target = (index + slides.length) % slides.length;
    track.scrollTo({ left: target * track.clientWidth });
  };

  const dotButtons = slides.map((_, index) => {
    const dot = document.createElement('button');
    dot.type = 'button';
    dot.className = 'slider__dot';
    dot.setAttribute('aria-label', text.textSlide.replace('{n}', String(index + 1)));
    dot.addEventListener('click', () => go(index));
    dots.append(dot);
    return dot;
  });

  const update = () => {
    const index = current();
    dotButtons.forEach((dot, i) => dot.setAttribute('aria-current', String(i === index)));
    prev.disabled = index === 0;
    next.disabled = index === slides.length - 1;
    // Only the visible slide's link is reachable with Tab.
    slides.forEach((slide, i) => slide.querySelectorAll('a').forEach((link) => { link.tabIndex = i === index ? 0 : -1; }));
  };

  prev.addEventListener('click', () => go(current() - 1));
  next.addEventListener('click', () => go(current() + 1));
  track.addEventListener('scroll', () => window.requestAnimationFrame(update), { passive: true });
  track.addEventListener('keydown', (event) => {
    if (event.key === 'ArrowLeft' || event.key === 'ArrowRight') {
      event.preventDefault();
      go(current() + (event.key === 'ArrowRight' ? 1 : -1));
    }
  });

  nav.append(dots);
  bar.append(prev, nav, next);
  slider.append(bar);

  // Autoplay only when chosen, always with a pause button, and paused for visitors
  // who prefer less motion, while the pointer or focus is inside, and when off screen.
  if ('autoplay' in slider.dataset) {
    const seconds = Math.min(Math.max(Number(slider.dataset.autoplay) || 5, 3), 12);
    const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    let playing = !reduced;
    let hold = false;
    let visible = true;
    const pause = button('slider__pause', '', ICONS.pause);
    const label = () => {
      pause.setAttribute('aria-label', playing ? text.textPause : text.textPlay);
      pause.replaceChildren(svg(playing ? ICONS.pause : ICONS.play));
    };
    pause.addEventListener('click', () => { playing = !playing; label(); });
    label();
    nav.append(pause);
    ['pointerenter', 'focusin'].forEach((type) => slider.addEventListener(type, () => { hold = true; }));
    slider.addEventListener('pointerleave', () => { hold = false; });
    slider.addEventListener('focusout', (event) => { if (!slider.contains(event.relatedTarget)) hold = false; });
    new IntersectionObserver(([entry]) => { visible = entry.isIntersecting; }).observe(slider);
    window.setInterval(() => {
      if (playing && !hold && visible && !document.hidden) {
        go(current() + 1);
      }
    }, seconds * 1000);
  }

  update();
};

export const setupImages = () => {
  document.querySelectorAll('.media-block__image[data-color]').forEach((image) => {
    image.style.backgroundColor = image.dataset.color;
  });
  document.querySelectorAll('[data-slider]').forEach(setupSlider);
};
