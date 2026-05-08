import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const frontendRoot = path.resolve(__dirname, '../..');

function readUtf8(relativePath) {
  return fs.readFileSync(path.join(frontendRoot, relativePath), 'utf8');
}

try {
  const html = readUtf8('tests/standalone/king-background-segmentation-harness.html');
  const harness = readUtf8('tests/standalone/king-background-segmentation-harness.ts');
  const backend = readUtf8('src/domain/realtime/background/backendSinetWasm.ts');
  const postprocess = readUtf8('src/domain/realtime/background/maskPostprocess.ts');
  const productionCallPaths = [
    readUtf8('src/domain/realtime/local/mediaOrchestration.ts'),
    readUtf8('src/domain/calls/access/joinPreview.ts'),
    readUtf8('src/domain/calls/dashboard/enterCall.ts'),
    readUtf8('src/domain/calls/admin/enterCall.ts'),
  ];

  assert.ok(html.includes('<option value="sinet">SINet fast</option>'), 'standalone default model must expose SINet fast first');
  assert.ok(html.includes('<option value="wasm">WASM</option>'), 'standalone default device must be WASM first');
  assert.ok(html.includes('<option value="float">SINet float</option>'), 'standalone default dtype must be SINet float');
  assert.ok(html.includes('id="alphaGammaInput" type="number" min="0.4" max="2.5" step="0.05" value="0.8"'), 'standalone default gamma must be 0.8');
  assert.ok(html.includes('id="maskContrastInput" type="number" min="0.25" max="4" step="0.05" value="0.75"'), 'standalone default contrast must be 0.75');
  assert.ok(html.includes('id="averageRadiusInput" type="number" min="0" max="12" step="1" value="6"'), 'standalone default Gaussian radius must be 6');
  assert.ok(html.includes('id="temporalRiseInput" type="number" min="0" max="1" step="0.05" value="0.7"'), 'standalone default temporal rise must be 0.7');
  assert.ok(html.includes('id="temporalFallInput" type="number" min="0" max="1" step="0.05" value="0.6"'), 'standalone default temporal fall must be 0.6');

  assert.ok(harness.includes('Number(alphaGammaInput.value || 0.8)'), 'harness fallback gamma must be 0.8');
  assert.ok(harness.includes('Number(maskContrastInput.value || 0.75)'), 'harness fallback contrast must be 0.75');
  assert.ok(harness.includes('Number(averageRadiusInput.value || 6)'), 'harness fallback Gaussian radius must be 6');
  assert.ok(harness.includes('Number(temporalRiseInput.value || 0.7)'), 'harness fallback temporal rise must be 0.7');
  assert.ok(harness.includes('Number(temporalFallInput.value || 0.6)'), 'harness fallback temporal fall must be 0.6');

  assert.ok(backend.includes("executionProviders: ['wasm']"), 'production SINet default execution provider must be WASM');
  assert.ok(backend.includes('Number(opts.alphaGamma ?? 0.8)'), 'production default gamma must be 0.8');
  assert.ok(backend.includes('Number(opts.maskContrast ?? 0.75)'), 'production default contrast must be 0.75');
  assert.ok(backend.includes('Number(opts.averageRadius ?? 6)'), 'production default Gaussian radius must be 6');
  assert.ok(backend.includes('Number(opts.temporalRise ?? 0.7)'), 'production default temporal rise must be 0.7');
  assert.ok(backend.includes('Number(opts.temporalFall ?? 0.6)'), 'production default temporal fall must be 0.6');

  assert.ok(postprocess.includes('Number(controls.gamma) || 0.8'), 'shared postprocess default gamma must be 0.8');
  assert.ok(postprocess.includes('Number(controls.contrast ?? 0.75)'), 'shared postprocess default contrast must be 0.75');
  assert.ok(postprocess.includes('Number(controls.averageRadius ?? 6)'), 'shared postprocess default Gaussian radius must be 6');
  assert.ok(postprocess.includes('controls.temporalRise ?? 0.7'), 'shared postprocess default temporal rise must be 0.7');
  assert.ok(postprocess.includes('controls.temporalFall ?? 0.6'), 'shared postprocess default temporal fall must be 0.6');

  for (const source of productionCallPaths) {
    assert.ok(source.includes('alphaGamma: 0.8,'), 'production call path must pass standalone gamma 0.8');
    assert.ok(source.includes('maskContrast: 0.75,'), 'production call path must pass standalone contrast 0.75');
    assert.ok(source.includes('averageRadius: 6,'), 'production call path must pass standalone Gaussian radius 6');
    assert.ok(source.includes('temporalRise: 0.7,'), 'production call path must pass standalone temporal rise 0.7');
    assert.ok(source.includes('temporalFall: 0.6,'), 'production call path must pass standalone temporal fall 0.6');
  }

  console.log('[background-sinet-defaults-contract] PASS');
} catch (error) {
  console.error(`[background-sinet-defaults-contract] FAIL: ${error.message}`);
  process.exit(1);
}
