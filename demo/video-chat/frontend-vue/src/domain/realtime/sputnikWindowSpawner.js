import { reactive } from 'vue';

const SPUTNIK_COUNT = 10;

function callGetterValue(getter) {
  return typeof getter === 'function' ? getter() : getter?.value;
}

function runnerStateFromPayload(payload, fallback = '') {
  const result = payload?.result && typeof payload.result === 'object' ? payload.result : {};
  const runner = result?.runner && typeof result.runner === 'object' ? result.runner : {};
  return String(runner?.state || result?.state || fallback || '').trim();
}

function runnerCountFromPayload(payload) {
  const result = payload?.result && typeof payload.result === 'object' ? payload.result : {};
  const runner = result?.runner && typeof result.runner === 'object' ? result.runner : {};
  const count = Number(runner?.count || runner?.participant_count || 0);
  return Number.isFinite(count) && count > 0 ? count : 0;
}

export function createSputnikWindowSpawner({ apiRequest, canSpawn, getCallId }) {
  const state = reactive({
    count: 0,
    pending: false,
    running: false,
    status: '',
    async refresh() {
      if (!callGetterValue(canSpawn)) return;
      const callId = String(callGetterValue(getCallId) || '').trim();
      if (callId === '') return;
      try {
        const payload = await apiRequest(`/api/calls/${encodeURIComponent(callId)}/sputnik-swarm`, {
          method: 'GET',
        });
        const runnerState = runnerStateFromPayload(payload, 'not_running');
        state.count = runnerCountFromPayload(payload);
        state.running = ['accepted', 'starting', 'running', 'degraded'].includes(runnerState);
        state.status = state.running
          ? `${state.count || SPUTNIK_COUNT} Sputniks laufen headless auf dem Server.`
          : 'Keine Sputniks laufen.';
      } catch {
        state.running = false;
      }
    },
    async spawn() {
      if (state.pending || !callGetterValue(canSpawn)) return;
      const callId = String(callGetterValue(getCallId) || '').trim();
      if (callId === '') {
        state.status = 'Call id fehlt.';
        return;
      }

      state.pending = true;
      state.status = 'Server startet Headless-Sputniks...';
      try {
        const payload = await apiRequest(`/api/calls/${encodeURIComponent(callId)}/sputnik-swarm`, {
          method: 'POST',
          body: { count: SPUTNIK_COUNT },
        });
        const runnerCount = runnerCountFromPayload(payload) || SPUTNIK_COUNT;
        state.count = runnerCount;
        state.running = true;
        state.status = `${runnerCount} Sputniks laufen headless auf dem Server und warten ggf. in der Lobby.`;
      } catch (error) {
        state.running = false;
        state.count = 0;
        state.status = error instanceof Error ? error.message : 'Sputnik Runner konnte nicht gestartet werden.';
      } finally {
        state.pending = false;
      }
    },
    async stop() {
      if (state.pending) return;
      const callId = String(callGetterValue(getCallId) || '').trim();
      if (callId === '') {
        state.running = false;
        state.count = 0;
        state.status = 'Sputniks beendet.';
        return;
      }

      state.pending = true;
      state.status = 'Server beendet Sputniks...';
      try {
        await apiRequest(`/api/calls/${encodeURIComponent(callId)}/sputnik-swarm`, {
          method: 'DELETE',
        });
      } catch {
        // Stop stays idempotent from the UI perspective; the next refresh can show the backend state.
      } finally {
        state.pending = false;
        state.running = false;
        state.count = 0;
        state.status = 'Sputniks beendet.';
      }
    },
  });
  return state;
}
