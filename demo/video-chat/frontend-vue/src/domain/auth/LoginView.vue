<template>
  <div class="ii-auth">
    <div class="ii-authCard">
      <div class="ii-authSplit">
        <div class="ii-authSplit__brand">
          <div class="ii-authSplit__brandInner">
            <img
              class="ii-authSplit__logo"
              src="/assets/orgas/kingrt/logo.svg"
              alt="logo"
            />
          </div>
        </div>

        <div class="ii-authSplit__form">
          <form class="ii-form" method="post" novalidate @submit.prevent="handleSubmit">
            <div>
              <label class="ii-fieldLabel" for="email">Email</label>
              <input
                id="email"
                v-model.trim="email"
                class="ii-input ii-input-email"
                type="email"
                inputmode="email"
                autocomplete="username"
                aria-label="Email"
                placeholder="name@company.com"
              />
              <div class="ii-fieldError" :hidden="!emailError">{{ emailError }}</div>
            </div>

            <div>
              <label class="ii-fieldLabel" for="password">Password</label>
              <input
                id="password"
                v-model="password"
                class="ii-input ii-input-password"
                type="password"
                autocomplete="current-password"
                aria-label="Password"
                placeholder="••••••••••"
              />
              <div class="ii-fieldError" :hidden="!passwordError">{{ passwordError }}</div>
            </div>

            <button class="ii-btn ii-btn--primary ii-authBtn ii-btn--block" type="submit" :disabled="submitting">
              <span class="ii-btn__label">{{ submitting ? 'Signing in…' : 'Sign in' }}</span>
            </button>

            <div class="ii-error" :hidden="!authError">{{ authError }}</div>
          </form>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { onMounted, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { resolveAuthorizedRedirect } from '../../http/router';
import { fetchBackend } from '../../support/backendFetch';
import {
  CALL_UUID_PATTERN,
  callRequiresJoinModalForViewer,
  joinPathFromAccessPayload,
} from '../calls/access/admissionGate';
import {
  defaultRouteForRole,
  loginWithEmailChangeToken,
  loginWithPassword,
  sessionState,
} from './session';

const router = useRouter();
const route = useRoute();

const email = ref('');
const password = ref('');
const emailError = ref('');
const passwordError = ref('');
const authError = ref('');
const submitting = ref(false);

function getRedirectTarget(role) {
  const redirect = typeof route.query.redirect === 'string' ? route.query.redirect : '';
  return resolveAuthorizedRedirect(redirect, role, router) || defaultRouteForRole(role);
}

function extractWorkspaceCallRef(redirectTarget) {
  const resolved = router.resolve(String(redirectTarget || '').trim());
  if (String(resolved?.name || '') !== 'call-workspace') {
    return '';
  }

  const entryMode = String(resolved.query?.entry || '').trim().toLowerCase();
  if (entryMode === 'invite') {
    return '';
  }

  const callRef = String(resolved.params?.callRef || '').trim().toLowerCase();
  if (!CALL_UUID_PATTERN.test(callRef)) {
    return '';
  }

  return callRef;
}

async function createSelfJoinPathForCall(callId, sessionToken) {
  const normalizedCallId = String(callId || '').trim().toLowerCase();
  if (!CALL_UUID_PATTERN.test(normalizedCallId) || String(sessionToken || '').trim() === '') {
    return '';
  }

  const { response } = await fetchBackend(`/api/calls/${encodeURIComponent(normalizedCallId)}/access-link`, {
    method: 'POST',
    headers: {
      accept: 'application/json',
      authorization: `Bearer ${sessionToken}`,
    },
  });
  const payload = await response.json().catch(() => null);
  if (!response.ok || !payload || payload.status !== 'ok') {
    return '';
  }

  return joinPathFromAccessPayload(payload);
}

async function normalizePostLoginRedirectTarget(redirectTarget) {
  const callRef = extractWorkspaceCallRef(redirectTarget);
  if (callRef === '') {
    return redirectTarget;
  }

  const sessionToken = String(sessionState.sessionToken || '').trim();
  if (sessionToken === '') {
    return redirectTarget;
  }

  try {
    const { response } = await fetchBackend(`/api/calls/resolve/${encodeURIComponent(callRef)}`, {
      method: 'GET',
      headers: {
        accept: 'application/json',
        authorization: `Bearer ${sessionToken}`,
      },
    });
    const payload = await response.json().catch(() => null);
    if (!response.ok || !payload || payload.status !== 'ok') {
      return redirectTarget;
    }

    const result = payload?.result && typeof payload.result === 'object' ? payload.result : {};
    if (String(result.state || '').trim().toLowerCase() !== 'resolved') {
      return redirectTarget;
    }

    const accessId = String(result?.access_link?.id || '').trim().toLowerCase();
    if (CALL_UUID_PATTERN.test(accessId)) {
      return `/join/${encodeURIComponent(accessId)}`;
    }

    const call = result?.call && typeof result.call === 'object' ? result.call : {};
    const callId = String(call?.id || '').trim().toLowerCase();
    if (
      CALL_UUID_PATTERN.test(callId)
      && callRequiresJoinModalForViewer(call, {
        userId: sessionState.userId,
        role: sessionState.role,
        email: sessionState.email,
      })
    ) {
      const joinPath = await createSelfJoinPathForCall(callId, sessionToken);
      return joinPath || redirectTarget;
    }

    return redirectTarget;
  } catch {
    return redirectTarget;
  }
}

async function handleSubmit() {
  if (submitting.value) return;
  emailError.value = '';
  passwordError.value = '';
  authError.value = '';

  const emailValue = email.value.trim().toLowerCase();
  const passwordValue = password.value;
  let hasError = false;

  if (emailValue === '') {
    emailError.value = 'Email is required.';
    hasError = true;
  } else if (!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(emailValue)) {
    emailError.value = 'Email is invalid.';
    hasError = true;
  }

  if (passwordValue === '') {
    passwordError.value = 'Password is required.';
    hasError = true;
  }

  if (hasError) {
    return;
  }

  submitting.value = true;
  try {
    const result = await loginWithPassword(emailValue, passwordValue);
    if (!result.ok) {
      authError.value = result.message || 'Invalid email or password.';
      return;
    }

    const redirectTarget = getRedirectTarget(result.role);
    const normalizedTarget = await normalizePostLoginRedirectTarget(redirectTarget);
    router.replace(normalizedTarget || defaultRouteForRole(result.role));
  } finally {
    submitting.value = false;
  }
}

async function handleEmailChangeTokenLogin(token) {
  if (submitting.value) return;
  submitting.value = true;
  emailError.value = '';
  passwordError.value = '';
  authError.value = '';

  try {
    const result = await loginWithEmailChangeToken(token);
    if (!result.ok) {
      authError.value = result.message || 'Email confirmation failed.';
      return;
    }

    const fallbackTarget = getRedirectTarget(result.role);
    const tokenTarget = typeof result.redirectPath === 'string' ? result.redirectPath.trim() : '';
    const nextTarget = tokenTarget !== '' ? tokenTarget : fallbackTarget;
    router.replace(nextTarget || defaultRouteForRole(result.role));
  } finally {
    submitting.value = false;
  }
}

onMounted(() => {
  const tokenFromQuery = typeof route.query.email_change_token === 'string'
    ? route.query.email_change_token.trim()
    : '';
  if (tokenFromQuery !== '') {
    void handleEmailChangeTokenLogin(tokenFromQuery);
  }
});
</script>
