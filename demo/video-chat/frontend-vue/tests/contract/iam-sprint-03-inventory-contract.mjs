import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const frontendRoot = path.resolve(__dirname, '../..');
const repoRoot = path.resolve(frontendRoot, '../../..');
const baseBranch = 'prod-kingrt-do-not-push-to-github';

function git(args, options = {}) {
  return execFileSync('git', args, {
    cwd: options.cwd || repoRoot,
    encoding: 'utf8',
    stdio: ['ignore', 'pipe', 'pipe'],
  }).trim();
}

function gitStatus(worktree) {
  return git(['-C', worktree, 'status', '--porcelain']);
}

function isAncestor(worktree, ref, ancestorOf) {
  try {
    execFileSync('git', ['-C', worktree, 'merge-base', '--is-ancestor', ref, ancestorOf], {
      encoding: 'utf8',
      stdio: ['ignore', 'pipe', 'pipe'],
    });
    return true;
  } catch {
    return false;
  }
}

function branchHead(worktree) {
  return git(['-C', worktree, 'rev-parse', '--short', 'HEAD']);
}

function branchName(worktree) {
  return git(['-C', worktree, 'rev-parse', '--abbrev-ref', 'HEAD']);
}

function assertWorktree(candidate) {
  assert.ok(fs.existsSync(path.join(candidate.worktree, '.git')), `${candidate.branch} worktree must exist`);
  assert.equal(branchName(candidate.worktree), candidate.branch, `${candidate.branch} must be checked out`);
  assert.equal(branchHead(candidate.worktree), candidate.head, `${candidate.branch} HEAD changed since inventory`);
  assert.equal(gitStatus(candidate.worktree), '', `${candidate.branch} must stay clean for automated Sprint 03 intake`);
  assert.equal(
    isAncestor(candidate.worktree, 'HEAD', baseBranch),
    false,
    `${candidate.branch} must remain not-contained until a worker ports minimal current proof`,
  );
}

const sprint03Tickets = [
  'IAM3-02',
  'IAM3-03',
  'IAM3-04',
  'IAM3-05',
  'IAM3-06',
  'IAM3-07',
  'IAM3-08',
  'IAM3-09',
  'IAM3-10',
  'IAM3-11',
  'IAM3-12',
  'IAM3-13',
  'IAM3-14',
  'IAM3-15',
  'IAM3-16',
  'IAM3-17',
  'IAM3-18',
  'IAM3-19',
  'IAM3-20',
];

