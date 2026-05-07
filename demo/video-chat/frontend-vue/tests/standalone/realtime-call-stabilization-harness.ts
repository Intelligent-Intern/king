import {
  rollingActivityScore as kingRollingActivityScore,
  selectCallLayoutParticipants,
} from '../../src/domain/realtime/layout/strategies.ts';

type Speaker = {
  id: number;
  name: string;
  initials: string;
  color: string;
  pattern: string;
  patternSize: string;
  samples: Array<{ score: number; at: number }>;
  readyAt: number;
};

type HarnessState = {
  running: boolean;
  startedAt: number;
  mainId: number;
  lastSwitchAt: number;
  lastSampleAt: number;
  log: string[];
  manualSpikeUntil: number;
};

const TOP_K_SAMPLE_COUNT = 3;
const SAMPLE_INTERVAL_MS = 250;

const people: Speaker[] = [
  {
    id: 1,
    name: 'Speaker A Sustained',
    initials: 'A',
    color: '#31d17c',
    pattern: 'repeating-linear-gradient(90deg, rgba(255,255,255,0.24) 0 10px, transparent 10px 26px)',
    patternSize: '52px 52px',
    samples: [],
    readyAt: 700,
  },
  {
    id: 2,
    name: 'Speaker B Spike',
    initials: 'B',
    color: '#ffb14a',
    pattern: 'repeating-linear-gradient(45deg, rgba(255,255,255,0.26) 0 8px, transparent 8px 22px)',
    patternSize: '44px 44px',
    samples: [],
    readyAt: 1100,
  },
  {
    id: 3,
    name: 'Speaker C Alternating',
    initials: 'C',
    color: '#7aa7ff',
    pattern: 'radial-gradient(circle, rgba(255,255,255,0.28) 0 4px, transparent 5px)',
    patternSize: '28px 28px',
    samples: [],
    readyAt: 1500,
  },
];

const state: HarnessState = {
  running: true,
  startedAt: performance.now(),
  mainId: 1,
  lastSwitchAt: 0,
  lastSampleAt: 0,
  log: [],
  manualSpikeUntil: 0,
};

function elementById<T extends HTMLElement>(id: string): T {
  const element = document.getElementById(id);
  if (!(element instanceof HTMLElement)) {
    throw new Error(`Missing harness element #${id}`);
  }
  return element as T;
}

function clamp(value: number, min: number, max: number): number {
  return Math.max(min, Math.min(max, value));
}

function scenarioScore(person: Speaker, elapsedMs: number): number {
  const t = elapsedMs / 1000;
  if (person.id === 1) return 56 + Math.sin(t * 4.2) * 6;
  if (person.id === 2) {
    if (performance.now() < state.manualSpikeUntil) return 100;
    return 8 + Math.sin(t * 2.8) * 4;
  }
  if (person.id === 3) {
    const phaseMs = elapsedMs % 9000;
    if (phaseMs > 3600 && phaseMs < 6900) return 78 + Math.sin(t * 5.1) * 7;
    return 20 + Math.sin(t * 3.7) * 8;
  }
  return 0;
}

function appendSample(person: Speaker, now: number, score: number): void {
  person.samples.push({ score: clamp(score, 0, 100), at: now });
  const cutoff = now - 15000;
  person.samples = person.samples.filter((sample) => sample.at >= cutoff).slice(-64);
}

function decayed(sample: { score: number; at: number }, now: number, windowMs: number): number {
  const age = Math.max(0, now - sample.at);
  if (age >= windowMs) return 0;
  return sample.score * (1 - (age / windowMs));
}

function topK(person: Speaker, now: number, windowMs: number): number {
  const scores = person.samples
    .map((sample) => decayed(sample, now, windowMs))
    .filter((score) => score > 0)
    .sort((a, b) => b - a)
    .slice(0, TOP_K_SAMPLE_COUNT);
  if (scores.length === 0) return 0;
  return scores.reduce((sum, score) => sum + score, 0) / TOP_K_SAMPLE_COUNT;
}

function kingActivityEntry(person: Speaker, now: number) {
  return {
    score: person.samples.at(-1)?.score || 0,
    topk_score_2s: topK(person, now, 2000),
    topk_score_5s: topK(person, now, 5000),
    topk_score_15s: topK(person, now, 15000),
    updated_at_ms: now,
  };
}

function activityByUserId(now: number): Record<number, ReturnType<typeof kingActivityEntry>> {
  return Object.fromEntries(people.map((person) => [person.id, kingActivityEntry(person, now)]));
}

function rollingScore(person: Speaker, now: number): number {
  return kingRollingActivityScore(kingActivityEntry(person, now));
}

function speakerStyle(person: Speaker): string {
  return [
    `--speaker-color:${person.color}`,
    `--speaker-pattern:${person.pattern}`,
    `--speaker-pattern-size:${person.patternSize}`,
  ].join(';');
}

