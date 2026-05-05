<template>
  <AppModalShell
    :open="open"
    title="Book a Video Call"
    aria-label="Public appointment booking"
    dialog-class="calls-modal-dialog appointment-booking-dialog"
    body-class="calls-modal-body appointment-booking-body"
    footer-class="calls-modal-footer appointment-booking-footer"
    @close="$emit('close')"
  >
    <template #body>
      <section v-if="state.error" class="calls-inline-error">{{ state.error }}</section>

      <section v-if="state.success" class="appointment-booking-success">
        <h2>Thank you.</h2>
        <p>Your video call is scheduled for {{ state.confirmedSlotLabel }}.</p>
        <div class="appointment-success-actions">
          <a v-if="bookingLinks.joinUrl" class="btn btn-cyan" :href="bookingLinks.joinUrl">Open video call</a>
          <a v-if="bookingLinks.googleUrl" class="btn" :href="bookingLinks.googleUrl" target="_blank" rel="noopener">
            Add to Google Calendar
          </a>
          <button v-if="bookingLinks.canDownloadIcs" class="btn" type="button" @click="downloadIcs">
            Download iCal
          </button>
        </div>
      </section>

      <section v-else class="appointment-booking-grid">
        <section class="appointment-booking-left" :class="{ 'has-invitation': invitationText }" aria-label="Available video call slots">
          <section v-if="invitationText" class="appointment-invitation-text">{{ invitationText }}</section>
          <section v-if="state.loading" class="calls-inline-hint">Loading slots...</section>
          <section v-show="!state.loading" ref="calendarEl" class="appointment-booking-calendar"></section>

          <div class="appointment-slot-list">
            <button
              v-for="slot in state.slots"
              :key="slot.id"
              class="appointment-slot-btn"
              :class="{ active: String(slot.id) === state.selectedSlotId }"
              type="button"
              @click="selectSlot(slot)"
            >
              {{ slotLabel(slot) }}
            </button>
            <p v-if="!state.loading && state.slots.length === 0" class="calls-inline-hint">
              No video call slots are currently available.
            </p>
          </div>
        </section>

        <form class="appointment-booking-form" novalidate @submit.prevent="submit">
          <section v-if="fieldError('slot_id')" class="appointment-field-error">{{ fieldError('slot_id') }}</section>
          <section class="appointment-selected-slot" :class="{ invalid: Boolean(fieldError('slot_id')) }">
            {{ selectedSlotLabel }}
          </section>

          <div class="appointment-form-row compact">
            <label class="field">
              <span>Salutation</span>
              <select v-model="form.salutation" class="input">
                <option value="">None</option>
                <option value="Mr.">Mr.</option>
                <option value="Ms.">Ms.</option>
                <option value="Mx.">Mx.</option>
                <option value="Dr.">Dr.</option>
              </select>
            </label>
            <label class="field">
              <span>Title</span>
              <input v-model.trim="form.title" class="input" type="text" autocomplete="honorific-prefix" />
            </label>
          </div>

          <div class="appointment-form-row">
            <label class="field">
              <span>First name</span>
              <span v-if="fieldError('first_name')" class="appointment-field-error">{{ fieldError('first_name') }}</span>
              <input
                v-model.trim="form.first_name"
                class="input"
                :class="{ invalid: Boolean(fieldError('first_name')) }"
                type="text"
                autocomplete="given-name"
              />
            </label>
            <label class="field">
              <span>Last name</span>
              <span v-if="fieldError('last_name')" class="appointment-field-error">{{ fieldError('last_name') }}</span>
              <input
                v-model.trim="form.last_name"
                class="input"
                :class="{ invalid: Boolean(fieldError('last_name')) }"
                type="text"
                autocomplete="family-name"
              />
            </label>
          </div>

          <label class="field">
            <span>Email</span>
            <span v-if="fieldError('email')" class="appointment-field-error">{{ fieldError('email') }}</span>
            <input
              v-model.trim="form.email"
              class="input"
              :class="{ invalid: Boolean(fieldError('email')) }"
              type="email"
              autocomplete="email"
            />
          </label>

          <label class="field">
            <span>Message</span>
            <textarea v-model.trim="form.message" class="calls-textarea appointment-message" rows="5"></textarea>
          </label>

          <section v-if="state.privacyOpen" class="appointment-privacy-overlay">
            <header>
              <h3>Privacy Policy</h3>
              <button class="icon-mini-btn" type="button" aria-label="Close privacy policy" @click="state.privacyOpen = false">
                <img src="/assets/orgas/kingrt/icons/cancel.png" alt="" />
              </button>
            </header>
            <div class="appointment-privacy-copy">
              <p>
                We process the details you submit to handle your video call booking, contact you,
                keep a lead list, and prepare the scheduled video call.
              </p>
              <p>
                The data can include your name, email address, organization context,
                message, selected call time, consent state, and technical server logs.
              </p>
              <p>
                Legal bases are pre-contractual measures and legitimate interest in
                handling booking requests. Data is deleted when it is no longer needed
                unless legal retention duties apply.
              </p>
              <p>
                You can request access, correction, deletion, restriction, portability,
                objection, or consent withdrawal by contacting kontakt@kingrt.com.
              </p>
            </div>
          </section>

          <label class="appointment-consent-row" :class="{ invalid: Boolean(fieldError('privacy_accepted')) }">
            <input v-model="form.privacy_accepted" type="checkbox" />
            <span>
              I have read and accept the
              <button class="appointment-link-button" type="button" @click="state.privacyOpen = true">privacy policy</button>.
            </span>
          </label>
          <span v-if="fieldError('privacy_accepted')" class="appointment-field-error">
            {{ fieldError('privacy_accepted') }}
          </span>
        </form>
      </section>
    </template>

    <template #footer>
      <button class="btn" type="button" :disabled="state.submitting" @click="$emit('close')">Close</button>
      <button
        v-if="!state.success"
        class="btn btn-cyan"
        type="button"
        :disabled="state.submitting || state.loading"
        @click="submit"
      >
        {{ state.submitting ? 'Submitting...' : 'Book video call' }}
      </button>
    </template>
  </AppModalShell>
