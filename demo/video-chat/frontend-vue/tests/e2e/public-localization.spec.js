import { test, expect } from '@playwright/test';

const localeDirections = {
  en: 'ltr',
  de: 'ltr',
  ar: 'rtl',
  fa: 'rtl',
};

const localizedResources = {
  ar: {
    'errors.api.call_access_validation_failed': 'رابط المكالمة غير صالح.',
  },
  fa: {
    'errors.api.call_access_validation_failed': 'پیوند تماس نامعتبر است.',
  },
};

function jsonResponse(json) {
  return {
    status: 200,
    contentType: 'application/json; charset=utf-8',
    body: JSON.stringify(json),
  };
}

async function installPublicLocalizationRoutes(page) {
  await page.route('**/api/**', async (route) => {
    const request = route.request();
    const url = new URL(request.url());
    const locale = String(url.searchParams.get('locale') || 'en').toLowerCase();

    if (url.pathname === '/api/localization/resources') {
      await route.fulfill(jsonResponse({
        status: 'ok',
        locale,
        direction: localeDirections[locale] || 'ltr',
        namespaces: String(url.searchParams.get('namespaces') || '').split(',').filter(Boolean),
        resources: localizedResources[locale] || {},
        fallback_resources: {},
        supported_locales: Object.keys(localeDirections).map((code) => ({
          code,
          label: code.toUpperCase(),
          direction: localeDirections[code],
        })),
      }));
      return;
    }

    if (url.pathname.startsWith('/api/appointment-calendar/public/') && request.method() === 'GET') {
      await route.fulfill(jsonResponse({
        status: 'ok',
        result: {
          owner: { display_name: 'Public Owner' },
          settings: { slot_minutes: 60, invitation_text: '' },
          slots: [{
            id: 'public-localization-slot',
            starts_at: '2026-01-02T09:00:00.000Z',
            ends_at: '2026-01-02T10:00:00.000Z',
          }],
        },
      }));
      return;
    }

    await route.fulfill({ status: 404, contentType: 'application/json', body: JSON.stringify({ status: 'error' }) });
  });
}

test('public booking resolves locale and direction without an authenticated session', async ({ page }) => {
  await installPublicLocalizationRoutes(page);

  for (const [locale, direction] of Object.entries(localeDirections)) {
    await page.goto(`/book/public-localization-calendar?locale=${locale}`);
    await expect(page.locator('html')).toHaveAttribute('lang', locale);
    await expect(page.locator('html')).toHaveAttribute('dir', direction);
    await expect(page.locator('.appointment-slot-btn')).toHaveCount(1);
  }
});

test('public join invalid access message uses localized safe frontend text', async ({ page }) => {
  await installPublicLocalizationRoutes(page);

  await page.goto('/join/not-a-valid-access-id?locale=ar');
  await expect(page.locator('html')).toHaveAttribute('lang', 'ar');
  await expect(page.locator('html')).toHaveAttribute('dir', 'rtl');
  await expect(page.getByText('رابط المكالمة غير صالح.')).toBeVisible();
});
