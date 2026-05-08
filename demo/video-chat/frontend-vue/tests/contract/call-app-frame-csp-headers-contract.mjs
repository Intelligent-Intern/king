import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import path from 'node:path';

const root = path.resolve(new URL('../..', import.meta.url).pathname);
const repoRoot = path.resolve(root, '../../..');

async function read(relativePath) {
  return readFile(path.join(repoRoot, relativePath), 'utf8');
}

const [
  edgeSource,
  callAppStaticSource,
  workspaceStateSource,
  semanticDnsSource,
  semanticDnsContract,
  deploySmoke,
  prodDebug,
] = await Promise.all([
  read('demo/video-chat/edge/edge.php'),
  read('demo/video-chat/edge/call_app_static.php'),
  read('demo/video-chat/frontend-vue/src/domain/realtime/callApps/callAppWorkspaceState.js'),
  read('demo/video-chat/backend-king-php/domain/call_apps/call_app_semantic_dns.php'),
  read('demo/video-chat/backend-king-php/tests/call-app-semantic-dns-contract.php'),
  read('demo/video-chat/scripts/deploy-smoke.sh'),
  read('demo/video-chat/scripts/prod-debug.sh'),
]);

assert.match(
  callAppStaticSource,
  /function videochat_edge_call_app_normalize_origin[\s\S]*parse_url\(\$trimmed\)[\s\S]*in_array\(\$scheme, \['http', 'https'\], true\)[\s\S]*return \$origin/s,
  'Call App static hosting must normalize trusted embedder origins before serializing CSP headers',
);

assert.match(
  callAppStaticSource,
  /function videochat_edge_call_app_frame_ancestor[\s\S]*return \$normalized !== '' \? \$normalized : "'none'"/,
  'Call App CSP must fail closed when the configured embedder origin is invalid',
);

assert.match(
  callAppStaticSource,
  /function videochat_edge_call_app_content_security_policy[\s\S]*"default-src 'self'"[\s\S]*"script-src 'self' 'unsafe-inline'"[\s\S]*"style-src 'self' 'unsafe-inline'"[\s\S]*"connect-src 'self'"[\s\S]*"img-src 'self' data: blob:"[\s\S]*"font-src 'self'"[\s\S]*"base-uri 'none'"[\s\S]*"object-src 'none'"[\s\S]*"frame-src 'none'"[\s\S]*'frame-ancestors ' \. \$frameAncestor/s,
  'Call App CSP must be explicit, self-scoped, and frame-ancestor compatible with the parent app',
);

assert.doesNotMatch(
  callAppStaticSource,
  /script-src[^"'\n;]*\*|connect-src[^"'\n;]*\*|frame-ancestors[^"'\n;]*\*/,
  'Call App CSP must not weaken script, connect, or frame-ancestor policy to wildcards',
);

assert.match(
  callAppStaticSource,
  /Content-Security-Policy'\] = videochat_edge_call_app_content_security_policy\(\$allowedEmbedderOrigin\)[\s\S]*Allow-CSP-From'\] = \$allowedEmbedderOrigin/s,
  'Call App HTML responses must deliver CSP and accept the same normalized embedder origin for Embedded-CSP checks',
);

