import { expect, test } from '@playwright/test';

function resolveProtectedPath(projectName: string): string {
    return projectName === 'tenant' ? '/tenant/dashboard' : '/dashboard';
}

function resolveSessionCookieName(): string {
    const explicitCookie = process.env.SESSION_COOKIE;

    if (typeof explicitCookie === 'string' && explicitCookie !== '') {
        return explicitCookie;
    }

    const appName = process.env.APP_NAME ?? 'Laravel';
    const slug = appName
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '');

    return `${slug || 'laravel'}-session`;
}

test('session cookie remains single-writer and path-root scoped', async ({ page, context }, testInfo) => {
    const protectedPath = resolveProtectedPath(testInfo.project.name);
    const sessionCookieName = resolveSessionCookieName();

    await page.goto(protectedPath);
    await expect(page).toHaveURL(/\/login/);

    await page.getByLabel('Email address').fill('test@example.com');
    await page.getByLabel('Password').fill('password');
    await page.getByRole('button', { name: /^log in$/i }).click();
    await page.waitForLoadState('networkidle');

    await page.goto(protectedPath);

    const host = new URL(page.url()).hostname;
    const cookies = await context.cookies();

    const hostSessionCookies = cookies.filter(
        (cookie) => cookie.name === sessionCookieName && cookie.domain === host,
    );

    expect(hostSessionCookies.length).toBe(1);
    expect(hostSessionCookies[0]?.path).toBe('/');

    if (hostSessionCookies[0]?.name.startsWith('__Host-')) {
        expect(hostSessionCookies[0]?.secure).toBeTruthy();
        expect(hostSessionCookies[0]?.domain.startsWith('.')).toBeFalsy();
    }
});
