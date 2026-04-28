import { fileURLToPath } from 'node:url';

import { defineConfig } from 'vite';
import vue from '@vitejs/plugin-vue';

const frontendRoot = fileURLToPath(new URL('./', import.meta.url));

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

const allowedHosts = parseAllowedHosts(process.env.VIDEOCHAT_VUE_ALLOWED_HOSTS || '');
const hostOptions = allowedHosts === undefined ? {} : { allowedHosts };

export default defineConfig({
  plugins: [assetVersionPlugin(), vue()],
  build: {
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
