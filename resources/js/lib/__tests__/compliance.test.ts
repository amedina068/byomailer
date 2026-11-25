import { describe, expect, it } from 'vitest';

import { hasUnsubscribeLink, needsUnsubscribeWarning } from '@/lib/compliance';

describe('hasUnsubscribeLink', () => {
    it('finds the placeholder in an href', () => {
        expect(
            hasUnsubscribeLink(
                '<p>Hi</p><a href="{{ unsubscribe_url }}">Unsubscribe</a>',
            ),
        ).toBe(true);
    });

    it('finds the placeholder without spaces', () => {
        expect(
            hasUnsubscribeLink('<a href="{{unsubscribe_url}}">Stop</a>'),
        ).toBe(true);
    });

    it('finds the placeholder in a second source, such as the text body', () => {
        expect(
            hasUnsubscribeLink(
                '<p>Hi</p>',
                'Unsubscribe: {{ unsubscribe_url }}',
            ),
        ).toBe(true);
    });

    it('returns false for content without the placeholder', () => {
        expect(
            hasUnsubscribeLink(
                '<p>Hi</p><a href="{{ preferences_url }}">Preferences</a>',
            ),
        ).toBe(false);
    });

    it('returns false for empty content', () => {
        expect(hasUnsubscribeLink('', null, undefined)).toBe(false);
    });
});

describe('needsUnsubscribeWarning', () => {
    it('is true for content without the placeholder', () => {
        expect(needsUnsubscribeWarning('<p>Hello</p>')).toBe(true);
    });

    it('is false for content with the placeholder', () => {
        expect(
            needsUnsubscribeWarning(
                '<p>Hello</p><a href="{{ unsubscribe_url }}">Stop</a>',
            ),
        ).toBe(false);
    });

    it('is false for a blank body, because the send check reports it as a problem', () => {
        expect(needsUnsubscribeWarning('<p>&nbsp;</p><p></p>')).toBe(false);
        expect(needsUnsubscribeWarning('', '  ')).toBe(false);
    });

    it('is true for an image-only body without the placeholder', () => {
        expect(
            needsUnsubscribeWarning('<img src="https://example.com/a.png">'),
        ).toBe(true);
    });

    it('is true for a text-only body without the placeholder', () => {
        expect(needsUnsubscribeWarning('', 'Hello')).toBe(true);
    });
});
