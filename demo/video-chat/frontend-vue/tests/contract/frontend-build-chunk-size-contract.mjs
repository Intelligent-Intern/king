import assert from 'node:assert/strict'
import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const __dirname = path.dirname(fileURLToPath(import.meta.url))
const frontendRoot = path.resolve(__dirname, '../..')
const distAssets = path.join(frontendRoot, 'dist/assets')
const maxChunkBytes = 500 * 1024

assert.ok(fs.existsSync(distAssets), '[frontend-build-chunk-size-contract] dist/assets missing; run npm run build first')

const oversized = fs.readdirSync(distAssets)
  .filter((name) => name.endsWith('.js'))
  .map((name) => {
    const filePath = path.join(distAssets, name)
    return {
      name,
      size: fs.statSync(filePath).size,
    }
  })
  .filter((entry) => entry.size > maxChunkBytes)

assert.deepEqual(
  oversized,
  [],
  `[frontend-build-chunk-size-contract] JavaScript chunks must stay <= ${maxChunkBytes} bytes`,
)

console.log('[frontend-build-chunk-size-contract] PASS')
