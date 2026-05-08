<template>
  <section class="view-card calendar-view">
    <header class="calendar-header">
      <div class="calendar-title">
        <h1>{{ t('calendar.calendar') }}</h1>
        <p>{{ t('calendar.limit_hint', { count: rows.length, max: maxCalendars }) }}</p>
      </div>
      <div class="calendar-header-actions">
        <button
          v-if="canCreateCalendar"
          class="btn btn-cyan"
          type="button"
          :disabled="state.creating"
          @click="createCalendar"
        >
          {{ state.creating ? t('common.saving') : t('calendar.add_calendar') }}
        </button>
        <AppIconButton
          v-if="activeCalendar"
          icon="/assets/orgas/kingrt/icons/gear.png"
          :title="t('calendar.calendar_settings')"
          :aria-label="t('calendar.calendar_settings')"
          @click="openSettings(activeCalendar)"
        />
      </div>
    </header>

    <section v-if="state.notice" class="calendar-banner ok">{{ state.notice }}</section>
    <section v-if="state.error" class="calendar-banner error">{{ state.error }}</section>

    <nav v-if="rows.length > 0" class="workspace-calendar-tabs" :aria-label="t('calendar.calendar')">
      <button
        v-for="calendar in rows"
        :key="calendar.id"
        class="workspace-calendar-tab"
        :class="{ active: calendar.id === activeCalendarId }"
        :style="{ '--calendar-color': calendar.color || defaultCalendarColor }"
        type="button"
        @click="selectCalendar(calendar.id)"
      >
        <span class="calendar-color-dot"></span>
        <span>{{ calendar.name }}</span>
      </button>
    </nav>

    <section v-if="state.loading" class="calendar-state">{{ t('common.loading') }}</section>
    <section v-else-if="!activeCalendar" class="calendar-state">{{ t('calendar.empty') }}</section>

    <section v-else class="calendar-content">
      <header class="calendar-content-header">
        <div class="calendar-active-summary">
          <strong>{{ activeCalendar.name }}</strong>
          <span>{{ calendarAccessSummary(activeCalendar) }}</span>
        </div>
        <div class="calendar-inner-tabs" role="tablist" :aria-label="t('calendar.calendar')">
          <button
            class="tab"
            :class="{ active: activePanel === 'calls' }"
            type="button"
            role="tab"
            :aria-selected="activePanel === 'calls'"
            @click="activePanel = 'calls'"
          >
            {{ t('calendar.call_calendar') }}
          </button>
          <button
            class="tab"
            :class="{ active: activePanel === 'personal' }"
            type="button"
            role="tab"
            :aria-selected="activePanel === 'personal'"
            @click="activePanel = 'personal'"
          >
            {{ t('calendar.personal_calendar') }}
          </button>
        </div>
      </header>

      <section v-show="activePanel === 'calls'" class="calendar-panel calendar-panel-calls">
        <section v-if="callsState.error" class="calendar-state error">{{ callsState.error }}</section>
        <section v-if="callsState.loading" class="calendar-inline-loading">{{ t('calls.admin.loading_calendar') }}</section>
        <section ref="callsCalendarEl" class="workspace-fullcalendar"></section>
      </section>

      <section v-show="activePanel === 'personal'" class="calendar-panel calendar-panel-personal">
        <AppointmentConfigPanel @saved="setNotice(t('calls.admin.appointment_slots_saved'))" />
      </section>
    </section>

    <aside v-if="settings.open" class="calendar-sidebar">
      <header class="calendar-sidebar-head">
        <strong>{{ t('calendar.calendar_settings') }}</strong>
        <AppIconButton
          icon="/assets/orgas/kingrt/icons/cancel.png"
          :title="t('common.close_panel')"
          :aria-label="t('common.close_panel')"
          @click="closeSettings"
        />
      </header>

      <form class="calendar-sidebar-body" @submit.prevent="saveSettings">
        <label class="settings-field">
          <span>{{ t('calendar.name') }}</span>
          <input v-model.trim="settingsForm.name" class="input" type="text" :placeholder="t('calendar.name_placeholder')" />
        </label>
        <label class="settings-field">
          <span>{{ t('calendar.description') }}</span>
          <textarea
            v-model.trim="settingsForm.description"
            class="settings-textarea"
            rows="4"
            :placeholder="t('calendar.description_placeholder')"
          ></textarea>
        </label>

        <section class="calendar-settings-block">
          <header>
            <strong>{{ t('calendar.color') }}</strong>
          </header>
          <div class="calendar-color-grid">
            <button
              v-for="color in calendarColors"
              :key="color"
              class="calendar-color-choice"
              :class="{ selected: settingsForm.color === color }"
              :style="{ '--calendar-color': color }"
              type="button"
              :aria-label="color"
              @click="settingsForm.color = color"
            ></button>
          </div>
        </section>

        <section class="calendar-settings-block">
          <header>
            <strong>{{ t('calendar.access') }}</strong>
            <span>{{ t('calendar.access_hint') }}</span>
          </header>

          <section v-if="selectedMembers.length > 0" class="calendar-selected-members">
            <article v-for="member in selectedMembers" :key="member.user_id" class="calendar-member-row">
              <span>
                <strong>{{ member.display_name || member.email }}</strong>
                <small>{{ member.email }}</small>
              </span>
              <AppSelect v-model="member.access_role" class="calendar-role-select">
                <option value="viewer">{{ t('calendar.role_viewer') }}</option>
                <option value="editor">{{ t('calendar.role_editor') }}</option>
              </AppSelect>
              <AppIconButton
                icon="/assets/orgas/kingrt/icons/cancel.png"
                :title="t('calendar.remove_member')"
                :aria-label="t('calendar.remove_member')"
                @click="removeSelectedMember(member.user_id)"
              />
            </article>
          </section>

          <section class="calendar-directory-search">
            <label class="search-field search-field-main" :aria-label="t('calendar.search_users')">
              <input
                v-model.trim="directory.query"
                class="input"
                type="search"
                :placeholder="t('calendar.search_users')"
                @keydown.enter.prevent="applyDirectorySearch"
              />
            </label>
            <AppIconButton
              icon="/assets/orgas/kingrt/icons/send.png"
              :title="t('common.search')"
              :aria-label="t('common.search')"
              @click="applyDirectorySearch"
            />
          </section>

          <section v-if="directory.error" class="calendar-state error">{{ directory.error }}</section>
          <section class="calendar-directory-list">
            <button
              v-for="user in directory.rows"
              :key="user.id"
              class="calendar-directory-row"
              type="button"
              :disabled="isMemberSelected(user.id)"
              @click="addDirectoryUser(user)"
            >
              <span>
                <strong>{{ user.display_name || user.email }}</strong>
                <small>{{ user.email }}</small>
              </span>
              <span>{{ isMemberSelected(user.id) ? t('calendar.access_granted') : t('calendar.select_user') }}</span>
            </button>
            <p v-if="!directory.loading && directory.rows.length === 0" class="calendar-empty-inline">
              {{ t('calendar.users_empty') }}
            </p>
          </section>

          <AppPagination
            :page="directory.page"
            :page-count="directory.page_count"
            :total="directory.total"
            :has-prev="directory.page > 1"
            :has-next="directory.page < directory.page_count"
            :disabled="directory.loading"
            @page-change="goToDirectoryPage"
          />
        </section>

        <section class="calendar-settings-block">
          <header>
            <strong>{{ t('calendar.sync') }}</strong>
            <span>{{ t('calendar.sync_hint') }}</span>
          </header>
          <label v-for="calendar in syncOptions" :key="calendar.id" class="calendar-sync-row">
            <input v-model="settingsForm.sync_calendar_ids" type="checkbox" :value="calendar.id" />
            <span class="calendar-color-dot" :style="{ '--calendar-color': calendar.color || defaultCalendarColor }"></span>
            <span>{{ calendar.name }}</span>
          </label>
          <p v-if="syncOptions.length === 0" class="calendar-empty-inline">{{ t('calendar.sync_empty') }}</p>
        </section>

        <section v-if="settings.error" class="calendar-state error">{{ settings.error }}</section>

        <footer class="calendar-sidebar-actions">
          <button
            v-if="settingsForm.id && !settingsForm.is_personal"
            class="btn btn-danger"
            type="button"
            :disabled="settings.saving"
            @click="deleteActiveCalendar"
          >
            {{ t('calendar.delete_calendar') }}
          </button>
          <button class="btn btn-cyan" type="submit" :disabled="settings.saving">
            {{ settings.saving ? t('common.saving') : t('common.save') }}
          </button>
        </footer>
      </form>
    </aside>

    <aside v-if="schedule.open" class="calendar-sidebar">
      <header class="calendar-sidebar-head">
        <strong>{{ schedule.mode === 'edit' ? t('calendar.edit_call') : t('calendar.schedule_call') }}</strong>
        <AppIconButton
          icon="/assets/orgas/kingrt/icons/cancel.png"
          :title="t('common.close_panel')"
          :aria-label="t('common.close_panel')"
          @click="closeSchedule"
        />
      </header>

      <form class="calendar-sidebar-body" @submit.prevent="saveSchedule">
        <label class="settings-field">
          <span>{{ t('calls.compose.title_label') }}</span>
          <input v-model.trim="schedule.title" class="input" type="text" />
        </label>
        <label class="settings-field">
          <span>{{ t('calls.compose.starts_at') }}</span>
          <input v-model="schedule.startsLocal" class="input" type="datetime-local" />
        </label>
        <label class="settings-field">
          <span>{{ t('calls.compose.ends_at') }}</span>
          <input v-model="schedule.endsLocal" class="input" type="datetime-local" />
        </label>
        <label class="settings-field">
          <span>{{ t('calls.compose.access_mode') }}</span>
          <AppSelect v-model="schedule.accessMode">
            <option value="invite_only">{{ t('calls.compose.invite_only') }}</option>
            <option value="free_for_all">{{ t('calls.compose.free_for_all') }}</option>
          </AppSelect>
        </label>

        <section v-if="schedule.error" class="calendar-state error">{{ schedule.error }}</section>

        <footer class="calendar-sidebar-actions">
          <button class="btn btn-cyan" type="submit" :disabled="schedule.saving">
            {{ schedule.saving ? t('common.saving') : t('common.save') }}
          </button>
        </footer>
      </form>
    </aside>
  </section>
