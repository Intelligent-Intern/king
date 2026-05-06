import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

function fail(message) {
  throw new Error(`[visual-standards-contract] FAIL: ${message}`);
}

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const root = path.resolve(__dirname, '../..');
const srcRoot = path.join(root, 'src');
const colorTokenFile = path.join(srcRoot, 'styles/base.css');
const workspaceStageFile = path.join(srcRoot, 'domain/realtime/CallWorkspaceStage.css');
const rawColorPattern = /#[0-9a-fA-F]{3,8}\b|rgba?\([^)]*\)|hsla?\([^)]*\)/g;
const allowedRawHexColors = new Set([
  '#000010',
  '#00052d',
  '#1582bf',
  '#59c7f2',
  '#efefe7',
  '#ffffff',
  '#03275a',
  '#00652f',
  '#f47221',
  '#ef4423',
]);
const allowedColorTokens = new Set([
  '--color-primary-navy',
  '--color-surface-navy',
  '--color-cyan-primary',
  '--color-cyan-hover',
  '--color-heading',
  '--color-text-primary',
  '--color-text-link',
  '--color-text-link-hover',
  '--color-border',
  '--color-success',
  '--color-warning',
  '--color-error',
]);
const modalRootAllowList = new Set([
  'call-access-join-modal',
  'call-owner-edit-modal',
  'calls-modal',
  'chat-archive-modal',
  'marketplace-modal',
  'settings-modal',
  'users-modal',
]);

function listSourceFiles(dir) {
  const files = [];
  for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
    const fullPath = path.join(dir, entry.name);
    if (entry.isDirectory()) {
      files.push(...listSourceFiles(fullPath));
      continue;
    }
    if (/\.(css|vue)$/.test(entry.name)) {
      files.push(fullPath);
    }
  }
  return files;
}

function relative(filePath) {
  return path.relative(root, filePath).replaceAll(path.sep, '/');
}

function classTokens(source) {
  const tokens = new Set();
  const classAttributePattern = /class=(?:"([^"]+)"|'([^']+)')/g;
  const classSelectorPattern = /\.([A-Za-z][A-Za-z0-9_-]*modal[A-Za-z0-9_-]*)/g;

  for (const match of source.matchAll(classAttributePattern)) {
    const value = String(match[1] || match[2] || '');
    for (const token of value.split(/\s+/)) {
      const normalized = token.trim();
      if (normalized !== '') tokens.add(normalized);
    }
  }

  for (const match of source.matchAll(classSelectorPattern)) {
    tokens.add(match[1]);
  }

  return tokens;
}

try {
  const files = listSourceFiles(srcRoot);
  const rawColorViolations = [];
  const modalRootViolations = [];

  for (const file of files) {
    const source = fs.readFileSync(file, 'utf8');
    const matches = source.match(rawColorPattern) || [];
    for (const match of matches) {
      const normalized = match.toLowerCase();
      if (!normalized.startsWith('#') || !allowedRawHexColors.has(normalized)) {
        rawColorViolations.push(`${relative(file)} uses non-styleguide color ${match}`);
      }
    }

    const colorTokens = source.match(/--color-[A-Za-z0-9_-]+/g) || [];
    for (const token of colorTokens) {
      if (!allowedColorTokens.has(token)) {
        rawColorViolations.push(`${relative(file)} uses non-styleguide token ${token}`);
      }
    }

    for (const token of classTokens(source)) {
      if (!token.endsWith('-modal') && token !== 'modal') continue;
      if (!modalRootAllowList.has(token)) {
        modalRootViolations.push(`${relative(file)} uses modal root class ${token}`);
      }
    }
  }

  assert.equal(
    rawColorViolations.length,
    0,
    `visual surfaces must use design tokens instead of raw colors:\n${rawColorViolations.join('\n')}`,
  );
  assert.equal(
    modalRootViolations.length,
    0,
    `modal root variants must stay on the approved shell set:\n${modalRootViolations.join('\n')}`,
  );

  const tokenSource = fs.readFileSync(colorTokenFile, 'utf8');
  const colorDefinitions = [...tokenSource.matchAll(/(--color-[\w-]+):\s*(#[0-9a-f]{6});/gi)];
  assert.equal(colorDefinitions.length, 12, 'base CSS must define exactly 12 KingRT styleguide color slots');
  assert.doesNotMatch(tokenSource, /--color-rgba-|rgba?\(|hsla?\(/, 'base CSS must not define arbitrary rgba or hsl color tokens');
  const workspaceStageSource = fs.readFileSync(workspaceStageFile, 'utf8');
  assert.doesNotMatch(
    workspaceStageSource,
    /rgba?\(/,
    'workspace stage CSS must not introduce raw rgba values',
  );
  assert.doesNotMatch(
    workspaceStageSource,
    /--color-rgba-/,
    'workspace stage CSS must not consume rgba color tokens',
  );

  process.stdout.write('[visual-standards-contract] PASS\n');
} catch (error) {
  if (error instanceof Error) {
    fail(error.message);
  }
  fail('unknown failure');
}
