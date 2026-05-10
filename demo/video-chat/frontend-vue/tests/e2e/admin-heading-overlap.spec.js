import { test, expect } from '@playwright/test';

const sessionStorageKey = 'ii_videocall_v1_session';

const adminSessionPayload = {
  status: 'ok',
  result: { state: 'authenticated' },
  session: {
    id: 'ux6-19-admin-session',
    token: 'ux6-19-admin-session',
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
      uuid: 'ux6-19-tenant',
      label: 'UX6-19 Tenant',
      role: 'owner',
      permissions: {
        platform_admin: true,
        tenant_admin: true,
        manage_users: true,
        manage_groups: true,
        manage_organizations: true,
        manage_permission_grants: true,
        export_import: true,
        edit_themes: true,
      },
    },
  },
  tenant: {
    id: 1,
    uuid: 'ux6-19-tenant',
    label: 'UX6-19 Tenant',
    role: 'owner',
    permissions: {
      platform_admin: true,
      tenant_admin: true,
      manage_users: true,
      manage_groups: true,
      manage_organizations: true,
      manage_permission_grants: true,
      export_import: true,
      edit_themes: true,
    },
  },
};

const migratedRoutes = [
  { path: '/admin/governance/users', title: 'Users', slug: 'governance-users' },
  { path: '/admin/governance/groups', title: 'Groups', slug: 'governance-groups' },
  { path: '/admin/governance/organizations', title: 'Organizations', slug: 'governance-organizations' },
  { path: '/admin/governance/modules', title: 'Modules', slug: 'governance-modules' },
  { path: '/admin/governance/roles', title: 'Roles', slug: 'governance-roles' },
  { path: '/admin/governance/grants', title: 'Grants', slug: 'governance-grants' },
  { path: '/admin/governance/policies', title: 'Policies', slug: 'governance-policies' },
  { path: '/admin/governance/audit-log', title: 'Audit Log', slug: 'governance-audit-log' },
  { path: '/admin/governance/data-portability', title: 'Export / Import', slug: 'governance-data-portability' },
  { path: '/admin/governance/compliance', title: 'Compliance', slug: 'governance-compliance' },
  { path: '/admin/administration/marketplace', title: 'Marketplace', slug: 'administration-marketplace' },
  { path: '/admin/administration/localization', title: 'Localization', slug: 'administration-localization' },
  { path: '/admin/administration/app-configuration', title: 'App Configuration', slug: 'administration-app-configuration' },
  { path: '/admin/administration/theme-editor', title: 'Theme Editor', slug: 'administration-theme-editor' },
];

const viewports = [
  { name: 'desktop', size: { width: 1366, height: 520 } },
  { name: 'tablet', size: { width: 900, height: 520 } },
  { name: 'mobile', size: { width: 390, height: 520 } },
];

function jsonResponse(body, status = 200) {
  return {
    status,
    contentType: 'application/json; charset=utf-8',
    body: JSON.stringify(body),
  };
}

function pagination(pageSize = 10, total = 0) {
  return {
    page: 1,
    page_size: pageSize,
    total,
    page_count: 1,
    has_prev: false,
    has_next: false,
  };
}

