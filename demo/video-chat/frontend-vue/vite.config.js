import { fileURLToPath } from 'node:url';
import fs from 'node:fs';
import path from 'node:path';

import { defineConfig } from 'vite';
import vue from '@vitejs/plugin-vue';

const frontendRoot = fileURLToPath(new URL('./', import.meta.url));
const callAppRoot = fileURLToPath(new URL('../../call-app/', import.meta.url));

const parseAllowedHosts = (value) => {
  if (!value) {
    return undefined;
  }

  const normalized = value.trim();
  if (normalized === '*' || normalized === 'true') {
    return true;
  }

  return normalized
    .split(',')
    .map((host) => host.trim())
    .filter(Boolean);
};

const resolveAssetVersion = () => {
  const explicit = String(process.env.VIDEOCHAT_ASSET_VERSION || '').trim();
  if (explicit !== '') {
    return explicit.replace(/[^A-Za-z0-9._-]+/g, '-');
  }

  const now = new Date();
  const part = (value) => String(value).padStart(2, '0');
  return [
    now.getUTCFullYear(),
    part(now.getUTCMonth() + 1),
    part(now.getUTCDate()),
    part(now.getUTCHours()),
    part(now.getUTCMinutes()),
    part(now.getUTCSeconds()),
  ].join('');
};

const resolveProductionSourcemap = () => {
  const value = String(process.env.VIDEOCHAT_PRODUCTION_SOURCEMAPS || '').trim().toLowerCase();
  if (value === 'inline') {
    return 'inline';
  }
  if (value === 'hidden' || value === '1' || value === 'true' || value === 'yes') {
    return 'hidden';
  }
  return false;
};

const buildAssetVersion = resolveAssetVersion();
const productionSourcemap = resolveProductionSourcemap();

