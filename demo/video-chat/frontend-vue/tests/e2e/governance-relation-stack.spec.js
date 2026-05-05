import { test, expect } from '@playwright/test';

const sessionStorageKey = 'ii_videocall_v1_session';

const adminSessionPayload = {
  status: 'ok',
  result: { state: 'authenticated' },
  session: {
    id: 'governance-relation-e2e-session',
    token: 'governance-relation-e2e-session',
    expires_at: '2099-01-01T00:00:00Z',
  },
  user: {
    id: 1,
    email: 'admin@king.local',
    display_name: 'Admin',
    role: 'admin',
    status: 'active',
    time_format: '24h',
    date_format: 'dmy_dot',
    theme: 'dark',
    can_edit_themes: true,
    avatar_path: '',
    account_type: 'account',
    is_guest: false,
    tenant: {
      id: 1,
      uuid: 'governance-relation-tenant',
      label: 'Governance Relation Tenant',
      role: 'owner',
      permissions: {
        platform_admin: true,
        tenant_admin: true,
        manage_users: true,
        manage_groups: true,
        manage_permission_grants: true,
      },
    },
  },
  tenant: {
    id: 1,
    uuid: 'governance-relation-tenant',
    label: 'Governance Relation Tenant',
    role: 'owner',
    permissions: {
      platform_admin: true,
      tenant_admin: true,
      manage_users: true,
      manage_groups: true,
      manage_permission_grants: true,
    },
  },
};

function jsonResponse(body, status = 200) {
  return {
    status,
    contentType: 'application/json; charset=utf-8',
    body: JSON.stringify(body),
  };
}

async function installQuietWebSocket(page) {
  await page.addInitScript(() => {
    class QuietWebSocket {
      static CONNECTING = 0;
      static OPEN = 1;
      static CLOSING = 2;
      static CLOSED = 3;

      constructor(url) {
        this.url = url;
        this.readyState = QuietWebSocket.OPEN;
      }

      addEventListener() {}
      removeEventListener() {}
      send() {}
      close() {
        this.readyState = QuietWebSocket.CLOSED;
      }
    }

    window.WebSocket = QuietWebSocket;
  });
}

async function seedAuthenticatedAdmin(page, requestLog) {
  await installQuietWebSocket(page);
  await page.addInitScript((key) => {
    window.localStorage.setItem(key, JSON.stringify({
      sessionId: 'governance-relation-e2e-session',
      sessionToken: 'governance-relation-e2e-session',
      expiresAt: '2099-01-01T00:00:00Z',
    }));
  }, sessionStorageKey);

  await page.route('**/api/**', async (route) => {
    const request = route.request();
    const url = new URL(request.url());
    if (request.method() === 'OPTIONS') {
      await route.fulfill({ status: 204 });
      return;
    }

    if (url.pathname === '/api/auth/session-state' || url.pathname === '/api/auth/session') {
      await route.fulfill(jsonResponse(adminSessionPayload));
      return;
    }

    if (url.pathname === '/api/workspace/appearance') {
      await route.fulfill(jsonResponse({
        status: 'ok',
        result: {
          sidebar_logo_path: '/assets/orgas/kingrt/logo.svg',
          modal_logo_path: '/assets/orgas/kingrt/logo.svg',
          themes: [{ id: 'dark', label: 'Dark', colors: {}, is_system: true }],
        },
      }));
      return;
    }

    if (url.pathname === '/api/admin/users' && request.method() === 'GET') {
      await route.fulfill(jsonResponse({
        status: 'ok',
        users: [],
        pagination: {
          page: 1,
          page_size: 10,
          total: 0,
          page_count: 1,
          has_prev: false,
          has_next: false,
        },
      }));
      return;
    }

    if (url.pathname === '/api/governance/roles' && request.method() === 'GET') {
      await route.fulfill(jsonResponse({ status: 'ok', result: { rows: [] }, roles: [] }));
      return;
    }

    if (url.pathname === '/api/governance/groups' && request.method() === 'GET') {
      await route.fulfill(jsonResponse({
        status: 'ok',
        result: {
          rows: [
            {
              id: 'existing-group',
              key: 'existing-group',
              name: 'Existing Group',
              status: 'active',
            },
          ],
        },
        groups: [],
      }));
      return;
    }

    if (url.pathname === '/api/governance/groups' && request.method() === 'POST') {
      const body = JSON.parse(request.postData() || '{}');
      requestLog.push({ type: 'group-create', body });
      await route.fulfill(jsonResponse({
        status: 'ok',
        result: {
          state: 'created',
          row: {
            id: 'created-group-e2e',
            key: body.key || 'created-group-e2e',
            name: body.name || 'Created Relation Group',
            status: body.status || 'active',
          },
          included: {
            groups: [
              {
                id: 'created-group-e2e',
                key: body.key || 'created-group-e2e',
                name: body.name || 'Created Relation Group',
                status: body.status || 'active',
              },
            ],
          },
        },
      }, 201));
      return;
    }

    if (url.pathname === '/api/admin/users' && request.method() === 'POST') {
      const body = JSON.parse(request.postData() || '{}');
      requestLog.push({ type: 'user-create', body });
      await route.fulfill(jsonResponse({
        status: 'ok',
        result: { state: 'created', user_id: 44 },
      }, 201));
      return;
    }

    await route.fulfill(jsonResponse({ status: 'ok', result: {} }));
  });
}

