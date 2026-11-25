export type FilterConnector = 'and' | 'or';

export type FilterMode = 'all' | 'any' | 'none';

export type FilterCategory =
    | 'attributes'
    | 'tags'
    | 'lists'
    | 'topics'
    | 'date'
    | 'engagement'
    | 'location';

export type AttributeOperator =
    | 'equals'
    | 'not_equals'
    | 'contains'
    | 'not_contains'
    | 'gt'
    | 'lt'
    | 'is_set'
    | 'is_not_set';

/** Attribute operators that ignore the value field. */
export const VALUELESS_ATTRIBUTE_OPERATORS: AttributeOperator[] = [
    'is_set',
    'is_not_set',
];

export interface AttributeValue {
    key: string;
    operator: AttributeOperator;
    value: string;
}

export interface AttributeFilter {
    connector: FilterConnector;
    category: 'attributes';
    mode: FilterMode;
    values: AttributeValue[];
}

export interface TagFilter {
    connector: FilterConnector;
    category: 'tags';
    mode: FilterMode;
    values: string[];
}

export interface ListFilter {
    connector: FilterConnector;
    category: 'lists';
    mode: FilterMode;
    values: number[];
}

export interface TopicFilter {
    connector: FilterConnector;
    category: 'topics';
    mode: FilterMode;
    values: number[];
}

export type DateOperator =
    | 'before'
    | 'after'
    | 'in_last'
    | 'more_than'
    | 'between';

/** Date operators backed by an N-days input rather than calendar dates. */
export const RELATIVE_DATE_OPERATORS: DateOperator[] = ['in_last', 'more_than'];

export interface DateFilterValues {
    date?: string;
    days?: number;
    from?: string;
    to?: string;
}

export interface DateFilter {
    connector: FilterConnector;
    category: 'date';
    operator: DateOperator;
    values: DateFilterValues;
}

export type EngagementOperator =
    | 'opened'
    | 'clicked'
    | 'received'
    | 'not_opened'
    | 'not_clicked'
    | 'not_received';

export type EngagementWindow = 'ever' | 'last_n_days';

export interface EngagementFilterValues {
    newsletter_id: number | null;
    within: EngagementWindow;
    days: number | null;
}

export interface EngagementFilter {
    connector: FilterConnector;
    category: 'engagement';
    operator: EngagementOperator;
    values: EngagementFilterValues;
}

export type LocationField = 'country' | 'timezone';

export type LocationMode = 'any' | 'none';

export interface LocationFilter {
    connector: FilterConnector;
    category: 'location';
    field: LocationField;
    mode: LocationMode;
    values: string[];
}

export type NewsletterFilter =
    | AttributeFilter
    | TagFilter
    | ListFilter
    | TopicFilter
    | DateFilter
    | EngagementFilter
    | LocationFilter;

export interface Segment {
    id: number;
    name: string;
    description: string | null;
    filters: NewsletterFilter[];
}

/** Operators allowed per category — mirrors App\Support\Filters\FilterSchema. */
export const ATTRIBUTE_OPERATORS: AttributeOperator[] = [
    'equals',
    'not_equals',
    'contains',
    'not_contains',
    'gt',
    'lt',
    'is_set',
    'is_not_set',
];

export const DATE_OPERATORS: DateOperator[] = [
    'before',
    'after',
    'in_last',
    'more_than',
    'between',
];

export const ENGAGEMENT_OPERATORS: EngagementOperator[] = [
    'opened',
    'clicked',
    'received',
    'not_opened',
    'not_clicked',
    'not_received',
];

/** One stored newsletter status and its label, from the `newsletterStatuses` prop. */
export interface NewsletterStatusOption {
    value: string;
    label: string;
}
