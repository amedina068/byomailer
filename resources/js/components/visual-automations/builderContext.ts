import type { InjectionKey } from 'vue';

import type { SelectOption } from './types';

export interface AddStepContext {
    sourceId: string;
    sourceHandle: string | null;
    /** When inserting between nodes, the target edge ID to splice into */
    insertOnEdgeId?: string;
}

export interface BuilderContext {
    readonly lists: SelectOption[];
    readonly tags: SelectOption[];
    readonly topics?: SelectOption[];
    readonly sequences: SelectOption[];
    isEditable: boolean;
    nodeContactsUrl: ((nodeId: string) => string) | null;
    updateNodeData: (
        nodeId: string,
        updates: Record<string, unknown>,
    ) => void;
    confirmRemoveNode: (nodeId: string) => void;
    openAddStepModal: (context: AddStepContext) => void;
}

export const builderKey: InjectionKey<BuilderContext> = Symbol('builder');
