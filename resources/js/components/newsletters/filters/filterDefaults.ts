import type {
    AttributeFilter,
    AttributeOperator,
    DateFilter,
    DateOperator,
    EngagementFilter,
    EngagementOperator,
    FilterCategory,
    ListFilter,
    LocationFilter,
    NewsletterFilter,
    TagFilter,
    TopicFilter,
} from '@/types/newsletters';

/** Shared input styling so every editor component matches the existing form. */
export const FIELD_CLASS =
    'rounded-lg border border-border bg-background px-3 py-2 text-sm text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none';

export const ATTRIBUTE_OPERATOR_OPTIONS: {
    value: AttributeOperator;
    label: string;
}[] = [
    { value: 'equals', label: 'Equals' },
    { value: 'not_equals', label: 'Does not equal' },
    { value: 'contains', label: 'Contains' },
    { value: 'not_contains', label: 'Does not contain' },
    { value: 'gt', label: 'Greater than' },
    { value: 'lt', label: 'Less than' },
    { value: 'is_set', label: 'Is set' },
    { value: 'is_not_set', label: 'Is not set' },
];

export const DATE_OPERATOR_OPTIONS: { value: DateOperator; label: string }[] = [
    { value: 'before', label: 'Before a date' },
    { value: 'after', label: 'After a date' },
    { value: 'in_last', label: 'In the last N days' },
    { value: 'more_than', label: 'More than N days ago' },
    { value: 'between', label: 'Between two dates' },
];

export const ENGAGEMENT_OPERATOR_OPTIONS: {
    value: EngagementOperator;
    label: string;
}[] = [
    { value: 'opened', label: 'Opened' },
    { value: 'clicked', label: 'Clicked' },
    { value: 'received', label: 'Received' },
    { value: 'not_opened', label: 'Did not open' },
    { value: 'not_clicked', label: 'Did not click' },
    { value: 'not_received', label: 'Did not receive' },
];

/** Category options surfaced in the category <select>. */
export const CATEGORY_OPTIONS: { value: FilterCategory; label: string }[] = [
    { value: 'lists', label: 'Lists' },
    { value: 'topics', label: 'Topics' },
    { value: 'attributes', label: 'Attributes' },
    { value: 'tags', label: 'Tags' },
    { value: 'date', label: 'Contact created date' },
    { value: 'engagement', label: 'Engagement' },
    { value: 'location', label: 'Location' },
];

/** Categories whose UI shows the all/any/none "Match mode" select. */
export const MODE_CATEGORIES: FilterCategory[] = [
    'attributes',
    'tags',
    'lists',
    'topics',
];

const attributeDefault = (
    connector: NewsletterFilter['connector'],
): AttributeFilter => ({
    connector,
    category: 'attributes',
    mode: 'all',
    values: [{ key: '', operator: 'equals', value: '' }],
});

const tagDefault = (connector: NewsletterFilter['connector']): TagFilter => ({
    connector,
    category: 'tags',
    mode: 'any',
    values: [''],
});

const listDefault = (connector: NewsletterFilter['connector']): ListFilter => ({
    connector,
    category: 'lists',
    mode: 'any',
    values: [0],
});

const topicDefault = (
    connector: NewsletterFilter['connector'],
): TopicFilter => ({
    connector,
    category: 'topics',
    mode: 'any',
    values: [0],
});

const dateDefault = (connector: NewsletterFilter['connector']): DateFilter => ({
    connector,
    category: 'date',
    operator: 'before',
    values: { date: '' },
});

const engagementDefault = (
    connector: NewsletterFilter['connector'],
): EngagementFilter => ({
    connector,
    category: 'engagement',
    operator: 'opened',
    values: { newsletter_id: null, within: 'ever', days: null },
});

const locationDefault = (
    connector: NewsletterFilter['connector'],
): LocationFilter => ({
    connector,
    category: 'location',
    field: 'country',
    mode: 'any',
    values: [],
});

/** Builds a fresh, contract-correct filter for the chosen category. */
export const buildDefaultFilter = (
    category: FilterCategory,
    connector: NewsletterFilter['connector'] = 'and',
): NewsletterFilter => {
    switch (category) {
        case 'attributes':
            return attributeDefault(connector);
        case 'tags':
            return tagDefault(connector);
        case 'lists':
            return listDefault(connector);
        case 'topics':
            return topicDefault(connector);
        case 'date':
            return dateDefault(connector);
        case 'engagement':
            return engagementDefault(connector);
        case 'location':
            return locationDefault(connector);
        default:
            return attributeDefault(connector);
    }
};

export type FiltersContext = 'newsletter' | 'sequence' | 'segment';

/** Help text and empty-state text for each screen that edits filters. */
const FILTERS_COPY: Record<FiltersContext, { help: string; empty: string }> = {
    newsletter: {
        help: 'Add filters to define which contacts will receive the newsletter. At least one list filter is required.',
        empty: 'No filters applied. Add at least one list filter to define which contacts will receive the newsletter.',
    },
    sequence: {
        help: 'Add filters to control which contacts remain in this sequence. Contacts that no longer match will be removed.',
        empty: 'No filters applied. All your active contacts will be included.',
    },
    segment: {
        help: 'Add filters to choose the contacts in this segment. A segment needs at least one filter.',
        empty: 'This segment has no filters. Add a filter to choose its contacts.',
    },
};

export const filtersCopy = (
    context: FiltersContext | undefined,
    requireListFilter: boolean,
): { help: string; empty: string } =>
    FILTERS_COPY[context ?? (requireListFilter ? 'newsletter' : 'sequence')];
