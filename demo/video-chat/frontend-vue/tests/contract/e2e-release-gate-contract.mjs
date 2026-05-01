import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const frontendRoot = path.resolve(__dirname, '../..');
const videoChatRoot = path.resolve(frontendRoot, '..');

function fail(message) {
  throw new Error(`[e2e-release-gate-contract] FAIL: ${message}`);
}

function readJson(filePath) {
  return JSON.parse(fs.readFileSync(filePath, 'utf8'));
}

function readText(filePath) {
  return fs.readFileSync(filePath, 'utf8');
}

function stripFrontendPrefix(filePath) {
  const normalized = String(filePath || '').replaceAll('\\', '/');
  return normalized.startsWith('frontend-vue/') ? normalized.slice('frontend-vue/'.length) : normalized;
}

function frontendPath(filePath) {
  return path.resolve(frontendRoot, stripFrontendPrefix(filePath));
}

function scriptIncludesPath(script, filePath) {
  const normalizedScript = String(script || '').replaceAll('\\', '/');
  return normalizedScript.includes(stripFrontendPrefix(filePath));
}

function assertExistingFrontendPath(filePath, label) {
  const absolutePath = frontendPath(filePath);
  assert.ok(fs.existsSync(absolutePath), `${label} path must exist: ${filePath}`);
  return absolutePath;
}

function assertScriptHasPath(script, scriptName, filePath) {
  assert.ok(
    scriptIncludesPath(script, filePath),
    `${scriptName} must execute ${stripFrontendPrefix(filePath)}`,
  );
}

try {
  const packageJsonPath = path.join(frontendRoot, 'package.json');
  const matrixPath = path.join(videoChatRoot, 'contracts/v1/ui-parity-acceptance.matrix.json');
  const packageJson = readJson(packageJsonPath);
  const matrix = readJson(matrixPath);
  const scripts = packageJson.scripts || {};
  const gate = matrix.release_gate || {};
  const uiParityCommand = matrix.commands?.['frontend:e2e:ui-parity'] || {};

  assert.equal(gate.script, 'test:e2e:release-gate', 'matrix must declare the executable release gate script');
  assert.ok(
    String(scripts['test:e2e:release-gate'] || '').includes('tests/contract/e2e-release-gate-contract.mjs'),
    'package.json must expose test:e2e:release-gate',
  );
  assert.ok(
    String(scripts['test:e2e:ui-parity'] || '').startsWith('playwright test '),
    'package.json must expose a Playwright-backed test:e2e:ui-parity script',
  );
  assert.equal(
    uiParityCommand.script,
    'test:e2e:ui-parity',
    'matrix frontend:e2e:ui-parity command must bind to the package script',
  );

  const requiredSpecs = Array.isArray(gate.required_ui_parity_specs) ? gate.required_ui_parity_specs : [];
  assert.ok(requiredSpecs.length >= 1, 'release gate must list required UI parity specs');
  const matrixUiParityPaths = new Set((Array.isArray(uiParityCommand.paths) ? uiParityCommand.paths : []).map(String));

  for (const specPath of requiredSpecs) {
    assertExistingFrontendPath(specPath, 'required UI parity spec');
    assert.ok(matrixUiParityPaths.has(specPath), `matrix frontend:e2e:ui-parity paths must include ${specPath}`);
    assertScriptHasPath(scripts['test:e2e:ui-parity'], 'test:e2e:ui-parity', specPath);
  }

  const requiredJourneys = Array.isArray(gate.required_core_video_journeys) ? gate.required_core_video_journeys : [];
  assert.ok(requiredJourneys.length >= 4, 'release gate must pin the four core video acceptance journeys');

  for (const journey of requiredJourneys) {
    const id = String(journey?.id || '').trim();
    const specPath = String(journey?.path || '').trim();
    const testTitle = String(journey?.test_title || '').trim();
    assert.notEqual(id, '', 'core video journey must have an id');
    assert.notEqual(specPath, '', `${id} must have a path`);
    assert.notEqual(testTitle, '', `${id} must have a test_title`);
    const absoluteSpecPath = assertExistingFrontendPath(specPath, `${id} spec`);
    assert.ok(matrixUiParityPaths.has(specPath), `${id} spec must be part of the UI parity matrix command`);
    assertScriptHasPath(scripts['test:e2e:ui-parity'], 'test:e2e:ui-parity', specPath);
    const source = readText(absoluteSpecPath);
    assert.ok(
      source.includes(`test('${testTitle}'`) || source.includes(`test("${testTitle}"`),
      `${id} must be backed by an executable Playwright test titled "${testTitle}"`,
    );
  }

  if (process.env.VIDEOCHAT_UI_PARITY_REQUIRE_COVERED === '1') {
    const releaseBlockingGaps = (Array.isArray(matrix.slices) ? matrix.slices : [])
      .filter((slice) => String(slice?.status || '').trim() !== 'covered' && slice?.release_blocking === true)
      .map((slice) => String(slice?.id || 'unknown'));
    assert.deepEqual(
      releaseBlockingGaps,
      [],
      `strict release validation blocks release while matrix gaps remain: ${releaseBlockingGaps.join(', ')}`,
    );
  }

  process.stdout.write('[e2e-release-gate-contract] PASS\n');
} catch (error) {
  if (error instanceof Error) {
    fail(error.message);
  }
  fail('unknown failure');
}
