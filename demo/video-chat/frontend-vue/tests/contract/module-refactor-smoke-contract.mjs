import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { spawnSync } from 'node:child_process';
import path from 'node:path';
import { workspaceModuleRouteRecords } from '../../src/modules/index.js';

const root = path.resolve(new URL('../..', import.meta.url).pathname);
const repoRoot = path.resolve(root, '../../..');
const routerSource = await readFile(path.join(root, 'src/http/router.js'), 'utf8');

const expectedModuleRoutes = new Map([
  ['/admin/overview', 'admin-overview'],
  ['/admin/administration/marketplace', 'admin-administration-marketplace'],
  ['/admin/administration/localization', 'admin-administration-localization'],
  ['/admin/administration/app-configuration', 'admin-administration-app-configuration'],
  ['/admin/administration/theme-editor', 'admin-administration-theme-editor'],
  ['/admin/governance/users', 'admin-governance-users'],
  ['/admin/governance/groups', 'admin-governance-groups'],
  ['/admin/governance/organizations', 'admin-governance-organizations'],
  ['/admin/governance/modules', 'admin-governance-modules'],
  ['/admin/governance/permissions', 'admin-governance-permissions'],
  ['/admin/governance/roles', 'admin-governance-roles'],
  ['/admin/governance/grants', 'admin-governance-grants'],
  ['/admin/governance/policies', 'admin-governance-policies'],
  ['/admin/governance/audit-log', 'admin-governance-audit-log'],
  ['/admin/governance/data-portability', 'admin-governance-data-portability'],
  ['/admin/governance/compliance', 'admin-governance-compliance'],
]);

const generatedByPath = new Map(workspaceModuleRouteRecords.map((record) => [`/${record.path}`, record]));
for (const [pathName, routeName] of expectedModuleRoutes) {
  assert.equal(generatedByPath.get(pathName)?.name, routeName, `${pathName} must keep its route name`);
}

assert.match(routerSource, /path:\s*['"]admin\/users['"][\s\S]*redirect:\s*['"]\/admin\/governance\/users['"]/, 'legacy /admin/users redirect missing');
assert.match(routerSource, /path:\s*['"]admin\/marketplace['"][\s\S]*redirect:\s*['"]\/admin\/administration\/marketplace['"]/, 'legacy /admin/marketplace redirect missing');
assert.match(routerSource, /path:\s*['"]admin\/tenancy['"][\s\S]*redirect:\s*['"]\/admin\/governance\/organizations['"]/, 'legacy /admin/tenancy redirect missing');

const moduleSprintBase = process.env.MODULE_REFACTOR_BASE || findCommitBySubject('docs: plan descriptor module sprint');
assert.ok(moduleSprintBase, 'module refactor base commit could not be resolved');

const excludedPatterns = [
  /^demo\/video-chat\/frontend-vue\/src\/domain\/calls\//,
  /^demo\/video-chat\/frontend-vue\/src\/domain\/realtime\//,
  /^demo\/video-chat\/frontend-vue\/src\/lib\/sfu\//,
  /^demo\/video-chat\/frontend-vue\/src\/lib\/wasm\//,
  /^demo\/video-chat\/frontend-vue\/src\/lib\/wavelet\//,
];
const changedFiles = git(['diff', '--name-only', `${moduleSprintBase}..HEAD`])
  .split('\n')
  .map((line) => line.trim())
  .filter(Boolean);
const excludedChanges = changedFiles.filter((file) => excludedPatterns.some((pattern) => pattern.test(file)));
assert.deepEqual(excludedChanges, [], 'module refactor wave must not edit excluded call/realtime paths');

function findCommitBySubject(subject) {
  const output = git(['log', '--format=%H%x00%s', '--all']);
  for (const line of output.split('\n')) {
    const [hash, title] = line.split('\0');
    if (title === subject) return hash;
  }
  return '';
}

function git(args) {
  const result = spawnSync('git', args, {
    cwd: repoRoot,
    encoding: 'utf8',
  });
  if (result.status !== 0) {
    throw new Error((result.stderr || result.stdout || `git ${args.join(' ')} failed`).trim());
  }
  return result.stdout;
}

console.log('[module-refactor-smoke-contract] PASS');
