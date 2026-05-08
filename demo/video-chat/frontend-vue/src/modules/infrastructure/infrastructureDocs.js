export const INFRASTRUCTURE_PROVIDER_KEYS = Object.freeze([
  'codesphere',
  'hetzner',
  'intares',
  'mittwald',
]);

export const INFRASTRUCTURE_PROVIDERS = Object.freeze(
  INFRASTRUCTURE_PROVIDER_KEYS.map((key) => Object.freeze({
    key,
    labelKey: `infrastructure.providers.${key}.label`,
    summaryKey: `infrastructure.providers.${key}.summary`,
    markdownKey: `infrastructure.providers.${key}.markdown`,
  })),
);

export const DEFAULT_INFRASTRUCTURE_PROVIDER = 'codesphere';

export function translateInfrastructureProviders(translate) {
  const t = typeof translate === 'function' ? translate : (key) => key;
  return INFRASTRUCTURE_PROVIDERS.map((provider) => Object.freeze({
    key: provider.key,
    label: t(provider.labelKey),
    summary: t(provider.summaryKey),
    markdown: t(provider.markdownKey),
  }));
}
