import { mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h } from 'vue';

type SesStatus = {
    enforcement_status: string | null;
    sending_enabled: boolean;
} | null;

const { page } = vi.hoisted(() => ({
    page: {
        url: '/dashboard',
        props: {
            sesAccountStatus: {
                enforcement_status: 'PROBATION',
                sending_enabled: true,
            } as SesStatus,
        },
    },
}));

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => page,
    Link: defineComponent({
        props: { href: String },
        setup:
            (props, { slots }) =>
            () =>
                h('a', { href: props.href }, slots.default?.()),
    }),
}));
vi.mock('@/components/AppSidebar.vue', () => ({
    default: defineComponent({ render: () => null }),
}));
vi.mock('@/components/AppSidebarHeader.vue', () => ({
    default: defineComponent({ render: () => null }),
}));
vi.mock('@/routes/reputation', () => ({
    show: () => ({ url: '/reputation' }),
}));

import AppSidebarLayout from '../AppSidebarLayout.vue';

const passThrough = { template: '<div><slot /></div>' };
const mountLayout = () =>
    mount(AppSidebarLayout, {
        global: {
            stubs: {
                AppShell: passThrough,
                AppContent: passThrough,
                AppSidebar: true,
                AppSidebarHeader: true,
            },
        },
    });

afterEach(() => {
    sessionStorage.clear();
    page.url = '/dashboard';
    page.props.sesAccountStatus = {
        enforcement_status: 'PROBATION',
        sending_enabled: true,
    };
});

describe('AppSidebarLayout banners', () => {
    it('renders the SES banner', () => {
        const text = mountLayout().text();

        expect(text).toContain('under review');
        expect(text).not.toContain('Choose a plan');
    });

    it('hides the SES banner on the reputation page', () => {
        page.url = '/reputation';

        expect(mountLayout().text()).not.toContain('under review');
    });

    it('lets a review banner be dismissed', async () => {
        const wrapper = mountLayout();
        await wrapper
            .get('button[aria-label="Hide this message"]')
            .trigger('click');

        expect(wrapper.text()).not.toContain('under review');
        expect(mountLayout().text()).not.toContain('under review');
    });

    it('keeps a paused banner without a dismiss control', () => {
        page.props.sesAccountStatus = {
            enforcement_status: 'SHUTDOWN',
            sending_enabled: true,
        };
        const wrapper = mountLayout();

        expect(wrapper.text()).toContain('paused sending');
        expect(
            wrapper.find('button[aria-label="Hide this message"]').exists(),
        ).toBe(false);
    });
});
