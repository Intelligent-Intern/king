import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

import { chromium, firefox } from '@playwright/test';
import { createServer } from 'vite';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const frontendRoot = path.resolve(__dirname, '../..');

const DEFAULT_PORT = 4176;
const DEFAULT_SAMPLE_FRAMES = 24;
const DEFAULT_TIMEOUT_MS = 30000;
const DIAGNOSTICS_STORAGE_KEY = 'ii.videocall.client_diagnostics.pending.v1';

const FAILURE_PATTERNS = [
  { id: 'mediapipe_segmenter_init', pattern: /\b(MediaPipe|Tasks-Vision|ImageSegmenter|createFromOptions|FilesetResolver|INIT_ERROR)\b/i },
  { id: 'chromium_gpu_service', pattern: /\b(GPU service|gpu service|GPU process|GpuChannel|ContextResult::kFatalFailure|Failed to create shared context|WebGL context lost)\b/i },
  { id: 'init_failure', pattern: /\b(init_failed|failed to initialize|initialization failed|WorkerSegmenter:|Worker error)\b/i },
];

const GPU_TOUCH_PATTERNS = [
  /\bWebGL\b/i,
  /\bGL version\b/i,
  /\bOpenGL\b/i,
  /\bGpuBuffer\b/i,
  /\bsegmentation_postprocessor_gl\b/i,
  /\bSOFTMAX activation function chosen on GPU\b/i,
  /\bGpuChannel\b/i,
  /\bGPU process\b/i,
  /\bGPU service\b/i,
];

function parseArgs(argv) {
  const options = {
    browser: process.env.BGF_BROWSER || '',
    executable: process.env.BGF_BROWSER_EXECUTABLE || '',
    headless: process.env.BGF_HEADLESS !== '0',
    label: process.env.BGF_BROWSER_LABEL || '',
    out: process.env.BGF_CAPTURE_OUT || '',
    port: Number.parseInt(process.env.BGF_CAPTURE_PORT || String(DEFAULT_PORT), 10),
    sampleFrames: Number.parseInt(process.env.BGF_CAPTURE_SAMPLE_FRAMES || String(DEFAULT_SAMPLE_FRAMES), 10),
    timeoutMs: Number.parseInt(process.env.BGF_CAPTURE_TIMEOUT_MS || String(DEFAULT_TIMEOUT_MS), 10),
    dryRun: false,
    help: false,
    write: process.env.BGF_CAPTURE_WRITE !== '0',
  };

  for (const arg of argv) {
    if (arg === '--help' || arg === '-h') options.help = true;
    else if (arg === '--dry-run') options.dryRun = true;
    else if (arg === '--no-write') options.write = false;
    else if (arg === '--headed') options.headless = false;
    else if (arg === '--headless') options.headless = true;
    else if (arg.startsWith('--browser=')) options.browser = arg.slice('--browser='.length);
    else if (arg.startsWith('--executable=')) options.executable = arg.slice('--executable='.length);
    else if (arg.startsWith('--label=')) options.label = arg.slice('--label='.length);
    else if (arg.startsWith('--out=')) options.out = arg.slice('--out='.length);
    else if (arg.startsWith('--port=')) options.port = Number.parseInt(arg.slice('--port='.length), 10);
    else if (arg.startsWith('--sample-frames=')) options.sampleFrames = Number.parseInt(arg.slice('--sample-frames='.length), 10);
    else if (arg.startsWith('--timeout-ms=')) options.timeoutMs = Number.parseInt(arg.slice('--timeout-ms='.length), 10);
  }

  options.browser = normalizeBrowserName(options.browser, options.executable, options.label);
  options.label = options.label || defaultLabel(options.browser, options.executable);
  options.port = Number.isFinite(options.port) && options.port > 0 ? options.port : DEFAULT_PORT;
  options.sampleFrames = Number.isFinite(options.sampleFrames) && options.sampleFrames > 0
    ? Math.min(90, options.sampleFrames)
    : DEFAULT_SAMPLE_FRAMES;
  options.timeoutMs = Number.isFinite(options.timeoutMs) && options.timeoutMs > 1000
    ? options.timeoutMs
    : DEFAULT_TIMEOUT_MS;

  return options;
}

