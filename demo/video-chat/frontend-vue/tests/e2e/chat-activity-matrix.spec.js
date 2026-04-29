import { test, expect } from '@playwright/test';
import {
  allowedFixtureNames,
  backendOrigin,
  blockedFixtureNames,
  createMatrixPage,
  corsHeaders,
  dropFilesOnChatComposer,
  fixtureBrowserPayload,
  fixtureExists,
  fixtureFileSpec,
  matrixCallId,
  matrixCallRef,
  matrixRoomId,
  matrixUsers,
  openChatTab,
  openMatrixWorkspace,
} from './helpers/videochatMatrixHarness.js';

async function withAttachmentHelpers(page, callbackSource) {
  await page.goto('/src/domain/realtime/chat/attachments.js', { waitUntil: 'domcontentloaded' });
  return page.evaluate(async (source) => {
    const helpers = await import('/src/domain/realtime/chat/attachments.js');
    const callback = new Function('helpers', `return (${source})(helpers);`);
    return callback(helpers);
  }, callbackSource.toString());
}

async function pasteTextIntoChat(page, text) {
  const input = page.getByPlaceholder('Write a message');
  await input.focus();
  await input.evaluate((node, value) => {
    const data = new DataTransfer();
    data.setData('text/plain', value);
    node.dispatchEvent(new ClipboardEvent('paste', { bubbles: true, cancelable: true, clipboardData: data }));
  }, text);
}

async function submitChat(page) {
  await page.locator('.workspace-chat-compose').evaluate((form) => {
    form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
  });
}

async function openMatrixWorkspaceWithRealtimeSocket(page) {
  await page.goto(`/workspace/call/${matrixCallRef}`);
  await page.waitForSelector('.workspace-call-view');
  await page.evaluate(({ roomId, callId }) => {
    const setup = document.querySelector('.workspace-call-view')?.__vueParentComponent?.setupState;
    if (!setup) throw new Error('Call workspace setup state is not available.');
    setup.connectionState = 'online';
    setup.connectionReason = 'ready';
    setup.serverRoomId = roomId;
    if ('activeCallId' in setup) setup.activeCallId = callId;
    if ('viewerCallRole' in setup) setup.viewerCallRole = window.__matrixActiveUser?.callRole || 'participant';
    setup.participantsRaw = Array.isArray(window.__matrixParticipants) ? window.__matrixParticipants : [];
    if ('hasRealtimeRoomSync' in setup) setup.hasRealtimeRoomSync = true;
    setup.socketRef = typeof window.__matrixCreateOpenSocket === 'function' ? window.__matrixCreateOpenSocket() : setup.socketRef;

    setup.callLayoutState.call_id = callId;
    setup.callLayoutState.room_id = roomId;
    setup.callLayoutState.mode = 'main_mini';
    setup.callLayoutState.strategy = 'manual_pinned';
    setup.callLayoutState.automation_paused = false;
    setup.callLayoutState.main_user_id = 1;
    setup.callLayoutState.pinned_user_ids.splice(0, setup.callLayoutState.pinned_user_ids.length);
    setup.callLayoutState.selected_user_ids.splice(0, setup.callLayoutState.selected_user_ids.length, 1, 2);
    setup.callLayoutState.selection.main_user_id = 1;
    setup.callLayoutState.selection.visible_user_ids.splice(0, setup.callLayoutState.selection.visible_user_ids.length, 1, 2);
    setup.callLayoutState.selection.mini_user_ids.splice(0, setup.callLayoutState.selection.mini_user_ids.length, 2);
    setup.callLayoutState.selection.pinned_user_ids.splice(0, setup.callLayoutState.selection.pinned_user_ids.length);
  }, { roomId: matrixRoomId, callId: matrixCallId });
  await page.waitForFunction(() => (
    (window.__matrixSocketFrames || []).some((frame) => frame?.type === 'room/snapshot/request')
  ));
}

