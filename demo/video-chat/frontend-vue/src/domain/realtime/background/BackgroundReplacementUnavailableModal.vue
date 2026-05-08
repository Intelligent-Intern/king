<template>
  <Teleport to="body">
    <section
      v-if="callMediaPrefs.backgroundReplacementUnavailablePromptOpen"
      class="background-unavailable-modal"
      role="dialog"
      aria-modal="true"
      :aria-label="t('calls.workspace.background_unavailable_title')"
    >
      <div class="background-unavailable-backdrop"></div>
      <article class="background-unavailable-dialog">
        <header class="background-unavailable-head">
          <img src="/assets/orgas/kingrt/icons/solid.png" alt="" />
          <div>
            <h2>{{ t('calls.workspace.background_unavailable_title') }}</h2>
            <p>{{ t('calls.workspace.background_unavailable_body') }}</p>
          </div>
        </header>

        <p v-if="failureLabel" class="background-unavailable-detail">{{ failureLabel }}</p>
        <p v-if="statusMessage" class="background-unavailable-status">{{ statusMessage }}</p>

        <div class="background-unavailable-actions">
          <button class="btn btn-cyan" type="button" @click="useDefaultAvatar">
            {{ t('calls.workspace.background_use_standard_avatar') }}
          </button>
          <label class="btn background-unavailable-upload">
            {{ t('calls.workspace.background_upload_avatar') }}
            <input
              ref="fileInputRef"
              type="file"
              accept="image/png,image/jpeg,image/webp"
              @change="handleAvatarFile"
            />
          </label>
          <button class="btn" type="button" @click="sendUnfilteredVideo">
            {{ t('calls.workspace.background_send_unfiltered') }}
          </button>
        </div>
      </article>
    </section>
  </Teleport>
</template>

<script setup>
import { computed, ref } from 'vue';
import { captureBackgroundModalChoiceDiagnostic } from './diagnostics/runtimeDiagnostics';
import {
  callMediaPrefs,
  clearCallBackgroundFallbackVideo,
  DEFAULT_BACKGROUND_FALLBACK_AVATAR_URL,
  useCallBackgroundFallbackAvatar,
} from '../media/preferences';
import { t } from '../../../modules/localization/i18nRuntime.js';
import { isAllowedAvatarMimeType, readAvatarFileAsDataUrl } from '../../../modules/users/pages/admin/avatarInput';

const props = defineProps({
  reconfigureBackground: {
    type: Function,
    default: null,
  },
});

const fileInputRef = ref(null);
const statusMessage = ref('');

const failureLabel = computed(() => {
  const failures = Array.isArray(callMediaPrefs.backgroundReplacementUnavailableFailures)
    ? callMediaPrefs.backgroundReplacementUnavailableFailures
    : [];
  return failures.length > 0 ? failures[0] : '';
});

function modalDiagnosticDetails() {
  return {
    backgroundBackdropMode: callMediaPrefs.backgroundBackdropMode,
    backgroundFilterMode: callMediaPrefs.backgroundFilterMode,
    backgroundQualityProfile: callMediaPrefs.backgroundQualityProfile,
    backend: callMediaPrefs.backgroundFilterBackend || 'none',
    failures: callMediaPrefs.backgroundReplacementUnavailableFailures,
    reason: callMediaPrefs.backgroundReplacementUnavailableReason || 'segmentation_unavailable',
    reasonUserChoiceRequired: callMediaPrefs.backgroundReplacementUnavailableReason || 'segmentation_unavailable',
    requestedBackend: 'worker-segmenter',
    selectedBackend: callMediaPrefs.backgroundFilterBackend || 'none',
  };
}

async function reconfigure() {
  if (typeof props.reconfigureBackground !== 'function') return;
  try {
    await props.reconfigureBackground();
  } catch {
    statusMessage.value = t('calls.workspace.background_choice_apply_failed');
  }
}

async function useDefaultAvatar() {
  statusMessage.value = '';
  const diagnosticDetails = modalDiagnosticDetails();
  useCallBackgroundFallbackAvatar(DEFAULT_BACKGROUND_FALLBACK_AVATAR_URL);
  captureBackgroundModalChoiceDiagnostic('standard_avatar', diagnosticDetails);
  await reconfigure();
}

async function sendUnfilteredVideo() {
  statusMessage.value = '';
  const diagnosticDetails = modalDiagnosticDetails();
  clearCallBackgroundFallbackVideo();
  captureBackgroundModalChoiceDiagnostic('unfiltered_video', diagnosticDetails);
  await reconfigure();
}

async function handleAvatarFile(event) {
  const input = event?.target instanceof HTMLInputElement ? event.target : fileInputRef.value;
  const file = input?.files?.[0] || null;
  if (input) input.value = '';
  if (!file) return;
  if (!isAllowedAvatarMimeType(file.type)) {
    statusMessage.value = t('calls.workspace.background_avatar_type_invalid');
    return;
  }
  try {
    const diagnosticDetails = modalDiagnosticDetails();
    const dataUrl = await readAvatarFileAsDataUrl(file);
    useCallBackgroundFallbackAvatar(dataUrl);
    captureBackgroundModalChoiceDiagnostic('uploaded_avatar', diagnosticDetails);
    statusMessage.value = '';
    await reconfigure();
  } catch {
    statusMessage.value = t('calls.workspace.background_avatar_read_failed');
  }
}
</script>

<style scoped>
.background-unavailable-modal {
  position: fixed;
  inset: 0;
  z-index: 5000;
  display: grid;
  place-items: center;
  padding: 24px;
}

.background-unavailable-backdrop {
  position: absolute;
  inset: 0;
  background: rgba(3, 7, 18, 0.72);
}

.background-unavailable-dialog {
  position: relative;
  display: grid;
  gap: 16px;
  width: min(520px, 100%);
  padding: 20px;
  border: 1px solid var(--border-subtle);
  border-radius: 8px;
  background: var(--bg-panel);
  color: var(--text-main);
  box-shadow: 0 24px 80px rgba(0, 0, 0, 0.38);
}

.background-unavailable-head {
  display: grid;
  grid-template-columns: 42px 1fr;
  gap: 14px;
  align-items: start;
}

.background-unavailable-head img {
  width: 42px;
  height: 42px;
  object-fit: contain;
}

.background-unavailable-head h2 {
  margin: 0 0 6px;
  font-size: 18px;
  line-height: 1.2;
}

.background-unavailable-head p,
.background-unavailable-detail,
.background-unavailable-status {
  margin: 0;
  font-size: 13px;
  line-height: 1.45;
  color: var(--text-secondary);
}

.background-unavailable-detail {
  padding: 10px 12px;
  border: 1px solid var(--border-subtle);
  border-radius: 6px;
  background: var(--bg-control);
  overflow-wrap: anywhere;
}

.background-unavailable-status {
  color: var(--color-warning);
}

.background-unavailable-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
}

.background-unavailable-upload {
  position: relative;
  overflow: hidden;
  cursor: pointer;
}

.background-unavailable-upload input {
  position: absolute;
  inset: 0;
  opacity: 0;
  pointer-events: none;
}
</style>