async function expectModalInsideViewport(page, target) {
  const locator = typeof target === 'string' ? page.locator(target).filter({ visible: true }).first() : target;
  const box = await locator.boundingBox();
  expect(box).not.toBeNull();
  const viewport = page.viewportSize();
  expect(viewport).not.toBeNull();
  expect(box.width).toBeLessThanOrEqual(viewport.width);
  expect(box.height).toBeLessThanOrEqual(viewport.height);
  const hasDocumentOverflow = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 1);
  expect(hasDocumentOverflow).toBe(false);
}

async function fillRequiredUserFields(userDialog, label) {
  await userDialog.getByLabel('Email').fill(`${label}@example.test`);
  await userDialog.getByLabel('Display name').fill(`Relation ${label}`);
  await userDialog.getByLabel('Password', { exact: true }).fill('ChangeMe123!');
  await userDialog.getByLabel('Repeat password').fill('ChangeMe123!');
}

async function createUserThroughNestedGroupRelation(page, label) {
  await page.goto('/admin/governance/users');
  await expect(page).toHaveURL(/\/admin\/governance\/users$/);
  await page.getByRole('button', { name: 'New user' }).click();
  const userDialog = page.getByRole('dialog', { name: 'Create user' });
  await expect(userDialog).toBeVisible();
  await fillRequiredUserFields(userDialog, label);

  await userDialog.locator('.users-field').filter({ hasText: /^Groups/ }).locator('button.users-relation-link').click();
  const relationDialog = page.locator('.crud-relation-dialog').filter({ visible: true }).first();
  await expect(relationDialog).toBeVisible();
  await expectModalInsideViewport(page, relationDialog);
  await expect(relationDialog.getByRole('button', { name: /Permissions/ })).toBeVisible();
  await expect(relationDialog.getByRole('button', { name: /Modules/ })).toBeVisible();
  await expect(relationDialog.getByRole('button', { name: /Members/ })).toHaveCount(0);
  await expect(relationDialog.getByRole('button', { name: /Roles/ })).toHaveCount(0);

  await relationDialog.getByRole('button', { name: 'Create related' }).click();
  await relationDialog.locator('.crud-relation-field').filter({ hasText: 'Name' }).locator('input').fill(`Created ${label} Group`);
  await relationDialog.locator('.crud-relation-field').filter({ hasText: 'Key' }).locator('input').fill(`created-${label}-group`);
  await relationDialog.getByRole('button', { name: 'Save draft' }).click();
  await expect(relationDialog.getByText(`Created ${label} Group`)).toBeVisible();

  await relationDialog.getByRole('button', { name: /Permissions/ }).click();
  await relationDialog.getByPlaceholder('Search related records').fill('governance.read');
  const permissionRow = relationDialog.locator('tbody tr').filter({ hasText: 'governance.read' }).first();
  await expect(permissionRow).toBeVisible();
  await permissionRow.locator('input[type="checkbox"]').check();
  await relationDialog.getByRole('button', { name: 'Back' }).click();
  await relationDialog.getByRole('button', { name: 'Apply selection' }).click();

  await expect(userDialog.locator('button.users-relation-link').filter({ hasText: `Created ${label} Group` })).toBeVisible();
  await userDialog.getByRole('button', { name: 'Create user' }).click();
}

async function selectCatalogRelation(relationDialog, catalogLabel) {
  await relationDialog.getByPlaceholder('Search related records').fill(catalogLabel);
  await selectVisibleRelationRow(relationDialog, catalogLabel);
  await relationDialog.getByRole('button', { name: 'Apply selection' }).click();
}

async function selectVisibleRelationRow(relationDialog, catalogLabel) {
  const catalogRow = relationDialog.locator('tbody tr').filter({ hasText: catalogLabel }).first();
  await expect(catalogRow).toBeVisible();
  await catalogRow.locator('input[type="checkbox"]').check();
}

async function selectPagedPermissionRelations(relationDialog) {
  await selectVisibleRelationRow(relationDialog, 'governance.read');
  await relationDialog.getByRole('button', { name: 'Next' }).click();
  await expect(relationDialog.getByText(/Page 2 \/ 2/)).toBeVisible();
  await selectVisibleRelationRow(relationDialog, 'workspace_settings.read');
  await relationDialog.getByRole('button', { name: 'Apply selection' }).click();
}

