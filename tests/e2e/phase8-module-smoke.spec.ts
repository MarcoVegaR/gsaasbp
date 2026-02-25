import { expect, test } from '@playwright/test';

const credentials = {
    email: 'test@example.com',
    password: 'password',
};

test('tenant sidebar exposes generated module entry and module route stays guarded', async ({ page }, testInfo) => {
    test.skip(testInfo.project.name !== 'tenant', 'Phase 8 tenant module smoke test runs only in tenant project.');

    await page.goto('/tenant/dashboard');
    await expect(page).toHaveURL(/\/login/);

    await page.getByLabel('Email address').fill(credentials.email);
    await page.getByLabel('Password').fill(credentials.password);
    await page.getByRole('button', { name: /^log in$/i }).click();
    await page.waitForLoadState('networkidle');

    await page.goto('/tenant/dashboard');
    await expect(page).toHaveURL(/\/tenant\/dashboard$/);

    const moduleLink = page.getByRole('link', { name: 'Sample Entity' }).first();
    await expect(moduleLink).toBeVisible();

    const moduleResponse = await page.goto('/tenant/modules/sample-entities');

    expect(moduleResponse?.status()).toBe(403);
});
