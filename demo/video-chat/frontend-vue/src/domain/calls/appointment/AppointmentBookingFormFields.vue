<template>
  <section v-if="showSelectedSlot && errorFor('slot_id')" class="appointment-field-error">{{ errorFor('slot_id') }}</section>
  <section
    v-if="showSelectedSlot"
    class="appointment-selected-slot"
    :class="{ invalid: Boolean(errorFor('slot_id')) }"
  >
    {{ selectedSlotLabel }}
  </section>

  <div class="appointment-form-row compact">
    <label class="field">
      <span>{{ t('public.booking.salutation') }}</span>
      <select v-model="model.salutation" class="input">
        <option value="">{{ t('public.booking.salutation_none') }}</option>
        <option value="Mr.">{{ t('public.booking.salutation_mr') }}</option>
        <option value="Ms.">{{ t('public.booking.salutation_ms') }}</option>
        <option value="Mx.">{{ t('public.booking.salutation_mx') }}</option>
        <option value="Dr.">{{ t('public.booking.salutation_dr') }}</option>
      </select>
    </label>
    <label class="field">
      <span>{{ t('public.booking.honorific_title') }}</span>
      <input v-model.trim="model.title" class="input" type="text" autocomplete="honorific-prefix" />
    </label>
  </div>

  <div class="appointment-form-row">
    <label class="field">
      <span>{{ t('public.booking.first_name') }}</span>
      <span v-if="errorFor('first_name')" class="appointment-field-error">{{ errorFor('first_name') }}</span>
      <input
        v-model.trim="model.first_name"
        class="input"
        :class="{ invalid: Boolean(errorFor('first_name')) }"
        type="text"
        autocomplete="given-name"
      />
    </label>
    <label class="field">
      <span>{{ t('public.booking.last_name') }}</span>
      <span v-if="errorFor('last_name')" class="appointment-field-error">{{ errorFor('last_name') }}</span>
      <input
        v-model.trim="model.last_name"
        class="input"
        :class="{ invalid: Boolean(errorFor('last_name')) }"
        type="text"
        autocomplete="family-name"
      />
    </label>
  </div>

  <label class="field">
    <span>{{ t('public.booking.email') }}</span>
    <span v-if="errorFor('email')" class="appointment-field-error">{{ errorFor('email') }}</span>
    <input
      v-model.trim="model.email"
      class="input"
      :class="{ invalid: Boolean(errorFor('email')) }"
      type="email"
      autocomplete="email"
    />
  </label>

  <label class="field">
    <span>{{ t('public.booking.message') }}</span>
    <textarea v-model.trim="model.message" class="calls-textarea appointment-message" rows="5"></textarea>
  </label>

  <section v-if="privacyOpen" class="appointment-privacy-overlay">
    <header>
      <h3>{{ t('public.booking.privacy_policy') }}</h3>
      <button
        class="icon-mini-btn"
        type="button"
        :aria-label="t('public.booking.close_privacy_policy')"
        @click="emitPrivacyOpen(false)"
      >
        <img src="/assets/orgas/kingrt/icons/cancel.png" alt="" />
      </button>
    </header>
    <div class="appointment-privacy-copy">
      <p>{{ t('public.booking.privacy_copy_1') }}</p>
      <p>{{ t('public.booking.privacy_copy_2') }}</p>
      <p>{{ t('public.booking.privacy_copy_3') }}</p>
      <p>{{ t('public.booking.privacy_copy_4') }}</p>
    </div>
  </section>

  <label class="appointment-consent-row" :class="{ invalid: Boolean(errorFor('privacy_accepted')) }">
    <input v-model="model.privacy_accepted" type="checkbox" />
    <span>
      {{ t('public.booking.privacy_accept_prefix') }}
      <button class="appointment-link-button" type="button" @click="emitPrivacyOpen(true)">
        {{ t('public.booking.privacy_policy_link') }}
      </button>{{ t('public.booking.privacy_accept_suffix') }}
    </span>
  </label>
  <span v-if="errorFor('privacy_accepted')" class="appointment-field-error">
    {{ errorFor('privacy_accepted') }}
  </span>
</template>

<script setup>
import { t } from '../../../modules/localization/i18nRuntime.js';

const props = defineProps({
  model: {
    type: Object,
    required: true,
  },
  selectedSlotLabel: {
    type: String,
    default: '',
  },
  showSelectedSlot: {
    type: Boolean,
    default: true,
  },
  fieldError: {
    type: Function,
    required: true,
  },
  privacyOpen: {
    type: Boolean,
    default: false,
  },
});

const emit = defineEmits(['update:privacyOpen']);

function errorFor(field) {
  const value = props.fieldError(field);
  return typeof value === 'string' ? value : '';
}

function emitPrivacyOpen(value) {
  emit('update:privacyOpen', Boolean(value));
}
</script>

<style src="./AppointmentBookingFormFields.css" scoped></style>