async function createGovernanceGroupThroughNestedRelations(page, label) {
  await page.goto('/admin/governance/groups');
  await expect(page).toHaveURL(/\/admin\/governance\/groups$/);
  await page.getByRole('button', { name: 'Create group' }).click();
  const crudDialog = page.getByRole('dialog', { name: 'Create Group' });
  await expect(crudDialog).toBeVisible();
  await expectModalInsideViewport(page, crudDialog.locator('.governance-modal-dialog'));

  await crudDialog.locator('.governance-field').filter({ hasText: 'Name' }).locator('input').fill(`Governance ${label} Group`);
  await crudDialog.locator('.governance-field').filter({ hasText: 'Key' }).locator('input').fill(`governance-${label}-group`);

  await crudDialog.locator('button.governance-relation-link').filter({ hasText: 'Permissions' }).click();
  const relationDialog = page.locator('.crud-relation-dialog').filter({ visible: true }).first();
  await expect(relationDialog).toBeVisible();
  await expectModalInsideViewport(page, relationDialog);
  await selectPagedPermissionRelations(relationDialog);
  await expect(crudDialog.locator('button.governance-relation-link').filter({ hasText: 'Permissions' }).filter({ hasText: '2 selected' })).toBeVisible();

  await crudDialog.locator('button.governance-relation-link').filter({ hasText: 'Modules' }).click();
  await expect(relationDialog).toBeVisible();
  await expectModalInsideViewport(page, relationDialog);
  await relationDialog.getByRole('button', { name: 'Cancel' }).click();
  await expect(page.locator('.crud-relation-dialog').filter({ visible: true })).toHaveCount(0);
  await expect(crudDialog.locator('button.governance-relation-link').filter({ hasText: 'Modules' }).filter({ hasText: '1 selected' })).toHaveCount(0);

  await crudDialog.locator('button.governance-relation-link').filter({ hasText: 'Modules' }).click();
  await expect(relationDialog).toBeVisible();
  await expectModalInsideViewport(page, relationDialog);
  await selectCatalogRelation(relationDialog, 'governance');
  await expect(crudDialog.locator('button.governance-relation-link').filter({ hasText: 'Modules' }).filter({ hasText: '1 selected' })).toBeVisible();

  await crudDialog.getByRole('button', { name: 'Create group' }).click();
}

for (const scenario of [
  { name: 'desktop', viewport: { width: 1366, height: 900 } },
  { name: 'mobile', viewport: { width: 390, height: 844 } },
]) {
  test(`user management relation stack creates nested group permission on ${scenario.name}`, async ({ page }) => {
    const requestLog = [];
    await page.setViewportSize(scenario.viewport);
    await seedAuthenticatedAdmin(page, requestLog);

    await createUserThroughNestedGroupRelation(page, scenario.name);

    const groupCreate = requestLog.find((entry) => entry.type === 'group-create');
    expect(groupCreate?.body).toMatchObject({
      name: `Created ${scenario.name} Group`,
      key: `created-${scenario.name}-group`,
      status: 'active',
    });

    const userCreate = requestLog.find((entry) => entry.type === 'user-create');
    expect(userCreate?.body?.relationships?.groups?.[0]).toMatchObject({
      id: 'created-group-e2e',
      key: `created-${scenario.name}-group`,
      relationships: {
        permissions: [
          {
            entity_key: 'permissions',
            key: 'governance.read',
          },
        ],
      },
    });
  });

  test(`governance groups CRUD relation stack submits nested catalog payload on ${scenario.name}`, async ({ page }) => {
    const requestLog = [];
    await page.setViewportSize(scenario.viewport);
    await seedAuthenticatedAdmin(page, requestLog);

    await createGovernanceGroupThroughNestedRelations(page, scenario.name);

    const groupCreate = requestLog.find((entry) => (
      entry.type === 'group-create' && entry.body.name === `Governance ${scenario.name} Group`
    ));
    expect(groupCreate?.body).toMatchObject({
      name: `Governance ${scenario.name} Group`,
      key: `governance-${scenario.name}-group`,
      status: 'active',
      relationships: {
        modules: [
          {
            entity_key: 'modules',
            key: 'governance',
          },
        ],
      },
    });
    expect(groupCreate?.body?.relationships?.permissions).toHaveLength(2);
    expect(groupCreate?.body?.relationships?.permissions).toEqual(expect.arrayContaining([
      expect.objectContaining({
        entity_key: 'permissions',
        key: 'governance.read',
      }),
      expect.objectContaining({
        entity_key: 'permissions',
        key: 'workspace_settings.read',
      }),
    ]));
  });
}
