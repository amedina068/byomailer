import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, ref } from 'vue';

const { visit, stub } = vi.hoisted(() => ({
    visit: vi.fn(),
    stub: { template: '<div />' },
}));

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    Head: defineComponent({ template: '<span />' }),
    router: { visit, post: vi.fn() },
    usePage: () => ({ props: { auth: { timezone: 'UTC' } } }),
}));
vi.mock('@/layouts/AppLayout.vue', () => ({
    default: defineComponent({ template: '<main><slot /></main>' }),
}));
vi.mock('@/components/ui/dialog', () => {
    const passThrough = defineComponent({ template: '<div><slot /></div>' });

    return {
        Dialog: defineComponent({
            props: { open: Boolean },
            template: '<div v-if="open" data-test="dialog"><slot /></div>',
        }),
        DialogClose: passThrough,
        DialogContent: passThrough,
        DialogDescription: passThrough,
        DialogFooter: passThrough,
        DialogHeader: passThrough,
        DialogTitle: passThrough,
    };
});
vi.mock('@/composables/useAudienceCount', () => ({
    useAudienceCount: () => ({
        count: ref(42),
        loading: ref(false),
        fetch: vi.fn(),
        schedule: vi.fn(),
    }),
}));

vi.mock('@/components/TiptapEditor.vue', () => ({ default: stub }));
vi.mock('@/components/ComplianceWarnings.vue', () => ({ default: stub }));
vi.mock('@/components/newsletters/TargetingForm.vue', () => ({
    default: stub,
}));
vi.mock('@/components/templates/TemplatePickerModal.vue', () => ({
    default: stub,
}));
vi.mock('@/components/projects/ProjectMultiSelect.vue', () => ({
    default: stub,
}));

import Create from '../newsletters/Create.vue';

const mountWizard = (initialStep: 'content' | 'schedule') =>
    mount(Create, {
        attachTo: document.body,
        props: {
            lists: [],
            segments: [],
            emailIdentities: [{ id: 3, email: 'ana@example.com', name: 'Ana' }],
            tags: [],
            contactAttributes: [],
            initialStep,
            draftNewsletter: {
                id: 5,
                title: 'April update',
                status: 'draft',
                subject: 'Hello',
                html_content: '<p>Hi</p>',
                email_identity_id: 3,
                filters: [],
            },
        },
    });

type Wrapper = ReturnType<typeof mountWizard>;

const stepButton = (wrapper: Wrapper, title: string) =>
    wrapper.findAll('button').find((button) => button.text().endsWith(title))!;

const dialogButton = (wrapper: Wrapper, label: string) =>
    wrapper
        .find('[data-test="dialog"]')
        .findAll('button')
        .find((button) => button.text() === label)!;

describe('Newsletter wizard', () => {
    beforeEach(() => {
        visit.mockReset();
    });

    it('opens another step at once when the step has no unsaved changes', async () => {
        const wrapper = mountWizard('content');

        await stepButton(wrapper, 'Audience').trigger('click');

        expect(visit).toHaveBeenCalledWith('/newsletters/5/filters');
    });

    it('asks before it leaves a step with unsaved changes', async () => {
        const wrapper = mountWizard('content');

        await wrapper.find('#subject').setValue('Hello again');
        await stepButton(wrapper, 'Audience').trigger('click');

        expect(visit).not.toHaveBeenCalled();
        expect(wrapper.find('[data-test="dialog"]').text()).toContain(
            'Leave without saving?',
        );

        await dialogButton(wrapper, 'Stay').trigger('click');
        expect(visit).not.toHaveBeenCalled();
        expect(
            (wrapper.find('#subject').element as HTMLInputElement).value,
        ).toBe('Hello again');

        await stepButton(wrapper, 'Audience').trigger('click');
        await dialogButton(wrapper, 'Leave').trigger('click');
        expect(visit).toHaveBeenCalledWith('/newsletters/5/filters');
    });

    it('asks for a confirmation before it schedules a send', async () => {
        const wrapper = mountWizard('schedule');

        await wrapper.find('#scheduled_for').setValue('2026-12-01T09:30');
        await wrapper
            .findAll('button')
            .find((button) => button.text() === 'Schedule send')!
            .trigger('click');

        const dialog = wrapper.find('[data-test="dialog"]');

        expect(dialog.text()).toContain('Schedule this newsletter?');
        expect(dialog.text()).toContain('42');
        expect(dialog.text()).toContain('from Ana <ana@example.com>');
        expect(dialog.text()).toMatch(/2026-12-01 09:30 \(UTC.00:00 UTC\)/);
    });
});
