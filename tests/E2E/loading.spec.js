import { test, expect } from '@playwright/test';

test('evaluation waits for response, distinguishes failure and emptiness, and retries', async ({ page }) => {
    let finish;
    await page.route('**/api/evaluation', async route => {
        await new Promise(resolve => { finish = resolve; });
        await route.fulfill({ status: 503, json: { message: 'Unavailable' } });
    });
    await page.goto('/');
    await expect(page.getByText('Loading evaluation…', { exact: true })).toBeVisible();
    await expect(page.getByText('No evaluation run', { exact: false })).toHaveCount(0);
    finish();
    await expect(page.getByText('Could not load evaluation.', { exact: false })).toBeVisible();
    await page.unroute('**/api/evaluation');
    await page.route('**/api/evaluation', route => route.fulfill({ json: { runs: [] } }));
    await page.getByRole('button', { name: 'Retry', exact: true }).click();
    await expect(page.getByText('No evaluation run has been published', { exact: false })).toBeVisible();
    await expect(page.getByText('Loading evaluation…', { exact: true })).toHaveCount(0);
});
