<template>
  <section class="governance-organizations-view">
    <section v-if="loading" class="governance-state">{{ t('common.loading') }}</section>
    <section v-if="loadError" class="governance-inline-error">{{ loadError }}</section>

    <template v-if="!editorOpen">
      <section v-if="showEmptyState" class="governance-organizations-empty">
        <GovernanceEmptyState
          :title="emptyStateTitle"
          :body="emptyStateBody"
          :create-label="createLabel"
          :show-create="showCreate"
          @create="$emit('create')"
        />
      </section>
      <section v-else-if="filteredCount === 0" class="governance-empty-cell">{{ t('governance.empty_filter') }}</section>
      <section v-else class="governance-organization-grid">
        <article
          v-for="row in rows"
          :key="row.id"
          class="governance-organization-card"
          role="button"
          tabindex="0"
          @click="$emit('edit', row)"
          @keydown.enter="$emit('edit', row)"
          @keydown.space.prevent="$emit('edit', row)"
        >
          <div class="governance-organization-logo">
            <img v-if="organizationLogo(row)" :src="organizationLogo(row)" :alt="row.name" />
            <span v-else>{{ organizationInitials(row) }}</span>
          </div>
          <div class="governance-organization-card-body">
            <strong>{{ row.name || t('common.not_available') }}</strong>
            <span>{{ t('governance.organization.user_count', { count: organizationUserCount(row) }) }}</span>
          </div>
          <span class="tag" :class="statusClass(row.status)">{{ statusLabel(row.status) }}</span>
          <AppIconButton
            v-if="deleteAction && !isReadonly(row)"
            class="governance-organization-delete"
            :icon="deleteAction.icon"
            :title="rowActionTitle(deleteAction)"
            :aria-label="rowActionTitle(deleteAction)"
            danger
            @click.stop="$emit('delete', row)"
          />
        </article>
      </section>
    </template>

    <section v-else class="governance-organization-editor">
      <header class="governance-organization-editor-head">
        <div>
          <h2>{{ editorTitle }}</h2>
          <span>{{ t('governance.organization.inline_form_hint') }}</span>
        </div>
        <AppIconButton
          icon="/assets/orgas/kingrt/icons/cancel.png"
          :title="t('common.close_panel')"
          :aria-label="t('common.close_panel')"
          @click="$emit('close')"
        />
      </header>

      <CrudRelationStack
        v-if="relationActive"
        :open="relationActive"
        :relation="relation"
        :selections="relationSelections"
        :row-provider="rowProvider"
        :create-draft="createDraft"
        :can-create-draft-for-entity="canCreateDraftForEntity"
        @close="$emit('close-relation')"
        @apply="$emit('apply-relation', $event)"
      />

      <template v-else>
        <form id="governanceOrganizationForm" class="governance-organization-form" autocomplete="off" @submit.prevent="$emit('submit')">
          <label v-for="field in fields" :key="field.key" :class="fieldClass(field)">
            <span>{{ fieldLabel(field) }}</span>
            <textarea
              v-if="field.type === 'textarea'"
              v-model.trim="form[field.key]"
              class="input governance-organization-textarea"
              rows="4"
            ></textarea>
            <AppSelect v-else-if="field.type === 'enum'" v-model="form[field.key]">
              <option v-for="option in field.options || []" :key="option.value" :value="option.value">
                {{ optionLabel(option) }}
              </option>
            </AppSelect>
            <input
              v-else
              v-model.trim="form[field.key]"
              class="input"
              :type="field.input_type || 'text'"
              :autocomplete="field.autocomplete || 'off'"
            />
          </label>
        </form>

        <section v-if="relationships.length > 0" class="governance-organization-relations">
          <button
            v-for="relationship in relationships"
            :key="relationship.key"
            class="governance-organization-relation-link"
            type="button"
            @click="$emit('open-relation', relationship)"
          >
            <strong>+1</strong>
            <span>{{ relationshipLabel(relationship) }}</span>
            <em>{{ relationSummary(relationship) }}</em>
          </button>
        </section>

        <p v-if="error" class="governance-inline-error">{{ error }}</p>
        <footer class="governance-organization-editor-actions">
          <button class="btn btn-cyan" type="submit" form="governanceOrganizationForm" :disabled="saving">
            {{ saving ? t('settings.saving') : submitLabel }}
          </button>
        </footer>
      </template>
    </section>
  </section>
