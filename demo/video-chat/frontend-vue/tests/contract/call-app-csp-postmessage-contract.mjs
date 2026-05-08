import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import path from 'node:path';

const root = path.resolve(new URL('../..', import.meta.url).pathname);
const repoRoot = path.resolve(root, '../../..');

async function read(relativePath) {
  return readFile(path.join(repoRoot, relativePath), 'utf8');
}

const [
  hostSource,
  workspaceStateSource,
  iframeBridgeSource,
  crdtBridgeSource,
  callAppStaticSource,
  edgeSource,
] = await Promise.all([
  read('demo/video-chat/frontend-vue/src/domain/realtime/callApps/CallAppWorkspaceHost.vue'),
  read('demo/video-chat/frontend-vue/src/domain/realtime/callApps/callAppWorkspaceState.js'),
  read('demo/video-chat/frontend-vue/src/domain/realtime/callApps/useCallAppIframeBridge.js'),
  read('demo/video-chat/frontend-vue/src/domain/realtime/callApps/useCallAppCrdtBridge.js'),
  read('demo/video-chat/edge/call_app_static.php'),
  read('demo/video-chat/edge/edge.php'),
]);

assert.match(
  workspaceStateSource,
  /function normalizeConfiguredCallAppOrigin[\s\S]*https:\/\/\$\{trimmed\}[\s\S]*return parsed\.toString\(\)\.replace\(\/\\\/\+\$\/,\s*''\)/,
  'Call App iframe origin must accept the configured whiteboard host even when it is supplied as a bare host',
);

assert.match(
  workspaceStateSource,
  /VITE_VIDEOCHAT_CALL_APP_ORIGIN[\s\S]*normalizeConfiguredCallAppOrigin[\s\S]*\['app', 'apps', 'whiteboard'\]\.includes\(parts\[0\]\)[\s\S]*parts\[0\] = hostAppKey/s,
  'Call App iframe URL must use the configured whiteboard host and derive future app subdomains from that host family',
);

assert.doesNotMatch(
  hostSource,
  /\scsp=/,
  'Call App iframe host must not use the iframe csp attribute because CSP is enforced by the served response',
);

assert.match(
  hostSource,
  /sandbox="allow-scripts allow-forms allow-pointer-lock allow-downloads"/,
  'Call App iframe must keep the sandbox policy that yields an opaque iframe origin',
);

assert.match(
  callAppStaticSource,
  /function videochat_edge_call_app_content_security_policy[\s\S]*"connect-src 'self'"[\s\S]*"img-src 'self' data: blob:"[\s\S]*'frame-ancestors ' \. \$frameAncestor[\s\S]*Content-Security-Policy'\] = videochat_edge_call_app_content_security_policy/s,
  'Call App static CSP must be compatible with the whiteboard host and allow only the configured app embedder origin',
);

assert.match(
  callAppStaticSource,
  /Allow-CSP-From'[\s\S]*allowedEmbedderOrigin/,
  'Call App static response must advertise the trusted embedder origin for browser Embedded-CSP compatibility',
);

assert.match(
  edgeSource,
  /videochat_edge_serve_call_app_static\([\s\S]*'https:\/\/' \. \$domain/,
  'edge must serialize the frontend app origin as the trusted frame ancestor for Call App subdomain responses',
);

assert.match(
  iframeBridgeSource,
  /import \{ computed, isProxy, isRef,[\s\S]*toRaw, unref,[\s\S]*\} from 'vue'/,
  'Call App bridge sanitizer must unwrap Vue refs/proxies before postMessage',
);

assert.match(
  iframeBridgeSource,
  /function rawBridgeValue[\s\S]*isRef\(value\)[\s\S]*isProxy\(value\) \? toRaw\(value\) : value/s,
  'Call App bridge sanitizer must normalize reactive values to raw cloneable values',
);

assert.match(
  iframeBridgeSource,
  /export function cloneSafeCallAppBridgePayload[\s\S]*structuredClone\(sanitized\)[\s\S]*JSON\.parse\(JSON\.stringify\(sanitized\)\)/s,
  'Call App bridge must prove payloads are structured-clone safe and retain a JSON fallback',
);

assert.match(
  iframeBridgeSource,
  /failedPostGeneration[\s\S]*if \(failedPostGeneration === generation\) return false[\s\S]*reason: 'post_message_failed'/s,
  'Call App launch postMessage failures must be terminal for the current launch generation and must not retry-loop',
);

assert.match(
  crdtBridgeSource,
  /cloneSafeCallAppBridgePayload[\s\S]*frameWindow\.postMessage\(message, '\*'\)/s,
  'Call App CRDT bridge responses must also pass through the clone-safe postMessage serializer',
);

assert.doesNotMatch(
  `${hostSource}\n${workspaceStateSource}\n${iframeBridgeSource}\n${crdtBridgeSource}`,
  /location\.reload|window\.location\.reload|iframeRef\.value\.src\s*=/,
  'Call App iframe launch, serialization, and CSP failures must not trigger frontend reload loops',
);

console.log('[call-app-csp-postmessage-contract] PASS');
