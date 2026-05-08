import { expect, test } from '@playwright/test';

const CALL_ID = 'call-whiteboard-install-proof';

function jsonResponse(payload, status = 200) {
  return {
    status,
    headers: {
      'cache-control': 'no-store',
      'content-type': 'application/json',
    },
    body: JSON.stringify(payload),
  };
}

function hostHtml() {
  return `<!doctype html>
    <html lang="en">
      <head>
        <meta charset="utf-8">
        <title>Whiteboard Install Sidebar Proof</title>
        <style>
          body {
            margin: 0;
            background: #000010;
            color: #fff;
            font-family: Inter, system-ui, sans-serif;
          }
          main {
            display: grid;
            grid-template-columns: minmax(0, 1fr) minmax(240px, 320px);
            min-height: 100vh;
          }
          .marketplace {
            padding: 20px;
            border-right: 1px solid #03275a;
          }
          .sidebar {
            min-width: 0;
            width: min(100%, 320px);
            overflow-x: hidden;
            container-type: inline-size;
            background: #00052d;
          }
          button {
            min-height: 34px;
            border: 1px solid #1582bf;
            background: #03275a;
            color: #fff;
            font-weight: 800;
          }
          .call-apps-list-item,
          .call-apps-access-row {
            min-width: 0;
            display: grid;
            grid-template-columns: minmax(0, 1fr);
            gap: 8px;
            width: 100%;
            padding: 12px;
            border: 0;
            border-bottom: 1px solid #03275a;
            background: #000010;
            text-align: left;
            box-sizing: border-box;
          }
          .call-apps-item-main,
          .call-apps-access-main,
          .call-apps-detail,
          .call-apps-access {
            min-width: 0;
            display: grid;
            gap: 8px;
          }
          .call-apps-detail,
          .call-apps-access {
            padding: 12px;
            border-bottom: 1px solid #03275a;
          }
          .call-apps-item-side,
          .call-apps-item-badges {
            min-width: 0;
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
          }
          .badge,
          .call-apps-access-state,
          .call-apps-access-default {
            width: fit-content;
            padding: 3px 8px;
            border: 1px solid #03275a;
            background: #00052d;
            font-size: 11px;
            font-weight: 900;
            text-transform: uppercase;
          }
          .state-allowed {
            color: #00d084;
          }
          .state-denied {
            color: #f5b84b;
          }
          .call-apps-policy {
            min-width: 0;
            margin: 0;
            padding: 0;
            border: 0;
            display: grid;
            gap: 8px;
          }
          .call-apps-policy-options {
            min-width: 0;
            display: grid;
            grid-template-columns: minmax(0, 1fr);
            gap: 8px;
          }
          .call-apps-policy-choice {
            display: grid;
            grid-template-columns: 18px minmax(0, 1fr);
            gap: 4px 8px;
            padding: 8px;
            border: 1px solid #03275a;
            background: #000010;
          }
          .call-apps-policy-choice input {
            grid-row: 1 / span 2;
          }
          .call-apps-grant-action {
            width: 100%;
          }
          @container (min-width: 380px) {
            .call-apps-list-item,
            .call-apps-access-row {
              grid-template-columns: minmax(0, 1fr) auto;
            }
            .call-apps-policy-options {
              grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
            }
            .call-apps-grant-action {
              width: auto;
              min-width: 86px;
            }
          }
          @media (max-width: 620px) {
            main {
              grid-template-columns: minmax(0, 1fr);
            }
            .marketplace {
              border-right: 0;
              border-bottom: 1px solid #03275a;
            }
            .sidebar {
              width: 100%;
            }
          }
        </style>
      </head>
      <body>
        <main>
          <section class="marketplace" aria-label="Marketplace">
            <h1>Marketplace</h1>
            <p id="marketplaceState">Whiteboard is not installed.</p>
            <button id="installWhiteboard" type="button">Install for organization</button>
          </section>
          <section class="sidebar" aria-label="Call Apps">
            <button id="openCallApps" type="button">Call Apps</button>
            <div id="callApps"></div>
          </section>
        </main>
        <script>
          (() => {
            const callId = ${JSON.stringify(CALL_ID)};
            const state = {
              installed: false,
              activeSession: null,
              participants: [
                { userId: 1, displayName: 'Owner' },
                { userId: 2, displayName: 'Participant' },
              ],
              grants: new Map(),
              requests: [],
            };
            window.whiteboardInstallSidebarProof = {
              get state() {
                return {
                  installed: state.installed,
                  activeSession: state.activeSession,
                  grants: Object.fromEntries(state.grants),
                  requests: state.requests.slice(),
                };
              },
            };

            function remember(label, payload) {
              state.requests.push({ label, payload });
            }

            async function api(path, options = {}) {
              const method = String(options.method || 'GET').toUpperCase();
              const body = options.body ? JSON.parse(options.body) : null;
              remember(method + ' ' + path, body);
              const response = await fetch(path, {
                method,
                headers: { 'content-type': 'application/json' },
                body: body ? JSON.stringify(body) : undefined,
              });
              if (!response.ok) throw new Error('Request failed: ' + method + ' ' + path);
              return response.json();
            }

            function grantStateFor(userId) {
              return state.grants.get(String(userId)) || 'allowed';
            }

            function renderAvailability(apps) {
              const root = document.getElementById('callApps');
              if (!apps.length) {
                root.innerHTML = '<p>No installed Call Apps available.</p>';
                return;
              }
              root.innerHTML = \`
                <button class="call-apps-list-item" id="selectWhiteboard" type="button">
                  <span class="call-apps-item-main">
                    <strong>Whiteboard</strong>
                    <span>whiteboard - 0.1.0</span>
                    <span class="call-apps-item-badges" aria-label="Call App availability">
                      <span class="badge">Installed</span>
                      <span class="badge">Enabled</span>
                      <span class="badge">Healthy</span>
                    </span>
                  </span>
                  <span class="call-apps-item-side">
                    <span>whiteboard</span>
                    <span class="badge">Select</span>
                  </span>
                </button>
                <section class="call-apps-detail" aria-label="Selected Call App" data-call-app-attach-flow="inline">
                  <h2>Whiteboard</h2>
                  <fieldset class="call-apps-policy">
                    <legend>Default participant access</legend>
                    <div class="call-apps-policy-options">
                      <label class="call-apps-policy-choice">
                        <input name="defaultAccess" type="radio" value="blocked_by_default">
                        <span>Blocked</span>
                        <small>Grant individually</small>
                      </label>
                      <label class="call-apps-policy-choice">
                        <input name="defaultAccess" type="radio" value="allowed_by_default" checked>
                        <span>Allowed</span>
                        <small>Participants can open</small>
                      </label>
                    </div>
                  </fieldset>
                  <button id="addWhiteboard" type="button">Add to call</button>
                </section>
              \`;
              document.getElementById('addWhiteboard').addEventListener('click', addWhiteboard);
            }

            function renderAccess() {
              const root = document.getElementById('callApps');
              const rows = state.participants.map((participant) => {
                const grant = grantStateFor(participant.userId);
                const action = grant === 'allowed' ? 'Revoke' : 'Allow';
                return \`
                  <div class="call-apps-access-row" data-user-id="\${participant.userId}">
                    <span class="call-apps-access-main">
                      <strong>\${participant.displayName}</strong>
                      <span class="call-apps-access-state state-\${grant}">\${grant === 'allowed' ? 'Allowed' : 'Blocked'}</span>
                    </span>
                    <button class="call-apps-grant-action" type="button" data-user-id="\${participant.userId}">\${action}</button>
                  </div>
                \`;
              }).join('');
              root.insertAdjacentHTML('beforeend', \`
                <section class="call-apps-access" aria-label="Call App participant access">
                  <h2>Access</h2>
                  <span class="call-apps-access-default">Default: allowed</span>
                  <div class="call-apps-access-list">\${rows}</div>
                </section>
              \`);
              root.querySelectorAll('.call-apps-grant-action').forEach((button) => {
                button.addEventListener('click', () => toggleGrant(Number(button.dataset.userId || 0)));
              });
            }

            async function loadAvailability() {
              const payload = await api('/api/calls/' + encodeURIComponent(callId) + '/call-apps/available?query=whiteboard&page=1&page_size=8');
              renderAvailability(payload.result.apps);
            }

            async function addWhiteboard() {
              const selected = document.querySelector('input[name="defaultAccess"]:checked')?.value || 'blocked_by_default';
              const payload = await api('/api/calls/' + encodeURIComponent(callId) + '/call-app-sessions', {
                method: 'POST',
                body: JSON.stringify({ app_key: 'whiteboard', default_app_policy: selected }),
              });
              state.activeSession = payload.result;
              for (const grant of payload.result.grants) {
                state.grants.set(String(grant.user_id), grant.grant_state);
              }
              renderAccess();
            }

            async function toggleGrant(userId) {
              const next = grantStateFor(userId) === 'allowed' ? 'denied' : 'allowed';
              await api('/api/call-app-sessions/session-whiteboard-install-proof/participant-grants', {
                method: 'PATCH',
                body: JSON.stringify({
                  grants: [{ subject_type: 'user', user_id: userId, grant_state: next }],
                }),
              });
              state.grants.set(String(userId), next);
              document.querySelector('.call-apps-access')?.remove();
              renderAccess();
            }

            async function installWhiteboard() {
              await api('/api/marketplace/call-apps/whiteboard/orders', { method: 'POST', body: JSON.stringify({}) });
              await api('/api/marketplace/call-apps/whiteboard/installations', { method: 'POST', body: JSON.stringify({}) });
              state.installed = true;
              document.getElementById('marketplaceState').textContent = 'Whiteboard installed and enabled for this organization.';
            }

            document.getElementById('installWhiteboard').addEventListener('click', installWhiteboard);
            document.getElementById('openCallApps').addEventListener('click', loadAvailability);
          })();
        </script>
      </body>
    </html>`;
}