</template>

<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { Calendar } from '@fullcalendar/core';
import dayGridPlugin from '@fullcalendar/daygrid';
import timeGridPlugin from '@fullcalendar/timegrid';
import interactionPlugin from '@fullcalendar/interaction';
import AppIconButton from '../../../components/AppIconButton.vue';
import AppPagination from '../../../components/AppPagination.vue';
import AppSelect from '../../../components/AppSelect.vue';
import AppointmentConfigPanel from '../../../domain/calls/appointment/AppointmentConfigPanel.vue';
import {
  createWorkspaceCalendar,
  deleteWorkspaceCalendar,
  listCalendarDirectoryUsers,
  listWorkspaceCalendars,
  updateWorkspaceCalendar,
} from '../../../domain/workspace/calendarApi';
import { sessionState } from '../../../domain/auth/session';
import { currentBackendOrigin, fetchBackend } from '../../../support/backendFetch';
import { fullCalendarEventTimeFormat } from '../../../support/dateTimeFormat';
import { createAdminSyncSocket } from '../../../support/adminSyncSocket';
import { t } from '../../localization/i18nRuntime.js';

const maxCalendars = 5;
const defaultCalendarColor = '#1582BF';
const calendarColors = Object.freeze([
  '#1582BF',
  '#59C7F2',
  '#03275A',
  '#00652F',
  '#F47221',
  '#EF4423',
  '#00052D',
  '#EFEFE7',
]);