function normalizeBrowserName(value, executable, label) {
  const source = `${value} ${executable} ${label}`.toLowerCase();
  if (source.includes('firefox')) return 'firefox';
  return 'chromium';
}

function defaultLabel(browser, executable) {
  const name = path.basename(String(executable || '').trim()).toLowerCase();
  if (name.includes('google-chrome')) return 'Chrome Stable';
  if (name.includes('chromium')) return 'Chromium Ubuntu';
  if (browser === 'firefox') return 'Firefox';
  return 'Playwright Chromium';
}

function usage() {
  return `Usage: node tests/e2e/background-regression-capture.mjs [options]

Captures BGF-01 browser evidence without changing runtime fallback behavior.

Options:
  --browser=chromium|firefox       Browser engine. Defaults from executable/label.
  --executable=/path/to/browser    Real browser executable to launch.
  --label="Chrome Stable"          Evidence browser label.
  --out=path                       JSON output path. Defaults to test-results/bgf-background-regression/.
  --sample-frames=N                Worker result polling frames. Default ${DEFAULT_SAMPLE_FRAMES}.
  --timeout-ms=N                   Capture timeout. Default ${DEFAULT_TIMEOUT_MS}.
  --headed                         Run headed.
  --no-write                       Print JSON only.
  --dry-run                        Print resolved plan only.
  --help                           Show this help.

Environment equivalents:
  BGF_BROWSER, BGF_BROWSER_EXECUTABLE, BGF_BROWSER_LABEL, BGF_CAPTURE_OUT,
  BGF_CAPTURE_SAMPLE_FRAMES, BGF_CAPTURE_TIMEOUT_MS, BGF_HEADLESS=0.
`;
}

function safeName(value) {
  return String(value || 'browser')
    .trim()
    .toLowerCase()
    .replace(/[^a-z0-9._-]+/g, '-')
    .replace(/^-+|-+$/g, '') || 'browser';
}

