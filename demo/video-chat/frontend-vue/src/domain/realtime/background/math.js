export function toNumber(value, fallback) {
  return typeof value === "number" && Number.isFinite(value) ? value : fallback;
}

export function lerp(a, b, t) {
  return a + (b - a) * t;
}

export function clamp01(value) {
  return Math.max(0, Math.min(1, value));
}

export function smoothstep(edge0, edge1, x) {
  const t = clamp01((x - edge0) / Math.max(1e-6, edge1 - edge0));
  return t * t * (3 - 2 * t);
}
