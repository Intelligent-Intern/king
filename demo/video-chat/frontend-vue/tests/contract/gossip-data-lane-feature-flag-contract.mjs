import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const __dirname = path.dirname(fileURLToPath(import.meta.url))
const frontendRoot = path.resolve(__dirname, '../..')
const featureFlagsPath = path.join(frontendRoot, 'src/lib/gossipmesh/featureFlags.ts')
const controllerPath = path.join(frontendRoot, 'src/lib/gossipmesh/gossipController.ts')
const harnessPath = path.join(frontendRoot, 'src/components/GossipHarness.vue')
const packagePath = path.join(frontendRoot, 'package.json')
const featureFlags = fs.readFileSync(featureFlagsPath, 'utf8')
const controller = fs.readFileSync(controllerPath, 'utf8')
const harness = fs.readFileSync(harnessPath, 'utf8')
const packageJson = fs.readFileSync(packagePath, 'utf8')

function assert(condition, message) {
  if (!condition) {
    throw new Error(`[gossip-data-lane-feature-flag-contract] ${message}`)
  }
}

assert(
  /export type GossipDataLaneMode = 'off' \| 'shadow' \| 'active'/.test(featureFlags),
  'feature flag must expose off, shadow, and active modes',
)
assert(
  /VITE_VIDEOCHAT_GOSSIP_DATA_LANE/.test(featureFlags),
  'feature flag must be driven by a Vite environment key',
)
assert(
  /return 'off'/.test(featureFlags),
  'gossip data lane must default to off',
)
assert(
  /normalized === '1' \|\| normalized === 'true' \|\| normalized === 'active'/.test(featureFlags),
  'active mode must be explicit',
)
assert(
  /normalized === 'shadow' \|\| normalized === 'observe' \|\| normalized === 'diagnostic'/.test(featureFlags),
  'shadow mode must be explicit and distinguishable from active',
)
assert(
  /publish:\s*mode === 'active'/.test(featureFlags) && /receive:\s*mode === 'active'/.test(featureFlags),
  'only active mode may publish or receive gossip data frames',
)
assert(
  /diagnosticsLabel/.test(featureFlags)
    && /gossip_data_off/.test(featureFlags)
    && /gossip_data_shadow/.test(featureFlags)
    && /gossip_data_active/.test(featureFlags),
  'feature flag must expose diagnostics labels for every mode',
)
assert(
  /private dataLaneConfig:\s*GossipDataLaneConfig = GOSSIP_DATA_LANE_CONFIG/.test(controller),
  'controller must default to resolved gossip data lane config',
)
assert(
  /setDataLaneConfig\(config:\s*GossipDataLaneConfig\):\s*void/.test(controller),
  'controller must allow tests and runtime integration to override the gossip data lane config',
)
assert(
  /if \(!this\.dataLaneConfig\.publish\)[\s\S]*gossip_data_lane_disabled/.test(controller),
  'publishFrame must be gated when gossip data lane publish is disabled',
)
assert(
  /data_lane_mode:\s*this\.dataLaneConfig\.mode/.test(controller)
    && /diagnostics_label:\s*this\.dataLaneConfig\.diagnosticsLabel/.test(controller),
  'data-lane events must include feature flag diagnostics',
)
assert(
  packageJson.includes('gossip-data-lane-feature-flag-contract.mjs'),
  'gossip contract suite must include the data lane feature flag contract',
)
assert(
  /ctrl\.setDataLaneConfig\(\{[\s\S]*mode:\s*'active'[\s\S]*diagnosticsLabel:\s*'gossip_data_active'/.test(harness),
  'local gossip harness must explicitly opt into the active data lane because production defaults off',
)

console.log('[gossip-data-lane-feature-flag-contract] PASS')
