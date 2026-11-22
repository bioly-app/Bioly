// Bioly link page: tiny start script. Everything else loads on demand.

// Scripts loaded later come with their own version from the page (see asset() in Twig),
// so a changed file is never taken from an old browser cache.
const script = (name) => document.body.dataset[`script${name}`] || `./${name.toLowerCase()}.js`;

// In the backend preview links do not navigate; popups like the multilink still open.
if (document.body.dataset.preview === 'true') {
  let toastTimer = null;
  document.addEventListener('click', (event) => {
    const link = event.target.closest('a[href]');
    if (!link) {
      return;
    }
    event.preventDefault();
    let toast = document.querySelector('.preview-toast');
    if (!toast) {
      toast = document.createElement('p');
      toast.className = 'preview-toast';
      toast.setAttribute('role', 'status');
      document.body.append(toast);
    }
    try {
      toast.textContent = JSON.parse(document.getElementById('site-texts')?.textContent || '{}').previewLink ?? '';
    } catch {
      toast.textContent = '';
    }
    toast.hidden = false;
    window.clearTimeout(toastTimer);
    toastTimer = window.setTimeout(() => { toast.hidden = true; }, 1800);
  }, true);
}

document.addEventListener('click', async (event) => {
  const button = event.target.closest('[data-multilink]');

  if (!button) {
    return;
  }

  event.preventDefault();
  const { openMultilink } = await import(script('Multilink'));
  openMultilink(button);
});

// Image module: only loaded when the page has images.
if (document.querySelector('.media-block')) {
  import(script('Slider')).then(({ setupImages }) => setupImages());
}

// Countdown module: only loaded when the page has one.
if (document.querySelector('[data-countdown]')) {
  import(script('Countdown')).then(({ setupCountdowns }) => setupCountdowns());
}

// iOS shows :active styles (press feedback on buttons and tiles) only with a touch listener.
document.addEventListener('touchstart', () => {}, { passive: true });
