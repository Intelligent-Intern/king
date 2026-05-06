<template>
  <AdminPageFrame class="infrastructure-view" :title="t('infrastructure.title')">
    <section class="infrastructure-shell">
      <aside class="section infrastructure-provider-nav" :aria-label="t('infrastructure.provider_tabs')">
        <button
          v-for="provider in providers"
          :key="provider.key"
          class="infrastructure-provider-tab"
          :class="{ active: provider.key === activeProvider.key }"
          type="button"
          role="tab"
          :aria-selected="provider.key === activeProvider.key"
          @click="selectProvider(provider.key)"
          @keydown.left.prevent="focusAdjacentProvider(-1)"
          @keydown.right.prevent="focusAdjacentProvider(1)"
        >
          <span>{{ provider.label }}</span>
          <small>{{ provider.summary }}</small>
        </button>
      </aside>

      <article class="section infrastructure-document">
        <header class="infrastructure-document-header">
          <div>
            <h2>{{ activeProvider.label }}</h2>
            <p>{{ activeProvider.summary }}</p>
          </div>
        </header>

        <div
          class="infrastructure-markdown"
          v-html="renderedMarkdown"
        />
      </article>
    </section>
  </AdminPageFrame>
</template>

<script setup>
import MarkdownIt from 'markdown-it';
import { computed, ref } from 'vue';
import AdminPageFrame from '../../../components/admin/AdminPageFrame.vue';
import { t } from '../../localization/i18nRuntime.js';
import {
  DEFAULT_INFRASTRUCTURE_PROVIDER,
  INFRASTRUCTURE_PROVIDERS,
} from '../infrastructureDocs.js';

const providers = INFRASTRUCTURE_PROVIDERS;
const activeProviderKey = ref(DEFAULT_INFRASTRUCTURE_PROVIDER);

const activeProvider = computed(() => (
  providers.find((provider) => provider.key === activeProviderKey.value) || providers[0]
));

const markdown = createMarkdownRenderer();
const renderedMarkdown = computed(() => markdown.render(activeProvider.value.markdown));

function selectProvider(key) {
  activeProviderKey.value = key;
}

function focusAdjacentProvider(direction) {
  const currentIndex = providers.findIndex((provider) => provider.key === activeProviderKey.value);
  const nextIndex = (currentIndex + direction + providers.length) % providers.length;
  activeProviderKey.value = providers[nextIndex].key;
}

function createMarkdownRenderer() {
  const renderer = new MarkdownIt({
    html: false,
    linkify: true,
    typographer: true,
  });

  const defaultFence = renderer.renderer.rules.fence || renderer.renderer.renderToken.bind(renderer.renderer);
  renderer.renderer.rules.fence = (tokens, idx, options, env, self) => {
    const token = tokens[idx];
    const language = String(token.info || '').trim().split(/\s+/)[0];
    if (language === 'mermaid') {
      return renderMermaidDiagram(token.content);
    }
    return defaultFence(tokens, idx, options, env, self);
  };

  const defaultHeadingOpen = renderer.renderer.rules.heading_open || renderer.renderer.renderToken.bind(renderer.renderer);
  renderer.renderer.rules.heading_open = (tokens, idx, options, env, self) => {
    const nextToken = tokens[idx + 1];
    if (nextToken?.type === 'inline') {
      tokens[idx].attrSet('id', slugify(nextToken.content));
    }
    return defaultHeadingOpen(tokens, idx, options, env, self);
  };

  const defaultLinkOpen = renderer.renderer.rules.link_open || renderer.renderer.renderToken.bind(renderer.renderer);
  renderer.renderer.rules.link_open = (tokens, idx, options, env, self) => {
    const href = tokens[idx].attrGet('href') || '';
    if (/^https?:\/\//i.test(href)) {
      tokens[idx].attrSet('target', '_blank');
      tokens[idx].attrSet('rel', 'noopener noreferrer');
    }
    return defaultLinkOpen(tokens, idx, options, env, self);
  };

  return renderer;
}

