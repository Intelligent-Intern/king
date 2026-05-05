import { spawn } from 'child_process';
import { createServer } from 'vite';
import { resolve, dirname } from 'path';
import { fileURLToPath } from 'url';

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '../../..');

const server = await createServer({
  root: ROOT,
  server: {
    port: 3456,
  },
});

await server.listen();

console.log('[gossip-harness] Dev server running at http://localhost:3456');
console.log('[gossip-harness] Open http://localhost:3456/gossip-harness.html in your browser');
console.log('[gossip-harness] Press Ctrl+C to stop');

process.on('SIGINT', () => {
  server.close();
  process.exit(0);
});
