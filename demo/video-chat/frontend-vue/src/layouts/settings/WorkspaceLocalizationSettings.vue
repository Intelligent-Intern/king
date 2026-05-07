<template>
  <section class="settings-panel">
    <div class="settings-row">
      <label class="settings-field">
        <span>{{ t('settings.language') }}</span>
        <AppSelect v-model="draft.language">
          <option v-for="language in languageOptions" :key="language.code" :value="language.code">
            {{ language.label }}
          </option>
        </AppSelect>
      </label>
      <label class="settings-field">
        <span>{{ t('settings.date_format') }}</span>
        <AppSelect v-model="draft.dateFormat">
          <option v-for="option in dateFormatOptions" :key="option.value" :value="option.value">
            {{ option.label }}
          </option>
        </AppSelect>
      </label>
    </div>
    <div class="settings-row">
      <label class="settings-field">
        <span>{{ t('settings.time_format') }}</span>
        <AppSelect v-model="draft.timeFormat">
          <option value="24h">24h</option>
          <option value="12h">12h</option>
        </AppSelect>
      </label>
    </div>
  </section>
</template>

<script setup>
import AppSelect from '../../components/AppSelect.vue';
import { t } from '../../modules/localization/i18nRuntime.js';

defineProps({
  draft: {
    type: Object,
    required: true,
  },
  languageOptions: {
    type: Array,
    default: () => [],
  },
  dateFormatOptions: {
    type: Array,
    default: () => [],
  },
});
</script>