const rows = ref([]);
const activeCalendarId = ref('');
const activePanel = ref('calls');
const callsCalendarEl = ref(null);
const calendarCalls = ref([]);
const selectedMembers = ref([]);
const state = reactive({ loading: false, creating: false, error: '', notice: '' });
const callsState = reactive({ loading: false, error: '' });
const settings = reactive({ open: false, saving: false, error: '' });
const settingsForm = reactive({
  id: '',
  name: '',
  description: '',
  color: defaultCalendarColor,
  is_personal: false,
  sync_calendar_ids: [],
});
const directory = reactive({
  query: '',
  page: 1,
  page_size: 8,
  total: 0,
  page_count: 1,
  loading: false,
  error: '',
  rows: [],
});
const schedule = reactive({
  open: false,
  mode: 'create',
  callId: '',
  title: '',
  accessMode: 'invite_only',
  startsLocal: '',
  endsLocal: '',
  saving: false,
  error: '',
});

let calendarInstance = null;
let calendarRootEl = null;
let lastCalendarDateClickAt = 0;
let lastCalendarDateKey = '';
let adminSyncReloadTimer = 0;
let adminSyncClient = null;

const activeCalendar = computed(() => (
  rows.value.find((calendar) => String(calendar?.id || '') === activeCalendarId.value)
  || rows.value[0]
  || null
));
const canCreateCalendar = computed(() => rows.value.length < maxCalendars && !state.loading);
const syncOptions = computed(() => rows.value.filter((calendar) => String(calendar?.id || '') !== settingsForm.id));

function setNotice(message) {
  state.notice = String(message || '').trim();
  if (state.notice !== '') {
    window.setTimeout(() => {
      state.notice = '';
    }, 3000);
  }
}

function requestHeaders(withBody = false) {
  const headers = { accept: 'application/json' };
  if (withBody) headers['content-type'] = 'application/json';
  const token = String(sessionState.sessionToken || '').trim();
  if (token !== '') headers.authorization = `Bearer ${token}`;
  return headers;
}

function extractErrorMessage(payload, fallback) {
  const message = payload && typeof payload === 'object' ? payload?.error?.message : '';
  return typeof message === 'string' && message.trim() !== '' ? message.trim() : fallback;
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
    throw new Error(extractErrorMessage(payload, `Request failed (${response.status}).`));
  }
  if (!payload || payload.status !== 'ok') {
    throw new Error('Backend returned an invalid payload.');
  }
  return payload;
}

function isoToLocalInput(isoValue) {
  if (typeof isoValue !== 'string' || isoValue.trim() === '') return '';
  const date = new Date(isoValue);
  if (Number.isNaN(date.getTime())) return '';
  const year = date.getFullYear();
  const month = String(date.getMonth() + 1).padStart(2, '0');
  const day = String(date.getDate()).padStart(2, '0');
  const hour = String(date.getHours()).padStart(2, '0');
  const minute = String(date.getMinutes()).padStart(2, '0');
  return `${year}-${month}-${day}T${hour}:${minute}`;
}

function localInputToIso(localValue) {
  if (typeof localValue !== 'string' || localValue.trim() === '') return '';
  const date = new Date(localValue);
  return Number.isNaN(date.getTime()) ? '' : date.toISOString();
}

function calendarAccessSummary(calendar) {
  const owner = String(calendar?.owner_name || calendar?.owner_email || '').trim();
  const role = String(calendar?.access_role || 'viewer').trim();
  const count = Number(calendar?.member_count || 0);
  return [
    owner ? `${t('calendar.owner')}: ${owner}` : '',
    role ? `${t('calendar.access')}: ${role}` : '',
    t('calendar.member_count', { count }),
  ].filter(Boolean).join(' · ');
}

