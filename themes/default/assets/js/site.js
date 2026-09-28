/* FarosCMS default theme: colour mode, navigation, accessible mobile drawer, sticky header. */
(function () {
  const root = document.documentElement;
  const modeKey = 'faroscms-mode';
  const paletteKey = 'faroscms-palette';
  const media = window.matchMedia('(prefers-color-scheme: dark)');

  const storage = {
    get(key) {
      try { return localStorage.getItem(key); } catch (e) { return null; }
    },
    set(key, value) {
      try { localStorage.setItem(key, value); } catch (e) { /* private mode */ }
    },
  };

  /* Colour mode ---------------------------------------------------------- */

  const storedPalette = storage.get(paletteKey);
  if (storedPalette) {
    root.dataset.theme = storedPalette;
  }

  let mode = storage.get(modeKey) || root.dataset.mode || 'system';
  const modeButtons = document.querySelectorAll('[data-theme-toggle]');

  function isDark(activeMode) {
    return activeMode === 'dark' || (activeMode === 'system' && media.matches);
  }

  function applyMode() {
    const darkOn = isDark(mode);
    root.dataset.mode = mode;
    root.classList.toggle('dark', darkOn);
    root.classList.toggle('light', !darkOn);
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
      storage.set(modeKey, mode);
      applyMode();
    });
  });

  /* Desktop dropdowns ----------------------------------------------------- */

  const menuItems = document.querySelectorAll('.site-nav .nav-item.has-children');
  if (window.matchMedia('(hover: hover)').matches) {
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

  /* Mobile drawer: dialog semantics, focus moves in, stays in, and returns. */

  const mobileOpen = document.querySelector('[data-mobile-open]');
  const mobileClose = document.querySelector('[data-mobile-close]');
  const mobileOverlay = document.querySelector('[data-mobile-overlay]');
  const mobileDrawer = document.querySelector('[data-mobile-drawer]');
  const mobileToggles = document.querySelectorAll('[data-mobile-toggle]');
  const focusableSelector = 'a[href], button:not([disabled]), input:not([disabled]), select, textarea, [tabindex]:not([tabindex="-1"])';

  const drawerIsOpen = () => document.body.classList.contains('mobile-menu-open');

  const openMobileMenu = () => {
    if (!mobileDrawer) return;
    document.body.classList.add('mobile-menu-open');
    mobileDrawer.setAttribute('aria-hidden', 'false');
    if (mobileOpen) mobileOpen.setAttribute('aria-expanded', 'true');
    window.setTimeout(() => {
      (mobileClose || mobileDrawer).focus();
    }, 30);
  };

  const closeMobileMenu = (restoreFocus = true) => {
    if (!mobileDrawer || !drawerIsOpen()) return;
    document.body.classList.remove('mobile-menu-open');
    mobileDrawer.setAttribute('aria-hidden', 'true');
    if (mobileOpen) {
      mobileOpen.setAttribute('aria-expanded', 'false');
      if (restoreFocus) mobileOpen.focus();
    }
  };

  if (mobileOpen) mobileOpen.addEventListener('click', openMobileMenu);
  if (mobileClose) mobileClose.addEventListener('click', () => closeMobileMenu());
  if (mobileOverlay) mobileOverlay.addEventListener('click', () => closeMobileMenu());

  if (mobileDrawer) {
    mobileDrawer.addEventListener('keydown', (event) => {
      if (event.key !== 'Tab') return;
      const focusable = Array.from(mobileDrawer.querySelectorAll(focusableSelector))
        .filter((el) => el.offsetParent !== null);
      if (focusable.length === 0) return;
      const first = focusable[0];
      const last = focusable[focusable.length - 1];
      if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus();
      } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
      }
    });
    mobileDrawer.querySelectorAll('a').forEach((link) => {
      link.addEventListener('click', () => closeMobileMenu(false));
    });
  }

  mobileToggles.forEach((button) => {
    const parent = button.closest('.mobile-item');
    if (!parent) return;
    const setOpen = (open) => {
      parent.classList.toggle('is-open', open);
      button.setAttribute('aria-expanded', open ? 'true' : 'false');
      const icon = button.querySelector('span');
      if (icon) icon.textContent = open ? '−' : '+';
    };
    if (parent.classList.contains('is-trail') || parent.classList.contains('is-active')) {
      setOpen(true);
    }
    button.addEventListener('click', () => setOpen(!parent.classList.contains('is-open')));
  });

  window.addEventListener('keydown', (event) => {
    if (event.key !== 'Escape') return;
    if (drawerIsOpen()) {
      closeMobileMenu();
      return;
    }
    menuItems.forEach((item) => item.classList.remove('is-open'));
  });

  window.addEventListener('resize', () => {
    if (window.innerWidth > 860) {
      closeMobileMenu(false);
    }
  });

  /* Sticky header ---------------------------------------------------------- */

  const headerEl = document.querySelector('.site-header');
  const headerSpacer = document.querySelector('[data-header-spacer]');
  if (headerEl && headerSpacer) {
    let sticky = false;

    const syncHeaderHeight = () => {
      const height = headerEl.offsetHeight;
      root.style.setProperty('--header-height', height + 'px');
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
