<template>
  <main class="app">
    <div class="shell no-right-sidebar" :class="shellClasses">
      <aside class="sidebar sidebar-left" :class="leftSidebarClasses">
        <div v-if="isCallWorkspace" class="sidebar-content left left-call-content">
          <div class="brand-strip">
            <img data-brand-logo src="/assets/orgas/kingrt/logo.svg" alt="KingRT" />
            <button
              class="sidebar-toggle-btn"
              type="button"
              :title="leftSidebarToggleLabel"
              :aria-label="leftSidebarToggleLabel"
              @click="handleLeftSidebarToggle"
            >
              <span v-if="isMobileViewport" class="sidebar-close-mark" aria-hidden="true">x</span>
              <img v-else class="arrow-icon-image" :src="leftSidebarToggleIcon" alt="" />
            </button>
          </div>

          <div class="call-left-settings">
            <section class="call-left-settings-block" aria-label="Camera">
              <div class="call-left-settings-title">Camera</div>
              <div class="call-left-settings-field">
                <AppSelect
                  id="call-left-camera-select"
                  aria-label="Camera"
                  :model-value="callMediaPrefs.selectedCameraId"
                  @update:model-value="setCallCameraDevice"
                >
                  <option value="">{{ callMediaPrefs.cameras.length === 0 ? 'No camera detected' : 'Select camera' }}</option>
                  <option
                    v-for="camera in callMediaPrefs.cameras"
                    :key="camera.id"
                    :value="camera.id"
                  >
                    {{ camera.label }}
                  </option>
                </AppSelect>
              </div>
            </section>

            <section class="call-left-settings-block" aria-label="Mic">
              <div class="call-left-settings-title">Mic</div>
              <div class="call-left-settings-field">
                <AppSelect
                  id="call-left-mic-select"
                  aria-label="Mic"
                  :model-value="callMediaPrefs.selectedMicrophoneId"
                  @update:model-value="setCallMicrophoneDevice"
                >
                  <option value="">{{ callMediaPrefs.microphones.length === 0 ? 'No microphone detected' : 'Select mic' }}</option>
                  <option
                    v-for="microphone in callMediaPrefs.microphones"
                    :key="microphone.id"
                    :value="microphone.id"
                  >
                    {{ microphone.label }}
                  </option>
                </AppSelect>
              </div>
              <div class="call-left-settings-field">
                <label for="call-left-mic-volume">Volume</label>
                <div class="call-left-volume-row">
                  <input
                    id="call-left-mic-volume"
                    class="call-left-range"
                    type="range"
                    min="0"
                    max="100"
                    step="1"
                    :value="callMediaPrefs.microphoneVolume"
                    @input="setCallMicrophoneVolume($event.target.value)"
                  />
                  <span class="call-left-volume-value">{{ callMediaPrefs.microphoneVolume }}%</span>
                </div>
                <div
                  class="call-left-meter"
                  role="meter"
                  aria-label="Microphone level"
                  aria-valuemin="0"
                  aria-valuemax="100"
                  :aria-valuenow="micLevelPercent"
                >
                  <span class="call-left-meter-bar" :style="{ width: `${micLevelPercent}%` }"></span>
                </div>
              </div>
            </section>

            <section class="call-left-settings-block" aria-label="Speaker">
              <div class="call-left-settings-title">Speaker</div>
              <div class="call-left-settings-field">
                <AppSelect
                  id="call-left-speaker-select"
                  aria-label="Speaker"
                  :model-value="callMediaPrefs.selectedSpeakerId"
                  @update:model-value="setCallSpeakerDevice"
                >
                  <option value="">{{ callMediaPrefs.speakers.length === 0 ? 'No speaker detected' : 'Select speaker' }}</option>
                  <option
                    v-for="speaker in callMediaPrefs.speakers"
                    :key="speaker.id"
                    :value="speaker.id"
                  >
                    {{ speaker.label }}
                  </option>
                </AppSelect>
              </div>
              <div class="call-left-settings-field">
                <label for="call-left-speaker-volume">Volume</label>
                <div class="call-left-volume-row">
                  <input
                    id="call-left-speaker-volume"
                    class="call-left-range"
                    type="range"
                    min="0"
                    max="100"
                    step="1"
                    :value="callMediaPrefs.speakerVolume"
                    @input="setCallSpeakerVolume($event.target.value)"
                  />
                  <span class="call-left-volume-value">{{ callMediaPrefs.speakerVolume }}%</span>
                </div>
              </div>
              <div class="call-left-settings-field">
                <button class="btn full call-left-test-btn" type="button" @click="playSpeakerTestSound">
                  Play test sound
                </button>
              </div>
            </section>

            <section class="call-left-settings-block" aria-label="Background blur">
              <div class="call-left-settings-title">Background blur</div>
              <div class="call-left-blur-controls" role="group" aria-label="Background blur controls">
                <button
                  class="call-left-blur-btn"
                  :class="{ active: isBackgroundPresetActive('light') }"
                  type="button"
                  :aria-pressed="isBackgroundPresetActive('light')"
                  aria-label="Blur"
                  title="Blur"
                  @click="applyBackgroundPreset('light')"
                >
                  <img class="call-left-blur-icon" src="/assets/orgas/kingrt/icons/desktop.png" alt="" />
                </button>
                <button
                  class="call-left-blur-btn"
                  :class="{ active: isBackgroundPresetActive('strong') }"
                  type="button"
                  :aria-pressed="isBackgroundPresetActive('strong')"
                  aria-label="Strong blur"
                  title="Strong blur"
                  @click="applyBackgroundPreset('strong')"
                >
                  <img class="call-left-blur-icon" src="/assets/orgas/kingrt/icons/desktop.png" alt="" />
                  <span class="call-left-blur-strong-mark" aria-hidden="true">+</span>
                </button>
              </div>
            </section>

            <section
              v-if="showInCallOwnerEditCard"
              class="call-left-owner-edit-block"
              aria-label="Call settings"
            >
              <div class="call-left-settings-title">Call settings</div>
              <button
                class="btn btn-cyan full call-left-owner-edit-btn"
                type="button"
                :disabled="callOwnerEditState.loadingContext || callOwnerEditState.submitting"
                @click="openInCallEditModal"
              >
                {{ callOwnerEditState.loadingContext ? 'Loading…' : 'Edit call' }}
              </button>
              <p v-if="callOwnerEditState.contextError" class="call-left-settings-error">
                {{ callOwnerEditState.contextError }}
              </p>
            </section>

            <div v-if="callMediaPrefs.error" class="call-left-settings-error">{{ callMediaPrefs.error }}</div>
          </div>
        </div>

        <div v-else class="sidebar-content left">
          <div class="brand-strip">
            <img data-brand-logo src="/assets/orgas/kingrt/logo.svg" alt="KingRT" />
            <button
              class="sidebar-toggle-btn"
              type="button"
              :title="leftSidebarToggleLabel"
              :aria-label="leftSidebarToggleLabel"
              @click="handleLeftSidebarToggle"
            >
              <span v-if="isMobileViewport" class="sidebar-close-mark" aria-hidden="true">x</span>
              <img v-else class="arrow-icon-image" :src="leftSidebarToggleIcon" alt="" />
            </button>
          </div>

          <nav class="nav" aria-label="Main navigation">
            <RouterLink
              v-for="item in navItems"
              :key="item.to"
              :to="item.to"
              class="nav-link"
              :class="{ active: isNavItemActive(item) }"
              @click="handleNavItemClick"
            >
              <img :src="item.icon" alt="" />
              <span>{{ item.label }}</span>
            </RouterLink>
          </nav>

          <section class="sidebar-profile avatar-only">
            <button class="sidebar-avatar-trigger" type="button" aria-label="Open settings" @click="openSettingsModal('about-me')">
              <img
                class="sidebar-avatar-image"
                :src="profileAvatarSrc"
                alt="Profile avatar"
              />
            </button>
          </section>

          <div class="logout-wrap">
            <button class="btn full" type="button" @click="handleSignOut">Log out</button>
          </div>
        </div>
      </aside>

      <section class="main" @click="handleMainClick">
        <div v-if="showMobileShellHeader" class="mobile-brand-strip">
          <img src="/assets/orgas/kingrt/king_logo-withslogan.svg" alt="KingRT" />
          <button class="mobile-menu-btn" type="button" aria-label="Toggle menu" @click.stop="handleLeftSidebarToggle">
            <span class="mobile-menu-btn-bars" aria-hidden="true"></span>
          </button>
        </div>
        <div class="workspace">
          <section v-if="showWorkspaceHeader" class="section">
            <div class="section-head">
              <div class="section-head-left">
                  <button
                    v-if="!isMobileViewport"
                    class="show-sidebar-overlay show-sidebar-inline show-left-sidebar-overlay"
                    type="button"
                    title="Show sidebar"
                    aria-label="Show sidebar"
                    @click="showLeftSidebar"
                  >
                    <img class="arrow-icon-image" src="/assets/orgas/kingrt/icons/forward.png" alt="" />
                  </button>
                <div class="section-head-title">
                  <h1 class="title">{{ pageTitle }}</h1>
                  <p v-if="pageSubtitle" class="subtitle">{{ pageSubtitle }}</p>
                </div>
              </div>
              <div class="actions">
                <template v-if="route.path === '/admin/overview'">
                  <button class="btn btn-cyan" type="button" @click="openCallsRegistry">Open Calls</button>
                  <button class="btn btn-cyan" type="button" @click="openGrafana">Open Grafana</button>
                </template>
                <button v-else class="btn" type="button" @click="openSettingsModal('about-me')">Settings</button>
              </div>
            </div>
          </section>

          <section class="panel-grid">
            <RouterView />
          </section>
        </div>
      </section>
    </div>
  </main>

  <div class="settings-modal" :hidden="!settingsState.open" role="dialog" aria-modal="true" aria-label="Workspace settings">
    <div class="settings-backdrop" @click="closeSettingsModal"></div>
    <div class="settings-dialog">
      <header class="settings-header">
        <div class="settings-title-wrap">
          <img src="/assets/orgas/kingrt/logo.svg" alt="" />
          <h3>Settings</h3>
        </div>
        <button class="icon-mini-btn" type="button" aria-label="Close settings" @click="closeSettingsModal">
          <img src="/assets/orgas/kingrt/icons/cancel.png" alt="" />
        </button>
      </header>

      <div class="settings-grid" role="tablist" aria-label="Settings categories">
        <button
          v-for="tile in settingsTiles"
          :key="tile.id"
          class="settings-tile tab"
          :class="{ active: activeSettingsTile === tile.id }"
          type="button"
          :disabled="settingsState.loading"
          @click="activeSettingsTile = tile.id"
        >
          {{ tile.label }}
        </button>
      </div>

      <section v-if="activeSettingsTile === 'about-me'" class="settings-panel">
        <div class="settings-row">
          <label class="settings-field">
            <span>Display name</span>
            <input v-model.trim="settingsDraft.displayName" class="input" type="text" autocomplete="name" />
          </label>
          <div class="settings-field">
            <span>Email</span>
            <div class="settings-readonly-value">{{ sessionState.email || '—' }}</div>
          </div>
        </div>

        <div class="settings-row">
          <div class="settings-field">
            <span>Avatar preview</span>
            <img class="settings-avatar-preview-lg" :src="settingsAvatarPreviewSrc" alt="Avatar preview" />
          </div>
          <div class="settings-field">
            <label
              class="settings-dropzone"
              :class="{ 'is-over': settingsState.dragging }"
              for="settings-avatar-input"
              @dragenter.prevent="settingsState.dragging = true"
              @dragover.prevent="settingsState.dragging = true"
              @dragleave.prevent="settingsState.dragging = false"
              @drop.prevent="handleAvatarDrop"
            >
              <input
                id="settings-avatar-input"
                class="settings-hidden-input"
                type="file"
                accept="image/png,image/jpeg,image/webp"
                @change="handleAvatarSelect"
              />
              <span class="settings-dropzone-title">Drop or choose an avatar</span>
              <span class="settings-dropzone-subtitle">PNG, JPEG, or WEBP. Backend upload is used directly.</span>
            </label>
            <div class="settings-upload-status">{{ settingsState.avatarStatus }}</div>
          </div>
        </div>
      </section>

      <section v-else-if="activeSettingsTile === 'theme'" class="settings-panel">
        <div class="settings-theme-layout">
          <section class="settings-theme-palette">
            <header class="settings-theme-palette-header">
              <div class="settings-theme-palette-heading">
                <img class="settings-theme-palette-icon" src="/assets/orgas/kingrt/icons/gear.png" alt="" />
                <h4>Theme Colors</h4>
              </div>
              <button class="btn" type="button" :disabled="settingsState.saving || settingsState.loading" @click="resetThemeColorsToDefault">
                Reset
              </button>
            </header>

            <div class="settings-theme-palette-list">
              <article v-for="field in themeColorFields" :key="field.key" class="settings-theme-color-row">
                <div class="settings-theme-color-meta">
                  <img class="settings-theme-color-meta-icon" src="/assets/orgas/kingrt/icons/gear.png" alt="" />
                  <div class="settings-theme-color-copy">
                    <span class="settings-theme-color-label">{{ field.label }}</span>
                    <code class="settings-theme-color-key">{{ field.key }}</code>
                  </div>
                </div>
                <div class="settings-theme-color-inputs">
                  <input
                    class="settings-theme-swatch"
                    type="color"
                    :value="settingsDraft.themeColors[field.key] || field.default"
                    @input="updateThemeColor(field.key, $event?.target?.value)"
                  />
                  <input
                    class="input settings-theme-hex"
                    type="text"
                    maxlength="7"
                    :value="settingsDraft.themeColors[field.key] || field.default"
                    @input="updateThemeColor(field.key, $event?.target?.value)"
                  />
                </div>
              </article>
            </div>
          </section>

          <section class="settings-theme-preview">
            <header class="settings-theme-preview-header">
              <img class="settings-theme-preview-header-icon" src="/assets/orgas/kingrt/icons/gear.png" alt="" />
              <h4>Video Call Management</h4>
            </header>

            <div class="settings-theme-preview-viewport">
              <div class="settings-theme-preview-scale">
                <div class="settings-theme-preview-shell">
                  <aside class="settings-theme-preview-left-pane">
                    <div class="brand-strip settings-theme-preview-brand-strip">
                      <img data-brand-logo src="/assets/orgas/kingrt/logo.svg" alt="KingRT" />
                    </div>
                    <nav class="nav settings-theme-preview-nav" aria-label="Preview workspace navigation">
                      <a class="nav-link active" href="#" @click.prevent>
                        <img src="/assets/orgas/kingrt/icons/lobby.png" alt="" />
                        <span>Video Calls</span>
                      </a>
                      <a class="nav-link" href="#" @click.prevent>
                        <img src="/assets/orgas/kingrt/icons/user.png" alt="" />
                        <span>User Management</span>
                      </a>
                      <a class="nav-link" href="#" @click.prevent>
                        <img src="/assets/orgas/kingrt/icons/users.png" alt="" />
                        <span>Overview</span>
                      </a>
                    </nav>
                    <section class="sidebar-profile avatar-only settings-theme-preview-profile">
                      <button class="sidebar-avatar-trigger" type="button" aria-label="Preview profile avatar">
                        <img class="sidebar-avatar-image" :src="settingsAvatarPreviewSrc" alt="Preview avatar" />
                      </button>
                    </section>
                  </aside>

                  <section class="settings-theme-preview-main">
                    <section class="view-card calls-view settings-theme-preview-calls-view">
                      <section class="section calls-header settings-theme-preview-calls-header">
                        <div class="calls-header-left">
                          <h1>Video Call Management</h1>
                        </div>
                        <div class="actions">
                          <button class="btn btn-cyan" type="button">New video call</button>
                        </div>
                      </section>

                      <section class="toolbar calls-toolbar settings-theme-preview-calls-toolbar">
                        <div class="calls-toolbar-left">
                          <div class="calls-view-tabs" role="tablist" aria-label="Calls view mode preview">
                            <button
                              class="tab"
                              :class="{ active: settingsThemePreview.viewMode === 'calls' }"
                              type="button"
                              role="tab"
                              :aria-selected="settingsThemePreview.viewMode === 'calls'"
                              @click="setSettingsThemePreviewViewMode('calls')"
                            >
                              Calls
                            </button>
                            <button
                              class="tab"
                              :class="{ active: settingsThemePreview.viewMode === 'calendar' }"
                              type="button"
                              role="tab"
                              :aria-selected="settingsThemePreview.viewMode === 'calendar'"
                              @click="setSettingsThemePreviewViewMode('calendar')"
                            >
                              Calender
                            </button>
                          </div>
                        </div>

                        <div class="calls-toolbar-right">
                          <label class="calls-search calls-search-main" aria-label="Preview call search">
                            <input
                              v-model="settingsThemePreview.query"
                              class="input"
                              type="search"
                              placeholder="Search call title"
                              @keydown.enter.prevent="applySettingsThemePreviewFilters"
                            />
                          </label>

                          <AppSelect v-model="settingsThemePreview.status" @change="applySettingsThemePreviewFilters">
                            <option value="all">All status</option>
                            <option value="scheduled">Scheduled</option>
                            <option value="active">Active</option>
                            <option value="ended">Ended</option>
                            <option value="cancelled">Cancelled</option>
                          </AppSelect>

                          <AppSelect v-model="settingsThemePreview.scope" @change="applySettingsThemePreviewFilters">
                            <option value="all">All scope</option>
                            <option value="my">My scope</option>
                          </AppSelect>

                          <button
                            class="icon-mini-btn calls-toolbar-search-btn"
                            type="button"
                            title="Search calls"
                            aria-label="Search calls"
                            @click="applySettingsThemePreviewFilters"
                          >
                            <img src="/assets/orgas/kingrt/icons/send.png" alt="" />
                          </button>
                        </div>
                      </section>

                      <section v-if="settingsThemePreview.viewMode === 'calls'" class="table-wrap calls-table-wrap">
                        <table class="calls-list-table">
                          <thead>
                            <tr>
                              <th class="col-title">Call</th>
                              <th>Status</th>
                              <th>Window</th>
                              <th>Participants</th>
                              <th>Owner</th>
                              <th class="col-actions">Actions</th>
                            </tr>
                          </thead>
                          <tbody v-if="settingsThemePreviewRows.length > 0">
                            <tr v-for="call in settingsThemePreviewRows" :key="call.id">
                              <td data-label="Call">
                                <div class="call-title">{{ call.title }}</div>
                                <div class="call-subline code">{{ call.id }}</div>
                              </td>
                              <td data-label="Status">
                                <span class="tag" :class="settingsThemePreviewStatusTagClass(call.status)">
                                  {{ call.status }}
                                </span>
                              </td>
                              <td data-label="Window">{{ call.window }}</td>
                              <td data-label="Participants">
                                {{ call.participants.total }}
                                <span class="call-subline">
                                  in {{ call.participants.internal }} / ex {{ call.participants.external }}
                                </span>
                              </td>
                              <td data-label="Owner">
                                {{ call.owner.displayName }}
                                <span class="call-subline">{{ call.owner.email }}</span>
                              </td>
                              <td data-label="Actions">
                                <div class="actions-inline">
                                  <button class="icon-mini-btn" type="button" title="Edit call" aria-label="Edit call">
                                    <img src="/assets/orgas/kingrt/icons/gear.png" alt="" />
                                  </button>
                                  <button class="icon-mini-btn" type="button" title="Enter video call" aria-label="Enter video call">
                                    <img src="/assets/orgas/kingrt/icons/add_to_call.png" alt="" />
                                  </button>
                                  <button class="icon-mini-btn danger" type="button" title="Cancel call" aria-label="Cancel call">
                                    <img src="/assets/orgas/kingrt/icons/end_call.png" alt="" />
                                  </button>
                                  <button class="icon-mini-btn danger" type="button" title="Delete call" aria-label="Delete call">
                                    <img src="/assets/orgas/kingrt/icons/remove_user.png" alt="" />
                                  </button>
                                </div>
                              </td>
                            </tr>
                          </tbody>
                        </table>

                        <section v-if="settingsThemePreviewRows.length === 0" class="section calls-empty">
                          No calls match the active filters.
                        </section>
                      </section>

                      <section v-else class="table-wrap calls-calendar-wrap">
                        <section class="calls-calendar-full settings-theme-preview-calendar-mock">
                          <div class="settings-theme-preview-calendar-grid">
                            <span>Mon</span>
                            <span>Tue</span>
                            <span>Wed</span>
                            <span>Thu</span>
                            <span>Fri</span>
                            <span>Sat</span>
                            <span>Sun</span>
                          </div>
                          <div class="settings-theme-preview-calendar-event">Platform Standup · 09:30</div>
                        </section>
                      </section>

                      <section v-if="settingsThemePreview.viewMode === 'calls'" class="footer calls-pagination-wrap">
                        <div class="pagination">
                          <button
                            class="pager-btn pager-icon-btn"
                            type="button"
                            :disabled="settingsThemePreview.page <= 1"
                            @click="goToSettingsThemePreviewPage(settingsThemePreview.page - 1)"
                          >
                            <img class="pager-icon-img" src="/assets/orgas/kingrt/icons/backward.png" alt="Previous" />
                          </button>
                          <div class="page-info">
                            Page {{ settingsThemePreview.page }} / {{ settingsThemePreviewPageCount }}
                          </div>
                          <button
                            class="pager-btn pager-icon-btn"
                            type="button"
                            :disabled="settingsThemePreview.page >= settingsThemePreviewPageCount"
                            @click="goToSettingsThemePreviewPage(settingsThemePreview.page + 1)"
                          >
                            <img class="pager-icon-img" src="/assets/orgas/kingrt/icons/forward.png" alt="Next" />
                          </button>
                        </div>
                      </section>
                    </section>
                  </section>
                </div>
              </div>
            </div>
          </section>
        </div>
      </section>

      <section v-else-if="activeSettingsTile === 'credentials-email'" class="settings-panel">
        <div class="settings-row">
          <label class="settings-field">
            <span>Primary email</span>
            <div class="settings-readonly-value">{{ sessionState.email || '—' }}</div>
          </label>
          <label class="settings-field">
            <span>Password</span>
            <input class="input" type="password" value="********" disabled autocomplete="off" />
          </label>
        </div>
      </section>

      <section v-else-if="activeSettingsTile === 'regional'" class="settings-panel">
        <section class="settings-section">
          <h4>Regional Time</h4>
          <p>Select how date and time should be displayed across the workspace.</p>
          <div class="settings-row">
            <label class="settings-field">
              <span>Time format</span>
              <AppSelect v-model="settingsDraft.timeFormat">
                <option value="24h">24h</option>
                <option value="12h">12h</option>
              </AppSelect>
            </label>
            <label class="settings-field">
              <span>Date display</span>
              <AppSelect v-model="settingsDraft.dateFormat">
                <option v-for="option in dateFormatOptions" :key="option.value" :value="option.value">
                  {{ option.label }}
                </option>
              </AppSelect>
            </label>
          </div>
        </section>
      </section>

      <section v-else-if="activeSettingsTile === 'notifications'" class="settings-panel">
        <div class="settings-upload-status">Notifications settings coming soon.</div>
      </section>

      <section v-else-if="activeSettingsTile === 'apps'" class="settings-panel">
        <div class="settings-upload-status">Apps settings coming soon.</div>
      </section>

      <section v-else class="settings-panel">
        <div class="settings-upload-status">Select a settings tab.</div>
      </section>

      <div class="settings-actions">
        <button class="btn" type="button" :disabled="settingsState.saving || settingsState.loading" @click="saveSettings">
          {{ settingsState.saving ? 'Saving…' : 'Save settings' }}
        </button>
      </div>

      <div class="settings-upload-status">{{ settingsState.message }}</div>
    </div>
  </div>

  <div
    class="call-owner-edit-modal"
    :hidden="!callOwnerEditState.open"
    role="dialog"
    aria-modal="true"
    aria-label="Edit call from workspace"
  >
    <div class="call-owner-edit-backdrop" @click="closeInCallEditModal"></div>
    <div class="call-owner-edit-dialog">
      <header class="call-owner-edit-header">
        <div class="call-owner-edit-title-wrap">
          <img class="call-owner-edit-logo" src="/assets/orgas/kingrt/logo.svg" alt="" />
          <h4>Edit video call</h4>
        </div>
        <button class="icon-mini-btn" type="button" aria-label="Close edit call modal" @click="closeInCallEditModal">
          <img src="/assets/orgas/kingrt/icons/cancel.png" alt="" />
        </button>
      </header>

      <div class="call-owner-edit-body">
        <section class="call-owner-edit-grid">
          <label class="field">
            <span>Title</span>
            <input
              v-model.trim="callOwnerEditState.title"
              class="input"
              type="text"
              placeholder="Weekly Product Sync"
            />
          </label>
          <label class="field">
            <span>Access mode</span>
            <AppSelect v-model="callOwnerEditState.accessMode" aria-label="Call access mode">
              <option value="invite_only">Invite only</option>
              <option value="free_for_all">Free for all</option>
            </AppSelect>
          </label>
          <label class="field">
            <span>Room ID</span>
            <input v-model.trim="callOwnerEditState.roomId" class="input" type="text" placeholder="lobby" />
          </label>
          <label class="field">
            <span>Starts at</span>
            <input
              v-model="callOwnerEditState.startsLocal"
              class="input"
              type="datetime-local"
              aria-label="Call starts at"
            />
          </label>
          <label class="field">
            <span>Ends at</span>
            <input
              v-model="callOwnerEditState.endsLocal"
              class="input"
              type="datetime-local"
              aria-label="Call ends at"
            />
          </label>
        </section>

        <section class="call-owner-edit-toggle">
          <label class="call-owner-edit-checkbox">
            <input v-model="callOwnerEditState.replaceParticipants" type="checkbox" />
            <span>Edit participant list</span>
          </label>
        </section>

        <section v-if="callOwnerEditState.replaceParticipants" class="call-owner-participants-grid">
          <article v-if="canLoadCallOwnerInternalDirectory" class="call-owner-participants-panel">
            <header class="call-owner-participants-head">
              <h5>Registered users</h5>
              <label class="call-owner-search" aria-label="Participant search">
                <input
                  v-model.trim="callOwnerParticipants.query"
                  class="input"
                  type="search"
                  placeholder="Search users"
                  @keydown.enter.prevent="applyCallOwnerParticipantSearch"
                />
                <button class="btn" type="button" :disabled="callOwnerParticipants.loading" @click="applyCallOwnerParticipantSearch">
                  Search
                </button>
              </label>
            </header>

            <section v-if="callOwnerParticipants.error" class="call-owner-inline-error">
              {{ callOwnerParticipants.error }}
            </section>

            <section class="call-owner-participants-list" :class="{ loading: callOwnerParticipants.loading }">
              <label
                v-for="user in callOwnerParticipants.rows"
                :key="user.id"
                class="call-owner-participant-row"
              >
                <input
                  type="checkbox"
                  :checked="isCallOwnerUserSelected(user.id)"
                  @change="toggleCallOwnerUserSelection(user.id)"
                />
                <span class="call-owner-participant-main">{{ user.display_name || user.email }}</span>
                <span class="call-owner-participant-meta">{{ user.email }} · {{ user.role }}</span>
              </label>
              <p v-if="!callOwnerParticipants.loading && callOwnerParticipants.rows.length === 0" class="call-owner-empty-inline">
                No users in this page.
              </p>
            </section>

            <div class="pagination">
              <button
                class="pager-btn pager-icon-btn"
                type="button"
                :disabled="!callOwnerParticipants.hasPrev || callOwnerParticipants.loading"
                @click="goToCallOwnerParticipantPage(callOwnerParticipants.page - 1)"
              >
                <img class="pager-icon-img" src="/assets/orgas/kingrt/icons/backward.png" alt="Previous" />
              </button>
              <div class="page-info">Page {{ callOwnerParticipants.page }} / {{ callOwnerParticipants.pageCount }}</div>
              <button
                class="pager-btn pager-icon-btn"
                type="button"
                :disabled="!callOwnerParticipants.hasNext || callOwnerParticipants.loading"
                @click="goToCallOwnerParticipantPage(callOwnerParticipants.page + 1)"
              >
                <img class="pager-icon-img" src="/assets/orgas/kingrt/icons/forward.png" alt="Next" />
              </button>
            </div>
          </article>

          <article v-else class="call-owner-participants-panel">
            <p class="call-owner-inline-hint">
              Internal participants stay unchanged in this editor for non-admin owners.
            </p>
          </article>

          <article class="call-owner-participants-panel">
            <header class="call-owner-participants-head">
              <h5>External participants</h5>
              <button class="btn" type="button" @click="addCallOwnerExternalRow">Add row</button>
            </header>

            <section class="call-owner-external-list">
              <div v-for="(row, index) in callOwnerExternalRows" :key="row.id" class="call-owner-external-row">
                <input
                  v-model.trim="row.display_name"
                  class="input"
                  type="text"
                  placeholder="Display name"
                  :aria-label="`External participant ${index + 1} display name`"
                />
                <input
                  v-model.trim="row.email"
                  class="input"
                  type="email"
                  placeholder="guest@example.com"
                  :aria-label="`External participant ${index + 1} email`"
                />
                <button
                  class="icon-mini-btn danger"
                  type="button"
                  title="Remove external participant"
                  :aria-label="`Remove external participant row ${index + 1}`"
                  @click="removeCallOwnerExternalRow(index)"
                >
                  <img src="/assets/orgas/kingrt/icons/remove_user.png" alt="" />
                </button>
              </div>
            </section>
          </article>
        </section>

        <section v-if="callOwnerEditState.error" class="call-owner-inline-error">
          {{ callOwnerEditState.error }}
        </section>
      </div>

      <footer class="call-owner-edit-footer">
        <button class="btn" type="button" :disabled="callOwnerEditState.submitting" @click="closeInCallEditModal">
          Close
        </button>
        <button class="btn" type="button" :disabled="callOwnerEditState.submitting" @click="submitInCallEditModal">
          {{ callOwnerEditState.submitting ? 'Saving…' : 'Save changes' }}
        </button>
      </footer>
    </div>
  </div>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, provide, reactive, ref, watch } from 'vue';
