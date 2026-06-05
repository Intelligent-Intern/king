import http from 'node:http';
import { setTimeout as delay } from 'node:timers/promises';

import { chromium } from '@playwright/test';

const host = String(process.env.VIDEOCHAT_SPUTNIK_RUNNER_HOST || '0.0.0.0');
const port = Number.parseInt(String(process.env.VIDEOCHAT_SPUTNIK_RUNNER_PORT || '19090'), 10) || 19090;
const defaultTimeoutMs = Math.max(10_000, Number.parseInt(String(process.env.VIDEOCHAT_SPUTNIK_JOIN_TIMEOUT_MS || '90000'), 10) || 90_000);
const defaultMaxRunMs = Math.max(60_000, Number.parseInt(String(process.env.VIDEOCHAT_SPUTNIK_MAX_RUN_MS || `${2 * 60 * 60 * 1000}`), 10) || 2 * 60 * 60 * 1000);
const defaultMonitorIntervalMs = Math.max(1_000, Number.parseInt(String(process.env.VIDEOCHAT_SPUTNIK_MONITOR_INTERVAL_MS || '2500'), 10) || 2_500);
const defaultRestartDelayMs = Math.max(1_000, Number.parseInt(String(process.env.VIDEOCHAT_SPUTNIK_RESTART_DELAY_MS || '5000'), 10) || 5_000);
const parsedDefaultMaxRestarts = Number.parseInt(String(process.env.VIDEOCHAT_SPUTNIK_MAX_RESTARTS ?? '5'), 10);
const defaultMaxRestarts = Math.max(0, Number.isFinite(parsedDefaultMaxRestarts) ? parsedDefaultMaxRestarts : 5);
const maxParticipants = Math.max(1, Math.min(50, Number.parseInt(String(process.env.VIDEOCHAT_SPUTNIK_MAX_PARTICIPANTS || '25'), 10) || 25));
const executablePath = String(process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH || '').trim() || undefined;

const tones = Object.freeze([330, 349, 392, 440, 494, 523, 587, 659, 740, 831, 880, 988, 1047, 1175, 1319]);
const jobs = new Map();

function nowIso() {
  return new Date().toISOString();
}

function boundedInt(value, fallback, min, max) {
  const parsed = Number.parseInt(String(value ?? ''), 10);
  if (!Number.isFinite(parsed)) return fallback;
  return Math.max(min, Math.min(max, parsed));
}

function jsonResponse(response, statusCode, payload) {
  const body = `${JSON.stringify(payload, null, 2)}\n`;
  response.writeHead(statusCode, {
    'cache-control': 'no-store',
    'content-length': Buffer.byteLength(body),
    'content-type': 'application/json; charset=utf-8',
  });
  response.end(body);
}

async function readJson(request) {
  const chunks = [];
  for await (const chunk of request) chunks.push(chunk);
  if (chunks.length === 0) return {};
  const raw = Buffer.concat(chunks).toString('utf8').trim();
  if (raw === '') return {};
  return JSON.parse(raw);
}

function summarizeParticipant(participant) {
  return {
    index: participant.index,
    last_seen_at: participant.lastSeenAt || null,
    last_state_check_at: participant.lastStateCheckAt || null,
    name: participant.name,
    restart_count: participant.restartCount || 0,
    restart_reason: participant.restartReason || '',
    state: participant.state,
    url: participant.url,
    error: participant.error || '',
    updated_at: participant.updatedAt,
  };
}

function jobParticipantCounts(job) {
  const counts = {
    closed_count: 0,
    failed_count: 0,
    lobby_count: 0,
    restarting_count: 0,
    starting_count: 0,
    waiting_count: 0,
    workspace_count: 0,
  };
  for (const participant of job.participants) {
    const state = String(participant.state || '').trim().toLowerCase();
    if (state === 'workspace') counts.workspace_count += 1;
    else if (state === 'lobby') counts.lobby_count += 1;
    else if (state === 'closed') counts.closed_count += 1;
    else if (state === 'failed') counts.failed_count += 1;
    else if (state === 'restarting') counts.restarting_count += 1;
    else if (state === 'starting') counts.starting_count += 1;
    else if (state === 'waiting') counts.waiting_count += 1;
  }
  return counts;
}

