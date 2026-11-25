<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

interface TopicItem {
    id: number;
    name: string;
    description: string | null;
    list_name: string | null;
    subscribed: boolean;
}

const props = defineProps<{
    contactMailUuid: string;
    isTestMail?: boolean;
    contact: { uuid: string; email: string; name: string | null };
    topics: TopicItem[];
    unsubscribedFromAll?: boolean;
}>();

const basePath = `/preferences/${props.contactMailUuid}`;

// A contact who unsubscribed from all emails gets no topic.
const savedTopicIds = (): number[] =>
    props.unsubscribedFromAll
        ? []
        : props.topics.filter((t) => t.subscribed).map((t) => t.id);

const selected = ref<number[]>(savedTopicIds());

const saving = ref(false);
const savedAt = ref<number | null>(null);

const subscribedCount = computed(() => selected.value.length);
const isDirty = computed(() => {
    const original = new Set(savedTopicIds());
    const current = new Set(selected.value);
    if (original.size !== current.size) return true;
    for (const id of original) {
        if (!current.has(id)) return true;
    }
    return false;
});

function save(): void {
    saving.value = true;

    router.patch(
        basePath,
        { subscribed_topic_ids: selected.value },
        {
            preserveScroll: true,
            onSuccess: () => {
                savedAt.value = Date.now();
                unsubscribedAll.value = props.unsubscribedFromAll ?? false;
            },
            onFinish: () => {
                saving.value = false;
            },
        },
    );
}

function toggle(topicId: number): void {
    selected.value = selected.value.includes(topicId)
        ? selected.value.filter((id) => id !== topicId)
        : [...selected.value, topicId];
}

function unsubscribeAll(): void {
    selected.value = [];
}

const unsubscribeAllConfirm = ref(false);
const unsubscribingAll = ref(false);
const unsubscribedAll = ref(props.unsubscribedFromAll ?? false);
const resubscribingAll = ref(false);

function globalUnsubscribe(): void {
    unsubscribingAll.value = true;

    router.delete(basePath, {
        preserveScroll: true,
        onSuccess: () => {
            unsubscribedAll.value = true;
            selected.value = [];
        },
        onFinish: () => {
            unsubscribingAll.value = false;
            unsubscribeAllConfirm.value = false;
        },
    });
}

function globalResubscribe(): void {
    resubscribingAll.value = true;

    router.post(
        `${basePath}/resubscribe`,
        {},
        {
            preserveScroll: true,
            onSuccess: () => {
                unsubscribedAll.value = false;
                selected.value = props.topics.map((t) => t.id);
            },
            onFinish: () => {
                resubscribingAll.value = false;
            },
        },
    );
}
</script>

