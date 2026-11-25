import {
    RELATIVE_DATE_OPERATORS,
    VALUELESS_ATTRIBUTE_OPERATORS,
    type AttributeFilter,
    type AttributeOperator,
    type DateFilter,
    type DateOperator,
    type EngagementFilter,
    type EngagementOperator,
    type ListFilter,
    type LocationFilter,
    type NewsletterFilter,
    type TagFilter,
    type TopicFilter,
} from '@/types/newsletters';

export interface FilterSentenceContext {
    lists: { id: number; name: string }[];
    topics: { id: number; name: string; list_name: string | null }[];
    newsletters: { id: number; title: string }[];
}

const NOT_CONFIGURED = '(not configured)';

const ATTRIBUTE_OPERATOR_LABELS: Record<AttributeOperator, string> = {
    equals: 'is',
    not_equals: 'is not',
    contains: 'contains',
    not_contains: "doesn't contain",
    gt: 'is greater than',
    lt: 'is less than',
    is_set: 'is set',
    is_not_set: 'is not set',
};

const DATE_OPERATOR_LABELS: Record<DateOperator, string> = {
    before: 'is before',
    after: 'is after',
    in_last: 'in the last',
    more_than: 'more than',
    between: 'is between',
};

const ENGAGEMENT_VERB_LABELS: Record<EngagementOperator, string> = {
    opened: 'opened',
    clicked: 'clicked',
    received: 'received',
    not_opened: "didn't open",
    not_clicked: "didn't click",
    not_received: "didn't receive",
};

const isAttributeFilter = (f: NewsletterFilter): f is AttributeFilter =>
    f.category === 'attributes';
const isTagFilter = (f: NewsletterFilter): f is TagFilter =>
    f.category === 'tags';
const isListFilter = (f: NewsletterFilter): f is ListFilter =>
    f.category === 'lists';
const isTopicFilter = (f: NewsletterFilter): f is TopicFilter =>
    f.category === 'topics';
const isDateFilter = (f: NewsletterFilter): f is DateFilter =>
    f.category === 'date';
const isEngagementFilter = (f: NewsletterFilter): f is EngagementFilter =>
    f.category === 'engagement';
const isLocationFilter = (f: NewsletterFilter): f is LocationFilter =>
    f.category === 'location';

const attributeSentence = (filter: AttributeFilter): string => {
    const configured = filter.values.filter((v) => v.key);

    if (configured.length === 0) {
        return `Contact attribute ${NOT_CONFIGURED}`;
    }

    const describePair = (pair: AttributeFilter['values'][number]): string => {
        const operatorLabel = ATTRIBUTE_OPERATOR_LABELS[pair.operator] ?? 'is';

        if (VALUELESS_ATTRIBUTE_OPERATORS.includes(pair.operator)) {
            return `${pair.key} ${operatorLabel}`;
        }

        return `${pair.key} ${operatorLabel} ${pair.value || '(any value)'}`;
    };

    if (configured.length === 1) {
        return `Contact attribute ${describePair(configured[0])}`;
    }

    const modeText: Record<string, string> = {
        all: 'matches all of',
        any: 'matches any of',
        none: "doesn't match any of",
    };
    const pairs = configured.map(describePair).join('; ');

    return `Contact attribute ${modeText[filter.mode] ?? 'matches'}: ${pairs}`;
};

const tagSentence = (filter: TagFilter): string => {
    const tagNames = filter.values.filter(Boolean).join(', ') || NOT_CONFIGURED;
    const modeText: Record<string, string> = {
        all: 'match all',
        any: 'match any of',
        none: "don't match any",
    };

    return `Contact tags ${modeText[filter.mode] ?? 'match'}: ${tagNames}`;
};

const listSentence = (
    filter: ListFilter,
    context: FilterSentenceContext,
): string => {
    const listNames =
        filter.values
            .filter((id) => id > 0)
            .map(
                (id) =>
                    context.lists.find((l) => l.id === id)?.name ??
                    `List #${id}`,
            )
            .join(', ') || NOT_CONFIGURED;
    const modeText: Record<string, string> = {
        all: 'match all',
        any: 'match any of',
        none: "don't match any",
    };

    return `Contact lists ${modeText[filter.mode] ?? 'match'}: ${listNames}`;
};

