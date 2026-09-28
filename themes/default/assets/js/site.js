/* FarosCMS default theme: colour mode, navigation, mobile drawer, sticky header. */
(function () {
  const root = document.documentElement;
  const modeKey = 'faroscms-mode';
  const paletteKey = 'faroscms-palette';
  const media = window.matchMedia('(prefers-color-scheme: dark)');

  const storedPalette = localStorage.getItem(paletteKey);
  if (storedPalette) {
    root.dataset.theme = storedPalette;
  }

  let mode = localStorage.getItem(modeKey) || root.dataset.mode || 'system';
  const modeButtons = document.querySelectorAll('[data-theme-toggle]');

  function isDark(activeMode) {
    return activeMode === 'dark' || (activeMode === 'system' && media.matches);
  }

  function applyMode() {
    const darkOn = isDark(mode);
    root.classList.toggle('dark', darkOn);
    root.dataset.modeCurrent = mode;
    modeButtons.forEach((button) => {
      button.setAttribute('aria-checked', darkOn ? 'true' : 'false');
      button.classList.toggle('is-dark', darkOn);
    });
  }

  applyMode();
  media.addEventListener('change', applyMode);

  modeButtons.forEach((button) => {
    button.addEventListener('click', () => {
      mode = isDark(mode) ? 'light' : 'dark';
      localStorage.setItem(modeKey, mode);
      applyMode();
    });
  });

  if (window.matchMedia('(hover: hover)').matches) {
    const menuItems = document.querySelectorAll('.site-nav .nav-item.has-children');
    menuItems.forEach((item) => {
      let closeTimer = null;
      const open = () => {
        if (closeTimer) {
          window.clearTimeout(closeTimer);
          closeTimer = null;
        }
        item.classList.add('is-open');
      };
      const closeWithDelay = () => {
        if (closeTimer) {
          window.clearTimeout(closeTimer);
        }
        closeTimer = window.setTimeout(() => {
          item.classList.remove('is-open');
          closeTimer = null;
        }, 180);
      };
      item.addEventListener('mouseenter', open);
      item.addEventListener('mouseleave', closeWithDelay);
      item.addEventListener('focusin', open);
      item.addEventListener('focusout', closeWithDelay);
    });
  }

  const mobileOpen = document.querySelector('[data-mobile-open]');
  const mobileClose = document.querySelector('[data-mobile-close]');
  const mobileOverlay = document.querySelector('[data-mobile-overlay]');
  const mobileDrawer = document.querySelector('[data-mobile-drawer]');
  const mobileToggles = document.querySelectorAll('[data-mobile-toggle]');

  const openMobileMenu = () => {
    document.body.classList.add('mobile-menu-open');
    if (mobileDrawer) {
      mobileDrawer.setAttribute('aria-hidden', 'false');
    }
  };

  const closeMobileMenu = () => {
    document.body.classList.remove('mobile-menu-open');
    if (mobileDrawer) {
      mobileDrawer.setAttribute('aria-hidden', 'true');
    }
  };

  if (mobileOpen) {
    mobileOpen.addEventListener('click', openMobileMenu);
  }
  if (mobileClose) {
    mobileClose.addEventListener('click', closeMobileMenu);
  }
  if (mobileOverlay) {
    mobileOverlay.addEventListener('click', closeMobileMenu);
  }

  mobileToggles.forEach((button) => {
    const parent = button.closest('.mobile-item');
    if (!parent) return;

    if (parent.classList.contains('is-trail') || parent.classList.contains('is-active')) {
      parent.classList.add('is-open');
      button.setAttribute('aria-expanded', 'true');
      const icon = button.querySelector('span');
      if (icon) icon.textContent = '-';
    }

    button.addEventListener('click', () => {
      const open = parent.classList.toggle('is-open');
      button.setAttribute('aria-expanded', open ? 'true' : 'false');
      const icon = button.querySelector('span');
      if (icon) icon.textContent = open ? '-' : '+';
    });
  });

  if (mobileDrawer) {
    mobileDrawer.querySelectorAll('a').forEach((link) => {
      link.addEventListener('click', closeMobileMenu);
    });
  }

  window.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') {
      closeMobileMenu();
    }
  });

  window.addEventListener('resize', () => {
    if (window.innerWidth > 780) {
      closeMobileMenu();
    }
  });

  const headerEl = document.querySelector('.site-header');
  const headerSpacer = document.querySelector('[data-header-spacer]');
  if (headerEl && headerSpacer) {
    let sticky = false;

    const syncHeaderHeight = () => {
      const height = headerEl.offsetHeight;
      document.documentElement.style.setProperty('--header-height', height + 'px');
      headerSpacer.style.height = sticky ? height + 'px' : '0px';
    };

    const syncSticky = () => {
      const shouldStick = window.scrollY > 200;
      if (shouldStick === sticky) return;
      sticky = shouldStick;
      document.body.classList.toggle('header-sticky', sticky);
      headerSpacer.style.height = sticky ? (headerEl.offsetHeight + 'px') : '0px';
    };

    syncHeaderHeight();
    syncSticky();

    window.addEventListener('scroll', syncSticky, { passive: true });
    window.addEventListener('resize', () => {
      syncHeaderHeight();
      syncSticky();
    });
  }

})();
