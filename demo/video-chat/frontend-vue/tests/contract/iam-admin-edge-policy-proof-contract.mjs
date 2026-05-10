import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const repoRoot = path.resolve(__dirname, '../../../../..');

function read(relativePath) {
  return fs.readFileSync(path.join(repoRoot, relativePath), 'utf8');
}

const analysis = read('analyse/IAM11-16-admin-edge-policy-proof.md');
const systemAdminContract = read('demo/video-chat/backend-king-php/tests/system-admin-call-rights-contract.php');
const orgAdminContract = read('demo/video-chat/backend-king-php/tests/org-admin-call-rights-contract.php');
const ownerTransferContract = read('demo/video-chat/backend-king-php/tests/call-owner-transfer-contract.php');
const crossOrgContract = read('demo/video-chat/backend-king-php/tests/call-access-cross-org-contract.php');
const callAccessDecision = read('demo/video-chat/backend-king-php/domain/calls/call_access_decision.php');
const callManagementQuery = read('demo/video-chat/backend-king-php/domain/calls/call_management_query.php');

assert.match(
  analysis,
  /local\/iam-e2e-org-admin-owner-transfer-policy[\s\S]*local\/iam-e2e-system-admin-edge-cases/,
  'IAM11-16 analysis must cite both local proof branches',
);
assert.match(
  analysis,
  /No Background, Gossip, SFU, MediaSecurity, or BTGF files touched/,
  'IAM11-16 analysis must preserve the requested ownership boundary',
);
assert.match(
  analysis,
  /must not be marked complete from docs alone/,
  'IAM11-16 analysis must not close org-admin owner-transfer until backend authority exists',
);

assert.match(
  systemAdminContract,
  /system admin should not need foreign tenant membership[\s\S]*system admin should not need guest-list participant row/,
  'system-admin proof must not depend on foreign tenant membership or guest-list rows',
);
assert.match(
  systemAdminContract,
  /system admin should transfer owner on foreign-tenant call[\s\S]*system admin rights should remain after owner transfer/,
  'system-admin proof must preserve owner-transfer authority across transfer',
);
assert.match(
  systemAdminContract,
  /regular user must not simulate system admin through role string[\s\S]*temporary account must not receive system-admin call rights/,
  'system-admin proof must reject forged role strings and temporary admin-shaped accounts',
);

assert.match(
  orgAdminContract,
  /org admin helper should allow own organization call[\s\S]*org admin helper should reject foreign organization call/,
  'org-admin proof must bind authority to the owning organization',
);
assert.match(
  orgAdminContract,
  /org admin should manage own organization call participants[\s\S]*org admin should not manage foreign organization call participants/,
  'org-admin proof must separate own-organization moderation from foreign calls',
);
assert.match(
  orgAdminContract,
  /org admin access should not require guest-list insertion/,
  'org-admin proof must not silently turn organization authority into guest-list membership',
);

assert.match(
  ownerTransferContract,
  /transfer should leave one owner participant row[\s\S]*one-owner invariant marker should be true/,
  'owner-transfer proof must preserve the exactly-one-owner invariant',
);
assert.match(
  analysis,
  /organization admins keep organization-admin moderation and[\s\S]*owner-transfer authority after ownership changes/,
  'IAM11-16 analysis must preserve the retained org-admin moderation and owner-transfer requirement',
);
assert.match(
  crossOrgContract,
  /active organization A context must not fetch organization B call[\s\S]*stale personalized organization B link alone must not grant organization A admin call access/,
  'cross-organization proof must deny foreign org-admin access',
);

assert.match(
  callAccessDecision,
  /\$canManageOwner = \$allowed && \(\$source === 'system_admin' \|\| \$normalizedEffectiveRole === 'owner'\)/,
  'call-access decision must keep owner management tied to real system admin or owner-equivalent effective role',
);
assert.match(
  callManagementQuery,
  /function videochat_user_has_system_admin_call_rights[\s\S]*password_hash/,
  'system-admin authority must be revalidated against the stored user record, not just caller input',
);
assert.match(
  callManagementQuery,
  /function videochat_user_is_organization_admin_for_call[\s\S]*owner_membership\.user_id = :owner_user_id/,
  'organization-admin authority must be derived from active organization membership for the call owner',
);

console.log('[iam-admin-edge-policy-proof-contract] PASS');
