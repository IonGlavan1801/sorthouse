import { defineConfig } from '@playwright/test';

export default defineConfig({
    testDir: 'tests/E2E',
    timeout: 60_000,
    workers: 1,
    use: { baseURL: process.env.E2E_URL || 'http://127.0.0.1:8139', trace: 'retain-on-failure', screenshot: 'only-on-failure' },
    projects: [
        { name: 'desktop', use: { viewport: { width: 1440, height: 900 } } },
        { name: 'mobile', use: { viewport: { width: 390, height: 844 }, hasTouch: true, isMobile: true } },
        { name: 'reduced-motion', use: { viewport: { width: 1280, height: 800 }, reducedMotion: 'reduce' } },
    ],
});