const cleanNotContainedCandidates = [
  {
    ticket: 'IAM3-02',
    branch: 'local/iam-e2e-security-manipulation-remaining',
    worktree: '/home/jochen/projects/king.site/worktrees/iam-e2e-security-manipulation-remaining',
    head: '787c2d6f',
    use: 'primary source for forged/tampered call-access, invite, and call-id manipulation safe-state coverage',
  },
  {
    ticket: 'IAM3-02',
    branch: 'local/iam-e2e-edge-error-matrix-proof',
    worktree: '/home/jochen/projects/king.site/worktrees/iam-e2e-edge-error-matrix-proof',
    head: 'b001f96e',
    use: 'secondary source for redacted API/browser edge-error matrix coverage',
  },
  {
    ticket: 'IAM3-03',
    branch: 'local/iam-e2e-parallel-account-tabs',
    worktree: '/home/jochen/projects/king.site/worktrees/iam-e2e-parallel-account-tabs',
    head: '2c3d2285',
    use: 'source for frontend storage/account-tab manipulation and call-scoped session isolation proof',
  },
  {
    ticket: 'IAM3-03',
    branch: 'local/iam-e2e-rejoin-refresh-session-safety',
    worktree: '/home/jochen/projects/king.site/worktrees/iam-e2e-rejoin-refresh-session-safety',
    head: 'fe6fd427',
    use: 'source for stale refreshed session binding rejection after manipulation',
  },
  {
    ticket: 'IAM3-04',
    branch: 'local/iam-e2e-duplicate-abuse-device-browser-proof-3',
    worktree: '/home/jochen/projects/king.site/worktrees/iam-e2e-duplicate-abuse-device-browser-proof-3',
    head: '2cd67944',
    use: 'primary source for deterministic duplicate device/browser personalized-link redemption',
  },
  {
    ticket: 'IAM3-04',
    branch: 'local/iam-e2e-review-abuse-cross-browser-proof-3',
    worktree: '/home/jochen/projects/king.site/worktrees/iam-e2e-review-abuse-cross-browser-proof-3',
    head: '0e02e605',
    use: 'source for cross-browser duplicate-review abuse redaction and warning policy',
  },
  {
    ticket: 'IAM3-05',
    branch: 'local/iam-e2e-abuse-logout-login-switch-proof-3',
    worktree: '/home/jochen/projects/king.site/worktrees/iam-e2e-abuse-logout-login-switch-proof-3',
    head: '29620bd4',
    use: 'primary source for two-browser logout/login switch session reuse prevention',
  },
  {
    ticket: 'IAM3-05',
    branch: 'local/iam-e2e-multi-session-device-safety',
    worktree: '/home/jochen/projects/king.site/worktrees/iam-e2e-multi-session-device-safety',
    head: 'db48bc2a',
    use: 'source for multi-session device isolation assertions',
  },
  {
    ticket: 'IAM3-06',
    branch: 'local/iam-e2e-strong-mismatch-host-verification-flow',
    worktree: '/home/jochen/projects/king.site/worktrees/iam-e2e-strong-mismatch-host-verification-flow',
    head: 'aa623b2e',
    use: 'primary source for wrong host/account mismatch verification with no foreign-data UI leaks',
  },
  {
    ticket: 'IAM3-06',
    branch: 'local/iam-e2e-foreign-personalized-mismatch',
    worktree: '/home/jochen/projects/king.site/worktrees/iam-e2e-foreign-personalized-mismatch',
    head: '17618082',
    use: 'source for foreign personalized invite mismatch privacy cases',
  },
  {
    ticket: 'IAM3-07',
    branch: 'local/iam-e2e-anonymous-temp-rights-proof-2',
    worktree: '/home/jochen/projects/king.site/worktrees/iam-e2e-anonymous-temp-rights-proof-2',
    head: 'f6748e36',
    use: 'primary source for anonymous guest rights and non-escalation checks',
  },
  {
    ticket: 'IAM3-07',
    branch: 'local/iam-e2e-anonymous-link-org-admin-rights',
    worktree: '/home/jochen/projects/king.site/worktrees/iam-e2e-anonymous-link-org-admin-rights',
    head: '03223058',
    use: 'source for logged-in anonymous-link org-admin escalation denial',
  },
  {
    ticket: 'IAM3-08',
    branch: 'local/iam-e2e-guest-lifecycle-temp-cleanup-remaining',
    worktree: '/home/jochen/projects/king.site/worktrees/iam-e2e-guest-lifecycle-temp-cleanup-remaining',
    head: '5703fb39',
    use: 'primary source for temporary call-link lifecycle cleanup and expiration scope',
  },
  {
    ticket: 'IAM3-08',
    branch: 'local/iam-e2e-guest-cleanup-remaining',
    worktree: '/home/jochen/projects/king.site/worktrees/iam-e2e-guest-cleanup-remaining',
    head: '10d8a706',
    use: 'source for temporary guest cleanup outside target call scope',
  },
  {
    ticket: 'IAM3-09',
    branch: 'local/iam-e2e-disabled-anonymous-links',
    worktree: '/home/jochen/projects/king.site/worktrees/iam-e2e-disabled-anonymous-links',
    head: '7c738190',
    use: 'primary source for disabled anonymous/call-access link fail-closed behavior',
  },
  {
    ticket: 'IAM3-09',
    branch: 'local/iam-e2e-link-invalidation-durability-proof',
    worktree: '/home/jochen/projects/king.site/worktrees/iam-e2e-link-invalidation-durability-proof',
    head: '9e5bf83f',
    use: 'source for invalidation durability before session or lobby insertion',
  },
  {
    ticket: 'IAM3-10',
    branch: 'local/iam-e2e-kick-active-call',
    worktree: '/home/jochen/projects/king.site/worktrees/iam-e2e-kick-active-call',
    head: '8ed7a409',
    use: 'primary source for kicked active-call participant rejoin denial',
  },
  {
    ticket: 'IAM3-10',
    branch: 'local/iam-e2e-temp-user-kick-rejoin',
    worktree: '/home/jochen/projects/king.site/worktrees/iam-e2e-temp-user-kick-rejoin',
    head: '453ee854',
    use: 'source for kicked temporary user stale-link/session rejoin denial',
  },
  {
    ticket: 'IAM3-11',
    branch: 'local/iam-e2e-permission-change-active-call',
    worktree: '/home/jochen/projects/king.site/worktrees/iam-e2e-permission-change-active-call',
    head: '79b60b17',
    use: 'primary source for active-call permission changes revoking stale UI actions',
  },
  {
    ticket: 'IAM3-11',
    branch: 'local/iam-e2e-reconnect-after-permission-change',
    worktree: '/home/jochen/projects/king.site/worktrees/iam-e2e-reconnect-after-permission-change',
    head: 'f94671e0',
    use: 'source for reconnect fail-closed behavior after permission downgrade',
  },
  {
    ticket: 'IAM3-12',
    branch: 'local/iam-e2e-audit-alias-followup-proof-3',
    worktree: '/home/jochen/projects/king.site/worktrees/iam-e2e-audit-alias-followup-proof-3',
    head: '9c80b101',
    use: 'primary source for canonical/redacted IAM audit aliases',
  },
  {
    ticket: 'IAM3-12',
    branch: 'local/iam-e2e-audit-event-compat-proof-3',
    worktree: '/home/jochen/projects/king.site/worktrees/iam-e2e-audit-event-compat-proof-3',
    head: 'daf6277d',
    use: 'source for legacy/current audit event compatibility',
  },
];

