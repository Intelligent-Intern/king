<template>
  <section class="call-left-settings-block" :aria-label="labelFor('background_blur')">
    <div class="call-left-settings-title">{{ labelFor('background_blur') }}</div>
    <div class="call-left-blur-controls" role="group" :aria-label="labelFor('background_blur_controls')">
      <button
        class="call-left-blur-btn"
        :class="{ active: isCallBackgroundPresetActive('light') }"
        type="button"
        :aria-pressed="isCallBackgroundPresetActive('light')"
        :aria-label="labelFor('blur')"
        :title="labelFor('blur')"
        @click="applyCallBackgroundPreset('light')"
      >
        <img class="call-left-blur-icon" src="/assets/orgas/kingrt/icons/blur.png" alt="" />
      </button>
      <button
        class="call-left-blur-btn"
        :class="{ active: isCallBackgroundPresetActive('strong') }"
        type="button"
        :aria-pressed="isCallBackgroundPresetActive('strong')"
        :aria-label="labelFor('strong_blur')"
        :title="labelFor('strong_blur')"
        @click="applyCallBackgroundPreset('strong')"
      >
        <img class="call-left-blur-icon" src="/assets/orgas/kingrt/icons/blurmore.png" alt="" />
      </button>
    </div>
  </section>
</template>

<script setup>
import {
  applyCallBackgroundPreset,
  isCallBackgroundPresetActive,
} from '../media/preferences';
import { t } from '../../../modules/localization/i18nRuntime.js';

const props = defineProps({
  i18nPrefix: {
    type: String,
    default: 'calls.enter',
  },
});

function labelFor(key) {
  return t(`${props.i18nPrefix}.${key}`);
}
</script>