function applyCalendarListing(payload, preferredActiveId = '') {
  rows.value = Array.isArray(payload?.calendars) ? payload.calendars.slice(0, maxCalendars) : [];
  const preferred = String(preferredActiveId || activeCalendarId.value || '').trim();
  const nextActive = rows.value.find((calendar) => String(calendar?.id || '') === preferred) || rows.value[0] || null;
  activeCalendarId.value = nextActive ? String(nextActive.id || '') : '';
}

async function loadRows({ preferredActiveId = '', background = false } = {}) {
  if (!background) {
    state.loading = true;
    state.error = '';
  }
  try {
    applyCalendarListing(await listWorkspaceCalendars({ page: 1, page_size: maxCalendars }), preferredActiveId);
  } catch (error) {
    if (!background) {
      state.error = error instanceof Error ? error.message : t('calendar.load_failed');
    }
  } finally {
    if (!background) state.loading = false;
  }
}

function selectCalendar(calendarId) {
  activeCalendarId.value = String(calendarId || '');
  closeSettings();
  closeSchedule();
  void ensureCalendarUiReady();
}

async function createCalendar() {
  if (!canCreateCalendar.value || state.creating) return;
  state.creating = true;
  state.error = '';
  try {
    const nextNumber = rows.value.length + 1;
    const color = calendarColors[(nextNumber - 1) % calendarColors.length];
    const payload = await createWorkspaceCalendar({
      name: t('calendar.default_calendar_name', { number: nextNumber }),
      description: '',
      color,
      members: [],
      sync_calendar_ids: [],
    });
    const createdId = String(payload?.calendar?.id || '');
    publishAdminSync('calendar', 'calendar_created');
    await loadRows({ preferredActiveId: createdId });
  } catch (error) {
    state.error = error instanceof Error ? error.message : t('calendar.save_failed');
  } finally {
    state.creating = false;
  }
}

function normalizeMember(member) {
  return {
    user_id: Number(member?.user_id ?? member?.id ?? 0),
    display_name: String(member?.display_name || ''),
    email: String(member?.email || ''),
    access_role: String(member?.access_role || 'viewer') === 'editor' ? 'editor' : 'viewer',
  };
}

function openSettings(calendar) {
  closeSchedule();
  settings.open = true;
  settings.error = '';
  settingsForm.id = String(calendar?.id || '');
  settingsForm.name = String(calendar?.name || '');
  settingsForm.description = String(calendar?.description || '');
  settingsForm.color = String(calendar?.color || defaultCalendarColor);
  settingsForm.is_personal = Boolean(calendar?.is_personal);
  settingsForm.sync_calendar_ids = Array.isArray(calendar?.sync_calendar_ids) ? [...calendar.sync_calendar_ids] : [];
  selectedMembers.value = Array.isArray(calendar?.members)
    ? calendar.members.map(normalizeMember).filter((member) => member.user_id > 0 && member.access_role !== 'owner')
    : [];
  void loadDirectory();
}

function closeSettings() {
  settings.open = false;
  settings.error = '';
}

function settingsPayload() {
  return {
    name: settingsForm.name,
    description: settingsForm.description,
    color: settingsForm.color,
    members: selectedMembers.value
      .map(normalizeMember)
      .filter((member) => member.user_id > 0)
      .map((member) => ({
        user_id: member.user_id,
        access_role: member.access_role,
      })),
    sync_calendar_ids: settingsForm.sync_calendar_ids,
  };
}

async function saveSettings() {
  if (settings.saving || settingsForm.id === '') return;
  settings.saving = true;
  settings.error = '';
  try {
    await updateWorkspaceCalendar(settingsForm.id, settingsPayload());
    publishAdminSync('calendar', 'calendar_updated');
    await loadRows({ preferredActiveId: settingsForm.id });
    closeSettings();
  } catch (error) {
    settings.error = error instanceof Error ? error.message : t('calendar.save_failed');
  } finally {
    settings.saving = false;
  }
}

async function deleteActiveCalendar() {
  if (settings.saving || settingsForm.id === '' || settingsForm.is_personal) return;
  settings.saving = true;
  settings.error = '';
  try {
    await deleteWorkspaceCalendar(settingsForm.id);
    publishAdminSync('calendar', 'calendar_deleted');
    closeSettings();
    await loadRows();
  } catch (error) {
    settings.error = error instanceof Error ? error.message : t('calendar.save_failed');
  } finally {
    settings.saving = false;
  }
}

function applyDirectoryPayload(payload) {
  directory.rows = Array.isArray(payload?.users) ? payload.users : [];
  const next = payload?.pagination || {};
  directory.page = Number(next.page || 1);
  directory.total = Number(next.total || directory.rows.length);
  directory.page_count = Math.max(1, Number(next.page_count || 1));
}