const runtimeProofSources = [
  {
    ticket: 'IAM3-13',
    source: 'local/iam-e2e-anonymous-temp-rights-proof-2',
    target: 'Docker PHP proof for anonymous temporary rights',
  },
  {
    ticket: 'IAM3-14',
    source: 'local/iam-e2e-temp-guest-list-direct-join and local/iam-e2e-invited-user-org-removal',
    target: 'Docker PHP proof for guest-list direct join and membership removal',
  },
  {
    ticket: 'IAM3-15',
    source: 'local/iam-e2e-cross-org-remaining-proof-2 and local/iam-e2e-membership-stale-invite-rights-proof-2',
    target: 'Docker PHP proof for cross-org and stale organization role checks',
  },
  {
    ticket: 'IAM3-16',
    source: 'current backend shell wrappers plus Docker fallbacks',
    target: 'single stable IAM backend runtime proof wrapper',
  },
  {
    ticket: 'IAM3-17',
    source: 'current package script/release-gate metadata after IAM3-02..16 land',
    target: 'wire Sprint 03 proof set without package-script edits in IAM3-01',
  },
  {
    ticket: 'IAM3-19',
    source: 'clean and contained Sprint 03 workers only',
    target: 'cleanup after contained-HEAD and clean-worktree verification',
  },
  {
    ticket: 'IAM3-20',
    source: 'integrated Sprint 03 branch after proof wiring',
    target: 'build, run proof set, deploy, and collect diagnostics',
  },
];

