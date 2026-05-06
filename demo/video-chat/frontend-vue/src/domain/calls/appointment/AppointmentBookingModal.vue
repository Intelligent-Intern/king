<template>
  <AppModalShell
    :class="{ 'is-mobile-booking-flow': state.isMobileBooking }"
    :open="open"
    :title="t('public.booking.title')"
    :aria-label="t('public.booking.dialog_aria')"
    logo-src="/assets/orgas/kingrt/logo.svg"
    dialog-class="calls-modal-dialog appointment-booking-dialog"
    body-class="calls-modal-body appointment-booking-body"
    footer-class="calls-modal-footer appointment-booking-footer"
    @close="$emit('close')"
  >
    <template #body>
      <section v-if="state.error" class="calls-inline-error">{{ state.error }}</section>

      <section v-if="state.success" class="appointment-booking-success">
        <header class="appointment-mobile-brand">
          <img src="/assets/orgas/kingrt/logo.svg" alt="KingRT" />
        </header>
        <h2>{{ t('public.booking.booking_confirmed') }}</h2>
        <p>{{ t('public.booking.scheduled_for', { slot: state.confirmedSlotLabel }) }}</p>
        <section class="appointment-confirmation-card">
          <strong>{{ state.confirmedSlotLabel }}</strong>
          <a v-if="bookingLinks.joinUrl" :href="bookingLinks.joinUrl">{{ bookingLinks.joinUrl }}</a>
        </section>
        <div class="appointment-success-actions">
          <a v-if="bookingLinks.joinUrl" class="btn btn-cyan" :href="bookingLinks.joinUrl">{{ t('public.booking.open_video_call') }}</a>
          <a v-if="bookingLinks.googleUrl" class="btn" :href="bookingLinks.googleUrl" target="_blank" rel="noopener">
            {{ t('public.booking.add_google_calendar') }}
          </a>
          <button v-if="bookingLinks.canDownloadIcs" class="btn" type="button" @click="downloadIcs">
            {{ t('public.booking.download_ical') }}
          </button>
        </div>
      </section>

      <section v-else-if="state.isMobileBooking" class="appointment-mobile-flow" :class="`is-${state.mobileStep}`">
        <header class="appointment-mobile-header">
          <button
            v-if="state.mobileStep === 'details'"
            class="icon-mini-btn appointment-mobile-back"
            type="button"
            :aria-label="t('common.back')"
            @click="goToMobileSlotStep"
          >
            <img src="/assets/orgas/kingrt/icons/backward.png" alt="" />
          </button>
          <img class="appointment-mobile-logo" src="/assets/orgas/kingrt/logo.svg" alt="KingRT" />
        </header>

        <section v-if="state.mobileStep === 'slot'" class="appointment-mobile-step appointment-mobile-slot-step">
          <h2>{{ t('public.booking.title') }}</h2>
          <p>{{ t('public.booking.mobile_pick_slot_help', { duration: state.settings.slot_minutes }) }}</p>
          <section v-if="invitationText" class="appointment-invitation-text">{{ invitationText }}</section>
          <section v-if="state.loading" class="calls-inline-hint">{{ t('public.booking.loading_slots') }}</section>
          <template v-else>
            <section class="appointment-mobile-group" :aria-label="t('public.booking.pick_date')">
              <h3>{{ t('public.booking.pick_date') }}</h3>
              <div class="appointment-mobile-day-rail">
                <button
                  v-for="day in mobileDayOptions"
                  :key="day.key"
                  class="appointment-mobile-day-btn"
                  :class="{ active: day.key === state.selectedDayKey }"
                  type="button"
                  @click="selectMobileDay(day.key)"
                >
                  <span>{{ day.weekday }}</span>
                  <strong>{{ day.day }}</strong>
                  <span>{{ day.month }}</span>
                </button>
              </div>
            </section>

            <section class="appointment-mobile-group" :aria-label="t('public.booking.pick_time')">
              <h3>{{ t('public.booking.pick_time') }}</h3>
              <div class="appointment-mobile-slot-grid">
                <button
                  v-for="slot in mobileSlotsForSelectedDay"
                  :key="slot.id"
                  class="appointment-slot-btn appointment-mobile-slot-btn"
                  :class="{ active: String(slot.id) === state.selectedSlotId }"
                  type="button"
                  @click="selectSlot(slot)"
                >
                  <span>{{ slotTimeLabel(slot) }}</span>
                  <img v-if="String(slot.id) === state.selectedSlotId" src="/assets/orgas/kingrt/icons/send.png" alt="" />
                </button>
              </div>
              <p v-if="mobileSlotsForSelectedDay.length === 0" class="calls-inline-hint">
                {{ t('public.booking.no_slots') }}
              </p>
              <p v-if="mobileSlotError" class="appointment-field-error">{{ mobileSlotError }}</p>
            </section>

            <section class="appointment-mobile-note">
              <img src="/assets/orgas/kingrt/icons/lobby.png" alt="" />
              <span>{{ t('public.booking.secure_video_call_note') }}</span>
            </section>
          </template>
        </section>

        <form
          v-else
          class="appointment-booking-form appointment-mobile-details-step"
          novalidate
          @submit.prevent="submit"
        >
          <h2>{{ t('public.booking.confirm_details') }}</h2>
          <p>{{ t('public.booking.confirm_details_copy') }}</p>
          <section class="appointment-mobile-selected-card">
            <img src="/assets/orgas/kingrt/icons/lobby.png" alt="" />
            <span>
              <strong>{{ selectedSlotDayLabel }}</strong>
              <span>{{ selectedSlotTimeRangeLabel }}</span>
              <small>{{ t('public.booking.duration_minutes', { duration: state.settings.slot_minutes }) }}</small>
            </span>
          </section>
          <AppointmentBookingFormFields
            v-model:privacy-open="state.privacyOpen"
            :model="form"
            :selected-slot-label="selectedSlotLabel"
            :show-selected-slot="false"
            :field-error="fieldError"
          />
        </form>
      </section>

      <section v-else class="appointment-booking-grid appointment-booking-desktop">
        <section class="appointment-booking-left" :class="{ 'has-invitation': invitationText }" :aria-label="t('public.booking.available_slots_aria')">
          <section v-if="invitationText" class="appointment-invitation-text">{{ invitationText }}</section>
          <section v-if="state.loading" class="calls-inline-hint">{{ t('public.booking.loading_slots') }}</section>
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
              {{ t('public.booking.no_slots') }}
            </p>
          </div>
        </section>

        <form class="appointment-booking-form" novalidate @submit.prevent="submit">
          <AppointmentBookingFormFields
            v-model:privacy-open="state.privacyOpen"
            :model="form"
            :selected-slot-label="selectedSlotLabel"
            :field-error="fieldError"
          />
        </form>
      </section>
    </template>

    <template #footer>
      <button
        v-if="!state.isMobileBooking || state.success"
        class="btn"
        type="button"
        :disabled="state.submitting"
        @click="$emit('close')"
      >
        {{ t('common.close') }}
      </button>
      <button
        v-if="state.isMobileBooking && !state.success && state.mobileStep === 'slot'"
        class="btn btn-cyan appointment-mobile-primary"
        type="button"
        :disabled="state.loading"
        @click="goToMobileDetailsStep"
      >
        {{ t('common.next') }}
        <img src="/assets/orgas/kingrt/icons/forward.png" alt="" />
      </button>
      <button
        v-if="state.isMobileBooking && !state.success && state.mobileStep === 'details'"
        class="btn"
        type="button"
        :disabled="state.submitting"
        @click="goToMobileSlotStep"
      >
        {{ t('common.back') }}
      </button>
      <button
        v-if="!state.success && (!state.isMobileBooking || state.mobileStep === 'details')"
        class="btn btn-cyan"
        type="button"
        :disabled="state.submitting || state.loading"
        @click="submit"
      >
        {{ state.submitting ? t('public.booking.submitting') : submitButtonLabel }}
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
import AppointmentBookingFormFields from './AppointmentBookingFormFields.vue';
import { bookPublicAppointment, loadPublicAppointmentSlots, toLocalSlotLabel } from './appointmentCalendarApi';
import { i18nState, t } from '../../../modules/localization/i18nRuntime.js';

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
  isMobileBooking: false,
  mobileStep: 'slot',
  selectedDayKey: '',
  mobileSlotTouched: false,
  settings: {
    slot_minutes: 15,
    invitation_text: '',
  },
});

