import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import path from 'node:path';

const root = path.resolve(new URL('../..', import.meta.url).pathname);
const repoRoot = path.resolve(root, '../../..');

async function read(relativePath) {
  return readFile(path.join(repoRoot, relativePath), 'utf8');
}

const [
  sprint,
  semanticDnsDomain,
  semanticDnsTest,
  server,
  workspaceState,
  compose,
  deploy,
  deployHetzner,
] = await Promise.all([
  read('SPRINT.md'),
  read('demo/video-chat/backend-king-php/domain/call_apps/call_app_semantic_dns.php'),
  read('demo/video-chat/backend-king-php/tests/call-app-semantic-dns-contract.php'),
  read('demo/video-chat/backend-king-php/server.php'),
  read('demo/video-chat/frontend-vue/src/domain/realtime/callApps/callAppWorkspaceState.js'),
  read('demo/video-chat/docker-compose.v1.yml'),
  read('demo/video-chat/scripts/deploy.sh'),
  read('demo/video-chat/scripts/lib/deploy-hetzner.sh'),
]);

assert.match(
  sprint,
  /WCA-09 Production deployment, subdomain, and Mothernode registration[\s\S]*Call App iframe host[\s\S]*Mothernode host/,
  'SPRINT.md must track the production Call App deployment ticket',
);

for (const functionName of [
  'videochat_call_app_semantic_dns_runtime_options_from_env',
  'videochat_call_app_register_semantic_dns_mother_node',
  'videochat_call_app_register_runtime_semantic_dns_catalog',
  'videochat_call_app_should_start_semantic_dns_runtime',
  'videochat_call_app_start_semantic_dns_runtime',
]) {
  assert.match(
    semanticDnsDomain,
    new RegExp(`function\\s+${functionName}\\s*\\(`),
    `${functionName} must exist in the Call App Semantic-DNS domain`,
  );
}

for (const envKey of [
  'VIDEOCHAT_DEPLOY_CALL_APP_DOMAIN',
  'VIDEOCHAT_DEPLOY_MOTHERNODE_DOMAIN',
  'VIDEOCHAT_CALL_APP_PUBLIC_HOST',
  'VIDEOCHAT_CALL_APP_MOTHERNODE_HOST',
  'VIDEOCHAT_CALL_APP_MCP_ENDPOINT',
  'VIDEOCHAT_CALL_APP_SEMANTIC_DNS_REGISTER',
]) {
  assert.match(semanticDnsDomain, new RegExp(envKey), `semantic DNS domain must parse ${envKey}`);
  assert.match(compose + deploy, new RegExp(envKey), `compose/deploy must propagate ${envKey}`);
}

assert.match(
  semanticDnsDomain,
  /king_semantic_dns_init[\s\S]*king_semantic_dns_start_server[\s\S]*videochat_call_app_register_runtime_semantic_dns_catalog/s,
  'Call App runtime must start Semantic DNS before registering package metadata',
);

assert.match(
  server,
  /call_app_semantic_dns\.php[\s\S]*videochat_call_app_should_start_semantic_dns_runtime[\s\S]*videochat_call_app_start_semantic_dns_runtime/s,
  'backend server must start the Call App Semantic-DNS runtime through the domain helper',
);

assert.match(
  semanticDnsTest,
  /VIDEOCHAT_DEPLOY_CALL_APP_DOMAIN[\s\S]*apps\.kingrt\.test[\s\S]*VIDEOCHAT_DEPLOY_MOTHERNODE_DOMAIN[\s\S]*mother\.kingrt\.test[\s\S]*videochat_call_app_register_runtime_semantic_dns_catalog/s,
  'backend contract must prove deploy env parsing and runtime Mothernode registration',
);

assert.match(workspaceState, /VITE_VIDEOCHAT_CALL_APP_ORIGIN[\s\S]*CALL_APP_IFRAME_ORIGIN/s);
assert.ok(
  workspaceState.includes("return CALL_APP_IFRAME_ORIGIN !== '' ?"),
  'Call App iframe URL must honor the dedicated deployment origin',
);
assert.match(
  workspaceState,
  /`\$\{CALL_APP_IFRAME_ORIGIN\}\$\{path\}` : path/,
  'Call App iframe URL must prefix the sanitized app path with the deployment origin',
);

assert.match(
  compose,
  /videochat-backend-v1:[\s\S]*VIDEOCHAT_CALL_APP_SEMANTIC_DNS_REGISTER[\s\S]*videochat-frontend-v1:[\s\S]*VITE_VIDEOCHAT_CALL_APP_ORIGIN[\s\S]*videochat-edge-v1:[\s\S]*VITE_VIDEOCHAT_CALL_APP_ORIGIN/s,
  'compose must pass Call App runtime env into backend and build-time iframe origin into frontend/edge',
);

assert.match(
  deploy,
  /DEPLOY_CALL_APP_DOMAIN[\s\S]*apps\.\$\{DEPLOY_DOMAIN\}[\s\S]*DEPLOY_MOTHERNODE_DOMAIN[\s\S]*mother\.\$\{DEPLOY_DOMAIN\}/,
  'deploy script must default Call App and Mothernode subdomains from the main domain',
);

assert.match(
  deploy,
  /CERTBOT_DOMAINS=\([\s\S]*CALL_APP_DOMAIN[\s\S]*MOTHERNODE_DOMAIN[\s\S]*certbot certonly/s,
  'deploy script must include Call App and Mothernode domains in the certificate SAN set',
);

assert.match(
  deployHetzner,
  /VIDEOCHAT_DEPLOY_CALL_APP_DOMAIN[\s\S]*VIDEOCHAT_DEPLOY_MOTHERNODE_DOMAIN[\s\S]*DEPLOY_CALL_APP_DOMAIN[\s\S]*DEPLOY_MOTHERNODE_DOMAIN/s,
  'Hetzner deploy helper must persist and provision Call App and Mothernode DNS names',
);

console.log('[call-app-production-deploy-contract] PASS');