async function loadDirectory() {
  directory.loading = true;
  directory.error = '';
  try {
    applyDirectoryPayload(await listCalendarDirectoryUsers({
      query: directory.query,
      page: directory.page,
      page_size: directory.page_size,
    }));
  } catch (error) {
    directory.error = error instanceof Error ? error.message : t('calendar.user_load_failed');
  } finally {
    directory.loading = false;
  }
}

function applyDirectorySearch() {
  directory.page = 1;
  void loadDirectory();
}

function goToDirectoryPage(page) {
  directory.page = Math.max(1, Number(page) || 1);
  void loadDirectory();
}

function normalizedUserId(value) {
  const id = Number(value);
  return Number.isInteger(id) && id > 0 ? id : 0;
}

function isMemberSelected(userId) {
  const id = normalizedUserId(userId);
  return selectedMembers.value.some((member) => normalizedUserId(member.user_id || member.id) === id);
}

function addDirectoryUser(user) {
  const id = normalizedUserId(user?.id);
  if (id <= 0 || isMemberSelected(id)) return;
  selectedMembers.value = [
    ...selectedMembers.value,
    {
      user_id: id,
      display_name: String(user?.display_name || ''),
      email: String(user?.email || ''),
      access_role: 'viewer',
    },
  ];
}

function removeSelectedMember(userId) {
  const id = normalizedUserId(userId);
  selectedMembers.value = selectedMembers.value.filter((member) => normalizedUserId(member.user_id || member.id) !== id);
}

function isEditable(call) {
  const status = String(call?.status || '').toLowerCase();
  return status !== 'cancelled' && status !== 'ended';
}

async function loadCallsCalendar({ background = false } = {}) {
  if (!background) {
    callsState.loading = true;
    callsState.error = '';
  }
  try {
    const payload = await apiRequest('/api/calls', {
      query: {
        scope: 'all',
        status: 'all',
        query: '',
        page: 1,
        page_size: 100,
      },
    });
    calendarCalls.value = Array.isArray(payload.calls) ? payload.calls : [];
    callsState.error = '';
  } catch (error) {
    if (!background) {
      calendarCalls.value = [];
      callsState.error = error instanceof Error ? error.message : 'Could not load calendar calls.';
    }
  } finally {
    if (!background) callsState.loading = false;
  }
}

function toCalendarEvents() {
  const events = [];
  const activeColor = String(activeCalendar.value?.color || defaultCalendarColor);
  for (const call of calendarCalls.value) {
    const startsAt = new Date(String(call?.starts_at || ''));
    const endsAt = new Date(String(call?.ends_at || ''));
    if (Number.isNaN(startsAt.getTime()) || Number.isNaN(endsAt.getTime())) continue;
    events.push({
      id: String(call.id || ''),
      title: String(call.title || call.id || 'Video call'),
      start: startsAt,
      end: endsAt,
      allDay: false,
      editable: isEditable(call),
      backgroundColor: activeColor,
      borderColor: activeColor,
      extendedProps: { callPayload: call },
    });
  }
  return events;
}

function syncCalendarEvents() {
  if (!calendarInstance) return;
  calendarInstance.removeAllEvents();
  for (const event of toCalendarEvents()) {
    calendarInstance.addEvent(event);
  }
}

function openScheduleForWindow(startValue, endValue, call = null) {
  closeSettings();
  const start = startValue instanceof Date ? startValue : new Date(startValue);
  let end = endValue instanceof Date ? endValue : new Date(endValue);
  if (Number.isNaN(start.getTime())) return;
  if (Number.isNaN(end.getTime()) || end.getTime() <= start.getTime()) {
    end = new Date(start.getTime() + (45 * 60 * 1000));
  }

  schedule.open = true;
  schedule.mode = call ? 'edit' : 'create';
  schedule.callId = String(call?.id || '');
  schedule.title = String(call?.title || t('calendar.default_call_title'));
  schedule.accessMode = String(call?.access_mode || 'invite_only') === 'free_for_all' ? 'free_for_all' : 'invite_only';
  schedule.startsLocal = isoToLocalInput(start.toISOString());
  schedule.endsLocal = isoToLocalInput(end.toISOString());
  schedule.error = '';
}

function closeSchedule() {
  schedule.open = false;
  schedule.saving = false;
  schedule.error = '';
}

async function saveSchedule() {
  if (schedule.saving) return;
  schedule.error = '';
  const startsAt = localInputToIso(schedule.startsLocal);
  const endsAt = localInputToIso(schedule.endsLocal);
  if (schedule.title.trim() === '') {
    schedule.error = t('calls.compose.title_required');
    return;
  }
  if (startsAt === '' || endsAt === '') {
    schedule.error = t('calls.compose.start_end_required');
    return;
  }
  if (new Date(endsAt).getTime() <= new Date(startsAt).getTime()) {
    schedule.error = t('calls.compose.end_after_start');
    return;
  }

  schedule.saving = true;
  try {
    const payload = {
      title: schedule.title.trim(),
      access_mode: schedule.accessMode === 'free_for_all' ? 'free_for_all' : 'invite_only',
      starts_at: startsAt,
      ends_at: endsAt,
    };
    if (schedule.mode === 'edit' && schedule.callId !== '') {
      await apiRequest(`/api/calls/${encodeURIComponent(schedule.callId)}`, { method: 'PATCH', body: payload });
      publishAdminSync('calls', 'call_updated');
    } else {
      await apiRequest('/api/calls', { method: 'POST', body: payload });
      publishAdminSync('calls', 'call_created');
    }
    closeSchedule();
    await loadCallsCalendar();
    setNotice(schedule.mode === 'edit' ? t('calls.compose.updated_notice') : t('calls.compose.created_notice'));
  } catch (error) {
    schedule.error = error instanceof Error ? error.message : t('calls.compose.save_failed');
  } finally {
    schedule.saving = false;
  }
}