import { RouterLink, RouterView, useRoute, useRouter } from 'vue-router';
import AppSelect from '../components/AppSelect.vue';
import {
  logoutSession,
  saveSessionSettings,
  sessionState,
  uploadSessionAvatar,
} from '../domain/auth/session';
import { DATE_FORMAT_OPTIONS, normalizeDateFormat, normalizeTimeFormat } from '../support/dateTimeFormat';
import { currentBackendOrigin, fetchBackend } from '../support/backendFetch';
import {
  attachCallMediaDeviceWatcher,
  callMediaPrefs,
  setCallBackgroundBackdropMode,
  setCallBackgroundBlurStrength,
  setCallBackgroundFilterMode,
  setCallBackgroundQualityProfile,
  setCallCameraDevice,
  setCallMicrophoneDevice,
  setCallMicrophoneVolume,
  setCallBackgroundApplyOutgoing,
  setCallSpeakerDevice,
  setCallSpeakerVolume,
} from '../domain/realtime/callMediaPreferences';

const router = useRouter();
const route = useRoute();
const leftSidebarCollapsed = ref(false);
const isTabletSidebarOpen = ref(false);
const isMobileSidebarOpen = ref(false);
const viewportMode = ref('desktop');
let laptopMedia = null;
let tabletMedia = null;
let mobileMedia = null;
let detachCallMediaWatcher = null;
const placeholderAvatar = '/assets/orgas/kingrt/avatar-placeholder.svg';
const LAPTOP_BREAKPOINT = 1440;
const TABLET_BREAKPOINT = 1180;
const MOBILE_BREAKPOINT = 760;
const SETTINGS_LANGUAGE_STORAGE_KEY = 'ii_videocall_v1_workspace_language';
const SETTINGS_THEME_COLORS_STORAGE_KEY = 'ii_videocall_v1_theme_colors';
const SUPPORTED_SETTINGS_LANGUAGES = ['en', 'de', 'fr', 'es'];
const themeColorFields = Object.freeze([
  { key: '--bg-shell', label: 'Shell background', default: '#0b1324' },
  { key: '--bg-pane', label: 'Pane background', default: '#182c4d' },
  { key: '--brand-bg', label: 'Brand strip', default: '#0b1324' },
  { key: '--bg-surface', label: 'Surface', default: '#003c93' },
  { key: '--bg-surface-strong', label: 'Surface strong', default: '#0c1c33' },
  { key: '--bg-input', label: 'Input background', default: '#d8dadd' },
  { key: '--bg-action', label: 'Action', default: '#0b1324' },
  { key: '--bg-action-hover', label: 'Action hover', default: '#5696ef' },
  { key: '--bg-row', label: 'Row', default: '#2a569f' },
  { key: '--bg-row-hover', label: 'Row hover', default: '#163260' },
  { key: '--line', label: 'Line', default: '#09111e' },
  { key: '--text-main', label: 'Text main', default: '#edf3ff' },
  { key: '--text-muted', label: 'Text muted', default: '#8490a1' },
  { key: '--ok', label: 'OK', default: '#177f22' },
  { key: '--wait', label: 'Wait', default: '#8d9500' },
  { key: '--danger', label: 'Danger', default: '#ff0000' },
  { key: '--bg-sidebar', label: 'Sidebar', default: '#0b1324' },
  { key: '--bg-main', label: 'Main', default: '#0b1324' },
  { key: '--bg-tab', label: 'Tab', default: '#003c93' },
  { key: '--bg-tab-hover', label: 'Tab hover', default: '#5696ef' },
  { key: '--bg-tab-active', label: 'Tab active', default: '#2a569f' },
  { key: '--bg-ui-chrome', label: 'UI chrome', default: '#3d5f98' },
  { key: '--bg-ui-chrome-active', label: 'UI chrome active', default: '#2a569f' },
  { key: '--bg-icon', label: 'Icon background', default: '#ffffff' },
  { key: '--bg-icon-active', label: 'Icon active', default: '#5696ef' },
  { key: '--border-subtle', label: 'Border subtle', default: '#09111e' },
  { key: '--text-primary', label: 'Text primary', default: '#edf3ff' },
  { key: '--text-secondary', label: 'Text secondary', default: '#c6d4eb' },
  { key: '--text-dim', label: 'Text dim', default: '#5e6d86' },
  { key: '--warn', label: 'Warn', default: '#4d5011' },
  { key: '--brand-cyan', label: 'Brand cyan', default: '#1482be' },
  { key: '--brand-cyan-hover', label: 'Brand cyan hover', default: '#1a96d8' },
  { key: '--brand-cyan-active', label: 'Brand cyan active', default: '#0f6ea8' },
]);
const themeColorDefaultMap = Object.freeze(themeColorFields.reduce((accumulator, field) => ({
  ...accumulator,
  [field.key]: field.default,
}), {}));