function governanceRows(entity, count) {
  return Array.from({ length: count }, (_, index) => ({
    id: `${entity}-${index + 1}`,
    key: `${entity}-${index + 1}`,
    name: `${entity} ${index + 1}`,
    status: 'active',
    description: `UX6-19 ${entity} row ${index + 1}`,
    scope_type: 'tenant',
    subject_type: 'user',
    resource: 'tenant',
    event: 'updated',
    created_at: '2026-05-10T00:00:00.000Z',
  }));
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

async function seedAuthenticatedAdmin(page) {
  await installQuietWebSocket(page);
  await page.addInitScript((key) => {
    window.localStorage.setItem(key, JSON.stringify({
      sessionId: 'ux6-19-admin-session',
      sessionToken: 'ux6-19-admin-session',
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
          themes: [
            { id: 'dark', label: 'Dark', colors: {}, is_system: true },
            { id: 'light', label: 'Light', colors: {}, is_system: true },
          ],
        },
      }));
      return;
    }

    if (url.pathname === '/api/admin/users' && request.method() === 'GET') {
      await route.fulfill(jsonResponse({
        status: 'ok',
        users: [],
        pagination: pagination(),
      }));
      return;
    }

    if (url.pathname === '/api/admin/marketplace/apps' && request.method() === 'GET') {
      await route.fulfill(jsonResponse({
        status: 'ok',
        apps: [],
        pagination: pagination(),
      }));
      return;
    }

    if (url.pathname === '/api/admin/localization/locales' && request.method() === 'GET') {
      await route.fulfill(jsonResponse({
        status: 'ok',
        locales: [
          { code: 'en', label: 'English', direction: 'ltr' },
          { code: 'de', label: 'Deutsch', direction: 'ltr' },
        ],
      }));
      return;
    }

    if (url.pathname === '/api/localization/resources' && request.method() === 'GET') {
      await route.fulfill(jsonResponse({
        status: 'ok',
        resources: {},
      }));
      return;
    }

    if (url.pathname === '/api/admin/workspace-administration' && request.method() === 'GET') {
      await route.fulfill(jsonResponse({
        status: 'ok',
        result: {
          settings: {},
          themes: [
            { id: 'dark', label: 'Dark', colors: {}, is_system: true },
            { id: 'light', label: 'Light', colors: {}, is_system: true },
          ],
        },
      }));
      return;
    }

    if (url.pathname === '/api/admin/workspace-administration/email-texts' && request.method() === 'GET') {
      await route.fulfill(jsonResponse({
        status: 'ok',
        result: {
          rows: [],
          pagination: pagination(),
        },
      }));
      return;
    }

    if (url.pathname === '/api/admin/workspace-administration/background-images' && request.method() === 'GET') {
      await route.fulfill(jsonResponse({
        status: 'ok',
        result: {
          rows: [],
          pagination: pagination(12),
        },
      }));
      return;
    }

    const governanceMatch = url.pathname.match(/^\/api\/governance\/([a-z-]+)$/);
    if (governanceMatch && request.method() === 'GET') {
      const entity = governanceMatch[1];
      const rows = entity === 'groups' ? governanceRows(entity, 24) : [];
      await route.fulfill(jsonResponse({
        status: 'ok',
        result: { rows },
        rows,
      }));
      return;
    }

    await route.fulfill(jsonResponse({ status: 'ok', result: { rows: [], pagination: pagination() } }));
  });
}

async function expectAdminHeadingLayout(page, routeInfo) {
  await expect(page).toHaveURL(new RegExp(`${routeInfo.path.replaceAll('/', '\\/')}$`));
  const heading = page.locator('.admin-page-frame-head h1').first();
  await expect(heading).toHaveText(routeInfo.title);
  await expect(heading).toBeVisible();

  const metrics = await page.evaluate(() => {
    const frame = document.querySelector('.admin-page-frame');
    const header = document.querySelector('.admin-page-frame-head');
    const heading = document.querySelector('.admin-page-frame-head h1');
    const actions = document.querySelector('.admin-page-frame-head .app-page-header-actions');
    const viewport = {
      width: window.innerWidth,
      height: window.innerHeight,
    };

    function rectFor(element) {
      const rect = element.getBoundingClientRect();
      return {
        top: rect.top,
        right: rect.right,
        bottom: rect.bottom,
        left: rect.left,
        width: rect.width,
        height: rect.height,
      };
    }

    function overlaps(a, b) {
      return a.left < b.right - 1
        && a.right > b.left + 1
        && a.top < b.bottom - 1
        && a.bottom > b.top + 1;
    }

    const children = Array.from(frame?.children || [])
      .filter((element) => {
        const style = window.getComputedStyle(element);
        const rect = element.getBoundingClientRect();
        return style.display !== 'none'
          && style.visibility !== 'hidden'
          && style.position !== 'fixed'
          && style.position !== 'absolute'
          && rect.width > 0
          && rect.height > 0;
      })
      .map((element, index) => ({ index, className: element.className, rect: rectFor(element) }));

    const childOverlaps = [];
    for (let left = 0; left < children.length; left += 1) {
      for (let right = left + 1; right < children.length; right += 1) {
        if (overlaps(children[left].rect, children[right].rect)) {
          childOverlaps.push(`${children[left].className} overlaps ${children[right].className}`);
        }
      }
    }

    const headingRect = heading ? rectFor(heading) : null;
    const actionsRect = actions ? rectFor(actions) : null;
    const frameRect = frame ? rectFor(frame) : null;
    const frameStyle = frame ? window.getComputedStyle(frame) : null;
    const headerStyle = header ? window.getComputedStyle(header) : null;
    const headingStyle = heading ? window.getComputedStyle(heading) : null;

    return {
      childOverlaps,
      documentScrollable: document.documentElement.scrollHeight > window.innerHeight + 1,
      horizontalOverflow: document.documentElement.scrollWidth > window.innerWidth + 1,
      frameOverflowY: frameStyle?.overflowY || '',
      frameWithinViewport: Boolean(frameRect)
        && frameRect.left >= -1
        && frameRect.top >= -1
        && frameRect.right <= viewport.width + 1
        && frameRect.bottom <= viewport.height + 1,
      headerFlexWrap: headerStyle?.flexWrap || '',
      headingActionOverlap: Boolean(headingRect && actionsRect && overlaps(headingRect, actionsRect)),
      headingFontSize: headingStyle?.fontSize || '',
      headingTagName: heading?.tagName || '',
      headingWithinViewport: Boolean(headingRect)
        && headingRect.left >= -1
        && headingRect.top >= -1
        && headingRect.right <= viewport.width + 1
        && headingRect.bottom <= viewport.height + 1,
    };
  });

  expect(metrics.headingTagName).toBe('H1');
  expect(metrics.headingFontSize).toBe('14px');
  expect(metrics.headerFlexWrap).toBe('wrap');
  expect(metrics.frameOverflowY).toBe('hidden');
  expect(metrics.headingWithinViewport).toBe(true);
  expect(metrics.frameWithinViewport).toBe(true);
  expect(metrics.headingActionOverlap).toBe(false);
  expect(metrics.horizontalOverflow).toBe(false);
  expect(metrics.documentScrollable).toBe(false);
  expect(metrics.childOverlaps).toEqual([]);
}