</template>

<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { Calendar } from '@fullcalendar/core';
import timeGridPlugin from '@fullcalendar/timegrid';
import interactionPlugin from '@fullcalendar/interaction';
import AppModalShell from '../../../components/AppModalShell.vue';
import { bookPublicAppointment, loadPublicAppointmentSlots, toLocalSlotLabel } from './appointmentCalendarApi';
import { i18nState } from '../../../modules/localization/i18nRuntime.js';

const props = defineProps({
  open: {
    type: Boolean,
    default: false,
  },
  calendarId: {
    type: [Number, String],
    required: true,
  },
});

defineEmits(['close']);

const calendarEl = ref(null);
const form = reactive({
  salutation: '',
  title: '',
  first_name: '',
  last_name: '',
  email: '',
  message: '',
  privacy_accepted: false,
});
const state = reactive({
  loading: false,
  submitting: false,
  error: '',
  slots: [],
  selectedSlotId: '',
  showErrors: false,
  serverFields: {},
  success: false,
  joinPath: '',
  call: null,
  confirmedSlotLabel: '',
  privacyOpen: false,
  settings: {
    slot_minutes: 15,
    invitation_text: '',
  },
});

let calendarInstance = null;

function currentDocumentLocale() {
  if (typeof document === 'undefined') return '';
  return String(document.documentElement?.lang || '').trim();
}