function summarizeJob(job) {
  const counts = jobParticipantCounts(job);
  return {
    call_id: job.callId,
    ...counts,
    count: job.count,
    created_at: job.createdAt,
    error: job.error || '',
    healthy_count: counts.workspace_count + counts.lobby_count + counts.waiting_count,
    participant_count: job.participants.length,
    participants: job.participants.map(summarizeParticipant),
    state: job.state,
    stopped_at: job.stoppedAt || null,
    updated_at: job.updatedAt,
  };
}

function sputnikJoinUrl(joinUrl, index, options) {
  const url = new URL(joinUrl);
  const number = String(index + 1).padStart(2, '0');
  url.searchParams.set('sputnik', '1');
  url.searchParams.set('sputnik_auto_join', '1');
  url.searchParams.set('auto_join', '1');
  url.searchParams.set('sputnik_name', `Sputnik ${number}`);
  url.searchParams.set('sputnik_tone', String(tones[index % tones.length]));
  url.searchParams.set('sputnik_fps', String(options.fps));
  url.searchParams.set('sputnik_w', String(options.width));
  url.searchParams.set('sputnik_h', String(options.height));
  return url.toString();
}

function terminalJobState(job) {
  return ['stopped', 'expired', 'failed'].includes(String(job.state || '').trim().toLowerCase());
}

function clearParticipantMonitor(participant) {
  if (!participant?.monitorTimer) return;
  clearInterval(participant.monitorTimer);
  participant.monitorTimer = null;
}

function updateJobState(job) {
  if (terminalJobState(job)) return;
  const counts = jobParticipantCounts(job);
  if (job.participants.length < job.count || counts.starting_count > 0 || counts.restarting_count > 0) {
    job.state = 'starting';
  } else if (counts.failed_count > 0 || counts.closed_count > 0) {
    job.state = 'degraded';
  } else {
    job.state = 'running';
  }
  job.updatedAt = nowIso();
}

async function classifyParticipantPage(page, callId) {
  if (!page || page.isClosed()) return 'closed';
  const workspaceNeedle = `/workspace/call/${callId}`;
  try {
    if (new URL(page.url()).pathname === workspaceNeedle) return 'workspace';
  } catch {
    // keep probing DOM below
  }
  const waitingModal = page.locator('.call-access-join-modal').filter({ hasText: /Call owner has been notified|Waiting for host/i }).first();
  if (await waitingModal.isVisible({ timeout: 200 }).catch(() => false)) return 'lobby';
  const joinModal = page.locator('.call-access-join-modal').first();
  if (await joinModal.isVisible({ timeout: 200 }).catch(() => false)) return 'waiting';
  return 'waiting';
}

function startParticipantMonitor(job, participant) {
  clearParticipantMonitor(participant);
  participant.monitorTimer = setInterval(() => {
    if (terminalJobState(job)) {
      clearParticipantMonitor(participant);
      return;
    }
    void (async () => {
      if (!participant.page || participant.page.isClosed()) {
        if (participant.state !== 'restarting') {
          participant.state = 'closed';
          participant.updatedAt = nowIso();
          updateJobState(job);
        }
        return;
      }
      try {
        const observedState = await classifyParticipantPage(participant.page, job.callId);
        participant.lastSeenAt = nowIso();
        participant.lastStateCheckAt = participant.lastSeenAt;
        if (participant.state !== observedState && participant.state !== 'restarting') {
          participant.state = observedState;
          participant.updatedAt = participant.lastSeenAt;
          updateJobState(job);
        }
      } catch (error) {
        participant.error = error instanceof Error ? error.message.slice(0, 300) : String(error || '').slice(0, 300);
        participant.lastStateCheckAt = nowIso();
        participant.updatedAt = participant.lastStateCheckAt;
      }
    })();
  }, job.options.monitorIntervalMs);
}

