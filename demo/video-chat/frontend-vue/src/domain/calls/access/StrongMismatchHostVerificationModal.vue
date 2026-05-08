<template>
  <div class="calls-modal-body strong-mismatch-host-verification">
    <section v-if="!verified" class="strong-mismatch-panel" aria-live="polite">
      <p class="strong-mismatch-eyebrow">{{ t('public.join.host_verification_eyebrow') }}</p>
      <h2>{{ t('public.join.host_verification_title') }}</h2>
      <p>{{ t('public.join.host_verification_foreign_link') }}</p>
      <p>{{ t('public.join.host_verification_account_diff') }}</p>

      <label class="strong-mismatch-host-field" for="strong-mismatch-host-name">
        <span>{{ t('public.join.host_name') }}</span>
        <input
          id="strong-mismatch-host-name"
          v-model.trim="hostName"
          class="input"
          type="text"
          maxlength="160"
          autocomplete="off"
          :disabled="verifying"
          @keydown.enter.prevent="verifyHost"
        />
      </label>

      <p v-if="errorMessage" class="calls-inline-error">{{ errorMessage }}</p>

      <div class="strong-mismatch-actions">
        <button class="btn" type="button" :disabled="verifying" @click="$emit('cancel')">
          {{ t('common.cancel') }}
        </button>
        <button class="btn btn-cyan" type="button" :disabled="verifying" @click="verifyHost">
          {{ verifying ? t('public.join.host_verifying') : t('public.join.host_verify') }}
        </button>
      </div>
    </section>

    <section v-else class="strong-mismatch-panel" aria-live="polite">
      <p class="strong-mismatch-eyebrow">{{ t('public.join.host_verified_eyebrow') }}</p>
      <h2>{{ t('public.join.host_verified_title') }}</h2>
      <p>{{ t('public.join.host_verified_continue') }}</p>
      <p>{{ t('public.join.account_update_choice') }}</p>
      <p v-if="updateNotice" class="calls-inline-hint">{{ updateNotice }}</p>

      <label class="strong-mismatch-host-field" for="strong-mismatch-update-display-name">
        <span>{{ t('public.join.manual_display_name') }}</span>
        <input
          id="strong-mismatch-update-display-name"
          v-model.trim="accountUpdateDisplayName"
          class="input"
          type="text"
          maxlength="160"
          :placeholder="t('public.join.manual_display_name_placeholder')"
          :disabled="accountUpdateSending || accountUpdatePending"
        />
      </label>
      <p v-if="accountUpdateError" class="calls-inline-error">{{ accountUpdateError }}</p>
      <p v-if="accountUpdatePending" class="calls-inline-hint">
        {{ t('public.join.confirmation_email_sent', { email: accountUpdateRecipient }) }}
      </p>

      <div class="strong-mismatch-actions">
        <button class="btn" type="button" :disabled="accountUpdateSending || accountUpdatePending" @click="requestAccountUpdate">
          {{ accountUpdateSending ? t('public.join.sending_confirmation') : t('public.join.account_update_request') }}
        </button>
        <button class="btn btn-cyan" type="button" @click="$emit('continue')">
          {{ t('public.join.account_update_decline_continue') }}
        </button>
      </div>
    </section>
  </div>
</template>

<script setup>
import { ref } from 'vue';
import { localizedApiErrorMessage } from '../../../modules/localization/apiErrorMessages.js';
import { t } from '../../../modules/localization/i18nRuntime.js';
import { loginWithCallAccess, requestCallAccessAccountUpdateConfirmation } from './callAccessSession';

const props = defineProps({
  accessId: { type: String, required: true },
  verifiedContext: { type: Object, default: null },
});

const emit = defineEmits(['cancel', 'verified', 'continue']);

const hostName = ref('');
const verifying = ref(false);
const verified = ref(false);
const errorMessage = ref('');
const updateNotice = ref('');
const accountUpdateDisplayName = ref('');
const accountUpdateSending = ref(false);
const accountUpdatePending = ref(false);
const accountUpdateError = ref('');
const accountUpdateRecipient = ref('');

function hostVerificationError(result) {
  const fields = result?.errorDetails && typeof result.errorDetails === 'object'
    ? result.errorDetails.fields || {}
    : {};
  const hostNameError = fields && typeof fields === 'object' ? String(fields.host_name || '') : '';
  if (hostNameError === 'rate_limited') {
    return t('public.join.host_verification_rate_limited');
  }
  if (hostNameError === 'wrong_host_name') {
    return t('public.join.host_verification_failed_manual_review');
  }
  return t('public.join.host_verification_required');
}

async function verifyHost() {
  if (verifying.value) return;
  if (hostName.value.trim() === '') {
    errorMessage.value = t('public.join.host_verification_required');
    return;
  }

  verifying.value = true;
  errorMessage.value = '';
  updateNotice.value = '';
  const result = await loginWithCallAccess(props.accessId, {
    verifiedContext: props.verifiedContext,
    hostName: hostName.value,
  });
  verifying.value = false;

  if (!result.ok) {
    errorMessage.value = hostVerificationError(result);
    return;
  }

  verified.value = true;
  emit('verified', result);
}

async function requestAccountUpdate() {
  if (!verified.value || accountUpdateSending.value || accountUpdatePending.value) return;
  const displayName = accountUpdateDisplayName.value.trim();
  if (displayName === '') {
    accountUpdateError.value = t('public.join.manual_display_name_required');
    return;
  }

  accountUpdateSending.value = true;
  accountUpdateError.value = '';
  updateNotice.value = t('public.join.account_update_manual_reentry_notice');
  const result = await requestCallAccessAccountUpdateConfirmation(props.accessId, {
    display_name: displayName,
  });
  accountUpdateSending.value = false;

  if (!result.ok) {
    const errorPayload = result.errorCode ? { error: { code: result.errorCode } } : null;
    accountUpdateError.value = localizedApiErrorMessage(errorPayload, t('public.join.confirmation_request_failed'));
    return;
  }

  accountUpdatePending.value = true;
  accountUpdateRecipient.value = String(result.result?.recipient_email || '').trim();
}
</script>

<style scoped>
.strong-mismatch-host-verification {
  min-height: 260px;
  place-items: center;
}

.strong-mismatch-panel {
  width: min(100%, 560px);
  display: grid;
  gap: 10px;
}

.strong-mismatch-panel h2,
.strong-mismatch-panel p {
  margin: 0;
}

.strong-mismatch-panel h2 {
  font-size: 20px;
  line-height: 1.2;
}

.strong-mismatch-eyebrow {
  color: var(--brand-cyan);
  font-size: 12px;
  font-weight: 800;
  text-transform: uppercase;
}

.strong-mismatch-host-field {
  display: grid;
  gap: 6px;
  font-size: 12px;
  font-weight: 800;
}

.strong-mismatch-host-field .input {
  width: 100%;
}

.strong-mismatch-actions {
  display: flex;
  justify-content: flex-end;
  gap: 8px;
  flex-wrap: wrap;
}
</style>
