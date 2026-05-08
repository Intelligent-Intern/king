import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import path from 'node:path';
import { workspaceModuleRegistry } from '../../src/modules/index.js';
import {
  INFRASTRUCTURE_PROVIDERS,
  translateInfrastructureProviders,
} from '../../src/modules/infrastructure/infrastructureDocs.js';
import { ENGLISH_MESSAGES } from '../../src/modules/localization/englishMessages.js';

const root = path.resolve(new URL('../..', import.meta.url).pathname);

async function source(relativePath) {
  return readFile(path.join(root, relativePath), 'utf8');
}

const descriptor = workspaceModuleRegistry.get('infrastructure');
assert.ok(descriptor, 'infrastructure module descriptor must be registered');
assert.deepEqual(descriptor.permissions, ['infrastructure.read'], 'infrastructure docs must expose a read permission');
assert.ok(
  descriptor.routes.some((route) => route.path === '/admin/infrastructure' && route.name === 'admin-infrastructure'),
  'infrastructure docs must expose an admin route',
);
assert.ok(
  descriptor.navigation.some((entry) => entry.to === '/admin/infrastructure' && entry.group === null),
  'infrastructure docs must be a first-level navigation item',
);

assert.deepEqual(
  INFRASTRUCTURE_PROVIDERS.map((provider) => provider.key),
  ['codesphere', 'hetzner', 'intares', 'mittwald'],
  'infrastructure docs must cover the four selected providers in tab order',
);

for (const provider of INFRASTRUCTURE_PROVIDERS) {
  for (const keyName of ['labelKey', 'summaryKey', 'markdownKey']) {
    assert.ok(
      Object.prototype.hasOwnProperty.call(ENGLISH_MESSAGES, provider[keyName]),
      `${provider.key} ${keyName} must have an English localization fallback`,
    );
  }
}

const translatedProviders = translateInfrastructureProviders((key) => ENGLISH_MESSAGES[key] || key);

for (const provider of translatedProviders) {
  assert.ok(provider.markdown.startsWith(`# ${provider.label}`), `${provider.key} markdown must start with its provider heading`);
  for (const section of [
    '## Table of Contents',
    '## Operating profile',
    '## Expansion options',
    '## King Extension Rollout',
    '## Monitoring with OpenTelemetry',
    '## Email and DNS',
    '## Sources',
  ]) {
    assert.ok(provider.markdown.includes(section), `${provider.key} markdown missing ${section}`);
  }
  assert.match(provider.markdown, /```mermaid[\s\S]*?```/, `${provider.key} docs must include a Mermaid diagram`);
  assert.match(provider.markdown, /\| .* \| .* \|/, `${provider.key} docs must include an options table`);
  assert.match(provider.markdown, /\[.+\]\(https?:\/\/.+\)/, `${provider.key} docs must cite public provider or platform sources`);
}

const codesphere = translatedProviders.find((provider) => provider.key === 'codesphere').markdown;
assert.match(codesphere, /Always On/, 'Codesphere docs must warn that call environments need Always On workspaces');
assert.match(codesphere, /Replicas/, 'Codesphere docs must cover replicas');
assert.match(codesphere, /Landscapes/, 'Codesphere docs must cover landscapes');
assert.match(codesphere, /CS_REPLICA/, 'Codesphere docs must cover replica-safe filesystem writes');

const hetzner = translatedProviders.find((provider) => provider.key === 'hetzner').markdown;
assert.match(hetzner, /Hetzner Cloud API/, 'Hetzner docs must cover Cloud API based orchestration');
assert.match(hetzner, /Load Balancer/, 'Hetzner docs must cover load balancers');
assert.match(hetzner, /HPA|KEDA|Cluster Autoscaler/, 'Hetzner docs must cover Kubernetes autoscaling options');

const intares = translatedProviders.find((provider) => provider.key === 'intares').markdown;
assert.match(intares, /self-service API autoscaling/i, 'Intares docs must explicitly state the current public autoscaling gap');
assert.match(intares, /Questions for Intares/, 'Intares docs must include provider due diligence questions');

const mittwald = translatedProviders.find((provider) => provider.key === 'mittwald').markdown;
assert.match(mittwald, /mStudio/, 'mittwald docs must cover mStudio');
assert.match(mittwald, /Terraform/, 'mittwald docs must cover Terraform rollout');
assert.match(mittwald, /mw container run/, 'mittwald docs must include CLI rollout guidance');

const viewSource = await source('src/modules/infrastructure/pages/InfrastructureView.vue');
assert.match(viewSource, /MarkdownIt/, 'infrastructure docs page must render Markdown');
assert.match(viewSource, /translateInfrastructureProviders/, 'infrastructure docs page must resolve provider copy through localization');
assert.match(viewSource, /renderMermaidDiagram/, 'infrastructure docs page must render Mermaid fences as diagrams');
assert.match(viewSource, /class="mermaid-diagram"/, 'infrastructure docs must render diagram markup without a heavyweight Mermaid vendor chunk');
assert.match(viewSource, /role="tab"/, 'provider selection must render as tabs');
assert.match(viewSource, /v-html="renderedMarkdown"/, 'rendered Markdown must be displayed in the page');

const messages = await source('src/modules/localization/englishMessages.js');
const infrastructureMessages = await source('src/modules/localization/infrastructureMessages.js');
assert.match(messages, /'navigation\.infrastructure': 'Infrastructure'/, 'infrastructure navigation key must be localized');
assert.match(infrastructureMessages, /'infrastructure\.mermaid_source': 'Mermaid source'/, 'infrastructure Mermaid source label must be localized');
assert.match(infrastructureMessages, /'infrastructure\.title': 'Infrastructure'/, 'infrastructure page title key must be localized');
assert.doesNotMatch(
  await source('src/modules/infrastructure/infrastructureDocs.js'),
  /Always On|Self-Service|Terraform|Monitoring with OpenTelemetry/,
  'infrastructure docs module must not hard-code visible document copy',
);

console.log('[infrastructure-docs-contract] PASS');
