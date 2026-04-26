import { test, expect } from '@playwright/test';

const backendOrigin = process.env.VITE_VIDEOCHAT_BACKEND_ORIGIN || 'http://127.0.0.1:18080';
const sessionStorageKey = 'ii_videocall_v1_session';

function corsHeaders() {
  return {
    'access-control-allow-origin': '*',
    'access-control-allow-credentials': 'true',
    'access-control-allow-headers': 'content-type, authorization, x-session-id',
    'access-control-allow-methods': 'GET, POST, PATCH, DELETE, OPTIONS',
    'access-control-max-age': '86400',
  };
}

async function withChatArchiveHelpers(page, callbackSource) {
  await page.goto('/src/domain/calls/chat/archive.js', { waitUntil: 'domcontentloaded' });
  return page.evaluate(async (source) => {
    const helpers = await import('/src/domain/calls/chat/archive.js');
    const callback = new Function('helpers', `return (${source})(helpers);`);
    return callback(helpers);
  }, callbackSource.toString());
}

async function installChatArchiveRoutes(page) {
  const call = {
    id: 'call-archive-ui',
    room_id: 'room-archive-ui',
    title: 'Archive UI Call',
    access_mode: 'invite_only',
    status: 'ended',
    starts_at: '2026-04-19T12:00:00.000Z',
    ends_at: '2026-04-19T13:00:00.000Z',
    owner: {
      user_id: 2,
      display_name: 'Call User',
      email: 'user@example.test',
    },
    participants: {
      total: 1,
      internal: 1,
      external: 0,
    },
    my_participation: {
      call_role: 'participant',
      invite_state: 'allowed',
    },
  };

  await page.route(`${backendOrigin}/api/auth/session-state`, async (route) => {
    await route.fulfill({
      status: 200,
      headers: { ...corsHeaders(), 'content-type': 'application/json; charset=utf-8' },
      json: {
        status: 'ok',
        result: { state: 'authenticated' },
        session: {
          id: 'sess_archive_ui',
          token: 'sess_archive_ui',
          expires_at: '2030-01-01T00:00:00.000Z',
        },
        user: {
          id: 2,
          email: 'user@example.test',
          display_name: 'Call User',
          role: 'user',
          status: 'active',
          time_format: '24h',
          date_format: 'dmy_dot',
          theme: 'dark',
          account_type: 'account',
          is_guest: false,
        },
      },
    });
  });

  await page.route(`${backendOrigin}/api/calls**`, async (route) => {
    const requestUrl = new URL(route.request().url());
    if (requestUrl.pathname === '/api/calls/call-archive-ui/chat-archive') {
      await route.fulfill({
        status: 200,
        headers: { ...corsHeaders(), 'content-type': 'application/json; charset=utf-8' },
        json: {
          status: 'ok',
          result: {
            state: 'loaded',
            archive: {
              call_id: 'call-archive-ui',
              room_id: 'room-archive-ui',
              read_only: true,
              messages: [
                {
                  id: 'chat-archive-1',
                  text: 'hello archive from user',
                  sender: { user_id: 2, display_name: 'Call User', role: 'user' },
                  server_time: '2026-04-19T12:00:10.000Z',
                  attachments: [
                    {
                      id: 'att-screen',
                      name: 'screen.png',
                      size_bytes: 2048,
                      kind: 'image',
                      download_url: '/api/calls/call-archive-ui/chat/attachments/att-screen',
                    },
                  ],
                },
              ],
              files: {
                groups: {
                  images: [
                    {
                      id: 'att-screen',
                      name: 'screen.png',
                      size_bytes: 2048,
                      kind: 'image',
                      sender: { user_id: 2, display_name: 'Call User' },
                      attached_at: '2026-04-19T12:00:10.000Z',
                      download_url: '/api/calls/call-archive-ui/chat/attachments/att-screen',
                    },
                  ],
                  pdfs: [],
                  office: [],
                  text: [],
                  documents: [],
                },
              },
              pagination: {
                cursor: 0,
                limit: 50,
                returned: 1,
                has_next: false,
                next_cursor: null,
              },
            },
          },
          time: '2026-04-19T12:00:11.000Z',
        },
      });
      return;
    }

    if (requestUrl.pathname === '/api/calls') {
      await route.fulfill({
        status: 200,
        headers: { ...corsHeaders(), 'content-type': 'application/json; charset=utf-8' },
        json: {
          status: 'ok',
          calls: [call],
          pagination: {
            page: 1,
            page_size: 10,
            total: 1,
            page_count: 1,
            returned: 1,
            has_prev: false,
            has_next: false,
          },
          time: '2026-04-19T12:00:00.000Z',
        },
      });
      return;
    }

    await route.fulfill({
      status: 404,
      headers: { ...corsHeaders(), 'content-type': 'application/json; charset=utf-8' },
      json: { status: 'error', error: { code: 'not_found', message: 'fixture route missing' } },
    });
  });
}

async function seedArchiveSession(page) {
  await page.addInitScript(
    ({ key }) => {
      localStorage.setItem(key, JSON.stringify({
        sessionId: 'sess_archive_ui',
        sessionToken: 'sess_archive_ui',
        expiresAt: '2030-01-01T00:00:00.000Z',
      }));
    },
    { key: sessionStorageKey },
  );
}

