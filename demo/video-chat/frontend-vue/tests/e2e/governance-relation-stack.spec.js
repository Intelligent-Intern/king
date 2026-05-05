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

function governanceGroupRows(count) {
  return Array.from({ length: count }, (_, index) => ({
    id: `group-${index + 1}`,
    key: `group-${index + 1}`,
    name: `Governance Group ${index + 1}`,
    status: 'active',
    description: `Governance group fixture ${index + 1}`,
  }));
}

async function seedAuthenticatedAdmin(page, requestLog, options = {}) {
  const groupRows = Array.isArray(options.groupRows) && options.groupRows.length > 0
    ? options.groupRows
    : [
      {
        id: 'existing-group',
        key: 'existing-group',
        name: 'Existing Group',
        status: 'active',
      },
    ];

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
          rows: groupRows,
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

async function expectReadonlyCatalogPage(page, path, title, rowText) {
  await page.goto(path);
  await expect(page).toHaveURL(new RegExp(`${path.replaceAll('/', '\\/')}$`));
  await expect(page.getByRole('heading', { name: title })).toBeVisible();
  await expect(page.getByRole('button', { name: /^Create/i })).toHaveCount(0);
  await expect(page.getByRole('button', { name: /^New/i })).toHaveCount(0);
  await expect(page.getByRole('button', { name: /Edit/i })).toHaveCount(0);
  await expect(page.getByRole('button', { name: /Delete/i })).toHaveCount(0);
  await expect(page.getByRole('columnheader', { name: 'Actions' })).toHaveCount(0);

  const search = page.locator('input[type="search"]').first();
  await expect(search).toBeVisible();
  await search.fill(rowText);
  await expect(page.locator('tbody tr').filter({ hasText: rowText }).first()).toBeVisible();
  await search.fill('__missing_catalog_row__');
  await expect(page.getByText('No entries match the current filter.')).toBeVisible();
}

async function expectAdminScrollOwnership(page) {
  await page.goto('/admin/governance/groups');
  await expect(page).toHaveURL(/\/admin\/governance\/groups$/);
  await expect(page.getByRole('heading', { name: 'Groups' })).toBeVisible();
  await expect(page.locator('.governance-table tbody tr')).toHaveCount(8);
  await page.evaluate(() => {
    const tbody = document.querySelector('.governance-table tbody');
    const rows = Array.from(tbody?.children || []);
    for (let index = 0; index < 24; index += 1) {
      const clone = rows[index % rows.length]?.cloneNode(true);
      if (clone) tbody.appendChild(clone);
    }
  });
  await expect(page.locator('.governance-table tbody tr')).toHaveCount(32);

  const metrics = await page.evaluate(() => {
    const sidebar = document.querySelector('.sidebar.sidebar-left');
    const workspace = document.querySelector('.workspace');
    const table = document.querySelector('.admin-table-frame');
    const header = document.querySelector('.admin-page-frame-head');
    const footer = document.querySelector('.admin-page-frame-footer');
    const before = {
      sidebarTop: sidebar?.getBoundingClientRect().top ?? 0,
      headerTop: header?.getBoundingClientRect().top ?? 0,
      footerBottom: footer?.getBoundingClientRect().bottom ?? 0,
    };
    if (table) {
      table.scrollTop = table.scrollHeight;
    }
    const after = {
      sidebarTop: sidebar?.getBoundingClientRect().top ?? 0,
      headerTop: header?.getBoundingClientRect().top ?? 0,
      footerBottom: footer?.getBoundingClientRect().bottom ?? 0,
    };
    const tableStyle = table ? window.getComputedStyle(table) : null;
    const workspaceStyle = workspace ? window.getComputedStyle(workspace) : null;
    return {
      documentScrollable: document.documentElement.scrollHeight > window.innerHeight + 1,
      hasHorizontalOverflow: document.documentElement.scrollWidth > window.innerWidth + 1,
      workspaceOverflowY: workspaceStyle?.overflowY || '',
      tableOverflowY: tableStyle?.overflowY || '',
      tableCanScroll: table ? table.scrollHeight > table.clientHeight + 1 : false,
      tableScrolled: table ? table.scrollTop > 0 : false,
      sidebarMoved: Math.abs(after.sidebarTop - before.sidebarTop) > 1,
      headerMoved: Math.abs(after.headerTop - before.headerTop) > 1,
      footerMoved: Math.abs(after.footerBottom - before.footerBottom) > 1,
    };
  });

  expect(metrics.documentScrollable).toBe(false);
  expect(metrics.hasHorizontalOverflow).toBe(false);
  expect(metrics.workspaceOverflowY).toBe('hidden');
  expect(metrics.tableOverflowY).toBe('auto');
  expect(metrics.tableCanScroll).toBe(true);
  expect(metrics.tableScrolled).toBe(true);
  expect(metrics.sidebarMoved).toBe(false);
  expect(metrics.headerMoved).toBe(false);
  expect(metrics.footerMoved).toBe(false);
}

for (const scenario of [
  { name: 'desktop', viewport: { width: 1366, height: 900 } },
  { name: 'tablet', viewport: { width: 900, height: 700 } },
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

  test(`readonly Governance catalogs expose rows without mutation controls on ${scenario.name}`, async ({ page }) => {
    const requestLog = [];
    await page.setViewportSize(scenario.viewport);
    await seedAuthenticatedAdmin(page, requestLog);

    await expectReadonlyCatalogPage(page, '/admin/governance/modules', 'Modules', 'governance');
    await expectReadonlyCatalogPage(page, '/admin/governance/permissions', 'Permissions', 'governance.read');
  });

  test(`admin Governance frame owns table scroll without moving shell chrome on ${scenario.name}`, async ({ page }) => {
    const requestLog = [];
    const viewport = {
      desktop: { width: 1366, height: 360 },
      tablet: { width: 900, height: 520 },
      mobile: { width: 390, height: 520 },
    }[scenario.name] || scenario.viewport;
    await page.setViewportSize(viewport);
    await seedAuthenticatedAdmin(page, requestLog, { groupRows: governanceGroupRows(24) });

    await expectAdminScrollOwnership(page);
  });
}
