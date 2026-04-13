(() => {
  const MODAL_ID = 'iiCallComposerModal';

  const DEFAULT_TEMPLATE = {
    subject: 'Invitation: [title] on [date] at [time]',
    body:
      '<p>[sehrgeehrte/r] [anrede] [titel] [nachname] [vorname],</p><p>you are invited to join <strong>[title]</strong>.</p><p>Date and time: <strong>[date] [time]</strong>.</p><p>Best regards,<br/>Intelligent Intern</p>',
  };

  const state = {
    resolver: null,
    mode: 'create',
    host: { id: '__host__', name: 'Host', email: 'host@example.com' },
    users: [],
    selectedUserIds: new Set(),
    externalInvitees: [],
    userSearch: '',
    userPage: 1,
    userPageSize: 10,
    step: 1,
    sendTimer: null,
    sending: false,
  };

  function getDateDisplay(value) {
    if (window.KingSettingsUI && typeof window.KingSettingsUI.formatDateTimeDisplay === 'function') {
      return window.KingSettingsUI.formatDateTimeDisplay(value);
    }
    if (typeof value === 'string') return value.replace('T', ' ');
    return '';
  }

  function toInputValue(value) {
    if (window.KingSettingsUI && typeof window.KingSettingsUI.formatDateTimeLocalInput === 'function') {
      return window.KingSettingsUI.formatDateTimeLocalInput(value);
    }
    const date = value instanceof Date ? value : new Date(value);
    if (Number.isNaN(date.getTime())) return '';
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');
    const hour = String(date.getHours()).padStart(2, '0');
    const minute = String(date.getMinutes()).padStart(2, '0');
    return `${year}-${month}-${day}T${hour}:${minute}`;
  }

  function escapeHtml(value) {
    return String(value)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  function createModal() {
    const existing = document.getElementById(MODAL_ID);
    if (existing instanceof HTMLElement) return existing;

    const modal = document.createElement('div');
    modal.id = MODAL_ID;
    modal.className = 'ii-call-composer';
    modal.hidden = true;
    modal.innerHTML = `
      <style>
        .ii-call-composer {
          position: fixed;
          inset: 0;
          z-index: 90;
          display: grid;
          place-items: center;
        }
        .ii-call-composer[hidden] {
          display: none;
        }
        .ii-call-composer__backdrop {
          position: absolute;
          inset: 0;
          background: #09111e;
        }
        .ii-call-composer__dialog {
          position: relative;
          width: min(1040px, calc(100vw - 32px));
          max-height: calc(100vh - 32px);
          overflow: auto;
          border: 1px solid var(--border-subtle);
          border-radius: 8px;
          background: var(--bg-surface-strong);
          padding: 14px;
          display: grid;
          gap: 10px;
        }
        .ii-call-composer__head {
          display: flex;
          align-items: center;
          justify-content: space-between;
          gap: 10px;
        }
        .ii-call-composer__head h3 {
          margin: 0;
          font-size: 18px;
          color: var(--text-main);
        }
        .ii-call-composer__steps {
          display: inline-flex;
          align-items: center;
          gap: 8px;
          font-size: 12px;
          color: var(--text-muted);
        }
        .ii-call-composer__step {
          padding: 3px 8px;
          border-radius: 6px;
          border: 1px solid var(--border-subtle);
          background: #132542;
        }
        .ii-call-composer__step.active {
          background: var(--bg-row);
          color: #ffffff;
        }
        .ii-call-composer__panel {
          display: none;
          gap: 10px;
          min-height: 0;
        }
        .ii-call-composer__panel.active {
          display: grid;
        }
        .ii-call-composer__fields {
          display: grid;
          grid-template-columns: repeat(3, minmax(0, 1fr));
          gap: 10px;
        }
        .ii-call-composer__field {
          display: grid;
          gap: 6px;
        }
        .ii-call-composer__field label {
          font-size: 12px;
          color: var(--text-muted);
        }
        .ii-call-composer__participants {
          display: grid;
          grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
          gap: 10px;
        }
        .ii-call-composer__box {
          border: 1px solid var(--border-subtle);
          border-radius: 6px;
          background: #152a49;
          display: grid;
          grid-template-rows: auto auto minmax(0, 1fr);
          min-height: 260px;
          overflow: hidden;
        }
        .ii-call-composer__box h4 {
          margin: 0;
          padding: 10px 12px;
          font-size: 13px;
          background: #1b355d;
          border-bottom: 1px solid var(--border-subtle);
          color: var(--text-main);
        }
        .ii-call-composer__box-search {
          padding: 10px 12px;
          border-bottom: 1px solid var(--border-subtle);
        }
        .ii-call-composer__list {
          min-height: 0;
          overflow: auto;
        }
        .ii-call-composer__pagination {
          display: flex;
          align-items: center;
          justify-content: space-between;
          gap: 8px;
          padding: 8px 12px;
          border-top: 1px solid var(--border-subtle);
          background: #142948;
        }
        .ii-call-composer__pagination[hidden] {
          display: none;
        }
        .ii-call-composer__page-label {
          font-size: 12px;
          color: var(--text-muted);
        }
        .ii-call-composer__row {
          display: flex;
          align-items: center;
          justify-content: space-between;
          gap: 8px;
          padding: 8px 12px;
          border-top: 1px solid var(--border-subtle);
        }
        .ii-call-composer__row:first-child {
          border-top: 0;
        }
        .ii-call-composer .icon-mini-btn.is-hidden {
          visibility: hidden;
          pointer-events: none;
        }
        .ii-call-composer__row-meta {
          min-width: 0;
          display: grid;
          gap: 2px;
        }
        .ii-call-composer__row-name {
          font-size: 13px;
          color: var(--text-main);
          white-space: nowrap;
          overflow: hidden;
          text-overflow: ellipsis;
        }
        .ii-call-composer__row-email {
          font-size: 12px;
          color: var(--text-muted);
          white-space: nowrap;
          overflow: hidden;
          text-overflow: ellipsis;
        }
        .ii-call-composer__empty {
          margin: 0;
          padding: 14px 12px;
          color: var(--text-muted);
          font-size: 12px;
        }
        .ii-call-composer__externals {
          border: 1px solid var(--border-subtle);
          border-radius: 6px;
          background: #152a49;
          overflow: auto;
        }
        .ii-call-composer__externals-head {
          display: flex;
          align-items: center;
          justify-content: space-between;
          gap: 8px;
          padding: 10px 12px;
          border-bottom: 1px solid var(--border-subtle);
          background: #1b355d;
        }
        .ii-call-composer__externals-head h4 {
          margin: 0;
          font-size: 13px;
          color: var(--text-main);
        }
        .ii-call-composer__external-table {
          width: 100%;
          border-collapse: collapse;
          table-layout: fixed;
        }
        .ii-call-composer__external-table th,
        .ii-call-composer__external-table td {
          border-top: 1px solid var(--border-subtle);
          padding: 6px;
          font-size: 12px;
          color: var(--text-main);
          vertical-align: top;
        }
        .ii-call-composer__external-table th {
          background: #142948;
          color: var(--text-muted);
          font-weight: 600;
        }
        .ii-call-composer__external-table input,
        .ii-call-composer__external-table select {
          width: 100%;
        }
        .ii-call-composer__toolbar {
          display: inline-flex;
          flex-wrap: wrap;
          gap: 6px;
        }
        .ii-call-composer__tool {
          min-width: 34px;
          height: 32px;
          border: 0;
          border-radius: 6px;
          background: var(--bg-action);
          color: #ffffff;
          font-weight: 700;
          cursor: pointer;
        }
        .ii-call-composer__tool:hover {
          background: var(--bg-action-hover);
        }
        .ii-call-composer__editor {
          min-height: 180px;
          max-height: 340px;
          overflow: auto;
          border: 1px solid var(--border-subtle);
          border-radius: 6px;
          background: var(--bg-input);
          color: #0b1323;
          padding: 10px;
          line-height: 1.45;
        }
        .ii-call-composer__hint {
          margin: 0;
          font-size: 12px;
          color: var(--text-muted);
        }
        .ii-call-composer__status {
          margin: 0;
          min-height: 16px;
          font-size: 12px;
          color: var(--text-muted);
        }
        .ii-call-composer__progress {
          height: 12px;
          border: 1px solid var(--border-subtle);
          border-radius: 6px;
          background: #12233f;
          overflow: hidden;
        }
        .ii-call-composer__progress[hidden] {
          display: none;
        }
        .ii-call-composer__progress-bar {
          width: 0%;
          height: 100%;
          background: var(--bg-action-hover);
          transition: width 120ms linear;
        }
        .ii-call-composer__actions {
          display: flex;
          justify-content: flex-end;
          gap: 8px;
        }
        @media (max-width: 980px) {
          .ii-call-composer__fields,
          .ii-call-composer__participants {
            grid-template-columns: 1fr;
          }
          .ii-call-composer__dialog {
            width: calc(100vw - 14px);
            max-height: calc(100vh - 14px);
            padding: 10px;
          }
        }
      </style>
      <div class="ii-call-composer__backdrop" data-composer-close="true"></div>
      <section class="ii-call-composer__dialog" role="dialog" aria-modal="true" aria-labelledby="iiCallComposerTitle">
        <div class="ii-call-composer__head">
          <h3 id="iiCallComposerTitle">Create New Call</h3>
          <button id="iiComposerCloseBtn" class="icon-mini-btn" type="button" title="Close" aria-label="Close">
            <img src="./assets/orgas/intelligent-intern/icons/cancel.png" alt="" />
          </button>
        </div>

        <div class="ii-call-composer__steps">
          <span id="iiComposerStep1" class="ii-call-composer__step active">1. Participants</span>
          <span id="iiComposerStep2" class="ii-call-composer__step">2. Invitation text</span>
        </div>

        <section id="iiComposerPanel1" class="ii-call-composer__panel active" aria-label="Call setup">
          <div class="ii-call-composer__fields">
            <div class="ii-call-composer__field">
              <label for="iiComposerTitleInput">Call title</label>
              <input id="iiComposerTitleInput" class="input" type="text" placeholder="Weekly planning" />
            </div>
            <div class="ii-call-composer__field">
              <label for="iiComposerStartInput">Start time</label>
              <input id="iiComposerStartInput" class="input" type="datetime-local" />
            </div>
            <div class="ii-call-composer__field">
              <label for="iiComposerEndInput">End time</label>
              <input id="iiComposerEndInput" class="input" type="datetime-local" />
            </div>
          </div>

          <div class="ii-call-composer__participants">
            <section class="ii-call-composer__box">
              <h4>Registered users</h4>
              <div class="ii-call-composer__box-search">
                <input id="iiComposerUserSearch" class="input" type="text" placeholder="Search users" />
              </div>
              <div id="iiComposerUserList" class="ii-call-composer__list"></div>
              <div id="iiComposerUsersPagination" class="ii-call-composer__pagination" hidden>
                <button id="iiComposerUsersPrevBtn" class="icon-mini-btn" type="button" aria-label="Previous users page">
                  <img src="./assets/orgas/intelligent-intern/icons/backward.png" alt="" />
                </button>
                <span id="iiComposerUsersPageLabel" class="ii-call-composer__page-label">Page 1 / 1</span>
                <button id="iiComposerUsersNextBtn" class="icon-mini-btn" type="button" aria-label="Next users page">
                  <img src="./assets/orgas/intelligent-intern/icons/forward.png" alt="" />
                </button>
              </div>
            </section>
            <section class="ii-call-composer__box">
              <h4 id="iiComposerSelectedTitle">Selected users (0)</h4>
              <div class="ii-call-composer__box-search">
                <p class="ii-call-composer__hint">Host is always included.</p>
              </div>
              <div id="iiComposerSelectedList" class="ii-call-composer__list"></div>
            </section>
          </div>

          <section class="ii-call-composer__externals" aria-label="External invitees">
            <div class="ii-call-composer__externals-head">
              <h4>External invitees</h4>
              <button id="iiComposerAddExternalBtn" class="btn" type="button">Add external</button>
            </div>
            <table class="ii-call-composer__external-table">
              <thead>
                <tr>
                  <th>Anrede</th>
                  <th>Titel</th>
                  <th>Vorname</th>
                  <th>Nachname</th>
                  <th>Email</th>
                  <th>Action</th>
                </tr>
              </thead>
              <tbody id="iiComposerExternalRows"></tbody>
            </table>
          </section>
        </section>

        <section id="iiComposerPanel2" class="ii-call-composer__panel" aria-label="Invitation text">
          <div class="ii-call-composer__field">
            <label for="iiComposerSubjectInput">Invitation subject</label>
            <input id="iiComposerSubjectInput" class="input" type="text" />
          </div>
          <div class="ii-call-composer__toolbar" aria-label="Editor tools">
            <button class="ii-call-composer__tool" type="button" data-editor-cmd="bold" title="Bold"><strong>B</strong></button>
            <button class="ii-call-composer__tool" type="button" data-editor-cmd="italic" title="Italic"><em>I</em></button>
            <button class="ii-call-composer__tool" type="button" data-editor-cmd="underline" title="Underline"><u>U</u></button>
            <button class="ii-call-composer__tool" type="button" data-editor-cmd="insertUnorderedList" title="Bullet list">•</button>
            <button class="ii-call-composer__tool" type="button" data-editor-cmd="createLink" title="Link">Link</button>
            <button class="ii-call-composer__tool" type="button" data-editor-cmd="removeFormat" title="Clear format">Clear</button>
          </div>
          <div id="iiComposerBodyEditor" class="ii-call-composer__editor" contenteditable="true"></div>
          <p class="ii-call-composer__hint">Use placeholders in text: [sehrgeehrte/r], [anrede], [titel], [nachname], [vorname], [title], [date], [time]</p>
          <div id="iiComposerProgress" class="ii-call-composer__progress" hidden>
            <div id="iiComposerProgressBar" class="ii-call-composer__progress-bar"></div>
          </div>
        </section>

        <p id="iiComposerStatus" class="ii-call-composer__status"></p>

        <div class="ii-call-composer__actions">
          <button id="iiComposerCancelBtn" class="btn" type="button">Cancel</button>
          <button id="iiComposerBackBtn" class="btn" type="button" hidden>Back</button>
          <button id="iiComposerNextBtn" class="btn" type="button">Next</button>
          <button id="iiComposerSendBtn" class="btn" type="button" hidden>Save and send invites</button>
        </div>
      </section>
    `;

    document.body.appendChild(modal);
    bindEvents(modal);
    return modal;
  }

  function getElements(modal) {
    return {
      modal,
      modalTitle: modal.querySelector('#iiCallComposerTitle'),
      panel1: modal.querySelector('#iiComposerPanel1'),
      panel2: modal.querySelector('#iiComposerPanel2'),
      step1: modal.querySelector('#iiComposerStep1'),
      step2: modal.querySelector('#iiComposerStep2'),
      titleInput: modal.querySelector('#iiComposerTitleInput'),
      startInput: modal.querySelector('#iiComposerStartInput'),
      endInput: modal.querySelector('#iiComposerEndInput'),
      userSearch: modal.querySelector('#iiComposerUserSearch'),
      userList: modal.querySelector('#iiComposerUserList'),
      selectedTitle: modal.querySelector('#iiComposerSelectedTitle'),
      selectedList: modal.querySelector('#iiComposerSelectedList'),
      usersPagination: modal.querySelector('#iiComposerUsersPagination'),
      usersPrevBtn: modal.querySelector('#iiComposerUsersPrevBtn'),
      usersNextBtn: modal.querySelector('#iiComposerUsersNextBtn'),
      usersPageLabel: modal.querySelector('#iiComposerUsersPageLabel'),
      externalRows: modal.querySelector('#iiComposerExternalRows'),
      subjectInput: modal.querySelector('#iiComposerSubjectInput'),
      bodyEditor: modal.querySelector('#iiComposerBodyEditor'),
      status: modal.querySelector('#iiComposerStatus'),
      progress: modal.querySelector('#iiComposerProgress'),
      progressBar: modal.querySelector('#iiComposerProgressBar'),
      backBtn: modal.querySelector('#iiComposerBackBtn'),
      nextBtn: modal.querySelector('#iiComposerNextBtn'),
      sendBtn: modal.querySelector('#iiComposerSendBtn'),
    };
  }

  function sanitizeUsers(users, host) {
    const seen = new Set();
    const normalized = [];

    if (host && typeof host.id === 'string' && host.id !== '') {
      normalized.push({
        id: host.id,
        name: host.name || 'Host',
        email: host.email || '',
      });
      seen.add(host.id);
    }

    for (const entry of users || []) {
      if (!entry || typeof entry !== 'object') continue;
      const id = typeof entry.id === 'string' && entry.id.trim() !== ''
        ? entry.id.trim()
        : `u-${Math.random().toString(36).slice(2, 10)}`;
      if (seen.has(id)) continue;
      seen.add(id);
      normalized.push({
        id,
        name: typeof entry.name === 'string' && entry.name.trim() !== '' ? entry.name.trim() : id,
        email: typeof entry.email === 'string' ? entry.email.trim() : '',
      });
    }

    return normalized;
  }

  function normalizeExternalInvitees(invitees) {
    if (!Array.isArray(invitees)) return [];
    return invitees.map((entry) => {
      const source = entry && typeof entry === 'object' ? entry : {};
      return {
        id: typeof source.id === 'string' && source.id.trim() !== ''
          ? source.id.trim()
          : `ext-${Date.now()}-${Math.random().toString(36).slice(2, 8)}`,
        salutation: typeof source.salutation === 'string' && source.salutation.trim() !== ''
          ? source.salutation.trim()
          : 'Herr',
        title: typeof source.title === 'string' ? source.title.trim() : '',
        firstName: typeof source.firstName === 'string' ? source.firstName.trim() : '',
        lastName: typeof source.lastName === 'string' ? source.lastName.trim() : '',
        email: typeof source.email === 'string' ? source.email.trim() : '',
      };
    });
  }

  function resetExternalRows(modal) {
    state.externalInvitees = [];
    renderExternalRows(modal);
  }

  function addExternalRow(modal) {
    state.externalInvitees.push({
      id: `ext-${Date.now()}-${Math.random().toString(36).slice(2, 8)}`,
      salutation: 'Herr',
      title: '',
      firstName: '',
      lastName: '',
      email: '',
    });
    renderExternalRows(modal);
  }

  function renderExternalRows(modal) {
    const { externalRows } = getElements(modal);
    if (!(externalRows instanceof HTMLElement)) return;

    if (state.externalInvitees.length === 0) {
      externalRows.innerHTML = `<tr><td colspan="6"><p class="ii-call-composer__empty">No external invitees yet.</p></td></tr>`;
      return;
    }

    externalRows.innerHTML = state.externalInvitees
      .map((row) => {
        return `
          <tr data-external-id="${escapeHtml(row.id)}">
            <td>
              <select class="select" data-ext-field="salutation">
                <option value="Herr" ${row.salutation === 'Herr' ? 'selected' : ''}>Herr</option>
                <option value="Frau" ${row.salutation === 'Frau' ? 'selected' : ''}>Frau</option>
                <option value="Divers" ${row.salutation === 'Divers' ? 'selected' : ''}>Divers</option>
              </select>
            </td>
            <td><input class="input" data-ext-field="title" type="text" value="${escapeHtml(row.title)}" /></td>
            <td><input class="input" data-ext-field="firstName" type="text" value="${escapeHtml(row.firstName)}" /></td>
            <td><input class="input" data-ext-field="lastName" type="text" value="${escapeHtml(row.lastName)}" /></td>
            <td><input class="input" data-ext-field="email" type="email" value="${escapeHtml(row.email)}" /></td>
            <td>
              <button class="icon-mini-btn danger" type="button" data-remove-external-id="${escapeHtml(row.id)}" aria-label="Remove external invitee">
                <img src="./assets/orgas/intelligent-intern/icons/cancel.png" alt="" />
              </button>
            </td>
          </tr>
        `;
      })
      .join('');
  }

  function renderUsers(modal) {
    const {
      userList,
      selectedList,
      selectedTitle,
      usersPagination,
      usersPrevBtn,
      usersNextBtn,
      usersPageLabel,
    } = getElements(modal);
    if (!(userList instanceof HTMLElement)) return;
    if (!(selectedList instanceof HTMLElement)) return;
    if (!(selectedTitle instanceof HTMLElement)) return;

    const query = state.userSearch.trim().toLowerCase();
    const selectedUsers = state.users.filter((user) => state.selectedUserIds.has(user.id));
    const availableUsers = state.users.filter((user) => {
      if (state.selectedUserIds.has(user.id)) return false;
      if (query === '') return true;
      return user.name.toLowerCase().includes(query) || user.email.toLowerCase().includes(query);
    });
    const totalPages = Math.max(1, Math.ceil(availableUsers.length / state.userPageSize));
    state.userPage = Math.max(1, Math.min(state.userPage, totalPages));
    const pageStart = (state.userPage - 1) * state.userPageSize;
    const pageUsers = availableUsers.slice(pageStart, pageStart + state.userPageSize);

    selectedTitle.textContent = `Selected users (${selectedUsers.length})`;

    userList.innerHTML = pageUsers.length === 0
      ? '<p class="ii-call-composer__empty">No users found.</p>'
      : pageUsers
          .map((user) => {
            return `
              <div class="ii-call-composer__row">
                <div class="ii-call-composer__row-meta">
                  <div class="ii-call-composer__row-name">${escapeHtml(user.name)}</div>
                  <div class="ii-call-composer__row-email">${escapeHtml(user.email)}</div>
                </div>
                <button class="icon-mini-btn" type="button" data-add-user-id="${escapeHtml(user.id)}" aria-label="Add user">+</button>
              </div>
            `;
          })
          .join('');
    if (usersPagination instanceof HTMLElement) {
      usersPagination.hidden = availableUsers.length <= state.userPageSize;
    }
    if (usersPageLabel instanceof HTMLElement) {
      usersPageLabel.textContent = `Page ${state.userPage} / ${totalPages}`;
    }
    if (usersPrevBtn instanceof HTMLButtonElement) {
      usersPrevBtn.disabled = state.userPage <= 1;
    }
    if (usersNextBtn instanceof HTMLButtonElement) {
      usersNextBtn.disabled = state.userPage >= totalPages;
    }

    selectedList.innerHTML = selectedUsers.length === 0
      ? '<p class="ii-call-composer__empty">No users selected.</p>'
      : selectedUsers
          .map((user) => {
            const removeDisabled = user.id === state.host.id;
            return `
              <div class="ii-call-composer__row">
                <div class="ii-call-composer__row-meta">
                  <div class="ii-call-composer__row-name">${escapeHtml(user.name)}</div>
                  <div class="ii-call-composer__row-email">${escapeHtml(user.email)}${user.id === state.host.id ? ' (Host)' : ''}</div>
                </div>
                <button class="icon-mini-btn${removeDisabled ? ' is-hidden' : ''}" type="button" data-remove-user-id="${escapeHtml(user.id)}" aria-label="Remove user">-</button>
              </div>
            `;
          })
          .join('');
  }

  function setStep(modal, step) {
    state.step = step;
    const { panel1, panel2, step1, step2, backBtn, nextBtn, sendBtn, status, progress, progressBar } = getElements(modal);
    const isEditMode = state.mode === 'edit';

    if (panel2 instanceof HTMLElement) {
      panel2.hidden = isEditMode;
    }
    if (step2 instanceof HTMLElement) {
      step2.hidden = isEditMode;
    }

    if (isEditMode) {
      panel1?.classList.add('active');
      panel2?.classList.remove('active');
      step1?.classList.add('active');
      step2?.classList.remove('active');
      if (backBtn instanceof HTMLElement) backBtn.hidden = true;
      if (nextBtn instanceof HTMLButtonElement) {
        nextBtn.hidden = false;
        nextBtn.textContent = 'Save changes';
      }
      if (sendBtn instanceof HTMLElement) sendBtn.hidden = true;
      if (status instanceof HTMLElement) status.textContent = '';
      if (progress instanceof HTMLElement) progress.hidden = true;
      if (progressBar instanceof HTMLElement) progressBar.style.width = '0%';
      return;
    }

    panel1?.classList.toggle('active', step === 1);
    panel2?.classList.toggle('active', step === 2);
    step1?.classList.toggle('active', step === 1);
    step2?.classList.toggle('active', step === 2);
    if (backBtn instanceof HTMLElement) backBtn.hidden = step === 1;
    if (nextBtn instanceof HTMLButtonElement) {
      nextBtn.hidden = step !== 1;
      nextBtn.textContent = 'Next';
    }
    if (sendBtn instanceof HTMLElement) sendBtn.hidden = step !== 2;
    if (status instanceof HTMLElement) status.textContent = '';
    if (progress instanceof HTMLElement) progress.hidden = true;
    if (progressBar instanceof HTMLElement) progressBar.style.width = '0%';
  }

  function closeModal(modal, payload = null) {
    if (state.sendTimer !== null) {
      window.clearInterval(state.sendTimer);
      state.sendTimer = null;
    }
    state.sending = false;
    modal.hidden = true;
    if (typeof state.resolver === 'function') {
      const resolve = state.resolver;
      state.resolver = null;
      resolve(payload);
    }
  }

  function validateStep1(modal) {
    const { titleInput, startInput, endInput, status } = getElements(modal);
    if (!(titleInput instanceof HTMLInputElement)) return null;
    if (!(startInput instanceof HTMLInputElement)) return null;
    if (!(endInput instanceof HTMLInputElement)) return null;

    const title = titleInput.value.trim();
    const start = startInput.value.trim();
    const end = endInput.value.trim();

    if (title === '') {
      if (status instanceof HTMLElement) status.textContent = 'Please enter a call title.';
      return null;
    }

    if (start === '' || end === '') {
      if (status instanceof HTMLElement) status.textContent = 'Please choose start and end time.';
      return null;
    }

    if (new Date(end).getTime() <= new Date(start).getTime()) {
      if (status instanceof HTMLElement) status.textContent = 'End time must be later than start time.';
      return null;
    }

    return { title, start, end };
  }

  function validateExternalInvitees(modal) {
    const { status } = getElements(modal);
    for (const invitee of state.externalInvitees) {
      const isEmpty = !invitee.salutation && !invitee.title && !invitee.firstName && !invitee.lastName && !invitee.email;
      if (isEmpty) continue;
      if (!invitee.email || !/^\S+@\S+\.\S+$/.test(invitee.email)) {
        if (status instanceof HTMLElement) {
          status.textContent = 'Please provide a valid email for each external invitee row.';
        }
        return false;
      }
      if (invitee.lastName.trim() === '' && invitee.firstName.trim() === '') {
        if (status instanceof HTMLElement) {
          status.textContent = 'Please provide at least first or last name for external invitees.';
        }
        return false;
      }
    }
    return true;
  }

  function buildPayload(modal) {
    const { titleInput, startInput, endInput, subjectInput, bodyEditor } = getElements(modal);
    const selectedUsers = state.users.filter((user) => state.selectedUserIds.has(user.id));
    const externalInvitees = state.externalInvitees
      .filter((entry) => entry.email.trim() !== '')
      .map((entry) => ({ ...entry }));

    return {
      mode: state.mode,
      title: titleInput instanceof HTMLInputElement ? titleInput.value.trim() : '',
      start: startInput instanceof HTMLInputElement ? startInput.value : '',
      end: endInput instanceof HTMLInputElement ? endInput.value : '',
      owner: state.host.name,
      ownerEmail: state.host.email,
      sendInvites: state.mode !== 'edit',
      participantsCount: selectedUsers.length + externalInvitees.length,
      selectedUsers,
      externalInvitees,
      invitation: {
        subject: subjectInput instanceof HTMLInputElement ? subjectInput.value.trim() : '',
        bodyHtml: bodyEditor instanceof HTMLElement ? bodyEditor.innerHTML : '',
      },
    };
  }

  function startSendSimulation(modal) {
    const { status, progress, progressBar, sendBtn, backBtn, cancelBtn } = {
      ...getElements(modal),
      cancelBtn: modal.querySelector('#iiComposerCancelBtn'),
    };

    if (state.sending) return;
    state.sending = true;
    if (sendBtn instanceof HTMLButtonElement) sendBtn.disabled = true;
    if (backBtn instanceof HTMLButtonElement) backBtn.disabled = true;
    if (cancelBtn instanceof HTMLButtonElement) cancelBtn.disabled = true;
    if (progress instanceof HTMLElement) progress.hidden = false;
    if (progressBar instanceof HTMLElement) progressBar.style.width = '0%';
    if (status instanceof HTMLElement) status.textContent = 'Sending invitations...';

    let value = 0;
    state.sendTimer = window.setInterval(() => {
      value = Math.min(100, value + 8 + Math.floor(Math.random() * 10));
      if (progressBar instanceof HTMLElement) {
        progressBar.style.width = `${value}%`;
      }

      if (value < 100) return;

      if (state.sendTimer !== null) {
        window.clearInterval(state.sendTimer);
        state.sendTimer = null;
      }

      if (status instanceof HTMLElement) {
        status.textContent = 'Invitations sent successfully.';
      }

      const payload = buildPayload(modal);
      window.setTimeout(() => {
        if (sendBtn instanceof HTMLButtonElement) sendBtn.disabled = false;
        if (backBtn instanceof HTMLButtonElement) backBtn.disabled = false;
        if (cancelBtn instanceof HTMLButtonElement) cancelBtn.disabled = false;
        closeModal(modal, payload);
      }, 320);
    }, 120);
  }

  function bindEvents(modal) {
    modal.addEventListener('click', (event) => {
      const target = event.target;
      if (!(target instanceof HTMLElement)) return;

      if (target.matches('[data-composer-close="true"]')) {
        closeModal(modal, null);
        return;
      }

      const closeBtn = target.closest('#iiComposerCloseBtn, #iiComposerCancelBtn');
      if (closeBtn instanceof HTMLElement) {
        if (!state.sending) {
          closeModal(modal, null);
        }
        return;
      }

      const addUserBtn = target.closest('button[data-add-user-id]');
      if (addUserBtn instanceof HTMLButtonElement) {
        const userId = addUserBtn.dataset.addUserId || '';
        if (userId !== '') {
          state.selectedUserIds.add(userId);
          renderUsers(modal);
        }
        return;
      }

      if (target.closest('#iiComposerUsersPrevBtn')) {
        state.userPage = Math.max(1, state.userPage - 1);
        renderUsers(modal);
        return;
      }

      if (target.closest('#iiComposerUsersNextBtn')) {
        state.userPage += 1;
        renderUsers(modal);
        return;
      }

      const removeUserBtn = target.closest('button[data-remove-user-id]');
      if (removeUserBtn instanceof HTMLButtonElement) {
        const userId = removeUserBtn.dataset.removeUserId || '';
        if (userId !== '' && userId !== state.host.id) {
          state.selectedUserIds.delete(userId);
          renderUsers(modal);
        }
        return;
      }

      const removeExternalBtn = target.closest('button[data-remove-external-id]');
      if (removeExternalBtn instanceof HTMLButtonElement) {
        const externalId = removeExternalBtn.dataset.removeExternalId || '';
        if (externalId !== '') {
          state.externalInvitees = state.externalInvitees.filter((row) => row.id !== externalId);
          renderExternalRows(modal);
        }
        return;
      }

      if (target.closest('#iiComposerAddExternalBtn')) {
        addExternalRow(modal);
        return;
      }

      const nextBtn = target.closest('#iiComposerNextBtn');
      if (nextBtn instanceof HTMLElement) {
        if (state.mode === 'edit') {
          if (!validateStep1(modal)) return;
          if (!validateExternalInvitees(modal)) return;
          closeModal(modal, buildPayload(modal));
          return;
        }
        if (!validateStep1(modal)) return;
        setStep(modal, 2);
        const editor = modal.querySelector('#iiComposerBodyEditor');
        if (editor instanceof HTMLElement) {
          editor.focus();
        }
        return;
      }

      const backBtn = target.closest('#iiComposerBackBtn');
      if (backBtn instanceof HTMLElement) {
        if (state.sending) return;
        setStep(modal, 1);
        return;
      }

      const sendBtn = target.closest('#iiComposerSendBtn');
      if (sendBtn instanceof HTMLElement) {
        if (!validateStep1(modal)) {
          setStep(modal, 1);
          return;
        }
        if (!validateExternalInvitees(modal)) {
          return;
        }
        startSendSimulation(modal);
        return;
      }

      const editorTool = target.closest('button[data-editor-cmd]');
      if (editorTool instanceof HTMLButtonElement) {
        const command = editorTool.dataset.editorCmd || '';
        const editor = modal.querySelector('#iiComposerBodyEditor');
        if (!(editor instanceof HTMLElement)) return;
        editor.focus();

        if (command === 'createLink') {
          const url = window.prompt('Enter link URL', 'https://');
          if (!url) return;
          document.execCommand('createLink', false, url);
          return;
        }

        document.execCommand(command, false);
      }
    });

    modal.addEventListener('input', (event) => {
      const target = event.target;
      if (!(target instanceof HTMLElement)) return;

      if (target.id === 'iiComposerUserSearch' && target instanceof HTMLInputElement) {
        state.userSearch = target.value;
        state.userPage = 1;
        renderUsers(modal);
        return;
      }

      const externalCellInput = target.closest('tr[data-external-id]');
      if (!(externalCellInput instanceof HTMLTableRowElement)) return;
      const externalId = externalCellInput.getAttribute('data-external-id') || '';
      if (externalId === '') return;
      const field = target.getAttribute('data-ext-field') || '';
      if (field === '') return;

      const row = state.externalInvitees.find((entry) => entry.id === externalId);
      if (!row) return;

      if (target instanceof HTMLInputElement || target instanceof HTMLSelectElement) {
        row[field] = target.value;
      }
    });

    window.addEventListener('keydown', (event) => {
      if (event.key === 'Escape' && !modal.hidden && !state.sending) {
        closeModal(modal, null);
      }
    });
  }

  function open(options = {}) {
    const modal = createModal();
    const elements = getElements(modal);

    const hostName = typeof options.hostName === 'string' && options.hostName.trim() !== ''
      ? options.hostName.trim()
      : 'Host';
    const hostEmail = typeof options.hostEmail === 'string' ? options.hostEmail.trim() : '';
    const hostId = typeof options.hostId === 'string' && options.hostId.trim() !== '' ? options.hostId.trim() : '__host__';

    if (options.mode === 'schedule') {
      state.mode = 'schedule';
    } else if (options.mode === 'edit') {
      state.mode = 'edit';
    } else {
      state.mode = 'create';
    }
    state.host = { id: hostId, name: hostName, email: hostEmail };
    state.users = sanitizeUsers(options.registeredUsers, state.host);
    const availableUserIds = new Set(state.users.map((user) => user.id));
    const selectedIds = new Set([state.host.id]);
    if (Array.isArray(options.initialSelectedUserIds)) {
      for (const id of options.initialSelectedUserIds) {
        if (typeof id === 'string' && availableUserIds.has(id)) {
          selectedIds.add(id);
        }
      }
    }
    state.selectedUserIds = selectedIds;
    state.externalInvitees = normalizeExternalInvitees(options.initialExternalInvitees);
    state.userSearch = '';
    state.userPage = 1;
    state.sending = false;

    const template = window.KingSettingsUI && typeof window.KingSettingsUI.getInvitationTemplate === 'function'
      ? window.KingSettingsUI.getInvitationTemplate()
      : DEFAULT_TEMPLATE;
    const initialInvitation = options.initialInvitation && typeof options.initialInvitation === 'object'
      ? options.initialInvitation
      : null;

    const initialStart = options.initialStart ? new Date(options.initialStart) : new Date(Date.now() + 60 * 60 * 1000);
    const initialEnd = options.initialEnd ? new Date(options.initialEnd) : new Date(initialStart.getTime() + 60 * 60 * 1000);
    const initialTitle = typeof options.initialTitle === 'string' && options.initialTitle.trim() !== ''
      ? options.initialTitle.trim()
      : (state.mode === 'schedule' ? 'Scheduled Call' : (state.mode === 'edit' ? 'Edit Video Call' : 'New Video Call'));

    if (elements.modalTitle instanceof HTMLElement) {
      if (state.mode === 'edit') {
        elements.modalTitle.textContent = 'Edit Call';
      } else if (state.mode === 'schedule') {
        elements.modalTitle.textContent = 'Schedule Call';
      } else {
        elements.modalTitle.textContent = 'Create New Call';
      }
    }
    if (elements.step1 instanceof HTMLElement) {
      elements.step1.textContent = state.mode === 'edit' ? 'Edit call' : '1. Participants';
    }
    if (elements.step2 instanceof HTMLElement) {
      elements.step2.textContent = '2. Invitation text';
    }

    if (elements.titleInput instanceof HTMLInputElement) elements.titleInput.value = initialTitle;
    if (elements.startInput instanceof HTMLInputElement) elements.startInput.value = toInputValue(initialStart);
    if (elements.endInput instanceof HTMLInputElement) elements.endInput.value = toInputValue(initialEnd);
    if (elements.userSearch instanceof HTMLInputElement) elements.userSearch.value = '';

    if (elements.subjectInput instanceof HTMLInputElement) {
      const resolvedTemplateSubject = initialInvitation && typeof initialInvitation.subject === 'string'
        ? initialInvitation.subject
        : template.subject;
      const subject = String(resolvedTemplateSubject || DEFAULT_TEMPLATE.subject)
        .replace(/\[title\]/gi, initialTitle)
        .replace(/\[date\]/gi, getDateDisplay(initialStart))
        .replace(/\[time\]/gi, getDateDisplay(initialStart).split(' ').slice(-1)[0] || '');
      elements.subjectInput.value = subject;
    }

    if (elements.bodyEditor instanceof HTMLElement) {
      const resolvedTemplateBody = initialInvitation && typeof initialInvitation.bodyHtml === 'string'
        ? initialInvitation.bodyHtml
        : template.body;
      elements.bodyEditor.innerHTML = String(resolvedTemplateBody || DEFAULT_TEMPLATE.body);
    }

    if (elements.status instanceof HTMLElement) {
      elements.status.textContent = '';
    }

    renderExternalRows(modal);
    renderUsers(modal);
    setStep(modal, 1);

    if (elements.sendBtn instanceof HTMLButtonElement) elements.sendBtn.disabled = false;
    if (elements.backBtn instanceof HTMLButtonElement) elements.backBtn.disabled = false;

    modal.hidden = false;

    return new Promise((resolve) => {
      state.resolver = resolve;
    });
  }

  window.KingCallComposer = {
    open,
  };
})();
