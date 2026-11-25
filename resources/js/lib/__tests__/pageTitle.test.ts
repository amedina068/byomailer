import { describe, expect, it } from 'vitest';

import { formatPageTitle } from '../pageTitle';

describe('formatPageTitle', () => {
    it('adds the app name to a page title', () => {
        expect(formatPageTitle('Topics API: Subscription Topics for Lists', 'BYOMailer')).toBe(
            'Topics API: Subscription Topics for Lists - BYOMailer',
        );
    });

    it('does not repeat the app name when the title already names it', () => {
        expect(formatPageTitle('BYOMailer Guides | AWS SES & Email Marketing', 'BYOMailer')).toBe(
            'BYOMailer Guides | AWS SES & Email Marketing',
        );
    });

    it('uses the app name when the page has no title', () => {
        expect(formatPageTitle('', 'BYOMailer')).toBe('BYOMailer');
    });
});
