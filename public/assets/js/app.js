(() => {
  'use strict';

  const one = (selector, root = document) => root.querySelector(selector);
  const all = (selector, root = document) => [...root.querySelectorAll(selector)];

  const menuToggle = one('[data-menu-toggle]');
  const menu = one('[data-menu]');
  if (menuToggle && menu) {
    menuToggle.addEventListener('click', () => {
      const open = menu.classList.toggle('open');
      menuToggle.setAttribute('aria-expanded', String(open));
    });
  }

  const sidebar = one('[data-sidebar]');
  const sidebarToggles = all('[data-sidebar-toggle]');
  const setSidebarOpen = (open) => {
    if (!sidebar) return;
    sidebar.classList.toggle('open', open);
    document.body.classList.toggle('sidebar-open', open);
    sidebarToggles.forEach((button) => button.setAttribute('aria-expanded', String(open)));
  };
  sidebarToggles.forEach((button) => {
    button.setAttribute('aria-expanded', 'false');
    button.addEventListener('click', () => setSidebarOpen(!sidebar?.classList.contains('open')));
  });
  document.addEventListener('click', (event) => {
    if (window.innerWidth > 900 || !sidebar?.classList.contains('open')) return;
    if (!sidebar.contains(event.target) && !event.target.closest('[data-sidebar-toggle]')) setSidebarOpen(false);
  });
  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') {
      setSidebarOpen(false);
      menu?.classList.remove('open');
      menuToggle?.setAttribute('aria-expanded', 'false');
    }
  });
  window.addEventListener('resize', () => {
    if (window.innerWidth > 900) setSidebarOpen(false);
  }, { passive: true });

  all('[data-dismiss]').forEach((button) => {
    button.addEventListener('click', () => button.closest('.alert, .flash-bar')?.remove());
  });

  all('[data-password-toggle]').forEach((button) => {
    button.addEventListener('click', () => {
      const input = button.parentElement.querySelector('[data-password]');
      if (!input) return;
      const visible = input.type === 'text';
      input.type = visible ? 'password' : 'text';
      button.textContent = visible ? 'Show' : 'Hide';
    });
  });

  all('[data-confirm]').forEach((element) => {
    element.addEventListener('click', (event) => {
      if (!window.confirm(element.dataset.confirm)) event.preventDefault();
    });
  });

  all('.accordion-item > button').forEach((button) => {
    button.addEventListener('click', () => {
      const panel = button.nextElementSibling;
      const isOpen = button.getAttribute('aria-expanded') === 'true';
      button.setAttribute('aria-expanded', String(!isOpen));
      button.querySelector('i').textContent = isOpen ? '+' : '−';
      panel.hidden = isOpen;
    });
  });

  const tabs = one('[data-tabs]');
  if (tabs) {
    all('[data-tab]', tabs).forEach((button) => {
      button.addEventListener('click', () => {
        all('[data-tab]', tabs).forEach((item) => item.classList.remove('active'));
        all('[data-panel]').forEach((panel) => panel.classList.remove('active'));
        button.classList.add('active');
        one(`[data-panel="${button.dataset.tab}"]`)?.classList.add('active');
      });
    });
  }

  all('input[type="file"]').forEach((input) => {
    input.addEventListener('change', () => {
      const label = input.parentElement.querySelector('span');
      if (label && input.files?.[0]) label.textContent = input.files[0].name;
    });
  });

  const formSections = all('.application-forms > section[id]');
  const stepLinks = all('.application-steps a');
  if (formSections.length && 'IntersectionObserver' in window) {
    const observer = new IntersectionObserver((entries) => {
      entries.forEach((entry) => {
        if (!entry.isIntersecting) return;
        stepLinks.forEach((link) => link.classList.toggle('active', link.getAttribute('href') === `#${entry.target.id}`));
      });
    }, { rootMargin: '-20% 0px -65% 0px' });
    formSections.forEach((section) => observer.observe(section));
  }

  all('[data-print]').forEach((button) => button.addEventListener('click', () => window.print()));

  const header = one('[data-header]');
  if (header) {
    const updateHeader = () => header.classList.toggle('scrolled', window.scrollY > 20);
    window.addEventListener('scroll', updateHeader, { passive: true });
    updateHeader();
  }
})();
