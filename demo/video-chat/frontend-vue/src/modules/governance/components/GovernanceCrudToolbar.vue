<template>
  <label class="search-field search-field-main governance-search-field" :aria-label="t('governance.search', { entity: pluralLabel })">
    <input
      :value="query"
      class="input"
      type="search"
      :placeholder="t('governance.search_placeholder', { entity: pluralLabelLower })"
      @input="$emit('update:query', $event.target.value)"
      @keydown.enter.prevent="$emit('search')"
    />
  </label>

  <AppSelect class="governance-filter-select" :model-value="statusFilter" @update:model-value="$emit('update:statusFilter', $event)">
    <option value="all">{{ t('governance.filter.all_status') }}</option>
    <option v-for="option in statusOptions" :key="option.value" :value="option.value">
      {{ optionLabel(option) }}
    </option>
  </AppSelect>

  <AppSelect class="governance-filter-select" :model-value="scopeFilter" @update:model-value="$emit('update:scopeFilter', $event)">
    <option value="all">{{ t('governance.filter.all_scope') }}</option>
    <option v-for="option in scopeOptions" :key="option.value" :value="option.value">
      {{ optionLabel(option) }}
    </option>
  </AppSelect>

  <AppIconButton
    class="governance-toolbar-submit-btn"
    icon="/assets/orgas/kingrt/icons/send.png"
    :title="t('governance.apply_filters')"
    :aria-label="t('governance.apply_filters')"
    @click="$emit('search')"
  />

  <span v-if="loading" class="governance-state">{{ t('common.loading') }}</span>
  <span v-if="loadError" class="governance-inline-error">{{ loadError }}</span>
</template>

<script setup>
import { computed } from 'vue';
import AppIconButton from '../../../components/AppIconButton.vue';
import AppSelect from '../../../components/AppSelect.vue';
import { t } from '../../localization/i18nRuntime.js';

const props = defineProps({
  query: {
    type: String,
    default: '',
  },
  statusFilter: {
    type: String,
    default: 'all',
  },
  scopeFilter: {
    type: String,
    default: 'all',
  },
  statusOptions: {
    type: Array,
    default: () => [],
  },
  scopeOptions: {
    type: Array,
    default: () => [],
  },
  pluralLabel: {
    type: String,
    default: '',
  },
  loading: {
    type: Boolean,
    default: false,
  },
  loadError: {
    type: String,
    default: '',
  },
});

defineEmits(['update:query', 'update:statusFilter', 'update:scopeFilter', 'search']);

const pluralLabelLower = computed(() => props.pluralLabel.toLocaleLowerCase());

function optionLabel(option) {
  const key = String(option?.label_key || '').trim();
  return key !== '' ? t(key) : String(option?.label || option?.value || '');
}
</script>
