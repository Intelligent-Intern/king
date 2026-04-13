(() => {
  const STORAGE_KEYS = {
    avatar: 'ii_call_avatar_data_url',
    logo: 'ii_call_brand_logo_data_url',
    themeMode: 'ii_call_theme_mode',
    customTheme: 'ii_call_theme_custom',
    timeFormat: 'ii_call_time_format',
    invitationTemplate: 'ii_call_invitation_template',
    mailTransportProfile: 'ii_call_mail_transport_profile',
  };

  const DEFAULTS = {
    avatar: './assets/orgas/intelligent-intern/avatar-placeholder.svg',
    logo: './assets/orgas/intelligent-intern/logo.svg',
  };

  const DARK_THEME = {
    '--bg-shell': '#09111e',
    '--bg-pane': '#182c4d',
    '--bg-surface': '#2b3e60',
    '--bg-surface-strong': '#0c1c33',
    '--bg-input': '#d8dadd',
    '--bg-action': '#162e51',
    '--bg-action-hover': '#5696ef',
    '--bg-row': '#2a569f',
    '--bg-row-hover': '#163260',
    '--line': '#09111e',
    '--text-main': '#edf3ff',
    '--text-muted': '#8490a1',
  };

  const LIGHT_THEME = {
    '--bg-shell': '#e8edf5',
    '--bg-pane': '#f4f7fc',
    '--bg-surface': '#ffffff',
    '--bg-surface-strong': '#dbe4f2',
    '--bg-input': '#ffffff',
    '--bg-action': '#315fa8',
    '--bg-action-hover': '#3f79d6',
    '--bg-row': '#dce8fb',
    '--bg-row-hover': '#c7daf9',
    '--line': '#b8c7df',
    '--text-main': '#0d1a2d',
    '--text-muted': '#445673',
  };

  const THEME_COLOR_FIELDS = [
    { id: 'themeColorShell', label: 'Shell', variable: '--bg-shell' },
    { id: 'themeColorPane', label: 'Pane', variable: '--bg-pane' },
    { id: 'themeColorSurface', label: 'Surface', variable: '--bg-surface' },
    { id: 'themeColorStrong', label: 'Surface Strong', variable: '--bg-surface-strong' },
    { id: 'themeColorAction', label: 'Action', variable: '--bg-action' },
    { id: 'themeColorRow', label: 'Row', variable: '--bg-row' },
    { id: 'themeColorLine', label: 'Line', variable: '--line' },
    { id: 'themeColorText', label: 'Text Main', variable: '--text-main' },
  ];

  const TIME_FORMAT_MAP = {
    iso_24: { id: 'iso_24', label: 'ISO 24h (YYYY-MM-DD HH:mm)' },
    eu_24: { id: 'eu_24', label: 'EU 24h (DD.MM.YYYY HH:mm)' },
    us_12: { id: 'us_12', label: 'US 12h (MM/DD/YYYY hh:mm AM/PM)' },
  };

  const DEFAULT_TIME_FORMAT = 'iso_24';

  function readStoredJSON(key, fallback) {
    try {
      const raw = localStorage.getItem(key);
      if (!raw) return fallback;
      const parsed = JSON.parse(raw);
      if (!parsed || typeof parsed !== 'object') return fallback;
      return parsed;
    } catch {
      return fallback;
    }
  }

  function normalizeHex(value, fallback = '#000000') {
    if (typeof value !== 'string') return fallback;
    const trimmed = value.trim();
    if (/^#[0-9a-fA-F]{6}$/.test(trimmed)) return trimmed.toLowerCase();
    if (/^#[0-9a-fA-F]{3}$/.test(trimmed)) {
      return `#${trimmed[1]}${trimmed[1]}${trimmed[2]}${trimmed[2]}${trimmed[3]}${trimmed[3]}`.toLowerCase();
    }
    return fallback;
  }

  function getCurrentTimeFormat() {
    const stored = localStorage.getItem(STORAGE_KEYS.timeFormat) || DEFAULT_TIME_FORMAT;
    return Object.prototype.hasOwnProperty.call(TIME_FORMAT_MAP, stored) ? stored : DEFAULT_TIME_FORMAT;
  }

  function normalizeDateInput(value) {
    if (value instanceof Date) {
      return Number.isNaN(value.getTime()) ? null : value;
    }
    if (typeof value !== 'string') {
      return null;
    }

    const trimmed = value.trim();
    if (trimmed === '') return null;
    const candidate = new Date(trimmed.includes('T') ? trimmed : trimmed.replace(' ', 'T'));
    if (Number.isNaN(candidate.getTime())) return null;
    return candidate;
  }

  function pad2(value) {
    return String(value).padStart(2, '0');
  }

  function formatDateTimeDisplay(value, formatOverride = null) {
    const date = normalizeDateInput(value);
    if (!(date instanceof Date)) {
      return typeof value === 'string' ? value : '';
    }

    const format = formatOverride && Object.prototype.hasOwnProperty.call(TIME_FORMAT_MAP, formatOverride)
      ? formatOverride
      : getCurrentTimeFormat();

    const year = date.getFullYear();
    const month = pad2(date.getMonth() + 1);
    const day = pad2(date.getDate());
    const hour24 = date.getHours();
    const minute = pad2(date.getMinutes());

    if (format === 'eu_24') {
      return `${day}.${month}.${year} ${pad2(hour24)}:${minute}`;
    }

    if (format === 'us_12') {
      const isPm = hour24 >= 12;
      const hour12Raw = hour24 % 12;
      const hour12 = hour12Raw === 0 ? 12 : hour12Raw;
      return `${month}/${day}/${year} ${pad2(hour12)}:${minute} ${isPm ? 'PM' : 'AM'}`;
    }

    return `${year}-${month}-${day} ${pad2(hour24)}:${minute}`;
  }

  function formatDateTimeLocalInput(value) {
    const date = normalizeDateInput(value);
    if (!(date instanceof Date)) return '';
    const year = date.getFullYear();
    const month = pad2(date.getMonth() + 1);
    const day = pad2(date.getDate());
    const hour = pad2(date.getHours());
    const minute = pad2(date.getMinutes());
    return `${year}-${month}-${day}T${hour}:${minute}`;
  }

  function emitPreferencesUpdated() {
    window.dispatchEvent(new CustomEvent('king:preferences-updated', {
      detail: {
        timeFormat: getCurrentTimeFormat(),
      },
    }));
  }

  function applyThemeVariables(themeMap) {
    const root = document.documentElement;
    Object.entries(themeMap).forEach(([key, value]) => {
      root.style.setProperty(key, value);
    });
  }

  function resolveTheme(mode, customTheme) {
    if (mode === 'light') return LIGHT_THEME;
    if (mode === 'custom') return { ...DARK_THEME, ...customTheme };
    return DARK_THEME;
  }

  function applySavedTheme() {
    const mode = localStorage.getItem(STORAGE_KEYS.themeMode) || 'dark';
    const customTheme = readStoredJSON(STORAGE_KEYS.customTheme, {});
    applyThemeVariables(resolveTheme(mode, customTheme));
  }

  function applySavedAvatar() {
    const avatar = localStorage.getItem(STORAGE_KEYS.avatar) || DEFAULTS.avatar;
    const avatars = Array.from(document.querySelectorAll('[data-sidebar-avatar]'));
    avatars.forEach((img) => {
      if (img instanceof HTMLImageElement) {
        img.src = avatar;
      }
    });
  }

  function applySavedLogo() {
    const logo = localStorage.getItem(STORAGE_KEYS.logo) || DEFAULTS.logo;
    const logos = Array.from(document.querySelectorAll('[data-brand-logo]'));
    logos.forEach((img) => {
      if (img instanceof HTMLImageElement) {
        img.src = logo;
      }
    });
  }

  function createSettingsModal(role) {
    const existing = document.getElementById('settingsModal');
    if (existing instanceof HTMLElement) {
      existing.setAttribute('data-role', role);
      return existing;
    }

    const modal = document.createElement('div');
    modal.id = 'settingsModal';
    modal.className = 'settings-modal';
    modal.hidden = true;

    modal.innerHTML = `
      <div class="settings-backdrop" data-settings-close="true"></div>
      <section class="settings-dialog" role="dialog" aria-modal="true" aria-labelledby="settingsTitle">
        <header class="settings-header">
          <div class="settings-title-wrap">
            <img src="./assets/orgas/intelligent-intern/icon.svg" alt="Intelligent Intern" />
            <h3 id="settingsTitle">Settings</h3>
          </div>
          <button id="settingsCloseBtn" class="icon-mini-btn" type="button" aria-label="Close settings" title="Close settings">
            <img src="./assets/orgas/intelligent-intern/icons/cancel.png" alt="" />
          </button>
        </header>

        <div class="settings-grid" role="tablist" aria-label="Settings categories">
          <button class="settings-tile active" type="button" data-settings-tile="about-me">About Me</button>
          <button class="settings-tile" type="button" data-settings-tile="credentials">Credentials</button>
          <button class="settings-tile" type="button" data-settings-tile="theme">Theme</button>
          <button class="settings-tile" type="button" data-settings-tile="general">${role === 'admin' ? 'General' : 'Workspace'}</button>
          <button class="settings-tile" type="button" data-settings-tile="regional-time">Regional Time</button>
          <button class="settings-tile" type="button" data-settings-tile="email-texts">Email Texts</button>
        </div>

        <div id="settingsPanelMount" class="settings-panel"></div>

        <footer class="settings-actions">
          <button id="settingsCloseFooterBtn" class="btn" type="button">Close</button>
        </footer>
      </section>
    `;

    document.body.appendChild(modal);
    return modal;
  }

  function renderPanelContent(modal, role, panelId, session) {
    const mount = modal.querySelector('#settingsPanelMount');
    if (!(mount instanceof HTMLElement)) return;

    if (panelId === 'about-me') {
      const avatarSrc = localStorage.getItem(STORAGE_KEYS.avatar) || DEFAULTS.avatar;
      mount.innerHTML = `
        <h4>About Me</h4>
        <p>Configure your profile avatar for the sidebar.</p>
        <div class="settings-row">
          <div class="settings-field">
            <label>Current avatar</label>
            <img id="settingsAvatarPreview" class="sidebar-avatar-image settings-avatar-preview-lg" src="${avatarSrc}" alt="Avatar preview" />
            <p id="settingsAvatarInfo">${session.displayName || 'User'} (${session.email || '-'})</p>
          </div>
          <div class="settings-field">
            <label>Avatar upload</label>
            <div id="settingsAvatarDropzone" class="settings-dropzone" tabindex="0" role="button" aria-label="Drop an image or click to upload an avatar">
              <div class="settings-dropzone-title">Drop image here</div>
              <div class="settings-dropzone-subtitle">or click to browse</div>
              <input id="settingsAvatarInput" class="settings-hidden-input" type="file" accept="image/*" />
            </div>
            <div class="settings-avatar-cropper">
              <div id="settingsAvatarCropStage" class="settings-crop-stage" aria-label="Avatar crop area">
                <img id="settingsAvatarCropImage" class="settings-crop-image" alt="Avatar crop source" />
              </div>
            </div>
            <div class="settings-field">
              <label for="settingsAvatarZoom">Zoom</label>
              <input id="settingsAvatarZoom" type="range" min="1" max="4" step="0.01" value="1" />
            </div>
            <div class="actions">
              <button id="settingsAvatarSaveBtn" class="btn" type="button">Save avatar</button>
              <button id="settingsAvatarResetBtn" class="btn" type="button">Reset</button>
            </div>
            <p id="settingsAvatarUploadStatus" class="settings-upload-status"></p>
          </div>
        </div>
      `;
      bindAboutMeHandlers(modal);
      return;
    }

    if (panelId === 'credentials') {
      mount.innerHTML = `
        <h4>Credentials</h4>
        <p>Update your password and review connected OAuth apps.</p>
        <div class="settings-row">
          <div class="settings-field">
            <label for="settingsCurrentPassword">Current password</label>
            <input id="settingsCurrentPassword" class="input" type="password" autocomplete="current-password" />
          </div>
          <div class="settings-field">
            <label for="settingsNewPassword">New password</label>
            <input id="settingsNewPassword" class="input" type="password" autocomplete="new-password" />
          </div>
        </div>
        <div class="settings-row">
          <div class="settings-field">
            <label for="settingsConfirmPassword">Confirm password</label>
            <input id="settingsConfirmPassword" class="input" type="password" autocomplete="new-password" />
          </div>
          <div class="settings-field">
            <label>&nbsp;</label>
            <button id="settingsPasswordSaveBtn" class="btn" type="button">Update password</button>
          </div>
        </div>
        <p id="settingsPasswordStatus"></p>
        <h4>Connected Apps</h4>
        <ul class="settings-app-list">
          <li>
            <span>Google Calendar</span>
            <span class="settings-status-tag connected">Connected</span>
          </li>
          <li>
            <span>Microsoft Calendar</span>
            <span class="settings-status-tag disconnected">Disconnected</span>
          </li>
          <li>
            <span>Slack Notifications</span>
            <span class="settings-status-tag connected">Connected</span>
          </li>
        </ul>
      `;
      bindCredentialsHandlers(modal);
      return;
    }

    if (panelId === 'theme') {
      const currentMode = localStorage.getItem(STORAGE_KEYS.themeMode) || 'dark';
      const customTheme = readStoredJSON(STORAGE_KEYS.customTheme, {});
      const resolvedTheme = resolveTheme(currentMode, customTheme);

      const colorInputs = THEME_COLOR_FIELDS.map((field) => {
        const value = normalizeHex(resolvedTheme[field.variable], '#000000');
        return `
          <div class="settings-theme-color">
            <label for="${field.id}">${field.label}</label>
            <input id="${field.id}" type="color" value="${value}" data-theme-var="${field.variable}" />
          </div>
        `;
      }).join('');

      mount.innerHTML = `
        <h4>Theme</h4>
        <p>Select light or dark mode, or build your own custom palette.</p>
        <div class="settings-row">
          <div class="settings-field">
            <label for="settingsThemeMode">Theme mode</label>
            <select id="settingsThemeMode" class="select">
              <option value="dark" ${currentMode === 'dark' ? 'selected' : ''}>Dark</option>
              <option value="light" ${currentMode === 'light' ? 'selected' : ''}>Light</option>
              <option value="custom" ${currentMode === 'custom' ? 'selected' : ''}>Custom</option>
            </select>
          </div>
        </div>
        <div class="settings-theme-grid">
          ${colorInputs}
        </div>
        <div class="actions">
          <button id="settingsThemeApplyBtn" class="btn" type="button">Apply now</button>
          <button id="settingsThemeSaveBtn" class="btn" type="button">Save theme</button>
          <button id="settingsThemeResetBtn" class="btn" type="button">Reset to dark</button>
        </div>
      `;
      bindThemeHandlers(modal);
      return;
    }

    if (panelId === 'general' && role === 'admin') {
      const logoSrc = localStorage.getItem(STORAGE_KEYS.logo) || DEFAULTS.logo;
      mount.innerHTML = `
        <h4>General</h4>
        <p>Manage workspace branding and replace the logo for the shell header.</p>
        <div class="settings-row">
          <div class="settings-field">
            <label>Current branding logo</label>
            <img id="settingsLogoPreview" class="settings-logo-preview" src="${logoSrc}" alt="Branding logo preview" />
          </div>
          <div class="settings-field">
            <label>Logo upload</label>
            <div id="settingsLogoDropzone" class="settings-dropzone" tabindex="0" role="button" aria-label="Drop a logo image or click to upload">
              <div class="settings-dropzone-title">Drop logo here</div>
              <div class="settings-dropzone-subtitle">or click to browse</div>
              <input id="settingsLogoInput" class="settings-hidden-input" type="file" accept="image/*" />
            </div>
            <div class="settings-logo-cropper">
              <div id="settingsLogoCropStage" class="settings-crop-stage settings-logo-crop-stage" aria-label="Logo crop area">
                <img id="settingsLogoCropImage" class="settings-crop-image" alt="Logo crop source" />
              </div>
            </div>
            <div class="settings-field">
              <label for="settingsLogoZoom">Zoom</label>
              <input id="settingsLogoZoom" type="range" min="1" max="4" step="0.01" value="1" />
            </div>
            <div class="actions">
              <button id="settingsLogoSaveBtn" class="btn" type="button">Save logo</button>
              <button id="settingsLogoResetBtn" class="btn" type="button">Reset logo</button>
            </div>
            <p id="settingsLogoUploadStatus" class="settings-upload-status"></p>
          </div>
        </div>
      `;
      bindGeneralHandlers(modal);
      return;
    }

    if (panelId === 'regional-time') {
      const currentFormat = getCurrentTimeFormat();
      const options = Object.values(TIME_FORMAT_MAP)
        .map((entry) => `<option value="${entry.id}" ${entry.id === currentFormat ? 'selected' : ''}>${entry.label}</option>`)
        .join('');

      mount.innerHTML = `
        <h4>Regional Time</h4>
        <p>Select how date and time should be displayed across the workspace.</p>
        <div class="settings-row">
          <div class="settings-field">
            <label for="settingsTimeFormatSelect">Display format</label>
            <select id="settingsTimeFormatSelect" class="select">${options}</select>
          </div>
          <div class="settings-field">
            <label>Preview</label>
            <div id="settingsTimeFormatPreview" class="input settings-readonly-value"></div>
          </div>
        </div>
        <div class="actions">
          <button id="settingsTimeFormatApplyBtn" class="btn" type="button">Apply</button>
          <button id="settingsTimeFormatSaveBtn" class="btn" type="button">Save globally</button>
        </div>
        <p id="settingsTimeFormatStatus" class="settings-upload-status"></p>
      `;
      bindRegionalTimeHandlers(modal);
      return;
    }

    if (panelId === 'email-texts') {
      const defaults = {
        subject: 'Invitation: [title] on [date] at [time]',
        body:
          '<p>[sehrgeehrte/r] [anrede] [titel] [nachname] [vorname],</p><p>you are invited to join <strong>[title]</strong>.</p><p>Date and time: <strong>[date] [time]</strong>.</p><p>Best regards,<br/>Intelligent Intern</p>',
      };
      const storedTemplate = readStoredJSON(STORAGE_KEYS.invitationTemplate, defaults);
      const storedTransport = readStoredJSON(STORAGE_KEYS.mailTransportProfile, {
        smtpHost: '',
        smtpPort: '587',
        smtpUser: '',
        smtpPass: '',
        imapHost: '',
        imapPort: '993',
        imapUser: '',
        imapPass: '',
        exchangeUrl: '',
        exchangeTenant: '',
      });

      mount.innerHTML = `
        <h4>Email Texts & Transport</h4>
        <p>Manage invitation templates and mail transport channels (SMTP/IMAP/Exchange).</p>
        <div class="settings-row">
          <div class="settings-field">
            <label for="settingsInvitationSubject">Default invitation subject</label>
            <input id="settingsInvitationSubject" class="input" type="text" value="${String(storedTemplate.subject || '').replace(/"/g, '&quot;')}" />
          </div>
        </div>
        <div class="settings-field">
          <label>Invitation text template</label>
          <div id="settingsInvitationBody" class="settings-rich-editor" contenteditable="true">${storedTemplate.body || ''}</div>
          <p class="settings-upload-status">Available placeholders: [sehrgeehrte/r], [anrede], [titel], [nachname], [vorname], [title], [date], [time]</p>
        </div>
        <div class="settings-row">
          <div class="settings-field">
            <label for="settingsSmtpHost">SMTP host</label>
            <input id="settingsSmtpHost" class="input" type="text" value="${String(storedTransport.smtpHost || '').replace(/"/g, '&quot;')}" />
          </div>
          <div class="settings-field">
            <label for="settingsSmtpPort">SMTP port</label>
            <input id="settingsSmtpPort" class="input" type="text" value="${String(storedTransport.smtpPort || '587').replace(/"/g, '&quot;')}" />
          </div>
        </div>
        <div class="settings-row">
          <div class="settings-field">
            <label for="settingsSmtpUser">SMTP user</label>
            <input id="settingsSmtpUser" class="input" type="text" value="${String(storedTransport.smtpUser || '').replace(/"/g, '&quot;')}" />
          </div>
          <div class="settings-field">
            <label for="settingsSmtpPass">SMTP password</label>
            <input id="settingsSmtpPass" class="input" type="password" value="${String(storedTransport.smtpPass || '').replace(/"/g, '&quot;')}" />
          </div>
        </div>
        <div class="settings-row">
          <div class="settings-field">
            <label for="settingsImapHost">IMAP host</label>
            <input id="settingsImapHost" class="input" type="text" value="${String(storedTransport.imapHost || '').replace(/"/g, '&quot;')}" />
          </div>
          <div class="settings-field">
            <label for="settingsImapPort">IMAP port</label>
            <input id="settingsImapPort" class="input" type="text" value="${String(storedTransport.imapPort || '993').replace(/"/g, '&quot;')}" />
          </div>
        </div>
        <div class="settings-row">
          <div class="settings-field">
            <label for="settingsImapUser">IMAP user</label>
            <input id="settingsImapUser" class="input" type="text" value="${String(storedTransport.imapUser || '').replace(/"/g, '&quot;')}" />
          </div>
          <div class="settings-field">
            <label for="settingsImapPass">IMAP password</label>
            <input id="settingsImapPass" class="input" type="password" value="${String(storedTransport.imapPass || '').replace(/"/g, '&quot;')}" />
          </div>
        </div>
        <div class="settings-row">
          <div class="settings-field">
            <label for="settingsExchangeUrl">Exchange endpoint</label>
            <input id="settingsExchangeUrl" class="input" type="text" value="${String(storedTransport.exchangeUrl || '').replace(/"/g, '&quot;')}" />
          </div>
          <div class="settings-field">
            <label for="settingsExchangeTenant">Exchange tenant</label>
            <input id="settingsExchangeTenant" class="input" type="text" value="${String(storedTransport.exchangeTenant || '').replace(/"/g, '&quot;')}" />
          </div>
        </div>
        <div id="settingsMailSaveProgressWrap" class="settings-mail-progress" hidden>
          <div id="settingsMailSaveProgressBar" class="settings-mail-progress-bar"></div>
        </div>
        <div class="actions">
          <button id="settingsMailSaveBtn" class="btn" type="button">Save templates and transport</button>
        </div>
        <p id="settingsMailSaveStatus" class="settings-upload-status"></p>
      `;
      bindEmailTextHandlers(modal);
      return;
    }

    mount.innerHTML = `
      <h4>${panelId === 'general' ? 'Placeholder' : 'Coming Soon'}</h4>
      <p>This section is intentionally reserved and will be extended in a later batch.</p>
    `;
  }

  function bindAboutMeHandlers(modal) {
    const CROP_SIZE = 220;
    const preview = modal.querySelector('#settingsAvatarPreview');
    const dropzone = modal.querySelector('#settingsAvatarDropzone');
    const input = modal.querySelector('#settingsAvatarInput');
    const cropStage = modal.querySelector('#settingsAvatarCropStage');
    const cropImage = modal.querySelector('#settingsAvatarCropImage');
    const zoomInput = modal.querySelector('#settingsAvatarZoom');
    const uploadStatus = modal.querySelector('#settingsAvatarUploadStatus');
    const saveBtn = modal.querySelector('#settingsAvatarSaveBtn');
    const resetBtn = modal.querySelector('#settingsAvatarResetBtn');

    const state = {
      stagedAvatar: localStorage.getItem(STORAGE_KEYS.avatar) || DEFAULTS.avatar,
      sourceImage: null,
      zoom: 1,
      minScale: 1,
      offsetX: 0,
      offsetY: 0,
      dragging: false,
      dragStartX: 0,
      dragStartY: 0,
      dragOffsetX: 0,
      dragOffsetY: 0,
    };

    function setUploadStatus(message) {
      if (uploadStatus instanceof HTMLElement) {
        uploadStatus.textContent = message;
      }
    }

    function setDropzoneOver(isOver) {
      if (!(dropzone instanceof HTMLElement)) return;
      dropzone.classList.toggle('is-over', isOver);
    }

    function clampOffsets() {
      if (!(state.sourceImage instanceof HTMLImageElement)) return;
      const renderWidth = state.sourceImage.naturalWidth * state.minScale * state.zoom;
      const renderHeight = state.sourceImage.naturalHeight * state.minScale * state.zoom;

      if (renderWidth <= CROP_SIZE) {
        state.offsetX = (CROP_SIZE - renderWidth) / 2;
      } else {
        const minX = CROP_SIZE - renderWidth;
        state.offsetX = Math.max(minX, Math.min(0, state.offsetX));
      }

      if (renderHeight <= CROP_SIZE) {
        state.offsetY = (CROP_SIZE - renderHeight) / 2;
      } else {
        const minY = CROP_SIZE - renderHeight;
        state.offsetY = Math.max(minY, Math.min(0, state.offsetY));
      }
    }

    function renderCrop() {
      if (!(cropImage instanceof HTMLImageElement)) return;
      if (!(state.sourceImage instanceof HTMLImageElement)) return;

      const renderWidth = state.sourceImage.naturalWidth * state.minScale * state.zoom;
      const renderHeight = state.sourceImage.naturalHeight * state.minScale * state.zoom;
      cropImage.style.width = `${renderWidth}px`;
      cropImage.style.height = `${renderHeight}px`;
      cropImage.style.transform = `translate(${state.offsetX}px, ${state.offsetY}px)`;
    }

    function updateZoom(nextZoom) {
      if (!(state.sourceImage instanceof HTMLImageElement)) {
        state.zoom = nextZoom;
        return;
      }

      const clampedZoom = Math.max(1, Math.min(4, nextZoom));
      const oldScale = state.minScale * state.zoom;
      const newScale = state.minScale * clampedZoom;
      const oldWidth = state.sourceImage.naturalWidth * oldScale;
      const oldHeight = state.sourceImage.naturalHeight * oldScale;
      const newWidth = state.sourceImage.naturalWidth * newScale;
      const newHeight = state.sourceImage.naturalHeight * newScale;

      const anchorX = CROP_SIZE / 2;
      const anchorY = CROP_SIZE / 2;
      const relativeX = oldWidth === 0 ? 0.5 : (anchorX - state.offsetX) / oldWidth;
      const relativeY = oldHeight === 0 ? 0.5 : (anchorY - state.offsetY) / oldHeight;

      state.zoom = clampedZoom;
      state.offsetX = anchorX - relativeX * newWidth;
      state.offsetY = anchorY - relativeY * newHeight;
      clampOffsets();
      renderCrop();
    }

    function loadImageFromDataUrl(dataUrl) {
      const image = new Image();
      image.onload = () => {
        state.sourceImage = image;
        state.zoom = 1;
        if (zoomInput instanceof HTMLInputElement) {
          zoomInput.value = '1';
        }

        state.minScale = Math.max(CROP_SIZE / image.naturalWidth, CROP_SIZE / image.naturalHeight);
        const renderWidth = image.naturalWidth * state.minScale;
        const renderHeight = image.naturalHeight * state.minScale;
        state.offsetX = (CROP_SIZE - renderWidth) / 2;
        state.offsetY = (CROP_SIZE - renderHeight) / 2;
        clampOffsets();

        if (cropImage instanceof HTMLImageElement) {
          cropImage.src = dataUrl;
        }
        renderCrop();
      };

      image.onerror = () => {
        setUploadStatus('Could not load image. Please select another file.');
      };

      image.src = dataUrl;
    }

    function readFile(file) {
      if (!(file instanceof File)) return;
      if (!file.type.startsWith('image/')) {
        setUploadStatus('Only image files are supported.');
        return;
      }

      const reader = new FileReader();
      reader.onload = () => {
        if (typeof reader.result !== 'string') return;
        loadImageFromDataUrl(reader.result);
        setUploadStatus('');
      };
      reader.readAsDataURL(file);
    }

    function exportCroppedAvatar() {
      if (!(state.sourceImage instanceof HTMLImageElement)) {
        return state.stagedAvatar;
      }

      const renderWidth = state.sourceImage.naturalWidth * state.minScale * state.zoom;
      const renderHeight = state.sourceImage.naturalHeight * state.minScale * state.zoom;
      const sourceX = (-state.offsetX / renderWidth) * state.sourceImage.naturalWidth;
      const sourceY = (-state.offsetY / renderHeight) * state.sourceImage.naturalHeight;
      const sourceWidth = (CROP_SIZE / renderWidth) * state.sourceImage.naturalWidth;
      const sourceHeight = (CROP_SIZE / renderHeight) * state.sourceImage.naturalHeight;

      const clampedSourceX = Math.max(0, Math.min(state.sourceImage.naturalWidth - 1, sourceX));
      const clampedSourceY = Math.max(0, Math.min(state.sourceImage.naturalHeight - 1, sourceY));
      const clampedSourceWidth = Math.max(1, Math.min(state.sourceImage.naturalWidth - clampedSourceX, sourceWidth));
      const clampedSourceHeight = Math.max(1, Math.min(state.sourceImage.naturalHeight - clampedSourceY, sourceHeight));

      const canvas = document.createElement('canvas');
      canvas.width = 512;
      canvas.height = 512;
      const context = canvas.getContext('2d');
      if (!context) {
        return state.stagedAvatar;
      }

      context.drawImage(
        state.sourceImage,
        clampedSourceX,
        clampedSourceY,
        clampedSourceWidth,
        clampedSourceHeight,
        0,
        0,
        canvas.width,
        canvas.height
      );

      return canvas.toDataURL('image/png');
    }

    if (preview instanceof HTMLImageElement) {
      preview.src = state.stagedAvatar;
    }
    loadImageFromDataUrl(state.stagedAvatar);
    setUploadStatus('');

    input?.addEventListener('change', () => {
      if (!(input instanceof HTMLInputElement)) return;
      const file = input.files && input.files[0] ? input.files[0] : null;
      if (!file) return;
      readFile(file);
    });

    dropzone?.addEventListener('click', () => {
      if (input instanceof HTMLInputElement) {
        input.click();
      }
    });

    dropzone?.addEventListener('keydown', (event) => {
      if (!(input instanceof HTMLInputElement)) return;
      if (event.key !== 'Enter' && event.key !== ' ') return;
      event.preventDefault();
      input.click();
    });

    dropzone?.addEventListener('dragenter', (event) => {
      event.preventDefault();
      setDropzoneOver(true);
    });

    dropzone?.addEventListener('dragover', (event) => {
      event.preventDefault();
      setDropzoneOver(true);
    });

    dropzone?.addEventListener('dragleave', (event) => {
      event.preventDefault();
      setDropzoneOver(false);
    });

    dropzone?.addEventListener('drop', (event) => {
      event.preventDefault();
      setDropzoneOver(false);
      if (!('dataTransfer' in event) || !event.dataTransfer) return;
      const file = event.dataTransfer.files && event.dataTransfer.files[0] ? event.dataTransfer.files[0] : null;
      if (!file) return;
      if (input instanceof HTMLInputElement) {
        input.value = '';
      }
      readFile(file);
    });

    zoomInput?.addEventListener('input', () => {
      if (!(zoomInput instanceof HTMLInputElement)) return;
      updateZoom(Number.parseFloat(zoomInput.value));
    });

    cropStage?.addEventListener('pointerdown', (event) => {
      if (!(state.sourceImage instanceof HTMLImageElement)) return;
      state.dragging = true;
      state.dragStartX = event.clientX;
      state.dragStartY = event.clientY;
      state.dragOffsetX = state.offsetX;
      state.dragOffsetY = state.offsetY;
      cropStage.classList.add('dragging');
      if (cropStage instanceof HTMLElement && typeof cropStage.setPointerCapture === 'function') {
        cropStage.setPointerCapture(event.pointerId);
      }
    });

    cropStage?.addEventListener('pointermove', (event) => {
      if (!state.dragging) return;
      state.offsetX = state.dragOffsetX + (event.clientX - state.dragStartX);
      state.offsetY = state.dragOffsetY + (event.clientY - state.dragStartY);
      clampOffsets();
      renderCrop();
    });

    const stopDragging = () => {
      if (!state.dragging) return;
      state.dragging = false;
      if (cropStage instanceof HTMLElement) {
        cropStage.classList.remove('dragging');
      }
    };

    cropStage?.addEventListener('pointerup', stopDragging);
    cropStage?.addEventListener('pointercancel', stopDragging);
    cropStage?.addEventListener('pointerleave', stopDragging);

    saveBtn?.addEventListener('click', () => {
      state.stagedAvatar = exportCroppedAvatar();
      if (preview instanceof HTMLImageElement) {
        preview.src = state.stagedAvatar;
      }
      localStorage.setItem(STORAGE_KEYS.avatar, state.stagedAvatar);
      applySavedAvatar();
      setUploadStatus('Avatar updated.');
    });

    resetBtn?.addEventListener('click', () => {
      localStorage.removeItem(STORAGE_KEYS.avatar);
      state.stagedAvatar = DEFAULTS.avatar;
      if (preview instanceof HTMLImageElement) {
        preview.src = state.stagedAvatar;
      }
      loadImageFromDataUrl(state.stagedAvatar);
      applySavedAvatar();
      setUploadStatus('');
    });
  }

  function bindCredentialsHandlers(modal) {
    const current = modal.querySelector('#settingsCurrentPassword');
    const next = modal.querySelector('#settingsNewPassword');
    const confirm = modal.querySelector('#settingsConfirmPassword');
    const saveBtn = modal.querySelector('#settingsPasswordSaveBtn');
    const status = modal.querySelector('#settingsPasswordStatus');

    saveBtn?.addEventListener('click', () => {
      if (!(current instanceof HTMLInputElement)) return;
      if (!(next instanceof HTMLInputElement)) return;
      if (!(confirm instanceof HTMLInputElement)) return;
      if (!(status instanceof HTMLElement)) return;

      if (next.value.trim().length < 8) {
        status.textContent = 'Password must be at least 8 characters.';
        return;
      }

      if (next.value !== confirm.value) {
        status.textContent = 'New password and confirmation do not match.';
        return;
      }

      status.textContent = 'Password updated (mock flow).';
      current.value = '';
      next.value = '';
      confirm.value = '';
    });
  }

  function bindThemeHandlers(modal) {
    const modeSelect = modal.querySelector('#settingsThemeMode');
    const applyBtn = modal.querySelector('#settingsThemeApplyBtn');
    const saveBtn = modal.querySelector('#settingsThemeSaveBtn');
    const resetBtn = modal.querySelector('#settingsThemeResetBtn');

    function readCustomThemeFromInputs() {
      const customTheme = {};
      THEME_COLOR_FIELDS.forEach((field) => {
        const input = modal.querySelector(`#${field.id}`);
        if (!(input instanceof HTMLInputElement)) return;
        customTheme[field.variable] = normalizeHex(input.value, '#000000');
      });
      return customTheme;
    }

    function applyCurrentSelection() {
      const mode = modeSelect instanceof HTMLSelectElement ? modeSelect.value : 'dark';
      const customTheme = readCustomThemeFromInputs();
      applyThemeVariables(resolveTheme(mode, customTheme));
    }

    applyBtn?.addEventListener('click', applyCurrentSelection);

    saveBtn?.addEventListener('click', () => {
      const mode = modeSelect instanceof HTMLSelectElement ? modeSelect.value : 'dark';
      const customTheme = readCustomThemeFromInputs();
      localStorage.setItem(STORAGE_KEYS.themeMode, mode);
      localStorage.setItem(STORAGE_KEYS.customTheme, JSON.stringify(customTheme));
      applyThemeVariables(resolveTheme(mode, customTheme));
    });

    resetBtn?.addEventListener('click', () => {
      localStorage.setItem(STORAGE_KEYS.themeMode, 'dark');
      localStorage.removeItem(STORAGE_KEYS.customTheme);
      applyThemeVariables(DARK_THEME);
      renderPanelContent(modal, modal.getAttribute('data-role') || 'user', 'theme', {});
      emitPreferencesUpdated();
    });
  }

  function bindGeneralHandlers(modal) {
    const CROP_WIDTH = 360;
    const CROP_HEIGHT = 96;
    const EXPORT_WIDTH = 1200;
    const EXPORT_HEIGHT = 320;

    const preview = modal.querySelector('#settingsLogoPreview');
    const dropzone = modal.querySelector('#settingsLogoDropzone');
    const input = modal.querySelector('#settingsLogoInput');
    const cropStage = modal.querySelector('#settingsLogoCropStage');
    const cropImage = modal.querySelector('#settingsLogoCropImage');
    const zoomInput = modal.querySelector('#settingsLogoZoom');
    const uploadStatus = modal.querySelector('#settingsLogoUploadStatus');
    const saveBtn = modal.querySelector('#settingsLogoSaveBtn');
    const resetBtn = modal.querySelector('#settingsLogoResetBtn');

    const state = {
      stagedLogo: localStorage.getItem(STORAGE_KEYS.logo) || DEFAULTS.logo,
      sourceImage: null,
      zoom: 1,
      minScale: 1,
      offsetX: 0,
      offsetY: 0,
      dragging: false,
      dragStartX: 0,
      dragStartY: 0,
      dragOffsetX: 0,
      dragOffsetY: 0,
    };

    function setUploadStatus(message) {
      if (uploadStatus instanceof HTMLElement) {
        uploadStatus.textContent = message;
      }
    }

    function setDropzoneOver(isOver) {
      if (!(dropzone instanceof HTMLElement)) return;
      dropzone.classList.toggle('is-over', isOver);
    }

    function clampOffsets() {
      if (!(state.sourceImage instanceof HTMLImageElement)) return;
      const renderWidth = state.sourceImage.naturalWidth * state.minScale * state.zoom;
      const renderHeight = state.sourceImage.naturalHeight * state.minScale * state.zoom;

      if (renderWidth <= CROP_WIDTH) {
        state.offsetX = (CROP_WIDTH - renderWidth) / 2;
      } else {
        const minX = CROP_WIDTH - renderWidth;
        state.offsetX = Math.max(minX, Math.min(0, state.offsetX));
      }

      if (renderHeight <= CROP_HEIGHT) {
        state.offsetY = (CROP_HEIGHT - renderHeight) / 2;
      } else {
        const minY = CROP_HEIGHT - renderHeight;
        state.offsetY = Math.max(minY, Math.min(0, state.offsetY));
      }
    }

    function renderCrop() {
      if (!(cropImage instanceof HTMLImageElement)) return;
      if (!(state.sourceImage instanceof HTMLImageElement)) return;
      const renderWidth = state.sourceImage.naturalWidth * state.minScale * state.zoom;
      const renderHeight = state.sourceImage.naturalHeight * state.minScale * state.zoom;
      cropImage.style.width = `${renderWidth}px`;
      cropImage.style.height = `${renderHeight}px`;
      cropImage.style.transform = `translate(${state.offsetX}px, ${state.offsetY}px)`;
    }

    function updateZoom(nextZoom) {
      if (!(state.sourceImage instanceof HTMLImageElement)) {
        state.zoom = nextZoom;
        return;
      }

      const clampedZoom = Math.max(1, Math.min(4, nextZoom));
      const oldScale = state.minScale * state.zoom;
      const newScale = state.minScale * clampedZoom;
      const oldWidth = state.sourceImage.naturalWidth * oldScale;
      const oldHeight = state.sourceImage.naturalHeight * oldScale;
      const newWidth = state.sourceImage.naturalWidth * newScale;
      const newHeight = state.sourceImage.naturalHeight * newScale;

      const anchorX = CROP_WIDTH / 2;
      const anchorY = CROP_HEIGHT / 2;
      const relativeX = oldWidth === 0 ? 0.5 : (anchorX - state.offsetX) / oldWidth;
      const relativeY = oldHeight === 0 ? 0.5 : (anchorY - state.offsetY) / oldHeight;

      state.zoom = clampedZoom;
      state.offsetX = anchorX - relativeX * newWidth;
      state.offsetY = anchorY - relativeY * newHeight;
      clampOffsets();
      renderCrop();
    }

    function loadImageFromDataUrl(dataUrl) {
      const image = new Image();
      image.onload = () => {
        state.sourceImage = image;
        state.zoom = 1;
        if (zoomInput instanceof HTMLInputElement) {
          zoomInput.value = '1';
        }

        state.minScale = Math.max(CROP_WIDTH / image.naturalWidth, CROP_HEIGHT / image.naturalHeight);
        const renderWidth = image.naturalWidth * state.minScale;
        const renderHeight = image.naturalHeight * state.minScale;
        state.offsetX = (CROP_WIDTH - renderWidth) / 2;
        state.offsetY = (CROP_HEIGHT - renderHeight) / 2;
        clampOffsets();

        if (cropImage instanceof HTMLImageElement) {
          cropImage.src = dataUrl;
        }
        renderCrop();
      };

      image.onerror = () => {
        setUploadStatus('Could not load logo image. Please select another file.');
      };

      image.src = dataUrl;
    }

    function readFile(file) {
      if (!(file instanceof File)) return;
      if (!file.type.startsWith('image/')) {
        setUploadStatus('Only image files are supported.');
        return;
      }

      const reader = new FileReader();
      reader.onload = () => {
        if (typeof reader.result !== 'string') return;
        loadImageFromDataUrl(reader.result);
        setUploadStatus('');
      };
      reader.readAsDataURL(file);
    }

    function exportCroppedLogo() {
      if (!(state.sourceImage instanceof HTMLImageElement)) {
        return state.stagedLogo;
      }

      const renderWidth = state.sourceImage.naturalWidth * state.minScale * state.zoom;
      const renderHeight = state.sourceImage.naturalHeight * state.minScale * state.zoom;
      const sourceX = (-state.offsetX / renderWidth) * state.sourceImage.naturalWidth;
      const sourceY = (-state.offsetY / renderHeight) * state.sourceImage.naturalHeight;
      const sourceWidth = (CROP_WIDTH / renderWidth) * state.sourceImage.naturalWidth;
      const sourceHeight = (CROP_HEIGHT / renderHeight) * state.sourceImage.naturalHeight;

      const clampedSourceX = Math.max(0, Math.min(state.sourceImage.naturalWidth - 1, sourceX));
      const clampedSourceY = Math.max(0, Math.min(state.sourceImage.naturalHeight - 1, sourceY));
      const clampedSourceWidth = Math.max(1, Math.min(state.sourceImage.naturalWidth - clampedSourceX, sourceWidth));
      const clampedSourceHeight = Math.max(1, Math.min(state.sourceImage.naturalHeight - clampedSourceY, sourceHeight));

      const canvas = document.createElement('canvas');
      canvas.width = EXPORT_WIDTH;
      canvas.height = EXPORT_HEIGHT;
      const context = canvas.getContext('2d');
      if (!context) {
        return state.stagedLogo;
      }

      context.drawImage(
        state.sourceImage,
        clampedSourceX,
        clampedSourceY,
        clampedSourceWidth,
        clampedSourceHeight,
        0,
        0,
        canvas.width,
        canvas.height
      );

      return canvas.toDataURL('image/png');
    }

    if (preview instanceof HTMLImageElement) {
      preview.src = state.stagedLogo;
    }
    loadImageFromDataUrl(state.stagedLogo);

    input?.addEventListener('change', () => {
      if (!(input instanceof HTMLInputElement)) return;
      const file = input.files && input.files[0] ? input.files[0] : null;
      if (!file) return;
      readFile(file);
    });

    dropzone?.addEventListener('click', () => {
      if (input instanceof HTMLInputElement) {
        input.click();
      }
    });

    dropzone?.addEventListener('keydown', (event) => {
      if (!(input instanceof HTMLInputElement)) return;
      if (event.key !== 'Enter' && event.key !== ' ') return;
      event.preventDefault();
      input.click();
    });

    dropzone?.addEventListener('dragenter', (event) => {
      event.preventDefault();
      setDropzoneOver(true);
    });

    dropzone?.addEventListener('dragover', (event) => {
      event.preventDefault();
      setDropzoneOver(true);
    });

    dropzone?.addEventListener('dragleave', (event) => {
      event.preventDefault();
      setDropzoneOver(false);
    });

    dropzone?.addEventListener('drop', (event) => {
      event.preventDefault();
      setDropzoneOver(false);
      if (!('dataTransfer' in event) || !event.dataTransfer) return;
      const file = event.dataTransfer.files && event.dataTransfer.files[0] ? event.dataTransfer.files[0] : null;
      if (!file) return;
      if (input instanceof HTMLInputElement) {
        input.value = '';
      }
      readFile(file);
    });

    zoomInput?.addEventListener('input', () => {
      if (!(zoomInput instanceof HTMLInputElement)) return;
      updateZoom(Number.parseFloat(zoomInput.value));
    });

    cropStage?.addEventListener('pointerdown', (event) => {
      if (!(state.sourceImage instanceof HTMLImageElement)) return;
      state.dragging = true;
      state.dragStartX = event.clientX;
      state.dragStartY = event.clientY;
      state.dragOffsetX = state.offsetX;
      state.dragOffsetY = state.offsetY;
      cropStage.classList.add('dragging');
      if (cropStage instanceof HTMLElement && typeof cropStage.setPointerCapture === 'function') {
        cropStage.setPointerCapture(event.pointerId);
      }
    });

    cropStage?.addEventListener('pointermove', (event) => {
      if (!state.dragging) return;
      state.offsetX = state.dragOffsetX + (event.clientX - state.dragStartX);
      state.offsetY = state.dragOffsetY + (event.clientY - state.dragStartY);
      clampOffsets();
      renderCrop();
    });

    const stopDragging = () => {
      if (!state.dragging) return;
      state.dragging = false;
      if (cropStage instanceof HTMLElement) {
        cropStage.classList.remove('dragging');
      }
    };

    cropStage?.addEventListener('pointerup', stopDragging);
    cropStage?.addEventListener('pointercancel', stopDragging);
    cropStage?.addEventListener('pointerleave', stopDragging);

    saveBtn?.addEventListener('click', () => {
      state.stagedLogo = exportCroppedLogo();
      if (preview instanceof HTMLImageElement) {
        preview.src = state.stagedLogo;
      }
      localStorage.setItem(STORAGE_KEYS.logo, state.stagedLogo);
      applySavedLogo();
      setUploadStatus('Logo updated.');
      emitPreferencesUpdated();
    });

    resetBtn?.addEventListener('click', () => {
      localStorage.removeItem(STORAGE_KEYS.logo);
      state.stagedLogo = DEFAULTS.logo;
      if (preview instanceof HTMLImageElement) {
        preview.src = state.stagedLogo;
      }
      loadImageFromDataUrl(state.stagedLogo);
      applySavedLogo();
      setUploadStatus('');
      emitPreferencesUpdated();
    });
  }

  function bindRegionalTimeHandlers(modal) {
    const select = modal.querySelector('#settingsTimeFormatSelect');
    const preview = modal.querySelector('#settingsTimeFormatPreview');
    const applyBtn = modal.querySelector('#settingsTimeFormatApplyBtn');
    const saveBtn = modal.querySelector('#settingsTimeFormatSaveBtn');
    const status = modal.querySelector('#settingsTimeFormatStatus');

    function updatePreview() {
      if (!(preview instanceof HTMLElement)) return;
      const format = select instanceof HTMLSelectElement ? select.value : getCurrentTimeFormat();
      preview.textContent = formatDateTimeDisplay(new Date(), format);
    }

    function persistFormat(withStatusText) {
      if (!(select instanceof HTMLSelectElement)) return;
      const selected = Object.prototype.hasOwnProperty.call(TIME_FORMAT_MAP, select.value)
        ? select.value
        : DEFAULT_TIME_FORMAT;
      localStorage.setItem(STORAGE_KEYS.timeFormat, selected);
      updatePreview();
      emitPreferencesUpdated();
      if (status instanceof HTMLElement) {
        status.textContent = withStatusText;
      }
    }

    select?.addEventListener('change', updatePreview);

    applyBtn?.addEventListener('click', () => {
      persistFormat('Time format applied.');
    });

    saveBtn?.addEventListener('click', () => {
      persistFormat('Time format saved globally.');
    });

    updatePreview();
  }

  function bindEmailTextHandlers(modal) {
    const subjectInput = modal.querySelector('#settingsInvitationSubject');
    const bodyEditor = modal.querySelector('#settingsInvitationBody');
    const smtpHost = modal.querySelector('#settingsSmtpHost');
    const smtpPort = modal.querySelector('#settingsSmtpPort');
    const smtpUser = modal.querySelector('#settingsSmtpUser');
    const smtpPass = modal.querySelector('#settingsSmtpPass');
    const imapHost = modal.querySelector('#settingsImapHost');
    const imapPort = modal.querySelector('#settingsImapPort');
    const imapUser = modal.querySelector('#settingsImapUser');
    const imapPass = modal.querySelector('#settingsImapPass');
    const exchangeUrl = modal.querySelector('#settingsExchangeUrl');
    const exchangeTenant = modal.querySelector('#settingsExchangeTenant');
    const saveBtn = modal.querySelector('#settingsMailSaveBtn');
    const status = modal.querySelector('#settingsMailSaveStatus');
    const progressWrap = modal.querySelector('#settingsMailSaveProgressWrap');
    const progressBar = modal.querySelector('#settingsMailSaveProgressBar');

    let timer = null;

    saveBtn?.addEventListener('click', () => {
      if (!(subjectInput instanceof HTMLInputElement)) return;
      if (!(bodyEditor instanceof HTMLElement)) return;

      if (progressWrap instanceof HTMLElement) progressWrap.hidden = false;
      if (progressBar instanceof HTMLElement) progressBar.style.width = '0%';
      if (status instanceof HTMLElement) status.textContent = 'Saving template and testing transport...';
      if (saveBtn instanceof HTMLButtonElement) saveBtn.disabled = true;

      const invitationTemplate = {
        subject: subjectInput.value.trim(),
        body: bodyEditor.innerHTML,
      };

      const transportProfile = {
        smtpHost: smtpHost instanceof HTMLInputElement ? smtpHost.value.trim() : '',
        smtpPort: smtpPort instanceof HTMLInputElement ? smtpPort.value.trim() : '',
        smtpUser: smtpUser instanceof HTMLInputElement ? smtpUser.value.trim() : '',
        smtpPass: smtpPass instanceof HTMLInputElement ? smtpPass.value : '',
        imapHost: imapHost instanceof HTMLInputElement ? imapHost.value.trim() : '',
        imapPort: imapPort instanceof HTMLInputElement ? imapPort.value.trim() : '',
        imapUser: imapUser instanceof HTMLInputElement ? imapUser.value.trim() : '',
        imapPass: imapPass instanceof HTMLInputElement ? imapPass.value : '',
        exchangeUrl: exchangeUrl instanceof HTMLInputElement ? exchangeUrl.value.trim() : '',
        exchangeTenant: exchangeTenant instanceof HTMLInputElement ? exchangeTenant.value.trim() : '',
      };

      let progress = 0;
      if (timer !== null) {
        window.clearInterval(timer);
      }
      timer = window.setInterval(() => {
        progress = Math.min(100, progress + 10 + Math.floor(Math.random() * 10));
        if (progressBar instanceof HTMLElement) {
          progressBar.style.width = `${progress}%`;
        }

        if (progress < 100) return;

        window.clearInterval(timer);
        timer = null;
        localStorage.setItem(STORAGE_KEYS.invitationTemplate, JSON.stringify(invitationTemplate));
        localStorage.setItem(STORAGE_KEYS.mailTransportProfile, JSON.stringify(transportProfile));
        if (status instanceof HTMLElement) {
          status.textContent = 'Email templates and transport settings saved.';
        }
        if (saveBtn instanceof HTMLButtonElement) saveBtn.disabled = false;
        emitPreferencesUpdated();
      }, 110);
    });
  }

  function activateTile(modal, panelId) {
    const tiles = Array.from(modal.querySelectorAll('[data-settings-tile]'));
    tiles.forEach((tile) => {
      const isActive = tile.getAttribute('data-settings-tile') === panelId;
      tile.classList.toggle('active', isActive);
    });
  }

  function openSettingsModal(modal, role, session, panel = 'about-me') {
    activateTile(modal, panel);
    renderPanelContent(modal, role, panel, session);
    modal.hidden = false;
  }

  function closeSettingsModal(modal) {
    modal.hidden = true;
  }

  function init(options) {
    const role = options && options.role === 'admin' ? 'admin' : 'user';
    const session = {
      displayName: options && typeof options.displayName === 'string' ? options.displayName : '',
      email: options && typeof options.email === 'string' ? options.email : '',
    };

    applySavedTheme();
    applySavedAvatar();
    applySavedLogo();

    const modal = createSettingsModal(role);
    modal.setAttribute('data-role', role);

    if (!modal.hasAttribute('data-settings-bound')) {
      const closeBtn = modal.querySelector('#settingsCloseBtn');
      const closeFooterBtn = modal.querySelector('#settingsCloseFooterBtn');
      const backdrop = modal.querySelector('[data-settings-close="true"]');

      closeBtn?.addEventListener('click', () => closeSettingsModal(modal));
      closeFooterBtn?.addEventListener('click', () => closeSettingsModal(modal));
      backdrop?.addEventListener('click', () => closeSettingsModal(modal));

      modal.addEventListener('click', (event) => {
        const target = event.target;
        if (!(target instanceof HTMLElement)) return;
        const tile = target.closest('[data-settings-tile]');
        if (!(tile instanceof HTMLElement)) return;

        const panel = tile.getAttribute('data-settings-tile') || 'about-me';
        activateTile(modal, panel);
        renderPanelContent(modal, modal.getAttribute('data-role') || 'user', panel, session);
      });

      window.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && !modal.hidden) {
          closeSettingsModal(modal);
        }
      });

      modal.setAttribute('data-settings-bound', 'true');
    }

    const settingsButton = options && options.settingsButton instanceof HTMLElement ? options.settingsButton : document.getElementById('settingsBtn');
    if (settingsButton instanceof HTMLElement) {
      settingsButton.addEventListener('click', (event) => {
        event.preventDefault();
        openSettingsModal(modal, role, session, 'about-me');
      });
    }

    const sidebarAvatar = document.querySelector('[data-sidebar-avatar]');
    if (sidebarAvatar instanceof HTMLElement) {
      sidebarAvatar.addEventListener('click', () => {
        openSettingsModal(modal, role, session, 'about-me');
      });
      sidebarAvatar.style.cursor = 'pointer';
    }
  }

  function getInvitationTemplate() {
    return readStoredJSON(STORAGE_KEYS.invitationTemplate, {
      subject: 'Invitation: [title] on [date] at [time]',
      body:
        '<p>[sehrgeehrte/r] [anrede] [titel] [nachname] [vorname],</p><p>you are invited to join <strong>[title]</strong>.</p><p>Date and time: <strong>[date] [time]</strong>.</p><p>Best regards,<br/>Intelligent Intern</p>',
    });
  }

  window.KingSettingsUI = {
    init,
    getTimeFormat: getCurrentTimeFormat,
    formatDateTimeDisplay,
    formatDateTimeLocalInput,
    getInvitationTemplate,
    applyPersistedAppearance() {
      applySavedTheme();
      applySavedAvatar();
      applySavedLogo();
    },
  };
})();