const activeLocale = computed(() => String(i18nState.locale || currentDocumentLocale() || 'en').trim() || 'en');
const activeDirection = computed(() => (i18nState.direction === 'rtl' ? 'rtl' : 'ltr'));
const selectedSlot = computed(() => state.slots.find((slot) => String(slot.id) === state.selectedSlotId) || null);
const selectedSlotLabel = computed(() => (
  selectedSlot.value ? toLocalSlotLabel(selectedSlot.value, { locale: activeLocale.value }) : 'Select a video call slot'
));
const invitationText = computed(() => String(state.settings.invitation_text || '').trim());
const bookingLinks = computed(() => {
  const joinUrl = absoluteFrontendUrl(state.joinPath);
  const call = state.call && typeof state.call === 'object' ? state.call : {};
  const startsAt = new Date(String(call.starts_at || ''));
  const endsAt = new Date(String(call.ends_at || ''));
  if (!joinUrl || Number.isNaN(startsAt.getTime()) || Number.isNaN(endsAt.getTime())) {
    return { joinUrl, googleUrl: '', canDownloadIcs: false };
  }

  return {
    joinUrl,
    googleUrl: buildGoogleCalendarUrl(call, joinUrl),
    canDownloadIcs: true,
  };
});

function slotMinutesToDuration(minutes) {
  const normalized = [5, 10, 15, 20, 30, 45, 60].includes(Number(minutes)) ? Number(minutes) : 15;
  return `00:${String(normalized).padStart(2, '0')}:00`;
}

function applySettings(settings) {
  const minutes = Number.parseInt(String(settings?.slot_minutes || 15), 10);
  state.settings.slot_minutes = [5, 10, 15, 20, 30, 45, 60].includes(minutes) ? minutes : 15;
  state.settings.invitation_text = String(settings?.invitation_text || '');
  calendarInstance?.setOption('slotDuration', slotMinutesToDuration(state.settings.slot_minutes));
  calendarInstance?.setOption('snapDuration', slotMinutesToDuration(state.settings.slot_minutes));
}

function slotLabel(slot) {
  return toLocalSlotLabel(slot, { locale: activeLocale.value });
}

function localErrors() {
  const errors = {};
  if (!selectedSlot.value) {
    errors.slot_id = 'Please select a video call slot.';
  }
  if (String(form.first_name || '').trim() === '') {
    errors.first_name = 'First name is required.';
  }
  if (String(form.last_name || '').trim() === '') {
    errors.last_name = 'Last name is required.';
  }
  const email = String(form.email || '').trim();
  if (email === '') {
    errors.email = 'Email is required.';
  } else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
    errors.email = 'Enter a valid email address.';
  }
  if (!form.privacy_accepted) {
    errors.privacy_accepted = 'Privacy acceptance is required.';
  }
  return errors;
}

function fieldError(field) {
  if (!state.showErrors) return '';
  const errors = { ...localErrors(), ...state.serverFields };
  const value = errors[field];
  return typeof value === 'string' ? value : '';
}

function selectSlot(slot) {
  const slotId = String(slot?.id || '');
  if (slotId === '') return;
  state.selectedSlotId = slotId;
  state.serverFields = {};
  if (calendarInstance) {
    for (const eventApi of calendarInstance.getEvents()) {
      eventApi.setProp('classNames', String(eventApi.id) === slotId ? ['appointment-slot-event-active'] : []);
    }
  }
}

function syncCalendarSlots() {
  if (!calendarInstance) return;
  calendarInstance.removeAllEvents();
  for (const slot of state.slots) {
    calendarInstance.addEvent({
      id: String(slot.id || ''),
      title: 'Video call',
      start: new Date(String(slot.starts_at || '')),
      end: new Date(String(slot.ends_at || '')),
      backgroundColor: String(slot.id) === state.selectedSlotId ? '#4fd7ff' : '#0e8fb8',
      borderColor: '#4fd7ff',
    });
  }
}

