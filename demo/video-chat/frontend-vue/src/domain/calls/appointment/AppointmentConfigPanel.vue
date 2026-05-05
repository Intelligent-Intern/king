<template>
  <article ref="panelEl" class="appointment-config-panel">
    <section class="appointment-config-toolbar">
      <label class="field appointment-link-field">
        <span>{{ t('appointment_config.public_booking_link') }}</span>
        <input class="input" type="text" :value="publicBookingUrl" readonly />
      </label>
      <button class="btn" type="button" :disabled="publicBookingUrl === ''" @click="copyPublicLink">
        {{ t('common.copy') }}
      </button>
      <button
        class="btn appointment-slot-mode-toggle"
        type="button"
        :class="{ active: isRecurringSlotMode }"
        :disabled="state.saving || state.loading"
        @click="toggleSlotMode"
      >
        {{ isRecurringSlotMode ? t('appointment_config.recurring_slots') : t('appointment_config.only_selected_dates') }}
      </button>
      <button class="btn btn-cyan" type="button" :disabled="state.saving || state.loading" @click="openSettingsBeforeSave">
        {{ state.saving ? t('common.saving') : t('appointment_config.save_slots') }}
      </button>
    </section>

    <section class="appointment-config-mode-tabs" role="tablist" :aria-label="t('appointment_config.input_mode_aria')">
      <button
        class="tab"
        :class="{ active: state.inputMode === 'calendar' }"
        type="button"
        role="tab"
        :aria-selected="state.inputMode === 'calendar'"
        @click="setInputMode('calendar')"
      >
        {{ t('appointment_config.week_calendar') }}
      </button>
      <button
        class="tab"
        :class="{ active: state.inputMode === 'form' }"
        type="button"
        role="tab"
        :aria-selected="state.inputMode === 'form'"
        @click="setInputMode('form')"
      >
        {{ t('appointment_config.form') }}
      </button>
    </section>

    <section class="appointment-config-status" aria-live="polite">
      <section v-if="state.error" class="calls-inline-error">{{ state.error }}</section>
      <section v-if="state.loading" class="calls-inline-hint">{{ t('appointment_config.loading_slots') }}</section>
    </section>

    <section class="appointment-config-body">
      <section v-show="state.inputMode === 'calendar'" ref="calendarEl" class="appointment-config-calendar"></section>

      <AppointmentSlotRowsForm
        v-show="state.inputMode === 'form'"
        :rows="formRows"
        :saving="state.saving"
        @add-row="addFormRow"
        @remove-row="removeFormRow"
      />
    </section>

    <p v-if="state.notice" class="appointment-config-notice">{{ state.notice }}</p>
    <AppointmentSettingsModal
      :open="state.settingsOpen"
      :saving="state.saving"
      :settings="settings"
      @close="state.settingsOpen = false"
      @confirm="confirmSettingsAndSave"
    />
  </article>
</template>

<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref } from 'vue';
import { Calendar } from '@fullcalendar/core';
import timeGridPlugin from '@fullcalendar/timegrid';
import interactionPlugin from '@fullcalendar/interaction';
import { loadAppointmentBlocks, saveAppointmentBlocks } from './appointmentCalendarApi';
import AppointmentSettingsModal from './AppointmentSettingsModal.vue';
import AppointmentSlotRowsForm from './AppointmentSlotRowsForm.vue';
import { compareDateTimeStrings } from '../../../support/dateTimeFormat';
import { t } from '../../../modules/localization/i18nRuntime.js';

const emit = defineEmits(['saved']);
const panelEl = ref(null);
const calendarEl = ref(null);
const formRows = reactive([]);
const copiedDayTemplate = ref(null);
const state = reactive({
  loading: false,
  saving: false,
  error: '',
  notice: '',
  publicPath: '',
  inputMode: 'calendar',
  settingsOpen: false,
});
const settings = reactive({
  slot_minutes: 15,
  slot_mode: 'selected_dates',
  invitation_text: '',
});

let calendarInstance = null;
let headerRefreshTimer = null;
let resizeObserver = null;
let resizeFrame = 0;

const publicBookingUrl = computed(() => {
  const path = String(state.publicPath || '').trim();
  if (path === '') return '';
  if (typeof window === 'undefined') return path;
  return `${window.location.origin}${path}`;
});
const isRecurringSlotMode = computed(() => settings.slot_mode === 'recurring_weekly');