function normalizeHexColor(value, fallback = '#000000') {
  const normalized = String(value || '').trim().toLowerCase();
  if (/^#[a-f0-9]{6}$/.test(normalized)) return normalized;
  if (/^[a-f0-9]{6}$/.test(normalized)) return `#${normalized}`;
  if (/^#[a-f0-9]{3}$/.test(normalized)) {
    return `#${normalized[1]}${normalized[1]}${normalized[2]}${normalized[2]}${normalized[3]}${normalized[3]}`;
  }
  if (/^[a-f0-9]{3}$/.test(normalized)) {
    return `#${normalized[0]}${normalized[0]}${normalized[1]}${normalized[1]}${normalized[2]}${normalized[2]}`;
  }
  return normalizeHexColor(fallback, '#000000');
}

function mergeThemeColorMap(source) {
  const merged = {};
  const payload = source && typeof source === 'object' ? source : {};
  for (const field of themeColorFields) {
    merged[field.key] = normalizeHexColor(payload[field.key], themeColorDefaultMap[field.key]);
  }
  // Keep UI chrome on the approved palette color.
  merged['--bg-ui-chrome'] = '#3d5f98';
  return merged;
}

function applyThemeColorMap(source) {
  if (typeof document === 'undefined') return;
  const merged = mergeThemeColorMap(source);
  for (const field of themeColorFields) {
    document.documentElement.style.setProperty(field.key, merged[field.key]);
  }
}

function readStoredThemeColorMap() {
  if (typeof localStorage === 'undefined') return mergeThemeColorMap(null);
  const raw = localStorage.getItem(SETTINGS_THEME_COLORS_STORAGE_KEY);
  if (!raw) return mergeThemeColorMap(null);
  try {
    const parsed = JSON.parse(raw);
    return mergeThemeColorMap(parsed);
  } catch {
    return mergeThemeColorMap(null);
  }
}

function storeThemeColorMap(source) {
  if (typeof localStorage === 'undefined') return;
  const merged = mergeThemeColorMap(source);
  localStorage.setItem(SETTINGS_THEME_COLORS_STORAGE_KEY, JSON.stringify(merged));
}

function patchThemeColorMap(target, source) {
  const merged = mergeThemeColorMap(source);
  for (const field of themeColorFields) {
    target[field.key] = merged[field.key];
  }
}

const persistedThemeColors = reactive(readStoredThemeColorMap());

const navItems = computed(() => {
  const role = sessionState.role;
  const items = [
    { to: '/admin/overview', label: 'Overview', icon: '/assets/orgas/kingrt/icons/users.png', roles: ['admin'] },
    { to: '/admin/users', label: 'User Management', icon: '/assets/orgas/kingrt/icons/user.png', roles: ['admin'] },
    { to: '/admin/calls', label: 'Video Calls', icon: '/assets/orgas/kingrt/icons/lobby.png', roles: ['admin'] },
    { to: '/user/dashboard', label: 'My Calls', icon: '/assets/orgas/kingrt/icons/lobby.png', roles: ['user'] },
  ];

  return items.filter((item) => role && item.roles.includes(role));
});

const pageTitle = computed(() => {
  const mapping = {
    '/admin/overview': 'Video Operations',
    '/admin/users': 'User Management',
    '/admin/calls': 'Video Call Management',
    '/user/dashboard': 'My Video Calls',
  };

  if (route.path.startsWith('/workspace/call')) return 'Video Call';
  return mapping[route.path] || 'Workspace';
});
const isCallWorkspace = computed(() => route.path.startsWith('/workspace/call'));

const pageSubtitle = computed(() => {
  return '';
});
const showWorkspaceHeader = computed(() => (
  !['/admin/users', '/admin/calls'].includes(route.path)
  && !isCallWorkspace.value
));

const isTabletViewport = computed(() => viewportMode.value === 'tablet');
const isMobileViewport = computed(() => viewportMode.value === 'mobile');
const isLaptopViewport = computed(() => viewportMode.value === 'laptop');
const isDesktopViewport = computed(() => viewportMode.value === 'desktop');
const isDesktopLikeViewport = computed(() => isDesktopViewport.value || isLaptopViewport.value);
const showMobileShellHeader = computed(() => isMobileViewport.value && !isCallWorkspace.value);

const profileAvatarSrc = computed(() => sessionState.avatarPath || placeholderAvatar);
const sidebarExpanded = computed(() => {
  if (isTabletViewport.value) return isTabletSidebarOpen.value;
  if (isMobileViewport.value) return isMobileSidebarOpen.value;
  return !leftSidebarCollapsed.value;
});
const leftSidebarToggleIcon = computed(() => (
  !sidebarExpanded.value
    ? '/assets/orgas/kingrt/icons/forward.png'
    : '/assets/orgas/kingrt/icons/backward.png'
));
const leftSidebarToggleLabel = computed(() => (
  !sidebarExpanded.value ? 'Open sidebar' : 'Hide sidebar'
));
const shellClasses = computed(() => ({
  'left-collapsed': (isDesktopLikeViewport.value && leftSidebarCollapsed.value) || (isMobileViewport.value && !isMobileSidebarOpen.value),
  'laptop-mode': isLaptopViewport.value,
  'tablet-mode': isTabletViewport.value,
  'tablet-left-open': isTabletViewport.value && isTabletSidebarOpen.value,
  'mobile-mode': isMobileViewport.value,
  'mobile-left-open': isMobileViewport.value && isMobileSidebarOpen.value,
  'call-workspace-mode': isCallWorkspace.value,
}));
const leftSidebarClasses = computed(() => ({
  collapsed: (isDesktopLikeViewport.value && leftSidebarCollapsed.value) || (isMobileViewport.value && !isMobileSidebarOpen.value),
}));
const micLevelPercent = ref(0);
let micLevelStream = null;
let micLevelAudioContext = null;
let micLevelSource = null;
let micLevelAnalyser = null;
let micLevelData = null;
let micLevelFrame = 0;
let micLevelMonitorToken = 0;

const settingsDraft = reactive({
  displayName: '',
  theme: 'dark',
  timeFormat: '24h',
  dateFormat: 'dmy_dot',
  language: 'en',
  avatarDataUrl: '',
  themeColors: mergeThemeColorMap(persistedThemeColors),
});

const settingsState = reactive({
  open: false,
  loading: false,
  saving: false,
  dragging: false,
  message: '',
  avatarStatus: '',
});
const activeSettingsTile = ref('about-me');
const settingsTiles = computed(() => ([
  { id: 'about-me', label: 'About Me' },
  { id: 'credentials-email', label: 'Credentials + Email' },
  { id: 'regional', label: 'Regional' },
  { id: 'theme', label: 'Theme' },
  { id: 'notifications', label: 'Notifications' },
  { id: 'apps', label: 'Apps' },
]));
const dateFormatOptions = DATE_FORMAT_OPTIONS;
const SETTINGS_THEME_PREVIEW_PAGE_SIZE = 4;
const settingsThemePreviewCalls = Object.freeze([
  {
    id: '9fbe5c05-8440-4d2e-8a3c-9900ca346c55',
    title: 'Platform Standup',
    status: 'active',
    window: '16 Apr 09:30 - 10:00',
    scope: 'my',
    participants: { total: 3, internal: 2, external: 1 },
    owner: { displayName: 'Platform Admin', email: 'admin@intelligent-intern.com' },
  },
  {
    id: '47632c72-ab39-49b6-ba58-05e3e6483fb8',
    title: 'Quarterly Review',
    status: 'scheduled',
    window: '17 Apr 14:00 - 14:45',
    scope: 'all',
    participants: { total: 6, internal: 4, external: 2 },
    owner: { displayName: 'Call Moderator', email: 'moderator@intelligent-intern.com' },
  },
  {
    id: '8d2c1cd4-d5af-45d0-8f8f-7d4ef3044b29',
    title: 'Customer Escalation',
    status: 'ended',
    window: '15 Apr 17:00 - 17:35',
    scope: 'all',
    participants: { total: 5, internal: 3, external: 2 },
    owner: { displayName: 'Platform Admin', email: 'admin@intelligent-intern.com' },
  },
  {
    id: '1ca5664f-2e32-4c63-9d85-2f6ca2418c11',
    title: 'Partner Kickoff',
    status: 'cancelled',
    window: '18 Apr 11:00 - 11:30',
    scope: 'my',
    participants: { total: 2, internal: 1, external: 1 },
    owner: { displayName: 'Platform Admin', email: 'admin@intelligent-intern.com' },
  },
  {
    id: '3bd2717f-e8ba-4e76-88ed-bceb5b8f39db',
    title: 'Hiring Sync',
    status: 'scheduled',
    window: '19 Apr 13:00 - 13:30',
    scope: 'all',
    participants: { total: 4, internal: 4, external: 0 },
    owner: { displayName: 'People Ops', email: 'peopleops@intelligent-intern.com' },
  },
]);
const settingsThemePreview = reactive({
  viewMode: 'calls',
  query: '',
  status: 'all',
  scope: 'all',
  page: 1,
});

const settingsThemePreviewFilteredCalls = computed(() => {
  const query = String(settingsThemePreview.query || '').trim().toLowerCase();
  return settingsThemePreviewCalls.filter((call) => {
    if (settingsThemePreview.status !== 'all' && call.status !== settingsThemePreview.status) {
      return false;
    }
    if (settingsThemePreview.scope !== 'all' && call.scope !== settingsThemePreview.scope) {
      return false;
    }
    if (query === '') {
      return true;
    }
    return call.title.toLowerCase().includes(query);
  });
});

const settingsThemePreviewPageCount = computed(() => {
  return Math.max(1, Math.ceil(settingsThemePreviewFilteredCalls.value.length / SETTINGS_THEME_PREVIEW_PAGE_SIZE));
});

const settingsThemePreviewRows = computed(() => {
  const currentPage = Math.max(1, Math.min(settingsThemePreview.page, settingsThemePreviewPageCount.value));
  const start = (currentPage - 1) * SETTINGS_THEME_PREVIEW_PAGE_SIZE;
  return settingsThemePreviewFilteredCalls.value.slice(start, start + SETTINGS_THEME_PREVIEW_PAGE_SIZE);
});

watch(
  () => settingsThemePreviewFilteredCalls.value.length,
  () => {
    settingsThemePreview.page = Math.max(1, Math.min(settingsThemePreview.page, settingsThemePreviewPageCount.value));
  }
);

function setSettingsThemePreviewViewMode(mode) {
  const normalized = String(mode || '').trim().toLowerCase();
  settingsThemePreview.viewMode = normalized === 'calendar' ? 'calendar' : 'calls';
}

function applySettingsThemePreviewFilters() {
  settingsThemePreview.page = 1;
}

function goToSettingsThemePreviewPage(nextPage) {
  const parsed = Number.parseInt(String(nextPage), 10);
  if (!Number.isInteger(parsed)) return;
  settingsThemePreview.page = Math.max(1, Math.min(parsed, settingsThemePreviewPageCount.value));
}

function settingsThemePreviewStatusTagClass(status) {
  const normalized = String(status || '').trim().toLowerCase();
  if (normalized === 'active') return 'ok';
  if (normalized === 'scheduled') return 'warn';
  if (normalized === 'cancelled') return 'danger';
  return 'warn';
}

const settingsAvatarPreviewSrc = computed(() => settingsDraft.avatarDataUrl || profileAvatarSrc.value);

function normalizeSettingsLanguage(value) {
  const normalized = String(value || '').trim().toLowerCase();
  return SUPPORTED_SETTINGS_LANGUAGES.includes(normalized) ? normalized : 'en';
}

function readStoredSettingsLanguage() {
  if (typeof localStorage === 'undefined') return 'en';
  return normalizeSettingsLanguage(localStorage.getItem(SETTINGS_LANGUAGE_STORAGE_KEY));
}

function storeSettingsLanguage(language) {
  if (typeof localStorage === 'undefined') return;
  localStorage.setItem(SETTINGS_LANGUAGE_STORAGE_KEY, normalizeSettingsLanguage(language));
}

function applySettingsLanguage(language) {
  if (typeof document === 'undefined') return;
  document.documentElement.lang = normalizeSettingsLanguage(language);
}

function updateThemeColor(key, value) {
  const normalizedKey = String(key || '').trim();
  if (normalizedKey === '' || !(normalizedKey in themeColorDefaultMap)) return;
  const fallback = settingsDraft.themeColors[normalizedKey] || themeColorDefaultMap[normalizedKey];
  const next = normalizeHexColor(value, fallback);
  settingsDraft.themeColors[normalizedKey] = next;
  applyThemeColorMap(settingsDraft.themeColors);
}

function resetThemeColorsToDefault() {
  patchThemeColorMap(settingsDraft.themeColors, themeColorDefaultMap);
  applyThemeColorMap(settingsDraft.themeColors);
}

function normalizeRole(value) {
  const role = String(value || '').trim().toLowerCase();
  if (role === 'admin') return 'admin';
  return 'user';
}

function normalizeCallAccessMode(value) {
  const normalized = String(value || '').trim().toLowerCase();
  return normalized === 'free_for_all' ? 'free_for_all' : 'invite_only';
}

function requestHeaders(withBody = false) {
  const headers = { accept: 'application/json' };
  if (withBody) headers['content-type'] = 'application/json';

  const token = String(sessionState.sessionToken || '').trim();
  if (token !== '') {
    headers.authorization = `Bearer ${token}`;
  }
  return headers;
}

function extractErrorMessage(payload, fallback) {
  if (payload && typeof payload === 'object') {
    const message = payload?.error?.message;
    if (typeof message === 'string' && message.trim() !== '') {
      return message.trim();
    }
  }
  return fallback;
}

function buildApiRequestError(payload, fallbackMessage, responseStatus = 0) {
  const error = new Error(extractErrorMessage(payload, fallbackMessage));
  error.responseStatus = Number(responseStatus) || 0;
  error.responseCode = String(payload?.error?.code || '').trim().toLowerCase();
  return error;
}

async function apiRequest(path, { method = 'GET', query = null, body = null } = {}) {
  let response = null;
  try {
    const result = await fetchBackend(path, {
      method,
      query,
      headers: requestHeaders(body !== null),
      body: body === null ? undefined : JSON.stringify(body),
    });
    response = result.response;
  } catch (error) {
    const message = error instanceof Error ? error.message.trim() : '';
    if (message === '' || /failed to fetch|socket|connection/i.test(message)) {
      throw new Error(`Could not reach backend (${currentBackendOrigin()}).`);
    }
    throw new Error(message);
  }

  let payload = null;
  try {
    payload = await response.json();
  } catch {
    payload = null;
  }

  if (!response.ok) {
    throw buildApiRequestError(payload, `Request failed (${response.status}).`, response.status);
  }

  if (!payload || payload.status !== 'ok') {
    throw new Error('Backend returned an invalid payload.');
  }

  return payload;
}

function isUuidLike(value) {
  const normalized = String(value || '').trim().toLowerCase();
  return /^[a-f0-9]{8}-[a-f0-9]{4}-[1-5][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/.test(normalized);
}

function isoToLocalInput(isoValue) {
  if (typeof isoValue !== 'string' || isoValue.trim() === '') return '';
  const date = new Date(isoValue);
  if (Number.isNaN(date.getTime())) return '';

  const year = date.getFullYear();
  const month = `${date.getMonth() + 1}`.padStart(2, '0');
  const day = `${date.getDate()}`.padStart(2, '0');
  const hours = `${date.getHours()}`.padStart(2, '0');
  const minutes = `${date.getMinutes()}`.padStart(2, '0');
  return `${year}-${month}-${day}T${hours}:${minutes}`;
}

function localInputToIso(localValue) {
  const text = String(localValue || '').trim();
  if (text === '') return '';
  const parsed = new Date(text);
  if (Number.isNaN(parsed.getTime())) return '';
  return parsed.toISOString();
}

const callOwnerEditState = reactive({
  visible: false,
  loadingContext: false,
  contextError: '',
  open: false,
  submitting: false,
  error: '',
  callId: '',
  title: '',
  accessMode: 'invite_only',
  roomId: 'lobby',
  startsLocal: '',
  endsLocal: '',
  replaceParticipants: false,
});

const callOwnerParticipants = reactive({
  loading: false,
  error: '',
  query: '',
  page: 1,
  pageSize: 10,
  pageCount: 1,
  hasPrev: false,
  hasNext: false,
  rows: [],
});

const callOwnerSelectedUserIds = ref([]);
const callOwnerExistingInternalUserIds = ref([]);
const callOwnerExternalRows = ref([]);
let callOwnerExternalRowId = 0;
let callOwnerContextSeq = 0;

const showInCallOwnerEditCard = computed(() => isCallWorkspace.value && callOwnerEditState.visible);
const canLoadCallOwnerInternalDirectory = computed(() => normalizeRole(sessionState.role) === 'admin');

function isNavItemActive(item) {
  if (item.to.startsWith('/workspace/call')) {
    return route.path.startsWith('/workspace/call');
  }

  return route.path === item.to;
}

function syncViewportState() {
  if (!laptopMedia || !tabletMedia || !mobileMedia) return;

  if (mobileMedia.matches) {
    viewportMode.value = 'mobile';
    leftSidebarCollapsed.value = false;
    isTabletSidebarOpen.value = false;
    isMobileSidebarOpen.value = false;
    return;
  }

  if (tabletMedia.matches) {
    viewportMode.value = 'tablet';
    leftSidebarCollapsed.value = false;
    isTabletSidebarOpen.value = false;
    isMobileSidebarOpen.value = false;
    return;
  }

  if (laptopMedia.matches) {
    viewportMode.value = 'laptop';
    isTabletSidebarOpen.value = false;
    isMobileSidebarOpen.value = false;
    return;
  }

  viewportMode.value = 'desktop';
  isTabletSidebarOpen.value = false;
  isMobileSidebarOpen.value = false;
}

function syncMobileScrollLock(forceUnlock = false) {
  if (typeof document === 'undefined') return;
  const lockScroll = !forceUnlock && isMobileViewport.value && isMobileSidebarOpen.value;
  document.documentElement.style.overflow = lockScroll ? 'hidden' : '';
  document.body.style.overflow = lockScroll ? 'hidden' : '';
}

function handleViewportChange() {
  syncViewportState();
}

function handleLeftSidebarToggle() {
  if (isTabletViewport.value) {
    isTabletSidebarOpen.value = !isTabletSidebarOpen.value;
    return;
  }

  if (isMobileViewport.value) {
    isMobileSidebarOpen.value = !isMobileSidebarOpen.value;
    return;
  }

  leftSidebarCollapsed.value = true;
}

function showLeftSidebar() {
  if (isTabletViewport.value) {
    isTabletSidebarOpen.value = true;
    return;
  }

  if (isMobileViewport.value) {
    isMobileSidebarOpen.value = true;
    return;
  }

  leftSidebarCollapsed.value = false;
}

function handleMainClick() {
  if (isTabletViewport.value && isTabletSidebarOpen.value) {
    isTabletSidebarOpen.value = false;
    return;
  }

  if (isMobileViewport.value && isMobileSidebarOpen.value) {
    isMobileSidebarOpen.value = false;
  }
}

function handleNavItemClick() {
  if (isTabletViewport.value && isTabletSidebarOpen.value) {
    isTabletSidebarOpen.value = false;
  }

  if (isMobileViewport.value && isMobileSidebarOpen.value) {
    isMobileSidebarOpen.value = false;
  }
}

function isBackgroundPresetActive(preset) {
  const mode = String(callMediaPrefs.backgroundFilterMode || 'off').trim().toLowerCase();
  const applyOutgoing = Boolean(callMediaPrefs.backgroundApplyOutgoing);
  const backdrop = String(callMediaPrefs.backgroundBackdropMode || 'blur7').trim().toLowerCase();

  if (preset === 'off') {
    return mode !== 'blur' || !applyOutgoing;
  }
  if (preset === 'light') {
    return mode === 'blur' && applyOutgoing && backdrop === 'blur7';
  }
  if (preset === 'strong') {
    return mode === 'blur' && applyOutgoing && backdrop === 'blur9';
  }
  return false;
}

function applyBackgroundPreset(preset) {
  if (preset !== 'light' && preset !== 'strong') {
    setCallBackgroundFilterMode('off');
    setCallBackgroundApplyOutgoing(false);
    return;
  }

  if (isBackgroundPresetActive(preset)) {
    setCallBackgroundFilterMode('off');
    setCallBackgroundApplyOutgoing(false);
    return;
  }

  setCallBackgroundFilterMode('blur');
  setCallBackgroundApplyOutgoing(true);

  if (preset === 'strong') {
    setCallBackgroundBackdropMode('blur9');
    setCallBackgroundQualityProfile('quality');
    setCallBackgroundBlurStrength(4);
    return;
  }

  setCallBackgroundBackdropMode('blur7');
  setCallBackgroundQualityProfile('balanced');
  setCallBackgroundBlurStrength(2);
}

function extractCallFromPayload(payload) {
  if (!payload || typeof payload !== 'object') return null;
  if (payload.call && typeof payload.call === 'object') {
    return payload.call;
  }

  const result = payload.result;
  if (result && typeof result === 'object' && result.call && typeof result.call === 'object') {
    return result.call;
  }

  return null;
}

async function fetchCallById(callId) {
  const normalizedCallId = String(callId || '').trim();
  if (normalizedCallId === '') {
    throw new Error('Missing call id.');
  }
  const payload = await apiRequest(`/api/calls/${encodeURIComponent(normalizedCallId)}`);
  const call = extractCallFromPayload(payload);
  if (!call || typeof call !== 'object') {
    throw new Error('Call payload is invalid.');
  }
  return call;
}

async function resolveEditableCallFromRouteRef(callRef) {
  const normalized = String(callRef || '').trim();
  if (normalized === '') {
    throw new Error('Missing call reference.');
  }

  if (isUuidLike(normalized)) {
    try {
      return await fetchCallById(normalized);
    } catch (directError) {
      const directStatus = Number(directError?.responseStatus || 0);
      if (directStatus !== 404) {
        throw directError;
      }

      const accessPayload = await apiRequest(`/api/call-access/${encodeURIComponent(normalized)}`);
      const accessCall = extractCallFromPayload(accessPayload);
      if (accessCall && typeof accessCall === 'object') {
        return accessCall;
      }
      throw new Error('Call payload is invalid.');
    }
  }

  return fetchCallById(normalized);
}

function nextCallOwnerExternalRow(seed = null) {
  callOwnerExternalRowId += 1;
  const source = seed && typeof seed === 'object' ? seed : {};
  return {
    id: callOwnerExternalRowId,
    display_name: String(source.display_name || '').trim(),
    email: String(source.email || '').trim().toLowerCase(),
  };
}

function resetCallOwnerParticipantsState() {
  callOwnerParticipants.loading = false;
  callOwnerParticipants.error = '';
  callOwnerParticipants.query = '';
  callOwnerParticipants.page = 1;
  callOwnerParticipants.pageCount = 1;
  callOwnerParticipants.hasPrev = false;
  callOwnerParticipants.hasNext = false;
  callOwnerParticipants.rows = [];
  callOwnerSelectedUserIds.value = [];
  callOwnerExistingInternalUserIds.value = [];
  callOwnerExternalRows.value = [];
}

function hydrateCallOwnerDraftFromCall(call) {
  const normalizedCall = call && typeof call === 'object' ? call : {};

  callOwnerEditState.callId = String(normalizedCall.id || '').trim();
  callOwnerEditState.title = String(normalizedCall.title || '').trim();
  callOwnerEditState.roomId = String(normalizedCall.room_id || 'lobby').trim() || 'lobby';
  callOwnerEditState.accessMode = normalizeCallAccessMode(normalizedCall.access_mode);
  callOwnerEditState.startsLocal = isoToLocalInput(String(normalizedCall.starts_at || ''));
  callOwnerEditState.endsLocal = isoToLocalInput(String(normalizedCall.ends_at || ''));
  callOwnerEditState.error = '';
  callOwnerEditState.replaceParticipants = false;

  const ownerUserId = Number(normalizedCall?.owner?.user_id || 0);
  const internalRows = Array.isArray(normalizedCall?.participants?.internal)
    ? normalizedCall.participants.internal
    : [];
  const externalRows = Array.isArray(normalizedCall?.participants?.external)
    ? normalizedCall.participants.external
    : [];

  const nextInternalIds = [];
  const seenInternalIds = new Set();
  for (const row of internalRows) {
    const userId = Number(row?.user_id || 0);
    if (!Number.isInteger(userId) || userId <= 0 || userId === ownerUserId || seenInternalIds.has(userId)) {
      continue;
    }
    seenInternalIds.add(userId);
    nextInternalIds.push(userId);
  }

  const nextExternalRows = [];
  for (const row of externalRows) {
    const email = String(row?.email || '').trim().toLowerCase();
    const displayName = String(row?.display_name || '').trim();
    if (email === '' && displayName === '') continue;
    nextExternalRows.push(nextCallOwnerExternalRow({
      email,
      display_name: displayName,
    }));
  }

  callOwnerExistingInternalUserIds.value = nextInternalIds;
  callOwnerSelectedUserIds.value = nextInternalIds.slice();
  callOwnerExternalRows.value = nextExternalRows.length > 0 ? nextExternalRows : [nextCallOwnerExternalRow()];
}

async function refreshCallOwnerContext() {
  const sequence = callOwnerContextSeq + 1;
  callOwnerContextSeq = sequence;

  if (!isCallWorkspace.value) {
    callOwnerEditState.visible = false;
    callOwnerEditState.loadingContext = false;
    callOwnerEditState.contextError = '';
    callOwnerEditState.callId = '';
    closeInCallEditModal();
    resetCallOwnerParticipantsState();
    return;
  }

  const callRef = String(route.params.callRef || '').trim();
  if (callRef === '') {
    callOwnerEditState.visible = false;
    callOwnerEditState.loadingContext = false;
    callOwnerEditState.contextError = '';
    callOwnerEditState.callId = '';
    return;
  }

  callOwnerEditState.loadingContext = true;
  callOwnerEditState.contextError = '';
  try {
    const call = await resolveEditableCallFromRouteRef(callRef);
    if (sequence !== callOwnerContextSeq) return;

    const currentUserId = Number(sessionState.userId || 0);
    const ownerUserId = Number(call?.owner?.user_id || 0);
    const isOwner = Number.isInteger(currentUserId) && currentUserId > 0 && currentUserId === ownerUserId;
    callOwnerEditState.visible = isOwner;

    if (isOwner) {
      hydrateCallOwnerDraftFromCall(call);
    } else {
      callOwnerEditState.callId = '';
      closeInCallEditModal();
      resetCallOwnerParticipantsState();
    }
  } catch (error) {
    if (sequence !== callOwnerContextSeq) return;
    callOwnerEditState.visible = false;
    callOwnerEditState.callId = '';
    closeInCallEditModal();
    resetCallOwnerParticipantsState();
    const status = Number(error?.responseStatus || 0);
    if (status !== 404 && status !== 403 && status !== 410) {
      callOwnerEditState.contextError = error instanceof Error ? error.message : 'Could not load call settings.';
    } else {
      callOwnerEditState.contextError = '';
    }
  } finally {
    if (sequence === callOwnerContextSeq) {
      callOwnerEditState.loadingContext = false;
    }
  }
}

function isCallOwnerUserSelected(userId) {
  const id = Number(userId);
  return callOwnerSelectedUserIds.value.includes(id);
}

function toggleCallOwnerUserSelection(userId) {
  const id = Number(userId);
  const ownUserId = Number(sessionState.userId || 0);
  if (!Number.isInteger(id) || id <= 0 || id === ownUserId) return;

  const next = callOwnerSelectedUserIds.value.slice();
  const index = next.indexOf(id);
  if (index >= 0) {
    next.splice(index, 1);
  } else {
    next.push(id);
  }
  callOwnerSelectedUserIds.value = next;
}

async function loadCallOwnerParticipantsDirectory() {
  if (!callOwnerEditState.open || !callOwnerEditState.replaceParticipants || !canLoadCallOwnerInternalDirectory.value) {
    return;
  }

  callOwnerParticipants.loading = true;
  callOwnerParticipants.error = '';
  try {
    const payload = await apiRequest('/api/admin/users', {
      query: {
        query: callOwnerParticipants.query,
        page: callOwnerParticipants.page,
        page_size: callOwnerParticipants.pageSize,
      },
    });
    const ownUserId = Number(sessionState.userId || 0);
    const rows = Array.isArray(payload?.users) ? payload.users : [];
    callOwnerParticipants.rows = rows.filter((row) => {
      const rowId = Number(row?.id || 0);
      return !Number.isInteger(rowId) || rowId !== ownUserId;
    });
    const pagination = payload?.pagination || {};
    callOwnerParticipants.pageCount = Number.isInteger(pagination.page_count) && pagination.page_count > 0
      ? pagination.page_count
      : 1;
    callOwnerParticipants.hasPrev = Boolean(pagination.has_prev);
    callOwnerParticipants.hasNext = Boolean(pagination.has_next);
  } catch (error) {
    callOwnerParticipants.rows = [];
    callOwnerParticipants.pageCount = 1;
    callOwnerParticipants.hasPrev = false;
    callOwnerParticipants.hasNext = false;
    callOwnerParticipants.error = error instanceof Error ? error.message : 'Could not load users.';
  } finally {
    callOwnerParticipants.loading = false;
  }
}

async function applyCallOwnerParticipantSearch() {
  callOwnerParticipants.page = 1;
  await loadCallOwnerParticipantsDirectory();
}

async function goToCallOwnerParticipantPage(nextPage) {
  const normalizedPage = Number(nextPage);
  if (!Number.isInteger(normalizedPage) || normalizedPage < 1 || normalizedPage === callOwnerParticipants.page) {
    return;
  }

  callOwnerParticipants.page = normalizedPage;
  await loadCallOwnerParticipantsDirectory();
}

function addCallOwnerExternalRow() {
  callOwnerExternalRows.value = [...callOwnerExternalRows.value, nextCallOwnerExternalRow()];
}

function removeCallOwnerExternalRow(index) {
  if (!Number.isInteger(index) || index < 0 || index >= callOwnerExternalRows.value.length) return;
  const next = callOwnerExternalRows.value.slice();
  next.splice(index, 1);
  callOwnerExternalRows.value = next.length > 0 ? next : [nextCallOwnerExternalRow()];
}

function normalizeCallOwnerExternalRows() {
  const rows = [];

  for (let index = 0; index < callOwnerExternalRows.value.length; index += 1) {
    const row = callOwnerExternalRows.value[index];
    const displayName = String(row?.display_name || '').trim();
    const email = String(row?.email || '').trim().toLowerCase();

    if (displayName === '' && email === '') continue;

    if (displayName === '' || email === '') {
      return {
        ok: false,
        error: `External participant row ${index + 1} requires both display name and email.`,
        rows: [],
      };
    }
    if (!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(email)) {
      return {
        ok: false,
        error: `External participant row ${index + 1} has an invalid email.`,
        rows: [],
      };
    }

    rows.push({
      display_name: displayName,
      email,
    });
  }

  return {
    ok: true,
    error: '',
    rows,
  };
}

function normalizedCallOwnerInternalParticipantUserIds() {
  const ownUserId = Number(sessionState.userId || 0);
  const source = canLoadCallOwnerInternalDirectory.value
    ? callOwnerSelectedUserIds.value
    : callOwnerExistingInternalUserIds.value;
  const seen = new Set();
  const result = [];
  for (const rawId of source) {
    const id = Number(rawId);
    if (!Number.isInteger(id) || id <= 0 || id === ownUserId || seen.has(id)) continue;
    seen.add(id);
    result.push(id);
  }
  return result;
}

async function openInCallEditModal() {
  callOwnerEditState.error = '';
  if (callOwnerEditState.loadingContext) return;

  if (!callOwnerEditState.visible || String(callOwnerEditState.callId || '').trim() === '') {
    await refreshCallOwnerContext();
  }
  if (!callOwnerEditState.visible || String(callOwnerEditState.callId || '').trim() === '') {
    return;
  }

  callOwnerEditState.open = true;
  callOwnerEditState.error = '';
  callOwnerEditState.replaceParticipants = false;
  if (callOwnerExternalRows.value.length === 0) {
    callOwnerExternalRows.value = [nextCallOwnerExternalRow()];
  }
}

function closeInCallEditModal() {
  callOwnerEditState.open = false;
  callOwnerEditState.submitting = false;
  callOwnerEditState.error = '';
  callOwnerEditState.replaceParticipants = false;
  callOwnerParticipants.error = '';
}

async function submitInCallEditModal() {
  callOwnerEditState.error = '';
  const callId = String(callOwnerEditState.callId || '').trim();
  if (callId === '') {
    callOwnerEditState.error = 'Missing call id.';
    return;
  }

  const title = String(callOwnerEditState.title || '').trim();
  if (title === '') {
    callOwnerEditState.error = 'Title is required.';
    return;
  }

  const startsAt = localInputToIso(callOwnerEditState.startsLocal);
  const endsAt = localInputToIso(callOwnerEditState.endsLocal);
  if (startsAt === '' || endsAt === '') {
    callOwnerEditState.error = 'Start and end timestamps are required.';
    return;
  }
  if (new Date(endsAt).getTime() <= new Date(startsAt).getTime()) {
    callOwnerEditState.error = 'End timestamp must be after start timestamp.';
    return;
  }

  const payload = {
    room_id: String(callOwnerEditState.roomId || '').trim() || 'lobby',
    title,
    access_mode: normalizeCallAccessMode(callOwnerEditState.accessMode),
    starts_at: startsAt,
    ends_at: endsAt,
  };

  if (callOwnerEditState.replaceParticipants) {
    const normalizedExternal = normalizeCallOwnerExternalRows();
    if (!normalizedExternal.ok) {
      callOwnerEditState.error = normalizedExternal.error;
      return;
    }
    payload.internal_participant_user_ids = normalizedCallOwnerInternalParticipantUserIds();
    payload.external_participants = normalizedExternal.rows;
  }

  callOwnerEditState.submitting = true;
  try {
    const response = await apiRequest(`/api/calls/${encodeURIComponent(callId)}`, {
      method: 'PATCH',
      body: payload,
    });
    const updatedCall = extractCallFromPayload(response);
    if (updatedCall && typeof updatedCall === 'object') {
      hydrateCallOwnerDraftFromCall(updatedCall);
    }
    closeInCallEditModal();
    await refreshCallOwnerContext();
  } catch (error) {
    callOwnerEditState.error = error instanceof Error ? error.message : 'Could not update call.';
  } finally {
    callOwnerEditState.submitting = false;
  }
}

function stopMicLevelMonitor() {
  micLevelMonitorToken += 1;
  if (micLevelFrame !== 0 && typeof cancelAnimationFrame === 'function') {
    cancelAnimationFrame(micLevelFrame);
  }
  micLevelFrame = 0;

  if (micLevelSource && typeof micLevelSource.disconnect === 'function') {
    try {
      micLevelSource.disconnect();
    } catch {
      // ignore
    }
  }
  micLevelSource = null;

  if (micLevelAnalyser && typeof micLevelAnalyser.disconnect === 'function') {
    try {
      micLevelAnalyser.disconnect();
    } catch {
      // ignore
    }
  }
  micLevelAnalyser = null;
  micLevelData = null;

  if (micLevelStream instanceof MediaStream) {
    for (const track of micLevelStream.getTracks()) {
      try {
        track.stop();
      } catch {
        // ignore
      }
    }
  }
  micLevelStream = null;

  if (micLevelAudioContext && typeof micLevelAudioContext.close === 'function') {
    micLevelAudioContext.close().catch(() => {});
  }
  micLevelAudioContext = null;
  micLevelPercent.value = 0;
}

function sampleMicLevel(token) {
  if (token !== micLevelMonitorToken) return;
  if (!micLevelAnalyser || !micLevelData) {
    micLevelPercent.value = 0;
    return;
  }

  micLevelAnalyser.getByteTimeDomainData(micLevelData);
  let energy = 0;
  let peak = 0;
  for (let index = 0; index < micLevelData.length; index += 1) {
    const centered = (micLevelData[index] - 128) / 128;
    energy += centered * centered;
    const amplitude = Math.abs(centered);
    if (amplitude > peak) peak = amplitude;
  }

  const rms = Math.sqrt(energy / micLevelData.length);
  const micScale = Math.max(0, Math.min(100, Number(callMediaPrefs.microphoneVolume || 100))) / 100;
  const gated = Math.max(0, Math.max(rms * 8.6, peak * 1.28) - 0.02);
  const normalized = Math.min(1, gated / 0.98);
  const boostedPercent = normalized * 100 * micScale * 3;
  micLevelPercent.value = Math.max(0, Math.min(100, Math.round(boostedPercent)));

  if (typeof requestAnimationFrame === 'function') {
    micLevelFrame = requestAnimationFrame(() => sampleMicLevel(token));
  }
}

async function startMicLevelMonitor() {
  stopMicLevelMonitor();
  if (!isCallWorkspace.value) return;
  if (
    typeof window === 'undefined'
    || typeof navigator === 'undefined'
    || !navigator.mediaDevices
    || typeof navigator.mediaDevices.getUserMedia !== 'function'
  ) {
    return;
  }

  const token = micLevelMonitorToken + 1;
  micLevelMonitorToken = token;
  const AudioContextCtor = window.AudioContext || window.webkitAudioContext;
  if (!AudioContextCtor) return;

  const selectedMicId = String(callMediaPrefs.selectedMicrophoneId || '').trim();
  const audioConstraints = selectedMicId !== ''
    ? { deviceId: { exact: selectedMicId }, echoCancellation: false, noiseSuppression: false, autoGainControl: false }
    : { echoCancellation: false, noiseSuppression: false, autoGainControl: false };

  try {
    const stream = await navigator.mediaDevices.getUserMedia({ audio: audioConstraints, video: false });
    if (token !== micLevelMonitorToken) {
      for (const track of stream.getTracks()) {
        track.stop();
      }
      return;
    }

    const context = new AudioContextCtor({ latencyHint: 'interactive' });
    const source = context.createMediaStreamSource(stream);
    const analyser = context.createAnalyser();
    analyser.fftSize = 256;
    analyser.smoothingTimeConstant = 0.08;
    source.connect(analyser);

    micLevelStream = stream;
    micLevelAudioContext = context;
    micLevelSource = source;
    micLevelAnalyser = analyser;
    micLevelData = new Uint8Array(analyser.fftSize);
    sampleMicLevel(token);
  } catch {
    if (token === micLevelMonitorToken) {
      micLevelPercent.value = 0;
    }
  }
}

async function playSpeakerTestSound() {
  if (typeof window === 'undefined') return;
  const AudioContextCtor = window.AudioContext || window.webkitAudioContext;
  if (!AudioContextCtor) return;

  let context = null;
  const audio = new Audio();
  try {
    context = new AudioContextCtor();
    const destination = context.createMediaStreamDestination();
    const oscillator = context.createOscillator();
    const gainNode = context.createGain();
    const normalizedVolume = Math.max(0, Math.min(100, Number(callMediaPrefs.speakerVolume || 100))) / 100;

    oscillator.type = 'sine';
    oscillator.frequency.value = 880;
    gainNode.gain.value = Math.max(0.01, normalizedVolume * 0.45);
    oscillator.connect(gainNode);
    gainNode.connect(destination);

    audio.srcObject = destination.stream;
    audio.playsInline = true;
    audio.muted = false;
    audio.volume = 1;

    const speakerDeviceId = String(callMediaPrefs.selectedSpeakerId || '').trim();
    if (speakerDeviceId !== '' && typeof audio.setSinkId === 'function') {
      await audio.setSinkId(speakerDeviceId).catch(() => {});
    }

    await audio.play();
    oscillator.start();
    oscillator.stop(context.currentTime + 0.22);
    await new Promise((resolve) => setTimeout(resolve, 260));
  } catch {
    // ignore
  } finally {
    try {
      audio.pause();
    } catch {
      // ignore
    }
    audio.srcObject = null;
    if (context && typeof context.close === 'function') {
      await context.close().catch(() => {});
    }
  }
}

provide('workspaceSidebarState', {
  leftSidebarCollapsed,
  isTabletViewport,
  isMobileViewport,
  isTabletSidebarOpen,
  showLeftSidebar,
});

watch([isMobileViewport, isMobileSidebarOpen], () => {
  syncMobileScrollLock();
}, { immediate: true });

watch(() => route.fullPath, () => {
  if (isMobileViewport.value && isMobileSidebarOpen.value) {
    isMobileSidebarOpen.value = false;
  }
});

watch(isCallWorkspace, (nextValue) => {
  if (nextValue) {
    if (!detachCallMediaWatcher) {
      detachCallMediaWatcher = attachCallMediaDeviceWatcher({ requestPermissions: false });
    }
    return;
  }
  if (detachCallMediaWatcher) {
    detachCallMediaWatcher();
    detachCallMediaWatcher = null;
  }
}, { immediate: true });

watch(
  () => [isCallWorkspace.value, callMediaPrefs.selectedMicrophoneId],
  ([inCallWorkspace]) => {
    if (inCallWorkspace) {
      void startMicLevelMonitor();
      return;
    }
    stopMicLevelMonitor();
  },
  { immediate: true }
);

watch(
  () => [
    isCallWorkspace.value,
    String(route.params.callRef || '').trim(),
    Number(sessionState.userId || 0),
    normalizeRole(sessionState.role),
  ],
  () => {
    void refreshCallOwnerContext();
  },
  { immediate: true }
);

watch(
  () => [
    callOwnerEditState.open,
    callOwnerEditState.replaceParticipants,
    canLoadCallOwnerInternalDirectory.value,
  ],
  ([isOpen, replaceParticipants, canLoadInternalDirectory]) => {
    if (!isOpen || !replaceParticipants || !canLoadInternalDirectory) return;
    void loadCallOwnerParticipantsDirectory();
  }
);

onMounted(() => {
  applySettingsLanguage(readStoredSettingsLanguage());
  applyThemeColorMap(persistedThemeColors);
  if (typeof window === 'undefined' || typeof window.matchMedia !== 'function') return;
  laptopMedia = window.matchMedia(`(max-width: ${LAPTOP_BREAKPOINT}px)`);
  tabletMedia = window.matchMedia(`(max-width: ${TABLET_BREAKPOINT}px)`);
  mobileMedia = window.matchMedia(`(max-width: ${MOBILE_BREAKPOINT}px)`);
  syncViewportState();
  if (typeof laptopMedia.addEventListener === 'function') {
    laptopMedia.addEventListener('change', handleViewportChange);
    tabletMedia.addEventListener('change', handleViewportChange);
    mobileMedia.addEventListener('change', handleViewportChange);
  } else if (typeof laptopMedia.addListener === 'function') {
    laptopMedia.addListener(handleViewportChange);
    tabletMedia.addListener(handleViewportChange);
    mobileMedia.addListener(handleViewportChange);
  }
});

onBeforeUnmount(() => {
  stopMicLevelMonitor();
  if (detachCallMediaWatcher) {
    detachCallMediaWatcher();
    detachCallMediaWatcher = null;
  }
  if (!laptopMedia || !tabletMedia || !mobileMedia) return;
  if (typeof laptopMedia.removeEventListener === 'function') {
    laptopMedia.removeEventListener('change', handleViewportChange);
    tabletMedia.removeEventListener('change', handleViewportChange);
    mobileMedia.removeEventListener('change', handleViewportChange);
  } else if (typeof laptopMedia.removeListener === 'function') {
    laptopMedia.removeListener(handleViewportChange);
    tabletMedia.removeListener(handleViewportChange);
    mobileMedia.removeListener(handleViewportChange);
  }
  laptopMedia = null;
  tabletMedia = null;
  mobileMedia = null;
  syncMobileScrollLock(true);
});

function openCallsRegistry() {
  router.push('/admin/calls');
}

function openGrafana() {
  window.open('https://grafana.example.local', '_blank', 'noopener,noreferrer');
}

function resetSettingsDraft() {
  settingsDraft.displayName = sessionState.displayName || '';
  settingsDraft.theme = sessionState.theme || 'dark';
  settingsDraft.timeFormat = sessionState.timeFormat || '24h';
  settingsDraft.dateFormat = sessionState.dateFormat || 'dmy_dot';
  settingsDraft.language = readStoredSettingsLanguage();
  settingsDraft.avatarDataUrl = '';
  patchThemeColorMap(settingsDraft.themeColors, persistedThemeColors);
}

function setAvatarStatus(message = '') {
  settingsState.avatarStatus = message;
}

function normalizeSettingsTile(tileId) {
  const normalized = String(tileId || '').trim();
  const fallback = 'about-me';
  if (normalized === '') return fallback;
  return settingsTiles.value.some((tile) => tile.id === normalized) ? normalized : fallback;
}

function closeSettingsModal() {
  if (settingsState.saving) return;
  applyThemeColorMap(persistedThemeColors);
  settingsState.open = false;
  settingsState.dragging = false;
  settingsState.loading = false;
  settingsState.message = '';
  settingsState.avatarStatus = '';
  activeSettingsTile.value = 'about-me';
  resetSettingsDraft();
}

function openSettingsModal(tileId = 'about-me') {
  activeSettingsTile.value = normalizeSettingsTile(tileId);
  if (settingsState.open) return;
  settingsState.open = true;
  settingsState.loading = false;
  settingsState.message = '';
  settingsState.avatarStatus = '';
  settingsState.dragging = false;
  resetSettingsDraft();
}

function readFileAsDataUrl(file) {
  return new Promise((resolve, reject) => {
    const reader = new FileReader();
    reader.onload = () => resolve(typeof reader.result === 'string' ? reader.result : '');
    reader.onerror = () => reject(new Error('Could not read avatar file.'));
    reader.readAsDataURL(file);
  });
}

async function setAvatarFromFile(file) {
  if (!file) return;
  if (!['image/png', 'image/jpeg', 'image/webp'].includes(file.type)) {
    setAvatarStatus('Avatar must be PNG, JPEG, or WEBP.');
    return;
  }

  try {
    const dataUrl = await readFileAsDataUrl(file);
    settingsDraft.avatarDataUrl = dataUrl;
    setAvatarStatus(`Selected ${file.name}. Save to upload.`);
  } catch (error) {
    setAvatarStatus(error instanceof Error ? error.message : 'Could not prepare avatar upload.');
  }
}

async function handleAvatarSelect(event) {
  const file = event?.target?.files?.[0] || null;
  settingsState.dragging = false;
  await setAvatarFromFile(file);
}

async function handleAvatarDrop(event) {
  settingsState.dragging = false;
  const file = event?.dataTransfer?.files?.[0] || null;
  await setAvatarFromFile(file);
}

async function saveSettings() {
  if (settingsState.saving || settingsState.loading) return;
  settingsState.message = '';
  settingsState.avatarStatus = '';

  const displayName = settingsDraft.displayName.trim();
  const theme = settingsDraft.theme.trim();
  const rawTimeFormat = settingsDraft.timeFormat.trim();
  const rawDateFormat = settingsDraft.dateFormat.trim();
  const timeFormat = normalizeTimeFormat(rawTimeFormat);
  const dateFormat = normalizeDateFormat(rawDateFormat);
  const language = normalizeSettingsLanguage(settingsDraft.language);

  if (displayName === '') {
    settingsState.message = 'Display name is required.';
    return;
  }

  if (theme === '') {
    settingsState.message = 'Theme is required.';
    return;
  }

  if (!['24h', '12h'].includes(rawTimeFormat)) {
    settingsState.message = 'Time format must be 24h or 12h.';
    return;
  }

  if (rawDateFormat === '' || rawDateFormat !== dateFormat) {
    settingsState.message = 'Please choose a supported date format.';
    return;
  }

  if (!SUPPORTED_SETTINGS_LANGUAGES.includes(language)) {
    settingsState.message = 'Unsupported language selected.';
    return;
  }

  settingsState.saving = true;
  try {
    let avatarPath = sessionState.avatarPath || null;
    if (settingsDraft.avatarDataUrl) {
      const uploadResult = await uploadSessionAvatar(settingsDraft.avatarDataUrl);
      if (!uploadResult.ok) {
        settingsState.message = uploadResult.message || 'Avatar upload failed.';
        if (uploadResult.reason === 'invalid_session') {
          settingsState.open = false;
          router.replace('/login');
        }
        return;
      }

      avatarPath = uploadResult.avatarPath || avatarPath;
      setAvatarStatus('Avatar uploaded on the backend.');
    }

    const saveResult = await saveSessionSettings({
      display_name: displayName,
      theme,
      time_format: timeFormat,
      date_format: dateFormat,
      avatar_path: avatarPath,
    });

    if (!saveResult.ok) {
      settingsState.message = saveResult.message || 'Could not save settings.';
      if (saveResult.reason === 'invalid_session') {
        settingsState.open = false;
        router.replace('/login');
      }
      return;
    }

    storeSettingsLanguage(language);
    applySettingsLanguage(language);
    patchThemeColorMap(persistedThemeColors, settingsDraft.themeColors);
    storeThemeColorMap(persistedThemeColors);
    applyThemeColorMap(persistedThemeColors);
    settingsState.message = 'Settings saved.';
    settingsState.open = false;
    resetSettingsDraft();
  } finally {
    settingsState.saving = false;
  }
}

async function handleSignOut() {
  await logoutSession();
  router.replace('/login');
}
</script>