</template>

<script setup>
import { computed } from 'vue';
import AppIconButton from '../../../components/AppIconButton.vue';
import AppSelect from '../../../components/AppSelect.vue';
import { t } from '../../localization/i18nRuntime.js';
import CrudRelationStack from '../components/CrudRelationStack.vue';
import GovernanceEmptyState from '../components/GovernanceEmptyState.vue';

const props = defineProps({
  rows: { type: Array, default: () => [] },
  loading: { type: Boolean, default: false },
  loadError: { type: String, default: '' },
  filteredCount: { type: Number, default: 0 },
  showEmptyState: { type: Boolean, default: false },
  emptyStateTitle: { type: String, default: '' },
  emptyStateBody: { type: String, default: '' },
  createLabel: { type: String, default: '' },
  showCreate: { type: Boolean, default: false },
  editorOpen: { type: Boolean, default: false },
  editorTitle: { type: String, default: '' },
  submitLabel: { type: String, default: '' },
  form: { type: Object, required: true },
  fields: { type: Array, default: () => [] },
  relationships: { type: Array, default: () => [] },
  relationSelections: { type: Object, default: () => ({}) },
  error: { type: String, default: '' },
  saving: { type: Boolean, default: false },
  relationActive: { type: Boolean, default: false },
  relation: { type: Object, default: null },
  rowProvider: { type: Function, default: () => [] },
  createDraft: { type: Function, default: null },
  canCreateDraftForEntity: { type: Function, default: () => true },
  rowActions: { type: Array, default: () => [] },
});

defineEmits(['create', 'edit', 'delete', 'submit', 'close', 'open-relation', 'close-relation', 'apply-relation']);

const deleteAction = computed(() => props.rowActions.find((action) => action.kind === 'delete') || null);

function organizationLogo(row) {
  return String(row?.logo_path || row?.logo_url || row?.avatar_path || '').trim();
}

function organizationInitials(row) {
  const words = String(row?.name || row?.key || row?.id || '').trim().split(/\s+/).filter(Boolean);
  return words.slice(0, 2).map((word) => word[0] || '').join('').toUpperCase() || 'OR';
}

function organizationUserCount(row) {
  const users = row?.relationships?.users;
  return Array.isArray(users) ? users.length : 0;
}

function isReadonly(row) {
  return row?.readonly === true;
}

function normalizeStatus(value) {
  const normalized = String(value || '').trim().toLowerCase();
  return ['active', 'archived', 'completed', 'draft', 'disabled', 'failed', 'queued', 'running'].includes(normalized)
    ? normalized
    : 'active';
}

function statusClass(status) {
  const normalized = normalizeStatus(status);
  if (['active', 'completed'].includes(normalized)) return 'ok';
  if (['disabled', 'failed'].includes(normalized)) return 'danger';
  return 'warn';
}

function statusLabel(status) {
  const normalized = normalizeStatus(status);
  const key = `governance.status_${normalized}`;
  return t(key);
}

function fieldLabel(field) {
  const key = String(field?.label_key || '').trim();
  return key !== '' ? t(key) : String(field?.key || '');
}

function optionLabel(option) {
  const key = String(option?.label_key || '').trim();
  return key !== '' ? t(key) : String(option?.label || option?.value || '');
}

function relationshipLabel(relationship) {
  const key = String(relationship?.label_key || '').trim();
  return key !== '' ? t(key) : String(relationship?.key || '');
}

function relationSummary(relationship) {
  const key = String(relationship?.key || '').trim();
  const selected = Array.isArray(props.relationSelections?.[key]) ? props.relationSelections[key] : [];
  if (selected.length === 0) return t('governance.relation_picker.none_selected');
  return t('governance.relation_picker.selected_count', { count: selected.length });
}

function rowActionTitle(action) {
  const key = String(action?.label_key || '').trim();
  return key !== '' ? t(key, { entity: t('governance.entity.organization') }) : String(action?.key || '');
}

function fieldClass(field) {
  return {
    'governance-organization-field': true,
    'governance-organization-field-wide': field?.wide === true || field?.type === 'textarea',
  };
}
</script>