async function installRoutes(page) {
  const server = {
    installed: false,
    session: null,
    requests: [],
  };
  await page.route('**/__whiteboard_install_sidebar/host.html', async (route) => {
    await route.fulfill({
      status: 200,
      headers: { 'content-type': 'text/html; charset=utf-8' },
      body: hostHtml(),
    });
  });
  await page.route('**/api/**', async (route) => {
    const request = route.request();
    const url = new URL(request.url());
    const method = request.method();
    const body = request.postDataJSON?.() || null;
    server.requests.push({ method, pathname: url.pathname, body });

    if (method === 'POST' && url.pathname === '/api/marketplace/call-apps/whiteboard/orders') {
      await route.fulfill(jsonResponse({ status: 'success', result: { order_id: 'order-whiteboard-install-proof' } }, 201));
      return;
    }
    if (method === 'POST' && url.pathname === '/api/marketplace/call-apps/whiteboard/installations') {
      server.installed = true;
      await route.fulfill(jsonResponse({ status: 'success', result: { installation_id: 'install-whiteboard-proof', status: 'enabled' } }, 201));
      return;
    }
    if (method === 'GET' && url.pathname === `/api/calls/${CALL_ID}/call-apps/available`) {
      await route.fulfill(jsonResponse({
        status: 'success',
        result: {
          apps: server.installed ? [{
            app_key: 'whiteboard',
            name: 'Whiteboard',
            category: 'whiteboard',
            version: '0.1.0',
            availability: { installed: true, enabled: true, healthy: true },
            installation: { status: 'enabled', default_app_policy: 'blocked_by_default' },
          }] : [],
          pagination: { page: 1, page_count: 1, has_prev: false, has_next: false, total: server.installed ? 1 : 0 },
        },
      }));
      return;
    }
    if (method === 'POST' && url.pathname === `/api/calls/${CALL_ID}/call-app-sessions`) {
      server.session = {
        id: 'session-whiteboard-install-proof',
        call_id: CALL_ID,
        app_key: 'whiteboard',
        status: 'active',
        default_app_policy: body.default_app_policy,
        app: { name: 'Whiteboard', category: 'whiteboard' },
        grants: [
          { subject_type: 'user', user_id: 1, grant_state: 'allowed' },
          { subject_type: 'user', user_id: 2, grant_state: 'allowed' },
        ],
      };
      await route.fulfill(jsonResponse({ status: 'success', result: server.session }, 201));
      return;
    }
    if (method === 'PATCH' && url.pathname === '/api/call-app-sessions/session-whiteboard-install-proof/participant-grants') {
      const grant = body.grants?.[0] || {};
      server.session.grants = server.session.grants.map((row) => (
        Number(row.user_id) === Number(grant.user_id) ? { ...row, grant_state: grant.grant_state } : row
      ));
      await route.fulfill(jsonResponse({
        status: 'success',
        result: {
          session: server.session,
          changed_grants: [{ ...grant, retired_launch_tokens: grant.grant_state === 'denied' ? 1 : 0 }],
        },
      }));
      return;
    }

    await route.fulfill(jsonResponse({ status: 'error', error: { code: 'unexpected_request' } }, 404));
  });
  return server;
}

