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

  all('[data-tabs]').forEach((tabs) => {
    const root = tabs.closest('.card') || tabs.parentElement;
    all('[data-tab]', tabs).forEach((button) => {
      button.addEventListener('click', () => {
        all('[data-tab]', tabs).forEach((item) => item.classList.remove('active'));
        all('[data-panel]', root).forEach((panel) => panel.classList.remove('active'));
        button.classList.add('active');
        one(`[data-panel="${CSS.escape(button.dataset.tab)}"]`, root)?.classList.add('active');
      });
    });
  });

  all('input[type="file"]').forEach((input) => {
    input.addEventListener('change', () => {
      const label = input.parentElement.querySelector('span');
      if (label && input.files?.[0]) label.textContent = input.files[0].name;
    });
  });

  const workspaces = all('[data-section-workspace]');
  workspaces.forEach((workspace) => {
    const navigation = one('[data-section-tabs]', workspace);
    if (!navigation) return;
    const links = all('a[href^="#"]', navigation).filter((link) => {
      const id = decodeURIComponent(link.hash.slice(1));
      const panel = document.getElementById(id);
      return Boolean(id && panel && workspace.contains(panel));
    });
    const panels = links.map((link) => document.getElementById(decodeURIComponent(link.hash.slice(1))));
    if (!links.length) return;

    workspace.classList.add('section-workspace-ready');
    navigation.setAttribute('role', 'tablist');
    links.forEach((link, index) => {
      const panel = panels[index];
      const tabId = `${panel.id}-tab`;
      link.id = link.id || tabId;
      link.setAttribute('role', 'tab');
      link.setAttribute('aria-controls', panel.id);
      panel.setAttribute('role', 'tabpanel');
      panel.setAttribute('aria-labelledby', link.id);
      panel.setAttribute('tabindex', '0');
      panel.dataset.sectionPanel = '';
    });

    let activeIndex = -1;
    const activate = (id, options = {}) => {
      const nextIndex = panels.findIndex((panel) => panel.id === id);
      if (nextIndex < 0) return false;
      const direction = activeIndex < 0 || nextIndex >= activeIndex ? 'forward' : 'backward';
      activeIndex = nextIndex;
      links.forEach((link, index) => {
        const selected = index === nextIndex;
        link.classList.toggle('active', selected);
        link.setAttribute('aria-selected', String(selected));
        link.setAttribute('tabindex', selected ? '0' : '-1');
        panels[index].hidden = !selected;
        panels[index].classList.toggle('active', selected);
        panels[index].classList.remove('slide-forward', 'slide-backward');
      });
      const panel = panels[nextIndex];
      panel.classList.add(direction === 'forward' ? 'slide-forward' : 'slide-backward');
      if (navigation.scrollWidth > navigation.clientWidth) {
        const link = links[nextIndex];
        navigation.scrollTo({ left: Math.max(0, link.offsetLeft - navigation.clientWidth / 3), behavior: options.instant ? 'auto' : 'smooth' });
      }
      if (options.history === 'push' && location.hash !== `#${encodeURIComponent(id)}`) history.pushState({ section: id }, '', `#${encodeURIComponent(id)}`);
      else if (options.history === 'replace') history.replaceState({ section: id }, '', `#${encodeURIComponent(id)}`);
      return true;
    };

    workspace.addEventListener('click', (event) => {
      const link = event.target.closest('a[href^="#"]');
      if (!link || !workspace.contains(link)) return;
      const id = decodeURIComponent(link.hash.slice(1));
      if (!panels.some((panel) => panel.id === id)) return;
      event.preventDefault();
      activate(id, { history: 'push' });
    });
    navigation.addEventListener('keydown', (event) => {
      if (!['ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown', 'Home', 'End'].includes(event.key)) return;
      event.preventDefault();
      let next = links.indexOf(document.activeElement);
      if (event.key === 'Home') next = 0;
      else if (event.key === 'End') next = links.length - 1;
      else next = (next + (['ArrowRight', 'ArrowDown'].includes(event.key) ? 1 : -1) + links.length) % links.length;
      links[next].focus();
      activate(panels[next].id, { history: 'push' });
    });

    const syncFromLocation = () => {
      const id = decodeURIComponent(location.hash.slice(1));
      if (activate(id, { instant: true })) return;
      const target = document.getElementById(id);
      const containingPanel = target ? panels.find((panel) => panel.contains(target)) : null;
      if (containingPanel) { activate(containingPanel.id, { instant: true }); return; }
      const aliasIndex = links.findIndex((link) => (link.dataset.sectionAliases || '').split(',').some((alias) => alias && (id === alias || id.startsWith(alias))));
      if (aliasIndex >= 0) { activate(panels[aliasIndex].id, { instant: true }); return; }
      activate(panels[0].id, { history: location.hash ? undefined : 'replace', instant: true });
    };
    window.addEventListener('popstate', syncFromLocation);
    window.addEventListener('hashchange', syncFromLocation);
    syncFromLocation();
  });

  // Application pipeline: batch selection, validated move proposals, and accessible confirmations.
  const bulkWorkflow = one('[data-bulk-workflow]');
  if (bulkWorkflow) {
    const selections = all('[data-application-select]', bulkWorkflow);
    const selectVisible = one('[data-select-visible]', bulkWorkflow);
    const selectedCount = one('[data-selected-count]', bulkWorkflow);
    const submitButtons = all('[data-confirm-bulk]', bulkWorkflow);
    const updateBulkState = () => {
      const count = selections.filter((input) => input.checked).length;
      if (selectedCount) selectedCount.textContent = `${count} selected`;
      if (selectVisible) {
        selectVisible.checked = count > 0 && count === selections.length;
        selectVisible.indeterminate = count > 0 && count < selections.length;
      }
      submitButtons.forEach((button) => { button.disabled = count === 0; });
    };
    selectVisible?.addEventListener('change', () => {
      selections.forEach((input) => { input.checked = selectVisible.checked; });
      updateBulkState();
    });
    selections.forEach((input) => input.addEventListener('change', updateBulkState));
    bulkWorkflow.addEventListener('submit', (event) => {
      const button = event.submitter;
      const count = selections.filter((input) => input.checked).length;
      if (!button?.matches('[data-confirm-bulk]')) return;
      if (count < 1) { event.preventDefault(); window.alert('Select at least one application.'); return; }
      const message = `${button.dataset.confirmBulk}\n\n${count} application${count === 1 ? '' : 's'} selected.`;
      if (!window.confirm(message)) event.preventDefault();
    });
    updateBulkState();
  }

  const workflowDialog = one('[data-workflow-dialog]');
  let pendingTransition = null;
  const submitWorkflowTransition = (form, status, label) => {
    if (!form || !status) return;
    const needsReason = ['rejected', 'withdrawn'].includes(status);
    if (!workflowDialog || typeof workflowDialog.showModal !== 'function') {
      if (!window.confirm(`${label}? The server will validate this transition before saving.`)) return;
      const reason = needsReason ? window.prompt('Enter the required decision reason:') : '';
      if (needsReason && !reason?.trim()) return;
      let statusInput = form.querySelector('[data-proposed-status]');
      if (!statusInput) { statusInput = document.createElement('input'); statusInput.type = 'hidden'; statusInput.name = 'status'; statusInput.dataset.proposedStatus = ''; form.append(statusInput); }
      statusInput.value = status;
      const remarks = one('[data-transition-remarks]', form);
      if (remarks) remarks.value = reason || '';
      form.requestSubmit();
      return;
    }
    pendingTransition = { form, status };
    const title = one('[data-dialog-title]', workflowDialog);
    const reason = one('[data-dialog-reason]', workflowDialog);
    const reasonHint = one('[data-dialog-reason-hint]', workflowDialog);
    if (title) title.textContent = `${label}?`;
    if (reason) { reason.value = ''; reason.required = needsReason; }
    if (reasonHint) reasonHint.textContent = needsReason ? '(required)' : '(optional)';
    workflowDialog.returnValue = '';
    workflowDialog.showModal();
    window.setTimeout(() => reason?.focus(), 0);
  };

  all('[data-workflow-transition]').forEach((button) => {
    button.addEventListener('click', (event) => {
      event.preventDefault();
      const form = document.getElementById(button.getAttribute('form'));
      submitWorkflowTransition(form, button.value, button.dataset.transitionLabel || button.textContent.trim());
    });
  });

  workflowDialog?.addEventListener('close', () => {
    if (workflowDialog.returnValue !== 'confirm' || !pendingTransition) { pendingTransition = null; return; }
    const { form, status } = pendingTransition;
    const reason = one('[data-dialog-reason]', workflowDialog)?.value.trim() || '';
    if (['rejected', 'withdrawn'].includes(status) && !reason) { pendingTransition = null; return; }
    let statusInput = form.querySelector('[data-proposed-status]');
    if (!statusInput) { statusInput = document.createElement('input'); statusInput.type = 'hidden'; statusInput.name = 'status'; statusInput.dataset.proposedStatus = ''; form.append(statusInput); }
    statusInput.value = status;
    const remarks = one('[data-transition-remarks]', form);
    if (remarks) remarks.value = reason;
    pendingTransition = null;
    form.requestSubmit();
  });

  const workflowBoard = one('[data-application-board]');
  if (workflowBoard) {
    let draggedCard = null;
    all('[data-workflow-card]', workflowBoard).forEach((card) => {
      card.addEventListener('dragstart', (event) => {
        if (event.target.closest('a, button, input, summary, details')) { event.preventDefault(); return; }
        draggedCard = card;
        card.classList.add('is-dragging');
        event.dataTransfer.effectAllowed = 'move';
        event.dataTransfer.setData('text/plain', card.dataset.applicationId || '');
      });
      card.addEventListener('dragend', () => {
        card.classList.remove('is-dragging');
        all('[data-workflow-column]', workflowBoard).forEach((column) => column.classList.remove('is-drop-target'));
        draggedCard = null;
      });
    });
    all('[data-workflow-column]', workflowBoard).forEach((column) => {
      const acceptsDraggedCard = () => {
        const target = column.dataset.dropStatus || '';
        const allowed = (draggedCard?.dataset.allowedStatuses || '').split(' ');
        return target !== '' && allowed.includes(target);
      };
      column.addEventListener('dragover', (event) => {
        if (!acceptsDraggedCard()) return;
        event.preventDefault();
        event.dataTransfer.dropEffect = 'move';
        column.classList.add('is-drop-target');
      });
      column.addEventListener('dragleave', (event) => {
        if (!column.contains(event.relatedTarget)) column.classList.remove('is-drop-target');
      });
      column.addEventListener('drop', (event) => {
        event.preventDefault();
        column.classList.remove('is-drop-target');
        if (!acceptsDraggedCard()) return;
        const status = column.dataset.dropStatus;
        const form = document.getElementById(draggedCard.dataset.actionForm);
        const label = column.querySelector('h2')?.textContent.trim() || status.replaceAll('_', ' ');
        submitWorkflowTransition(form, status, `Move application to ${label}`);
      });
    });
  }

  all('[data-print]').forEach((button) => button.addEventListener('click', () => window.print()));

  const header = one('[data-header]');
  if (header) {
    const updateHeader = () => header.classList.toggle('scrolled', window.scrollY > 20);
    window.addEventListener('scroll', updateHeader, { passive: true });
    updateHeader();
  }
})();