const appendAssetVersion = (source) => source.replace(
  /(['"`])(\/(?:assets|cdn)\/[^'"`?#]+)(\?[^'"`#]*)?(#[^'"`]*)?\1/g,
  (match, quote, assetPath, query = '', hash = '') => {
    const hasTerminalFilename = /\/[^/?#]+\.[A-Za-z0-9]+$/.test(assetPath);
    if (!hasTerminalFilename) {
      return match;
    }
    if (/(?:^|[?&])v=/.test(query)) {
      return match;
    }

    const separator = query === '' ? '?' : '&';
    return `${quote}${assetPath}${query}${separator}v=${buildAssetVersion}${hash}${quote}`;
  },
);

const assetVersionPlugin = () => ({
  name: 'kingrt-asset-version',
  enforce: 'pre',
  transform(code, id) {
    if (!id.startsWith(frontendRoot) || id.includes('/node_modules/')) {
      return null;
    }
    if (!/\.(css|js|mjs|ts|vue)$/.test(id)) {
      return null;
    }

    const updated = appendAssetVersion(code);
    if (updated === code) {
      return null;
    }

    return {
      code: updated,
      map: null,
    };
  },
  transformIndexHtml: {
    order: 'post',
    handler(html) {
      return appendAssetVersion(html);
    },
  },
});

const wasmStaticCompatibilityPlugin = () => {
  const parseRequestUrl = (req) => {
    const host = String(req?.headers?.host || 'localhost');
    const forwardedProto = String(req?.headers?.['x-forwarded-proto'] || '').split(',')[0].trim();
    const protocol = forwardedProto || 'http';
    return new URL(req.url, `${protocol}://${host}`);
  };

  const normalizeWasmImportQuery = (req, next) => {
    if (!req.url || !req.url.startsWith('/wasm/')) {
      next();
      return;
    }
    const parsed = parseRequestUrl(req);
    if (!parsed.searchParams.has('import')) {
      next();
      return;
    }
    parsed.searchParams.delete('import');
    const query = parsed.searchParams.toString();
    req.url = `${parsed.pathname}${query ? `?${query}` : ''}`;
    next();
  };

  return {
    name: 'kingrt-wasm-static-compat',
    enforce: 'pre',
    configureServer(server) {
      server.middlewares.use((req, _res, next) => normalizeWasmImportQuery(req, next));
    },
    configurePreviewServer(server) {
      server.middlewares.use((req, _res, next) => normalizeWasmImportQuery(req, next));
    },
  };
};

const callAppContentType = (filePath) => {
  switch (path.extname(filePath).toLowerCase()) {
    case '.html':
      return 'text/html; charset=utf-8';
    case '.js':
    case '.mjs':
      return 'text/javascript; charset=utf-8';
    case '.css':
      return 'text/css; charset=utf-8';
    case '.json':
      return 'application/json; charset=utf-8';
    case '.svg':
      return 'image/svg+xml';
    case '.png':
      return 'image/png';
    case '.jpg':
    case '.jpeg':
      return 'image/jpeg';
    case '.webp':
      return 'image/webp';
    default:
      return 'application/octet-stream';
  }
};

const walkFiles = (root, prefix = '') => {
  if (!fs.existsSync(root)) return [];
  const entries = fs.readdirSync(root, { withFileTypes: true });
  const files = [];
  for (const entry of entries) {
    const relativePath = path.posix.join(prefix, entry.name);
    const absolutePath = path.join(root, entry.name);
    if (entry.isDirectory()) {
      files.push(...walkFiles(absolutePath, relativePath));
    } else if (entry.isFile()) {
      files.push(relativePath);
    }
  }
  return files;
};

const resolveCallAppStaticPath = (requestPath) => {
  if (!requestPath.startsWith('/call-app/')) return '';
  const cleanParts = requestPath
    .slice('/call-app/'.length)
    .split('/')
    .map((part) => decodeURIComponent(part).trim())
    .filter((part) => part !== '' && part !== '.' && part !== '..');
  if (cleanParts.length < 2) return '';
  const candidate = path.resolve(callAppRoot, ...cleanParts);
  const root = path.resolve(callAppRoot);
  if (candidate !== root && candidate.startsWith(`${root}${path.sep}`)) {
    return candidate;
  }
  return '';
};

const serveCallAppStatic = (req, res, next) => {
  const parsed = new URL(req.url || '/', 'http://kingrt.local');
  const filePath = resolveCallAppStaticPath(parsed.pathname);
  if (!filePath) {
    next();
    return;
  }
  if (!fs.existsSync(filePath) || !fs.statSync(filePath).isFile()) {
    res.statusCode = 404;
    res.setHeader('Content-Type', 'text/plain; charset=utf-8');
    res.end('Not Found\n');
    return;
  }
  res.statusCode = 200;
  res.setHeader('Content-Type', callAppContentType(filePath));
  res.end(fs.readFileSync(filePath));
};

const callAppStaticPlugin = () => ({
  name: 'kingrt-call-app-static',
  configureServer(server) {
    server.middlewares.use(serveCallAppStatic);
  },
  configurePreviewServer(server) {
    server.middlewares.use(serveCallAppStatic);
  },
  generateBundle() {
    for (const relativePath of walkFiles(callAppRoot)) {
      this.emitFile({
        type: 'asset',
        fileName: `call-app/${relativePath}`,
        source: fs.readFileSync(path.join(callAppRoot, relativePath)),
      });
    }
  },
});

const allowedHosts = parseAllowedHosts(process.env.VIDEOCHAT_VUE_ALLOWED_HOSTS || '');
const hostOptions = allowedHosts === undefined ? {} : { allowedHosts };
const callWorkspaceChunkForId = (id) => {
  const normalized = id.replace(/\\/g, '/');
  if (normalized.includes('/node_modules/')) {
    if (normalized.includes('/@vue/') || normalized.endsWith('/vue/dist/vue.runtime.esm-bundler.js')) {
      return 'vendor-vue';
    }
    return 'vendor';
  }
  if (normalized.includes('/src/lib/wasm/') || normalized.includes('/src/domain/realtime/local/')) {
    return 'call-workspace-capture';
  }
  if (normalized.includes('/src/lib/sfu/') || normalized.includes('/src/domain/realtime/sfu/')) {
    return 'call-workspace-sfu';
  }
  if (
    normalized.includes('/src/domain/realtime/media/security')
    || normalized.includes('/src/domain/realtime/workspace/callWorkspace/mediaSecurity')
  ) {
    return 'call-workspace-security';
  }
  if (normalized.includes('/src/domain/realtime/native/') || normalized.includes('/src/domain/realtime/workspace/callWorkspace/nativeStack')) {
    return 'call-workspace-native';
  }
  if (normalized.includes('/src/domain/realtime/workspace/callWorkspace/')) {
    return 'call-workspace-runtime';
  }
  return undefined;
};

export default defineConfig({
  plugins: [assetVersionPlugin(),
  wasmStaticCompatibilityPlugin(),
  callAppStaticPlugin(),
  vue()],
  optimizeDeps: {
    exclude: [
      '@mediapipe/tasks-audio',
      '@mediapipe/tasks-text'
    ]
  },
  worker: {
    format: 'es',
    plugins: () => [
      assetVersionPlugin(),
      wasmStaticCompatibilityPlugin()
    ]
  },
  define: {
    'import.meta.env.VIDEOCHAT_ASSET_VERSION': JSON.stringify(buildAssetVersion),
  },
  build: {
    rollupOptions: {
      output: {
        manualChunks: callWorkspaceChunkForId,
      },
    },
    sourcemap: productionSourcemap,
  },
  server: {
    host: process.env.VIDEOCHAT_VUE_HOST || '127.0.0.1',
    port: Number.parseInt(process.env.VIDEOCHAT_VUE_PORT || '5176', 10),
    ...hostOptions,
  },
  preview: {
    host: process.env.VIDEOCHAT_VUE_HOST || '127.0.0.1',
    port: Number.parseInt(process.env.VIDEOCHAT_VUE_PORT || '5176', 10),
    ...hostOptions,
  },
});
