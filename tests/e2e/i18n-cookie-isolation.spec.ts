import { expect, test, type Cookie } from '@playwright/test';

const localeCookieName = process.env.APP_LOCALE_COOKIE ?? 'locale';

function extractLocaleCookie(cookies: Cookie[], host: string): Cookie | undefined {
    return cookies.find(
        (cookie) => cookie.name === localeCookieName && cookie.domain === host,
    );
}

test('locale cookie is isolated between central and tenant hosts', async ({ page, context }) => {
    const activeProjectBaseUrl = test.info().project.use.baseURL;

    if (typeof activeProjectBaseUrl !== 'string' || activeProjectBaseUrl === '') {
        throw new Error('Playwright project baseURL is required for this test.');
    }

    const activeProjectPort = new URL(activeProjectBaseUrl).port || '8000';
    const centralBaseUrl =
        process.env.PLAYWRIGHT_CENTRAL_BASE_URL ??
        `http://localhost:${activeProjectPort}`;
    const tenantBaseUrl =
        process.env.PLAYWRIGHT_TENANT_BASE_URL ??
        `http://tenant.localhost:${activeProjectPort}`;

    const centralHost = new URL(centralBaseUrl).hostname;
    const tenantHost = new URL(tenantBaseUrl).hostname;

    await page.goto(`${centralBaseUrl}/?lang=es`);

    const centralCookies = await context.cookies(centralBaseUrl);
    const centralLocaleCookie = extractLocaleCookie(centralCookies, centralHost);

    expect(centralLocaleCookie).toBeDefined();
    expect(centralLocaleCookie?.value).toBeTruthy();
    expect(centralLocaleCookie?.path).toBe('/');
    expect(centralLocaleCookie?.sameSite).toBe('Lax');
    expect(centralLocaleCookie?.secure).toBeFalsy();
    expect(centralLocaleCookie?.domain.startsWith('.')).toBeFalsy();

    const centralLocaleCookieValue = centralLocaleCookie?.value;

    await page.goto(`${tenantBaseUrl}/`);

    const tenantCookiesBeforeLangSwitch = await context.cookies(tenantBaseUrl);
    const leakedCookie = extractLocaleCookie(tenantCookiesBeforeLangSwitch, tenantHost);
    expect(leakedCookie).toBeUndefined();

    await page.goto(`${tenantBaseUrl}/?lang=en`);

    const tenantCookiesAfterLangSwitch = await context.cookies(tenantBaseUrl);
    const tenantLocaleCookie = extractLocaleCookie(
        tenantCookiesAfterLangSwitch,
        tenantHost,
    );

    expect(tenantLocaleCookie).toBeDefined();
    expect(tenantLocaleCookie?.value).toBeTruthy();
    expect(tenantLocaleCookie?.path).toBe('/');
    expect(tenantLocaleCookie?.sameSite).toBe('Lax');
    expect(tenantLocaleCookie?.secure).toBeFalsy();
    expect(tenantLocaleCookie?.domain.startsWith('.')).toBeFalsy();

    const centralCookiesAfterTenantSwitch = await context.cookies(centralBaseUrl);
    const centralLocaleCookieAfterTenantSwitch = extractLocaleCookie(
        centralCookiesAfterTenantSwitch,
        centralHost,
    );

    expect(centralLocaleCookieAfterTenantSwitch?.value).toBe(
        centralLocaleCookieValue,
    );
});
