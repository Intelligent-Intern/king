import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

function fail(message) {
  throw new Error(`[wavelet-pipeline-contract] FAIL: ${message}`);
}

function methodBody(source, name) {
  const re = new RegExp(`(?:async\\s+)?${name}\\s*\\([^)]*\\)[^{]*\\{`);
  const m = source.match(re);
  if (!m) return null;
  const start = m.index + m[0].length;
  let depth = 1;
  for (let i = start; i < source.length; i++) {
    if (source[i] === '{') depth++;
    else if (source[i] === '}') { depth--; if (depth === 0) return source.slice(start, i); }
  }
  return null;
}

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);

const pipelinePath = path.resolve(__dirname, '../../src/lib/wavelet/processor-pipeline.ts');
const source = fs.readFileSync(pipelinePath, 'utf8');

try {
  assert.ok(
    /class WaveletVideoProcessor/.test(source),
    'pipeline must define WaveletVideoProcessor class'
  );

  assert.ok(
    /BackgroundBlurProcessor/.test(source),
    'WaveletVideoProcessor must use BackgroundBlurProcessor'
  );

  assert.ok(
    /blurProcessor/.test(source),
    'WaveletVideoProcessor must have blurProcessor field'
  );

  const configBody = source.match(/constructor\s*\([^)]*\)\s*\{[\s\S]{1,2000}/);
  assert.ok(configBody, 'WaveletVideoProcessor must have constructor');
  assert.ok(
    /blurMode/.test(configBody[0]),
    'WaveletProcessorConfig must include blurMode'
  );

  const processStreamBody = methodBody(source, 'processStream');
  assert.ok(processStreamBody, 'WaveletVideoProcessor must have processStream method');
  assert.ok(
    /new BackgroundBlurProcessor\(\)/.test(processStreamBody),
    'processStream must instantiate BackgroundBlurProcessor'
  );
  assert.ok(
    /blurProcessor\.init/.test(processStreamBody),
    'processStream must call blurProcessor.init'
  );
  assert.ok(
    /blurMode/.test(processStreamBody),
    'processStream must pass blurMode to blurProcessor.init'
  );

  const stopBody = methodBody(source, 'stop');
  assert.ok(stopBody, 'WaveletVideoProcessor must have stop method');
  assert.ok(
    /blurProcessor\.dispose\(\)/.test(stopBody),
    'stop must dispose blurProcessor'
  );

  const setBlurRadiusBody = methodBody(source, 'setBlurRadius');
  assert.ok(setBlurRadiusBody, 'WaveletVideoProcessor must have setBlurRadius method');
  assert.ok(
    /blurProcessor\.setBlurRadius/.test(setBlurRadiusBody),
    'setBlurRadius must call blurProcessor.setBlurRadius'
  );

  const setBlurModeBody = methodBody(source, 'setBlurMode');
  assert.ok(setBlurModeBody, 'WaveletVideoProcessor must have setBlurMode method');
  assert.ok(
    /blurProcessor\.setBlurMode/.test(setBlurModeBody),
    'setBlurMode must call blurProcessor.setBlurMode'
  );

  const getBlurStatsBody = methodBody(source, 'getBlurStats');
  assert.ok(getBlurStatsBody, 'WaveletVideoProcessor must have getBlurStats method');
  assert.ok(
    /blurProcessor\?\.getStats\(\)/.test(getBlurStatsBody),
    'getBlurStats must call blurProcessor.getStats()'
  );

  const blurStatsType = source.match(/interface\s+BlurStats[\s\S]{1,300}?\}/);
  assert.ok(blurStatsType, 'pipeline must define BlurStats interface');
  assert.ok(/fps/.test(blurStatsType[0]), 'BlurStats must include fps');
  assert.ok(/avgBlurMs/.test(blurStatsType[0]), 'BlurStats must include avgBlurMs');
  assert.ok(/mode.*'fast'.*'quality'/.test(blurStatsType[0]), 'BlurStats must include mode: fast | quality');

  assert.ok(
    source.indexOf('PreEncodeBlurCompositor') === -1,
    'processor-pipeline must NOT use PreEncodeBlurCompositor (use BackgroundBlurProcessor)'
  );

  process.stdout.write('[wavelet-pipeline-contract] PASS\n');
} catch (error) {
  if (error instanceof Error) {
    fail(error.message);
  }
  fail('unknown failure');
}