import { reactive } from 'vue';
import { currentBackendOrigin, fetchBackend } from './backendFetch';

export const backendRuntimeState = reactive({
  status: 'idle',
  backendOrigin: currentBackendOrigin(),
  checkedAt: '',
  data: null,
  error: '',
});

let inFlight = null;

export async function probeBackendRuntime() {
  if (inFlight) return inFlight;

  backendRuntimeState.backendOrigin = currentBackendOrigin();
  backendRuntimeState.status = 'probing';
  backendRuntimeState.error = '';

  const controller = new AbortController();
  const timeout = setTimeout(() => controller.abort(), 3500);

  inFlight = fetchBackend('/api/runtime', {
    method: 'GET',
    headers: { accept: 'application/json' },
    signal: controller.signal,
  })
    .then(async ({ response, origin }) => {
      backendRuntimeState.backendOrigin = origin || currentBackendOrigin();
      if (!response.ok) {
        throw new Error(`runtime preflight failed with HTTP ${response.status}`);
      }

      const payload = await response.json();
      backendRuntimeState.data = payload;
      backendRuntimeState.checkedAt = new Date().toISOString();
      backendRuntimeState.status = 'ready';
      return payload;
    })
    .catch((error) => {
      backendRuntimeState.status = 'error';
      backendRuntimeState.error = error instanceof Error ? error.message : 'runtime preflight failed';
      backendRuntimeState.checkedAt = new Date().toISOString();
      return null;
    })
    .finally(() => {
      clearTimeout(timeout);
      inFlight = null;
    });

  return inFlight;
}
