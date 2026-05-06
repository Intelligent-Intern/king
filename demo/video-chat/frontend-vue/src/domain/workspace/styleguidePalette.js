export const STYLEGUIDE_PALETTE = Object.freeze({
  '--color-primary-navy': '#000010',
  '--color-surface-navy': '#00052d',
  '--color-cyan-primary': '#1582bf',
  '--color-cyan-hover': '#59c7f2',
  '--color-heading': '#efefe7',
  '--color-text-primary': '#ffffff',
  '--color-text-link': '#1582bf',
  '--color-text-link-hover': '#59c7f2',
  '--color-border': '#03275a',
  '--color-success': '#00652f',
  '--color-warning': '#f47221',
  '--color-error': '#ef4423',
});

export const STYLEGUIDE_ALLOWED_HEX = Object.freeze(new Set(Object.values(STYLEGUIDE_PALETTE)));

export const STYLEGUIDE_COLOR_FIELDS = Object.freeze([
  { key: '--color-primary-navy', labelKey: 'theme_settings.color.primary_navy', default: STYLEGUIDE_PALETTE['--color-primary-navy'] },
  { key: '--color-surface-navy', labelKey: 'theme_settings.color.surface_navy', default: STYLEGUIDE_PALETTE['--color-surface-navy'] },
  { key: '--color-cyan-primary', labelKey: 'theme_settings.color.cyan_primary', default: STYLEGUIDE_PALETTE['--color-cyan-primary'] },
  { key: '--color-cyan-hover', labelKey: 'theme_settings.color.cyan_hover', default: STYLEGUIDE_PALETTE['--color-cyan-hover'] },
  { key: '--color-heading', labelKey: 'theme_settings.color.heading', default: STYLEGUIDE_PALETTE['--color-heading'] },
  { key: '--color-text-primary', labelKey: 'theme_settings.color.text_primary', default: STYLEGUIDE_PALETTE['--color-text-primary'] },
  { key: '--color-text-link', labelKey: 'theme_settings.color.text_link', default: STYLEGUIDE_PALETTE['--color-text-link'] },
  { key: '--color-text-link-hover', labelKey: 'theme_settings.color.text_link_hover', default: STYLEGUIDE_PALETTE['--color-text-link-hover'] },
  { key: '--color-border', labelKey: 'theme_settings.color.border', default: STYLEGUIDE_PALETTE['--color-border'] },
  { key: '--color-success', labelKey: 'theme_settings.color.success', default: STYLEGUIDE_PALETTE['--color-success'] },
  { key: '--color-warning', labelKey: 'theme_settings.color.warning', default: STYLEGUIDE_PALETTE['--color-warning'] },
  { key: '--color-error', labelKey: 'theme_settings.color.error', default: STYLEGUIDE_PALETTE['--color-error'] },
]);

export const STYLEGUIDE_DERIVED_COLOR_KEYS = Object.freeze([
  '--bg-shell',
  '--bg-pane',
  '--brand-bg',
  '--bg-surface',
  '--bg-surface-strong',
  '--bg-input',
  '--bg-action',
  '--bg-action-hover',
  '--bg-row',
  '--bg-row-hover',
  '--line',
  '--text-main',
  '--text-muted',
  '--ok',
  '--wait',
  '--danger',
  '--bg-sidebar',
  '--bg-main',
  '--bg-tab',
  '--bg-tab-hover',
  '--bg-tab-active',
  '--bg-ui-chrome',
  '--bg-ui-chrome-active',
  '--bg-icon',
  '--bg-icon-active',
  '--border-subtle',
  '--text-primary',
  '--text-secondary',
  '--text-dim',
  '--warn',
  '--brand-cyan',
  '--brand-cyan-hover',
  '--brand-cyan-active',
]);

const LIGHT_THEME_OVERRIDES = Object.freeze({
  '--color-primary-navy': '#efefe7',
  '--color-surface-navy': '#ffffff',
  '--color-heading': '#000010',
  '--color-text-primary': '#000010',
});

export function normalizeStyleguideHex(value, fallback = STYLEGUIDE_PALETTE['--color-primary-navy']) {
  const normalized = String(value || '').trim().toLowerCase();
  if (/^#[a-f0-9]{6}$/.test(normalized)) return normalized;
  if (/^[a-f0-9]{6}$/.test(normalized)) return `#${normalized}`;
  if (/^#[a-f0-9]{3}$/.test(normalized)) {
    return `#${normalized[1]}${normalized[1]}${normalized[2]}${normalized[2]}${normalized[3]}${normalized[3]}`;
  }
  return fallback;
}

export function defaultWorkspaceThemeColors(themeId = 'dark') {
  const isLight = String(themeId || '').trim().toLowerCase() === 'light';
  const colors = {};
  for (const field of STYLEGUIDE_COLOR_FIELDS) {
    colors[field.key] = isLight && LIGHT_THEME_OVERRIDES[field.key]
      ? LIGHT_THEME_OVERRIDES[field.key]
      : field.default;
  }
  return colors;
}

export function normalizeStyleguideThemeColors(colors = {}, fallbackColors = defaultWorkspaceThemeColors()) {
  const source = colors && typeof colors === 'object' ? colors : {};
  const fallback = fallbackColors && typeof fallbackColors === 'object' ? fallbackColors : {};
  const normalized = {};
  for (const field of STYLEGUIDE_COLOR_FIELDS) {
    const fallbackValue = normalizeStyleguideHex(fallback[field.key], field.default);
    normalized[field.key] = normalizeStyleguideHex(source[field.key], fallbackValue);
  }
  return normalized;
}
