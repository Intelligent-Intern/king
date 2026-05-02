<template>
  <AppModalShell
    :open="open"
    title="Appointment Slots"
    aria-label="Appointment slot configuration"
    dialog-class="calls-modal-dialog appointment-config-dialog"
    body-class="calls-modal-body appointment-config-body"
    footer-class="calls-modal-footer appointment-config-footer"
    @close="close"
  >
    <template #body>
      <section v-if="state.error" class="calls-inline-error">{{ state.error }}</section>

      <section class="appointment-config-toolbar">
        <label class="field appointment-link-field">
          <span>Public booking link</span>
          <input class="input" type="text" :value="publicBookingUrl" readonly />
        </label>
        <button class="btn" type="button" :disabled="publicBookingUrl === ''" @click="copyPublicLink">
          Copy
        </button>
      </section>

      <section v-if="state.loading" class="calls-inline-hint">Loading slots...</section>
      <section v-show="!state.loading" ref="calendarEl" class="appointment-config-calendar"></section>
    </template>

    <template #footer>
      <p v-if="state.notice" class="appointment-config-notice">{{ state.notice }}</p>
      <button class="btn" type="button" :disabled="state.saving" @click="close">Cancel</button>
      <button class="btn btn-cyan" type="button" :disabled="state.saving || state.loading" @click="save">
        {{ state.saving ? 'Saving...' : 'Save slots' }}
      </button>
    </template>
  </AppModalShell>
</template>

<script setup>
import { computed, nextTick, onBeforeUnmount, reactive, ref, watch } from 'vue';
import { Calendar } from '@fullcalendar/core';
import timeGridPlugin from '@fullcalendar/timegrid';
import interactionPlugin from '@fullcalendar/interaction';
import AppModalShell from '../../../components/AppModalShell.vue';
import { loadAppointmentBlocks, saveAppointmentBlocks } from './appointmentCalendarApi';

const props = defineProps({
  open: {
    type: Boolean,
    default: false,
  },
});

const emit = defineEmits(['close', 'saved']);
const calendarEl = ref(null);
const state = reactive({
  loading: false,
  saving: false,
  error: '',
  notice: '',
  publicPath: '',
});

let calendarInstance = null;

const publicBookingUrl = computed(() => {
  const path = String(state.publicPath || '').trim();
  if (path === '') return '';
  if (typeof window === 'undefined') return path;
  return `${window.location.origin}${path}`;
});

function close() {
  emit('close');
}

function blockToEvent(block) {
  const booked = Boolean(block?.booked);
  return {
    id: String(block?.id || `slot-${Date.now()}-${Math.random().toString(16).slice(2)}`),
    title: booked ? 'Booked demo' : 'Demo slot',
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

function eventToBlock(eventApi) {
  return {
    starts_at: eventApi.start instanceof Date ? eventApi.start.toISOString() : '',
    ends_at: eventApi.end instanceof Date ? eventApi.end.toISOString() : '',
    timezone: Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC',
  };
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
}

async function ensureCalendar() {
  if (calendarInstance || !(calendarEl.value instanceof HTMLElement)) return;
  calendarInstance = new Calendar(calendarEl.value, {
    plugins: [timeGridPlugin, interactionPlugin],
    initialView: 'timeGridWeek',
    headerToolbar: {
      left: 'prev,next today',
      center: 'title',
      right: 'timeGridWeek,timeGridDay',
    },
    height: '100%',
    allDaySlot: false,
    selectable: true,
    editable: true,
    eventResizableFromStart: true,
    slotDuration: '00:15:00',
    snapDuration: '00:15:00',
    select(info) {
      const start = info.start instanceof Date ? info.start : null;
      const end = info.end instanceof Date ? info.end : null;
      if (!start || !end || end.getTime() <= start.getTime()) return;
      calendarInstance.addEvent({
        id: `draft-${Date.now()}-${Math.random().toString(16).slice(2)}`,
        title: 'Demo slot',
        start,
        end,
        editable: true,
        backgroundColor: '#0e8fb8',
        borderColor: '#4fd7ff',
        extendedProps: { booked: false },
      });
      calendarInstance.unselect();
    },
    eventClick(info) {
      if (info.event.extendedProps?.booked) return;
      info.event.remove();
    },
  });
  calendarInstance.render();
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
    syncEvents(Array.isArray(result.blocks) ? result.blocks : []);
    await nextTick();
    calendarInstance?.updateSize();
  } catch (error) {
    state.error = error instanceof Error ? error.message : 'Could not load appointment slots.';
  } finally {
    state.loading = false;
  }
}

async function save() {
  if (!calendarInstance || state.saving) return;
  state.saving = true;
  state.error = '';
  state.notice = '';
  try {
    const blocks = calendarInstance.getEvents()
      .filter((eventApi) => !eventApi.extendedProps?.booked)
      .map(eventToBlock)
      .filter((block) => block.starts_at && block.ends_at);
    const result = await saveAppointmentBlocks(blocks);
    state.publicPath = String(result.public_path || state.publicPath || '');
    syncEvents(Array.isArray(result.blocks) ? result.blocks : []);
    state.notice = 'Saved.';
    emit('saved');
  } catch (error) {
    state.error = error instanceof Error ? error.message : 'Could not save appointment slots.';
  } finally {
    state.saving = false;
  }
}

async function copyPublicLink() {
  if (publicBookingUrl.value === '' || typeof navigator === 'undefined' || !navigator.clipboard) return;
  try {
    await navigator.clipboard.writeText(publicBookingUrl.value);
    state.notice = 'Copied.';
  } catch {
    state.notice = '';
  }
}

watch(
  () => props.open,
  (nextOpen) => {
    if (nextOpen) {
      void load();
    }
  },
);

onBeforeUnmount(() => {
  if (calendarInstance) {
    calendarInstance.destroy();
    calendarInstance = null;
  }
});
</script>

<style scoped>
:deep(.appointment-config-dialog) {
  width: min(1120px, calc(100vw - 24px));
  height: min(820px, calc(100dvh - 24px));
  max-height: calc(100dvh - 24px);
  overflow: hidden;
  grid-template-rows: auto minmax(0, 1fr) auto;
}

:deep(.appointment-config-body) {
  min-height: 0;
  display: grid;
  grid-template-rows: auto auto minmax(0, 1fr);
  gap: 10px;
  overflow: hidden;
}

.appointment-config-toolbar {
  display: grid;
  grid-template-columns: minmax(0, 1fr) auto;
  gap: 8px;
  align-items: end;
}

.appointment-link-field {
  min-width: 0;
}

.appointment-config-calendar {
  min-height: 420px;
  height: 100%;
  border: 1px solid var(--border-subtle);
  border-radius: 6px;
  background: var(--bg-surface-strong);
  padding: 8px;
  overflow: hidden;
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

:deep(.appointment-config-footer) {
  align-items: center;
}

.appointment-config-notice {
  margin: 0 auto 0 0;
  color: var(--text-muted);
  font-size: 12px;
}

@media (max-width: 760px) {
  :deep(.appointment-config-dialog) {
    width: calc(100vw - 6px);
    height: calc(100dvh - 6px);
    max-height: calc(100dvh - 6px);
  }

  .appointment-config-toolbar {
    grid-template-columns: 1fr;
  }

  .appointment-config-calendar {
    min-height: 360px;
  }
}
</style>
