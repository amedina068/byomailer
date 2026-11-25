import { expect, test } from '@playwright/test';

import { ownerStorageState, seededTagName, unique } from './support';

test.use({ storageState: ownerStorageState });

test.describe('tags', () => {
    test('the owner creates a tag and sees it in the list', async ({
        page,
    }) => {
        const name = unique('e2e-tag');

        await page.goto('/tags');
        await expect(
            page.getByRole('cell', { name: seededTagName }),
        ).toBeVisible();

        await page.getByRole('button', { name: 'Create tag' }).click();

        const dialog = page.getByRole('dialog', { name: 'Create new tag' });
        await dialog.getByLabel('Tag name').fill(name);
        await dialog.getByRole('button', { name: 'Create tag' }).click();

        await expect(dialog).toBeHidden();
        await expect(page.getByRole('cell', { name })).toBeVisible();
    });
});
