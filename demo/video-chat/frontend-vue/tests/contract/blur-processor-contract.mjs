import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

function fail(message) {
  throw new Error(`[blur-processor-contract] FAIL: ${message}`);
}

function functionBody(source, name) {
  const marker = `function ${name}`;
  const start = source.indexOf(marker);
  if (start === -1) {
    const tsMarker = ` ${name.replace(/\([^)]*\).*$/, '').trim()}`;
    const altStart = source.indexOf(tsMarker);
    if (altStart !== -1) {
      const parenOpen = source.indexOf('(', altStart + tsMarker.length);
      if (parenOpen !== -1) {
        let bodyStart = source.indexOf('{', parenOpen);
        if (bodyStart === -1) return null;
        let depth = 0;
        for (let i = bodyStart; i < source.length; i += 1) {
          if (source[i] === '{') depth += 1;
          if (source[i] === '}') {
            depth -= 1;
            if (depth === 0) return source.slice(bodyStart + 1, i);
          }
        }
      }
    }
    return null;
  }

  const parenOpen = source.indexOf('(', start);
  let bodyStart;
  if (parenOpen !== -1) {
    let depth = 0;
    let pi = parenOpen;
    while (pi < source.length) {
      if (source[pi] === '(') depth += 1;
      if (source[pi] === ')') { depth -= 1; if (depth === 0) break; }
      pi += 1;
    }
    bodyStart = source.indexOf('{', pi + 1);
  } else {
    bodyStart = source.indexOf('{', start);
  }
  if (bodyStart === -1) return null;

  let depth = 0;
  for (let i = bodyStart; i < source.length; i += 1) {
    if (source[i] === '{') depth += 1;
    if (source[i] === '}') {
      depth -= 1;
      if (depth === 0) {
        return source.slice(bodyStart + 1, i);
      }
    }
  }
  return null;
}

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);

const contractPath = path.resolve(__dirname, '../../src/lib/wavelet/blur-processor.ts');
const source = fs.readFileSync(contractPath, 'utf8');

const pipelinePath = path.resolve(__dirname, '../../src/lib/wavelet/processor-pipeline.ts');
const pipelineSource = fs.readFileSync(pipelinePath, 'utf8');

try {
  assert.ok(
    /class BackgroundBlurProcessor/.test(source),
    'blur-processor.ts must export class BackgroundBlurProcessor'
  );

  assert.ok(
    /blurMode:\s*('fast'|'quality')/.test(source),
    'BlurProcessor must support blurMode: fast | quality'
  );

  assert.ok(
    /frameInterval/.test(source),
    'BackgroundBlurProcessor must implement frameInterval (frame skipping for performance)'
  );

  assert.ok(
    /frameInterval\s*=\s*this\.options\.blurRadius\s*>=\s*7/.test(source),
    'BackgroundBlurProcessor must increase frame interval when blur radius >= 7'
  );

  assert.ok(
    /frameSkip/.test(source),
    'BackgroundBlurProcessor must track frameSkip for adaptive frame skipping'
  );

  assert.ok(
    /this\.frameSkip\s*<\s*this\.frameInterval/.test(source),
    'BackgroundBlurProcessor.process must skip processing when frameSkip < frameInterval'
  );

  assert.ok(
    /ctx\.filter\s*=\s*`blur/.test(source),
    'BackgroundBlurProcessor must set ctx.filter for fast blur mode'
  );

  assert.ok(
    /this\.lastBlurFrame/.test(source),
    'BackgroundBlurProcessor.process must cache last frame for reuse'
  );

  assert.ok(
    /BackgroundBlurProcessor/.test(pipelineSource),
    'processor-pipeline must instantiate BackgroundBlurProcessor'
  );

  assert.ok(
    /blurProcessor\.dispose\(\)/.test(pipelineSource),
    'processor-pipeline.stop must dispose blurProcessor'
  );

  assert.ok(
    /this\.config\.blurMode/.test(pipelineSource),
    'processor-pipeline config must include blurMode option'
  );

  assert.ok(
    /setBlurMode/.test(source),
    'BackgroundBlurProcessor must expose setBlurMode method'
  );

  assert.ok(
    /getStats/.test(source),
    'BackgroundBlurProcessor must expose getStats method'
  );

  const getStatsBody = source.slice(source.indexOf('getStats(): BlurStats {'));
  assert.ok(getStatsBody.length > 0, 'getStats must have a body');
  assert.ok(
    /BlurStats/.test(getStatsBody),
    'getStats must return BlurStats'
  );
  assert.ok(
    /mode.*('fast'|'quality')/.test(getStatsBody) || /mode:/.test(getStatsBody),
    'BlurStats must include mode field'
  );
  assert.ok(
    /fps/.test(getStatsBody),
    'BlurStats must include fps'
  );

  const setBlurRadiusBody = functionBody(source, 'setBlurRadius');
  if (setBlurRadiusBody) {
    assert.ok(
      /this\.qualityCompositor\.setBlurRadius/.test(setBlurRadiusBody),
      'setBlurRadius must propagate to qualityCompositor'
    );
  }

  process.stdout.write('[blur-processor-contract] PASS\n');
} catch (error) {
  if (error instanceof Error) {
    fail(error.message);
  }
  fail('unknown failure');
}