import assert from 'node:assert/strict';
import { readdir, readFile } from 'node:fs/promises';
import path from 'node:path';
import { ENGLISH_MESSAGES } from '../../src/modules/localization/englishMessages.js';

const root = path.resolve(new URL('../..', import.meta.url).pathname);
const sourceRoot = path.join(root, 'src');
const excludedRoots = new Set([
  'src/domain/calls',
  'src/domain/realtime',
  'src/lib/sfu',
  'src/lib/wasm',
  'src/lib/wavelet',
]);

function normalizeRelative(filePath) {
  return path.relative(root, filePath).split(path.sep).join('/');
}

function isExcluded(filePath) {
  const relativePath = normalizeRelative(filePath);
  return [...excludedRoots].some((excludedRoot) => (
    relativePath === excludedRoot || relativePath.startsWith(`${excludedRoot}/`)
  ));
}

async function collectSourceFiles(directory) {
  const entries = await readdir(directory, { withFileTypes: true });
  const files = [];
  for (const entry of entries) {
    const filePath = path.join(directory, entry.name);
    if (isExcluded(filePath)) continue;
    if (entry.isDirectory()) {
      files.push(...await collectSourceFiles(filePath));
      continue;
    }
    if (entry.isFile() && /\.(vue|js)$/.test(entry.name)) {
      files.push(filePath);
    }
  }
  return files.sort();
}

const sourceFiles = await collectSourceFiles(sourceRoot);

const usedKeys = new Set();
for (const sourceFile of sourceFiles) {
  const source = await readFile(sourceFile, 'utf8');
  for (const match of source.matchAll(/\bt\(\s*['"]([a-z0-9_.-]+)['"]/g)) {
    usedKeys.add(match[1]);
  }
  for (const match of source.matchAll(/\b(?:label_key|pageTitle_key|entitySingular_key|entityPlural_key|labelKey|titleKey|statusKey):\s*['"]([a-z0-9_.-]+)['"]/g)) {
    usedKeys.add(match[1]);
  }
}

assert.ok(usedKeys.size > 0, 'translation key usage scan should find keys');
for (const key of [...usedKeys].sort()) {
  assert.ok(Object.prototype.hasOwnProperty.call(ENGLISH_MESSAGES, key), `missing English fallback for ${key}`);
}

console.log('[frontend-translation-key-coverage-contract] PASS');