async function ensureCalendar() {
  if (calendarInstance || !(calendarEl.value instanceof HTMLElement)) return;
  calendarInstance = new Calendar(calendarEl.value, {
    plugins: [timeGridPlugin, interactionPlugin],
    initialView: 'timeGridWeek',
    locale: activeLocale.value,
    direction: activeDirection.value,
    headerToolbar: {
      left: 'prev,next today',
      center: 'title',
      right: '',
    },
    height: '100%',
    allDaySlot: false,
    slotDuration: slotMinutesToDuration(state.settings.slot_minutes),
    snapDuration: slotMinutesToDuration(state.settings.slot_minutes),
    selectable: false,
    editable: false,
    eventClick(info) {
      const slot = state.slots.find((entry) => String(entry.id) === String(info.event.id));
      if (slot) selectSlot(slot);
    },
  });
  calendarInstance.render();
}

async function loadSlots() {
  state.loading = true;
  state.error = '';
  state.success = false;
  state.joinPath = '';
  state.call = null;
  try {
    await nextTick();
    await ensureCalendar();
    const result = await loadPublicAppointmentSlots(props.calendarId);
    applySettings(result.settings || {});
    state.slots = Array.isArray(result.slots) ? result.slots : [];
    if (!selectedSlot.value && state.slots.length > 0) {
      state.selectedSlotId = String(state.slots[0].id || '');
    }
    syncCalendarSlots();
    await nextTick();
    calendarInstance?.updateSize();
  } catch (error) {
    state.error = error instanceof Error ? error.message : 'Could not load video call slots.';
  } finally {
    state.loading = false;
  }
}

async function submit() {
  if (state.submitting || state.loading || state.success) return;
  state.showErrors = true;
  state.serverFields = {};
  const errors = localErrors();
  if (Object.keys(errors).length > 0) return;

  state.submitting = true;
  state.error = '';
  try {
    const result = await bookPublicAppointment(props.calendarId, {
      slot_id: state.selectedSlotId,
      salutation: form.salutation,
      title: form.title,
      first_name: form.first_name,
      last_name: form.last_name,
      email: form.email,
      message: form.message,
      privacy_accepted: form.privacy_accepted,
      locale: activeLocale.value,
    });
    const joinPath = String(result.join_path || result.booking?.join_path || '');
    const call = result.call && typeof result.call === 'object' ? result.call : null;
    const confirmedSlotLabel = selectedSlotLabel.value;
    await loadSlots();
    state.joinPath = joinPath;
    state.call = call;
    state.confirmedSlotLabel = confirmedSlotLabel;
    state.success = true;
  } catch (error) {
    state.serverFields = error?.fields && typeof error.fields === 'object' ? error.fields : {};
    state.error = error instanceof Error ? error.message : 'Could not book video call.';
  } finally {
    state.submitting = false;
  }
}

