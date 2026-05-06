import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const frontendRoot = path.resolve(__dirname, '../..');
const repoRoot = path.resolve(frontendRoot, '../../..');

function read(relativePath) {
  return fs.readFileSync(path.join(repoRoot, relativePath), 'utf8');
}

const administrationApi = fs.readFileSync(
  path.join(frontendRoot, 'src/domain/workspace/administrationApi.js'),
  'utf8',
);
const workspaceModule = read('demo/video-chat/backend-king-php/http/module_workspace_administration.php');
const workspaceDomain = read('demo/video-chat/backend-king-php/domain/workspace/workspace_app_configuration.php');
const edge = read('demo/video-chat/edge/edge.php');

assert.match(
  administrationApi,
  /BACKGROUND_IMAGE_UPLOAD_BATCH_MAX_CHARS\s*=\s*7_250_000/,
  'background image uploads must split bulk files before the King HTTP body limit',
);
assert.match(
  administrationApi,
  /'x-upload-trace-id': traceId/,
  'background image upload requests must carry a trace id header',
);
assert.match(
  administrationApi,
  /client_batch_index: index \+ 1/,
  'background image upload payload must expose the client batch index',
);
assert.match(
  administrationApi,
  /console\.info\('\[BackgroundImages\] upload batch'/,
  'client-side diagnostics must log the upload batch trace',
);
assert.match(
  workspaceModule,
  /videochat_workspace_background_upload_log\(\$traceId, 'route_request_received'/,
  'backend route must log request receipt with the same trace id',
);
assert.match(
  workspaceModule,
  /videochat_workspace_background_upload_max_body_bytes\(\$brandingMaxBytes\)/,
  'backend route must reject oversized background upload bodies before JSON decode',
);
for (const stage of [
  'image_parse_started',
  'object_store_put_started',
  'object_store_put_ok',
  'object_store_put_failed',
  'local_file_write_ok',
  'db_insert_ok',
]) {
  assert.ok(workspaceDomain.includes(stage), `workspace background upload diagnostics missing ${stage}`);
}
assert.match(
  edge,
  /\$edgeUploadLog\(\$uploadTraceId, 'proxy_connect_started'/,
  'edge proxy must log background upload connect diagnostics',
);
assert.match(
  edge,
  /proxy_rejected_invalid_upload_length/,
  'edge proxy must reject malformed background upload Content-Length with diagnostics',
);
assert.match(
  edge,
  /X-Upload-Trace-Id/,
  'edge proxy must preserve trace headers through CORS',
);

console.log('[background-image-upload-diagnostics-contract] PASS');
