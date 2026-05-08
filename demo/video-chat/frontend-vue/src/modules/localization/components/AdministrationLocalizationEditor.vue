<template>
  <section v-if="open" class="localization-editor">
    <header class="localization-editor-header">
      <div>
        <h2>{{ t('localization.admin.editor_title') }}</h2>
        <p>{{ t('localization.admin.editor_count', { count: translationRows.length }) }}</p>
      </div>
    </header>

    <div class="localization-editor-grid">
      <section class="localization-editor-column">
        <label class="field">
          <span>{{ t('localization.admin.left_language') }}</span>
          <select class="ii-select" :value="editorLeftLocale" @change="$emit('update:left-locale', $event.target.value)">
            <option v-for="language in languages" :key="`left-${language.code}`" :value="language.code">
              {{ language.label }} ({{ language.code }})
            </option>
          </select>
        </label>
        <div class="localization-editor-fields" :aria-busy="editorLoading">
          <label v-for="row in translationRows" :key="`left-${row.fullKey}`" class="localization-resource-field">
            <span>{{ row.fullKey }}</span>
            <textarea
              class="input localization-resource-input"
              :dir="localeDirection(editorLeftLocale)"
              :disabled="editorLoading || saving"
              :value="editorValue(editorLeftLocale, row.fullKey)"
              @input="$emit('update-value', {
                locale: editorLeftLocale,
                fullKey: row.fullKey,
                value: $event.target.value,
              })"
            />
          </label>
        </div>
      </section>

      <section class="localization-editor-column">
        <label class="field">
          <span>{{ t('localization.admin.right_language') }}</span>
          <select class="ii-select" :value="editorRightLocale" @change="$emit('update:right-locale', $event.target.value)">
            <option v-for="language in languages" :key="`right-${language.code}`" :value="language.code">
              {{ language.label }} ({{ language.code }})
            </option>
          </select>
        </label>
        <div class="localization-editor-fields" :aria-busy="editorLoading">
          <label v-for="row in translationRows" :key="`right-${row.fullKey}`" class="localization-resource-field">
            <span>{{ row.fullKey }}</span>
            <textarea
              class="input localization-resource-input"
              :dir="localeDirection(editorRightLocale)"
              :disabled="editorLoading || saving"
              :value="editorValue(editorRightLocale, row.fullKey)"
              @input="$emit('update-value', {
                locale: editorRightLocale,
                fullKey: row.fullKey,
                value: $event.target.value,
              })"
            />
          </label>
        </div>
      </section>
    </div>

    <footer class="localization-editor-footer">
      <button class="btn btn-cyan" type="button" :disabled="editorLoading || saving" @click="$emit('save')">
        {{ saving ? t('localization.admin.saving_translations') : t('localization.admin.save_translations') }}
      </button>
    </footer>
  </section>
</template>

<script setup>
import { t } from '../i18nRuntime.js';

defineProps({
  open: {
    type: Boolean,
    default: false,
  },
  languages: {
    type: Array,
    default: () => [],
  },
  translationRows: {
    type: Array,
    default: () => [],
  },
  editorLeftLocale: {
    type: String,
    required: true,
  },
  editorRightLocale: {
    type: String,
    required: true,
  },
  editorLoading: {
    type: Boolean,
    default: false,
  },
  saving: {
    type: Boolean,
    default: false,
  },
  editorValue: {
    type: Function,
    required: true,
  },
  localeDirection: {
    type: Function,
    required: true,
  },
});

defineEmits(['update:left-locale', 'update:right-locale', 'update-value', 'save']);
</script>

<style scoped>
.localization-editor {
  min-height: 0;
  margin: 20px;
  border: 1px solid var(--border);
  background: var(--surface-navy);
  display: flex;
  flex-direction: column;
  overflow: hidden;
}

.localization-editor-header {
  display: flex;
  justify-content: space-between;
  gap: 20px;
  padding: 18px 20px;
  border-bottom: 1px solid var(--border);
}

.localization-editor-header h2,
.localization-editor-header p {
  margin: 0;
}

.localization-editor-header h2 {
  font-size: 1rem;
}

.localization-editor-header p {
  margin-top: 4px;
  color: var(--text-muted);
}

.localization-editor-grid {
  min-height: 0;
  display: grid;
  grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
  gap: 20px;
  padding: 20px;
  overflow: hidden;
}

.localization-editor-column {
  min-height: 0;
  display: flex;
  flex-direction: column;
  gap: 14px;
}

.localization-editor-fields {
  min-height: 420px;
  max-height: 58vh;
  overflow: auto;
  display: grid;
  gap: 12px;
  padding-inline-end: 6px;
}

.localization-resource-field {
  display: grid;
  gap: 6px;
}

.localization-resource-field > span {
  color: var(--text-muted);
  font-size: 0.82rem;
}

.localization-resource-input {
  min-height: 76px;
  resize: vertical;
}

.localization-editor-footer {
  display: flex;
  justify-content: flex-end;
  padding: 0 20px 20px;
}

@media (max-width: 860px) {
  .localization-editor-grid {
    grid-template-columns: 1fr;
  }

  .localization-editor-fields {
    max-height: none;
  }
}
</style>
