<template>
  <footer
    class="calls-modal-footer calls-modal-footer-enter"
    :data-admission-state="waitingForAdmission ? 'waiting' : (joining ? 'joining' : 'idle')"
  >
    <p v-if="admissionMessage" class="calls-enter-admission-status" role="status" aria-live="polite">
      {{ admissionMessage }}
    </p>
    <p v-if="joinError" class="calls-inline-error calls-enter-footer-error">
      {{ joinError }}
    </p>
    <section v-if="mismatch.visible" class="call-access-mismatch-panel" aria-label="Personalized link verification">
      <label class="call-access-mismatch-field">
        <span>{{ t('public.join.host_name') }}</span>
        <input
          v-model.trim="mismatch.hostName"
          class="input"
          type="text"
          maxlength="96"
          autocomplete="off"
          :disabled="joining || waitingForAdmission"
          @keydown.enter.prevent="$emit('verify-host')"
        />
      </label>
      <template v-if="mismatch.step === 'update'">
        <label class="call-access-mismatch-field">
          <span>{{ t('public.join.first_name') }}</span>
          <input
            v-model.trim="mismatch.firstName"
            class="input"
            type="text"
            maxlength="96"
            autocomplete="given-name"
            :disabled="joining || waitingForAdmission"
          />
        </label>
        <label class="call-access-mismatch-field">
          <span>{{ t('public.join.last_name') }}</span>
          <input
            v-model.trim="mismatch.lastName"
            class="input"
            type="text"
            maxlength="96"
            autocomplete="family-name"
            :disabled="joining || waitingForAdmission"
          />
        </label>
      </template>
    </section>
    <button class="btn" type="button" :disabled="joining" @click="$emit('cancel')">{{ t('common.cancel') }}</button>
    <template v-if="mismatch.visible && mismatch.step === 'host'">
      <button
        class="btn btn-cyan"
        type="button"
        :disabled="joining || waitingForAdmission || mismatch.hostName.trim() === ''"
        @click="$emit('verify-host')"
      >
        {{ t('public.join.verify_host') }}
      </button>
    </template>
    <template v-else-if="mismatch.visible && mismatch.step === 'update'">
      <button
        class="btn"
        type="button"
        :disabled="joining || waitingForAdmission"
        @click="$emit('decline-update')"
      >
        {{ t('public.join.continue_without_update') }}
      </button>
      <button
        class="btn btn-cyan"
        type="button"
        :disabled="joining || waitingForAdmission || mismatch.firstName.trim() === '' || mismatch.lastName.trim() === ''"
        @click="$emit('request-confirmation')"
      >
        {{ t('public.join.send_confirmation_email') }}
      </button>
    </template>
    <button
      v-else
      class="btn btn-cyan"
      data-join-action="start"
      type="button"
      :disabled="joining || waitingForAdmission"
      @click="$emit('start-join')"
    >
      {{ waitingForAdmission ? t('public.join.waiting_for_host') : (joining ? t('public.join.joining') : t('public.join.join_call')) }}
    </button>
  </footer>
</template>

<script setup>
import { t } from '../../../modules/localization/i18nRuntime.js';

defineProps({
  admissionMessage: { type: String, default: '' },
  joinError: { type: String, default: '' },
  joining: { type: Boolean, default: false },
  mismatch: { type: Object, required: true },
  waitingForAdmission: { type: Boolean, default: false },
});

defineEmits(['cancel', 'decline-update', 'request-confirmation', 'start-join', 'verify-host']);
</script>
