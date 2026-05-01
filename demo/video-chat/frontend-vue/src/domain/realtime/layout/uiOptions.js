const LAYOUT_MODE_OPTIONS = [
  { mode: 'grid', label: 'Grid', icon: 'G' },
  { mode: 'main_mini', label: 'Main + Mini', icon: 'M' },
  { mode: 'main_only', label: 'Main only', icon: '1' },
];

const LAYOUT_STRATEGY_LABELS = {
  manual_pinned: 'Manual / pinned',
  most_active_window: 'Most active window',
  active_speaker_main: 'Active speaker main',
  round_robin_active: 'Round robin active',
};

export function layoutModeOptionsFor(modes) {
  const allowedModes = Array.isArray(modes) ? modes : [];
  return LAYOUT_MODE_OPTIONS.filter((option) => allowedModes.includes(option.mode));
}

export function layoutStrategyLabel(strategy) {
  const normalized = String(strategy || '').trim().toLowerCase();
  return LAYOUT_STRATEGY_LABELS[normalized] || normalized || 'Strategy';
}

export function layoutStrategyOptionsFor(strategies) {
  const allowedStrategies = Array.isArray(strategies) ? strategies : [];
  return allowedStrategies.map((strategy) => ({
    strategy,
    label: layoutStrategyLabel(strategy),
  }));
}

export function gridVideoSlotId(userId) {
  const normalizedUserId = Number(userId);
  return `workspace-grid-video-slot-${
    Number.isInteger(normalizedUserId) && normalizedUserId > 0 ? normalizedUserId : 'unknown'
  }`;
}