test('Whiteboard install appears in Call Apps sidebar with usable access controls', async ({ page }) => {
  const server = await installRoutes(page);
  const baseURL = test.info().project.use.baseURL || 'http://127.0.0.1:4174';

  await page.setViewportSize({ width: 360, height: 760 });
  await page.goto(`${baseURL.replace(/\/+$/, '')}/__whiteboard_install_sidebar/host.html`, { waitUntil: 'domcontentloaded' });

  await page.getByRole('button', { name: 'Install for organization' }).click();
  await expect(page.getByText('Whiteboard installed and enabled for this organization.')).toBeVisible();

  await page.getByRole('button', { name: 'Call Apps' }).click();
  await expect(page.getByRole('button', { name: /Whiteboard/ })).toBeVisible();
  await expect(page.locator('.badge').getByText('Installed', { exact: true })).toBeVisible();
  await expect(page.locator('.badge').getByText('Enabled', { exact: true })).toBeVisible();
  await expect(page.locator('.badge').getByText('Healthy', { exact: true })).toBeVisible();
  await expect(page.getByText('Select', { exact: true })).toBeVisible();

  const sidebarNoOverflow = await page.locator('.sidebar').evaluate((element) => element.scrollWidth <= element.clientWidth);
  expect(sidebarNoOverflow).toBe(true);
  const narrowColumns = await page.locator('.call-apps-list-item').evaluate((element) => getComputedStyle(element).gridTemplateColumns);
  expect(narrowColumns.trim().split(/\s+/)).toHaveLength(1);

  await page.getByRole('radio', { name: 'Allowed Participants can open' }).check();
  await page.getByRole('button', { name: 'Add to call' }).click();
  await expect(page.getByRole('heading', { name: 'Access' })).toBeVisible();
  await expect(page.getByText('Default: allowed')).toBeVisible();
  await expect(page.locator('.call-apps-access-row[data-user-id="1"]')).toContainText('Owner');
  await expect(page.locator('.call-apps-access-row[data-user-id="1"]')).toContainText('Allowed');
  await expect(page.locator('.call-apps-access-row[data-user-id="2"]')).toContainText('Participant');
  await expect(page.locator('.call-apps-access-row[data-user-id="2"]')).toContainText('Revoke');

  await page.locator('.call-apps-access-row[data-user-id="2"] .call-apps-grant-action').click();
  await expect(page.locator('.call-apps-access-row[data-user-id="2"]')).toContainText('Blocked');
  await expect(page.locator('.call-apps-access-row[data-user-id="2"]')).toContainText('Allow');

  expect(server.requests.map((entry) => `${entry.method} ${entry.pathname}`)).toEqual(expect.arrayContaining([
    'POST /api/marketplace/call-apps/whiteboard/orders',
    'POST /api/marketplace/call-apps/whiteboard/installations',
    `GET /api/calls/${CALL_ID}/call-apps/available`,
    `POST /api/calls/${CALL_ID}/call-app-sessions`,
    'PATCH /api/call-app-sessions/session-whiteboard-install-proof/participant-grants',
  ]));
  expect(server.requests.find((entry) => entry.pathname === `/api/calls/${CALL_ID}/call-app-sessions`)?.body)
    .toMatchObject({ app_key: 'whiteboard', default_app_policy: 'allowed_by_default' });
  expect(server.requests.find((entry) => entry.pathname === '/api/call-app-sessions/session-whiteboard-install-proof/participant-grants')?.body)
    .toMatchObject({ grants: [{ subject_type: 'user', user_id: 2, grant_state: 'denied' }] });
});
