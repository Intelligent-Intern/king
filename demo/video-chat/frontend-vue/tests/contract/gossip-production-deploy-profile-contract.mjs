import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const __dirname = path.dirname(fileURLToPath(import.meta.url))
const frontendRoot = path.resolve(__dirname, '../..')
const repoRoot = path.resolve(frontendRoot, '../../..')

function read(relativePath) {
  return fs.readFileSync(path.join(repoRoot, relativePath), 'utf8')
}

function assert(condition, message) {
  if (!condition) {
    throw new Error(`[gossip-production-deploy-profile-contract] ${message}`)
  }
}

const deployScript = read('demo/video-chat/scripts/deploy.sh')
const compose = read('demo/video-chat/docker-compose.v1.yml')
const frontendDockerfile = read('demo/video-chat/frontend-vue/Dockerfile')
const edgeDockerfile = read('demo/video-chat/edge/Dockerfile')
const packageJson = read('demo/video-chat/frontend-vue/package.json')

assert(
  deployScript.includes('VITE_VIDEOCHAT_GOSSIP_DATA_LANE=active')
    && deployScript.includes('VITE_VIDEOCHAT_MEDIA_CARRIER=gossip_primary'),
  'production deploy env template must make the gossip data lane active and select gossip_primary media carrier',
)
assert(
  deployScript.includes('set_env_value VITE_VIDEOCHAT_GOSSIP_DATA_LANE active')
    && deployScript.includes('set_env_value VITE_VIDEOCHAT_MEDIA_CARRIER gossip_primary'),
  'production deploy env refresh must persist gossip_primary flags on every deploy',
)
assert(
  /VITE_VIDEOCHAT_GOSSIP_DATA_LANE:\s*"\$\{VITE_VIDEOCHAT_GOSSIP_DATA_LANE:-\}"/.test(compose)
    && /VITE_VIDEOCHAT_MEDIA_CARRIER:\s*"\$\{VITE_VIDEOCHAT_MEDIA_CARRIER:-\}"/.test(compose),
  'compose must pass gossip lane and media carrier flags into frontend/edge build surfaces',
)
assert(
  /ARG VITE_VIDEOCHAT_GOSSIP_DATA_LANE=""/.test(frontendDockerfile)
    && /ENV VITE_VIDEOCHAT_GOSSIP_DATA_LANE="\$\{VITE_VIDEOCHAT_GOSSIP_DATA_LANE\}"/.test(frontendDockerfile)
    && /ARG VITE_VIDEOCHAT_MEDIA_CARRIER=""/.test(frontendDockerfile)
    && /ENV VITE_VIDEOCHAT_MEDIA_CARRIER="\$\{VITE_VIDEOCHAT_MEDIA_CARRIER\}"/.test(frontendDockerfile),
  'frontend image build must expose gossip lane and media carrier flags to Vite',
)
assert(
  /ARG VITE_VIDEOCHAT_GOSSIP_DATA_LANE=""/.test(edgeDockerfile)
    && /ENV VITE_VIDEOCHAT_GOSSIP_DATA_LANE="\$\{VITE_VIDEOCHAT_GOSSIP_DATA_LANE\}"/.test(edgeDockerfile)
    && /ARG VITE_VIDEOCHAT_MEDIA_CARRIER=""/.test(edgeDockerfile)
    && /ENV VITE_VIDEOCHAT_MEDIA_CARRIER="\$\{VITE_VIDEOCHAT_MEDIA_CARRIER\}"/.test(edgeDockerfile),
  'edge image frontend build must expose gossip lane and media carrier flags to Vite',
)
assert(
  packageJson.includes('gossip-production-deploy-profile-contract.mjs'),
  'gossip contract suite must include the production deploy profile contract',
)

console.log('[gossip-production-deploy-profile-contract] PASS')
