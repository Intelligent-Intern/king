import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';

function fail(message) {
  throw new Error(`[refactor-commit-boundaries-contract] FAIL: ${message}`);
}

function git(args) {
  return execFileSync('git', args, {
    cwd: new URL('../../../..', import.meta.url),
    encoding: 'utf8',
    stdio: ['ignore', 'pipe', 'pipe'],
  });
}

const expectedCommits = [
  {
    subject: 'refactor(videochat): split call layout ui options',
    requiredPaths: [
      'demo/video-chat/frontend-vue/src/domain/realtime/callLayoutUiOptions.js',
      'demo/video-chat/frontend-vue/tests/contract/call-layout-ui-options-contract.mjs',
    ],
  },
  {
    subject: 'refactor(videochat): share call list ui primitives',
    requiredPaths: [
      'demo/video-chat/frontend-vue/src/domain/calls/CallsListTable.vue',
      'demo/video-chat/frontend-vue/tests/contract/shared-ui-primitives-contract.mjs',
    ],
  },
  {
    subject: 'refactor(videochat): split call view state stores',
    requiredPaths: [
      'demo/video-chat/frontend-vue/src/domain/calls/callViewState.js',
      'demo/video-chat/frontend-vue/tests/contract/frontend-state-stores-contract.mjs',
    ],
  },
  {
    subject: 'test(videochat): guard visual standards',
    requiredPaths: [
      'demo/video-chat/frontend-vue/tests/contract/visual-standards-contract.mjs',
    ],
  },
  {
    subject: 'test(videochat): cover shared ui surfaces',
    requiredPaths: [
      'demo/video-chat/frontend-vue/tests/e2e/shared-ui-surfaces.spec.js',
    ],
  },
];

function recentCommits() {
  return git(['log', '--format=%H%x00%s', '-n', '80'])
    .trim()
    .split('\n')
    .filter(Boolean)
    .map((line) => {
      const [hash, subject] = line.split('\0');
      return { hash, subject };
    });
}

function commitStats(hash) {
  const rows = git(['diff-tree', '--no-commit-id', '--numstat', '-r', hash])
    .trim()
    .split('\n')
    .filter(Boolean);

  return rows.map((row) => {
    const [added, deleted, filePath] = row.split('\t');
    return {
      added: Number.parseInt(added, 10) || 0,
      deleted: Number.parseInt(deleted, 10) || 0,
      filePath,
    };
  });
}

try {
  const commits = recentCommits();
  const positions = new Map();

  for (const expected of expectedCommits) {
    const index = commits.findIndex((commit) => commit.subject === expected.subject);
    assert.notEqual(index, -1, `missing expected Q21 commit: ${expected.subject}`);
    positions.set(expected.subject, index);

    const stats = commitStats(commits[index].hash);
    const paths = stats.map((row) => row.filePath);
    const changedLines = stats.reduce((total, row) => total + row.added + row.deleted, 0);

    assert.ok(paths.length <= 8, `${expected.subject} must stay a small reviewable file set`);
    assert.ok(changedLines <= 650, `${expected.subject} must stay within the Q21 bisection size cap`);
    assert.ok(
      paths.every((filePath) => filePath === 'SPRINT.md' || filePath.startsWith('demo/video-chat/frontend-vue/')),
      `${expected.subject} must not mix unrelated product areas into the videochat frontend refactor`,
    );

    for (const requiredPath of expected.requiredPaths) {
      assert.ok(paths.includes(requiredPath), `${expected.subject} must carry ${requiredPath}`);
    }
  }

  for (let i = 1; i < expectedCommits.length; i += 1) {
    const previous = expectedCommits[i - 1].subject;
    const current = expectedCommits[i].subject;
    assert.ok(
      positions.get(current) < positions.get(previous),
      `${current} must be newer than ${previous}`,
    );
  }

  process.stdout.write('[refactor-commit-boundaries-contract] PASS\n');
} catch (error) {
  if (error instanceof Error) {
    fail(error.message);
  }
  fail('unknown failure');
}