function resolveCalendarEventCall(eventApi) {
  const payloadCall = eventApi?.extendedProps?.callPayload;
  if (payloadCall && typeof payloadCall === 'object') return payloadCall;
  const callId = String(eventApi?.id || '').trim();
  return calendarCalls.value.find((call) => String(call?.id || '') === callId) || null;
}

async function persistCalendarEventWindow(eventApi, revert) {
  const call = resolveCalendarEventCall(eventApi);
  const callId = String(call?.id || eventApi?.id || '').trim();
  if (callId === '' || !isEditable(call)) {
    if (typeof revert === 'function') revert();
    return;
  }

  const startDate = eventApi?.start instanceof Date ? new Date(eventApi.start.getTime()) : null;
  let endDate = eventApi?.end instanceof Date ? new Date(eventApi.end.getTime()) : null;
  if (!(startDate instanceof Date) || Number.isNaN(startDate.getTime())) {
    if (typeof revert === 'function') revert();
    return;
  }
  if (!(endDate instanceof Date) || Number.isNaN(endDate.getTime())) {
    const previousStart = new Date(String(call?.starts_at || ''));
    const previousEnd = new Date(String(call?.ends_at || ''));
    if (!Number.isNaN(previousStart.getTime()) && !Number.isNaN(previousEnd.getTime())) {
      endDate = new Date(startDate.getTime() + (previousEnd.getTime() - previousStart.getTime()));
    }
  }
  if (!(endDate instanceof Date) || Number.isNaN(endDate.getTime()) || endDate.getTime() <= startDate.getTime()) {
    if (typeof revert === 'function') revert();
    return;
  }

  try {
    await apiRequest(`/api/calls/${encodeURIComponent(callId)}`, {
      method: 'PATCH',
      body: {
        starts_at: startDate.toISOString(),
        ends_at: endDate.toISOString(),
      },
    });
    publishAdminSync('calls', 'call_schedule_updated');
    await loadCallsCalendar({ background: true });
  } catch {
    if (typeof revert === 'function') revert();
  }
}

async function initCallsCalendar() {
  if (activePanel.value !== 'calls') return;
  if (!(callsCalendarEl.value instanceof HTMLElement)) return;
  if (calendarInstance && calendarRootEl !== callsCalendarEl.value) {
    calendarInstance.destroy();
    calendarInstance = null;
    calendarRootEl = null;
  }
  if (calendarInstance) return;

  try {
    calendarRootEl = callsCalendarEl.value;
    calendarInstance = new Calendar(callsCalendarEl.value, {
      plugins: [dayGridPlugin, timeGridPlugin, interactionPlugin],
      initialView: 'dayGridMonth',
      headerToolbar: {
        left: 'prev,next today',
        center: 'title',
        right: 'dayGridMonth,timeGridWeek,timeGridDay',
      },
      height: '100%',
      expandRows: true,
      eventTimeFormat: fullCalendarEventTimeFormat(sessionState.timeFormat),
      selectable: true,
      selectMirror: true,
      selectMinDistance: 1,
      editable: true,
      eventStartEditable: true,
      eventDurationEditable: true,
      eventResizableFromStart: true,
      events: [],
      dateClick(info) {
        const now = Date.now();
        const dateKey = `${String(info.view?.type || '')}:${info.dateStr}`;
        const isDoubleClick = dateKey === lastCalendarDateKey && now - lastCalendarDateClickAt < 360;
        lastCalendarDateKey = dateKey;
        lastCalendarDateClickAt = now;
        if (isDoubleClick) {
          openScheduleForWindow(info.date instanceof Date ? info.date : new Date(info.dateStr), null);
        }
      },
      select(info) {
        const viewType = String(info.view?.type || '');
        if (!viewType.startsWith('timeGrid')) {
          calendarInstance?.unselect();
          return;
        }
        openScheduleForWindow(info.start, info.end);
        calendarInstance?.unselect();
      },
      eventClick(info) {
        openScheduleForWindow(info.event.start, info.event.end, resolveCalendarEventCall(info.event));
      },
      eventDrop(info) {
        void persistCalendarEventWindow(info.event, typeof info.revert === 'function' ? info.revert : null);
      },
      eventResize(info) {
        void persistCalendarEventWindow(info.event, typeof info.revert === 'function' ? info.revert : null);
      },
    });
    calendarInstance.render();
    syncCalendarEvents();
  } catch {
    calendarInstance = null;
    calendarRootEl = null;
    callsState.error = 'Could not load FullCalendar.';
  }
}