function normalizeSlotMode(value) {
  const mode = String(value || '').trim();
  return mode === 'recurring_weekly' ? 'recurring_weekly' : 'selected_dates';
}

function blockToEvent(block) {
  const booked = Boolean(block?.booked);
  return {
    id: String(block?.id || `slot-${Date.now()}-${Math.random().toString(16).slice(2)}`),
    title: booked ? t('appointment_config.booked_call') : t('appointment_config.call_slot'),
    start: new Date(String(block?.starts_at || '')),
    end: new Date(String(block?.ends_at || '')),
    editable: !booked,
    durationEditable: !booked,
    startEditable: !booked,
    backgroundColor: booked ? '#52627a' : '#0e8fb8',
    borderColor: booked ? '#6c7b92' : '#4fd7ff',
    extendedProps: {
      booked,
      timezone: String(block?.timezone || 'UTC'),
    },
  };
}

function dateToLocalDateValue(date) {
  if (!(date instanceof Date) || Number.isNaN(date.getTime())) return '';
  const year = date.getFullYear();
  const month = String(date.getMonth() + 1).padStart(2, '0');
  const day = String(date.getDate()).padStart(2, '0');
  return `${year}-${month}-${day}`;
}

function dateToLocalTimeValue(date) {
  if (!(date instanceof Date) || Number.isNaN(date.getTime())) return '';
  const hour = String(date.getHours()).padStart(2, '0');
  const minute = String(date.getMinutes()).padStart(2, '0');
  return `${hour}:${minute}`;
}

function eventToBlock(eventApi) {
  return {
    starts_at: eventApi.start instanceof Date ? eventApi.start.toISOString() : '',
    ends_at: eventApi.end instanceof Date ? eventApi.end.toISOString() : '',
    timezone: Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC',
  };
}

function slotMinutesToDuration(minutes) {
  const normalized = [5, 10, 15, 20, 30, 45, 60].includes(Number(minutes)) ? Number(minutes) : 15;
  return `00:${String(normalized).padStart(2, '0')}:00`;
}

function applySettings(nextSettings) {
  const minutes = Number.parseInt(String(nextSettings?.slot_minutes || 15), 10);
  settings.slot_minutes = [5, 10, 15, 20, 30, 45, 60].includes(minutes) ? minutes : 15;
  if (Object.prototype.hasOwnProperty.call(nextSettings || {}, 'slot_mode')) {
    settings.slot_mode = normalizeSlotMode(nextSettings.slot_mode);
  }
  settings.invitation_text = String(nextSettings?.invitation_text || '');
  calendarInstance?.setOption('slotDuration', slotMinutesToDuration(settings.slot_minutes));
  calendarInstance?.setOption('snapDuration', slotMinutesToDuration(settings.slot_minutes));
  scheduleCalendarSizeUpdate(2);
}

function toggleSlotMode() {
  settings.slot_mode = isRecurringSlotMode.value ? 'selected_dates' : 'recurring_weekly';
  state.notice = settings.slot_mode === 'recurring_weekly'
    ? t('appointment_config.recurring_notice')
    : t('appointment_config.selected_dates_notice');
}

function createFormRow(seed = {}) {
  return {
    rowId: String(seed.rowId || `form-${Date.now()}-${Math.random().toString(16).slice(2)}`),
    date: String(seed.date || ''),
    startTime: String(seed.startTime || ''),
    endTime: String(seed.endTime || ''),
    booked: Boolean(seed.booked),
  };
}

function blockToFormRow(block) {
  const start = new Date(String(block?.starts_at || ''));
  const end = new Date(String(block?.ends_at || ''));
  if (Number.isNaN(start.getTime()) || Number.isNaN(end.getTime())) return null;
  return createFormRow({
    date: dateToLocalDateValue(start),
    startTime: dateToLocalTimeValue(start),
    endTime: dateToLocalTimeValue(end),
    booked: Boolean(block?.booked),
  });
}

function eventToFormRow(eventApi) {
  return blockToFormRow({
    starts_at: eventApi?.start instanceof Date ? eventApi.start.toISOString() : '',
    ends_at: eventApi?.end instanceof Date ? eventApi.end.toISOString() : '',
    booked: Boolean(eventApi?.extendedProps?.booked),
  });
}

