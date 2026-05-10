<template>
  <nav v-if="actions.length > 0" class="admin-page-action-bar" :aria-label="ariaLabel">
    <button
      v-for="action in actions"
      :key="action.key"
      class="btn"
      :class="{ 'btn-cyan': action.kind === 'create' }"
      type="button"
      :title="actionTitle(action)"
      :aria-label="actionTitle(action)"
      @click="$emit('select', action)"
    >
      <img v-if="action.icon" class="admin-page-action-icon" :src="action.icon" alt="" />
      <span>{{ actionTitle(action) }}</span>
    </button>
  </nav>
</template>

<script setup>
import { t } from '../../modules/localization/i18nRuntime.js';
import { actionBarLabel } from '../../modules/actionBars.js';

const props = defineProps({
  actions: {
    type: Array,
    default: () => [],
  },
  ariaLabel: {
    type: String,
    required: true,
  },
  labelParams: {
    type: Object,
    default: () => ({}),
  },
});

defineEmits(['select']);

function actionTitle(action) {
  return actionBarLabel(action, t, String(action?.key || ''), props.labelParams);
}
</script>

<style scoped>
.admin-page-action-bar {
  display: flex;
  align-items: center;
  justify-content: flex-end;
  flex-wrap: wrap;
  gap: 10px;
}

.admin-page-action-bar .btn {
  min-height: 40px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
}

.admin-page-action-icon {
  width: 16px;
  height: 16px;
  object-fit: contain;
}
</style>