function slugify(value) {
  return String(value || '')
    .toLowerCase()
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '');
}

function escapeHtml(value) {
  return String(value || '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');
}

function renderMermaidDiagram(source) {
  const lines = String(source || '')
    .split('\n')
    .map((line) => line.trim())
    .filter(Boolean);
  const kind = lines[0] || 'flowchart';
  let body = '';

  if (kind.startsWith('sequenceDiagram')) {
    body = renderSequenceDiagram(lines.slice(1));
  } else if (kind.startsWith('stateDiagram')) {
    body = renderStateDiagram(lines.slice(1));
  } else {
    body = renderFlowDiagram(lines.slice(1));
  }

  return [
    '<figure class="mermaid-diagram" data-diagram-kind="',
    escapeHtml(kind),
    '">',
    body,
    '<details><summary>',
    escapeHtml(t('infrastructure.mermaid_source')),
    '</summary><pre><code>',
    escapeHtml(source),
    '</code></pre></details>',
    '</figure>',
  ].join('');
}

function renderFlowDiagram(lines) {
  const nodes = new Map();
  const edges = [];

  for (const line of lines) {
    const match = line.match(/^(.+?)\s*-->\s*(.+)$/);
    if (!match) continue;
    const from = parseMermaidNode(match[1]);
    const to = parseMermaidNode(match[2]);
    rememberNode(nodes, from);
    rememberNode(nodes, to);
    edges.push([from.id, to.id]);
  }

  return [
    '<div class="mermaid-flow">',
    [...nodes.entries()].map(([id, label], index) => [
      '<div class="mermaid-node" data-node-id="',
      escapeHtml(id),
      '">',
      '<span>',
      escapeHtml(label),
      '</span>',
      index < nodes.size - 1 ? '<i aria-hidden="true">-></i>' : '',
      '</div>',
    ].join('')).join(''),
    '</div>',
    renderEdgeList(edges, nodes),
  ].join('');
}

function renderSequenceDiagram(lines) {
  const participants = new Map();
  const messages = [];

  for (const line of lines) {
    const participant = line.match(/^participant\s+([A-Za-z0-9_]+)\s+as\s+(.+)$/);
    if (participant) {
      participants.set(participant[1], participant[2]);
      continue;
    }
    const message = line.match(/^([A-Za-z0-9_]+)\s*-+>>?\s*([A-Za-z0-9_]+)\s*:\s*(.+)$/);
    if (message) {
      messages.push({ from: message[1], to: message[2], label: message[3] });
      if (!participants.has(message[1])) participants.set(message[1], message[1]);
      if (!participants.has(message[2])) participants.set(message[2], message[2]);
    }
  }

  return [
    '<div class="mermaid-sequence-participants">',
    [...participants.values()].map((label) => `<span>${escapeHtml(label)}</span>`).join(''),
    '</div>',
    '<ol class="mermaid-steps">',
    messages.map((message) => [
      '<li><strong>',
      escapeHtml(participants.get(message.from) || message.from),
      '</strong><i aria-hidden="true">-></i><strong>',
      escapeHtml(participants.get(message.to) || message.to),
      '</strong><span>',
      escapeHtml(message.label),
      '</span></li>',
    ].join('')).join(''),
    '</ol>',
  ].join('');
}

function renderStateDiagram(lines) {
  const states = new Map();
  const transitions = [];

  for (const line of lines) {
    const transition = line.match(/^(.+?)\s*-->\s*(.+?)(?::\s*(.+))?$/);
    if (!transition) continue;
    const from = parseMermaidNode(transition[1]);
    const to = parseMermaidNode(transition[2]);
    rememberNode(states, from);
    rememberNode(states, to);
    transitions.push({ from: from.id, to: to.id, label: transition[3] || '' });
  }

  return [
    '<ol class="mermaid-steps">',
    transitions.map((transition) => [
      '<li><strong>',
      escapeHtml(states.get(transition.from) || transition.from),
      '</strong><i aria-hidden="true">-></i><strong>',
      escapeHtml(states.get(transition.to) || transition.to),
      '</strong>',
      transition.label ? `<span>${escapeHtml(transition.label)}</span>` : '',
      '</li>',
    ].join('')).join(''),
    '</ol>',
  ].join('');
}

