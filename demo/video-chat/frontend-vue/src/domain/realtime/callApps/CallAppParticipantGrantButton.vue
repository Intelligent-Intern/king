<template>
  <button
    v-if="hasActiveSession"
    class="icon-mini-btn call-app-grant-btn"
    :class="[
      `variant-${variant}`,
      `action-${normalizedPermissionAction}`,
      { allowed: isEffectivelyAllowed, denied: !isEffectivelyAllowed },
    ]"
    type="button"
    :title="buttonTitle"
    :aria-label="buttonTitle"
    :disabled="!canToggle"
    @click="toggleGrant"
  >
    <img :src="buttonIcon" alt="" />
    <span v-if="variant === 'icon' && normalizedPermissionAction !== 'access'" class="call-app-grant-letter">{{ permissionShortLabel }}</span>
    <span v-if="variant === 'label'">{{ buttonLabel }}</span>
  </button>
</template>

<script setup>
import { computed, ref, watch } from 'vue';

import {
  buildCallAppGrantPatch,
  callAppGrantForUser,
  callAppGrantPermissionsForUser,
  defaultCallAppGrantState,
  normalizeCallAppGrantState,
} from './callAppParticipantGrantHelpers.js';

const props = defineProps({
  session: {
    type: Object,
    default: null,
  },
  row: {
    type: Object,
    required: true,
  },
  canManage: {
    type: Boolean,
    default: false,
  },
  apiRequest: {
    type: Function,
    required: true,
  },
  sendSocketFrame: {
    type: Function,
    required: true,
  },
  requestRoomSnapshot: {
    type: Function,
    required: true,
  },
  variant: {
    type: String,
    default: 'icon',
    validator: (value) => ['icon', 'label'].includes(value),
  },
  permissionAction: {
    type: String,
    default: 'access',
    validator: (value) => ['access', 'read', 'write', 'delete'].includes(value),
  },
});
const emit = defineEmits(['grant-updated']);

const pending = ref(false);
const localGrantState = ref('');
const localPermissions = ref(null);

const normalizedSession = computed(() => (props.session && typeof props.session === 'object' ? props.session : null));
const sessionId = computed(() => String(normalizedSession.value?.id || '').trim());
const callId = computed(() => String(normalizedSession.value?.call_id || normalizedSession.value?.callId || '').trim());
const rowUserId = computed(() => Number(props.row?.userId || props.row?.user_id || 0));
const hasActiveSession = computed(() => sessionId.value !== '' && String(normalizedSession.value?.status || '').toLowerCase() === 'active');
const canToggle = computed(() => (
  hasActiveSession.value
  && props.canManage
  && !pending.value
  && Number.isInteger(rowUserId.value)
  && rowUserId.value > 0
  && props.row?.isRoomMember !== false
));

const normalizedPermissionAction = computed(() => (
  ['read', 'write', 'delete'].includes(props.permissionAction) ? props.permissionAction : 'access'
));
const defaultGrantState = computed(() => defaultCallAppGrantState(normalizedSession.value || {}));

const storedGrantState = computed(() => {
  const userGrant = callAppGrantForUser(normalizedSession.value || {}, rowUserId.value);
  return normalizeCallAppGrantState(userGrant?.grant_state || userGrant?.grantState) || defaultGrantState.value;
});

const storedPermissions = computed(() => callAppGrantPermissionsForUser(normalizedSession.value || {}, rowUserId.value));
const effectivePermissions = computed(() => {
  if (localPermissions.value && typeof localPermissions.value === 'object') {
    return {
      ...storedPermissions.value,
      ...localPermissions.value,
    };
  }
  return storedPermissions.value;
});

const effectiveGrantState = computed(() => {
  const localState = String(localGrantState.value || '').trim().toLowerCase();
  return normalizeCallAppGrantState(localState) || storedGrantState.value;
});
const isEffectivelyAllowed = computed(() => {
  if (normalizedPermissionAction.value === 'access') return effectiveGrantState.value === 'allowed';
  return effectivePermissions.value[normalizedPermissionAction.value] === true;
});

