<script setup lang="ts">
import { PhPlus, PhTrash } from '@phosphor-icons/vue';
import { computed } from 'vue';

import InputError from '@/components/InputError.vue';

import {
    type AutomationRule,
    optionLabel,
    optionsForConditionType,
    type ResourceCatalogs,
    RULE_LABELS,
    type RuleType,
} from './types';

const MAX_RULES = 10;

const props = withDefaults(
    defineProps<{
        label: string;
        description: string;
        emptyText: string;
        catalogs: ResourceCatalogs;
        disabled?: boolean;
        error?: string;
    }>(),
    { disabled: false, error: undefined },
);

const rules = defineModel<AutomationRule[]>({ required: true });

const types = computed(() =>
    (Object.keys(RULE_LABELS) as RuleType[]).filter(
        (type) => optionsForConditionType(type, props.catalogs).length > 0,
    ),
);

const canAdd = computed(
    () =>
        !props.disabled &&
        types.value.length > 0 &&
        rules.value.length < MAX_RULES,
);

const ruleOf = (type: RuleType): AutomationRule => ({
    type,
    value: optionsForConditionType(type, props.catalogs)[0]?.id ?? null,
});

const add = () => {
    rules.value = [...rules.value, ruleOf(types.value[0])];
};

const replace = (index: number, rule: AutomationRule) => {
    rules.value = rules.value.map((current, i) =>
        i === index ? rule : current,
    );
};

const remove = (index: number) => {
    rules.value = rules.value.filter((_, i) => i !== index);
};

const selectValue = (event: Event): string =>
    (event.target as HTMLSelectElement).value;
</script>

<template>
    <div class="grid content-start gap-3">
        <div class="flex items-start justify-between gap-3">
            <div>
                <h3 class="text-sm font-semibold text-foreground">
                    {{ label }}
                </h3>
                <p class="text-xs text-muted-foreground">{{ description }}</p>
            </div>
            <button
                type="button"
                data-test="add-rule"
                :disabled="!canAdd"
                class="inline-flex shrink-0 items-center gap-1.5 rounded-md border border-border px-2.5 py-1.5 text-xs font-medium text-foreground transition-colors hover:border-primary hover:text-primary disabled:cursor-not-allowed disabled:opacity-50"
                @click="add"
            >
                <PhPlus class="h-3.5 w-3.5" />
                Add
            </button>
        </div>

        <p
            v-if="!rules.length"
            class="rounded-md border border-dashed border-border px-3 py-4 text-center text-xs text-muted-foreground"
        >
            {{ emptyText }}
        </p>

        <div
            v-for="(rule, index) in rules"
            :key="index"
            data-test="rule"
            class="flex items-center gap-2"
        >
            <select
                data-test="rule-type"
                :aria-label="`${label} ${index + 1} type`"
                class="min-w-0 flex-1 rounded-md border border-border bg-background px-2 py-1.5 text-sm disabled:opacity-50"
                :disabled="disabled"
                :value="rule.type"
                @change="
                    replace(index, ruleOf(selectValue($event) as RuleType))
                "
            >
                <option v-for="type in types" :key="type" :value="type">
                    {{ RULE_LABELS[type] }}
                </option>
            </select>
            <select
                data-test="rule-value"
                :aria-label="`${label} ${index + 1} value`"
                class="min-w-0 flex-1 rounded-md border border-border bg-background px-2 py-1.5 text-sm disabled:opacity-50"
                :disabled="disabled"
                :value="rule.value ?? ''"
                @change="
                    replace(index, {
                        ...rule,
                        value: Number(selectValue($event)),
                    })
                "
            >
                <option
                    v-for="option in optionsForConditionType(
                        rule.type,
                        catalogs,
                    )"
                    :key="option.id"
                    :value="option.id"
                >
                    {{ optionLabel(option) }}
                </option>
            </select>
            <button
                type="button"
                data-test="remove-rule"
                :aria-label="`Remove ${label.toLowerCase()} ${index + 1}`"
                :disabled="disabled"
                class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-md border border-border text-muted-foreground transition-colors hover:border-destructive hover:text-destructive disabled:cursor-not-allowed disabled:opacity-50"
                @click="remove(index)"
            >
                <PhTrash class="h-4 w-4" />
            </button>
        </div>

        <InputError :message="error" />
    </div>
</template>