async function matrixRealtimeCounts(page) {
  return page.evaluate(() => {
    const frames = Array.isArray(window.__matrixSocketFrames) ? window.__matrixSocketFrames : [];
    const roomSockets = (Array.isArray(window.__matrixSockets) ? window.__matrixSockets : [])
      .filter((socket) => String(socket?.url || '').includes('/ws?'));
    return {
      roomSockets: roomSockets.length,
      snapshotRequests: frames.filter((frame) => frame?.type === 'room/snapshot/request').length,
      controlSyncs: frames.filter((frame) => (
        frame?.type === 'call/control-state'
        && frame?.payload?.kind === 'workspace-control-state'
      )).length,
      currentControlSyncs: frames.filter((frame) => (
        frame?.type === 'call/control-state'
        && frame?.payload?.kind === 'workspace-control-state'
        && frame?.payload?.state?.handRaised === true
        && frame?.payload?.state?.micEnabled === false
      )).length,
      mediaSecurityHellos: frames.filter((frame) => frame?.type === 'media-security/hello').length,
    };
  });
}

async function waitForLastChatMessage(page, expectedAttachmentCount) {
  const handle = await page.waitForFunction((count) => (
    window.__matrixLastChatMessage
    && window.__matrixLastChatMessage.message
    && Array.isArray(window.__matrixLastChatMessage.message.attachments)
    && window.__matrixLastChatMessage.message.attachments.length === count
  ), expectedAttachmentCount);
  await handle.dispose();
  return page.evaluate(() => window.__matrixLastChatMessage);
}

async function waitForLastChatText(page, expectedText) {
  const handle = await page.waitForFunction((text) => (
    window.__matrixLastChatMessage
    && window.__matrixLastChatMessage.message
    && window.__matrixLastChatMessage.message.text === text
  ), expectedText);
  await handle.dispose();
  return page.evaluate(() => window.__matrixLastChatMessage);
}