let calendarInstance = null;
let mobileBookingMedia = null;

function currentDocumentLocale() {
  if (typeof document === 'undefined') return '';
  return String(document.documentElement?.lang || '').trim();
}

const activeLocale = computed(() => String(i18nState.locale || currentDocumentLocale() || 'en').trim() || 'en');
const activeDirection = computed(() => (i18nState.direction === 'rtl' ? 'rtl' : 'ltr'));
const selectedSlot = computed(() => state.slots.find((slot) => String(slot.id) === state.selectedSlotId) || null);
const selectedSlotLabel = computed(() => (
  selectedSlot.value ? toLocalSlotLabel(selectedSlot.value, { locale: activeLocale.value }) : t('public.booking.select_slot')
));
const invitationText = computed(() => String(state.settings.invitation_text || '').trim());
const mobileDayOptions = computed(() => {
  const dayMap = new Map();
  for (const slot of state.slots) {
    const key = localDateKey(slot?.starts_at);
    if (key === '') continue;
    if (!dayMap.has(key)) {
      dayMap.set(key, {
        key,
        date: new Date(String(slot.starts_at || '')),
        slots: [],
      });
    }
    dayMap.get(key).slots.push(slot);
  }

  return [...dayMap.values()]
    .sort((left, right) => left.date.getTime() - right.date.getTime())
    .map((day) => ({
      ...day,
      weekday: formatSlotDate(day.date, { weekday: 'short' }),
      day: formatSlotDate(day.date, { day: 'numeric' }),
      month: formatSlotDate(day.date, { month: 'short' }),
    }));
});
const mobileSlotsForSelectedDay = computed(() => {
  const selectedKey = state.selectedDayKey || mobileDayOptions.value[0]?.key || '';
  return state.slots
    .filter((slot) => localDateKey(slot?.starts_at) === selectedKey)
    .sort((left, right) => new Date(String(left.starts_at || '')).getTime() - new Date(String(right.starts_at || '')).getTime());
});
const mobileSlotError = computed(() => (
  state.mobileSlotTouched && !selectedSlot.value ? t('public.booking.error_select_slot') : ''
));
const submitButtonLabel = computed(() => (
  state.isMobileBooking ? t('public.booking.confirm_video_call') : t('public.booking.book_video_call')
));
const selectedSlotDayLabel = computed(() => (
  selectedSlot.value ? formatSlotDate(selectedSlot.value.starts_at, { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' }) : ''
));
const selectedSlotTimeRangeLabel = computed(() => (
  selectedSlot.value ? slotTimeRangeLabel(selectedSlot.value) : ''
));
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

function formatSlotDate(value, options) {
  const date = value instanceof Date ? value : new Date(String(value || ''));
  if (Number.isNaN(date.getTime())) return '';
  return new Intl.DateTimeFormat(activeLocale.value, options).format(date);
}

function localDateKey(value) {
  const date = value instanceof Date ? value : new Date(String(value || ''));
  if (Number.isNaN(date.getTime())) return '';
  const parts = new Intl.DateTimeFormat('en-CA', {
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
  }).formatToParts(date);
  const valueFor = (type) => parts.find((part) => part.type === type)?.value || '';
  const year = valueFor('year');
  const month = valueFor('month');
  const day = valueFor('day');
  return year && month && day ? `${year}-${month}-${day}` : '';
}

function slotTimeLabel(slot) {
  return formatSlotDate(slot?.starts_at, { hour: '2-digit', minute: '2-digit' });
}

function slotTimeRangeLabel(slot) {
  const startsAt = slotTimeLabel(slot);
  const endsAt = formatSlotDate(slot?.ends_at, { hour: '2-digit', minute: '2-digit' });
  return startsAt && endsAt ? `${startsAt} - ${endsAt}` : startsAt;
}

function selectMobileDay(dayKey) {
  const nextKey = String(dayKey || '').trim();
  if (nextKey === '') return;
  state.selectedDayKey = nextKey;
  if (selectedSlot.value && localDateKey(selectedSlot.value.starts_at) !== nextKey) {
    state.selectedSlotId = '';
  }
  state.mobileSlotTouched = false;
}

function syncMobileDaySelection() {
  const firstDay = mobileDayOptions.value[0]?.key || '';
  if (state.selectedDayKey === '' && firstDay !== '') {
    state.selectedDayKey = firstDay;
  }
  if (state.selectedDayKey && !mobileDayOptions.value.some((day) => day.key === state.selectedDayKey)) {
    state.selectedDayKey = firstDay;
  }
  if (state.isMobileBooking && selectedSlot.value && localDateKey(selectedSlot.value.starts_at) !== state.selectedDayKey) {
    state.selectedSlotId = '';
  }
}

function goToMobileDetailsStep() {
  state.mobileSlotTouched = true;
  if (!selectedSlot.value) return;
  state.showErrors = false;
  state.serverFields = {};
  state.mobileStep = 'details';
}

function goToMobileSlotStep() {
  state.mobileStep = 'slot';
  state.showErrors = false;
}

function localErrors() {
  const errors = {};
  if (!selectedSlot.value) {
    errors.slot_id = t('public.booking.error_select_slot');
  }
  if (String(form.first_name || '').trim() === '') {
    errors.first_name = t('public.booking.error_first_name_required');
  }
  if (String(form.last_name || '').trim() === '') {
    errors.last_name = t('public.booking.error_last_name_required');
  }
  const email = String(form.email || '').trim();
  if (email === '') {
    errors.email = t('public.booking.error_email_required');
  } else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
    errors.email = t('public.booking.error_email_invalid');
  }
  if (!form.privacy_accepted) {
    errors.privacy_accepted = t('public.booking.error_privacy_required');
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
  state.mobileSlotTouched = false;
  const slotDay = localDateKey(slot?.starts_at);
  if (slotDay !== '') {
    state.selectedDayKey = slotDay;
  }
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
      title: t('public.booking.default_call_title'),
      start: new Date(String(slot.starts_at || '')),
      end: new Date(String(slot.ends_at || '')),
      backgroundColor: String(slot.id) === state.selectedSlotId ? 'var(--color-cyan-hover)' : 'var(--color-cyan-primary)',
      borderColor: 'var(--color-cyan-hover)',
    });
  }
}