async function driveJoin(page, callId, name, timeoutMs) {
  const workspaceNeedle = `/workspace/call/${callId}`;
  const startedAt = Date.now();
  const hasWorkspaceUrl = () => {
    try {
      return new URL(page.url()).pathname === workspaceNeedle;
    } catch {
      return false;
    }
  };

  if (hasWorkspaceUrl()) return 'workspace';

  const remaining = () => Math.max(1_000, timeoutMs - (Date.now() - startedAt));
  const dialog = page.locator('.call-access-join-modal').first();
  await dialog.waitFor({ state: 'visible', timeout: Math.min(45_000, remaining()) }).catch(() => null);

  if (!hasWorkspaceUrl()) {
    const guestNameInput = dialog.locator('.call-access-join-guest-name input, input[placeholder*="display name" i], input[type="text"]').first();
    if (await guestNameInput.isVisible({ timeout: 5_000 }).catch(() => false)) {
      await guestNameInput.fill(name).catch(() => null);
      await guestNameInput.evaluate((element, value) => {
        if (!(element instanceof HTMLInputElement)) return;
        element.value = value;
        element.dispatchEvent(new Event('input', { bubbles: true }));
        element.dispatchEvent(new Event('change', { bubbles: true }));
      }, name).catch(() => null);
    }

    const joinButton = dialog.getByRole('button', { name: /^Join call$/i });
    if (await joinButton.isVisible({ timeout: 5_000 }).catch(() => false)) {
      await joinButton.click().catch(() => null);
    }
  }

  const outcome = await Promise.race([
    page.waitForURL((url) => url.pathname === workspaceNeedle, { timeout: remaining() }).then(() => 'workspace').catch(() => null),
    page.locator('.call-access-join-modal').filter({ hasText: /Call owner has been notified|Waiting for host/i }).waitFor({ state: 'visible', timeout: remaining() }).then(() => 'lobby').catch(() => null),
    delay(remaining()).then(() => null),
  ]);

  return outcome || (hasWorkspaceUrl() ? 'workspace' : 'waiting');
}

async function launchParticipant(job, index, existingParticipant = null) {
  const name = `Sputnik ${String(index + 1).padStart(2, '0')}`;
  const participant = existingParticipant || {
    browser: null,
    context: null,
    error: '',
    index,
    lastSeenAt: null,
    lastStateCheckAt: null,
    monitorTimer: null,
    name,
    page: null,
    restartCount: 0,
    restartInFlight: false,
    restartReason: '',
    state: 'starting',
    updatedAt: nowIso(),
    url: '',
  };
  clearParticipantMonitor(participant);
  participant.browser = null;
  participant.context = null;
  participant.error = '';
  participant.name = name;
  participant.page = null;
  participant.state = existingParticipant ? 'restarting' : 'starting';
  participant.updatedAt = nowIso();
  if (!existingParticipant) job.participants.push(participant);
  job.updatedAt = nowIso();

  try {
    const browser = await chromium.launch({
      executablePath,
      headless: true,
      args: [
        '--autoplay-policy=no-user-gesture-required',
        '--disable-dev-shm-usage',
        '--no-sandbox',
        '--use-fake-ui-for-media-stream',
      ],
    });
    const context = await browser.newContext({
      ignoreHTTPSErrors: true,
      permissions: ['camera', 'microphone'],
      viewport: { width: 1280, height: 720 },
    });
    const page = await context.newPage();
    participant.browser = browser;
    participant.context = context;
    participant.page = page;
    participant.url = sputnikJoinUrl(job.joinUrl, index, job.options);

    page.on('pageerror', (error) => {
      participant.error = error instanceof Error ? error.message.slice(0, 300) : String(error || '').slice(0, 300);
      participant.updatedAt = nowIso();
    });
    page.on('close', () => {
      if (!['stopped', 'restarting'].includes(participant.state)) {
        participant.state = 'closed';
        participant.updatedAt = nowIso();
        updateJobState(job);
      }
    });
    page.on('crash', () => {
      participant.state = 'closed';
      participant.error = 'page_crashed';
      participant.updatedAt = nowIso();
      updateJobState(job);
    });

    await page.goto(participant.url, { waitUntil: 'domcontentloaded', timeout: job.options.timeoutMs });
    participant.state = await driveJoin(page, job.callId, name, job.options.timeoutMs);
    participant.lastSeenAt = nowIso();
    participant.lastStateCheckAt = participant.lastSeenAt;
    participant.updatedAt = nowIso();
    startParticipantMonitor(job, participant);
  } catch (error) {
    participant.state = 'failed';
    participant.error = error instanceof Error ? error.message.slice(0, 500) : String(error || '').slice(0, 500);
    participant.updatedAt = nowIso();
    clearParticipantMonitor(participant);
    await participant.browser?.close?.().catch(() => null);
  } finally {
    participant.restartInFlight = false;
    updateJobState(job);
  }
}

