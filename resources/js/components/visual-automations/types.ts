export type TriggerType =
    | 'contact_added_to_list'
    | 'removed_from_list'
    | 'tag_added'
    | 'removed_tag'
    | 'added_to_sequence'
    | 'sequence_finished'
    | 'link_clicked';

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

export interface SelectOption {
    id: number;
    name: string;
    status?: string;
}

export const TRIGGER_LABELS: Record<TriggerType, string> = {
    contact_added_to_list: 'Contact added to list',
    removed_from_list: 'Removed from list',
    tag_added: 'Tag added',
    removed_tag: 'Tag removed',
    added_to_sequence: 'Added to sequence',
    sequence_finished: 'Sequence finished',
    link_clicked: 'Link clicked',
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
