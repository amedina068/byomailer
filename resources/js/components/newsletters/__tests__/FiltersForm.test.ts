import { mount, type VueWrapper } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import { nextTick } from 'vue';

import type { NewsletterFilter } from '@/types/newsletters';

import FiltersForm from '../FiltersForm.vue';

const attributeFilter = (): NewsletterFilter => ({
    connector: 'and',
    category: 'attributes',
    mode: 'all',
    values: [{ key: '', operator: 'equals', value: '' }],
});

const tagFilter = (): NewsletterFilter => ({
    connector: 'and',
    category: 'tags',
    mode: 'any',
    values: ['vip'],
});

const mountForm = (props: Record<string, unknown> = {}): VueWrapper =>
    mount(FiltersForm, {
        props: {
            lists: [],
            tags: [{ id: 1, name: 'vip' }],
            attributeKeys: ['company'],
            processing: false,
            canEdit: true,
            requireListFilter: false,
            showBackButton: false,
            filters: [tagFilter(), attributeFilter()],
            ...props,
        },
    });

const buttonNamed = (wrapper: VueWrapper, label: string) =>
    wrapper.findAll('button').find((button) => button.text() === label);

const isEditing = (wrapper: VueWrapper): boolean =>
    buttonNamed(wrapper, 'Cancel') !== undefined;

describe('FiltersForm filter errors', () => {
    it('keeps the editor open and shows the error under the failed filter', async () => {
        const wrapper = mountForm();

        await buttonNamed(wrapper, 'Edit filters')!.trigger('click');
        await buttonNamed(wrapper, 'Save filters & continue')!.trigger('click');
        expect(wrapper.emitted('save')).toHaveLength(1);

        await wrapper.setProps({ processing: true });
        await wrapper.setProps({
            processing: false,
            errors: {
                'filters.1.values': 'Choose an attribute for this filter.',
            },
        });
        await nextTick();

        expect(isEditing(wrapper)).toBe(true);

        const rows = wrapper.findAll('[data-filter-row]');
        expect(rows).toHaveLength(2);
        expect(rows[0].text()).not.toContain('Choose an attribute');
        expect(rows[1].text()).toContain(
            'Choose an attribute for this filter.',
        );
    });

    it('closes the editor after a save without filter errors', async () => {
        const wrapper = mountForm();

        await buttonNamed(wrapper, 'Edit filters')!.trigger('click');
        await buttonNamed(wrapper, 'Save filters & continue')!.trigger('click');

        await wrapper.setProps({ processing: true });
        await wrapper.setProps({ processing: false, errors: {} });
        await nextTick();

        expect(isEditing(wrapper)).toBe(false);
    });

    it('opens the editor when the errors arrive for a filter row', async () => {
        const wrapper = mountForm();
        expect(isEditing(wrapper)).toBe(false);

        await wrapper.setProps({
            errors: { 'filters.0.values': 'Choose at least one tag.' },
        });
        await nextTick();

        expect(isEditing(wrapper)).toBe(true);
        expect(wrapper.findAll('[data-filter-row]')[0].text()).toContain(
            'Choose at least one tag.',
        );
    });

    it('shows the row errors in view mode when the user cannot edit', () => {
        const wrapper = mountForm({
            canEdit: false,
            errors: {
                'filters.1.values': 'Choose an attribute for this filter.',
            },
        });

        const rows = wrapper.findAll('[data-filter-row]');
        expect(rows).toHaveLength(2);
        expect(rows[1].text()).toContain(
            'Choose an attribute for this filter.',
        );
    });

    it('shows the top-level message from the errors object or the old prop', async () => {
        const wrapper = mountForm({
            filters: [],
            errors: { filters: 'A segment needs at least one filter.' },
        });
        expect(wrapper.text()).toContain(
            'A segment needs at least one filter.',
        );

        await wrapper.setProps({
            errors: {},
            filtersError: 'Old prop message.',
        });
        expect(wrapper.text()).toContain('Old prop message.');
    });
});

describe('FiltersForm copy', () => {
    it('tells the user that a segment needs at least one filter', () => {
        const text = mountForm({ filters: [], context: 'segment' }).text();

        expect(text).toContain('A segment needs at least one filter.');
        expect(text).not.toContain('remain in this sequence');
        expect(text).not.toContain('All your active contacts will be included');
    });

    it('keeps the sequence copy when no context is given', () => {
        const text = mountForm({ filters: [] }).text();

        expect(text).toContain('remain in this');
        expect(text).toContain('All your active contacts will be included');
    });
});
