<template>
  <button
    v-if="hasActiveSession"
    class="icon-mini-btn call-app-grant-btn"
    type="button"
    :class="{ allowed: effectiveGrantState === 'allowed', denied: effectiveGrantState === 'denied' }"
    :title="buttonTitle"
    :aria-label="buttonTitle"
    :disabled="!canToggle"
    @click="toggleGrant"
  >
    <img :src="buttonIcon" alt="" />
  </button>
</template>

<script setup>
import { computed, ref, watch } from 'vue';

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
});

const pending = ref(false);
const localGrantState = ref('');

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

const defaultGrantState = computed(() => (
  String(normalizedSession.value?.default_app_policy || '') === 'allowed_by_default' ? 'allowed' : 'denied'
));

const storedGrantState = computed(() => {
  const grants = Array.isArray(normalizedSession.value?.grants) ? normalizedSession.value.grants : [];
  const userGrant = grants.find((grant) => (
    String(grant?.subject_type || '') === 'user'
    && Number(grant?.user_id || 0) === rowUserId.value
  ));
  const state = String(userGrant?.grant_state || '').trim().toLowerCase();
  return state === 'allowed' || state === 'denied' ? state : defaultGrantState.value;
});

const effectiveGrantState = computed(() => {
  const localState = String(localGrantState.value || '').trim().toLowerCase();
  return localState === 'allowed' || localState === 'denied' ? localState : storedGrantState.value;
});

const nextGrantState = computed(() => (effectiveGrantState.value === 'allowed' ? 'denied' : 'allowed'));
const buttonIcon = computed(() => (
  effectiveGrantState.value === 'allowed'
    ? '/assets/orgas/kingrt/icons/add_to_call.png'
    : '/assets/orgas/kingrt/icons/remove_user.png'
));
const buttonTitle = computed(() => (
  effectiveGrantState.value === 'allowed'
    ? 'Revoke Call App access'
    : 'Allow Call App access'
));

function emitGrantRealtimeUpdate(grantState) {
  props.sendSocketFrame({
    type: 'call-app/grants-updated',
    target_user_id: rowUserId.value,
    payload: {
      kind: 'call-app-participant-grant-updated',
      call_id: callId.value,
      app_session_id: sessionId.value,
      subject_type: 'user',
      user_id: rowUserId.value,
      grant_state: grantState,
    },
  });
  props.requestRoomSnapshot();
}

async function toggleGrant() {
  if (!canToggle.value) return;

  const grantState = nextGrantState.value;
  pending.value = true;
  try {
    await props.apiRequest(`/api/call-app-sessions/${encodeURIComponent(sessionId.value)}/participant-grants`, {
      method: 'PATCH',
      body: {
        grants: [{
          subject_type: 'user',
          user_id: rowUserId.value,
          grant_state: grantState,
        }],
      },
    });
    localGrantState.value = grantState;
    emitGrantRealtimeUpdate(grantState);
  } finally {
    pending.value = false;
  }
}

watch(
  () => [sessionId.value, rowUserId.value, storedGrantState.value],
  () => {
    localGrantState.value = '';
  },
);
</script>

<style scoped>
.call-app-grant-btn.allowed {
  border-color: var(--color-success);
}

.call-app-grant-btn.denied {
  border-color: var(--color-warning);
}
</style>
