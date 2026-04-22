import { test, expect } from '@playwright/test';

async function withChatAttachmentHelpers(page, callbackSource) {
  await page.goto('/src/domain/realtime/chatAttachments.js', { waitUntil: 'domcontentloaded' });
  return page.evaluate(async (source) => {
    const helpers = await import('/src/domain/realtime/chatAttachments.js');
    const callback = new Function('helpers', `return (${source})(helpers);`);
    return callback(helpers);
  }, callbackSource.toString());
}

test('large pasted chat text becomes txt, md, or csv attachment drafts instead of inline websocket text', async ({ page }) => {
  const result = await withChatAttachmentHelpers(page, async (helpers) => {
    const markdown = `${'# Release notes\n\n- first item\n- second item\n\n'}${'body line\n'.repeat(1200)}`;
    const csv = 'name,score\nalpha,1\nbeta,2\ngamma,3';
    const plain = 'plain text only\n'.repeat(1200);
    const markdownDraft = helpers.buildTextAttachmentDraft(markdown, new Date('2026-04-19T12:00:00Z'));
    const plainDraft = helpers.buildTextAttachmentDraft(plain, new Date('2026-04-19T12:00:00Z'));

    return {
      shortInlineAllowed: helpers.isChatTextInlineAllowed('short message'),
      exactInlineBoundaryAllowed: helpers.isChatTextInlineAllowed('a'.repeat(2000)),
      charOverflowInlineAllowed: helpers.isChatTextInlineAllowed('a'.repeat(2001)),
      markdownInlineAllowed: helpers.isChatTextInlineAllowed(markdown),
      emojiOverflowInlineAllowed: helpers.isChatTextInlineAllowed('🚀'.repeat(2048)),
      markdownExtension: markdownDraft.extension,
      markdownName: markdownDraft.name,
      markdownValidationOk: helpers.validateChatAttachmentDraft(markdownDraft, []).ok,
      csvExtension: helpers.detectTextAttachmentExtension(csv),
      plainExtension: plainDraft.extension,
      base64Prefix: (await helpers.chatAttachmentDraftToBase64(plainDraft)).slice(0, 8),
    };
  });

  expect(result.shortInlineAllowed).toBe(true);
  expect(result.exactInlineBoundaryAllowed).toBe(true);
  expect(result.charOverflowInlineAllowed).toBe(false);
  expect(result.markdownInlineAllowed).toBe(false);
  expect(result.emojiOverflowInlineAllowed).toBe(false);
  expect(result.markdownExtension).toBe('md');
  expect(result.markdownName).toMatch(/chat-paste-20260419T120000Z\.md$/);
  expect(result.markdownValidationOk).toBe(true);
  expect(result.csvExtension).toBe('csv');
  expect(result.plainExtension).toBe('txt');
  expect(result.base64Prefix.length).toBeGreaterThan(0);
});

test('attachment picker rules allow safe media/docs and reject count overflow or executables', async ({ page }) => {
  const result = await withChatAttachmentHelpers(page, async (helpers) => {
    const pngBytes = new Uint8Array([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a, 1]);
    const accepted = [];
    const imageResults = [];
    for (let index = 0; index < 10; index += 1) {
      const draft = helpers.buildFileAttachmentDraft(new File([pngBytes], `screen-${index}.png`, { type: 'image/png' }));
      const validation = helpers.validateChatAttachmentDraft(draft, accepted);
      imageResults.push(validation.ok);
      if (validation.ok) accepted.push({ ...draft, name: validation.name, kind: validation.kind });
    }

    const overflowDraft = helpers.buildFileAttachmentDraft(new File([pngBytes], 'screen-overflow.png', { type: 'image/png' }));
    const pdfDraft = helpers.buildFileAttachmentDraft(new File(['%PDF-1.7\n'], 'brief.pdf', { type: 'application/pdf' }));
    const docxDraft = helpers.buildFileAttachmentDraft(new File(['PK\x03\x04[Content_Types].xml word/'], 'brief.docx', {
      type: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    }));
    const exeDraft = helpers.buildFileAttachmentDraft(new File(['MZ'], 'malware.exe', { type: 'application/x-msdownload' }));
    const textDraft = helpers.buildFileAttachmentDraft(new File(['hello'], 'note.txt', { type: 'text/plain' }));

    return {
      allTenImagesAccepted: imageResults.every(Boolean),
      overflowCode: helpers.validateChatAttachmentDraft(overflowDraft, accepted).code,
      pdfOk: helpers.validateChatAttachmentDraft(pdfDraft, []).ok,
      docxOk: helpers.validateChatAttachmentDraft(docxDraft, []).ok,
      exeCode: helpers.validateChatAttachmentDraft(exeDraft, []).code,
      textBase64: await helpers.chatAttachmentDraftToBase64(textDraft),
      allowedHasOffice: helpers.chatAttachmentAllowedExtensions().includes('xlsx'),
    };
  });

  expect(result.allTenImagesAccepted).toBe(true);
  expect(result.overflowCode).toBe('attachment_count_exceeded');
  expect(result.pdfOk).toBe(true);
  expect(result.docxOk).toBe(true);
  expect(result.exeCode).toBe('attachment_type_not_allowed');
  expect(result.textBase64).toBe('aGVsbG8=');
  expect(result.allowedHasOffice).toBe(true);
});
