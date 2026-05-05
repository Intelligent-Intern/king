export const DATA_PORTABILITY_ENTITY_KEY = 'data-portability';

const PORTABILITY_ACTION_KINDS = Object.freeze(['export', 'import']);
const PORTABILITY_SCOPE_OPTIONS = Object.freeze([
  { value: 'organization', label_key: 'governance.option.organization' },
  { value: 'user', label_key: 'governance.option.user' },
]);

const PORTABILITY_SCOPE_FIELD = Object.freeze({
  key: 'scope_type',
  label_key: 'governance.field.scope_type',
  type: 'enum',
  options: PORTABILITY_SCOPE_OPTIONS,
  required: true,
  default: 'organization',
});

const PORTABILITY_BUNDLE_FIELD = Object.freeze({
  key: 'bundle_json',
  label_key: 'governance.field.import_bundle',
  type: 'textarea',
  required: true,
  wide: true,
});

export function isDataPortabilityEntity(entityKey = '') {
  return String(entityKey || '').trim() === DATA_PORTABILITY_ENTITY_KEY;
}

export function isDataPortabilityActionKind(kind = '') {
  return PORTABILITY_ACTION_KINDS.includes(String(kind || '').trim());
}

export function dataPortabilityModalFields(entityKey = '', modalMode = '', fallbackFields = []) {
  if (!isDataPortabilityEntity(entityKey) || !isDataPortabilityActionKind(modalMode)) {
    return fallbackFields;
  }
  return modalMode === 'import'
    ? [PORTABILITY_SCOPE_FIELD, PORTABILITY_BUNDLE_FIELD]
    : [PORTABILITY_SCOPE_FIELD];
}

export function dataPortabilityModalTitle(entityKey = '', modalMode = '') {
  if (!isDataPortabilityEntity(entityKey) || !isDataPortabilityActionKind(modalMode)) return '';
  return modalMode === 'import'
    ? 'governance.data_portability.import_title'
    : 'governance.data_portability.export_title';
}

export function dataPortabilitySubmitLabel(entityKey = '', modalMode = '') {
  if (!isDataPortabilityEntity(entityKey) || !isDataPortabilityActionKind(modalMode)) return '';
  return modalMode === 'import'
    ? 'governance.action.import_data'
    : 'governance.action.export_data';
}

export function dataPortabilityPayloadFromForm(modalMode = '', form = {}, relationships = {}) {
  const jobType = String(modalMode || '').trim();
  if (!isDataPortabilityActionKind(jobType)) {
    return { ok: false, error_key: 'governance.data_portability.invalid_job_type' };
  }

  const payload = {
    job_type: jobType,
    scope_type: normalizePortabilityScope(form.scope_type),
  };
  if (relationships && Object.keys(relationships).length > 0) {
    payload.relationships = relationships;
  }
  if (jobType === 'import') {
    const bundle = parseImportBundle(form.bundle_json);
    if (!bundle.ok) return bundle;
    payload.bundle = bundle.bundle;
  }
  return { ok: true, payload };
}

export function downloadPortabilityExport(row = {}) {
  if (!row || row.job_type !== 'export' || row.status !== 'completed') return false;
  const result = row.result && typeof row.result === 'object' ? row.result : null;
  if (!result || typeof document === 'undefined' || typeof URL === 'undefined') return false;
  const blob = new Blob([`${JSON.stringify(result, null, 2)}\n`], { type: 'application/json' });
  const link = document.createElement('a');
  const objectUrl = URL.createObjectURL(blob);
  link.href = objectUrl;
  link.download = `${String(row.id || 'tenant-export').replace(/[^A-Za-z0-9_.-]/g, '_')}.json`;
  document.body.appendChild(link);
  link.click();
  link.remove();
  URL.revokeObjectURL(objectUrl);
  return true;
}

function normalizePortabilityScope(value = '') {
  return String(value || '').trim() === 'user' ? 'user' : 'organization';
}

function parseImportBundle(value = '') {
  try {
    const bundle = JSON.parse(String(value || '').trim());
    if (!bundle || typeof bundle !== 'object' || Array.isArray(bundle)) {
      return { ok: false, error_key: 'governance.data_portability.bundle_must_be_object' };
    }
    return { ok: true, bundle };
  } catch {
    return { ok: false, error_key: 'governance.data_portability.invalid_bundle_json' };
  }
}
