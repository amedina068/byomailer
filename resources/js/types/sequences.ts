/** One stored sequence status and its label, from the `sequenceStatuses` prop. Sequences and steps use the same statuses. */
export interface SequenceStatusOption {
    value: string;
    label: string;
}

/** The label of a sequence or step status. An unknown status shows its value. */
export function sequenceStatusLabel(
    statuses: SequenceStatusOption[],
    status: string,
): string {
    return statuses.find((option) => option.value === status)?.label ?? status;
}