async function ensureCalendarUiReady() {
  if (activePanel.value !== 'calls' || callsState.error) return;
  await nextTick();
  await initCallsCalendar();
  if (!calendarInstance) return;
  await nextTick();
  calendarInstance.updateSize();
  syncCalendarEvents();
}

function clearAdminSyncReloadTimer() {
  if (adminSyncReloadTimer > 0) {
    window.clearTimeout(adminSyncReloadTimer);
    adminSyncReloadTimer = 0;
  }
}

function queueAdminSyncReload(topic) {
  if (adminSyncReloadTimer > 0) return;
  adminSyncReloadTimer = window.setTimeout(() => {
    adminSyncReloadTimer = 0;
    const normalizedTopic = String(topic || '').toLowerCase();
    if (normalizedTopic === 'calendar' || normalizedTopic === 'all') {
      void loadRows({ background: true });
    }
    if (normalizedTopic === 'calls' || normalizedTopic === 'all') {
      void loadCallsCalendar({ background: true });
    }
  }, 120);
}

function publishAdminSync(topic, reason) {
  if (!adminSyncClient) return;
  adminSyncClient.publish(topic, reason);
}

function handleAdminSyncEvent(payload) {
  const sourceSessionId = String(payload?.source_session_id || '').trim();
  const ownSessionId = String(sessionState.sessionId || sessionState.sessionToken || '').trim();
  if (sourceSessionId !== '' && sourceSessionId === ownSessionId) return;
  const topic = String(payload?.topic || '').trim().toLowerCase();
  if (!['all', 'calls', 'calendar'].includes(topic)) return;
  queueAdminSyncReload(topic);
}

function startAdminSyncSocket() {
  if (adminSyncClient) adminSyncClient.disconnect();
  adminSyncClient = createAdminSyncSocket({
    getSessionToken: () => String(sessionState.sessionToken || '').trim(),
    onSync: handleAdminSyncEvent,
  });
  adminSyncClient.connect();
}

function stopAdminSyncSocket() {
  if (!adminSyncClient) return;
  adminSyncClient.disconnect();
  adminSyncClient = null;
}

watch(activePanel, () => {
  void ensureCalendarUiReady();
});

watch(activeCalendarId, () => {
  syncCalendarEvents();
  void ensureCalendarUiReady();
});

watch(calendarCalls, () => {
  syncCalendarEvents();
  void ensureCalendarUiReady();
});

watch(
  () => sessionState.timeFormat,
  () => {
    if (!calendarInstance) return;
    calendarInstance.setOption('eventTimeFormat', fullCalendarEventTimeFormat(sessionState.timeFormat));
  }
);

onMounted(async () => {
  startAdminSyncSocket();
  await Promise.all([loadRows(), loadCallsCalendar()]);
  void ensureCalendarUiReady();
});

onBeforeUnmount(() => {
  clearAdminSyncReloadTimer();
  stopAdminSyncSocket();
  if (calendarInstance) {
    calendarInstance.destroy();
    calendarInstance = null;
    calendarRootEl = null;
  }
});
</script>

<style scoped>
.calendar-view {
  position: relative;
  height: 100%;
  min-height: 0;
  display: flex;
  flex-direction: column;
  gap: 20px;
  overflow: hidden;
  background: var(--bg-ui-chrome);
  padding: 0 20px 20px;
}

.calendar-header,
.calendar-content-header,
.calendar-header-actions,
.calendar-inner-tabs,
.workspace-calendar-tabs,
.calendar-directory-search,
.calendar-sidebar-actions {
  display: flex;
  align-items: center;
  gap: 20px;
}

.calendar-header,
.calendar-content-header {
  justify-content: space-between;
  flex: 0 0 auto;
}

.calendar-title h1 {
  margin: 0;
  font-size: 14px;
  font-weight: 700;
}

.calendar-title p,
.calendar-active-summary span,
.calendar-settings-block header span,
.calendar-member-row small,
.calendar-directory-row small,
.calendar-empty-inline {
  margin: 4px 0 0;
  color: var(--text-muted);
  font-size: 12px;
}

.calendar-header-actions {
  margin-left: auto;
}

.calendar-banner,
.calendar-state,
.calendar-inline-loading {
  flex: 0 0 auto;
  color: var(--text-muted);
  font-size: 12px;
}

.calendar-banner {
  padding: 10px 12px;
}

.calendar-banner.ok {
  background: var(--color-success);
  color: var(--color-text-primary);
}

.calendar-banner.error,
.calendar-state.error {
  color: var(--color-heading);
  background: var(--color-surface-navy);
}

.workspace-calendar-tabs {
  flex: 0 0 auto;
  overflow-x: auto;
  padding-bottom: 2px;
}

.workspace-calendar-tab {
  min-width: 150px;
  height: 40px;
  display: inline-flex;
  align-items: center;
  gap: 10px;
  padding: 0 14px;
  border: 1px solid var(--color-border);
  background: var(--color-surface-navy);
  color: var(--color-text-primary);
  font: inherit;
  cursor: pointer;
}

.workspace-calendar-tab.active {
  border-color: var(--calendar-color);
  box-shadow: inset 0 -3px 0 var(--calendar-color);
}