async function openArchiveModal(page) {
  await page.goto('/user/dashboard');
  await expect(page.getByText('Archive UI Call')).toBeVisible();
  await page.getByRole('button', { name: 'Open chat archive for Archive UI Call' }).click();
  await expect(page.getByTestId('chat-archive-modal')).toBeVisible();
}

test('user opens the call list chat archive modal as a read-only two-column archive', async ({ page }) => {
  await installChatArchiveRoutes(page);
  await seedArchiveSession(page);

  await openArchiveModal(page);

  await expect(page.getByRole('heading', { name: 'Chat archive' })).toBeVisible();
  await expect(page.getByLabel('Archived chat messages')).toContainText('hello archive from user');
  await expect(page.getByLabel('Archived chat messages')).toContainText('screen.png');
  await expect(page.getByLabel('Archived chat files')).toContainText('Images');
  await expect(page.getByLabel('Archived chat files')).toContainText('screen.png');
  await expect(page.getByText('Read-only archive. Messages and files cannot be changed here.')).toBeVisible();
  await expect(page.getByLabel('Chat archive filters').getByRole('button', { name: /^Search$/ })).toBeVisible();
  await expect(page.getByRole('button', { name: /^Send$/ })).toHaveCount(0);
  await expect(page.getByRole('textbox', { name: /message/i })).toHaveCount(0);
  await expect(page.getByRole('link', { name: 'Download screen.png' })).toHaveAttribute(
    'href',
    /\/api\/calls\/call-archive-ui\/chat\/attachments\/att-screen$/,
  );
});

test('chat archive modal stacks messages and files in the mobile modal layout', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 740 });
  await installChatArchiveRoutes(page);
  await seedArchiveSession(page);

  await openArchiveModal(page);

  const toolbarBox = await page.getByTestId('chat-archive-toolbar').boundingBox();
  const messageBox = await page.getByTestId('chat-archive-messages').boundingBox();
  const filesBox = await page.getByTestId('chat-archive-files').boundingBox();
  expect(toolbarBox?.width || 0).toBeLessThanOrEqual(382);
  expect(messageBox?.width || 0).toBeLessThanOrEqual(382);
  expect(filesBox?.width || 0).toBeLessThanOrEqual(382);
  expect(filesBox?.y || 0).toBeGreaterThan((messageBox?.y || 0) + 40);
  await expect(page.getByRole('link', { name: 'Download screen.png' })).toBeVisible();
});

test('chat archive payload is normalized as read-only modal data with message and file columns', async ({ page }) => {
  const result = await withChatArchiveHelpers(page, (helpers) => {
    const archive = helpers.normalizeChatArchivePayload({
      status: 'ok',
      result: {
        archive: {
          call_id: 'call-archive-ui',
          room_id: 'room-archive-ui',
          read_only: true,
          messages: [
            {
              id: 'chat-1',
              text: 'hello archive',
              sender: { user_id: 2, display_name: 'Call User' },
              attachments: [{ id: 'att-1', name: 'screen.png', download_url: '/download/screen' }],
            },
          ],
          files: {
            groups: {
              images: [{ id: 'att-1', name: 'screen.png', size_bytes: 2048 }],
              pdfs: [{ id: 'att-2', name: 'brief.pdf', size_bytes: 4096 }],
              office: [{ id: 'att-3', name: 'brief.docx', size_bytes: 8192 }],
              text: [{ id: 'att-4', name: 'notes.md', size_bytes: 128 }],
            },
          },
          pagination: {
            cursor: 0,
            limit: 50,
            returned: 1,
            has_next: false,
            next_cursor: null,
          },
        },
      },
    });

    return {
      callId: archive.callId,
      readOnly: helpers.chatArchiveIsReadOnly(archive),
      messageCount: archive.messages.length,
      firstMessageAttachmentCount: archive.messages[0].attachments.length,
      fileCount: helpers.chatArchiveFileCount(archive.files.groups),
      hasOfficeGroup: helpers.CHAT_ARCHIVE_FILE_GROUPS.some((group) => group.key === 'office'),
      bytesLabel: helpers.formatChatArchiveBytes(2048),
    };
  });

  expect(result.callId).toBe('call-archive-ui');
  expect(result.readOnly).toBe(true);
  expect(result.messageCount).toBe(1);
  expect(result.firstMessageAttachmentCount).toBe(1);
  expect(result.fileCount).toBe(4);
  expect(result.hasOfficeGroup).toBe(true);
  expect(result.bytesLabel).toBe('2.0 KB');
});

test('chat archive helper prevents missing groups from creating editable archive state', async ({ page }) => {
  const result = await withChatArchiveHelpers(page, (helpers) => {
    const archive = helpers.normalizeChatArchivePayload({
      archive: {
        read_only: true,
        messages: [],
        files: {
          groups: {
            images: [{ id: 'img-1' }],
          },
        },
      },
    });

    return {
      readOnly: helpers.chatArchiveIsReadOnly(archive),
      imageCount: archive.files.groups.images.length,
      pdfCount: archive.files.groups.pdfs.length,
      textCount: archive.files.groups.text.length,
      kindOptions: helpers.CHAT_ARCHIVE_FILE_KIND_OPTIONS.map((option) => option.value),
    };
  });

  expect(result.readOnly).toBe(true);
  expect(result.imageCount).toBe(1);
  expect(result.pdfCount).toBe(0);
  expect(result.textCount).toBe(0);
  expect(result.kindOptions).toEqual(['all', 'image', 'pdf', 'office', 'text', 'document']);
});