function setFormRows(rows) {
  const nextRows = rows.filter(Boolean);
  formRows.splice(0, formRows.length, ...nextRows);
  if (formRows.length === 0) {
    formRows.push(createFormRow());
  }
}

function syncFormRowsFromBlocks(blocks) {
  const rows = Array.isArray(blocks) ? blocks.map(blockToFormRow) : [];
  setFormRows(rows);
}

function syncFormRowsFromEvents() {
  if (!calendarInstance) return;
  const rows = calendarInstance.getEvents()
    .sort((left, right) => {
      const leftTime = left.start instanceof Date ? left.start.getTime() : 0;
      const rightTime = right.start instanceof Date ? right.start.getTime() : 0;
      return leftTime - rightTime;
    })
    .map(eventToFormRow);
  setFormRows(rows);
}

function addFormRow() {
  formRows.push(createFormRow());
}

function removeFormRow(rowId) {
  const index = formRows.findIndex((row) => row.rowId === rowId);
  if (index >= 0) {
    formRows.splice(index, 1);
  }
  if (formRows.length === 0) {
    addFormRow();
  }
}

function localDateAndTimeToDate(dateValue, timeValue) {
  const date = String(dateValue || '').trim();
  const time = String(timeValue || '').trim();
  if (date === '' || time === '') return null;
  const parsed = new Date(`${date}T${time}`);
  if (Number.isNaN(parsed.getTime())) return null;
  return parsed;
}

function formRowsToBlocks({ includeBooked = false } = {}) {
  const blocks = [];
  for (const row of formRows) {
    if (row.booked && !includeBooked) continue;

    const hasAnyValue = String(row.date || '').trim() !== ''
      || String(row.startTime || '').trim() !== ''
      || String(row.endTime || '').trim() !== '';
    if (!hasAnyValue) continue;

    const start = localDateAndTimeToDate(row.date, row.startTime);
    const end = localDateAndTimeToDate(row.date, row.endTime);
    if (!start || !end) {
      throw new Error(t('appointment_config.row_required'));
    }
    if (end.getTime() <= start.getTime()) {
      throw new Error(t('appointment_config.row_end_after_start'));
    }

    blocks.push({
      starts_at: start.toISOString(),
      ends_at: end.toISOString(),
      timezone: Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC',
      booked: Boolean(row.booked),
    });
  }

  return blocks.sort((left, right) => compareDateTimeStrings(left.starts_at, right.starts_at));
}

function syncCalendarFromFormRows() {
  if (!calendarInstance) return;
  const blocks = formRowsToBlocks({ includeBooked: true });
  syncEvents(blocks);
}

function scheduleCalendarSizeUpdate(retries = 2) {
  if (typeof window === 'undefined' || resizeFrame !== 0) return;
  resizeFrame = window.requestAnimationFrame(() => {
    resizeFrame = 0;
    calendarInstance?.updateSize();
    if (retries > 0) {
      scheduleCalendarSizeUpdate(retries - 1);
    }
  });
}

function setInputMode(nextMode) {
  if (nextMode !== 'calendar' && nextMode !== 'form') return;
  if (state.inputMode === nextMode) return;

  state.error = '';
  if (state.inputMode === 'calendar' && nextMode === 'form') {
    syncFormRowsFromEvents();
  }
  if (state.inputMode === 'form' && nextMode === 'calendar') {
    try {
      syncCalendarFromFormRows();
    } catch (error) {
      state.error = error instanceof Error ? error.message : t('appointment_config.read_form_rows_failed');
      return;
    }
  }

  state.inputMode = nextMode;
  if (nextMode === 'calendar') {
    void nextTick(() => scheduleCalendarSizeUpdate(3));
  }
}

function startOfLocalDay(dateValue) {
  const date = dateValue instanceof Date ? dateValue : new Date(dateValue);
  return new Date(date.getFullYear(), date.getMonth(), date.getDate());
}

function eventStartsOnDay(eventApi, dateValue) {
  if (!(eventApi?.start instanceof Date)) return false;
  const dayStart = startOfLocalDay(dateValue).getTime();
  const nextDayStart = dayStart + 24 * 60 * 60 * 1000;
  const eventStart = eventApi.start.getTime();
  return eventStart >= dayStart && eventStart < nextDayStart;
}

