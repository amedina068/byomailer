import { expect, test } from '@playwright/test';

import { owner } from './support';

test.describe('login', () => {
    test('a wrong password shows an error and keeps the user on the login page', async ({
        page,
    }) => {
        await page.goto('/login');
        await page.getByLabel('Email address').fill(owner.email);
        await page.getByLabel('Password').fill('not-the-password');
        await page.getByRole('button', { name: 'Log in' }).click();

        await expect(
            page.getByText('These credentials do not match our records.'),
        ).toBeVisible();
        await expect(page).toHaveURL(/\/login$/);
    });

    test('the owner logs in, sees the dashboard and logs out', async ({
        page,
    }) => {
        await page.goto('/login');
        await page.getByLabel('Email address').fill(owner.email);
        await page.getByLabel('Password').fill(owner.password);
        await page.getByRole('button', { name: 'Log in' }).click();

        await expect(page).toHaveURL(/\/dashboard$/);
        await expect(page).toHaveTitle(/Dashboard/);

        await page
            .getByRole('button', { name: new RegExp(owner.name) })
            .click();
        await page.getByRole('menuitem', { name: 'Log out' }).click();

        await expect(page).toHaveURL(/\/login$/);
    });
});
