import { test, expect } from '@playwright/test';

const supportedLocales = [
  { code: 'en', label: 'English', direction: 'ltr' },
  { code: 'de', label: 'Deutsch', direction: 'ltr' },
  { code: 'ar', label: 'Arabic', direction: 'rtl' },
  { code: 'fa', label: 'Persian', direction: 'rtl' },
];

const rtlLocales = new Set(['ar', 'fa']);

const arabicResources = {
  'localization.admin.title': 'الترجمة',
  'localization.admin.refresh': 'تحديث',
  'localization.admin.upload_csv': 'رفع CSV',
  'localization.admin.preview': 'معاينة',
  'localization.admin.commit': 'اعتماد',
  'localization.admin.search_languages': 'بحث اللغات',
  'localization.admin.no_csv_selected': 'لم يتم اختيار CSV',
  'localization.admin.language': 'اللغة',
  'localization.admin.code': 'الرمز',
  'localization.admin.direction': 'الاتجاه',
  'localization.admin.source': 'المصدر',
  'localization.admin.locale': 'اللغة',
  'localization.admin.namespace': 'النطاق',
  'localization.admin.tenant': 'المستأجر',
  'localization.admin.keys': 'المفاتيح',
  'localization.admin.updated': 'آخر تحديث',
  'localization.admin.bundles': 'الحزم',
  'localization.admin.import_history': 'سجل الاستيراد',
  'localization.admin.file': 'الملف',
  'localization.admin.status': 'الحالة',
  'localization.admin.rows': 'الصفوف',
  'localization.admin.imported': 'تم الاستيراد',
  'localization.admin.no_bundles': 'لا توجد حزم ترجمة بعد.',
  'localization.admin.no_imports': 'لا توجد عمليات استيراد بعد.',
  'localization.admin.languages_total': 'لغات',
  'navigation.administration': 'الإدارة',
  'navigation.administration.app_configuration': 'إعداد التطبيق',
  'navigation.administration.localization': 'الترجمة',
  'navigation.administration.marketplace': 'السوق',
  'navigation.administration.theme_editor': 'محرر السمات',
  'navigation.calls.admin': 'مكالمات الفيديو',
  'navigation.governance': 'الحوكمة',
  'navigation.group.collapse': 'طي {label}',
  'navigation.group.expand': 'فتح {label}',
  'navigation.main': 'التنقل الرئيسي',
  'settings.about': 'نبذة عني',
  'settings.application_language': 'لغة التطبيق',
  'settings.category_tabs_aria': 'فئات الإعدادات',
  'settings.close': 'إغلاق',
  'settings.credentials': 'بيانات الدخول والبريد',
  'settings.dialog_aria': 'إعدادات المستخدم',
  'settings.dialog_title': 'الإعدادات',
  'settings.language': 'اللغة',
  'settings.localization': 'الترجمة',
  'settings.regional_time': 'الوقت والمنطقة',
  'settings.save_settings': 'حفظ الإعدادات',
  'settings.settings_saved': 'تم حفظ الإعدادات.',
  'settings.text_direction': 'اتجاه النص',
  'settings.theme': 'السمة',
};

const viewportMatrix = [
  { name: 'desktop', width: 1280, height: 820 },
  { name: 'tablet', width: 768, height: 920 },
  { name: 'mobile', width: 390, height: 844 },
];

const callShellViewports = [
  { name: 'desktop', width: 1280, height: 820 },
  { name: 'mobile', width: 390, height: 844 },
];

function localeDirection(locale) {
  return rtlLocales.has(String(locale || '').toLowerCase()) ? 'rtl' : 'ltr';
}

function jsonResponse(json) {
  return {
    status: 200,
    contentType: 'application/json; charset=utf-8',
    body: JSON.stringify(json),
  };
}

function adminUser(locale) {
  return {
    id: 1,
    email: 'admin@intelligent-intern.com',
    display_name: 'Admin',
    role: 'admin',
    status: 'active',
    time_format: '24h',
    date_format: 'dmy_dot',
    theme: 'dark',
    locale,
    direction: localeDirection(locale),
    supported_locales: supportedLocales,
    can_edit_themes: true,
    avatar_path: '',
    account_type: 'account',
    is_guest: false,
    tenant: tenantPayload(),
  };
}

