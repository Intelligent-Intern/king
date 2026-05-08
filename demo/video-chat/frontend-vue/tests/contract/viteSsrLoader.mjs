import path from 'node:path';
import { createServer } from 'vite';

export async function loadViteSsrModule(frontendRoot, modulePath) {
  const normalizedModulePath = String(modulePath || '').startsWith('/')
    ? String(modulePath)
    : `/${String(modulePath || '').replace(/^\/+/, '')}`;
  const server = await createServer({
    configFile: path.resolve(frontendRoot, 'vite.config.js'),
    logLevel: 'error',
    server: { middlewareMode: true, hmr: false },
    appType: 'custom',
  });
  try {
    return await server.ssrLoadModule(normalizedModulePath);
  } finally {
    await server.close();
  }
}
