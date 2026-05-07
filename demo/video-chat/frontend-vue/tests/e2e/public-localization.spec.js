import { test, expect } from '@playwright/test';

const localeDirections = {
  en: 'ltr',
  de: 'ltr',
  ar: 'rtl',
  fa: 'rtl',
};

const viewportMatrix = [
  { name: 'desktop', width: 1280, height: 820 },
  { name: 'tablet', width: 768, height: 920 },
  { name: 'mobile', width: 390, height: 844 },
];

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

    if (url.pathname.startsWith('/api/appointment-calendar/public/') && url.pathname.endsWith('/book') && request.method() === 'POST') {
      await route.fulfill(jsonResponse({
        status: 'ok',
        result: {
          booking: { join_path: '/join/booked-public-localization' },
          join_path: '/join/booked-public-localization',
          call: {
            id: 'booked-public-localization',
            title: 'Video call',
            starts_at: '2026-01-02T09:00:00.000Z',
            ends_at: '2026-01-02T10:00:00.000Z',
          },
        },
      }));
      return;
    }

    await route.fulfill({ status: 404, contentType: 'application/json', body: JSON.stringify({ status: 'error' }) });
  });
}

test('public booking resolves locale and direction without an authenticated session', async ({ page }) => {
  await installPublicLocalizationRoutes(page);

  for (const viewport of viewportMatrix) {
    await page.setViewportSize({ width: viewport.width, height: viewport.height });
    for (const [locale, direction] of Object.entries(localeDirections)) {
      await page.goto(`/book/public-localization-calendar?locale=${locale}`);
      await expect(page.locator('html')).toHaveAttribute('lang', locale);
      await expect(page.locator('html')).toHaveAttribute('dir', direction);
      await expect(page.locator('.appointment-slot-btn')).toHaveCount(1);
      const hasHorizontalOverflow = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 2);
      expect(hasHorizontalOverflow, `${viewport.name} ${locale} should not horizontally overflow`).toBe(false);

      if (viewport.name === 'mobile' && locale === 'en') {
        await expect(page.locator('.appointment-mobile-flow')).toBeVisible();
        await expect(page.locator('.appointment-mobile-day-btn')).toHaveCount(1);
        await expect(page.locator('.appointment-booking-calendar')).toHaveCount(0);
        await expect(page.locator('.appointment-booking-form')).toHaveCount(0);
        await page.locator('.appointment-slot-btn').click();
        await page.getByRole('button', { name: /next/i }).click();
        await expect(page.locator('.appointment-booking-form')).toBeVisible();
        await page.locator('input[autocomplete="given-name"]').fill('Alex');
        await page.locator('input[autocomplete="family-name"]').fill('Tester');
        await page.locator('input[autocomplete="email"]').fill('alex@example.test');
        await page.locator('input[type="checkbox"]').check();
        await page.getByRole('button', { name: /confirm appointment/i }).click();
        await expect(page.getByRole('heading', { name: /booking confirmed/i })).toBeVisible();
        await expect(page.getByRole('link', { name: /open video call/i })).toHaveAttribute('href', /\/join\/booked-public-localization$/);
        await expect(page.getByRole('link', { name: /google calendar/i })).toBeVisible();
        await expect(page.getByRole('button', { name: /ical/i })).toBeVisible();
      }
    }
  }
});

test('public join invalid access message uses localized safe frontend text', async ({ page }) => {
  await installPublicLocalizationRoutes(page);

  await page.goto('/join/not-a-valid-access-id?locale=ar');
  await expect(page.locator('html')).toHaveAttribute('lang', 'ar');
  await expect(page.locator('html')).toHaveAttribute('dir', 'rtl');
  await expect(page.getByText('رابط المكالمة غير صالح.')).toBeVisible();
});
