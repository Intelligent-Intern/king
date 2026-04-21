import { createAdminSyncSocket } from '../../support/adminSyncSocket';

export function createAdminSyncReloadController({ getSessionToken, getOwnSessionId, onReload }) {
  let reloadTimer = 0;
  let adminSyncClient = null;

  function clearReloadTimer() {
    if (reloadTimer > 0) {
      window.clearTimeout(reloadTimer);
      reloadTimer = 0;
    }
  }

  async function reload() {
    await onReload();
  }

  function queueReload() {
    if (reloadTimer > 0) return;
    reloadTimer = window.setTimeout(() => {
      reloadTimer = 0;
      void reload();
    }, 120);
  }

  function publish(topic, reason) {
    if (!adminSyncClient) return;
    adminSyncClient.publish(topic, reason);
  }

  function handleSyncEvent(payload) {
    const sourceSessionId = String(payload?.source_session_id || '').trim();
    const ownSessionId = String(getOwnSessionId() || '').trim();
    if (sourceSessionId !== '' && sourceSessionId === ownSessionId) {
      return;
    }

    const topic = String(payload?.topic || '').trim().toLowerCase();
    if (!['all', 'users', 'overview'].includes(topic)) {
      return;
    }

    queueReload();
  }

  function start() {
    stop();
    adminSyncClient = createAdminSyncSocket({
      getSessionToken: () => String(getSessionToken() || '').trim(),
      onSync: handleSyncEvent,
    });
    adminSyncClient.connect();
  }

  function stop() {
    if (!adminSyncClient) return;
    adminSyncClient.disconnect();
    adminSyncClient = null;
  }

  function reconnect() {
    const sessionToken = String(getSessionToken() || '').trim();
    if (sessionToken === '') {
      stop();
      return;
    }
    if (!adminSyncClient) {
      start();
      return;
    }
    adminSyncClient.reconnect();
  }

  function dispose() {
    clearReloadTimer();
    stop();
  }

  return {
    clearReloadTimer,
    publish,
    reconnect,
    start,
    stop,
    dispose,
  };
}