const topicSentence = (
    filter: TopicFilter,
    context: FilterSentenceContext,
): string => {
    const topicNames =
        filter.values
            .filter((id) => id > 0)
            .map((id) => {
                const topic = context.topics.find((t) => t.id === id);
                return topic
                    ? `${topic.name}${topic.list_name ? ` (${topic.list_name})` : ''}`
                    : `Topic #${id}`;
            })
            .join(', ') || NOT_CONFIGURED;
    const modeText: Record<string, string> = {
        all: 'subscribed to all',
        any: 'subscribed to any of',
        none: 'not subscribed to any of',
    };

    return `Contact ${modeText[filter.mode] ?? 'subscribed to'}: ${topicNames}`;
};

const dateSentence = (filter: DateFilter): string => {
    const operatorLabel = DATE_OPERATOR_LABELS[filter.operator] ?? filter.operator;

    if (RELATIVE_DATE_OPERATORS.includes(filter.operator)) {
        const days = filter.values.days;

        if (typeof days !== 'number' || days <= 0) {
            return `Contact created date ${NOT_CONFIGURED}`;
        }

        const dayWord = days === 1 ? 'day' : 'days';

        if (filter.operator === 'in_last') {
            return `Contact created in the last ${days} ${dayWord}`;
        }

        return `Contact created more than ${days} ${dayWord} ago`;
    }

    if (filter.operator === 'between') {
        const { from, to } = filter.values;

        if (!from || !to) {
            return `Contact created date ${NOT_CONFIGURED}`;
        }

        return `Contact created date is between ${from} and ${to}`;
    }

    const date = filter.values.date;

    if (!date) {
        return `Contact created date ${NOT_CONFIGURED}`;
    }

    return `Contact created date ${operatorLabel} ${date}`;
};

const engagementSentence = (
    filter: EngagementFilter,
    context: FilterSentenceContext,
): string => {
    const verb = ENGAGEMENT_VERB_LABELS[filter.operator] ?? filter.operator;
    const newsletterId = filter.values.newsletter_id;
    const target =
        newsletterId === null
            ? 'any newsletter'
            : (context.newsletters.find((n) => n.id === newsletterId)?.title ??
              `newsletter #${newsletterId}`);

    let timeText = '';

    if (filter.values.within === 'last_n_days') {
        const days = filter.values.days;

        if (typeof days === 'number' && days > 0) {
            const dayWord = days === 1 ? 'day' : 'days';
            timeText = ` in the last ${days} ${dayWord}`;
        }
    }

    return `Contact ${verb} ${target}${timeText}`;
};

const locationSentence = (filter: LocationFilter): string => {
    const fieldLabel = filter.field === 'country' ? 'country' : 'timezone';
    const values = filter.values.filter(Boolean).join(', ') || NOT_CONFIGURED;
    const modeText = filter.mode === 'none' ? 'is none of' : 'is any of';

    return `Contact ${fieldLabel} ${modeText}: ${values}`;
};

/** Renders a single filter as a human-readable clause (no connector prefix). */
export const filterClause = (
    filter: NewsletterFilter,
    context: FilterSentenceContext,
): string => {
    if (isAttributeFilter(filter)) {
        return attributeSentence(filter);
    }

    if (isTagFilter(filter)) {
        return tagSentence(filter);
    }

    if (isListFilter(filter)) {
        return listSentence(filter, context);
    }

    if (isTopicFilter(filter)) {
        return topicSentence(filter, context);
    }

    if (isDateFilter(filter)) {
        return dateSentence(filter);
    }

    if (isEngagementFilter(filter)) {
        return engagementSentence(filter, context);
    }

    if (isLocationFilter(filter)) {
        return locationSentence(filter);
    }

    return 'Unknown filter';
};

/** Renders a filter row with its leading AND/OR connector (omitted for index 0). */
export const filterToSentence = (
    filter: NewsletterFilter,
    index: number,
    context: FilterSentenceContext,
): string => {
    const prefix = index === 0 ? '' : `${filter.connector.toUpperCase()} `;

    return `${prefix}${filterClause(filter, context)}`;
};
