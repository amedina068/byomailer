// The values that database/seeders/E2eSeeder.php writes.
export const owner = {
    name: 'E2E Owner',
    email: 'e2e@example.com',
    password: 'password',
};

export const seededContactEmail = 'seeded.contact@example.com';

export const seededTagName = 'e2e-seeded-tag';

// auth.setup.ts saves the session of the owner here. .gitignore excludes it.
export const ownerStorageState = 'tests/e2e/.auth/owner.json';

// A value that is new in each test run.
export function unique(prefix: string): string {
    return `${prefix}-${Date.now()}-${Math.floor(Math.random() * 1000)}`;
}
