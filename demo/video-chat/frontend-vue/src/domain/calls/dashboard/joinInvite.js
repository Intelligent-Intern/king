import { reactive } from 'vue';
import { t } from '../../../modules/localization/i18nRuntime.js';

function resolveJoinTarget(joinContext) {
  const callAccess = joinContext?.call_access;
  const accessId = String(callAccess?.id || '').trim();
  const callId = String(joinContext?.call?.id || '').trim();
  const roomId = String(joinContext?.room?.id || joinContext?.call?.room_id || '').trim();
  return {
    accessId,
    callId,
    roomId: roomId === '' ? 'lobby' : roomId,
  };
}

export function createJoinInviteController({
  apiRequest,
  clearNotice,
  setNotice,
  closeEnterCallModal,
  openRedeemedInvitePreview,
}) {
  const joinState = reactive({
    open: false,
    submitting: false,
    error: '',
    code: '',
  });

  function openJoinModal() {
    clearNotice();
    closeEnterCallModal();
    joinState.open = true;
    joinState.submitting = false;
    joinState.error = '';
    joinState.code = '';
  }

  function closeJoinModal() {
    joinState.open = false;
    joinState.submitting = false;
    joinState.error = '';
  }

  async function submitJoinInvite() {
    clearNotice();
    joinState.error = '';

    const code = String(joinState.code || '').trim();
    if (code === '') {
      joinState.error = t('calls.invite.code_required');
      return;
    }

    joinState.submitting = true;

    try {
      const payload = await apiRequest('/api/invite-codes/redeem', {
        method: 'POST',
        body: { code },
      });

      const redemption = payload?.result?.redemption || {};
      const joinContext = redemption?.join_context || {};
      const joinTarget = resolveJoinTarget(joinContext);
      const scope = String(joinContext?.scope || '');

      closeJoinModal();
      setNotice('ok', t('calls.invite.redeemed_notice', { scope: scope || t('calls.invite.default_scope') }));
      await openRedeemedInvitePreview(joinTarget);
    } catch (error) {
      joinState.error = error instanceof Error ? error.message : t('calls.invite.redeem_failed');
    } finally {
      joinState.submitting = false;
    }
  }

  return {
    joinState,
    openJoinModal,
    closeJoinModal,
    submitJoinInvite,
  };
}