const variant = computed(() => (props.variant === 'label' ? 'label' : 'icon'));
const nextEnabledState = computed(() => !isEffectivelyAllowed.value);
const buttonIcon = computed(() => (
  isEffectivelyAllowed.value
    ? '/assets/orgas/kingrt/icons/remove_user.png'
    : '/assets/orgas/kingrt/icons/add_to_call.png'
));
const permissionLabel = computed(() => {
  if (normalizedPermissionAction.value === 'read') return 'read';
  if (normalizedPermissionAction.value === 'write') return 'write';
  if (normalizedPermissionAction.value === 'delete') return 'delete';
  return 'access';
});
const permissionShortLabel = computed(() => permissionLabel.value.slice(0, 1).toUpperCase());
const buttonLabel = computed(() => {
  if (pending.value) return 'Saving';
  const verb = isEffectivelyAllowed.value ? 'Revoke' : 'Allow';
  return normalizedPermissionAction.value === 'access' ? verb : `${verb} ${permissionLabel.value}`;
});
const buttonTitle = computed(() => (
  !props.canManage
    ? 'Only the call owner or a moderator can change Call App access'
    : `${buttonLabel.value} Call App ${permissionLabel.value} for ${String(props.row?.displayName || props.row?.display_name || 'participant').trim() || 'participant'}`
));

function emitGrantRealtimeUpdate(grantPatch) {
  props.sendSocketFrame({
    type: 'call-app/grants-updated',
    target_user_id: rowUserId.value,
    payload: {
      kind: 'call-app-participant-grant-updated',
      call_id: callId.value,
      app_session_id: sessionId.value,
      subject_type: 'user',
      user_id: rowUserId.value,
      grant_state: grantPatch.grant_state,
      permissions: grantPatch.permissions,
      permission_actions: grantPatch.permission_actions,
    },
  });
  props.requestRoomSnapshot();
}

async function toggleGrant() {
  if (!canToggle.value) return;

  const grantPatch = buildCallAppGrantPatch({
    session: normalizedSession.value || {},
    userId: rowUserId.value,
    permissionAction: normalizedPermissionAction.value,
    enabled: nextEnabledState.value,
  });
  pending.value = true;
  try {
    await props.apiRequest(`/api/call-app-sessions/${encodeURIComponent(sessionId.value)}/participant-grants`, {
      method: 'PATCH',
      body: {
        grants: [grantPatch],
      },
    });
    localGrantState.value = grantPatch.grant_state;
    localPermissions.value = { ...grantPatch.permissions };
    emit('grant-updated', {
      sessionId: sessionId.value,
      userId: rowUserId.value,
      grantState: grantPatch.grant_state,
      permissions: grantPatch.permissions,
      permissionActions: grantPatch.permission_actions,
      permissionAction: normalizedPermissionAction.value,
    });
    emitGrantRealtimeUpdate(grantPatch);
  } finally {
    pending.value = false;
  }
}

watch(
  () => [sessionId.value, rowUserId.value, storedGrantState.value, JSON.stringify(storedPermissions.value)],
  () => {
    localGrantState.value = '';
    localPermissions.value = null;
  },
);
</script>

<style scoped>
.call-app-grant-btn {
  gap: 6px;
  position: relative;
}

.call-app-grant-btn.allowed {
  border-color: var(--color-success);
}

.call-app-grant-btn.denied {
  border-color: var(--color-warning);
}

.call-app-grant-btn.variant-label {
  width: auto;
  min-width: 86px;
  height: 34px;
  padding: 0 10px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  color: var(--text-primary);
  font-size: 11px;
  font-weight: 900;
  text-transform: uppercase;
  letter-spacing: 0;
}

.call-app-grant-btn.variant-label img {
  width: 14px;
  height: 14px;
}

.call-app-grant-letter {
  position: absolute;
  right: 4px;
  bottom: 3px;
  min-width: 13px;
  height: 13px;
  border-radius: 3px;
  display: inline-grid;
  place-items: center;
  background: var(--color-surface-navy);
  color: var(--color-text-primary);
  font-size: 9px;
  font-weight: 900;
  line-height: 1;
}
</style>
