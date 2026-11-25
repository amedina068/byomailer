<script setup lang="ts">
import AutomationRuleList from './AutomationRuleList.vue';
import type { AutomationRule, ResourceCatalogs } from './types';

const props = defineProps<{
    catalogs: ResourceCatalogs;
    disabled: boolean;
    errors: Record<string, string | undefined>;
}>();

const goals = defineModel<AutomationRule[]>('goals', { required: true });
const exitConditions = defineModel<AutomationRule[]>('exitConditions', {
    required: true,
});

const errorFor = (key: string): string | undefined =>
    Object.entries(props.errors).find(([field]) => field.startsWith(key))?.[1];
</script>

<template>
    <div
        class="grid gap-6 rounded-md border border-border p-5 md:grid-cols-2"
        data-test="automation-rules"
    >
        <div class="md:col-span-2">
            <h2 class="text-base font-semibold text-foreground">
                Goals and exit conditions
            </h2>
            <p class="text-sm text-muted-foreground">
                The automation checks these rules before each step and every
                minute. A contact that meets a goal or an exit condition leaves
                the automation and gets no more steps. If a contact meets both,
                the goal wins.
            </p>
            <p
                v-if="disabled"
                class="mt-1 text-xs text-muted-foreground"
                data-test="rules-locked"
            >
                Pause the automation to edit the goals and exit conditions.
            </p>
        </div>

        <AutomationRuleList
            v-model="goals"
            label="Goals"
            description="A contact that meets one goal leaves as converted."
            empty-text="No goals. Contacts leave at the end of the workflow."
            :catalogs="catalogs"
            :disabled="disabled"
            :error="errorFor('workflow.goals')"
        />

        <AutomationRuleList
            v-model="exitConditions"
            label="Exit conditions"
            description="A contact that meets one exit condition leaves."
            empty-text="No exit conditions."
            :catalogs="catalogs"
            :disabled="disabled"
            :error="errorFor('workflow.exit_conditions')"
        />
    </div>
</template>
