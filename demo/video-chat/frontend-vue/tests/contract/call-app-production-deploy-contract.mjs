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
  backendDockerfile,
  compose,
  edgeDockerfile,
  edgePhp,
  deploy,
  deploySmoke,
  deployHetzner,
] = await Promise.all([
  read('SPRINT.md'),
  read('demo/video-chat/backend-king-php/domain/call_apps/call_app_semantic_dns.php'),
  read('demo/video-chat/backend-king-php/tests/call-app-semantic-dns-contract.php'),
  read('demo/video-chat/backend-king-php/server.php'),
  read('demo/video-chat/frontend-vue/src/domain/realtime/callApps/callAppWorkspaceState.js'),
  read('demo/video-chat/backend-king-php/Dockerfile'),
  read('demo/video-chat/docker-compose.v1.yml'),
  read('demo/video-chat/edge/Dockerfile'),
  read('demo/video-chat/edge/edge.php'),
  read('demo/video-chat/scripts/deploy.sh'),
  read('demo/video-chat/scripts/deploy-smoke.sh'),
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
  'VIDEOCHAT_DEPLOY_REGISTRY_DOMAIN',
  'VIDEOCHAT_DEPLOY_MOTHERNODE_DOMAIN',
  'VIDEOCHAT_CALL_APP_PUBLIC_HOST',
  'VIDEOCHAT_CALL_APP_PUBLIC_ROOT_DOMAIN',
  'VIDEOCHAT_CALL_APP_REGISTRY_HOST',
  'VIDEOCHAT_CALL_APP_MOTHERNODE_HOST',
  'VIDEOCHAT_CALL_APP_MCP_ENDPOINT',
  'VIDEOCHAT_CALL_APP_SEMANTIC_DNS_REGISTER',
  'VIDEOCHAT_CALL_APP_PACKAGE_ROOT',
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
  /VIDEOCHAT_DEPLOY_CALL_APP_DOMAIN[\s\S]*whiteboard\.kingrt\.test[\s\S]*VIDEOCHAT_DEPLOY_REGISTRY_DOMAIN[\s\S]*registry\.kingrt\.test[\s\S]*videochat_call_app_register_runtime_semantic_dns_catalog/s,
  'backend contract must prove deploy env parsing and runtime registry registration',
);

assert.match(workspaceState, /VITE_VIDEOCHAT_CALL_APP_ORIGIN[\s\S]*CALL_APP_IFRAME_ORIGIN/s);
assert.ok(
  workspaceState.includes('function callAppOriginForAppKey'),
  'Call App iframe URL must honor the dedicated deployment origin',
);
assert.match(
  workspaceState,
  /parts\[0\] = hostAppKey[\s\S]*return origin !== '' \? `\$\{origin\}\$\{path\}` : path/s,
  'Call App iframe URL must resolve whiteboard.kingrt.com and future {app_key}.kingrt.com origins',
);

assert.match(
  compose,
  /videochat-backend-v1:[\s\S]*VIDEOCHAT_CALL_APP_SEMANTIC_DNS_REGISTER[\s\S]*videochat-frontend-v1:[\s\S]*VITE_VIDEOCHAT_CALL_APP_ORIGIN[\s\S]*videochat-edge-v1:[\s\S]*VITE_VIDEOCHAT_CALL_APP_ORIGIN/s,
  'compose must pass Call App runtime env into backend and build-time iframe origin into frontend/edge',
);

assert.match(
  backendDockerfile,
  /COPY demo\/call-app\/ \/call-app\/[\s\S]*VIDEOCHAT_CALL_APP_PACKAGE_ROOT=\/call-app/s,
  'backend image must carry Call App packages at the package-root used by Semantic-DNS registration',
);

assert.match(
  edgeDockerfile,
  /COPY demo\/call-app\/ \/app\/call-app\//,
  'edge image must carry Call App iframe assets',
);

assert.match(
  edgePhp,
  /VIDEOCHAT_EDGE_CALL_APP_DOMAIN[\s\S]*call_app_static[\s\S]*videochat_edge_serve_call_app_static/s,
  'edge must route the Call App subdomain/path to the packaged iframe assets',
);

assert.match(
  deploy,
  /DEPLOY_APP_DOMAIN[\s\S]*app\.\$\{DEPLOY_DOMAIN\}[\s\S]*DEPLOY_CALL_APP_DOMAIN[\s\S]*whiteboard\.\$\{DEPLOY_DOMAIN\}[\s\S]*DEPLOY_REGISTRY_DOMAIN[\s\S]*registry\.\$\{DEPLOY_DOMAIN\}/,
  'deploy script must split app.kingrt.com from the kingrt.com service root and default Call App/registry subdomains from the root',
);

assert.match(
  deploy,
  /CERTBOT_DOMAINS=\([\s\S]*APP_DOMAIN[\s\S]*CALL_APP_DOMAIN[\s\S]*REGISTRY_DOMAIN[\s\S]*certbot certonly/s,
  'deploy script must include app, Call App, and registry domains in the certificate SAN set',
);

assert.match(
  deploySmoke,
  /DEPLOY_APP_DOMAIN="\$\{VIDEOCHAT_DEPLOY_APP_DOMAIN:-app\.\$\{DEPLOY_DOMAIN\}\}"[\s\S]*expect_http_code https-frontend 200 "https:\/\/\$\{DEPLOY_APP_DOMAIN\}\/"/s,
  'deploy smoke must probe app.kingrt.com as the frontend when kingrt.com is the service root',
);

assert.match(
  deploySmoke,
  /CALL_APP_DOMAIN=\$\{call_app_q\} REGISTRY_DOMAIN=\$\{registry_q\}[\s\S]*"\$\{CALL_APP_DOMAIN\}" "\$\{REGISTRY_DOMAIN\}"/s,
  'deploy smoke must verify Call App and registry certificate SANs',
);

assert.match(
  deploySmoke,
  /expect_http_code call-app-whiteboard-host 200 "https:\/\/\$\{DEPLOY_CALL_APP_DOMAIN\}\/public\/index\.html"[\s\S]*expect_http_code call-app-whiteboard-path 200 "https:\/\/\$\{DEPLOY_CALL_APP_DOMAIN\}\/call-app\/whiteboard\/public\/index\.html"/s,
  'deploy smoke must verify the semantic whiteboard.kingrt.com host and packaged Call App path',
);

assert.match(
  deployHetzner,
  /VIDEOCHAT_DEPLOY_APP_DOMAIN[\s\S]*VIDEOCHAT_DEPLOY_CALL_APP_DOMAIN[\s\S]*VIDEOCHAT_DEPLOY_REGISTRY_DOMAIN[\s\S]*DEPLOY_APP_DOMAIN[\s\S]*DEPLOY_CALL_APP_DOMAIN[\s\S]*DEPLOY_REGISTRY_DOMAIN/s,
  'Hetzner deploy helper must persist and provision app, Call App, and registry DNS names',
);

assert.ok(!deploy.includes('cnd.${DEPLOY_DOMAIN}') && !deployHetzner.includes('cnd.${DEPLOY_DOMAIN}'), 'production generation must not provision legacy cnd aliases');
assert.ok(!deploy.includes('mother.${DEPLOY_DOMAIN}') && !deployHetzner.includes('mother.${DEPLOY_DOMAIN}'), 'production generation must not use mother.kingrt.com as the canonical registry host');

console.log('[call-app-production-deploy-contract] PASS');
