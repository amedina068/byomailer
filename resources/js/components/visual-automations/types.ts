import type { WorkflowEdge, WorkflowNode } from './layoutWorkflow';

export type TriggerType =
    | 'contact_added_to_list'
    | 'removed_from_list'
    | 'tag_added'
    | 'removed_tag'
    | 'added_to_sequence'
    | 'sequence_finished';

export type ActionType =
    | 'add_tag'
    | 'remove_tag'
    | 'add_list'
    | 'remove_list'
    | 'add_sequence'
    | 'unsubscribe_contact';

export type ConditionBranchType =
    | 'has_tag'
    | 'not_has_tag'
    | 'in_list'
    | 'not_in_list'
    | 'subscribed_to_topic'
    | 'not_subscribed_to_topic'
    | 'in_sequence'
    | 'not_in_sequence';

export type WorkflowNodeType = 'trigger' | 'action' | 'delay' | 'condition';

/** The condition types that a goal or an exit condition can use. */
export type RuleType =
    | 'has_tag'
    | 'in_list'
    | 'subscribed_to_topic'
    | 'in_sequence';

/** A goal or an exit condition of an automation. */
export interface AutomationRule {
    type: RuleType;
    value: number | null;
}

/** The workflow of a visual automation, as the server stores it. */
export interface AutomationWorkflow {
    nodes: WorkflowNode[];
    edges: WorkflowEdge[];
    goals?: AutomationRule[] | null;
    exit_conditions?: AutomationRule[] | null;
}

export interface SelectOption {
    id: number;
    name: string;
    status?: string;
}

export interface ResourceCatalogs {
    readonly tags: SelectOption[];
    readonly lists: SelectOption[];
    readonly topics?: SelectOption[];
    readonly sequences: SelectOption[];
}

/** The tags, lists, topics or sequences that a condition type can select. */
export const optionsForConditionType = (
    type: ConditionBranchType,
    catalogs: ResourceCatalogs,
): SelectOption[] => {
    switch (type) {
        case 'has_tag':
        case 'not_has_tag':
            return catalogs.tags;
        case 'in_list':
        case 'not_in_list':
            return catalogs.lists;
        case 'subscribed_to_topic':
        case 'not_subscribed_to_topic':
            return catalogs.topics ?? [];
        case 'in_sequence':
        case 'not_in_sequence':
            return catalogs.sequences;
        default:
            return [];
    }
};

/** The label of a tag, list or sequence. A topic also names its list. */
export const optionLabel = (option: SelectOption): string => {
    const topic = option as SelectOption & { list_name?: string | null };

    return topic.list_name
        ? `${option.name} (${topic.list_name})`
        : option.name;
};

export const TRIGGER_LABELS: Record<TriggerType, string> = {
    contact_added_to_list: 'Contact added to list',
    removed_from_list: 'Removed from list',
    tag_added: 'Tag added',
    removed_tag: 'Tag removed',
    added_to_sequence: 'Added to sequence',
    sequence_finished: 'Sequence finished',
};

export const ACTION_LABELS: Record<ActionType, string> = {
    add_tag: 'Add tag',
    remove_tag: 'Remove tag',
    add_list: 'Add to list',
    remove_list: 'Remove from list',
    add_sequence: 'Add to sequence',
    unsubscribe_contact: 'Unsubscribe contact',
};

export const CONDITION_LABELS: Record<ConditionBranchType, string> = {
    has_tag: 'Has tag',
    not_has_tag: 'Does not have tag',
    in_list: 'In list',
    not_in_list: 'Not in list',
    subscribed_to_topic: 'Subscribed to topic',
    not_subscribed_to_topic: 'Not subscribed to topic',
    in_sequence: 'In sequence',
    not_in_sequence: 'Not in sequence',
};

export const RULE_LABELS: Record<RuleType, string> = {
    has_tag: 'Has tag',
    in_list: 'In list',
    subscribed_to_topic: 'Subscribed to topic',
    in_sequence: 'In sequence',
};
