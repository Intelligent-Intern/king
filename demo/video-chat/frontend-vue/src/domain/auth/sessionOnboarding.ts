import { fetchBackend } from '../../support/backendFetch';
import { extractErrorMessage, normalizeNetworkErrorMessage } from './sessionErrors';
import {
  normalizeOnboardingBadges,
  normalizeOnboardingCompletedTours,
  normalizeString,
} from './sessionNormalizers';
import { clearSessionState, sessionState } from './session';

function sessionHeaders() {
  const token = normalizeString(sessionState.sessionToken);
  const headers = {
    accept: 'application/json',
    'content-type': 'application/json',
  };
  if (token === '') {
    return headers;
  }
  return {
    ...headers,
    authorization: `Bearer ${token}`,
  };
}

async function readJsonResponse(response) {
  try {
    return await response.json();
  } catch {
    return null;
  }
}

export async function completeOnboardingTour(tourKey) {
  if (!sessionState.sessionToken) {
    return {
      ok: false,
      reason: 'missing_session',
      message: 'A valid session token is required.',
    };
  }

  const normalizedTourKey = normalizeString(tourKey).toLowerCase();
  if (normalizedTourKey === '') {
    return {
      ok: false,
      reason: 'invalid_tour_key',
      message: 'Tour key is required.',
    };
  }

  try {
    const { response } = await fetchBackend('/api/user/onboarding/tours/complete', {
      method: 'POST',
      headers: sessionHeaders(),
      body: JSON.stringify({ tour_key: normalizedTourKey }),
    });
    const payload = await readJsonResponse(response);
    if (!response.ok || !payload || payload.status !== 'ok') {
      const message = extractErrorMessage(payload, 'Could not update onboarding progress.');
      if ([401, 403].includes(response.status)) {
        clearSessionState();
        return {
          ok: false,
          reason: 'invalid_session',
          status: response.status,
          message,
        };
      }
      return {
        ok: false,
        reason: 'request_failed',
        status: response.status,
        message,
        fields: payload?.error?.details?.fields || {},
      };
    }

    const onboarding = payload.result?.onboarding || {};
    sessionState.onboardingCompletedTours = normalizeOnboardingCompletedTours(onboarding.completed_tours);
    sessionState.onboardingBadges = normalizeOnboardingBadges(onboarding.badges);
    return {
      ok: true,
      reason: payload.result?.state || 'completed',
      onboarding,
    };
  } catch (error) {
    return {
      ok: false,
      reason: 'network_error',
      status: 0,
      message: normalizeNetworkErrorMessage(error, 'Could not update onboarding progress.'),
    };
  }
}