async function maybeAttachScreenshot(page, testInfo, label) {
  if (process.env.PLAYWRIGHT_UX6_19_SCREENSHOTS !== '1') return;
  await testInfo.attach(label, {
    body: await page.screenshot({ fullPage: false }),
    contentType: 'image/png',
  });
}

async function expectGovernanceTableScrollReachable(page) {
  await page.goto('/admin/governance/groups');
  await expect(page.getByRole('heading', { name: 'Groups' })).toBeVisible();
  await expect(page.locator('.governance-table tbody tr')).toHaveCount(8);
  await page.evaluate(() => {
    const tbody = document.querySelector('.governance-table tbody');
    const rows = Array.from(tbody?.children || []);
    for (let index = 0; index < 32; index += 1) {
      const clone = rows[index % rows.length]?.cloneNode(true);
      if (clone) tbody.appendChild(clone);
    }
  });

  const metrics = await page.evaluate(() => {
    const header = document.querySelector('.admin-page-frame-head');
    const footer = document.querySelector('.admin-page-frame-footer');
    const table = document.querySelector('.admin-table-frame');
    const before = {
      headerTop: header?.getBoundingClientRect().top ?? 0,
      footerBottom: footer?.getBoundingClientRect().bottom ?? 0,
    };
    if (table) table.scrollTop = table.scrollHeight;
    const after = {
      headerTop: header?.getBoundingClientRect().top ?? 0,
      footerBottom: footer?.getBoundingClientRect().bottom ?? 0,
    };
    const tableStyle = table ? window.getComputedStyle(table) : null;
    return {
      documentScrollable: document.documentElement.scrollHeight > window.innerHeight + 1,
      horizontalOverflow: document.documentElement.scrollWidth > window.innerWidth + 1,
      tableOverflowY: tableStyle?.overflowY || '',
      tableCanScroll: table ? table.scrollHeight > table.clientHeight + 1 : false,
      tableScrolled: table ? table.scrollTop > 0 : false,
      headerMoved: Math.abs(after.headerTop - before.headerTop) > 1,
      footerMoved: Math.abs(after.footerBottom - before.footerBottom) > 1,
    };
  });

  expect(metrics.documentScrollable).toBe(false);
  expect(metrics.horizontalOverflow).toBe(false);
  expect(metrics.tableOverflowY).toBe('auto');
  expect(metrics.tableCanScroll).toBe(true);
  expect(metrics.tableScrolled).toBe(true);
  expect(metrics.headerMoved).toBe(false);
  expect(metrics.footerMoved).toBe(false);
}

for (const viewport of viewports) {
  test(`migrated Admin and Governance headings stay standard and non-overlapping on ${viewport.name}`, async ({ page }, testInfo) => {
    await page.setViewportSize(viewport.size);
    await seedAuthenticatedAdmin(page);

    for (const routeInfo of migratedRoutes) {
      await page.goto(routeInfo.path);
      await expectAdminHeadingLayout(page, routeInfo);
      await maybeAttachScreenshot(page, testInfo, `${viewport.name}-${routeInfo.slug}`);
    }
  });

  test(`Governance table overflow remains reachable without moving heading chrome on ${viewport.name}`, async ({ page }) => {
    await page.setViewportSize(viewport.size);
    await seedAuthenticatedAdmin(page);
    await expectGovernanceTableScrollReachable(page);
  });
}
