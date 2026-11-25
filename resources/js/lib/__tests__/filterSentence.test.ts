import { describe, expect, it } from 'vitest';

import type {
    AttributeFilter,
    DateFilter,
    EngagementFilter,
    ListFilter,
    LocationFilter,
    TagFilter,
    TopicFilter,
} from '@/types/newsletters';

import {
    filterClause,
    filterToSentence,
    type FilterSentenceContext,
} from '../filterSentence';

const context: FilterSentenceContext = {
    lists: [
        { id: 1, name: 'Customers' },
        { id: 2, name: 'Leads' },
    ],
    topics: [{ id: 5, name: 'Product', list_name: 'Customers' }],
    newsletters: [
        { id: 10, title: 'March Update' },
        { id: 11, title: 'Welcome' },
    ],
};

describe('filterClause — attributes', () => {
    it('renders a single attribute rule with its operator', () => {
        const filter: AttributeFilter = {
            connector: 'and',
            category: 'attributes',
            mode: 'all',
            values: [{ key: 'plan', operator: 'equals', value: 'pro' }],
        };

        expect(filterClause(filter, context)).toBe(
            'Contact attribute plan is pro',
        );
    });

    it('hides the value for valueless operators', () => {
        const filter: AttributeFilter = {
            connector: 'and',
            category: 'attributes',
            mode: 'all',
            values: [{ key: 'phone', operator: 'is_set', value: '' }],
        };

        expect(filterClause(filter, context)).toBe(
            'Contact attribute phone is set',
        );
    });

    it('joins multiple rules with the mode label', () => {
        const filter: AttributeFilter = {
            connector: 'and',
            category: 'attributes',
            mode: 'any',
            values: [
                { key: 'plan', operator: 'equals', value: 'pro' },
                { key: 'age', operator: 'gt', value: '18' },
            ],
        };

        expect(filterClause(filter, context)).toBe(
            'Contact attribute matches any of: plan is pro; age is greater than 18',
        );
    });
});

describe('filterClause — tags / lists / topics', () => {
    it('renders tag names', () => {
        const filter: TagFilter = {
            connector: 'and',
            category: 'tags',
            mode: 'any',
            values: ['vip', 'beta'],
        };

        expect(filterClause(filter, context)).toBe(
            'Contact tags match any of: vip, beta',
        );
    });

    it('resolves list names from context', () => {
        const filter: ListFilter = {
            connector: 'and',
            category: 'lists',
            mode: 'all',
            values: [1, 2],
        };

        expect(filterClause(filter, context)).toBe(
            'Contact lists match all: Customers, Leads',
        );
    });

    it('resolves topic names with list label', () => {
        const filter: TopicFilter = {
            connector: 'and',
            category: 'topics',
            mode: 'any',
            values: [5],
        };

        expect(filterClause(filter, context)).toBe(
            'Contact subscribed to any of: Product (Customers)',
        );
    });
});

describe('filterClause — date', () => {
    it('renders a before-date clause', () => {
        const filter: DateFilter = {
            connector: 'and',
            category: 'date',
            operator: 'before',
            values: { date: '2026-03-01' },
        };

        expect(filterClause(filter, context)).toBe(
            'Contact created date is before 2026-03-01',
        );
    });

    it('renders a relative in_last clause', () => {
        const filter: DateFilter = {
            connector: 'and',
            category: 'date',
            operator: 'in_last',
            values: { days: 30 },
        };

        expect(filterClause(filter, context)).toBe(
            'Contact created in the last 30 days',
        );
    });

    it('renders a more_than clause', () => {
        const filter: DateFilter = {
            connector: 'and',
            category: 'date',
            operator: 'more_than',
            values: { days: 90 },
        };

        expect(filterClause(filter, context)).toBe(
            'Contact created more than 90 days ago',
        );
    });

    it('renders a between clause', () => {
        const filter: DateFilter = {
            connector: 'and',
            category: 'date',
            operator: 'between',
            values: { from: '2026-01-01', to: '2026-02-01' },
        };

        expect(filterClause(filter, context)).toBe(
            'Contact created date is between 2026-01-01 and 2026-02-01',
        );
    });
});

describe('filterClause — engagement', () => {
    it('renders opened any newsletter in the last N days', () => {
        const filter: EngagementFilter = {
            connector: 'and',
            category: 'engagement',
            operator: 'opened',
            values: { newsletter_id: null, within: 'last_n_days', days: 7 },
        };

        expect(filterClause(filter, context)).toBe(
            'Contact opened any newsletter in the last 7 days',
        );
    });

    it('renders a specific newsletter and negated verb', () => {
        const filter: EngagementFilter = {
            connector: 'and',
            category: 'engagement',
            operator: 'not_clicked',
            values: { newsletter_id: 10, within: 'ever', days: null },
        };

        expect(filterClause(filter, context)).toBe(
            "Contact didn't click March Update",
        );
    });
});

describe('filterClause — location', () => {
    it('renders an "is any of" country clause', () => {
        const filter: LocationFilter = {
            connector: 'and',
            category: 'location',
            field: 'country',
            mode: 'any',
            values: ['US', 'GB'],
        };

        expect(filterClause(filter, context)).toBe(
            'Contact country is any of: US, GB',
        );
    });

    it('renders an "is none of" timezone clause', () => {
        const filter: LocationFilter = {
            connector: 'and',
            category: 'location',
            field: 'timezone',
            mode: 'none',
            values: ['America/New_York'],
        };

        expect(filterClause(filter, context)).toBe(
            'Contact timezone is none of: America/New_York',
        );
    });
});

describe('filterToSentence', () => {
    it('omits the connector prefix for the first filter', () => {
        const filter: TagFilter = {
            connector: 'or',
            category: 'tags',
            mode: 'any',
            values: ['vip'],
        };

        expect(filterToSentence(filter, 0, context)).toBe(
            'Contact tags match any of: vip',
        );
    });

    it('prepends the uppercased connector for later filters', () => {
        const filter: TagFilter = {
            connector: 'or',
            category: 'tags',
            mode: 'any',
            values: ['vip'],
        };

        expect(filterToSentence(filter, 1, context)).toBe(
            'OR Contact tags match any of: vip',
        );
    });
});
