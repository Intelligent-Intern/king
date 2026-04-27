import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
// Path to the C++ codec implementation used by the WASM wrapper
const cppPath = path.resolve(__dirname, '../../src/lib/wasm/cpp/codec.cpp');
const source = fs.readFileSync(cppPath, 'utf8');

// Ensure the C++ codec implements temporal residual (delta) handling
assert.ok(/Temporal residual on Y for delta frames/.test(source), 'C++ codec should contain temporal residual comment');
assert.ok(/Y_\[i\]\s*-\s*prevY_\[i\]/.test(source), 'C++ codec should compute delta Y values');

// Ensure the WASM wrapper does not contain any JSON usage for frames
const wrapperPath = path.resolve(__dirname, '../../src/lib/wasm/wasm-codec.ts');
const wrapperSource = fs.readFileSync(wrapperPath, 'utf8');
assert.ok(!/JSON/.test(wrapperSource), 'WASM codec wrapper should not contain JSON usage');

process.stdout.write('[wasm-codec-delta] PASS\n');