const dirtyManualCandidates = [
  {
    ticket: 'IAM3-18',
    branch: 'codex/iam-call-access-e2e-foundation',
    worktree: '/home/jochen/projects/king.site/worktrees/king-domain-registry',
    head: 'fdf66140',
    contained: true,
    classification: 'manual-contained-dirty',
    statusNeedles: [
      'demo/video-chat/contracts/v1/ui-parity-acceptance.matrix.json',
      'demo/video-chat/frontend-vue/package.json',
      'demo/video-chat/frontend-vue/tests/contract/iam-call-access-e2e-foundation-contract.mjs',
      'demo/video-chat/scripts/smoke.sh',
    ],
  },
  {
    ticket: 'IAM3-18',
    branch: 'codex/iam-duplicate-cleanup-reaudit-20260509',
    worktree: '/home/jochen/projects/king.site/worktrees/codex-iam-duplicate-cleanup-reaudit-20260509',
    head: '37b13ece',
    contained: false,
    classification: 'manual-not-contained-dirty-conflict',
    statusNeedles: [
      'UU demo/video-chat/frontend-vue/package.json',
      'demo/video-chat/frontend-vue/tests/contract/call-access-security-manipulation-contract.mjs',
      'demo/video-chat/frontend-vue/tests/contract/iam-call-access-contract-suite.mjs',
      'demo/video-chat/frontend-vue/tests/e2e/call-access-e2e-suite.mjs',
    ],
  },
];

const workerBranchPolicy = {
  prefix: 'agent/iam-s3-',
  cleanup_rule: 'delete only after merge, contained-HEAD proof, and clean worktree proof',
  persistence: 'ephemeral',
};

const sprint = fs.readFileSync(path.join(repoRoot, 'SPRINT.md'), 'utf8');
for (const ticket of sprint03Tickets) {
  assert.ok(sprint.includes(ticket), `SPRINT.md must list ${ticket}`);
}

for (const candidate of cleanNotContainedCandidates) {
  assertWorktree(candidate);
}

for (const candidate of dirtyManualCandidates) {
  assert.ok(fs.existsSync(path.join(candidate.worktree, '.git')), `${candidate.branch} worktree must exist`);
  assert.equal(branchName(candidate.worktree), candidate.branch, `${candidate.branch} must be checked out`);
  assert.equal(branchHead(candidate.worktree), candidate.head, `${candidate.branch} HEAD changed since inventory`);
  assert.notEqual(gitStatus(candidate.worktree), '', `${candidate.branch} must remain classified manual until dirty state is resolved`);
  assert.equal(
    isAncestor(candidate.worktree, 'HEAD', baseBranch),
    candidate.contained,
    `${candidate.branch} contained-HEAD classification changed`,
  );
  const status = gitStatus(candidate.worktree);
  for (const needle of candidate.statusNeedles) {
    assert.ok(status.includes(needle), `${candidate.branch} dirty status must mention ${needle}`);
  }
}

assert.equal(cleanNotContainedCandidates.length, 22, 'inventory should pin the clean not-contained IAM candidate set');
assert.equal(dirtyManualCandidates.length, 2, 'inventory should pin the dirty/manual IAM3-18 candidate set');
assert.equal(runtimeProofSources.length, 7, 'inventory should map IAM3-13..20 follow-up planning sources');
assert.deepEqual(
  workerBranchPolicy,
  {
    prefix: 'agent/iam-s3-',
    cleanup_rule: 'delete only after merge, contained-HEAD proof, and clean worktree proof',
    persistence: 'ephemeral',
  },
  'Sprint 03 worker branches must be treated as ephemeral, not stable inventory dependencies',
);
assert.ok(
  cleanNotContainedCandidates.every((candidate) => /^IAM3-(0[2-9]|1[0-2])$/.test(candidate.ticket)),
  'clean candidate intake must stay scoped to IAM3-02 through IAM3-12',
);
assert.ok(
  runtimeProofSources.every((candidate) => /^IAM3-1[3-79]$|^IAM3-20$/.test(candidate.ticket)),
  'runtime and cleanup planning must stay scoped to IAM3-13 through IAM3-20',
);

process.stdout.write('[iam-sprint-03-inventory-contract] PASS\n');
