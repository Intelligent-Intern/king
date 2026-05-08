import { fetchBackend } from '../../../support/backendFetch';
import { applySessionEnvelope, sessionState } from '../../auth/session';
import { extractErrorMessage, normalizeNetworkErrorMessage } from '../../auth/sessionErrors';
import { CALL_UUID_PATTERN, callAccessVerifiedContextFromSession } from './admissionGate';

function errorCodeFromPayload(payload: unknown): string {
  const source = payload && typeof payload === 'object'
    ? payload as { error?: { code?: unknown } }
    : null;
  const code = source?.error?.code ?? '';
  return typeof code === 'string' ? code.trim() : '';
}

function errorDetailsFromPayload(payload: unknown): Record<string, unknown> {
  const source = payload && typeof payload === 'object'
    ? payload as { error?: { details?: unknown } }
    : null;
  return source?.error?.details && typeof source.error.details === 'object'
    ? source.error.details as Record<string, unknown>
    : {};
}

async function readJsonResponse(response: Response): Promise<any> {
  try {
    return await response.json();
  } catch {
    return null;
  }
}

function callAccessSessionRequestBody(options: Record<string, any> = {}): Record<string, unknown> | null {
  const body: Record<string, unknown> = {};
  const guestName = typeof options?.guestName === 'string' ? options.guestName.trim() : '';
  if (guestName !== '') {
    body.guest_name = guestName;
  }

  const verifiedContext = callAccessVerifiedContextFromSession(options?.verifiedContext);
  if (verifiedContext) {
    body.verified_user_id = verifiedContext.userId;
    body.verified_session_id = verifiedContext.sessionId;
  }

  const hostName = typeof options?.hostName === 'string' ? options.hostName.trim() : '';
  if (hostName !== '') {
    body.host_name = hostName;
  }

  return Object.keys(body).length > 0 ? body : null;
}

function callAccessSessionHeaders(hasBody: boolean): Record<string, string> {
  const headers: Record<string, string> = {
    accept: 'application/json',
  };
  if (hasBody) {
    headers['content-type'] = 'application/json';
  }

  const token = String(sessionState.sessionToken || '').trim();
  if (token !== '') {
    headers.authorization = `Bearer ${token}`;
  }

  return headers;
}

export async function loginWithCallAccess(accessId: unknown, options: Record<string, any> = {}) {
  const normalizedAccessId = String(accessId || '').trim().toLowerCase();
  if (!CALL_UUID_PATTERN.test(normalizedAccessId)) {
    return {
      ok: false,
      status: 422,
      errorCode: 'call_access_validation_failed',
      message: 'Call access id is invalid.',
    };
  }

  const verifiedContext = callAccessVerifiedContextFromSession(options?.verifiedContext);
  if (verifiedContext && String(sessionState.sessionToken || '').trim() === '') {
    return {
      ok: false,
      status: 409,
      errorCode: 'call_access_conflict',
      message: 'Call access session context changed.',
    };
  }

  try {
    const requestBody = callAccessSessionRequestBody(options);
    const { response } = await fetchBackend(`/api/call-access/${encodeURIComponent(normalizedAccessId)}/session`, {
      method: 'POST',
      headers: callAccessSessionHeaders(requestBody !== null),
      body: requestBody === null ? undefined : JSON.stringify(requestBody),
    });
    const payload = await readJsonResponse(response);
    if (!response.ok) {
      return {
        ok: false,
        status: response.status,
        errorCode: errorCodeFromPayload(payload),
        errorDetails: errorDetailsFromPayload(payload),
        message: extractErrorMessage(payload, 'Could not start call access session.'),
      };
    }
    if (!payload || payload.status !== 'ok') {
      return {
        ok: false,
        status: response.status,
        message: 'Call access response is invalid.',
      };
    }
    const result = payload?.result && typeof payload.result === 'object' ? payload.result : {};
    applySessionEnvelope(result.session, result.user, result.tenant);
    return {
      ok: true,
      role: sessionState.role,
      session: result.session || null,
      user: result.user || null,
      accessLink: result.access_link || null,
      call: result.call || null,
    };
  } catch (error) {
    return {
      ok: false,
      status: 0,
      message: normalizeNetworkErrorMessage(error, 'Call access session request failed.'),
    };
  }
}