function editableSlotEventsForDay(dateValue) {
  if (!calendarInstance) return [];
  return calendarInstance.getEvents()
    .filter((eventApi) => !eventApi.extendedProps?.booked && eventStartsOnDay(eventApi, dateValue))
    .sort((left, right) => left.start.getTime() - right.start.getTime());
}

function dayHasAnySlots(dateValue) {
  if (!calendarInstance) return false;
  return calendarInstance.getEvents().some((eventApi) => eventStartsOnDay(eventApi, dateValue));
}

function scheduleDayHeaderRefresh() {
  if (!calendarInstance || headerRefreshTimer !== null) return;
  headerRefreshTimer = setTimeout(() => {
    headerRefreshTimer = null;
    calendarInstance?.setOption('dayHeaderContent', (arg) => renderDayHeader(arg));
  }, 0);
}

function copyDaySlots(dateValue) {
  const dayStart = startOfLocalDay(dateValue).getTime();
  const ranges = editableSlotEventsForDay(dateValue)
    .map((eventApi) => {
      const start = eventApi.start instanceof Date ? eventApi.start.getTime() : 0;
      const end = eventApi.end instanceof Date ? eventApi.end.getTime() : start;
      return {
        startOffsetMs: start - dayStart,
        endOffsetMs: end - dayStart,
      };
    })
    .filter((range) => range.endOffsetMs > range.startOffsetMs);

  if (ranges.length < 1) return;
  copiedDayTemplate.value = { ranges };
  state.notice = t('common.copied');
  scheduleDayHeaderRefresh();
}

function insertCopiedSlots(dateValue) {
  const template = copiedDayTemplate.value;
  if (!template?.ranges?.length || !calendarInstance || dayHasAnySlots(dateValue)) return;

  const dayStart = startOfLocalDay(dateValue).getTime();
  for (const range of template.ranges) {
    const start = new Date(dayStart + range.startOffsetMs);
    const end = new Date(dayStart + range.endOffsetMs);
    if (Number.isNaN(start.getTime()) || Number.isNaN(end.getTime()) || end.getTime() <= start.getTime()) {
      continue;
    }
    calendarInstance.addEvent({
      id: `draft-${Date.now()}-${Math.random().toString(16).slice(2)}`,
      title: t('appointment_config.call_slot'),
      start,
      end,
      editable: true,
      backgroundColor: '#0e8fb8',
      borderColor: '#4fd7ff',
      extendedProps: { booked: false },
    });
  }
  state.notice = t('appointment_config.inserted');
  scheduleDayHeaderRefresh();
}

function renderDayHeader(arg) {
  const wrapper = document.createElement('div');
  wrapper.className = 'appointment-day-header';

  const label = document.createElement('span');
  label.className = 'appointment-day-label';
  label.textContent = String(arg?.text || '');
  wrapper.append(label);

  const date = arg?.date instanceof Date ? arg.date : null;
  if (!date) return { domNodes: [wrapper] };

  const editableSlots = editableSlotEventsForDay(date);
  const hasAnySlots = dayHasAnySlots(date);
  const canInsert = copiedDayTemplate.value?.ranges?.length > 0 && !hasAnySlots;
  if (editableSlots.length > 0 || canInsert) {
    const button = document.createElement('button');
    button.type = 'button';
    button.className = `appointment-day-action ${editableSlots.length > 0 ? 'copy' : 'insert'}`;
    button.textContent = editableSlots.length > 0 ? t('appointment_config.copy_this') : t('appointment_config.insert');
    button.addEventListener('click', (event) => {
      event.preventDefault();
      event.stopPropagation();
      if (editableSlots.length > 0) {
        copyDaySlots(date);
        return;
      }
      insertCopiedSlots(date);
    });
    wrapper.append(button);
  }

  return { domNodes: [wrapper] };
}

function syncEvents(blocks) {
  if (!calendarInstance) return;
  calendarInstance.removeAllEvents();
  for (const block of blocks) {
    const event = blockToEvent(block);
    if (!Number.isNaN(event.start.getTime()) && !Number.isNaN(event.end.getTime())) {
      calendarInstance.addEvent(event);
    }
  }
  scheduleDayHeaderRefresh();
  scheduleCalendarSizeUpdate();
}

