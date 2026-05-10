<template>
  <section class="call-access-strong-mismatch" :aria-label="t('public.join.strong_mismatch_title')">
    <div class="call-left-settings-title">{{ t('public.join.strong_mismatch_title') }}</div>
    <p class="call-access-strong-mismatch-copy">{{ t('public.join.strong_mismatch_body') }}</p>

    <template v-if="!state.hostVerified">
      <label class="call-left-settings-field" for="call-access-host-name">
        <span>{{ t('public.join.host_name') }}</span>
        <input
          id="call-access-host-name"
          v-model.trim="state.hostName"
          class="input"
          type="text"
          maxlength="120"
          :placeholder="t('public.join.host_name_placeholder')"
          :disabled="state.verifyingHost"
          @keydown.enter.prevent="$emit('verify-host')"
        />
      </label>
      <p v-if="state.hostVerificationError" class="calls-inline-error">{{ state.hostVerificationError }}</p>
      <button class="btn btn-cyan full" type="button" :disabled="state.verifyingHost" @click="$emit('verify-host')">
        {{ state.verifyingHost ? t('public.join.verifying_host') : t('public.join.verify_host') }}
      </button>
    </template>

    <template v-else>
      <p class="calls-inline-hint">{{ t('public.join.host_verified') }}</p>
      <button class="btn full" type="button" :disabled="state.joining || state.waitingForAdmission" @click="$emit('continue-without-update')">
        {{ t('public.join.continue_without_update') }}
      </button>

      <div class="call-access-account-update">
        <label class="call-left-settings-field" for="call-access-update-display-name">
          <span>{{ t('public.join.manual_display_name') }}</span>
          <input
            id="call-access-update-display-name"
            v-model.trim="state.accountUpdateDisplayName"
            class="input"
            type="text"
            maxlength="160"
            :placeholder="t('public.join.manual_display_name_placeholder')"
            :disabled="state.accountUpdateSending || state.accountUpdatePending"
          />
        </label>
        <p v-if="state.accountUpdateError" class="calls-inline-error">{{ state.accountUpdateError }}</p>
        <p v-if="state.accountUpdatePending" class="calls-inline-hint">
          {{ t('public.join.confirmation_email_sent', { email: state.accountUpdateRecipient }) }}
        </p>
        <button
          class="btn btn-cyan full"
          type="button"
          :disabled="state.accountUpdateSending || state.accountUpdatePending"
          @click="$emit('request-update')"
        >
          {{ state.accountUpdateSending ? t('public.join.sending_confirmation') : t('public.join.send_confirmation_email') }}
        </button>
      </div>
    </template>
  </section>
</template>

<script setup>
import { t } from '../../../modules/localization/i18nRuntime.js';

defineProps({
  state: {
    type: Object,
    required: true,
  },
});

defineEmits(['verify-host', 'continue-without-update', 'request-update']);
</script>