function tenantPayload() {
  return {
    id: 1,
    uuid: 'localization-smoke-tenant',
    label: 'Localization Smoke',
    role: 'owner',
    permissions: {
      platform_admin: true,
      tenant_admin: true,
      manage_users: true,
      manage_groups: true,
      manage_organizations: true,
      manage_permission_grants: true,
      edit_themes: true,
    },
  };
}

function sessionEnvelope(locale) {
  return {
    status: 'ok',
    result: { state: 'authenticated' },
    session: {
      id: 'localization-smoke-session',
      token: 'localization-smoke-session',
      expires_at: '2099-01-01T00:00:00Z',
    },
    user: adminUser(locale),
    tenant: tenantPayload(),
  };
}

async function seedAuthenticatedLocalizationRoutes(page, options = {}) {
  let savedLocale = options.locale || 'en';

  await page.addInitScript(() => {
    window.localStorage.setItem('ii_videocall_v1_session', JSON.stringify({
      sessionId: 'localization-smoke-session',
      sessionToken: 'localization-smoke-session',
      expiresAt: '2099-01-01T00:00:00Z',
    }));
  });

  await page.route('**/api/**', async (route) => {
    const request = route.request();
    const url = new URL(request.url());
    const requestedLocale = String(url.searchParams.get('locale') || savedLocale || 'en').toLowerCase();

    if (url.pathname === '/api/auth/session-state') {
      await route.fulfill(jsonResponse(sessionEnvelope(savedLocale)));
      return;
    }

    if (url.pathname === '/api/localization/resources') {
      await route.fulfill(jsonResponse({
        status: 'ok',
        locale: requestedLocale,
        direction: localeDirection(requestedLocale),
        namespaces: String(url.searchParams.get('namespaces') || '').split(',').filter(Boolean),
        resources: requestedLocale === 'ar' ? arabicResources : {},
        fallback_resources: {},
        supported_locales: supportedLocales,
      }));
      return;
    }

    if (url.pathname === '/api/user/settings' && request.method() === 'PATCH') {
      const patch = JSON.parse(request.postData() || '{}');
      savedLocale = String(patch.locale || savedLocale || 'en').toLowerCase();
      await route.fulfill(jsonResponse({
        status: 'ok',
        result: {
          settings: { locale: savedLocale },
          user: adminUser(savedLocale),
        },
      }));
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

    if (url.pathname === '/api/admin/localization/locales') {
      await route.fulfill(jsonResponse({ status: 'ok', locales: supportedLocales }));
      return;
    }

    if (url.pathname === '/api/admin/localization/bundles') {
      await route.fulfill(jsonResponse({
        status: 'ok',
        bundles: [{
          locale: 'ar',
          namespace: 'settings',
          tenant_id: null,
          resource_count: Object.keys(arabicResources).length,
          updated_at: '2026-05-05T10:00:00Z',
        }],
      }));
      return;
    }

    if (url.pathname === '/api/admin/localization/imports') {
      await route.fulfill(jsonResponse({ status: 'ok', imports: [] }));
      return;
    }

    await route.fulfill(jsonResponse({ status: 'ok', result: {} }));
  });
}

async function seedCallWorkspaceLocalizationRoutes(page, options = {}) {
  const locale = options.locale || 'ar';
  const callId = 'localization-shell-call';
  const roomId = 'localization-shell-room';
  const participantRows = [
    {
      user_id: 1,
      display_name: 'Localization Admin',
      email: 'admin@example.test',
      call_role: 'owner',
      invite_state: 'allowed',
      joined_at: '2026-05-05T10:00:00.000Z',
      connected_at: '2026-05-05T10:00:00.000Z',
    },
  ];
  const call = {
    id: callId,
    room_id: roomId,
    title: 'Localization Shell Call',
    status: 'active',
    starts_at: '2026-05-05T10:00:00.000Z',
    ends_at: '2026-05-05T11:00:00.000Z',
    owner: {
      user_id: 1,
      display_name: 'Localization Admin',
      email: 'admin@example.test',
    },
    participants: participantRows,
  };

  await page.addInitScript((init) => {
    window.localStorage.setItem('ii_videocall_v1_session', JSON.stringify({
      sessionId: 'localization-call-shell-session',
      sessionToken: 'localization-call-shell-session',
      expiresAt: '2099-01-01T00:00:00Z',
    }));

    const listenersSymbol = Symbol('listeners');
    const participants = [
      {
        connection_id: 'conn-localization-admin',
        room_id: init.roomId,
        user: {
          id: 1,
          display_name: 'Localization Admin',
          role: 'admin',
          call_role: 'owner',
        },
        connected_at: '2026-05-05T10:00:00.000Z',
      },
    ];

    function snapshotPayload(reason = 'requested') {
      return {
        type: 'room/snapshot',
        room_id: init.roomId,
        participant_count: participants.length,
        participants,
        viewer: {
          user_id: 1,
          role: 'admin',
          call_id: init.callId,
          call_role: 'owner',
          can_moderate: true,
        },
        layout: {
          call_id: init.callId,
          room_id: init.roomId,
          mode: 'main_mini',
          strategy: 'manual_pinned',
          automation_paused: false,
          pinned_user_ids: [],
          selected_user_ids: [1],
          main_user_id: 1,
          selection: {
            main_user_id: 1,
            visible_user_ids: [1],
            mini_user_ids: [],
            pinned_user_ids: [],
          },
        },
        activity: [],
        reason,
        time: new Date().toISOString(),
      };
    }

    class FakeWebSocket {
      static CONNECTING = 0;
      static OPEN = 1;
      static CLOSING = 2;
      static CLOSED = 3;

      constructor(url) {
        this.url = url;
        this.readyState = FakeWebSocket.CONNECTING;
        this[listenersSymbol] = {};
        setTimeout(() => {
          this.readyState = FakeWebSocket.OPEN;
          this.dispatch('open', {});
          this.emit({
            type: 'system/welcome',
            active_room_id: init.roomId,
            call_context: {
              user_id: 1,
              call_id: init.callId,
              call_role: 'owner',
              can_moderate: true,
            },
          });
          this.emit(snapshotPayload('connected'));
        }, 0);
      }

      addEventListener(type, callback) {
        if (!this[listenersSymbol][type]) this[listenersSymbol][type] = [];
        this[listenersSymbol][type].push(callback);
      }

      removeEventListener(type, callback) {
        this[listenersSymbol][type] = (this[listenersSymbol][type] || []).filter((row) => row !== callback);
      }

      dispatch(type, event) {
        for (const callback of this[listenersSymbol][type] || []) {
          callback(event);
        }
      }

      emit(payload) {
        this.dispatch('message', { data: JSON.stringify(payload) });
      }

      send(data) {
        const payload = JSON.parse(String(data || '{}'));
        if (payload.type === 'room/snapshot/request') {
          setTimeout(() => this.emit(snapshotPayload('requested')), 0);
        }
      }

      close() {
        this.readyState = FakeWebSocket.CLOSED;
        this.dispatch('close', { code: 1000, reason: 'test_close' });
      }
    }

    window.WebSocket = FakeWebSocket;
    Object.defineProperty(navigator, 'mediaDevices', {
      configurable: true,
      value: {
        enumerateDevices: async () => [],
        getUserMedia: async () => new MediaStream(),
        addEventListener: () => {},
        removeEventListener: () => {},
      },
    });
  }, { callId, roomId });

  await page.route('**/api/**', async (route) => {
    const request = route.request();
    const url = new URL(request.url());
    const requestedLocale = String(url.searchParams.get('locale') || locale || 'en').toLowerCase();

    if (url.pathname === '/api/auth/session-state') {
      await route.fulfill(jsonResponse(sessionEnvelope(locale)));
      return;
    }

    if (url.pathname === '/api/localization/resources') {
      await route.fulfill(jsonResponse({
        status: 'ok',
        locale: requestedLocale,
        direction: localeDirection(requestedLocale),
        namespaces: String(url.searchParams.get('namespaces') || '').split(',').filter(Boolean),
        resources: requestedLocale === 'ar' ? arabicResources : {},
        fallback_resources: {},
        supported_locales: supportedLocales,
      }));
      return;
    }

    if (url.pathname === `/api/calls/resolve/${roomId}`) {
      await route.fulfill(jsonResponse({
        status: 'ok',
        result: {
          state: 'resolved',
          resolved_as: 'call',
          call,
        },
      }));
      return;
    }

    if (url.pathname === `/api/calls/${callId}`) {
      await route.fulfill(jsonResponse({ status: 'ok', call }));
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

    await route.fulfill(jsonResponse({ status: 'ok', result: {} }));
  });

  return { callId, roomId };
}

async function expectNoHorizontalOverflow(page, label) {
  const hasHorizontalOverflow = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 2);
  expect(hasHorizontalOverflow, `${label} should not horizontally overflow`).toBe(false);
}

test('settings language switch persists and flips the workspace to RTL', async ({ page }, testInfo) => {
  await seedAuthenticatedLocalizationRoutes(page, { locale: 'en' });

  await page.setViewportSize({ width: 1280, height: 820 });
  await page.goto('/admin/administration/localization');
  await expect(page.locator('html')).toHaveAttribute('lang', 'en');
  await expect(page.locator('html')).toHaveAttribute('dir', 'ltr');
  await expect(page.getByRole('heading', { name: 'Localization' })).toBeVisible();
  await expectNoHorizontalOverflow(page, 'settings smoke before language switch');
  await page.screenshot({ path: testInfo.outputPath('settings-admin-ltr.png'), fullPage: true });

  await page.getByLabel('Open settings').click();
  const dialog = page.locator('.settings-dialog');
  await expect(dialog).toBeVisible();
  await expect(dialog).toHaveAttribute('dir', 'ltr');
  await page.getByRole('button', { name: 'Localization', exact: true }).click();
  await dialog.locator('select').selectOption('ar');
  await expect(dialog).toHaveAttribute('dir', 'rtl');
  await page.screenshot({ path: testInfo.outputPath('settings-dialog-rtl-before-save.png'), fullPage: true });

  await page.getByRole('button', { name: 'Save settings' }).click();
  await expect(dialog).toBeHidden();
  await expect(page.locator('html')).toHaveAttribute('lang', 'ar');
  await expect(page.locator('html')).toHaveAttribute('dir', 'rtl');
  await expect(page.getByRole('heading', { name: 'الترجمة' })).toBeVisible();
  await expectNoHorizontalOverflow(page, 'settings smoke after language switch');
  await page.screenshot({ path: testInfo.outputPath('settings-admin-rtl.png'), fullPage: true });

  await page.reload();
  await expect(page.locator('html')).toHaveAttribute('lang', 'ar');
  await expect(page.locator('html')).toHaveAttribute('dir', 'rtl');
  await expect(page.getByRole('heading', { name: 'الترجمة' })).toBeVisible();
});

test('admin localization shell stays within the viewport across LTR and RTL breakpoints', async ({ page }, testInfo) => {
  await seedAuthenticatedLocalizationRoutes(page, { locale: 'ar' });

  for (const viewport of viewportMatrix) {
    await page.setViewportSize({ width: viewport.width, height: viewport.height });
    await page.goto('/admin/administration/localization');
    await expect(page.locator('html')).toHaveAttribute('lang', 'ar');
    await expect(page.locator('html')).toHaveAttribute('dir', 'rtl');
    await expect(page.getByRole('heading', { name: 'الترجمة' })).toBeVisible();
    await expectNoHorizontalOverflow(page, `admin localization ${viewport.name} rtl`);
    await page.screenshot({ path: testInfo.outputPath(`admin-localization-${viewport.name}-rtl.png`), fullPage: true });
  }
});

test('call workspace shell renders in RTL without camera permission', async ({ page }, testInfo) => {
  const { roomId } = await seedCallWorkspaceLocalizationRoutes(page, { locale: 'ar' });

  for (const viewport of callShellViewports) {
    await page.setViewportSize({ width: viewport.width, height: viewport.height });
    await page.goto(`/workspace/call/${roomId}`);
    await expect(page.locator('html')).toHaveAttribute('lang', 'ar');
    await expect(page.locator('html')).toHaveAttribute('dir', 'rtl');
    await expect(page.locator('.workspace-stage')).toBeVisible();
    await expect(page.locator('.user-row.self .user-name')).toHaveText('Admin');
    await expectNoHorizontalOverflow(page, `call workspace ${viewport.name} rtl`);
    await page.screenshot({ path: testInfo.outputPath(`call-workspace-${viewport.name}-rtl.png`), fullPage: true });
  }
});
