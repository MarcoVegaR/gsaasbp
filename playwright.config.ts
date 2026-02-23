import path from 'node:path';
import { defineConfig, devices } from '@playwright/test';

const defaultPlaywrightPort = Number(process.env.PLAYWRIGHT_PORT ?? '8000');
const centralBaseUrl = process.env.PLAYWRIGHT_CENTRAL_BASE_URL ??
    `http://localhost:${defaultPlaywrightPort}`;
const tenantBaseUrl = process.env.PLAYWRIGHT_TENANT_BASE_URL ??
    `http://tenant.localhost:${defaultPlaywrightPort}`;
const centralHost = new URL(centralBaseUrl).hostname;
const tenantHost = new URL(tenantBaseUrl).hostname;
const playwrightPort = Number(
    process.env.PLAYWRIGHT_PORT || new URL(centralBaseUrl).port || '8000',
);
const webServerReadyUrl = new URL('/login', centralBaseUrl).toString();
const playwrightSqlitePath = path.resolve(
    process.cwd(),
    'database/playwright.sqlite',
);
const webServerEnvironment = {
    APP_ENV: 'testing',
    APP_URL: centralBaseUrl,
    CENTRAL_DOMAINS: `${centralHost},127.0.0.1`,
    PLAYWRIGHT_TENANT_DOMAIN: tenantHost,
    APP_SUPPORTED_LOCALES: 'en,es',
    DB_CONNECTION: 'sqlite',
    DB_DATABASE: playwrightSqlitePath,
    CACHE_STORE: 'file',
    QUEUE_CONNECTION: 'sync',
    SESSION_DRIVER: 'file',
};
const webServerEnvPrefix = Object.entries(webServerEnvironment)
    .map(([key, value]) => `${key}=${JSON.stringify(value)}`)
    .join(' ');

export default defineConfig({
    testDir: './tests/e2e',
    fullyParallel: false,
    forbidOnly: !!process.env.CI,
    retries: process.env.CI ? 2 : 0,
    workers: 1,
    reporter: process.env.CI
        ? [['github'], ['html', { open: 'never' }]]
        : [['list'], ['html', { open: 'never' }]],
    use: {
        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
        video: 'retain-on-failure',
        viewport: {
            width: 1280,
            height: 800,
        },
    },
    webServer: {
        command: [
            'touch database/playwright.sqlite',
            `${webServerEnvPrefix} php artisan optimize:clear`,
            `${webServerEnvPrefix} php artisan migrate:fresh --seed --force`,
            `${webServerEnvPrefix} npm run build`,
            `${webServerEnvPrefix} php -S 127.0.0.1:${playwrightPort} -t public`,
        ].join(' && '),
        url: webServerReadyUrl,
        timeout: 180_000,
        reuseExistingServer: !process.env.CI,
        env: webServerEnvironment,
    },
    projects: [
        {
            name: 'central',
            use: {
                ...devices['Desktop Chrome'],
                baseURL: centralBaseUrl,
            },
        },
        {
            name: 'tenant',
            use: {
                ...devices['Desktop Chrome'],
                baseURL: tenantBaseUrl,
            },
        },
    ],
});
