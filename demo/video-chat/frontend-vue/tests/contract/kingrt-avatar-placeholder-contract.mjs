import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const root = path.resolve(__dirname, '../..');
const publicPath = path.join(root, 'public/assets/orgas/kingrt/avatar-placeholder.svg');
const usersViewPath = path.join(root, 'src/domain/users/admin/UsersView.vue');

assert.ok(fs.existsSync(publicPath), 'KingRT avatar placeholder asset must exist at the public path used by user profile UI');

const asset = fs.readFileSync(publicPath, 'utf8');
assert.match(asset, /<svg\b/i, 'KingRT avatar placeholder must be an SVG asset');
assert.match(asset, /viewBox="0 0 160 160"/, 'KingRT avatar placeholder must keep a square viewBox for avatar slots');

const usersView = fs.readFileSync(usersViewPath, 'utf8');
assert.ok(
  usersView.includes('/assets/orgas/kingrt/avatar-placeholder.svg'),
  'User profile UI must keep using the deployed KingRT avatar placeholder path',
);

process.stdout.write('[kingrt-avatar-placeholder-contract] PASS\n');
