import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';

import AppToaster from '../AppToaster.vue';

describe('AppToaster', () => {
    it('puts the toasts in a polite live region', () => {
        mount(AppToaster, { attachTo: document.body });

        const region = document.body.querySelector('[role="status"]');
        expect(region).not.toBeNull();
        expect(region?.getAttribute('aria-live')).toBe('polite');
    });
});