assert.match(
  edgeSource,
  /\$serveStatic = static function \([\s\S]*use \([\s\S]*\$domain[\s\S]*\$isCallAppAsset[\s\S]*videochat_edge_call_app_content_security_policy\(\$allowedEmbedderOrigin\)[\s\S]*Allow-CSP-From'\] = \$allowedEmbedderOrigin/s,
  'edge fallback for packaged /call-app assets must use the same Call App CSP helper and app embedder origin',
);

assert.match(
  edgeSource,
  /videochat_edge_serve_call_app_static\([\s\S]*'https:\/\/' \. \$domain/,
  'dedicated Call App host must receive the configured frontend app origin as frame ancestor',
);

assert.doesNotMatch(
  `${edgeSource}\n${callAppStaticSource}`,
  /X-Frame-Options|frame-ancestors 'self'/,
  'Call App frame responses must not reintroduce X-Frame-Options or self-only frame ancestors',
);

assert.match(
  workspaceStateSource,
  /normalizeConfiguredCallAppOrigin[\s\S]*VITE_VIDEOCHAT_CALL_APP_ORIGIN[\s\S]*\['app', 'apps', 'whiteboard'\]\.includes\(parts\[0\]\)[\s\S]*parts\[0\] = hostAppKey/s,
  'frontend iframe URL generation must stay aligned with the dedicated Call App host family',
);

assert.match(
  edgeSource,
  /if \(\$label === '' \|\| str_contains\(\$label, '\.'\)\) \{[\s\S]*return ''/s,
  'edge Call App host routing must reject nested app labels like app.whiteboard.kingrt.com',
);

assert.match(
  semanticDnsContract,
  /array_merge\(\$whiteboard, \['app_key' => 'kanban'\]\)[\s\S]*'whiteboard\.kingrt\.test'[\s\S]*'kanban\.kingrt\.test'/s,
  'Semantic-DNS contract must prove future app hosts are derived as app-key.root-domain, not nested below whiteboard',
);

assert.match(
  deploySmoke,
  /expect_response_header_contains[\s\S]*Content-Security-Policy[\s\S]*frame-ancestors https:\/\/\$\{DEPLOY_APP_DOMAIN\}[\s\S]*Allow-CSP-From[\s\S]*https:\/\/\$\{DEPLOY_APP_DOMAIN\}[\s\S]*expect_response_header_absent[\s\S]*X-Frame-Options/s,
  'deploy smoke must verify production Whiteboard CSP headers and absence of frame-blocking X-Frame-Options',
);

assert.match(
  prodDebug,
  /call_app_csp_header_proof[\s\S]*\/public\/index\.html[\s\S]*\/call-app\/whiteboard\/public\/index\.html/s,
  'prod-debug must provide a read-only proof path for both Whiteboard Call App production entrypoints',
);

assert.match(
  prodDebug,
  /Content-Security-Policy[\s\S]*frame-ancestors https:\/\/\$\{DEPLOY_APP_DOMAIN\}[\s\S]*script-src 'self'[\s\S]*connect-src 'self'[\s\S]*Allow-CSP-From[\s\S]*https:\/\/\$\{DEPLOY_APP_DOMAIN\}[\s\S]*X-Frame-Options/s,
  'prod-debug must prove Call App CSP and Embedded-CSP headers are compatible with the configured app embedder',
);

assert.match(
  prodDebug,
  /wildcard_frame_ancestors_pattern[\s\S]*frame-ancestors\[\^;\]\*\\\*[\s\S]*wildcard_frame_src_pattern[\s\S]*frame-src\[\^;\]\*\\\*[\s\S]*wildcard_script_src_pattern[\s\S]*script-src\[\^;\]\*\\\*[\s\S]*wildcard_connect_src_pattern[\s\S]*connect-src\[\^;\]\*\\\*/,
  'prod-debug must reject wildcard frame/script/connect CSP directives in production responses',
);

assert.match(
  prodDebug,
  /nested_pattern="https\?:\/\/\[A-Za-z0-9\.-\]\+\\\\\.\$\{escaped_app_domain\}"[\s\S]*must not reference nested \*\.\$\{DEPLOY_APP_DOMAIN\} service origins/s,
  'prod-debug must reject nested *.app.kingrt.com service origins in Call App production responses',
);

assert.doesNotMatch(
  `${edgeSource}\n${callAppStaticSource}\n${deploySmoke}\n${prodDebug}`,
  /location\.reload|window\.location\.reload|reload\(\)/,
  'edge/deploy Call App frame CSP failures must not be handled through reload loops',
);

console.log('[call-app-frame-csp-headers-contract] PASS');
