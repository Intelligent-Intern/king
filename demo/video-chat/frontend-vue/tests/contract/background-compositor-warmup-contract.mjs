import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

import { chromium } from '@playwright/test';
import { createServer } from 'vite';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const frontendRoot = path.resolve(__dirname, '../..');

async function startViteServer() {
  const server = await createServer({
    appType: 'custom',
    configFile: path.join(frontendRoot, 'vite.config.js'),
    logLevel: 'error',
    root: frontendRoot,
    server: {
      host: '127.0.0.1',
      port: 0,
      strictPort: false,
    },
  });
  await server.listen();
  const address = server.httpServer?.address();
  assert.ok(address && typeof address === 'object', 'vite server must expose a TCP address');
  return {
    origin: `http://127.0.0.1:${address.port}`,
    server,
  };
}

function resolveChromiumLaunchOptions() {
  const explicitExecutablePath = String(process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH || '').trim();
  if (explicitExecutablePath !== '') {
    assert.ok(
      fs.existsSync(explicitExecutablePath),
      `PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH does not exist: ${explicitExecutablePath}`,
    );
    return { executablePath: explicitExecutablePath };
  }

  const playwrightBundledPath = chromium.executablePath();
  if (playwrightBundledPath && fs.existsSync(playwrightBundledPath)) {
    return {};
  }

  const systemExecutablePath = [
    '/usr/bin/google-chrome-stable',
    '/usr/bin/google-chrome',
    '/usr/bin/chromium',
    '/usr/bin/chromium-browser',
    '/snap/bin/chromium',
  ].find((candidate) => fs.existsSync(candidate));

  return systemExecutablePath ? { executablePath: systemExecutablePath } : {};
}