function absoluteFrontendUrl(path) {
  const value = String(path || '').trim();
  if (value === '') return '';
  if (/^https?:\/\//i.test(value)) return value;
  if (typeof window === 'undefined') return value;
  return `${window.location.origin}/${value.replace(/^\/+/, '')}`;
}

function calendarDateUtc(value) {
  const date = value instanceof Date ? value : new Date(String(value || ''));
  if (Number.isNaN(date.getTime())) return '';
  return date.toISOString().replace(/[-:]/g, '').replace(/\.\d{3}Z$/, 'Z');
}

function buildGoogleCalendarUrl(call, joinUrl) {
  const startsAt = calendarDateUtc(call?.starts_at);
  const endsAt = calendarDateUtc(call?.ends_at);
  if (!startsAt || !endsAt || !joinUrl) return '';
  const params = new URLSearchParams({
    action: 'TEMPLATE',
    text: String(call?.title || 'Video call'),
    dates: `${startsAt}/${endsAt}`,
    details: `Video call link:\n${joinUrl}`,
    location: joinUrl,
  });
  return `https://calendar.google.com/calendar/render?${params.toString()}`;
}

function escapeIcsText(value) {
  return String(value || '')
    .replace(/\\/g, '\\\\')
    .replace(/;/g, '\\;')
    .replace(/,/g, '\\,')
    .replace(/\r?\n/g, '\\n');
}

function buildIcsText() {
  const call = state.call && typeof state.call === 'object' ? state.call : {};
  const joinUrl = bookingLinks.value.joinUrl;
  const startsAt = calendarDateUtc(call.starts_at);
  const endsAt = calendarDateUtc(call.ends_at);
  if (!startsAt || !endsAt || !joinUrl) return '';
  return [
    'BEGIN:VCALENDAR',
    'VERSION:2.0',
    'PRODID:-//KingRT//Video Call Booking//EN',
    'BEGIN:VEVENT',
    `UID:${escapeIcsText(String(call.id || (typeof crypto !== 'undefined' && crypto.randomUUID ? crypto.randomUUID() : Date.now())))}@kingrt`,
    `DTSTAMP:${calendarDateUtc(new Date())}`,
    `DTSTART:${startsAt}`,
    `DTEND:${endsAt}`,
    `SUMMARY:${escapeIcsText(String(call.title || 'Video call'))}`,
    `DESCRIPTION:${escapeIcsText(`Video call link:\n${joinUrl}`)}`,
    `LOCATION:${escapeIcsText(joinUrl)}`,
    'END:VEVENT',
    'END:VCALENDAR',
    '',
  ].join('\r\n');
}

function downloadIcs() {
  const ics = buildIcsText();
  if (!ics || typeof document === 'undefined') return;
  const blob = new Blob([ics], { type: 'text/calendar;charset=utf-8' });
  const url = URL.createObjectURL(blob);
  const link = document.createElement('a');
  link.href = url;
  link.download = 'video-call.ics';
  document.body.append(link);
  link.click();
  link.remove();
  URL.revokeObjectURL(url);
}

watch(
  () => props.open,
  (nextOpen) => {
    if (nextOpen) {
      void loadSlots();
    }
  },
);

watch([activeLocale, activeDirection], ([locale, direction]) => {
  calendarInstance?.setOption('locale', locale);
  calendarInstance?.setOption('direction', direction);
  syncCalendarSlots();
});

onMounted(() => {
  if (props.open) {
    void loadSlots();
  }
});

onBeforeUnmount(() => {
  if (calendarInstance) {
    calendarInstance.destroy();
    calendarInstance = null;
  }
});
</script>

<style scoped>
:deep(.appointment-booking-dialog) {
  width: min(1180px, calc(100vw - 24px));
  height: min(960px, calc(100dvh - 24px));
  max-height: calc(100dvh - 24px);
  overflow: hidden;
  grid-template-rows: auto minmax(0, 1fr) auto;
}

:deep(.appointment-booking-body) {
  min-height: 0;
  overflow: hidden;
}

.appointment-booking-grid {
  min-height: 0;
  display: grid;
  grid-template-columns: minmax(0, 1.1fr) minmax(340px, 0.9fr);
  gap: 12px;
}

.appointment-booking-left {
  min-height: 0;
  display: grid;
  grid-template-rows: minmax(442px, 1fr) auto;
  gap: 10px;
}

.appointment-booking-left.has-invitation {
  grid-template-rows: auto minmax(442px, 1fr) auto;
}

.appointment-invitation-text {
  border: 1px solid var(--border-subtle);
  border-radius: 6px;
  background: var(--color-132745);
  color: var(--text-main);
  font-size: 13px;
  line-height: 1.45;
  padding: 10px 12px;
  white-space: pre-wrap;
}

.appointment-booking-calendar {
  min-height: 442px;
  height: 100%;
  border: 1px solid var(--border-subtle);
  border-radius: 6px;
  background: var(--bg-surface-strong);
  padding: 8px;
  overflow: hidden;
}

.appointment-booking-calendar :deep(.fc-timegrid-slot) {
  height: 3em;
}

.appointment-booking-calendar :deep(.fc-timegrid-col:not(:first-child)),
.appointment-booking-calendar :deep(.fc-col-header-cell:not(:first-child)) {
  box-shadow: inset 1px 0 0 var(--border-subtle);
}

.appointment-booking-calendar :deep(.appointment-slot-event-active) {
  filter: brightness(1.25);
}

.appointment-slot-list {
  max-height: 120px;
  overflow: auto;
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}

.appointment-slot-btn {
  border: 1px solid var(--border-subtle);
  border-radius: 6px;
  background: var(--color-122340);
  color: var(--text-main);
  min-height: 34px;
  padding: 0 10px;
  font-size: 12px;
}

.appointment-slot-btn.active {
  border-color: var(--color-4fd7ff);
  background: var(--color-0f3a52);
}

.appointment-booking-form {
  min-height: 0;
  display: grid;
  align-content: start;
  gap: 10px;
  overflow: auto;
  padding-right: 2px;
}

.appointment-form-row {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 10px;
}

.appointment-form-row.compact {
  grid-template-columns: minmax(120px, 0.5fr) minmax(0, 1fr);
}

.appointment-selected-slot {
  border: 1px solid var(--border-subtle);
  border-radius: 6px;
  background: var(--color-132745);
  color: var(--text-main);
  font-size: 12px;
  padding: 8px 10px;
}

.appointment-selected-slot.invalid,
.input.invalid,
.appointment-consent-row.invalid {
  border-color: var(--color-a81a1a);
}

.appointment-field-error {
  color: var(--color-ff9f9f);
  font-size: 11px;
}

.appointment-message {
  min-height: 110px;
}

.calls-textarea {
  width: 100%;
  border: 1px solid var(--border-subtle);
  border-radius: 6px;
  background: var(--bg-input);
  color: var(--color-0a1322);
  padding: 8px 10px;
  resize: vertical;
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

.appointment-consent-row {
  border: 1px solid var(--border-subtle);
  border-radius: 6px;
  display: grid;
  grid-template-columns: auto minmax(0, 1fr);
  align-items: start;
  gap: 8px;
  color: var(--text-main);
  font-size: 12px;
  padding: 8px 10px;
}

.appointment-link-button {
  border: 0;
  background: transparent;
  color: var(--color-4fd7ff);
  padding: 0;
  text-decoration: underline;
}

.appointment-privacy-overlay {
  border: 1px solid var(--border-subtle);
  border-radius: 6px;
  background: var(--color-081326);
  box-shadow: 0 6px 18px var(--color-rgba-0-0-0-0-28);
  padding: 10px;
  display: grid;
  gap: 8px;
}

.appointment-privacy-overlay header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
}

.appointment-privacy-overlay h3 {
  margin: 0;
  font-size: 14px;
}

.appointment-privacy-copy {
  max-height: 220px;
  overflow: auto;
  color: var(--text-muted);
  font-size: 12px;
  line-height: 1.45;
}

.appointment-privacy-copy p {
  margin: 0 0 8px;
}

.appointment-booking-success {
  display: grid;
  gap: 12px;
  align-content: start;
  max-width: 720px;
}

.appointment-booking-success h2,
.appointment-booking-success p {
  margin: 0;
}

.appointment-booking-success p {
  color: var(--text-muted);
}

.appointment-success-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}

:deep(.appointment-booking-footer) {
  align-items: center;
}

@media (max-width: 900px) {
  :deep(.appointment-booking-dialog) {
    width: calc(100vw - 6px);
    max-height: calc(100dvh - 6px);
  }

  :deep(.appointment-booking-body) {
    overflow: auto;
  }

  .appointment-booking-grid,
  .appointment-form-row,
  .appointment-form-row.compact {
    grid-template-columns: 1fr;
  }

  .appointment-booking-left {
    grid-template-rows: minmax(300px, auto) auto;
  }
}
</style>
