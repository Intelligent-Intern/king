import { createServer } from 'node:http';
import { readFile } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const __filename = fileURLToPath(import.meta.url);
const __dirname = dirname(__filename);
const rootDir = dirname(__dirname);
const host = process.env.VIDEOCHAT_VUE_HOST || '127.0.0.1';
const port = Number.parseInt(process.env.VIDEOCHAT_VUE_PORT || '5176', 10);

const server = createServer(async (_req, res) => {
  try {
    const html = await readFile(join(rootDir, 'index.html'), 'utf8');
    res.writeHead(200, { 'content-type': 'text/html; charset=utf-8' });
    res.end(html);
  } catch (error) {
    res.writeHead(500, { 'content-type': 'application/json; charset=utf-8' });
    res.end(JSON.stringify({ error: error instanceof Error ? error.message : 'unknown error' }));
  }
});

server.listen(port, host, () => {
  console.log(`[video-chat][frontend-vue] listening on http://${host}:${port}`);
});
