<template>
  <section class="appointment-config-form-scroll" :aria-label="t('appointment_config.slot_form_aria')">
    <div class="appointment-form-head" aria-hidden="true">
      <span>{{ t('appointment_config.date') }}</span>
      <span>{{ t('appointment_config.from') }}</span>
      <span>{{ t('appointment_config.to') }}</span>
      <span></span>
    </div>

    <article v-for="row in rows" :key="row.rowId" class="appointment-form-row" :class="{ booked: row.booked }">
      <label>
        <span>{{ t('appointment_config.date') }}</span>
        <input v-model="row.date" class="input" type="date" :disabled="saving || row.booked" />
      </label>
      <label>
        <span>{{ t('appointment_config.from') }}</span>
        <input v-model="row.startTime" class="input" type="time" :disabled="saving || row.booked" />
      </label>
      <label>
        <span>{{ t('appointment_config.to') }}</span>
        <input v-model="row.endTime" class="input" type="time" :disabled="saving || row.booked" />
      </label>
      <div class="appointment-form-actions">
        <span v-if="row.booked" class="appointment-form-state">{{ t('appointment_config.booked') }}</span>
        <button v-else class="btn" type="button" :disabled="saving" @click="$emit('remove-row', row.rowId)">
          {{ t('common.remove') }}
        </button>
      </div>
    </article>

    <button class="btn appointment-form-add" type="button" :disabled="saving" @click="$emit('add-row')">
      {{ t('appointment_config.add_entry') }}
    </button>
  </section>
</template>

<script setup>
import { t } from '../../../modules/localization/i18nRuntime.js';

defineProps({
  rows: {
    type: Array,
    required: true,
  },
  saving: {
    type: Boolean,
    default: false,
  },
});

defineEmits(['add-row', 'remove-row']);
</script>

<style scoped>
.appointment-config-form-scroll {
  min-height: 0;
  height: 100%;
  border: 1px solid var(--border-subtle);
  border-radius: 6px;
  background: var(--bg-surface-strong);
  padding: 8px;
  overflow: auto;
  display: grid;
  gap: 8px;
  align-content: start;
  overscroll-behavior: contain;
  -webkit-overflow-scrolling: touch;
}

.appointment-form-head,
.appointment-form-row {
  display: grid;
  grid-template-columns: minmax(170px, 1.15fr) minmax(112px, 0.7fr) minmax(112px, 0.7fr) minmax(110px, auto);
  gap: 8px;
  align-items: end;
}

.appointment-form-head {
  position: sticky;
  top: -8px;
  z-index: 1;
  background: var(--bg-surface-strong);
  color: var(--text-muted);
  font-size: 11px;
  font-weight: 700;
  padding: 0 0 4px;
}

.appointment-form-row {
  border: 1px solid var(--border-subtle);
  border-radius: 6px;
  background: var(--color-border);
  padding: 8px;
}

.appointment-form-row.booked { background: var(--color-border); }

.appointment-form-row label {
  min-width: 0;
  display: grid;
  gap: 4px;
  color: var(--text-muted);
  font-size: 11px;
}

.appointment-form-row .input { width: 100%; }

.appointment-form-actions {
  min-width: 0;
  display: flex;
  justify-content: flex-end;
  align-items: center;
}

.appointment-form-actions .btn { height: 34px; padding: 0 12px; }

.appointment-form-state {
  min-height: 34px;
  border: 1px solid var(--border-subtle);
  border-radius: 6px;
  color: var(--text-muted);
  display: inline-flex;
  align-items: center;
  padding: 0 10px;
  font-size: 12px;
}

.appointment-form-add { justify-self: start; }

@media (max-width: 760px) {
  .appointment-form-head { display: none; }
  .appointment-form-row { grid-template-columns: 1fr; }
  .appointment-form-actions { justify-content: flex-start; }
}
</style>
