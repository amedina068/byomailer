import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { defineComponent } from 'vue';

vi.mock('@/components/ui/dialog', () => {
    const passThrough = defineComponent({ template: '<div><slot /></div>' });

    return {
        Dialog: passThrough,
        DialogClose: passThrough,
        DialogContent: passThrough,
        DialogDescription: passThrough,
        DialogFooter: passThrough,
        DialogHeader: passThrough,
        DialogTitle: passThrough,
    };
});

import ConfirmStatusToggleModal from '../ConfirmStatusToggleModal.vue';

const render = (
    isLive: boolean,
    impact?: { enrolled_contacts: number; pending_mails_due_now: number },
) =>
    mount(ConfirmStatusToggleModal, {
        props: { isOpen: true, isLive, label: 'step', impact },
    });

describe('Sequence status modal', () => {
    it('says that a pause keeps the due emails for the resume', () => {
        const text = render(true).text();

        expect(text).toContain(
            'Emails that come due wait, and they go out when you activate it again.',
        );
        expect(text).not.toContain('stop running');
    });

    it('says that a resume sends the emails that came due', () => {
        expect(render(false).text()).toContain(
            'Emails that came due during a pause go out within minutes.',
        );
    });

    it('counts the emails that go out when it is activated', () => {
        const text = render(false, {
            enrolled_contacts: 12,
            pending_mails_due_now: 3,
        }).text();

        expect(text).toContain('12 contacts are in this step.');
        expect(text).toContain(
            '3 emails that came due during the pause go out within minutes.',
        );
    });

    it('uses the singular for one contact and one email', () => {
        const text = render(false, {
            enrolled_contacts: 1,
            pending_mails_due_now: 1,
        }).text();

        expect(text).toContain('1 contact is in this step.');
        expect(text).toContain(
            '1 email that came due during the pause goes out within minutes.',
        );
    });

    it('says when no emails are due', () => {
        expect(
            render(false, {
                enrolled_contacts: 0,
                pending_mails_due_now: 0,
            }).text(),
        ).toContain('No emails are due now.');
    });
});
