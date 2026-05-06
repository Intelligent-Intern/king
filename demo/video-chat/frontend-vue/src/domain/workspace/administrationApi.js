import { sessionState } from '../auth/session';
import { fetchBackend } from '../../support/backendFetch';
import { buildLocalizedApiError } from '../../modules/localization/apiErrorMessages.js';

function headers(withBody = false) {
  const result = { accept: 'application/json' };
  if (withBody) result['content-type'] = 'application/json';
  const token = String(sessionState.sessionToken || '').trim();
  if (token !== '') result.authorization = `Bearer ${token}`;
  return result;
}

async function request(path, options = {}) {
  const response = await fetchBackend(path, {
    method: options.method || 'GET',
    headers: {
      ...headers(options.body !== undefined),
      ...(options.headers && typeof options.headers === 'object' ? options.headers : {}),
    },
    body: options.body === undefined ? undefined : JSON.stringify(options.body),
    query: options.query || null,
    timeoutMs: options.timeoutMs,
    serialize: options.serialize,
  }).then((result) => result.response);
  const payload = await response.json().catch(() => null);
  if (!response.ok || payload?.status !== 'ok') {
    const error = buildLocalizedApiError(payload, `Request failed (${response.status}).`, response.status);
    error.fields = payload?.error?.details?.fields || {};
    throw error;
  }
  return payload.result || {};
}

export function loadWorkspaceAdministration() {
  return request('/api/admin/workspace-administration');
}

export function saveWorkspaceAdministration(body) {
  return request('/api/admin/workspace-administration', {
    method: 'PATCH',
    body: body && typeof body === 'object' ? body : {},
  });
}

export function deleteWorkspaceTheme(themeId) {
  const id = String(themeId || '').trim();
  return request(`/api/admin/workspace-administration/themes/${encodeURIComponent(id)}`, {
    method: 'DELETE',
  });
}

export function listWorkspaceEmailTexts(query = {}) {
  return request('/api/admin/workspace-administration/email-texts', {
    query,
  });
}

export function createWorkspaceEmailText(body) {
  return request('/api/admin/workspace-administration/email-texts', {
    method: 'POST',
    body: body && typeof body === 'object' ? body : {},
  });
}

export function updateWorkspaceEmailText(id, body) {
  return request(`/api/admin/workspace-administration/email-texts/${encodeURIComponent(String(id || '').trim())}`, {
    method: 'PATCH',
    body: body && typeof body === 'object' ? body : {},
  });
}

export function deleteWorkspaceEmailText(id) {
  return request(`/api/admin/workspace-administration/email-texts/${encodeURIComponent(String(id || '').trim())}`, {
    method: 'DELETE',
  });
}

export function listWorkspaceBackgroundImages(query = {}) {
  return request('/api/admin/workspace-administration/background-images', {
    query,
  });
}

function backgroundImageUploadTimeoutMs(files) {
  const rows = Array.isArray(files) ? files : [];
  const encodedBytes = rows.reduce((total, row) => total + String(row?.data_url || '').length, 0);
  const encodedMiB = Math.max(1, Math.ceil(encodedBytes / (1024 * 1024)));
  return Math.max(60_000, Math.min(300_000, 30_000 + encodedMiB * 12_000));
}

const BACKGROUND_IMAGE_UPLOAD_BATCH_MAX_CHARS = 7_250_000;

function createBackgroundUploadTraceId(batchIndex) {
  const prefix = `bgup_${Date.now().toString(36)}_${Math.max(1, Number(batchIndex) || 1).toString(36)}`;
  if (globalThis.crypto?.randomUUID) {
    return `${prefix}_${globalThis.crypto.randomUUID().replace(/-/g, '').slice(0, 16)}`;
  }
  return `${prefix}_${Math.random().toString(36).slice(2, 14)}`;
}

function backgroundUploadPayloadChars(rows) {
  return (Array.isArray(rows) ? rows : []).reduce((total, row) => (
    total
      + String(row?.data_url || '').length
      + String(row?.file_name || '').length
      + String(row?.label || '').length
      + 256
  ), 128);
}

function backgroundUploadBatches(files) {
  const rows = Array.isArray(files) ? files : [];
  const batches = [];
  let current = [];
  let currentChars = 128;
  for (const row of rows) {
    const rowChars = backgroundUploadPayloadChars([row]);
    if (current.length > 0 && currentChars + rowChars > BACKGROUND_IMAGE_UPLOAD_BATCH_MAX_CHARS) {
      batches.push(current);
      current = [];
      currentChars = 128;
    }
    current.push(row);
    currentChars += rowChars;
  }
  if (current.length > 0) {
    batches.push(current);
  }
  return batches;
}

export async function uploadWorkspaceBackgroundImages(files) {
  const batches = backgroundUploadBatches(files);
  const rows = [];
  const diagnostics = [];
  for (let index = 0; index < batches.length; index += 1) {
    const batch = batches[index];
    const traceId = createBackgroundUploadTraceId(index + 1);
    const payloadChars = backgroundUploadPayloadChars(batch);
    try {
      console.info('[BackgroundImages] upload batch', {
        traceId,
        batch: index + 1,
        batchCount: batches.length,
        fileCount: batch.length,
        payloadChars,
      });
      const result = await request('/api/admin/workspace-administration/background-images', {
        method: 'POST',
        body: {
          files: batch,
          client_trace_id: traceId,
          client_batch_index: index + 1,
          client_batch_count: batches.length,
          client_payload_chars: payloadChars,
        },
        headers: {
          'x-upload-trace-id': traceId,
          'x-upload-batch-index': String(index + 1),
          'x-upload-batch-count': String(batches.length),
        },
        serialize: false,
        timeoutMs: backgroundImageUploadTimeoutMs(batch),
      });
      if (Array.isArray(result?.rows)) rows.push(...result.rows);
      if (Array.isArray(result?.diagnostics)) diagnostics.push(...result.diagnostics);
    } catch (error) {
      if (error instanceof Error) {
        error.uploadTraceId = traceId;
        error.message = `${error.message} (upload trace ${traceId}, batch ${index + 1}/${batches.length})`;
      }
      throw error;
    }
  }
  return {
    state: 'stored',
    rows,
    diagnostics,
  };
}

export function deleteWorkspaceBackgroundImage(id) {
  return request(`/api/admin/workspace-administration/background-images/${encodeURIComponent(String(id || '').trim())}`, {
    method: 'DELETE',
  });
}