function chooseMain(now: number): void {
  const selection = selectCallLayoutParticipants({
    participants: people.map((person) => ({
      userId: person.id,
      displayName: person.name,
      role: 'user',
      callRole: 'participant',
    })),
    currentUserId: 1,
    pinnedUsers: {},
    activityByUserId: activityByUserId(now),
    layoutState: {
      mode: 'main_mini',
      strategy: 'active_speaker_main',
      automation_paused: false,
      main_user_id: state.mainId,
      selected_user_ids: people.map((person) => person.id),
    },
    nowMs: now,
  });
  if (state.mainId !== selection.mainUserId) {
    const previous = people.find((person) => person.id === state.mainId);
    const next = people.find((person) => person.id === selection.mainUserId);
    state.mainId = selection.mainUserId;
    state.lastSwitchAt = now;
    state.log.unshift(`${previous?.name || 'Unknown'} -> ${next?.name || 'Unknown'}`);
    state.log = state.log.slice(0, 7);
  }
}

function renderTile(person: Speaker, now: number): string {
  const ready = now - state.startedAt >= person.readyAt;
  const score = rollingScore(person, now);
  return `
    <article class="tile ${ready ? 'ready' : ''}" data-user-id="${person.id}" style="${speakerStyle(person)}">
      <div class="tile-media"></div>
      <div class="tile-placeholder">
        <span class="initials">${person.initials}</span>
      </div>
      <span class="tile-score">${score.toFixed(0)}</span>
      <span class="tile-name">${person.name}</span>
    </article>
  `;
}

function render(now: number): void {
  const main = people.find((person) => person.id === state.mainId) || people[0];
  const mainMedia = elementById('mainMedia');
  mainMedia.setAttribute('style', speakerStyle(main));
  mainMedia.innerHTML = `
    <div class="main-pattern"></div>
    <div class="main-avatar">${main.initials}</div>
  `;
  mainMedia.style.setProperty('--motion-x', `${Math.sin(now / (main.id === 1 ? 260 : main.id === 2 ? 120 : 190)) * (main.id === 2 ? 58 : 34)}px`);
  mainMedia.style.setProperty('--motion-y', `${Math.cos(now / (main.id === 1 ? 310 : main.id === 2 ? 140 : 230)) * (main.id === 2 ? 46 : 24)}px`);
  elementById('mainName').innerHTML = `<strong>MAIN SPEAKER ${main.initials}</strong>${main.name}`;
  elementById('miniStrip').innerHTML = people
    .filter((person) => person.id !== state.mainId)
    .map((person) => renderTile(person, now))
    .join('');

  const ranked = people
    .map((person) => ({
      person,
      score: rollingScore(person, now),
      raw: scenarioScore(person, now - state.startedAt),
    }))
    .sort((a, b) => b.score - a.score);
  elementById('legend').innerHTML = people.map((person) => `
    <div class="legend" style="${speakerStyle(person)}">
      <span class="swatch"></span>
      <span><strong>${person.initials}</strong> ${person.name}</span>
    </div>
  `).join('');
  elementById('metrics').innerHTML = ranked.map((row) => `
    <div class="metric ${row.person.id === state.mainId ? 'speaker' : ''}" style="${speakerStyle(row.person)}">
      <span class="metric-name">
        ${row.person.initials}
        <small>${row.person.name}</small>
      </span>
      <span>
        <span class="bar"><span class="fill" style="--w:${Math.round(row.score)}%"></span></span>
        <span class="bar raw-bar"><span class="fill" style="--w:${Math.round(row.raw)}%"></span></span>
      </span>
      <span>${row.score.toFixed(1)}</span>
    </div>
  `).join('');
  elementById('log').innerHTML = state.log.length
    ? state.log.map((entry) => `<span>${entry}</span>`).join('')
    : '<span>No speaker switches yet.</span>';
}

function tick(now: number): void {
  if (state.running) {
    if (now - state.lastSampleAt >= SAMPLE_INTERVAL_MS) {
      state.lastSampleAt = now;
      const elapsed = now - state.startedAt;
      for (const person of people) {
        appendSample(person, now, scenarioScore(person, elapsed));
      }
      chooseMain(now);
    }
  }
  render(now);
  requestAnimationFrame(tick);
}

elementById<HTMLButtonElement>('playPause').addEventListener('click', (event) => {
  state.running = !state.running;
  if (event.currentTarget instanceof HTMLButtonElement) {
    event.currentTarget.textContent = state.running ? 'Pause' : 'Play';
  }
});

elementById<HTMLButtonElement>('singleSpike').addEventListener('click', () => {
  state.manualSpikeUntil = performance.now() + 700;
  state.log.unshift('Injected a single Speaker B spike. Speaker A should recover if sustained.');
  state.log = state.log.slice(0, 7);
});

elementById<HTMLButtonElement>('reset').addEventListener('click', () => {
  for (const person of people) person.samples = [];
  state.startedAt = performance.now();
  state.mainId = 1;
  state.lastSampleAt = 0;
  state.log = [];
  state.manualSpikeUntil = 0;
});

requestAnimationFrame(tick);
