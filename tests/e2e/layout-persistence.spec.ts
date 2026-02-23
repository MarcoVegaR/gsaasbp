import { expect, test } from '@playwright/test';

type RouteSet = {
    dashboardPath: string;
    followUpPath: string;
};

const credentials = {
    email: 'test@example.com',
    password: 'password',
};

function endsWithPath(path: string): RegExp {
    const escapedPath = path.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');

    return new RegExp(`${escapedPath}$`);
}

function resolveRoutes(projectName: string): RouteSet {
    if (projectName === 'tenant') {
        return {
            dashboardPath: '/tenant/dashboard',
            followUpPath: '/tenant/settings',
        };
    }

    return {
        dashboardPath: '/dashboard',
        followUpPath: '/settings/profile',
    };
}

test('sidebar state persists across navigation and reload', async ({ page }, testInfo) => {
    const { dashboardPath, followUpPath } = resolveRoutes(testInfo.project.name);

    await page.goto(dashboardPath);
    await expect(page).toHaveURL(/\/login/);

    await page.getByLabel('Email address').fill(credentials.email);
    await page.getByLabel('Password').fill(credentials.password);

    await page.getByRole('button', { name: /^log in$/i }).click();
    await page.waitForLoadState('networkidle');

    await page.goto(dashboardPath);
    await expect(page).toHaveURL(endsWithPath(dashboardPath));

    const toggleButton = page.getByRole('button', { name: /toggle sidebar/i }).first();
    await expect(toggleButton).toBeVisible();

    await toggleButton.click();

    const collapsedSidebar = page
        .locator('[data-slot="sidebar"][data-state="collapsed"]')
        .first();
    await expect(collapsedSidebar).toBeVisible();

    const currentHost = new URL(page.url()).hostname;
    const collapsedCookie = (await page.context().cookies()).find(
        (cookie) => cookie.name === 'sidebar_state' && cookie.domain === currentHost,
    );

    expect(collapsedCookie?.value).toBe('false');

    await page.goto(followUpPath);
    await expect(page).toHaveURL(endsWithPath(followUpPath));
    await expect(collapsedSidebar).toBeVisible();

    await page.reload();
    await expect(collapsedSidebar).toBeVisible();
});