async function ensureCalendar() {
  if (calendarInstance || !(calendarEl.value instanceof HTMLElement)) return;
  calendarInstance = new Calendar(calendarEl.value, {
    plugins: [timeGridPlugin, interactionPlugin],
    initialView: 'timeGridWeek',
    headerToolbar: {
      left: 'prev,next today',
      center: 'title',
      right: '',
    },
    height: '100%',
    expandRows: true,
    allDaySlot: false,
    selectable: true,
    selectMirror: true,
    editable: true,
    eventResizableFromStart: true,
    firstDay: 1,
    slotDuration: slotMinutesToDuration(settings.slot_minutes),
    snapDuration: slotMinutesToDuration(settings.slot_minutes),
    dayHeaderContent: (arg) => renderDayHeader(arg),
    select(info) {
      const start = info.start instanceof Date ? info.start : null;
      const end = info.end instanceof Date ? info.end : null;
      if (!start || !end || end.getTime() <= start.getTime()) return;
      calendarInstance.addEvent({
        id: `draft-${Date.now()}-${Math.random().toString(16).slice(2)}`,
        title: t('appointment_config.call_slot'),
        start,
        end,
        editable: true,
        backgroundColor: '#0e8fb8',
        borderColor: '#4fd7ff',
        extendedProps: { booked: false },
      });
      calendarInstance.unselect();
      scheduleDayHeaderRefresh();
    },
    eventClick(info) {
      if (info.event.extendedProps?.booked) return;
      info.event.remove();
      scheduleDayHeaderRefresh();
    },
    eventAdd: scheduleDayHeaderRefresh,
    eventChange: scheduleDayHeaderRefresh,
    eventRemove: scheduleDayHeaderRefresh,
  });
  calendarInstance.render();
  scheduleCalendarSizeUpdate(3);
}

async function load() {
  state.loading = true;
  state.error = '';
  state.notice = '';
  try {
    await nextTick();
    await ensureCalendar();
    const result = await loadAppointmentBlocks();
    state.publicPath = String(result.public_path || '');
    applySettings(result.settings || {});
    syncEvents(Array.isArray(result.blocks) ? result.blocks : []);
    syncFormRowsFromBlocks(Array.isArray(result.blocks) ? result.blocks : []);
    await nextTick();
    scheduleCalendarSizeUpdate(3);
  } catch (error) {
    state.error = error instanceof Error ? error.message : t('appointment_config.load_slots_failed');
  } finally {
    state.loading = false;
  }
}

function openSettingsBeforeSave() {
  state.settingsOpen = true;
}

async function save() {
  if (!calendarInstance || state.saving) return;
  state.saving = true;
  state.error = '';
  state.notice = '';
  try {
    const blocks = state.inputMode === 'form'
      ? formRowsToBlocks()
      : calendarInstance.getEvents()
        .filter((eventApi) => !eventApi.extendedProps?.booked)
        .map(eventToBlock)
        .filter((block) => block.starts_at && block.ends_at);
    const result = await saveAppointmentBlocks(blocks, settings);
    state.publicPath = String(result.public_path || state.publicPath || '');
    applySettings(result.settings || settings);
    syncEvents(Array.isArray(result.blocks) ? result.blocks : []);
    syncFormRowsFromBlocks(Array.isArray(result.blocks) ? result.blocks : []);
    state.notice = t('common.saved');
    state.settingsOpen = false;
    emit('saved');
  } catch (error) {
    state.error = error instanceof Error ? error.message : t('appointment_config.save_slots_failed');
  } finally {
    state.saving = false;
  }
}

async function confirmSettingsAndSave(nextSettings) {
  applySettings(nextSettings);
  await save();
}

async function copyPublicLink() {
  if (publicBookingUrl.value === '' || typeof navigator === 'undefined' || !navigator.clipboard) return;
  try {
    await navigator.clipboard.writeText(publicBookingUrl.value);
    state.notice = t('common.copied');
  } catch {
    state.notice = '';
  }
}

onMounted(() => {
  if (typeof ResizeObserver !== 'undefined') {
    resizeObserver = new ResizeObserver(() => scheduleCalendarSizeUpdate(3));
    void nextTick(() => {
      if (panelEl.value instanceof HTMLElement) resizeObserver?.observe(panelEl.value);
      if (calendarEl.value instanceof HTMLElement) resizeObserver?.observe(calendarEl.value);
    });
  }
  void load();
});