function sanitizeText(value, maxLength = 800) {
  return String(value ?? '')
    .replace(/https?:\/\/[^\s"'<>]+/gi, '[url]')
    .replace(/[A-Za-z0-9+/=_-]{48,}/g, '[opaque]')
    .replace(/\s+/g, ' ')
    .trim()
    .slice(0, maxLength);
}

function uniqueByText(events, max = 30) {
  const seen = new Set();
  const out = [];
  for (const event of events) {
    const text = sanitizeText(event.text || event.message || '');
    if (!text || seen.has(text)) continue;
    seen.add(text);
    out.push({ ...event, text });
    if (out.length >= max) break;
  }
  return out;
}

function matchedSignatures(events) {
  const matches = [];
  for (const event of events) {
    const text = sanitizeText(event.text || event.message || '');
    if (!text) continue;
    const groupIds = FAILURE_PATTERNS
      .filter((entry) => entry.pattern.test(text))
      .map((entry) => entry.id);
    if (groupIds.length === 0) continue;
    matches.push({
      type: event.type || 'event',
      text,
      groups: Array.from(new Set(groupIds)),
    });
  }
  return uniqueByText(matches, 20);
}

function matchedGpuTouchEvents(events) {
  return uniqueByText(events
    .filter((event) => GPU_TOUCH_PATTERNS.some((pattern) => pattern.test(event.text || event.message || '')))
    .map((event) => ({
      type: event.type || 'event',
      text: sanitizeText(event.text || event.message || ''),
    })), 20);
}

function delegateEventWindow(events, delegate) {
  const needle = new RegExp(`delegate[:= ]+${delegate}`, 'i');
  const start = events.findIndex((event) => needle.test(event.text || event.message || ''));
  if (start < 0) return [];
  let end = events.length;
  for (let index = start + 1; index < events.length; index += 1) {
    const text = String(events[index].text || events[index].message || '');
    if (/\[BackgroundFilter\]/.test(text)) {
      end = index;
      break;
    }
    if (/Worker received INIT message/i.test(text) && !needle.test(text)) {
      end = index;
      break;
    }
  }
  return events.slice(start, end);
}

function outputPathFor(options, evidence) {
  if (options.out) return path.resolve(frontendRoot, options.out);
  const dir = path.join(frontendRoot, 'test-results', 'bgf-background-regression');
  const version = safeName(evidence.version || 'unknown-version');
  const label = safeName(options.label);
  return path.join(dir, `${label}-${version}.json`);
}

function withTimeout(promise, ms, label) {
  let timer = null;
  const timeoutPromise = new Promise((_, reject) => {
    timer = setTimeout(() => reject(new Error(`${label}_timeout_after_${ms}ms`)), Math.max(1000, ms));
    timer.unref?.();
  });
  return Promise.race([
    Promise.resolve(promise),
    timeoutPromise,
  ]).finally(() => {
    if (timer) clearTimeout(timer);
  });
}

async function startVite(port) {
  const server = await createServer({
    configFile: path.join(frontendRoot, 'vite.config.js'),
    root: frontendRoot,
    logLevel: 'error',
    plugins: [{
      name: 'bgf-background-regression-capture-page',
      configureServer(viteServer) {
        viteServer.middlewares.use((req, res, next) => {
          const requestUrl = String(req.url || '').split('?')[0];
          if (requestUrl !== '/__bgf-background-regression-capture.html') {
            next();
            return;
          }
          res.statusCode = 200;
          res.setHeader('content-type', 'text/html; charset=utf-8');
          res.end(`<!doctype html>
<html>
  <head><meta charset="utf-8"><title>BGF capture</title></head>
  <body><main id="bgf-capture-root">BGF capture</main></body>
</html>`);
        });
      },
    }],
    server: {
      host: '127.0.0.1',
      port,
      strictPort: false,
    },
  });
  await server.listen();
  const localUrls = server.resolvedUrls?.local || [];
  const baseURL = localUrls.find((url) => url.startsWith('http://127.0.0.1:'))
    || localUrls[0]
    || `http://127.0.0.1:${port}/`;
  return { baseURL: baseURL.replace(/\/+$/, ''), server };
}

function browserLauncher(browserName) {
  return browserName === 'firefox' ? firefox : chromium;
}

function readSmallText(filePath, maxBytes = 4096) {
  try {
    const handle = fs.openSync(filePath, 'r');
    try {
      const buffer = Buffer.alloc(maxBytes);
      const bytes = fs.readSync(handle, buffer, 0, maxBytes, 0);
      return buffer.subarray(0, bytes).toString('utf8');
    } finally {
      fs.closeSync(handle);
    }
  } catch {
    return '';
  }
}

function isSnapFirefoxExecutable(executablePath) {
  const normalized = String(executablePath || '');
  let realPath = normalized;
  try {
    realPath = fs.realpathSync(normalized);
  } catch {}
  const pathProbe = `${normalized}\n${realPath}`.toLowerCase();
  if (pathProbe.includes('/snap/firefox/') || pathProbe.includes('/snap/bin/firefox')) return true;
  const launcherText = readSmallText(normalized).toLowerCase();
  return launcherText.includes('/snap/bin/firefox') || launcherText.includes('snap run firefox');
}

function assertBrowserExecutable(options, launcher, launchOptions) {
  const executablePath = launchOptions.executablePath || launcher.executablePath();
  if (!executablePath || !fs.existsSync(executablePath)) {
    throw new Error(`${options.browser}_unavailable: executable_missing:${executablePath || 'unknown'}`);
  }
  if (options.browser === 'firefox' && isSnapFirefoxExecutable(executablePath)) {
    throw new Error(`firefox_unavailable: snap_firefox_rejected:${executablePath}. Snap Firefox hangs Playwright/Juggler launch in this environment and leaves unkillable browser processes; use Playwright Firefox or a non-Snap Firefox executable.`);
  }
  return executablePath;
}

function launchOptionsFor(options) {
  const launchOptions = { headless: options.headless };
  launchOptions.timeout = Math.min(options.timeoutMs, 15000);
  if (options.executable) launchOptions.executablePath = options.executable;
  if (options.browser === 'chromium') {
    launchOptions.args = [
      '--autoplay-policy=no-user-gesture-required',
      '--use-fake-ui-for-media-stream',
      '--no-sandbox',
    ];
  }
  return launchOptions;
}

function installNodePageMonitors(page, events) {
  page.on('console', (message) => {
    events.push({
      at: Date.now(),
      location: message.location(),
      type: `console:${message.type()}`,
      text: message.text(),
    });
  });
  page.on('pageerror', (error) => {
    events.push({
      at: Date.now(),
      type: 'pageerror',
      text: error?.stack || error?.message || error,
    });
  });
  page.on('requestfailed', (request) => {
    events.push({
      at: Date.now(),
      type: 'requestfailed',
      text: `${request.method()} ${request.url()} ${request.failure()?.errorText || ''}`,
    });
  });
  page.on('response', (response) => {
    if (response.status() < 400) return;
    events.push({
      at: Date.now(),
      type: 'response',
      text: `${response.status()} ${response.url()}`,
    });
  });
  page.on('worker', (worker) => {
    events.push({
      at: Date.now(),
      type: 'worker',
      text: worker.url(),
    });
  });
}

async function installBrowserSideMonitor(page) {
  await page.addInitScript(() => {
    const events = [];
    Object.defineProperty(window, '__bgfCaptureEvents', {
      configurable: true,
      value: events,
      writable: false,
    });
    const format = (value) => {
      if (value instanceof Error) return `${value.name || 'Error'}: ${value.message || ''}`;
      if (typeof value === 'string') return value;
      try {
        return JSON.stringify(value);
      } catch {
        return String(value);
      }
    };
    for (const level of ['debug', 'log', 'info', 'warn', 'error']) {
      const original = typeof console[level] === 'function' ? console[level].bind(console) : null;
      if (!original) continue;
      console[level] = (...args) => {
        events.push({
          at: Date.now(),
          type: `console:${level}`,
          text: args.map(format).join(' '),
        });
        original(...args);
      };
    }
    window.addEventListener('error', (event) => {
      events.push({
        at: Date.now(),
        type: 'window:error',
        text: event.error?.stack || event.message || 'window_error',
      });
    });
    window.addEventListener('unhandledrejection', (event) => {
      events.push({
        at: Date.now(),
        type: 'window:unhandledrejection',
        text: format(event.reason || 'unhandled_rejection'),
      });
    });
  });
}

async function captureInPage(page, options) {
  return page.evaluate(async ({ diagnosticsStorageKey, sampleFrames, timeoutMs }) => {
    const delay = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
    const startedAt = performance.now();

    function sanitize(value, maxLength = 800) {
      return String(value ?? '')
        .replace(/https?:\/\/[^\s"'<>]+/gi, '[url]')
        .replace(/[A-Za-z0-9+/=_-]{48,}/g, '[opaque]')
        .replace(/\s+/g, ' ')
        .trim()
        .slice(0, maxLength);
    }

    function errorPayload(error) {
      return {
        name: sanitize(error?.name || 'Error', 120),
        message: sanitize(error?.message || String(error || ''), 500),
        stack: sanitize(error?.stack || '', 1200),
      };
    }

    function readDiagnostics() {
      try {
        const parsed = JSON.parse(localStorage.getItem(diagnosticsStorageKey) || '[]');
        return Array.isArray(parsed)
          ? parsed.filter((entry) => String(entry?.event_type || '').startsWith('local_background_'))
          : [];
      } catch {
        return [];
      }
    }

    function selectedBackendFromDiagnostics(diagnostics) {
      for (let index = diagnostics.length - 1; index >= 0; index -= 1) {
        const selected = String(diagnostics[index]?.payload?.selected_backend || '').trim();
        if (selected && selected !== 'none') return selected;
      }
      return 'none';
    }

    function gpuAvailability() {
      if (typeof document === 'undefined') return { gpu_available: false, gpu_api: 'none' };
      try {
        const canvas = document.createElement('canvas');
        const webgl2 = canvas.getContext('webgl2');
        if (webgl2) return { gpu_available: true, gpu_api: 'webgl2' };
        const webgl = canvas.getContext('webgl') || canvas.getContext('experimental-webgl');
        if (webgl) return { gpu_available: true, gpu_api: 'webgl' };
        return { gpu_available: false, gpu_api: 'none' };
      } catch (error) {
        return { gpu_available: false, gpu_api: 'none', error: sanitize(error?.message || error) };
      }
    }

    function browserFamily() {
      const source = `${navigator.userAgentData?.brands?.map((brand) => brand.brand).join(' ') || ''} ${navigator.userAgent || ''}`.toLowerCase();
      if (source.includes('firefox')) return 'firefox';
      if (source.includes('edg/')) return 'edge';
      if (source.includes('chrome') || source.includes('chromium')) return 'chromium';
      if (source.includes('safari')) return 'safari';
      return 'unknown';
    }

    function createSyntheticVideo(width = 320, height = 180, frameRate = 12) {
      const canvas = document.createElement('canvas');
      canvas.width = width;
      canvas.height = height;
      const ctx = canvas.getContext('2d', { alpha: false });
      const video = document.createElement('video');
      video.autoplay = true;
      video.muted = true;
      video.playsInline = true;
      let frame = 0;

      const draw = () => {
        frame += 1;
        if (!ctx) return;
        ctx.fillStyle = '#12315f';
        ctx.fillRect(0, 0, width, height);
        ctx.fillStyle = '#f2c6a0';
        ctx.beginPath();
        ctx.arc(width * 0.5, height * 0.3, height * 0.12, 0, Math.PI * 2);
        ctx.fill();
        ctx.fillStyle = '#1f8f6a';
        ctx.beginPath();
        const sway = Math.sin(frame / 3) * width * 0.03;
        ctx.roundRect(width * 0.36 + sway, height * 0.43, width * 0.28, height * 0.42, 18);
        ctx.fill();
        ctx.fillStyle = '#f2c6a0';
        ctx.fillRect(width * 0.27 + sway, height * 0.48, width * 0.46, height * 0.08);
      };

      draw();
      const stream = canvas.captureStream(frameRate);
      video.srcObject = stream;
      document.body.appendChild(video);

      const dispose = () => {
        for (const track of stream.getTracks()) {
          try { track.stop(); } catch {}
        }
        video.pause();
        video.srcObject = null;
        video.remove();
      };

      return { canvas, dispose, draw, stream, video };
    }

    async function waitForVideo(video) {
      const started = performance.now();
      while (video.readyState < 2 && performance.now() - started < 3000) {
        await delay(50);
      }
    }

    async function probeWorkerDelegate(delegate, backendModule) {
      const result = {
        delegate,
        status: 'not_run',
        selected_backend: 'none',
        init_ms: 0,
        init_error: null,
        labels: [],
        segment_result_count: 0,
        segment_error_count: 0,
        matte_bitmap_count: 0,
        matte_values_count: 0,
        detect_samples_ms: [],
      };
      const started = performance.now();
      let backend = null;
      let source = null;
      try {
        backend = await backendModule.createWorkerSegmenterBackend({ delegate });
        result.status = 'init_ok';
        result.selected_backend = backend?.kind || 'none';
        result.labels = Array.isArray(backend?.labels) ? backend.labels.map((label) => sanitize(label, 80)) : [];
        source = createSyntheticVideo();
        await source.video.play().catch(() => {});
        await waitForVideo(source.video);
        for (let index = 0; index < sampleFrames; index += 1) {
          source.draw();
          const segmentation = backend.nextFaces(source.video, 256, 144, performance.now());
          if (typeof segmentation?.detectSampleMs === 'number') {
            result.segment_result_count += 1;
            result.detect_samples_ms.push(Math.max(0, Number(segmentation.detectSampleMs.toFixed(3))));
          }
          if (segmentation?.matteMaskBitmap) {
            result.matte_bitmap_count += 1;
            try { segmentation.matteMaskBitmap.close?.(); } catch {}
          }
          if (segmentation?.matteMaskValues instanceof Float32Array) {
            result.matte_values_count += 1;
          }
          await delay(100);
        }
      } catch (error) {
        result.status = result.status === 'init_ok' ? 'segment_failed' : 'init_failed';
        result.init_error = errorPayload(error);
      } finally {
        result.init_ms = Math.max(0, Number((performance.now() - started).toFixed(3)));
        try { backend?.dispose?.(); } catch {}
        try { source?.dispose?.(); } catch {}
      }
      return result;
    }

    async function probeProductionStream(streamModule) {
      const result = {
        status: 'not_run',
        selected_backend: 'none',
        active: false,
        reason: 'not_run',
        mode: 'blur',
        unavailable_callbacks: [],
        diagnostics: [],
        error: null,
      };
      let source = null;
      let handle = null;
      try {
        localStorage.removeItem(diagnosticsStorageKey);
        source = createSyntheticVideo();
        await source.video.play().catch(() => {});
        await waitForVideo(source.video);
        handle = await streamModule.createBackgroundFilterStream(source.stream, {
          detectIntervalMs: 1,
          maxProcessFps: 12,
          maxProcessWidth: 256,
          mode: 'blur',
          onSegmentationUnavailable: (details = {}) => {
            result.unavailable_callbacks.push(JSON.parse(JSON.stringify(details)));
          },
          sourceActive: true,
        });
        await Promise.race([
          handle.ready || Promise.resolve(),
          delay(Math.min(2500, timeoutMs)),
        ]);
        for (let index = 0; index < sampleFrames; index += 1) {
          source.draw();
          await delay(100);
        }
        result.status = 'ok';
        result.diagnostics = readDiagnostics();
        result.selected_backend = handle?.backend && handle.backend !== 'none'
          ? handle.backend
          : selectedBackendFromDiagnostics(result.diagnostics);
        result.active = Boolean(handle?.active);
        result.reason = sanitize(handle?.reason || '');
        result.mode = sanitize(handle?.mode || 'blur');
      } catch (error) {
        result.status = 'failed';
        result.error = errorPayload(error);
        result.diagnostics = readDiagnostics();
      } finally {
        try { handle?.dispose?.(); } catch {}
        try { source?.dispose?.(); } catch {}
      }
      return result;
    }

    const backendModule = await import('/src/domain/realtime/background/backendWorkerSegmenter.js');
    const streamModule = await import('/src/domain/realtime/background/stream.ts');
    const runtimeDiagnostics = await import('/src/domain/realtime/background/diagnostics/runtimeDiagnostics.js');

    const gpu = gpuAvailability();
    const model = runtimeDiagnostics.resolveBackgroundModelDescriptor();
    const workerGpu = await probeWorkerDelegate('GPU', backendModule);
    await delay(300);
    const workerCpu = await probeWorkerDelegate('CPU', backendModule);
    await delay(300);
    const productionStream = await probeProductionStream(streamModule);

    return {
      browser_family: browserFamily(),
      captured_at: new Date().toISOString(),
      duration_ms: Math.max(0, Number((performance.now() - startedAt).toFixed(3))),
      gpu_availability: gpu.gpu_available ? gpu.gpu_api : 'unavailable',
      gpu_available: Boolean(gpu.gpu_available),
      gpu_api: gpu.gpu_api || 'none',
      model_asset: model.model_asset,
      model_source: model.model_source,
      navigator_user_agent: navigator.userAgent || '',
      path_results: {
        mediapipe_worker_direct: {
          gpu: workerGpu,
          cpu: workerCpu,
        },
        king_production_background_stream: productionStream,
      },
      selected_backend: productionStream.selected_backend || workerGpu.selected_backend || 'none',
      diagnostics: productionStream.diagnostics || [],
      browser_side_events: Array.isArray(window.__bgfCaptureEvents) ? window.__bgfCaptureEvents : [],
    };
  }, {
    diagnosticsStorageKey: DIAGNOSTICS_STORAGE_KEY,
    sampleFrames: options.sampleFrames,
    timeoutMs: options.timeoutMs,
  });
}

async function runCapture(options) {
  const nodeEvents = [];
  const launcher = browserLauncher(options.browser);
  const launchOptions = launchOptionsFor(options);
  assertBrowserExecutable(options, launcher, launchOptions);
  let server = null;
  let browser = null;
  let context = null;
  try {
    const startedServer = await startVite(options.port);
    server = startedServer.server;
    const baseURL = startedServer.baseURL;
    browser = await launcher.launch(launchOptions);
    context = await browser.newContext({
      baseURL,
      permissions: ['camera', 'microphone'],
    });
    const page = await context.newPage();
    installNodePageMonitors(page, nodeEvents);
    await installBrowserSideMonitor(page);
    await page.goto(`${baseURL}/__bgf-background-regression-capture.html`, {
      waitUntil: 'domcontentloaded',
      timeout: options.timeoutMs,
    });
    const browserVersion = browser.version();
    const pageEvidence = await withTimeout(
      captureInPage(page, options),
      options.timeoutMs,
      'background_regression_capture',
    );
    const combinedEvents = uniqueByText([
      ...nodeEvents,
      ...(pageEvidence.browser_side_events || []),
    ], 80);
    const consoleSignatures = matchedSignatures(combinedEvents);
    const cpuEvents = delegateEventWindow(combinedEvents, 'CPU');
    const cpuGpuTouchSignatures = matchedGpuTouchEvents(cpuEvents);
    const cpuDelegateGpuTouch = cpuGpuTouchSignatures.length > 0;

    return {
      schema_version: 'king.bgf.browser_regression_capture.v1',
      browser: options.label,
      browser_engine: options.browser,
      version: browserVersion,
      os: `${os.type()} ${os.release()} ${os.arch()}`,
      captured_at: pageEvidence.captured_at,
      runner: {
        script: 'tests/e2e/background-regression-capture.mjs',
        base_url: baseURL,
        executable_path: options.executable || launcher.executablePath(),
        headless: options.headless,
        sample_frames: options.sampleFrames,
      },
      browser_family: pageEvidence.browser_family,
      user_agent: pageEvidence.navigator_user_agent,
      gpu_availability: pageEvidence.gpu_availability,
      gpu_available: pageEvidence.gpu_available,
      gpu_api: pageEvidence.gpu_api,
      model_asset: pageEvidence.model_asset,
      model_source: pageEvidence.model_source,
      selected_backend: pageEvidence.selected_backend,
      mediapipe_gpu_result: pageEvidence.path_results.mediapipe_worker_direct.gpu.status,
      mediapipe_cpu_result: pageEvidence.path_results.mediapipe_worker_direct.cpu.status,
      cpu_delegate_gpu_touch: {
        observed: cpuDelegateGpuTouch,
        gpu_touch_signatures: cpuGpuTouchSignatures,
        console_signatures: consoleSignatures.filter((event) => event.groups.includes('chromium_gpu_service')),
      },
      console_signatures: consoleSignatures,
      diagnostics: pageEvidence.diagnostics,
      path_results: pageEvidence.path_results,
      event_sample: combinedEvents.slice(0, 40),
    };
  } finally {
    await Promise.allSettled([
      context ? withTimeout(context.close(), 5000, 'browser_context_close') : Promise.resolve(),
      browser ? withTimeout(browser.close(), 5000, 'browser_close') : Promise.resolve(),
      server ? withTimeout(server.close(), 5000, 'vite_server_close') : Promise.resolve(),
    ]);
  }
}

async function main() {
  const options = parseArgs(process.argv.slice(2));
  if (options.help) {
    process.stdout.write(usage());
    return;
  }
  if (options.dryRun) {
    process.stdout.write(`${JSON.stringify({
      dry_run: true,
      browser: options.browser,
      executable: options.executable || browserLauncher(options.browser).executablePath(),
      label: options.label,
      headless: options.headless,
      port: options.port,
      sample_frames: options.sampleFrames,
      write: options.write,
      out: options.out || 'test-results/bgf-background-regression/<label>-<version>.json',
    }, null, 2)}\n`);
    return;
  }

  const evidence = await runCapture(options);
  if (options.write) {
    const outPath = outputPathFor(options, evidence);
    fs.mkdirSync(path.dirname(outPath), { recursive: true });
    fs.writeFileSync(outPath, `${JSON.stringify(evidence, null, 2)}\n`);
    evidence.output_path = path.relative(frontendRoot, outPath);
  }
  process.stdout.write(`${JSON.stringify(evidence, null, 2)}\n`);
}

main().catch((error) => {
  console.error('[background-regression-capture] FAIL');
  console.error(error?.stack || error?.message || error);
  process.exit(1);
});
