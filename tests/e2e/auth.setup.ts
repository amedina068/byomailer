import { expect, test as setup } from '@playwright/test';

import { owner, ownerStorageState } from './support';

setup('log in as the owner', async ({ page }) => {
    await page.goto('/login');
    await page.getByLabel('Email address').fill(owner.email);
    await page.getByLabel('Password').fill(owner.password);
    await page.getByRole('button', { name: 'Log in' }).click();

    await expect(page).toHaveURL(/\/dashboard$/);

    await page.context().storageState({ path: ownerStorageState });
});