async function ensureCalendar() {
  if (state.isMobileBooking) return;
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
    syncMobileDaySelection();
    if (state.isMobileBooking) {
      if (selectedSlot.value && localDateKey(selectedSlot.value.starts_at) !== state.selectedDayKey) {
        state.selectedSlotId = '';
      }
    } else if (!selectedSlot.value && state.slots.length > 0) {
      state.selectedSlotId = String(state.slots[0].id || '');
    }
    syncCalendarSlots();
    await nextTick();
    calendarInstance?.updateSize();
  } catch (error) {
    state.error = error instanceof Error ? error.message : t('public.booking.load_failed');
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
    state.error = error instanceof Error ? error.message : t('public.booking.book_failed');
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
    text: String(call?.title || t('public.booking.default_call_title')),
    dates: `${startsAt}/${endsAt}`,
    details: `${t('public.booking.video_call_link_label')}:\n${joinUrl}`,
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
    `SUMMARY:${escapeIcsText(String(call.title || t('public.booking.default_call_title')))}`,
    `DESCRIPTION:${escapeIcsText(`${t('public.booking.video_call_link_label')}:\n${joinUrl}`)}`,
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

function destroyCalendar() {
  if (!calendarInstance) return;
  calendarInstance.destroy();
  calendarInstance = null;
}

function syncMobileBookingViewport() {
  state.isMobileBooking = Boolean(mobileBookingMedia?.matches);
  if (state.isMobileBooking) {
    destroyCalendar();
    state.mobileStep = state.mobileStep || 'slot';
    syncMobileDaySelection();
    return;
  }

  if (props.open) {
    void nextTick().then(async () => {
      await ensureCalendar();
      syncCalendarSlots();
      calendarInstance?.updateSize();
    });
  }
}

function attachMobileBookingViewport() {
  if (typeof window === 'undefined' || typeof window.matchMedia !== 'function') return;
  mobileBookingMedia = window.matchMedia('(max-width: 900px)');
  syncMobileBookingViewport();
  if (typeof mobileBookingMedia.addEventListener === 'function') {
    mobileBookingMedia.addEventListener('change', syncMobileBookingViewport);
  } else if (typeof mobileBookingMedia.addListener === 'function') {
    mobileBookingMedia.addListener(syncMobileBookingViewport);
  }
}

function detachMobileBookingViewport() {
  if (!mobileBookingMedia) return;
  if (typeof mobileBookingMedia.removeEventListener === 'function') {
    mobileBookingMedia.removeEventListener('change', syncMobileBookingViewport);
  } else if (typeof mobileBookingMedia.removeListener === 'function') {
    mobileBookingMedia.removeListener(syncMobileBookingViewport);
  }
  mobileBookingMedia = null;
}

watch(
  () => props.open,
  (nextOpen) => {
    if (nextOpen) {
      syncMobileBookingViewport();
      void loadSlots();
    }
  },
);

watch([activeLocale, activeDirection], ([locale, direction]) => {
  calendarInstance?.setOption('locale', locale);
  calendarInstance?.setOption('direction', direction);
  syncMobileDaySelection();
  syncCalendarSlots();
});

onMounted(() => {
  attachMobileBookingViewport();
  if (props.open) {
    void loadSlots();
  }
});

onBeforeUnmount(() => {
  detachMobileBookingViewport();
  destroyCalendar();
});
</script>

<style src="./AppointmentBookingModal.css" scoped></style>
