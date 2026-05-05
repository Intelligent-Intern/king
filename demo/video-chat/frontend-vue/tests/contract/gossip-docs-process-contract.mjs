import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const __dirname = path.dirname(fileURLToPath(import.meta.url))
const repoRoot = path.resolve(__dirname, '../../../../..')
const currentPath = path.join(repoRoot, 'GOSSIP_CURRENT_BUILD.md')
const planningPath = path.join(repoRoot, 'GOSSIP_PLANNING.md')

function assert(condition, message) {
  if (!condition) {
    throw new Error(`[gossip-docs-process-contract] ${message}`)
  }
}

function readRequired(filePath) {
  assert(fs.existsSync(filePath), `${path.basename(filePath)} must exist at the repo root`)
  const content = fs.readFileSync(filePath, 'utf8')
  assert(content.trim() !== '', `${path.basename(filePath)} must not be empty`)
  return content
}

const current = readRequired(currentPath)
const planning = readRequired(planningPath)

for (const section of [
  '## Current State',
  '## What Is Still Simulated',
  '## Current Server Role',
  '## Current Peer Role',
  '## Verification',
  '## Iteration Log',
]) {
  assert(current.includes(section), `GOSSIP_CURRENT_BUILD.md missing section: ${section}`)
}

for (const phrase of [
  '`publishFrame()` seeds only the publisher neighbor set',
  'GossipRtcDataChannelTransport',
  'VITE_VIDEOCHAT_GOSSIP_DATA_LANE',
  'Step 3: Feature Flag The Gossip Data Lane',
  'Step 4: Bind Gossip RTCDataChannel Transport To Live Native Peers',
  'Step 5: Route Live Gossip Receives To Remote Decode',
  'gossip_data_lane_frame_routed',
  'king-video-chat-gossipmesh-iibin-media-envelope',
  'king-object-store-gossipmesh-control-plane',
  'npm run test:contract:gossip',
  'npm run build',
]) {
  assert(current.includes(phrase), `GOSSIP_CURRENT_BUILD.md missing current build fact: ${phrase}`)
}

for (const section of [
  '## Operating Rule',
  '## Ranked Next Tasks',
  '## Current Priority',
  '## Step Checklist Template',
]) {
  assert(planning.includes(section), `GOSSIP_PLANNING.md missing section: ${section}`)
}

for (const phrase of [
  'Every gossip mesh iteration must update both',
  'executable regression coverage',
  'Impact:',
  'Complexity:',
  'Done when:',
]) {
  assert(planning.includes(phrase), `GOSSIP_PLANNING.md missing planning rule: ${phrase}`)
}

assert(
  planning.indexOf('### 1. Bind Gossip Data Transport To Live Native WebRTC Peers')
    < planning.indexOf('### 2. Add Server-Style Topology Snapshot Contract'),
  'ranked tasks must keep the live RTC binding as the highest-impact item',
)

console.log('[gossip-docs-process-contract] PASS')
