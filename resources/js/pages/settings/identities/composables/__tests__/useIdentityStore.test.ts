import { describe, expect, it, vi } from 'vitest';

const axiosPost = vi.fn();

vi.mock('axios', () => ({
    default: {
        post: (...args: unknown[]) => axiosPost(...args),
        isAxiosError: () => true,
    },
}));

import { useIdentityStore } from '../useIdentityStore';

const payload = {
    email: 'hello@example.com',
    name: 'A'.repeat(300),
    existing_aws_identity: false,
    open_tracking_enabled: true,
    click_tracking_enabled: true,
    mail_from_subdomain: 'bad',
};

describe('useIdentityStore', () => {
    it('shows the errors of the display name and the MAIL FROM domain', async () => {
        axiosPost.mockRejectedValue({
            response: {
                data: {
                    errors: {
                        name: [
                            'The name field must not be greater than 255 characters.',
                        ],
                        mail_from_subdomain: [
                            'The MAIL FROM domain is not valid.',
                        ],
                    },
                },
            },
        });
        const { store, submitError } = useIdentityStore();

        expect(await store(payload)).toBeNull();
        expect(submitError.value).toBe(
            'The name field must not be greater than 255 characters. The MAIL FROM domain is not valid.',
        );
    });
});