function renderEdgeList(edges, nodes) {
  if (!edges.length) return '';
  return [
    '<ol class="mermaid-edge-list">',
    edges.map(([from, to]) => [
      '<li>',
      escapeHtml(nodes.get(from) || from),
      '<i aria-hidden="true">-></i>',
      escapeHtml(nodes.get(to) || to),
      '</li>',
    ].join('')).join(''),
    '</ol>',
  ].join('');
}

function parseMermaidNode(rawValue) {
  const value = String(rawValue || '').trim();
  const id = (value.match(/^([A-Za-z0-9_*]+)/)?.[1] || value).replace(/^\[\*\]$/, 'Start');
  const labelMatch = value.match(/\[\((.*?)\)\]|\[\s*(.*?)\s*\]|\(\((.*?)\)\)|\((.*?)\)/);
  const label = labelMatch?.slice(1).find((entry) => entry !== undefined && entry !== '') || id;
  return { id, label };
}

function rememberNode(nodes, node) {
  if (!nodes.has(node.id) || node.label !== node.id) {
    nodes.set(node.id, node.label);
  }
}
</script>

<style scoped>
.infrastructure-view {
  min-height: 0;
}

.infrastructure-shell {
  min-height: 0;
  flex: 1 1 auto;
  display: grid;
  grid-template-columns: minmax(220px, 300px) minmax(0, 1fr);
  gap: 20px;
  padding: 0 20px 20px;
  overflow: hidden;
}

.infrastructure-provider-nav {
  min-height: 0;
  display: grid;
  align-content: start;
  gap: 20px;
  overflow: auto;
}

.infrastructure-provider-tab {
  min-width: 0;
  border: 1px solid var(--border-subtle);
  background: var(--color-surface-navy);
  color: var(--color-text-primary);
  display: grid;
  gap: 8px;
  padding: 14px;
  text-align: start;
  cursor: pointer;
}

.infrastructure-provider-tab:hover,
.infrastructure-provider-tab.active {
  border-color: var(--color-cyan-primary);
  background: var(--color-border);
}

.infrastructure-provider-tab span {
  color: var(--color-heading);
  font-size: 14px;
  font-weight: 800;
}

.infrastructure-provider-tab small {
  color: var(--text-muted);
  font-size: 12px;
  line-height: 1.45;
}

.infrastructure-document {
  min-width: 0;
  min-height: 0;
  display: grid;
  grid-template-rows: auto minmax(0, 1fr);
  gap: 20px;
  overflow: hidden;
}

.infrastructure-document-header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 20px;
}

.infrastructure-document-header h2 {
  margin: 0;
  color: var(--color-heading);
  font-size: 14px;
}

.infrastructure-document-header p {
  max-width: 780px;
  margin: 6px 0 0;
  color: var(--text-muted);
  font-size: 12px;
  line-height: 1.5;
}

.infrastructure-markdown {
  min-height: 0;
  overflow: auto;
  color: var(--color-text-primary);
  font-size: 13px;
  line-height: 1.55;
  padding-right: 6px;
}

.infrastructure-markdown :deep(h1),
.infrastructure-markdown :deep(h2),
.infrastructure-markdown :deep(h3) {
  color: var(--color-heading);
  line-height: 1.25;
}

.infrastructure-markdown :deep(h1) {
  margin: 0 0 12px;
  font-size: 18px;
}

.infrastructure-markdown :deep(h2) {
  margin: 28px 0 10px;
  font-size: 14px;
}

.infrastructure-markdown :deep(h3) {
  margin: 22px 0 8px;
  font-size: 13px;
}

