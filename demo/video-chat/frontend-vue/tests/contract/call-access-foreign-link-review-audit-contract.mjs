import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const frontendRoot = path.resolve(__dirname, '../..');
const repoRoot = path.resolve(frontendRoot, '../../..');

function read(relativePath) {
  return fs.readFileSync(path.join(repoRoot, relativePath), 'utf8');
}

function functionBody(source, name) {
  const start = source.indexOf(`function ${name}`);
  assert.notEqual(start, -1, `${name} must exist`);
  const next = source.indexOf('\nfunction ', start + 1);
  return source.slice(start, next === -1 ? source.length : next);
}

const reviewHelper = read('demo/video-chat/backend-king-php/domain/calls/call_access_review.php');
const auditEvents = read('demo/video-chat/backend-king-php/domain/audit/audit_events.php');
const backendContract = read('demo/video-chat/backend-king-php/tests/call-access-foreign-link-review-audit-contract.php');
const backendAggregate = read('demo/video-chat/backend-king-php/tests/iam-call-access-sqlite-runtime-proof.sh');
const packageJson = JSON.parse(read('demo/video-chat/frontend-vue/package.json'));
const uiMatrix = read('demo/video-chat/contracts/v1/ui-parity-acceptance.matrix.json');
const sprint = read('SPRINT.md');
const readiness = read('READYNESS_TRACKER.md');
const docs = read('documentation/iam7-13-foreign-link-review-audit.md');

const reviewTenantBody = functionBody(reviewHelper, 'videochat_call_access_review_tenant_id');
assert.match(
  reviewTenantBody,
  /\$call\['tenant_id'\][\s\S]*return \(int\) \$call\['tenant_id'\][\s\S]*\$accessLink\['tenant_id'\][\s\S]*return \(int\) \$accessLink\['tenant_id'\]/,
  'review flags must scope tenant to the resolved call before falling back to link tenant',
);

const auditTenantBody = functionBody(auditEvents, 'videochat_audit_call_access_tenant_id');
assert.match(
  auditTenantBody,
  /\$call\['tenant_id'\][\s\S]*return \(int\) \$call\['tenant_id'\][\s\S]*\$accessLink\['tenant_id'\][\s\S]*return \(int\) \$accessLink\['tenant_id'\]/,
  'call-access audit events must scope tenant to the resolved call before falling back to link tenant',
);

for (const helper of [
  'videochat_audit_record_call_access_link_open',
  'videochat_audit_record_call_access_invitation_created',
  'videochat_audit_record_call_access_account_compared',
  'videochat_audit_record_call_scoped_access_continued',
]) {
  assert.match(
    functionBody(auditEvents, helper),
    /'tenant_id'\s*=>\s*videochat_audit_call_access_tenant_id\(\$accessLink,\s*\$call\)/,
    `${helper} must use the shared call-scoped tenant resolver`,
  );
}

for (const [pattern, message] of [
  [/review tenant resolver must prefer the call organization over a stale link tenant/, 'backend proof must directly assert review tenant precedence'],
  [/audit tenant resolver must prefer the call organization over a stale link tenant/, 'backend proof must directly assert audit tenant precedence'],
  [/review flags must deduplicate per foreign subject and access fingerprint/, 'backend proof must assert duplicate review flag dedupe'],
  [/review flag tenant must follow the call organization[\s\S]*review flag call id must follow the call/, 'backend proof must assert review flag call scoping'],
  [/review audit tenant must follow the call organization[\s\S]*review audit call id must follow the call/, 'backend proof must assert review audit call scoping'],
  [/review audit must not persist raw access id[\s\S]*review audit must fingerprint the foreign link[\s\S]*review audit must fingerprint the session id/, 'backend proof must assert fingerprint-only identifiers'],
  [/host_name[\s\S]*token[\s\S]*sdp[\s\S]*ice_candidate[\s\S]*foreign_email[\s\S]*private_call_title/, 'backend proof must inject sensitive fields that must not persist'],
  [/mismatched account audit tenant must follow the call organization[\s\S]*mismatched account audit must mark foreign data omission/, 'backend proof must cover mismatch comparison audit scoping and privacy'],
]) {
  assert.match(backendContract, pattern, message);
}

const iamScript = String(packageJson.scripts?.['test:contract:iam-call-access'] || '');
assert.match(
  iamScript,
  /node tests\/contract\/call-access-foreign-link-review-audit-contract\.mjs/,
  'IAM contract gate must run the foreign-link review audit static contract',
);
assert.match(
  backendAggregate,
  /call-access-foreign-link-review-audit-contract\.sh/,
  'IAM SQLite aggregate must run the foreign-link review audit backend contract',
);
assert.match(
  uiMatrix,
  /frontend-vue\/tests\/contract\/call-access-foreign-link-review-audit-contract\.mjs[\s\S]*backend-king-php\/tests\/call-access-foreign-link-review-audit-contract\.sh/,
  'release-gate metadata must list the foreign-link review audit frontend and backend contracts',
);
assert.match(
  sprint,
  /\[x\] IAM7-13 Extract or prove foreign link review audit scoping/,
  'SPRINT.md must mark IAM7-13 complete after verification',
);
assert.match(
  docs,
  /local\/iam-e2e-foreign-link-review-audit[\s\S]*not safe to merge wholesale[\s\S]*focused current proof/,
  'IAM7-13 evidence must classify the historical branch and the focused extraction',
);
assert.match(
  readiness,
  /IAM7-13 foreign link review audit scoping extraction[\s\S]*call-scoped tenant precedence[\s\S]*No push, deploy, Background, Gossip, SFU, MediaSecurity, or BTGF/,
  'readiness log must record IAM7-13 proof and scope boundaries',
);

process.stdout.write('[call-access-foreign-link-review-audit-contract] PASS\n');
