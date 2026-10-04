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

  // Settings: explain the selected delivery mode and keep SMTP credentials private.
  all('[data-mail-settings]').forEach((settings) => {
    const driver = one('[data-mail-driver]', settings);
    const auth = one('[data-mail-auth]', settings);
    const password = one('[data-mail-password]', settings);
    const clearPassword = one('[data-mail-clear-password]', settings);
    const passwordToggle = one('[data-mail-password-toggle]', settings);
    const modeBadge = one('[data-mail-mode-badge]', settings);
    const modeCopy = one('[data-mail-mode-copy]', settings);
    const testButton = one('[data-mail-test]', settings);
    if (!driver) return;

    const syncMailSettings = () => {
      const smtp = driver.value === 'smtp';
      const authenticated = Boolean(auth?.checked);
      const clearing = Boolean(clearPassword?.checked);
      settings.classList.toggle('is-mail-log-mode', !smtp);
      all('[data-smtp-required]', settings).forEach((field) => { field.required = smtp; });
      all('[data-smtp-auth-required]', settings).forEach((field) => { field.required = smtp && authenticated; });
      if (password) {
        password.disabled = clearing;
        password.required = smtp && authenticated && password.dataset.passwordConfigured !== '1' && !clearing;
        if (clearing) password.value = '';
      }
      if (passwordToggle) passwordToggle.disabled = clearing;
      if (testButton) {
        testButton.disabled = !smtp;
        testButton.title = smtp ? 'Save these settings and send a real test email.' : 'Select SMTP delivery to send a test email.';
      }
      if (modeBadge) {
        modeBadge.classList.toggle('is-live', smtp);
        modeBadge.classList.toggle('is-log', !smtp);
        modeBadge.textContent = smtp ? 'SMTP enabled' : 'Local log mode';
      }
      const modeTitle = modeCopy?.querySelector('b');
      const modeDescription = modeCopy?.querySelector('span');
      if (modeTitle) modeTitle.textContent = smtp ? 'Live delivery selected' : 'Development safety mode';
      if (modeDescription) modeDescription.textContent = smtp
        ? 'Messages will be delivered through the server below after validation.'
        : 'Non-sensitive messages are logged; OTPs and account links are suppressed.';
    };

    passwordToggle?.addEventListener('click', () => {
      if (!password) return;
      const visible = password.type === 'text';
      password.type = visible ? 'password' : 'text';
      passwordToggle.textContent = visible ? 'Show' : 'Hide';
      passwordToggle.setAttribute('aria-pressed', String(!visible));
    });
    driver.addEventListener('change', syncMailSettings);
    auth?.addEventListener('change', syncMailSettings);
    clearPassword?.addEventListener('change', syncMailSettings);
    syncMailSettings();
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
        if (selected) link.setAttribute('aria-current', 'step');
        else link.removeAttribute('aria-current');
        link.setAttribute('tabindex', selected ? '0' : '-1');
        panels[index].hidden = !selected;
        panels[index].classList.toggle('active', selected);
        panels[index].classList.remove('slide-forward', 'slide-backward');
      });
      const panel = panels[nextIndex];
      panel.classList.add(direction === 'forward' ? 'slide-forward' : 'slide-backward');
      if (workspace.classList.contains('admission-wizard')) {
        const currentStep = one('[data-wizard-current-step]');
        const currentLabel = one('b', links[nextIndex]);
        if (currentStep && currentLabel) currentStep.textContent = currentLabel.textContent.trim();
      }
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

  // Make unsaved admission configuration visible without changing normal form submission.
  const admissionWizard = one('.admission-wizard');
  const wizardSaveState = one('[data-wizard-save-state]');
  if (admissionWizard && wizardSaveState) {
    const wizardForms = all('form', admissionWizard);
    const updateWizardSaveState = () => {
      const dirtyCount = wizardForms.filter((form) => form.dataset.unsaved === 'true').length;
      wizardSaveState.classList.toggle('is-dirty', dirtyCount > 0);
      wizardSaveState.classList.remove('is-saving');
      wizardSaveState.textContent = dirtyCount > 0
        ? `${dirtyCount} form${dirtyCount === 1 ? '' : 's'} with unsaved changes — use its Save button`
        : '✓ All displayed values are saved';
    };
    wizardForms.forEach((form) => {
      const markDirty = (event) => {
        if (event.target.matches('button, input[type="hidden"], input[type="submit"]')) return;
        form.dataset.unsaved = 'true';
        updateWizardSaveState();
      };
      form.addEventListener('input', markDirty);
      form.addEventListener('change', markDirty);
      form.addEventListener('reset', () => {
        window.setTimeout(() => { delete form.dataset.unsaved; updateWizardSaveState(); }, 0);
      });
      form.addEventListener('submit', () => {
        delete form.dataset.unsaved;
        wizardSaveState.classList.remove('is-dirty');
        wizardSaveState.classList.add('is-saving');
        wizardSaveState.textContent = 'Saving and validating changes…';
      });
    });
    window.addEventListener('beforeunload', (event) => {
      if (!wizardForms.some((form) => form.dataset.unsaved === 'true')) return;
      event.preventDefault();
      event.returnValue = '';
    });
  }

  // Admission setup: show the seat arithmetic before the administrator submits it.
  all('[data-seat-matrix]').forEach((form) => {
    const capacity = one('[data-seat-capacity]', form);
    const seatInputs = all('[data-seat-count]', form);
    const balance = one('[data-seat-balance]', form);
    if (!capacity || !balance || !seatInputs.length) return;
    const updateSeatBalance = () => {
      const approved = Math.max(0, Number.parseInt(capacity.value || '0', 10) || 0);
      const assigned = seatInputs.reduce((total, input) => total + Math.max(0, Number.parseInt(input.value || '0', 10) || 0), 0);
      const difference = approved - assigned;
      balance.classList.remove('is-balanced', 'is-short', 'is-over');
      if (difference === 0) {
        balance.classList.add('is-balanced');
        balance.querySelector('b').textContent = `Ready to save — ${assigned} of ${approved} seats assigned.`;
      } else if (difference > 0) {
        balance.classList.add('is-short');
        balance.querySelector('b').textContent = `Assign ${difference} more seat${difference === 1 ? '' : 's'} before saving.`;
      } else {
        balance.classList.add('is-over');
        balance.querySelector('b').textContent = `Remove ${Math.abs(difference)} seat${difference === -1 ? '' : 's'} before saving.`;
      }
    };
    [capacity, ...seatInputs].forEach((input) => input.addEventListener('input', updateSeatBalance));
    updateSeatBalance();
  });

  // Keep ordinary form customisation simple while preserving advanced keys and option formats.
  all('[data-key-builder]').forEach((builder) => {
    const source = one('[data-key-source]', builder);
    const target = one('[data-key-target]', builder);
    if (!source || !target) return;
    let manuallyEdited = target.value.trim() !== '';
    target.addEventListener('input', () => { manuallyEdited = target.value.trim() !== ''; });
    source.addEventListener('input', () => {
      if (manuallyEdited) return;
      target.value = source.value.toLowerCase().trim().replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '');
    });
  });
  const optionFieldTypes = new Set(['select', 'radio', 'checkbox', 'multiselect']);
  all('[data-field-builder]').forEach((builder) => {
    const type = one('[data-field-type]', builder);
    const options = one('[data-field-options]', builder);
    if (!type || !options) return;
    const syncOptions = () => { options.hidden = !optionFieldTypes.has(type.value); };
    type.addEventListener('change', syncOptions);
    syncOptions();
  });

  // Admission setup help is available beside every option heading, not hidden in a separate manual.
  const admissionOptionHelp = {
    'Academic session': 'Select the academic year to which this admission notice and its applications belong.',
    'Cycle name': 'The public name staff and applicants use for this admission process.',
    'Cycle code': 'A short unique identifier used in administration and reports, for example UG-2027.',
    'Public slug': 'The web-address ending for the public Admissions page. Use a short readable value.',
    'Applications open': 'The date and time when eligible applicants can start or access applications.',
    'Applications close': 'The final date and time for starting, editing and submitting applications.',
    'Correction deadline': 'Optional final time by which requested applicant corrections must be completed.',
    'Application number prefix': 'Characters placed before each generated application number.',
    'Maximum preferences': 'The maximum number of programmes one applicant may rank in this cycle.',
    'Closing-soon threshold (hours)': 'How many hours before closing the public site begins showing a closing-soon warning.',
    'Public admission summary': 'A short introduction displayed on the public admission notice.',
    'Applicant instructions': 'Step-by-step guidance applicants should read before and during application.',
    'Applicant declaration': 'The statement applicants must accept before final submission.',
    'Prospectus PDF (maximum 10 MB)': 'Optional prospectus applicants can open from the public admission notice.',
    'General category minimum (%)': 'Minimum Class 12 percentage used for applicants in the General category.',
    'Reserved category minimum (%)': 'Minimum Class 12 percentage used for non-General reservation categories.',
    'Programme state': 'Active programmes can be selected by applicants; inactive ones remain stored but unavailable.',
    'Minimum age': 'Youngest permitted age when this admission cycle opens.',
    'Maximum age': 'Oldest permitted age when this admission cycle opens. Leave blank when no maximum applies.',
    'Accepted entrance examinations': 'Names of qualifying entrance examinations accepted for this programme.',
    'Programme': 'The programme being included in this admission cycle.',
    'Initial capacity': 'The approved total intake before it is divided into categories and seat pools.',
    'Application fee': 'Amount assessed for submitting an application to this programme.',
    'Admission fee': 'Amount assessed after an applicant is selected for admission.',
    'Approved programme intake': 'The final seat total. Every category and seat-pool row must add up to this number.',
    'Reservation category': 'The reservation group that may receive this row of seats, such as General, SC or ST.',
    'Seat pool / quota': 'The source or pool of these seats, such as State, Management or NRI.',
    'Number of seats': 'How many seats belong to this category and seat-pool combination.',
    'Applicant information to check': 'Choose the applicant answer the eligibility engine should examine.',
    'Condition': 'How the applicant answer is compared with the required value, for example at least or contains.',
    'Required value': 'The threshold, accepted text or list the applicant answer is compared against.',
    'Plain-language result shown to staff': 'A readable explanation recorded with the eligibility result.',
    'Rule category': 'Groups this eligibility check as marks, age, subject, entrance or another rule type.',
    'Evaluation order': 'Controls which eligibility check is evaluated first. Lower numbers come first.',
    'Fee type': 'Application fee is due during application; admission fee is due after selection.',
    'Category override': 'Use All categories for the normal amount, or choose one category for a special amount.',
    'State': 'Active fee rules are used for assessment; inactive rules are retained but ignored.',
    'Label': 'The fee name shown to applicants and staff.',
    'Amount': 'The base fee amount in Indian rupees.',
    'Late fee': 'Optional extra amount charged after the normal due date.',
    'Due date': 'Optional deadline for this fee rule.',
    'Refund policy': 'Plain-language information explaining whether and when this fee may be refunded.',
    'Section title': 'The heading applicants see for this group of questions.',
    'Short introduction': 'A sentence telling applicants what information belongs in this section.',
    'Visibility': 'Active items appear in the application; inactive items remain stored but are hidden.',
    'Internal section key': 'Stable system name used by corrections and saved applications. Avoid changing an existing key.',
    'Internal key (optional)': 'A stable system name. Leave blank to create it automatically from the title.',
    'Display order': 'Controls position within the form. Lower numbers appear first.',
    'Form section': 'The section where this applicant question appears.',
    'Question shown to applicants': 'The exact label applicants read above this answer control.',
    'Answer type': 'Choose whether applicants enter text, a date, a number or select from choices.',
    'Example answer / placeholder': 'Optional example shown inside an empty answer control.',
    'Helpful instruction': 'Optional guidance displayed below the question.',
    'Answer choices': 'For choice questions, enter one visible option per line.',
    'Internal field key': 'Stable system name used by responses, exports and conditions. Avoid changing an existing key.',
    'Internal field key (optional)': 'Leave blank to generate a stable key automatically from the question label.',
    'Built-in data connection': 'Connects this definition to an existing profile or academic field. Existing values should normally remain unchanged.',
    'Show only when (JSON)': 'Advanced condition that shows this question only when another answer matches.',
    'Document type': 'The kind of evidence applicants must upload, such as photograph or marksheet.',
    'Programme scope': 'All programmes applies this requirement everywhere; choose one programme to limit it.',
    'Category scope': 'All categories applies this requirement to everyone; choose one category to limit it.',
    'Stage': 'Application-stage documents are required before submission; admission-stage documents are collected later.',
    'New name': 'Public name for the new draft created by duplication.',
    'New code': 'Unique administrative code for the duplicated draft.',
    'New slug': 'Unique public web-address ending for the duplicated draft.',
    'Opening': 'Opening date and time for the duplicated cycle.',
    'Closing': 'Closing date and time for the duplicated cycle.',
    'Cycle identity, dates and applicant copy': 'Configure the public notice, application schedule and instructions applicants will read.',
    'Choose programmes and intake defaults': 'Select available programmes and enter their basic marks, age and entrance requirements.',
    'Basic entry requirements': 'These common thresholds are used by the detailed eligibility checks in the next step.',
    'Divide seats and decide who is eligible': 'Make category seats equal approved intake, then define the checks applicants must pass.',
    'Seat distribution': 'Divide approved intake among reservation categories and seat pools without exceeding the total.',
    'Eligibility checks': 'Rules that compare saved applicant information with programme requirements.',
    'Set application and admission fees': 'Configure the charges used by server-side fee assessment.',
    'Fee rules': 'Base and category-specific application or admission charges for this programme.',
    'Design the application form': 'Organise sections and questions, then verify them in the student-style preview.',
    'Form sections': 'Groups of related questions shown together to applicants.',
    'Applicant questions': 'Individual answer controls applicants complete inside form sections.',
    'Student view and quick edit': 'A safe, disabled preview using the same visual components applicants see. Edit links return to the matching configuration.',
    'Choose required documents': 'Select what evidence is collected, from whom and at which stage.',
    'Review and publish the admission notice': 'Resolve readiness errors, preview the public notice and create an immutable published version.',
  };
  const ownText = (element) => [...element.childNodes].filter((node) => node.nodeType === Node.TEXT_NODE).map((node) => node.textContent).join(' ').replace(/\s+/g, ' ').replace(/\s*\*\s*$/, '').trim();
  const addOptionHelp = (heading) => {
    if (!heading || one('.option-help', heading) || heading.querySelector(':scope > small')) return;
    const label = ownText(heading);
    if (!label) return;
    const help = admissionOptionHelp[label] || `Use this setting to configure “${label}” for the admission cycle. Save the current form to apply changes.`;
    const marker = document.createElement('span');
    marker.className = 'option-help'; marker.tabIndex = 0; marker.setAttribute('role', 'button');
    marker.setAttribute('aria-label', `Help for ${label}: ${help}`); marker.setAttribute('aria-expanded', 'false'); marker.dataset.help = help; marker.textContent = '?';
    const toggleHelp = (open = !marker.classList.contains('is-open')) => { marker.classList.toggle('is-open', open); marker.setAttribute('aria-expanded', String(open)); };
    marker.addEventListener('click', (event) => { event.preventDefault(); event.stopPropagation(); toggleHelp(); });
    marker.addEventListener('keydown', (event) => {
      if (event.key === 'Escape') toggleHelp(false);
      if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); toggleHelp(); }
    });
    heading.classList.add('has-option-help'); heading.append(marker);
  };
  all('.admission-wizard label > span:not(.sr-only), .admission-wizard .workspace-section-heading h2, .admission-wizard .card-heading h2, .admission-wizard .builder-block-heading h3, .admission-wizard .designer-column-heading h3').forEach(addOptionHelp);
  document.addEventListener('click', (event) => all('.option-help.is-open').forEach((marker) => { if (!marker.contains(event.target)) { marker.classList.remove('is-open'); marker.setAttribute('aria-expanded', 'false'); } }));
  all('.admission-wizard details > summary').forEach((summary) => {
    if (!summary.title) summary.title = `Open or close “${summary.textContent.replace(/\s+/g, ' ').trim()}”.`;
  });
  all('.admission-wizard button, .admission-wizard a.button').forEach((action) => {
    if (action.title) return;
    const text = action.textContent.replace(/\s+/g, ' ').trim();
    const lower = text.toLowerCase();
    let purpose = `Select “${text}”.`;
    if (lower.includes('continue') || lower.includes('next')) purpose = `Move to the next setup step. Unsaved values in the current form are not saved unless this button also says Save.`;
    else if (lower.startsWith('save') || lower.startsWith('update')) purpose = `Save the changes in this form to the admission cycle.`;
    else if (lower.startsWith('add') || lower.startsWith('assign')) purpose = `Create this new configuration item in the current admission cycle.`;
    else if (lower.includes('delete') || lower.includes('remove')) purpose = `Remove this unused draft item after confirmation.`;
    else if (lower.includes('publish')) purpose = `Publish the validated admission notice and freeze a versioned configuration snapshot.`;
    else if (lower.includes('preview')) purpose = `Open the applicant-facing public preview in a new tab.`;
    action.title = purpose;
  });

  // The student-style preview links directly back to the matching section or question editor.
  all('[data-open-editor]').forEach((link) => {
    link.addEventListener('click', (event) => {
      const editor = document.getElementById(link.dataset.openEditor || '');
      if (!editor) return;
      event.preventDefault();
      if (editor instanceof HTMLDetailsElement) editor.open = true;
      editor.classList.remove('editor-highlight'); void editor.offsetWidth; editor.classList.add('editor-highlight');
      history.replaceState(history.state, '', `#${encodeURIComponent(editor.id)}`);
      editor.scrollIntoView({ behavior: 'smooth', block: 'center' });
      window.setTimeout(() => one('input, select, textarea', editor)?.focus({ preventScroll: true }), 350);
    });
  });

  // Applicant documents save immediately after a file is selected; the normal submit remains as a no-JavaScript fallback.
  all('[data-auto-upload]').forEach((form) => {
    const input = one('[data-auto-upload-input]', form);
    const message = one('[data-auto-upload-message]', form);
    const pickerLabel = one('[data-file-picker-label]', form);
    if (!input || !window.fetch || !window.FormData) return;
    form.classList.add('auto-upload-ready');
    input.addEventListener('change', async () => {
      const file = input.files?.[0];
      if (!file) return;
      const payload = new FormData(form);
      input.disabled = true;
      form.classList.add('is-uploading');
      const documentSection = form.closest('#documents');
      const continueButton = documentSection?.querySelector('[data-document-continue]');
      if (continueButton) continueButton.disabled = true;
      if (message) { message.textContent = `Saving ${file.name}…`; message.className = 'auto-upload-message is-saving'; }
      try {
        const response = await fetch(form.action, {
          method: 'POST', body: payload, credentials: 'same-origin',
          headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
        });
        const contentType = response.headers.get('content-type') || '';
        const result = contentType.includes('application/json') ? await response.json() : { ok: false, message: 'The upload session changed. Refresh the page and try again.' };
        if (!response.ok || !result.ok) throw new Error(result.message || 'The file could not be saved.');
        if (message) { message.textContent = result.message; message.className = 'auto-upload-message is-saved'; }
        if (pickerLabel) pickerLabel.textContent = 'Replace file';
        const copy = form.closest('article')?.querySelector('[data-upload-copy]');
        if (copy && result.document) {
          let state = one('[data-upload-state]', copy);
          if (!state) { state = document.createElement('span'); state.dataset.uploadState = ''; copy.append(state); }
          state.className = 'upload-status status-pending';
          state.textContent = `Pending · ${result.document.original_name}`;
          let view = one('[data-upload-view]', copy);
          if (!view) { view = document.createElement('a'); view.dataset.uploadView = ''; view.target = '_blank'; copy.append(view); }
          view.href = result.view_url;
          view.textContent = 'View saved file';
        }
      } catch (error) {
        if (message) { message.textContent = error instanceof Error ? error.message : 'The file could not be saved.'; message.className = 'auto-upload-message is-error'; }
      } finally {
        input.disabled = false;
        input.value = '';
        form.classList.remove('is-uploading');
        if (continueButton && !documentSection?.querySelector('.auto-upload.is-uploading')) continueButton.disabled = false;
      }
    });
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