.infrastructure-markdown :deep(p),
.infrastructure-markdown :deep(ul),
.infrastructure-markdown :deep(ol),
.infrastructure-markdown :deep(table),
.infrastructure-markdown :deep(pre),
.infrastructure-markdown :deep(blockquote),
.infrastructure-markdown :deep(.mermaid-diagram) {
  margin: 0 0 16px;
}

.infrastructure-markdown :deep(ul),
.infrastructure-markdown :deep(ol) {
  padding-left: 22px;
}

.infrastructure-markdown :deep(li + li) {
  margin-top: 6px;
}

.infrastructure-markdown :deep(a) {
  color: var(--color-text-link);
  text-decoration: none;
}

.infrastructure-markdown :deep(a:hover) {
  color: var(--color-text-link-hover);
  text-decoration: underline;
}

.infrastructure-markdown :deep(blockquote) {
  border-left: 3px solid var(--color-cyan-primary);
  background: var(--color-surface-navy);
  color: var(--color-text-primary);
  padding: 12px 14px;
}

.infrastructure-markdown :deep(table) {
  width: 100%;
  border-collapse: collapse;
  background: var(--color-surface-navy);
}

.infrastructure-markdown :deep(th),
.infrastructure-markdown :deep(td) {
  border: 1px solid var(--border-subtle);
  padding: 10px;
  vertical-align: top;
}

.infrastructure-markdown :deep(th) {
  color: var(--color-heading);
  font-size: 12px;
  text-align: start;
}

.infrastructure-markdown :deep(pre),
.infrastructure-markdown :deep(.mermaid-diagram) {
  border: 1px solid var(--border-subtle);
  background: var(--color-surface-navy);
  overflow: auto;
  padding: 14px;
}

.infrastructure-markdown :deep(code) {
  color: var(--color-heading);
  font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
  font-size: 12px;
}

.infrastructure-markdown :deep(.mermaid-flow),
.infrastructure-markdown :deep(.mermaid-sequence-participants),
.infrastructure-markdown :deep(.mermaid-steps li),
.infrastructure-markdown :deep(.mermaid-edge-list li) {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 10px;
}

.infrastructure-markdown :deep(.mermaid-flow) {
  gap: 12px;
  margin-bottom: 12px;
}

.infrastructure-markdown :deep(.mermaid-node span),
.infrastructure-markdown :deep(.mermaid-sequence-participants span),
.infrastructure-markdown :deep(.mermaid-steps strong) {
  border: 1px solid var(--color-cyan-primary);
  background: var(--color-border);
  color: var(--color-heading);
  padding: 8px 10px;
}

.infrastructure-markdown :deep(.mermaid-node i),
.infrastructure-markdown :deep(.mermaid-steps i),
.infrastructure-markdown :deep(.mermaid-edge-list i) {
  color: var(--color-cyan-primary);
  font-style: normal;
  font-weight: 800;
}

.infrastructure-markdown :deep(.mermaid-steps),
.infrastructure-markdown :deep(.mermaid-edge-list) {
  display: grid;
  gap: 8px;
  margin: 10px 0 0;
  padding-left: 20px;
}

.infrastructure-markdown :deep(.mermaid-steps span) {
  color: var(--text-muted);
}

.infrastructure-markdown :deep(.mermaid-diagram details) {
  margin-top: 12px;
  color: var(--text-muted);
}

.infrastructure-markdown :deep(.mermaid-diagram summary) {
  cursor: pointer;
  font-size: 12px;
  font-weight: 700;
}

.infrastructure-markdown :deep(.mermaid-diagram details pre) {
  margin: 10px 0 0;
  background: var(--color-primary-navy);
}

@media (max-width: 900px) {
  .infrastructure-shell {
    grid-template-columns: 1fr;
    overflow: auto;
  }

  .infrastructure-provider-nav,
  .infrastructure-document,
  .infrastructure-markdown {
    overflow: visible;
  }
}

@media (max-width: 600px) {
  .infrastructure-shell {
    padding: 0 10px 10px;
  }
}
</style>
