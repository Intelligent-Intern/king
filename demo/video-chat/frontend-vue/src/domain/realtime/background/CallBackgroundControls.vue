<template>
  <CallBackgroundBlurControls :i18n-prefix="i18nPrefix" />
  <CallBackgroundImagePicker :title="imageTitle" hide-when-empty />
</template>

<script setup>
import CallBackgroundBlurControls from './CallBackgroundBlurControls.vue';
import CallBackgroundImagePicker from './CallBackgroundImagePicker.vue';

defineProps({
  i18nPrefix: {
    type: String,
    default: 'calls.enter',
  },
  imageTitle: {
    type: String,
    default: '',
  },
});
</script>
