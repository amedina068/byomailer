import { expect, test } from '@playwright/test';

import { ownerStorageState, seededContactEmail, unique } from './support';

test.use({ storageState: ownerStorageState });

test.describe('contacts', () => {
    test('the contacts page lists the seeded contact', async ({ page }) => {
        await page.goto('/contacts');

        await expect(
            page.getByRole('heading', { level: 1, name: 'Contacts' }),
        ).toBeVisible();
        await expect(page.getByText(seededContactEmail)).toBeVisible();
    });

    test('the owner creates a contact', async ({ page }) => {
        const email = `${unique('new-contact')}@example.com`;

        await page.goto('/contacts');
        await page.getByRole('button', { name: 'Create contact' }).click();

        const dialog = page.getByRole('dialog', { name: 'Create new contact' });
        await dialog.getByLabel('Email address').fill(email);
        await dialog.getByRole('button', { name: 'Create contact' }).click();

        await expect(page).toHaveURL(/\/contacts\/\d+$/);
        await expect(page.getByText(email).first()).toBeVisible();

        await page.goto('/contacts');
        await page.getByLabel('Search contacts').fill(email);
        await expect(page.getByText(email)).toBeVisible();
    });
});
