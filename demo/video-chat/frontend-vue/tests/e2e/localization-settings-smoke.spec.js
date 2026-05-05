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