async function restartParticipant(job, participant, reason = 'participant_unhealthy') {
  if (terminalJobState(job) || participant.restartInFlight) return;
  if ((participant.restartCount || 0) >= job.options.maxRestarts) {
    participant.state = 'failed';
    participant.error = `restart_limit_reached:${reason}`;
    participant.updatedAt = nowIso();
    updateJobState(job);
    return;
  }
  participant.restartInFlight = true;
  participant.restartCount = (participant.restartCount || 0) + 1;
  participant.restartReason = reason;
  participant.state = 'restarting';
  participant.updatedAt = nowIso();
  updateJobState(job);
  clearParticipantMonitor(participant);
  await participant.context?.close?.().catch(() => null);
  await participant.browser?.close?.().catch(() => null);
  await delay(job.options.restartDelayMs);
  if (terminalJobState(job)) {
    participant.restartInFlight = false;
    return;
  }
  await launchParticipant(job, participant.index, participant);
}

function startJobSupervisor(job) {
  if (job.supervisorTimer) clearInterval(job.supervisorTimer);
  job.supervisorTimer = setInterval(() => {
    if (terminalJobState(job)) {
      clearInterval(job.supervisorTimer);
      job.supervisorTimer = null;
      return;
    }
    for (const participant of job.participants) {
      if (['closed', 'failed'].includes(participant.state)) {
        void restartParticipant(job, participant, participant.state === 'failed' ? 'launch_failed' : 'page_closed');
      }
    }
    updateJobState(job);
  }, Math.max(job.options.monitorIntervalMs, 1_000));
}

async function stopJob(callId, state = 'stopped') {
  const job = jobs.get(callId);
  if (!job) return null;
  job.state = state;
  job.stoppedAt = nowIso();
  job.updatedAt = nowIso();
  if (job.timer) clearTimeout(job.timer);
  if (job.supervisorTimer) clearInterval(job.supervisorTimer);
  await Promise.allSettled(job.participants.map(async (participant) => {
    clearParticipantMonitor(participant);
    participant.state = 'stopped';
    participant.updatedAt = nowIso();
    await participant.context?.close?.().catch(() => null);
    await participant.browser?.close?.().catch(() => null);
  }));
  return job;
}

async function runJob(job) {
  job.state = 'starting';
  job.updatedAt = nowIso();
  const batchSize = job.options.batchSize;
  try {
    for (let offset = 0; offset < job.count; offset += batchSize) {
      if (job.state === 'stopped' || job.state === 'expired') break;
      await Promise.all(Array.from({ length: Math.min(batchSize, job.count - offset) }, (_, batchIndex) => launchParticipant(job, offset + batchIndex)));
      await delay(500);
    }
    if (job.state !== 'stopped' && job.state !== 'expired') {
      startJobSupervisor(job);
      updateJobState(job);
    }
  } catch (error) {
    job.error = error instanceof Error ? error.message.slice(0, 500) : String(error || '').slice(0, 500);
    job.state = 'failed';
    job.updatedAt = nowIso();
  }
}

