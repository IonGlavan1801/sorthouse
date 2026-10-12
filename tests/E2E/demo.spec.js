import { test, expect } from '@playwright/test';

const openMsg = async (page, isMobile, text) => {
    if (isMobile) await page.getByRole('button', { name: 'Inbox', exact: true }).click();
    await page.locator('button.msg', { hasText: text }).click();
    if (isMobile) await page.getByRole('button', { name: 'Case', exact: true }).click();
};

test.describe('Sorthouse demo', () => {
    test('landing explains the product and launches an isolated session', async ({ page }) => {
        await page.goto('/');
        await expect(page.getByRole('heading', { level: 1 })).toContainText('stop and ask a human');
        await expect(page.getByText('Live vs simulated')).toBeVisible();
        await page.getByRole('button', { name: 'Launch demo' }).click();
        await page.waitForURL('**/app');
        await expect(page.getByText('SIMULATED · heuristic baseline')).toBeVisible();
        await expect(page.locator('button.msg').first()).toBeVisible({ timeout: 20_000 });
    });

    test('review flow: approve, correct with evidence, reject, and no horizontal overflow', async ({ page, isMobile }) => {
        await page.goto('/');
        await page.getByRole('button', { name: 'Launch demo' }).click();
        await page.waitForURL('**/app');
        await page.waitForFunction(() => document.querySelectorAll('button.msg').length === 8, null, { timeout: 30_000 });
        await page.waitForFunction(() => !document.body.innerText.includes('PROCESSING'), null, { timeout: 30_000 });

        const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
        expect(overflow).toBeLessThanOrEqual(1);

        // photographed invoice: low-confidence field, evidence region, correction
        await openMsg(page, isMobile, 'INV-40881');
        await expect(page.getByText('WHY REVIEW?', { exact: false }).first()).toBeVisible();
        await page.locator('table.fields tr', { hasText: 'invoice_number' }).getByRole('button', { name: 'region' }).click();
        await expect(page.locator('.bbox')).toBeVisible();
        await page.getByRole('button', { name: 'Correct' }).click();
        await page.locator('#f-invoice_number').fill('INV-40881');
        await page.getByRole('button', { name: 'Save correction' }).click();
        await expect(page.locator('table.fields tr', { hasText: 'invoice_number' })).toContainText('INV-40881');
        await expect(page.locator('table.fields tr', { hasText: 'invoice_number' })).toContainText('INV-4OBBl');

        // complaint: approve -> executed (SIMULATED)
        await openMsg(page, isMobile, 'ORD-58213');
        await expect(page.getByRole('button', { name: 'Approve' })).toBeEnabled();
        await page.getByRole('button', { name: 'Approve' }).click();
                await expect(page.locator('.chips').getByText('executed', { exact: false }).first()).toBeVisible({ timeout: 15_000 });

        // reject the mismatch invoice with a reason
        await openMsg(page, isMobile, 'FA-77120');
        await page.getByRole('button', { name: 'Reject' }).first().click();
        await page.getByRole('dialog').getByRole('button', { name: 'Reject' }).click();
        await expect(page.locator('.chips').getByText('rejected', { exact: false }).first()).toBeVisible();
    });

    test('failure lab: prompt injection is blocked and duplicates collapse', async ({ page }) => {
        await page.goto('/');
        await page.getByRole('button', { name: 'Launch demo' }).click();
        await page.waitForURL('**/app');
        await page.waitForFunction(() => document.querySelectorAll('button.msg').length === 8, null, { timeout: 30_000 });
        await page.getByRole('tab', { name: 'Failure Lab' }).click();
        await page.locator('.card', { hasText: 'Prompt injection' }).first().getByRole('button', { name: 'Run' }).click();
        await expect(page.getByText('Blocked by policy', { exact: false }).first()).toBeVisible({ timeout: 20_000 });
        await expect(page.getByText('PROMPT_INJECTION_DETECTED').first()).toBeVisible();
        await expect(page.getByRole('button', { name: 'Approve' })).toBeDisabled();
    });

    test('evaluation page shows measured numbers with provider label', async ({ page }) => {
        await page.goto('/');
        await page.getByRole('button', { name: 'Launch demo' }).click();
        await page.waitForURL('**/app');
        await page.getByRole('tab', { name: 'Evaluation' }).click();
        await expect(page.getByText('false auto actions').first()).toBeVisible({ timeout: 15_000 });
        await expect(page.getByText('SIMULATED heuristic baseline').first()).toBeVisible();
    });
});
