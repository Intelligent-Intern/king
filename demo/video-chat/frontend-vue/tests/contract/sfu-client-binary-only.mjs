import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const sfuPath = path.resolve(__dirname, '../../src/lib/sfu/sfuClient.ts');
const source = fs.readFileSync(sfuPath, 'utf8');

// Ensure the SFU client does not contain any JSON fallback for IIBIN frames
assert.ok(!/JSON/.test(source), 'SFUClient should not contain any JSON usage for binary frames');

// Verify that handleBinaryFrame validates magic number and reads fields
assert.ok(/view\.getUint32\(0, false\)/.test(source), 'handleBinaryFrame must validate magic number');
assert.ok(/view\.getUint8\(4\)/.test(source), 'handleBinaryFrame must read version byte');
assert.ok(/view\.getUint8\(5\)/.test(source), 'handleBinaryFrame must read frame type');
assert.ok(/view\.getUint8\(6\)/.test(source), 'handleBinaryFrame must read quality');
assert.ok(/view\.getUint8\(7\)/.test(source), 'handleBinaryFrame must read DWT levels');

process.stdout.write('[sfu-client-binary-only] PASS\n');
