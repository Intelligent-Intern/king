import { reactive } from 'vue';

function resolveBackendOrigin() {
  const envOrigin = String(import.meta.env.VITE_VIDEOCHAT_BACKEND_ORIGIN || '').trim();
  if (envOrigin !== '') {
    return envOrigin.replace(/\/+$/, '');
  }

  const port = String(import.meta.env.VITE_VIDEOCHAT_BACKEND_PORT || '18080').trim() || '18080';

  if (typeof window !== 'undefined') {
    const protocol = window.location.protocol === 'https:' ? 'https' : 'http';
    const host = window.location.hostname || '127.0.0.1';
    return `${protocol}://${host}:${port}`;
  }

  return `http://127.0.0.1:${port}`;
}

export const backendRuntimeState = reactive({
  status: 'idle',
  backendOrigin: resolveBackendOrigin(),
  checkedAt: '',
  data: null,
  error: '',
});

let inFlight = null;

export async function probeBackendRuntime() {
  if (inFlight) return inFlight;

  const endpoint = `${backendRuntimeState.backendOrigin}/api/runtime`;
  backendRuntimeState.status = 'probing';
  backendRuntimeState.error = '';

  const controller = new AbortController();
  const timeout = setTimeout(() => controller.abort(), 3500);

  inFlight = fetch(endpoint, {
    method: 'GET',
    headers: { accept: 'application/json' },
    signal: controller.signal,
  })
    .then(async (response) => {
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