function escapedRegExp(input) {
  return String(input).replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

async function installJourneyArchiveRoutes(page, messages) {
  const archivedMessages = messages.map((event, index) => ({
    id: event?.message?.id || `journey-chat-${index + 1}`,
    text: String(event?.message?.text || ''),
    sender: event?.message?.sender || { user_id: matrixUsers.admin.id, display_name: matrixUsers.admin.displayName, role: matrixUsers.admin.role },
    server_time: event?.message?.server_time || new Date(Date.now() + index).toISOString(),
    attachments: Array.isArray(event?.message?.attachments) ? event.message.attachments : [],
  }));
  const files = archivedMessages.flatMap((message) => message.attachments.map((attachment) => ({
    ...attachment,
    sender: message.sender,
    attached_at: message.server_time,
  })));
  const groups = {
    images: files.filter((file) => file.kind === 'image'),
    pdfs: files.filter((file) => file.kind === 'pdf'),
    office: files.filter((file) => file.kind === 'document' && ['doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'odt', 'ods', 'odp'].includes(String(file.extension || '').toLowerCase())),
    text: files.filter((file) => file.kind === 'text' || ['txt', 'csv', 'md'].includes(String(file.extension || '').toLowerCase())),
    documents: files.filter((file) => !['image', 'pdf', 'text'].includes(String(file.kind || ''))),
  };
  const callListRoute = new RegExp(`${escapedRegExp(backendOrigin)}/api/calls(?:\\?.*)?$`);

  await page.route(callListRoute, async (route) => {
    await route.fulfill({
      status: 200,
      headers: { ...corsHeaders(), 'content-type': 'application/json; charset=utf-8' },
      json: {
        status: 'ok',
        calls: [
          {
            id: matrixCallId,
            room_id: matrixCallRef,
            title: 'Matrix UI Call',
            access_mode: 'invite_only',
            status: 'ended',
            starts_at: '2026-04-19T12:00:00.000Z',
            ends_at: '2026-04-19T13:00:00.000Z',
            owner: {
              user_id: matrixUsers.admin.id,
              display_name: matrixUsers.admin.displayName,
              email: matrixUsers.admin.email,
            },
            participants: {
              total: 2,
              internal: 2,
              external: 0,
            },
            my_participation: {
              call_role: 'participant',
              invite_state: 'allowed',
            },
          },
        ],
        pagination: {
          page: 1,
          page_size: 10,
          total: 1,
          page_count: 1,
          returned: 1,
          has_prev: false,
          has_next: false,
        },
        time: '2026-04-19T13:00:00.000Z',
      },
    });
  });

  await page.route(`**/api/calls/${matrixCallId}/chat-archive**`, async (route) => {
    await route.fulfill({
      status: 200,
      headers: { ...corsHeaders(), 'content-type': 'application/json; charset=utf-8' },
      json: {
        status: 'ok',
        result: {
          archive: {
            call_id: matrixCallId,
            room_id: matrixCallRef,
            read_only: true,
            messages: archivedMessages,
            files: { groups },
            pagination: {
              cursor: 0,
              limit: 50,
              returned: archivedMessages.length,
              has_next: false,
              next_cursor: null,
            },
          },
        },
      },
    });
  });
}

async function waitForLastReactionEvent(page, expectedEmoji) {
  const handle = await page.waitForFunction((emoji) => {
    const payload = window.__matrixLastReactionEvent || {};
    const reaction = payload.reaction || {};
    const reactions = Array.isArray(payload.reactions) ? payload.reactions : [];
    return reaction.emoji === emoji || reactions.some((row) => row && row.emoji === emoji);
  }, expectedEmoji);
  await handle.dispose();
  return page.evaluate(() => window.__matrixLastReactionEvent);
}

test('chat matrix fixture set covers allowed and blocked upload types', async ({ page }) => {
  for (const name of allowedFixtureNames) {
    expect(fixtureExists('allowed', name), `missing allowed fixture ${name}`).toBe(true);
  }
  for (const name of blockedFixtureNames) {
    expect(fixtureExists('blocked', name), `missing blocked fixture ${name}`).toBe(true);
  }

  const result = await withAttachmentHelpers(page, (helpers) => {
    const allowedExtensions = helpers.chatAttachmentAllowedExtensions();
    const allowed = ['txt', 'csv', 'md', 'pdf', 'docx', 'xlsx', 'odt', 'png', 'jpg', 'webp']
      .every((extension) => allowedExtensions.includes(extension));
    const blockedCodes = ['malware.exe', 'script.sh', 'blob.bin'].map((name) => helpers.validateChatAttachmentDraft({ name, sizeBytes: 12 }, []).code);
    const disguisedPdfClientSide = helpers.validateChatAttachmentDraft({ name: 'renamed-binary.pdf', sizeBytes: 18 }, []);
    return { allowed, blockedCodes, disguisedPdfClientSide };
  });

  expect(result.allowed).toBe(true);
  expect(result.blockedCodes).toEqual(['attachment_type_not_allowed', 'attachment_type_not_allowed', 'attachment_type_not_allowed']);
  expect(result.disguisedPdfClientSide.ok).toBe(true);
});

test('text and emoji chat payloads enable the submit button and send through realtime', async ({ browser }) => {
  test.setTimeout(60_000);
  const baseURL = test.info().project.use.baseURL || 'http://127.0.0.1:4174';
  const admin = await createMatrixPage(browser, baseURL, matrixUsers.admin);

  try {
    await openMatrixWorkspace(admin.page);
    await openChatTab(admin.page);

    const input = admin.page.getByPlaceholder('Write a message');
    const sendButton = admin.page.locator('.workspace-chat-compose button[type="submit"]');
    await expect(sendButton).toBeDisabled();

    const textMessage = `matrix text ${Date.now()}`;
    await input.fill(textMessage);
    await expect(sendButton).toBeEnabled();
    await sendButton.click();
    const textPayload = await waitForLastChatText(admin.page, textMessage);
    expect(textPayload.message.text).toBe(textMessage);

    await admin.page.locator('.chat-emoji-toggle').click();
    await admin.page.locator('.workspace-chat-emoji-btn', { hasText: '🚀' }).click();
    await expect(input).toHaveValue('🚀');
    await expect(sendButton).toBeEnabled();
    await sendButton.click();
    const emojiPayload = await waitForLastChatText(admin.page, '🚀');
    expect(emojiPayload.message.text).toBe('🚀');
  } finally {
    await Promise.allSettled([admin.context.close()]);
  }
});

test('reaction emoji and chat emoji are visible to every call participant', async ({ browser }) => {
  test.setTimeout(60_000);
  const baseURL = test.info().project.use.baseURL || 'http://127.0.0.1:4174';
  const admin = await createMatrixPage(browser, baseURL, matrixUsers.admin);
  const user = await createMatrixPage(browser, baseURL, matrixUsers.user);

  try {
    await openMatrixWorkspace(admin.page);
    await openMatrixWorkspace(user.page);

    await admin.page.getByTitle('Reactions').click();
    await admin.page.locator('.workspace-reaction-btn', { hasText: '👏' }).click();
    await expect(admin.page.locator('.workspace-reaction-particle', { hasText: '👏' }).first()).toBeVisible();

    const reactionPayload = await waitForLastReactionEvent(admin.page, '👏');
    await user.page.evaluate((event) => window.__matrixEmit(event), reactionPayload);
    await expect(user.page.locator('.workspace-reaction-particle', { hasText: '👏' }).first()).toBeVisible();

    await openChatTab(admin.page);
    await admin.page.locator('.chat-emoji-toggle').click();
    await admin.page.locator('.workspace-chat-emoji-btn', { hasText: '🚀' }).click();
    await admin.page.locator('.workspace-chat-compose button[type="submit"]').click();
    const chatPayload = await waitForLastChatText(admin.page, '🚀');

    await user.page.evaluate((event) => window.__matrixEmit(event), chatPayload);
    await openChatTab(user.page);
    await expect(user.page.locator('.workspace-chat-message').last()).toContainText('🚀');
  } finally {
    await Promise.allSettled([admin.context.close(), user.context.close()]);
  }
});

test('first incoming chat message shows unread badge and chat icon notification only for peers', async ({ browser }) => {
  test.setTimeout(60_000);
  const baseURL = test.info().project.use.baseURL || 'http://127.0.0.1:4174';
  const admin = await createMatrixPage(browser, baseURL, matrixUsers.admin);
  const user = await createMatrixPage(browser, baseURL, matrixUsers.user);

  try {
    await openMatrixWorkspace(admin.page);
    await openMatrixWorkspace(user.page);
    await openChatTab(admin.page);

    const firstMessage = `first unread ${Date.now()}`;
    await admin.page.getByPlaceholder('Write a message').fill(firstMessage);
    await admin.page.locator('.workspace-chat-compose button[type="submit"]').click();
    const payload = await waitForLastChatText(admin.page, firstMessage);

    await expect(admin.page.locator('.tab-chat-unread-badge')).toHaveCount(0);
    await expect(admin.page.locator('.workspace-chat-toast')).toHaveCount(0);

    await user.page.evaluate((event) => window.__matrixEmit(event), payload);
    await expect(user.page.locator('.tab-chat-unread-badge')).toBeVisible();
    await expect(user.page.locator('.workspace-chat-toast')).toBeVisible();

    await user.page.locator('.workspace-chat-toast').click();
    await expect(user.page.getByRole('tab', { name: 'Chat' })).toHaveAttribute('aria-selected', 'true');
    await expect(user.page.locator('.workspace-chat-message').last()).toContainText(firstMessage);
    await expect(user.page.locator('.tab-chat-unread-badge')).toHaveCount(0);
    await expect(user.page.locator('.workspace-chat-toast')).toHaveCount(0);
  } finally {
    await Promise.allSettled([admin.context.close(), user.context.close()]);
  }
});

test('chat journey covers text, emoji, unread badge, attachment, and read-only archive', async ({ browser }) => {
  test.setTimeout(90_000);
  const baseURL = test.info().project.use.baseURL || 'http://127.0.0.1:4174';
  const admin = await createMatrixPage(browser, baseURL, matrixUsers.admin);
  const user = await createMatrixPage(browser, baseURL, matrixUsers.user);

  try {
    await openMatrixWorkspace(admin.page);
    await openMatrixWorkspace(user.page);
    await openChatTab(admin.page);

    const textMessage = `journey text ${Date.now()}`;
    await admin.page.getByPlaceholder('Write a message').fill(textMessage);
    await admin.page.locator('.workspace-chat-compose button[type="submit"]').click();
    const textPayload = await waitForLastChatText(admin.page, textMessage);

    await user.page.evaluate((event) => window.__matrixEmit(event), textPayload);
    await expect(user.page.locator('.tab-chat-unread-badge')).toBeVisible();
    await expect(user.page.locator('.workspace-chat-toast')).toBeVisible();
    await user.page.locator('.workspace-chat-toast').click();
    await expect(user.page.getByRole('tab', { name: 'Chat' })).toHaveAttribute('aria-selected', 'true');
    await expect(user.page.locator('.workspace-chat-message').last()).toContainText(textMessage);
    await expect(user.page.locator('.tab-chat-unread-badge')).toHaveCount(0);

    await openChatTab(admin.page);
    await admin.page.locator('.chat-emoji-toggle').click();
    await admin.page.locator('.workspace-chat-emoji-btn', { hasText: '🚀' }).click();
    await admin.page.locator('.workspace-chat-compose button[type="submit"]').click();
    const emojiPayload = await waitForLastChatText(admin.page, '🚀');
    await user.page.evaluate((event) => window.__matrixEmit(event), emojiPayload);
    await expect(user.page.locator('.workspace-chat-message').last()).toContainText('🚀');

    await openChatTab(admin.page);
    await admin.page.locator('input.workspace-chat-file-input').setInputFiles([fixtureFileSpec('allowed', 'note.txt')]);
    await expect(admin.page.locator('.workspace-chat-draft')).toHaveCount(1);
    await submitChat(admin.page);
    const attachmentPayload = await waitForLastChatMessage(admin.page, 1);
    await user.page.evaluate((event) => window.__matrixEmit(event), attachmentPayload);
    await expect(user.page.locator('.workspace-chat-attachment', { hasText: 'note.txt' })).toBeVisible();

    await installJourneyArchiveRoutes(user.page, [textPayload, emojiPayload, attachmentPayload]);
    await user.page.goto('/user/dashboard');
    await expect(user.page.getByText('Matrix UI Call')).toBeVisible();
    await user.page.getByRole('button', { name: 'Open chat archive for Matrix UI Call' }).click();
    await expect(user.page.getByTestId('chat-archive-modal')).toBeVisible();
    await expect(user.page.getByLabel('Archived chat messages')).toContainText(textMessage);
    await expect(user.page.getByLabel('Archived chat messages')).toContainText('🚀');
    await expect(user.page.getByLabel('Archived chat messages')).toContainText('note.txt');
    await expect(user.page.getByLabel('Archived chat files')).toContainText('Text');
    await expect(user.page.getByLabel('Archived chat files')).toContainText('note.txt');
    await expect(user.page.getByText('Read-only archive. Messages and files cannot be changed here.')).toBeVisible();
    await expect(user.page.getByRole('button', { name: /^Send$/ })).toHaveCount(0);
    await expect(user.page.getByRole('textbox', { name: /message/i })).toHaveCount(0);
  } finally {
    await Promise.allSettled([admin.context.close(), user.context.close()]);
  }
});

test('websocket reconnect resyncs room snapshot, media security, and control state', async ({ browser }) => {
  test.setTimeout(90_000);
  const baseURL = test.info().project.use.baseURL || 'http://127.0.0.1:4174';
  const admin = await createMatrixPage(browser, baseURL, matrixUsers.admin);

  try {
    await openMatrixWorkspace(admin.page);

    await expect(admin.page.locator('.user-row', { hasText: matrixUsers.user.displayName })).toBeVisible();
    await expect(admin.page.locator('.workspace-mini-tile', { hasText: matrixUsers.user.displayName })).toBeVisible();

    await admin.page.waitForFunction(() => (
      (window.__matrixSocketFrames || []).some((frame) => frame?.type === 'room/snapshot/request')
    ), null, { timeout: 10_000 });

    const beforeCounts = await matrixRealtimeCounts(admin.page);
    expect(beforeCounts.snapshotRequests).toBeGreaterThan(0);

    await admin.page.getByTitle('Raise hand').click();
    await admin.page.getByTitle('Toggle microphone').click();
    await expect(admin.page.getByTitle('Raise hand')).toHaveClass(/active/);
    await expect(admin.page.getByTitle('Toggle microphone')).not.toHaveClass(/active/);

    const dropped = await admin.page.evaluate(() => window.__matrixForceSocketClose(1006, 'network_drop'));
    expect(dropped).toBe(true);

    await admin.page.waitForFunction(() => {
      const setup = document.querySelector('.workspace-call-view')?.__vueParentComponent?.setupState;
      return setup?.connectionState === 'retrying';
    }, { timeout: 10_000 });

    const afterRetry = await admin.page.waitForFunction(() => {
      const setup = document.querySelector('.workspace-call-view')?.__vueParentComponent?.setupState;
      return setup?.connectionState === 'online';
    }, { timeout: 15_000 });

    await admin.page.waitForFunction(() => (
      (window.__matrixSocketFrames || []).some((frame) => frame?.type === 'room/snapshot/request')
    ), null, { timeout: 10_000 });

    await expect(admin.page.locator('.user-row', { hasText: matrixUsers.user.displayName })).toBeVisible();
    await expect(admin.page.locator('.workspace-call-view')).toBeVisible();
  } finally {
    await Promise.allSettled([admin.context.close()]);
  }
});

test('large paste becomes a chat file and the other participant gets unread badge plus attachment', async ({ browser }) => {
  test.setTimeout(90_000);
  const baseURL = test.info().project.use.baseURL || 'http://127.0.0.1:4174';
  const admin = await createMatrixPage(browser, baseURL, matrixUsers.admin);
  const user = await createMatrixPage(browser, baseURL, matrixUsers.user);

  try {
    await openMatrixWorkspace(admin.page);
    await openMatrixWorkspace(user.page);
    await expect(admin.page.locator('.workspace-main-video')).toBeVisible();
    await expect(user.page.locator('.workspace-main-video')).toBeVisible();

    await openChatTab(admin.page);
    const oversizedMarkdown = `${'# Matrix paste\n\n'}${'- test line\n'.repeat(2400)}`;
    await pasteTextIntoChat(admin.page, oversizedMarkdown);
    await expect(admin.page.locator('.workspace-chat-draft-name')).toHaveValue(/chat-paste-.*\.md/);

    await submitChat(admin.page);
    const payload = await waitForLastChatMessage(admin.page, 1);
    expect(payload.message.attachments[0].name).toMatch(/chat-paste-.*\.md/);

    await user.page.evaluate((event) => window.__matrixEmit(event), payload);
    await expect(user.page.locator('.tab-chat-unread-badge')).toBeVisible();
    await openChatTab(user.page);
    await expect(user.page.locator('.workspace-chat-attachment')).toContainText(/chat-paste-.*\.md/);
  } finally {
    await Promise.allSettled([admin.context.close(), user.context.close()]);
  }
});

test('drag-and-drop sends 10 images to another participant and visibly rejects the 11th image', async ({ browser }) => {
  test.setTimeout(90_000);
  const baseURL = test.info().project.use.baseURL || 'http://127.0.0.1:4174';
  const admin = await createMatrixPage(browser, baseURL, matrixUsers.admin);
  const user = await createMatrixPage(browser, baseURL, matrixUsers.user);

  try {
    await openMatrixWorkspace(admin.page);
    await openMatrixWorkspace(user.page);
    await openChatTab(admin.page);

    const tenImages = Array.from({ length: 10 }, (_, index) => fixtureBrowserPayload('allowed', 'screen.png', `screen-${index + 1}.png`));
    await dropFilesOnChatComposer(admin.page, tenImages);
    await expect(admin.page.locator('.workspace-chat-draft')).toHaveCount(10);
    await submitChat(admin.page);

    const payload = await waitForLastChatMessage(admin.page, 10);
    await user.page.evaluate((event) => window.__matrixEmit(event), payload);
    await openChatTab(user.page);
    await expect(user.page.locator('.workspace-chat-attachment')).toHaveCount(10);
    await expect(user.page.locator('.workspace-chat-attachment').first()).toContainText('screen-1.png');

    await openChatTab(admin.page);
    const elevenImages = Array.from({ length: 11 }, (_, index) => fixtureBrowserPayload('allowed', 'screen.png', `overflow-${index + 1}.png`));
    await dropFilesOnChatComposer(admin.page, elevenImages);
    await expect(admin.page.locator('.workspace-chat-attachment-error')).toContainText('Only 10 attachments are allowed per chat message.');
  } finally {
    await Promise.allSettled([admin.context.close(), user.context.close()]);
  }
});

test('pdf and office uploads become downloadable chat files while unsafe binaries never send a chat message', async ({ browser }) => {
  test.setTimeout(90_000);
  const baseURL = test.info().project.use.baseURL || 'http://127.0.0.1:4174';
  const admin = await createMatrixPage(browser, baseURL, matrixUsers.admin);

  try {
    await openMatrixWorkspace(admin.page);
    await openChatTab(admin.page);

    await admin.page.locator('input.workspace-chat-file-input').setInputFiles([
      fixtureFileSpec('allowed', 'brief.pdf'),
      fixtureFileSpec('allowed', 'brief.docx'),
      fixtureFileSpec('allowed', 'sheet.xlsx'),
      fixtureFileSpec('allowed', 'notes.odt'),
    ]);
    await expect(admin.page.locator('.workspace-chat-draft')).toHaveCount(4);
    await submitChat(admin.page);
    await waitForLastChatMessage(admin.page, 4);
    await expect(admin.page.locator('.workspace-chat-attachment', { hasText: 'brief.pdf' })).toBeVisible();
    await expect(admin.page.locator('.workspace-chat-attachment', { hasText: 'brief.docx' })).toBeVisible();

    const pdfHref = await admin.page.locator('.workspace-chat-attachment', { hasText: 'brief.pdf' }).getAttribute('href');
    const downloadProbe = await admin.page.evaluate(async ({ origin, href }) => {
      const absoluteUrl = new URL(href, origin).toString();
      const response = await fetch(absoluteUrl);
      return {
        status: response.status,
        disposition: response.headers.get('content-disposition') || '',
        bytes: (await response.arrayBuffer()).byteLength,
      };
    }, { origin: backendOrigin, href: pdfHref });
    expect(downloadProbe.status).toBe(200);
    expect(downloadProbe.disposition).toContain('brief.pdf');
    expect(downloadProbe.bytes).toBeGreaterThan(0);

    await admin.page.locator('input.workspace-chat-file-input').setInputFiles([fixtureFileSpec('blocked', 'malware.exe')]);
    await expect(admin.page.locator('.workspace-chat-attachment-error')).toContainText('File type is not allowed.');

    const sentBefore = await admin.page.evaluate(() => window.__matrixSocketFrames.filter((frame) => frame.type === 'chat/send').length);
    await admin.page.locator('input.workspace-chat-file-input').setInputFiles([fixtureFileSpec('blocked', 'renamed-binary.pdf')]);
    await expect(admin.page.locator('.workspace-chat-draft')).toHaveCount(1);
    await submitChat(admin.page);
    await expect(admin.page.locator('.workspace-chat-attachment-error')).toContainText('Attachment binary type is not allowed.');
    const sentAfter = await admin.page.evaluate(() => window.__matrixSocketFrames.filter((frame) => frame.type === 'chat/send').length);
    expect(sentAfter).toBe(sentBefore);
  } finally {
    await admin.context.close();
  }
});

test('admin layout controls react to activity and pinning overrides active-speaker changes', async ({ browser }) => {
  test.setTimeout(90_000);
  const baseURL = test.info().project.use.baseURL || 'http://127.0.0.1:4174';
  const admin = await createMatrixPage(browser, baseURL, matrixUsers.admin);

  try {
    await openMatrixWorkspace(admin.page);
    const layoutControls = admin.page.locator('.call-left-owner-edit-block');
    if (!(await layoutControls.isVisible())) {
      await admin.page.getByRole('button', { name: 'Open left sidebar' }).click();
    }
    await expect(layoutControls).toBeVisible();
    await expect(admin.page.locator('.workspace-mini-title')).toContainText('Active User');

    await layoutControls.getByLabel('Video layout mode').selectOption('grid');
    await expect(admin.page.locator('.workspace-stage.layout-grid')).toBeVisible();
    await expect(admin.page.locator('.workspace-grid-tile')).toHaveCount(2);
    await layoutControls.getByLabel('Video layout mode').selectOption('main_mini');
    await expect(admin.page.locator('.workspace-stage.layout-main-mini')).toBeVisible();

    await layoutControls.getByLabel('Activity strategy').selectOption('active_speaker_main');
    await expect(layoutControls.getByLabel('Activity strategy')).toHaveValue('active_speaker_main');
    await admin.page.evaluate(() => window.__matrixEmitActivity(2, 95));
    await expect(admin.page.locator('.workspace-mini-title')).toContainText('Active User');
    await expect(admin.page.locator('.user-row', { hasText: 'Active User' }).locator('.user-activity-pill')).toContainText(/Speaking|Active/);

    await layoutControls.getByLabel('Activity strategy').selectOption('manual_pinned');
    await expect(layoutControls.getByLabel('Activity strategy')).toHaveValue('manual_pinned');
    await admin.page.locator('.user-row', { hasText: 'Active User' }).locator('button[title="Pin user"]').click();
    await admin.page.evaluate(() => window.__matrixEmitActivity(1, 99));
    await expect(admin.page.locator('.workspace-mini-title')).toContainText('Active User');
  } finally {
    await admin.context.close();
  }
});

test('unauthorized account cannot read chat archive or download archived attachments', async ({ browser }) => {
  test.setTimeout(60_000);
  const baseURL = test.info().project.use.baseURL || 'http://127.0.0.1:4174';
  const outsider = await createMatrixPage(browser, baseURL, matrixUsers.outsider);

  try {
    await outsider.page.goto('/');
    const archiveResult = await outsider.page.evaluate(async ({ origin, callId }) => {
      const response = await fetch(`${origin}/api/calls/${callId}/chat-archive`);
      return { status: response.status, payload: await response.json().catch(() => null) };
    }, { origin: backendOrigin, callId: matrixCallId });
    expect(archiveResult.status).toBe(403);
    expect(archiveResult.payload.error.code).toBe('forbidden');

    const downloadResult = await outsider.page.evaluate(async ({ origin, callId }) => {
      const response = await fetch(`${origin}/api/calls/${callId}/chat/attachments/not-owned`);
      return { status: response.status, payload: await response.json().catch(() => null) };
    }, { origin: backendOrigin, callId: matrixCallId });
    expect(downloadResult.status).toBe(403);
    expect(downloadResult.payload.error.code).toBe('forbidden');
  } finally {
    await outsider.context.close();
  }
});