.calendar-color-dot {
  width: 12px;
  height: 12px;
  flex: 0 0 12px;
  border-radius: 999px;
  background: var(--calendar-color, var(--brand-cyan));
  box-shadow: inset 0 0 0 1px var(--color-border);
}

.calendar-content {
  min-height: 0;
  flex: 1 1 auto;
  display: flex;
  flex-direction: column;
  gap: 14px;
  overflow: hidden;
}

.calendar-active-summary strong {
  display: block;
  color: var(--text-main);
}

.calendar-inner-tabs {
  flex: 0 0 auto;
  background: var(--color-border);
  gap: 1px;
}

.calendar-inner-tabs .tab {
  min-width: 150px;
  height: 40px;
}

.calendar-panel {
  min-height: 0;
  flex: 1 1 auto;
  overflow: auto;
  overscroll-behavior: contain;
  -webkit-overflow-scrolling: touch;
}

.calendar-panel-calls {
  position: relative;
  display: grid;
  grid-template-rows: minmax(0, 1fr);
}

.calendar-inline-loading {
  position: absolute;
  z-index: 2;
  top: 18px;
  right: 18px;
}

.workspace-fullcalendar {
  min-height: 760px;
  height: 100%;
  border: 1px solid var(--color-border);
  background: var(--color-surface-navy);
  padding: 10px;
  overflow: hidden;
}

.workspace-fullcalendar :deep(.fc),
.workspace-fullcalendar :deep(.fc-view-harness),
.workspace-fullcalendar :deep(.fc-view-harness-active) {
  width: 100%;
  min-height: 0;
  height: 100% !important;
}

.workspace-fullcalendar :deep(.fc-timegrid-slot) {
  height: 3em;
}

.workspace-fullcalendar :deep(.fc-timegrid-col:not(:first-child)),
.workspace-fullcalendar :deep(.fc-col-header-cell:not(:first-child)) {
  box-shadow: inset 1px 0 0 var(--color-border);
}

.calendar-panel-personal {
  display: grid;
  grid-template-rows: minmax(0, 1fr);
}

.calendar-panel-personal :deep(.appointment-config-panel) {
  min-height: 760px;
}

.calendar-sidebar {
  position: absolute;
  top: 0;
  right: 0;
  bottom: 0;
  z-index: 20;
  width: min(460px, 42vw);
  min-width: 380px;
  display: flex;
  flex-direction: column;
  min-height: 0;
  border-left: 1px solid var(--color-border);
  background: var(--color-surface-navy);
}

.calendar-sidebar-head,
.calendar-sidebar-actions {
  flex: 0 0 auto;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 20px;
  padding: 20px;
}

.calendar-sidebar-body {
  min-height: 0;
  flex: 1 1 auto;
  display: flex;
  flex-direction: column;
  gap: 18px;
  padding: 0 20px 20px;
  overflow: auto;
}

.calendar-settings-block {
  display: flex;
  flex-direction: column;
  gap: 14px;
}

.calendar-settings-block header {
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.calendar-color-grid {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 10px;
}

.calendar-color-choice {
  height: 36px;
  border: 1px solid var(--color-border);
  background: var(--calendar-color);
  cursor: pointer;
}

.calendar-color-choice.selected {
  outline: 2px solid var(--color-cyan-hover);
  outline-offset: 2px;
}

.calendar-selected-members,
.calendar-directory-list {
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.calendar-member-row,
.calendar-directory-row,
.calendar-sync-row {
  display: flex;
  align-items: center;
  gap: 12px;
  min-height: 44px;
  border: 1px solid var(--color-border);
  background: var(--color-border);
  color: var(--text-main);
  padding: 8px 10px;
}

.calendar-member-row > span,
.calendar-directory-row > span:first-child {
  min-width: 0;
  flex: 1 1 auto;
}

.calendar-role-select {
  width: 110px;
}

.calendar-directory-search {
  justify-content: flex-end;
}

.calendar-directory-search .search-field {
  flex: 0 1 320px;
  max-width: 100%;
}

.calendar-directory-row {
  width: 100%;
  justify-content: space-between;
  text-align: left;
  font: inherit;
  cursor: pointer;
}

.calendar-directory-row:disabled {
  cursor: default;
  opacity: 0.72;
}

.calendar-sync-row input {
  flex: 0 0 auto;
}

.calendar-sidebar-actions {
  margin-top: auto;
  justify-content: flex-end;
  padding: 0;
}

.calendar-sidebar-actions .btn-danger {
  margin-right: auto;
}

@media (max-width: 980px) {
  .calendar-view {
    overflow: auto;
    padding-inline: 10px;
  }

  .calendar-header,
  .calendar-content-header {
    align-items: stretch;
    flex-direction: column;
  }

  .calendar-header-actions,
  .calendar-inner-tabs {
    justify-content: flex-end;
  }

  .calendar-content,
  .calendar-panel {
    overflow: visible;
  }

  .workspace-fullcalendar,
  .calendar-panel-personal :deep(.appointment-config-panel) {
    min-height: 640px;
  }

  .calendar-sidebar {
    position: fixed;
    inset: 0;
    width: auto;
    min-width: 0;
  }
}
</style>