onBeforeUnmount(() => {
  if (resizeObserver) {
    resizeObserver.disconnect();
    resizeObserver = null;
  }
  if (resizeFrame !== 0 && typeof window !== 'undefined') {
    window.cancelAnimationFrame(resizeFrame);
    resizeFrame = 0;
  }
  if (headerRefreshTimer !== null) {
    clearTimeout(headerRefreshTimer);
    headerRefreshTimer = null;
  }
  if (calendarInstance) {
    calendarInstance.destroy();
    calendarInstance = null;
  }
});
</script>

<style scoped>
.appointment-config-panel {
  width: 100%;
  min-width: 0;
  min-height: 0;
  height: 100%;
  display: grid;
  grid-template-rows: auto auto auto minmax(0, 1fr) auto;
  gap: 10px;
  overflow: hidden;
}

.appointment-config-toolbar {
  display: grid;
  grid-template-columns: minmax(0, 1fr) auto auto auto;
  gap: 8px;
  align-items: end;
}

.appointment-link-field {
  min-width: 0;
}

.appointment-slot-mode-toggle.active {
  border-color: var(--color-4fd7ff);
  background: var(--color-0f3a52);
}

.appointment-config-mode-tabs {
  display: inline-grid;
  grid-template-columns: repeat(2, minmax(0, 160px));
  justify-content: start;
  gap: 1px;
  background: var(--border-subtle);
  width: fit-content;
  max-width: 100%;
}

.appointment-config-mode-tabs .tab {
  min-width: 0;
  height: 36px;
}

.appointment-config-status {
  min-height: 0;
  display: grid;
  gap: 8px;
}

.appointment-config-status:empty {
  display: block;
}

.appointment-config-body {
  min-width: 0;
  min-height: 0;
  height: 100%;
  display: grid;
  grid-template-rows: minmax(0, 1fr);
}

.appointment-config-body > * {
  grid-area: 1 / 1;
  min-width: 0;
  min-height: 0;
}

.appointment-config-calendar {
  width: 100%;
  min-width: 0;
  min-height: 0;
  height: 100%;
  border: 1px solid var(--border-subtle);
  border-radius: 6px;
  background: var(--bg-surface-strong);
  padding: 8px;
  overflow: hidden;
}

.appointment-config-calendar :deep(.fc),
.appointment-config-calendar :deep(.fc-view-harness),
.appointment-config-calendar :deep(.fc-view-harness-active) {
  width: 100%;
  min-height: 0;
  height: 100% !important;
}

.appointment-config-calendar :deep(.fc-timegrid-slot) {
  height: 3em;
}

.appointment-config-calendar :deep(.fc-timegrid-col:not(:first-child)),
.appointment-config-calendar :deep(.fc-col-header-cell:not(:first-child)) {
  box-shadow: inset 1px 0 0 var(--border-subtle);
}

.calls-inline-error {
  border: 1px solid var(--color-6b1f1f);
  border-radius: 6px;
  background: var(--color-331616);
  color: var(--color-ffb5b5);
  font-size: 12px;
  padding: 8px 10px;
}

.calls-inline-hint {
  margin: 0;
  border: 1px solid var(--border-subtle);
  border-radius: 6px;
  background: var(--color-132745);
  color: var(--text-muted);
  font-size: 12px;
  padding: 8px 10px;
}

.appointment-config-notice {
  margin: 0;
  color: var(--text-muted);
  font-size: 12px;
}

:deep(.appointment-day-header) {
  min-height: 44px;
  display: grid;
  gap: 4px;
  justify-items: center;
  align-content: center;
}

:deep(.appointment-day-label) {
  font-size: 12px;
}

:deep(.appointment-day-action) {
  height: 24px;
  max-width: 100%;
  border: 1px solid var(--border-subtle);
  border-radius: 6px;
  background: var(--color-0f3a52);
  color: var(--color-ffffff);
  font-size: 11px;
  line-height: 1;
  padding: 0 8px;
  cursor: pointer;
}

:deep(.appointment-day-action.insert) {
  background: var(--color-1f4f31);
}

@media (max-width: 760px) {
  .appointment-config-toolbar {
    grid-template-columns: 1fr;
  }

  .appointment-config-mode-tabs {
    width: 100%;
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }

  :deep(.appointment-day-header) {
    min-height: 52px;
  }

  :deep(.appointment-day-action) {
    width: 100%;
    padding: 0 4px;
    font-size: 10px;
  }
}
</style>