function createJob(callId, payload) {
  const joinUrl = String(payload.join_url || payload.joinUrl || '').trim();
  if (joinUrl === '') {
    throw new Error('join_url is required');
  }
  const parsed = new URL(joinUrl);
  if (!['http:', 'https:'].includes(parsed.protocol)) {
    throw new Error('join_url must be http or https');
  }

  const job = {
    callId,
    count: boundedInt(payload.count, 10, 1, maxParticipants),
    createdAt: nowIso(),
    error: '',
    joinUrl: parsed.toString(),
    options: {
      batchSize: boundedInt(payload.batch_size ?? payload.batchSize, 3, 1, 8),
      fps: boundedInt(payload.fps, 10, 1, 30),
      height: boundedInt(payload.height, 360, 120, 1080),
      maxRestarts: boundedInt(payload.max_restarts ?? payload.maxRestarts, defaultMaxRestarts, 0, 25),
      monitorIntervalMs: boundedInt(payload.monitor_interval_ms ?? payload.monitorIntervalMs, defaultMonitorIntervalMs, 1_000, 60_000),
      restartDelayMs: boundedInt(payload.restart_delay_ms ?? payload.restartDelayMs, defaultRestartDelayMs, 1_000, 5 * 60_000),
      timeoutMs: boundedInt(payload.timeout_ms ?? payload.timeoutMs, defaultTimeoutMs, 10_000, 5 * 60_000),
      width: boundedInt(payload.width, 640, 160, 1920),
    },
    participants: [],
    state: 'accepted',
    stoppedAt: null,
    supervisorTimer: null,
    timer: null,
    updatedAt: nowIso(),
  };
  job.timer = setTimeout(() => {
    void stopJob(callId, 'expired').catch(() => null);
  }, boundedInt(payload.max_run_ms ?? payload.maxRunMs, defaultMaxRunMs, 60_000, 12 * 60 * 60 * 1000));
  return job;
}

async function handleRequest(request, response) {
  const requestUrl = new URL(request.url || '/', `http://${request.headers.host || 'sputnik-runner.local'}`);
  if (requestUrl.pathname === '/health') {
    jsonResponse(response, 200, {
      status: 'ok',
      jobs: Array.from(jobs.values()).map(summarizeJob),
      time: nowIso(),
    });
    return;
  }

  const match = /^\/jobs\/([^/]+)$/.exec(requestUrl.pathname);
  if (!match) {
    jsonResponse(response, 404, { error: 'not_found', status: 'error', time: nowIso() });
    return;
  }

  const callId = decodeURIComponent(match[1]).trim();
  if (callId === '') {
    jsonResponse(response, 422, { error: 'call_id_required', status: 'error', time: nowIso() });
    return;
  }

  if (request.method === 'GET') {
    const job = jobs.get(callId);
    jsonResponse(response, job ? 200 : 404, {
      status: job ? 'ok' : 'error',
      result: job ? summarizeJob(job) : null,
      error: job ? null : 'not_found',
      time: nowIso(),
    });
    return;
  }

  if (request.method === 'DELETE') {
    const job = await stopJob(callId);
    jsonResponse(response, 200, {
      status: 'ok',
      result: job ? summarizeJob(job) : { call_id: callId, state: 'not_running' },
      time: nowIso(),
    });
    return;
  }

  if (request.method !== 'POST') {
    jsonResponse(response, 405, { error: 'method_not_allowed', status: 'error', time: nowIso() });
    return;
  }

  const existing = jobs.get(callId);
  if (existing && !['stopped', 'expired', 'failed'].includes(existing.state)) {
    jsonResponse(response, 200, {
      status: 'ok',
      result: summarizeJob(existing),
      time: nowIso(),
    });
    return;
  }

  const payload = await readJson(request);
  const job = createJob(callId, payload);
  jobs.set(callId, job);
  void runJob(job);
  jsonResponse(response, 202, {
    status: 'ok',
    result: summarizeJob(job),
    time: nowIso(),
  });
}

const server = http.createServer((request, response) => {
  handleRequest(request, response).catch((error) => {
    jsonResponse(response, 500, {
      error: error instanceof Error ? error.message : String(error || 'internal_error'),
      status: 'error',
      time: nowIso(),
    });
  });
});

server.listen(port, host, () => {
  process.stdout.write(`[sputnik-runner] listening on ${host}:${port}\n`);
});