async function runBrowserContract(origin) {
  const browser = await chromium.launch({
    headless: true,
    args: [
      '--enable-webgl',
      '--ignore-gpu-blocklist',
      '--use-gl=swiftshader',
    ],
    ...resolveChromiumLaunchOptions(),
  });

  try {
    const page = await browser.newPage();
    const pageUrl = `${origin}/bgf05-compositor-warmup.html`;
    await page.route(pageUrl, (route) => route.fulfill({
      contentType: 'text/html',
      body: '<!doctype html><html><head><meta charset="utf-8"></head><body></body></html>',
    }));
    await page.goto(pageUrl, { waitUntil: 'domcontentloaded' });

    return await page.evaluate(async () => {
      const { createBackgroundCompositorStage } = await import('/src/domain/realtime/background/pipeline/compositorStage.js');
      const SIZE = 64;
      const CENTER = Math.floor(SIZE / 2);
      const CORNER = 2;
      const COLORS = {
        blue: [6, 26, 74, 255],
        green: [20, 210, 40, 255],
        red: [220, 20, 20, 255],
        warm: [180, 80, 30, 255],
        yellow: [230, 220, 20, 255],
      };

      function cssColor(color) {
        return `rgba(${color[0]}, ${color[1]}, ${color[2]}, ${color[3] / 255})`;
      }

      function fillCanvas(canvas, color) {
        const ctx = canvas.getContext('2d', { alpha: true });
        if (!ctx) throw new Error('2d source context unavailable');
        ctx.save();
        ctx.globalCompositeOperation = 'copy';
        ctx.fillStyle = cssColor(color);
        ctx.fillRect(0, 0, canvas.width, canvas.height);
        ctx.restore();
      }

      function readPixel(canvas, backend, x, y) {
        if (backend === 'webgl') {
          const gl = canvas.getContext('webgl2') || canvas.getContext('webgl');
          if (!gl) throw new Error('webgl readback context unavailable');
          const pixel = new Uint8Array(4);
          gl.readPixels(
            Math.max(0, Math.min(canvas.width - 1, x)),
            Math.max(0, Math.min(canvas.height - 1, canvas.height - 1 - y)),
            1,
            1,
            gl.RGBA,
            gl.UNSIGNED_BYTE,
            pixel,
          );
          return Array.from(pixel);
        }

        const ctx = canvas.getContext('2d', { willReadFrequently: true });
        if (!ctx) throw new Error('2d readback context unavailable');
        return Array.from(ctx.getImageData(x, y, 1, 1).data);
      }

      function pixelLabel(pixel) {
        return `rgba(${pixel.join(',')})`;
      }

      function nearPixel(actual, expected, tolerance = 16) {
        return Math.abs(actual[0] - expected[0]) <= tolerance
          && Math.abs(actual[1] - expected[1]) <= tolerance
          && Math.abs(actual[2] - expected[2]) <= tolerance
          && Math.abs(actual[3] - expected[3]) <= tolerance;
      }

      function createCenterMaskBitmap() {
        const mask = document.createElement('canvas');
        mask.width = SIZE;
        mask.height = SIZE;
        const ctx = mask.getContext('2d', { alpha: true });
        if (!ctx) throw new Error('2d mask context unavailable');
        ctx.clearRect(0, 0, SIZE, SIZE);
        ctx.fillStyle = 'rgba(255, 255, 255, 1)';
        ctx.fillRect(16, 16, 32, 32);
        return createImageBitmap(mask);
      }

      function createHarness(preferWebGl) {
        const canvas = document.createElement('canvas');
        canvas.width = SIZE;
        canvas.height = SIZE;

        const source = document.createElement('canvas');
        source.width = SIZE;
        source.height = SIZE;
        fillCanvas(source, COLORS.red);

        const stage = createBackgroundCompositorStage({
          canvas,
          getBackgroundColor: () => '#061a4a',
          getBackgroundImageUrl: () => '',
          getBlurPx: () => 3,
          preferWebGl,
          video: source,
        });

        return { canvas, source, stage };
      }

      async function exerciseStage(preferWebGl, expectedBackend) {
        const failures = [];
        const { canvas, source, stage } = createHarness(preferWebGl);
        const backend = stage.backend;

        if (backend !== expectedBackend) {
          failures.push(`${expectedBackend} compositor expected backend ${expectedBackend}, got ${backend}`);
          return { backend, expectedBackend, failures };
        }

        function expectPixel(label, x, y, expected, tolerance = 16) {
          try {
            const actual = readPixel(canvas, backend, x, y);
            if (!nearPixel(actual, expected, tolerance)) {
              failures.push(`${backend} ${label}: expected ${pixelLabel(expected)}, got ${pixelLabel(actual)}`);
            }
          } catch (error) {
            failures.push(`${backend} ${label}: ${error?.message || error}`);
          }
        }

        function render(label, payload) {
          try {
            stage.render(payload);
          } catch (error) {
            failures.push(`${backend} ${label}: render failed: ${error?.message || error}`);
          }
        }

        fillCanvas(source, COLORS.red);
        render('replace warmup source visibility', {
          hasMatteMask: false,
          maskUpdated: false,
          mode: 'replace',
        });
        expectPixel('replace warmup center remains source', CENTER, CENTER, COLORS.red);
        expectPixel('replace warmup corner remains source', CORNER, CORNER, COLORS.red);

        fillCanvas(source, COLORS.green);
        render('blur warmup source visibility', {
          hasMatteMask: false,
          maskUpdated: false,
          mode: 'blur',
        });
        expectPixel('blur warmup center remains source', CENTER, CENTER, COLORS.green);
        expectPixel('blur warmup corner remains source', CORNER, CORNER, COLORS.green);

        fillCanvas(source, COLORS.red);
        const centerMaskBitmap = await createCenterMaskBitmap();
        render('valid mask baseline', {
          hasMatteMask: true,
          maskBitmap: centerMaskBitmap,
          maskHeight: SIZE,
          maskUpdated: true,
          maskWidth: SIZE,
          mode: 'replace',
        });
        expectPixel('valid mask center keeps foreground', CENTER, CENTER, COLORS.red);
        expectPixel('valid mask corner uses replacement background', CORNER, CORNER, COLORS.blue);

        fillCanvas(source, COLORS.green);
        render('stale mask redraws live source', {
          hasMatteMask: true,
          maskHeight: SIZE,
          maskUpdated: false,
          maskWidth: SIZE,
          mode: 'replace',
        });
        expectPixel('stale mask center redraws live source', CENTER, CENTER, COLORS.green);
        expectPixel('stale mask corner keeps replacement background', CORNER, CORNER, COLORS.blue);

        stage.reset();
        fillCanvas(source, COLORS.yellow);
        render('reset warmup source visibility', {
          hasMatteMask: false,
          maskUpdated: false,
          mode: 'replace',
        });
        expectPixel('reset warmup center remains source', CENTER, CENTER, COLORS.yellow);
        expectPixel('reset warmup corner remains source', CORNER, CORNER, COLORS.yellow);

        fillCanvas(source, COLORS.green);
        render('metadata-only matte source visibility', {
          hasMatteMask: true,
          maskHeight: SIZE,
          maskUpdated: true,
          maskWidth: SIZE,
          mode: 'replace',
        });
        expectPixel('metadata-only matte center remains source', CENTER, CENTER, COLORS.green);
        expectPixel('metadata-only matte corner remains source', CORNER, CORNER, COLORS.green);

        fillCanvas(source, COLORS.warm);
        render('segmentation unavailable empty matte visibility', {
          hasMatteMask: true,
          maskHeight: SIZE,
          maskUpdated: true,
          maskValues: new Float32Array(SIZE * SIZE),
          maskWidth: SIZE,
          mode: 'replace',
        });
        expectPixel('unavailable empty matte center remains source', CENTER, CENTER, COLORS.warm);
        expectPixel('unavailable empty matte corner remains source', CORNER, CORNER, COLORS.warm);

        centerMaskBitmap.close?.();
        return { backend, expectedBackend, failures };
      }

      return [
        await exerciseStage(false, 'canvas'),
        await exerciseStage(true, 'webgl'),
      ];
    });
  } finally {
    await browser.close();
  }
}

let server = null;
try {
  const started = await startViteServer();
  server = started.server;
  const results = await runBrowserContract(started.origin);
  const failures = results.flatMap((result) => result.failures || []);
  assert.deepEqual(failures, [], failures.join('\n'));
  console.log('[background-compositor-warmup-contract] PASS');
} catch (error) {
  console.error(`[background-compositor-warmup-contract] FAIL: ${error?.message || error}`);
  process.exitCode = 1;
} finally {
  await server?.close();
}
