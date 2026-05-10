import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const root = path.resolve(__dirname, '..', '..');

function read(relativePath) {
  return fs.readFileSync(path.join(root, relativePath), 'utf8');
}

const e2eSpec = read('tests/e2e/call-access-join.spec.js');
const joinView = read('src/domain/calls/access/JoinView.vue');
const mismatchFooter = read('src/domain/calls/access/CallAccessJoinFooter.vue');
const mismatchState = read('src/domain/calls/access/callAccessPersonalizedMismatch.ts');

assert.ok(
  joinView.split('\n').length <= 800,
  'IAM11-08 must keep public JoinView.vue at or below the 800-line frontend ceiling',
);
assert.match(
  joinView,
  /<CallAccessJoinFooter[\s\S]*@request-confirmation="requestPersonalizedMismatchUpdateConfirmation"[\s\S]*@verify-host="verifyPersonalizedMismatchHost"/,
  'IAM11-08 must keep correct-host mismatch UI extracted from JoinView.vue',
);
assert.match(
  `${mismatchFooter}\n${mismatchState}`,
  /public\.join\.verify_host[\s\S]*public\.join\.continue_without_update[\s\S]*public\.join\.send_confirmation_email[\s\S]*strong_personalized_link/,
  'IAM11-08 extracted mismatch helpers must carry verify, decline, confirmation, and strong-mismatch handling',
);

assert.match(
  e2eSpec,
  /strong personalized-link mismatch wrong host denial gives no access and leaks no foreign person data/,
  'public join E2E must cover strong personalized-link mismatch wrong-host denial',
);
assert.match(
  e2eSpec,
  /strong personalized-link mismatch correct host supports decline and update-confirm-email without foreign data/,
  'public join E2E must cover correct-host decline and update-confirm-email branches',
);
assert.match(
  e2eSpec,
  /foreignNeedles\s*=\s*\[[\s\S]*linkInviteeName[\s\S]*linkInviteeEmail[\s\S]*realHostName[\s\S]*realHostEmail[\s\S]*deniedSessionToken[\s\S]*\]/,
  'strong-mismatch E2E must define link invitee, host, and denied-session leak sentinels',
);
assert.match(
  e2eSpec,
  /expectTextDoesNotContain\(joinBody,\s*foreignNeedles,\s*'strong-mismatch join response'\)/,
  'strong-mismatch E2E must prove the join response has no foreign person data',
);
assert.match(
  e2eSpec,
  /status:\s*403[\s\S]*code:\s*'call_access_forbidden'[\s\S]*mismatch:\s*'strong_personalized_link'[\s\S]*host_name:\s*'wrong_host_name'/,
  'strong-mismatch E2E must model a server-side wrong-host-name denial',
);
assert.match(
  e2eSpec,
  /expectTextDoesNotContain\(sessionBody,\s*foreignNeedles,\s*'strong-mismatch wrong-host denial response'\)/,
  'strong-mismatch E2E must prove the denial response has no foreign person data',
);
assert.match(
  e2eSpec,
  /sessionRequestAuthorization\)\.toBe\(`Bearer \$\{wrongLoggedInSession\.sessionToken\}`\)/,
  'strong-mismatch E2E must prove the current logged-in session is authoritative',
);
assert.match(
  e2eSpec,
  /sessionRequestBody\)\.toEqual\(\{\s*verified_user_id:\s*wrongLoggedInUserId,\s*verified_session_id:\s*wrongLoggedInSession\.sessionId,\s*\}\)/,
  'strong-mismatch E2E must prove verified logged-in context is sent to session issuance',
);
assert.match(
  e2eSpec,
  /not\.toContainText\(\/Call owner has been notified\|Waiting for host\/i\)[\s\S]*expect\(page\.url\(\)\)\.not\.toContain\('\/workspace\/call'\)/,
  'strong-mismatch E2E must prove wrong-host denial grants no direct call access',
);
assert.match(
  e2eSpec,
  /storedSession\.sessionId\)\.toBe\(wrongLoggedInSession\.sessionId\)[\s\S]*storedSession\.sessionToken\)\.toBe\(wrongLoggedInSession\.sessionToken\)[\s\S]*storedSession\.sessionToken\)\.not\.toBe\(deniedSessionToken\)/,
  'strong-mismatch E2E must prove denied responses do not bind a foreign session',
);
assert.match(
  e2eSpec,
  /expect\(joinGetCount\)\.toBe\(1\)[\s\S]*expect\(sessionPostCount\)\.toBe\(1\)/,
  'strong-mismatch E2E must guard against reload or duplicate request loops',
);
assert.match(
  e2eSpec,
  /getByLabel\('Host name'\)\.fill\(correctHostName\)[\s\S]*getByRole\('button', \{ name: 'Verify host' \}\)\.click\(\)/,
  'correct-host branch must require manual host-name entry and verification',
);
assert.match(
  e2eSpec,
  /getByRole\('button', \{ name: 'Continue without updating' \}\)\.click\(\)[\s\S]*mismatch_update_decision:\s*'decline'/,
  'correct-host branch must prove the user can decline account-data update',
);
assert.match(
  e2eSpec,
  /getByLabel\('First name'\)\.fill\('Manual'\)[\s\S]*getByLabel\('Last name'\)\.fill\('Correction'\)[\s\S]*getByRole\('button', \{ name: 'Send confirmation email' \}\)\.click\(\)[\s\S]*mismatch_update_decision:\s*'request_confirmation'/,
  'update branch must prove manual re-entry before confirmation email request',
);
assert.match(
  e2eSpec,
  /email_confirmation:\s*updateDecision === 'request_confirmation'[\s\S]*recipient_email:\s*currentUser\.email/,
  'update branch must send confirmation to the logged-in account email',
);
assert.match(
  e2eSpec,
  /storedSession\.sessionId\)\.toBe\(currentUser\.sessionId\)[\s\S]*storedSession\.sessionToken\)\.toBe\(currentUser\.sessionToken\)/,
  'correct-host branches must keep the logged-in account session active',
);
assert.match(
  e2eSpec,
  /JSON\.stringify\(sessionBodies\)\)\.not\.toContain\(linkInviteeName\)[\s\S]*JSON\.stringify\(sessionBodies\)\)\.not\.toContain\(linkInviteeEmail\)[\s\S]*JSON\.stringify\(sessionBodies\)\)\.not\.toContain\(temporaryForeignAccountId\)/,
  'correct-host branches must not submit foreign invitee or temporary account data',
);

console.log('[call-access-strong-mismatch-privacy-contract] PASS');