<template>
    <div class="min-h-dvh bg-background px-6 py-12 text-zinc-900 antialiased">
        <Head title="Email preferences" />

        <div class="mx-auto max-w-xl space-y-8">
            <div
                v-if="isTestMail"
                class="rounded-[var(--bm-radius-md)] border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-800"
            >
                <span class="font-semibold">Test email preview</span> — this
                page is read-only. Actions won't affect real contacts.
            </div>

            <div>
                <h1 class="text-3xl font-semibold tracking-tight text-zinc-900">
                    Email preferences
                </h1>
                <p class="mt-3 text-base text-zinc-700">
                    Choose which emails you want to receive at
                    <span class="font-mono text-zinc-900">{{
                        contact.email
                    }}</span
                    >. Turn a topic
                    <span class="font-semibold text-emerald-700">on</span> to
                    get it,
                    <span class="font-semibold text-zinc-900">off</span> to
                    stop.
                </p>
                <p
                    class="mt-4 font-mono text-xs tracking-wide text-zinc-500 uppercase tabular-nums"
                >
                    <span class="text-zinc-900">{{ subscribedCount }}</span>
                    of
                    <span class="text-zinc-900">{{ topics.length }}</span>
                    on
                </p>
            </div>

            <div
                v-if="topics.length === 0"
                class="rounded-[var(--bm-radius-md)] border border-dashed border-zinc-300 p-8 text-center text-sm text-zinc-500"
            >
                No topics available for your subscriptions.
            </div>

            <ul v-else role="list" class="space-y-2">
                <li
                    v-for="topic in topics"
                    :key="topic.id"
                    class="group relative flex items-center gap-4 rounded-[var(--bm-radius-md)] border border-zinc-200 bg-white p-4 shadow-xs transition-colors has-checked:border-emerald-600 has-checked:bg-emerald-50"
                >
                    <div class="min-w-0 flex-1">
                        <label
                            :for="`topic-${topic.id}`"
                            class="block cursor-pointer font-medium text-zinc-900"
                        >
                            {{ topic.name }}
                        </label>
                        <p
                            v-if="topic.description"
                            class="mt-0.5 text-sm text-zinc-600"
                        >
                            {{ topic.description }}
                        </p>
                        <div
                            class="mt-2 flex items-center gap-2 font-mono text-[10px] tracking-wider uppercase"
                        >
                            <span
                                v-if="selected.includes(topic.id)"
                                class="inline-flex items-center gap-1 text-emerald-700"
                            >
                                <span
                                    class="size-1.5 rounded-full bg-emerald-600"
                                />
                                Subscribed
                            </span>
                            <span
                                v-else
                                class="inline-flex items-center gap-1 text-zinc-500"
                            >
                                <span
                                    class="size-1.5 rounded-full bg-zinc-400"
                                />
                                Paused
                            </span>
                            <span v-if="topic.list_name" class="text-zinc-400">
                                · {{ topic.list_name }}
                            </span>
                        </div>
                    </div>

                    <div
                        class="group/toggle relative inline-flex w-11 shrink-0 rounded-full bg-zinc-200 p-0.5 inset-ring inset-ring-zinc-900/10 outline-offset-2 outline-emerald-600 transition-colors duration-200 ease-in-out has-checked:bg-emerald-600 has-focus-visible:outline-2 sm:w-10"
                    >
                        <span
                            class="aspect-square w-1/2 rounded-full bg-white shadow-xs ring-1 ring-zinc-900/10 transition-transform duration-200 ease-in-out group-has-checked/toggle:translate-x-full"
                        />
                        <input
                            :id="`topic-${topic.id}`"
                            type="checkbox"
                            :checked="selected.includes(topic.id)"
                            :aria-label="`Toggle ${topic.name}`"
                            class="absolute inset-0 size-full cursor-pointer appearance-none focus:outline-hidden"
                            @change="toggle(topic.id)"
                        />
                    </div>
                </li>
            </ul>

            <div
                class="flex flex-col gap-4 border-t border-zinc-200 pt-6 sm:flex-row sm:items-center sm:justify-between"
            >
                <div class="flex items-center gap-3">
                    <button
                        type="button"
                        :disabled="saving || !isDirty"
                        class="rounded-[var(--bm-radius-md)] bg-zinc-900 px-5 py-2 text-sm font-semibold text-white shadow-xs transition-all hover:bg-zinc-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-zinc-900 active:scale-[0.98] disabled:pointer-events-none disabled:opacity-40"
                        @click="save"
                    >
                        {{ saving ? 'Saving…' : 'Save preferences' }}
                    </button>
                    <span
                        v-if="savedAt && !saving && !isDirty"
                        class="inline-flex items-center gap-1.5 text-xs text-emerald-700"
                        role="status"
                    >
                        <span class="size-1.5 rounded-full bg-emerald-600" />
                        Saved
                    </span>
                </div>

                <button
                    type="button"
                    class="text-left text-xs text-zinc-500 underline-offset-4 transition-colors hover:text-zinc-900 hover:underline sm:text-right"
                    @click="unsubscribeAll"
                >
                    Turn off all topics
                </button>
            </div>

            <div class="pt-2 text-center">
                <button
                    v-if="!unsubscribeAllConfirm && !unsubscribedAll"
                    type="button"
                    class="text-xs text-zinc-500 underline underline-offset-4 transition-colors hover:text-red-600"
                    @click="unsubscribeAllConfirm = true"
                >
                    Unsubscribe from all emails
                </button>
                <div
                    v-else-if="unsubscribeAllConfirm && !unsubscribedAll"
                    class="inline-flex items-center gap-2 text-xs text-zinc-700"
                >
                    <span>Stop all emails to this address?</span>
                    <button
                        type="button"
                        :disabled="unsubscribingAll"
                        class="font-semibold text-red-600 underline underline-offset-4 hover:text-red-700 disabled:opacity-50"
                        @click="globalUnsubscribe"
                    >
                        {{
                            unsubscribingAll
                                ? 'Unsubscribing…'
                                : 'Yes, unsubscribe'
                        }}
                    </button>
                    <span class="text-zinc-400">·</span>
                    <button
                        type="button"
                        class="text-zinc-500 hover:text-zinc-900"
                        @click="unsubscribeAllConfirm = false"
                    >
                        Cancel
                    </button>
                </div>
                <div
                    v-else
                    class="inline-flex items-center gap-2 text-xs text-zinc-700"
                    role="status"
                >
                    <span class="text-emerald-700">
                        You are unsubscribed from all emails.
                    </span>
                    <span class="text-zinc-400">·</span>
                    <button
                        type="button"
                        :disabled="resubscribingAll"
                        class="font-semibold text-emerald-700 underline underline-offset-4 hover:text-emerald-800 disabled:opacity-50"
                        @click="globalResubscribe"
                    >
                        {{
                            resubscribingAll
                                ? 'Subscribing…'
                                : 'Subscribe again'
                        }}
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>
