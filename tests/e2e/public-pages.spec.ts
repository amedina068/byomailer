import { expect, test } from '@playwright/test';

test.describe('public pages', () => {
    test('a guest who opens the home page goes to the login page', async ({
        page,
    }) => {
        await page.goto('/');

        await expect(page).toHaveURL(/\/login$/);
        await expect(page.getByText('Log in to your account')).toBeVisible();
    });

    test('a guest can read the getting started guide', async ({ page }) => {
        await page.goto('/documentation');

        await expect(page).toHaveURL(
            /\/documentation\/guides\/getting-started$/,
        );
        await expect(
            page.getByRole('heading', {
                level: 1,
                name: 'Getting Started with BYOMailer',
            }),
        ).toBeVisible();
        await expect(
            page.getByRole('link', { name: 'REST API' }),
        ).toBeVisible();
    });

    test('the setup page sends to the login page after the owner account exists', async ({
        page,
    }) => {
        await page.goto('/setup');

        await expect(page).toHaveURL(/\/login$/);
        await expect(
            page.getByText(
                'This instance is already set up. Log in with your account.',
            ),
        ).toBeVisible();
    });
});
