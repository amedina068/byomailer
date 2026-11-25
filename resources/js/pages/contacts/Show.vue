<script setup lang="ts">
import {
    Deferred,
    Head,
    Link,
    router,
    useForm,
    usePage,
} from '@inertiajs/vue3';
import {
    PhArrowCounterClockwise,
    PhCheck,
    PhCopy,
    PhEnvelopeSimple,
    PhFingerprint,
    PhFlowArrow,
    PhListChecks,
    PhPaperPlaneTilt,
    PhPencilSimple,
    PhPlus,
    PhProhibit,
    PhStack,
    PhTag,
    PhX,
} from '@phosphor-icons/vue';
import { computed, nextTick, ref, watch } from 'vue';

import {
    subscribe as contactSubscribe,
    unsubscribe as contactUnsubscribe,
} from '@/actions/App/Http/Controllers/ContactController';
import ReactivateContactController from '@/actions/App/Http/Controllers/ReactivateContactController';
import AddToSequenceModal from '@/components/contacts/AddToSequenceModal.vue';
import ContactAttributesCard from '@/components/contacts/ContactAttributesCard.vue';
import RemoveSuppressionModal from '@/components/contacts/RemoveSuppressionModal.vue';
import InputError from '@/components/InputError.vue';
import CreateListModal from '@/components/lists/CreateListModal.vue';
import CreateTagModal from '@/components/tags/CreateTagModal.vue';
import { Button } from '@/components/ui/button';
import { ConfirmDialog } from '@/components/ui/confirm-dialog';
import { toast } from '@/composables/useToast';
import AppLayout from '@/layouts/AppLayout.vue';
import { show as contactMailShow } from '@/routes/contact-mails';
import { index as contactsIndex } from '@/routes/contacts';

interface Tag {
    id: number;
    name: string;
}

interface ContactList {
    id: number;
    name: string;
}

interface ContactMail {
    id: number;
    subject?: string | null;
    status: string;
    sent_at?: string | null;
    scheduled_at?: string | null;
    unsubscribed_at?: string | null;
}

interface ContactTopic {
    id: number;
    name: string;
    list_id: number;
    list_name: string | null;
}

interface SequenceSummary {
    id: number;
    name: string;
    status: string;
}

const props = defineProps<{
    contact: {
        id: number;
        name: string;
        email: string;
        extra_attributes: Record<string, unknown>;
        unsubscribed_at?: string | null;
        unsubscribed_by_contact?: boolean;
        sunset_at?: string | null;
        lists: ContactList[];
        tags: Tag[];
        topics: ContactTopic[];
        sequences: SequenceSummary[];
    };
    mails?: ContactMail[];
    allTags: Tag[];
    allLists: ContactList[];
    allSequences: SequenceSummary[];
    contactAttributes: string[];
    suppression: { reason: string | null; can_remove: boolean } | null;
}>();

const breadcrumbs = [
    {
        title: 'Contacts',
        href: contactsIndex().url,
    },
];

const mailHistory = computed(() => props.mails ?? []);

// Unsubscribed wins over sunset when both are set.
const isSunset = computed(
    () => !props.contact.unsubscribed_at && !!props.contact.sunset_at,
);

const subscriptionStatus = computed(() => {
    if (props.contact.unsubscribed_at) {
        return 'Unsubscribed';
    }

    return isSunset.value ? 'Sunset' : 'Subscribed';
});

const subscriptionBadgeClass = computed(() => {
    if (props.contact.unsubscribed_at) {
        return 'text-red-700 bg-red-500/10 dark:text-red-400';
    }

    return isSunset.value
        ? 'text-amber-700 bg-amber-500/10 dark:text-amber-400'
        : 'text-emerald-700 bg-emerald-500/10 dark:text-emerald-400';
});

const subscriptionDotClass = computed(() => {
    if (props.contact.unsubscribed_at) {
        return 'bg-red-500';
    }

    return isSunset.value ? 'bg-amber-500' : 'bg-emerald-500';
});

const initials = computed(() => {
    const source = props.contact.name?.trim() || props.contact.email || '';
    const parts = source.split(/[\s@._-]+/).filter(Boolean);
    if (!parts.length) {
        return '?';
    }
    if (parts.length === 1) {
        return parts[0].slice(0, 2).toUpperCase();
    }
    return (parts[0][0] + parts[1][0]).toUpperCase();
});

const formatDateTime = (value?: string | null) =>
    value ? new Date(value).toLocaleString() : '—';

const copiedField = ref<string | null>(null);

const copyToClipboard = async (text: string, field: string) => {
    try {
        await navigator.clipboard.writeText(text);
        copiedField.value = field;
        toast.success('Copied to clipboard.');
        window.setTimeout(() => {
            if (copiedField.value === field) {
                copiedField.value = null;
            }
        }, 1500);
    } catch {
        toast.error('Could not copy to clipboard.');
    }
};

const mailStatusStyles: Record<string, { badge: string; dot: string }> = {
    sent: {
        badge: 'text-emerald-700 bg-emerald-500/10 dark:text-emerald-400',
        dot: 'bg-emerald-500',
    },
    delivered: {
        badge: 'text-emerald-700 bg-emerald-500/10 dark:text-emerald-400',
        dot: 'bg-emerald-500',
    },
    opened: {
        badge: 'text-sky-700 bg-sky-500/10 dark:text-sky-400',
        dot: 'bg-sky-500',
    },
    clicked: {
        badge: 'text-sky-700 bg-sky-500/10 dark:text-sky-400',
        dot: 'bg-sky-500',
    },
    scheduled: {
        badge: 'text-amber-700 bg-amber-500/10 dark:text-amber-400',
        dot: 'bg-amber-500',
    },
    pending: {
        badge: 'text-amber-700 bg-amber-500/10 dark:text-amber-400',
        dot: 'bg-amber-500',
    },
    queued: {
        badge: 'text-amber-700 bg-amber-500/10 dark:text-amber-400',
        dot: 'bg-amber-500',
    },
    bounced: {
        badge: 'text-red-700 bg-red-500/10 dark:text-red-400',
        dot: 'bg-red-500',
    },
    failed: {
        badge: 'text-red-700 bg-red-500/10 dark:text-red-400',
        dot: 'bg-red-500',
    },
    complained: {
        badge: 'text-red-700 bg-red-500/10 dark:text-red-400',
        dot: 'bg-red-500',
    },
    unsubscribed: {
        badge: 'text-amber-700 bg-amber-500/10 dark:text-amber-400',
        dot: 'bg-amber-500',
    },
};

const mailStatusMeta = (status: string) =>
    mailStatusStyles[status?.toLowerCase()] ?? {
        badge: 'text-muted-foreground bg-muted',
        dot: 'bg-muted-foreground/60',
    };

const subscriptionProcessing = ref(false);

const canResubscribe = computed(
    () =>
        props.contact.unsubscribed_at &&
        !props.contact.unsubscribed_by_contact &&
        (props.suppression === null || props.suppression.can_remove),
);

const isPermanentlySuppressed = computed(
    () => props.suppression !== null && !props.suppression.can_remove,
);

const isRemoveSuppressionOpen = ref(false);
const isUnsubscribeConfirmOpen = ref(false);

const requestSubscribe = () => {
    if (props.suppression) {
        isRemoveSuppressionOpen.value = true;

        return;
    }

    subscribeContact();
};

const unsubscribeContact = () => {
    if (subscriptionProcessing.value) {
        return;
    }

    subscriptionProcessing.value = true;
    isUnsubscribeConfirmOpen.value = false;

    router
        .optimistic<{ contact: { unsubscribed_at: string | null } }>((page) => {
            page.contact.unsubscribed_at = new Date().toISOString();
        })
        .post(
            contactUnsubscribe(props.contact.id).url,
            {},
            {
                preserveScroll: true,
                onSuccess: () => {
                    toast.success('Contact unsubscribed.');
                },
                onFinish: () => {
                    subscriptionProcessing.value = false;
                },
            },
        );
};

const subscribeContact = () => {
    if (subscriptionProcessing.value) {
        return;
    }

    subscriptionProcessing.value = true;
    isRemoveSuppressionOpen.value = false;

    router
        .optimistic<{ contact: { unsubscribed_at: string | null } }>((page) => {
            page.contact.unsubscribed_at = null;
        })
        .post(
            contactSubscribe(props.contact.id).url,
            { remove_suppression: props.suppression !== null },
            {
                preserveScroll: true,
                onSuccess: () => {
                    toast.success('Contact subscribed.');
                },
                onFinish: () => {
                    subscriptionProcessing.value = false;
                },
            },
        );
};

const reactivateContact = () => {
    if (subscriptionProcessing.value) {
        return;
    }

    subscriptionProcessing.value = true;

    router
        .optimistic<{ contact: { sunset_at: string | null } }>((page) => {
            page.contact.sunset_at = null;
        })
        .post(
            ReactivateContactController(props.contact.id).url,
            {},
            {
                preserveScroll: true,
                onSuccess: () => {
                    toast.success('Contact reactivated.');
                },
                onFinish: () => {
                    subscriptionProcessing.value = false;
                },
            },
        );
};

// Inline editing for name
const isEditingName = ref(false);
const nameInput = ref<HTMLInputElement | null>(null);
const skipNameBlurSave = ref(false);

const nameForm = useForm({
    name: props.contact.name,
    email: props.contact.email,
});

watch(
    () => props.contact.name,
    (newName) => {
        nameForm.defaults({ name: newName, email: props.contact.email });
        if (!isEditingName.value) {
            nameForm.name = newName;
        }
        nameForm.clearErrors();
    },
);

const startEditingName = () => {
    isEditingName.value = true;
    nextTick(() => {
        nameInput.value?.focus();
        nameInput.value?.select();
    });
};

const cancelEditingName = () => {
    skipNameBlurSave.value = true;
    isEditingName.value = false;
    nameForm.name = props.contact.name;
    nameForm.clearErrors();
    nameInput.value?.blur();
};

const saveName = () => {
    if (nameForm.processing) {
        return;
    }

    if (nameForm.name === props.contact.name) {
        isEditingName.value = false;
        return;
    }

    nameForm.patch(`/contacts/${props.contact.id}`, {
        preserveScroll: true,
        only: ['contact'],
        onSuccess: () => {
            isEditingName.value = false;
            toast.success('Name updated.');
        },
    });
};

const handleNameBlur = () => {
    if (skipNameBlurSave.value) {
        skipNameBlurSave.value = false;
        return;
    }

    saveName();
};

// Inline editing for email
const isEditingEmail = ref(false);
const emailInput = ref<HTMLInputElement | null>(null);
const skipEmailBlurSave = ref(false);

watch(
    () => props.contact.email,
    (newEmail) => {
        nameForm.defaults({ name: props.contact.name, email: newEmail });
        if (!isEditingEmail.value) {
            nameForm.email = newEmail;
        }
        nameForm.clearErrors();
    },
);

const startEditingEmail = () => {
    isEditingEmail.value = true;
    nextTick(() => {
        emailInput.value?.focus();
        emailInput.value?.select();
    });
};

const cancelEditingEmail = () => {
    skipEmailBlurSave.value = true;
    isEditingEmail.value = false;
    nameForm.email = props.contact.email;
    nameForm.clearErrors();
    emailInput.value?.blur();
};

const saveEmail = () => {
    if (nameForm.processing) {
        return;
    }

    if (nameForm.email === props.contact.email) {
        isEditingEmail.value = false;
        return;
    }

    nameForm.patch(`/contacts/${props.contact.id}`, {
        preserveScroll: true,
        only: ['contact'],
        onSuccess: () => {
            isEditingEmail.value = false;
            toast.success('Email updated.');
        },
    });
};

const handleEmailBlur = () => {
    if (skipEmailBlurSave.value) {
        skipEmailBlurSave.value = false;
        return;
    }

    saveEmail();
};

// Tags management
const isAddingTag = ref(false);
const selectedTagId = ref('');
const showCreateTagModal = ref(false);
const tagProcessing = ref(false);

const page = usePage();

const availableTags = computed(() =>
    props.allTags.filter(
        (tag) => !props.contact.tags.some((ct) => ct.id === tag.id),
    ),
);

const addTag = () => {
    if (!selectedTagId.value || tagProcessing.value) {
        return;
    }

    tagProcessing.value = true;

    const currentTagIds = props.contact.tags.map((t) => t.id);
    const newTagIds = [...currentTagIds, Number(selectedTagId.value)];

    router.post(
        `/contacts/${props.contact.id}/tags`,
        { tag_ids: newTagIds },
        {
            preserveScroll: true,
            onSuccess: () => {
                selectedTagId.value = '';
                isAddingTag.value = false;
                toast.success('Tag added.');
            },
            onFinish: () => {
                tagProcessing.value = false;
            },
        },
    );
};

const syncTagToContact = (tagId: number) => {
    if (tagProcessing.value) {
        return;
    }

    tagProcessing.value = true;

    const currentTagIds = props.contact.tags.map((t) => t.id);
    const newTagIds = [...currentTagIds, tagId];

    router.post(
        `/contacts/${props.contact.id}/tags`,
        { tag_ids: newTagIds },
        {
            preserveScroll: true,
            onSuccess: () => {
                selectedTagId.value = '';
                isAddingTag.value = false;
                toast.success('Tag created and added.');
            },
            onFinish: () => {
                tagProcessing.value = false;
            },
        },
    );
};

const handleTagCreated = () => {
    showCreateTagModal.value = false;
    const flash = page.props.flash as Record<string, any>;
    const createdTag = flash?.createdTag;
    if (createdTag) {
        syncTagToContact(createdTag.id);
    }
};

const removeTag = (tagId: number) => {
    // Each request sends the full tag set from the current props. A second
    // request before the first one returns sends a stale set and restores the tag.
    if (tagProcessing.value) {
        return;
    }

    tagProcessing.value = true;

    const newTagIds = props.contact.tags
        .filter((t) => t.id !== tagId)
        .map((t) => t.id);

    router.post(
        `/contacts/${props.contact.id}/tags`,
        { tag_ids: newTagIds },
        {
            preserveScroll: true,
            onSuccess: () => {
                toast.success('Tag removed.');
            },
            onFinish: () => {
                tagProcessing.value = false;
            },
        },
    );
};

// Lists management
const isAddingList = ref(false);
const selectedListId = ref('');
const showCreateListModal = ref(false);
const listProcessing = ref(false);

const availableLists = computed(() =>
    props.allLists.filter(
        (list) => !props.contact.lists.some((cl) => cl.id === list.id),
    ),
);

const addList = () => {
    if (!selectedListId.value || listProcessing.value) {
        return;
    }

    listProcessing.value = true;

    const currentListIds = props.contact.lists.map((l) => l.id);
    const newListIds = [...currentListIds, Number(selectedListId.value)];

    router.post(
        `/contacts/${props.contact.id}/lists`,
        { list_ids: newListIds },
        {
            preserveScroll: true,
            onSuccess: () => {
                selectedListId.value = '';
                isAddingList.value = false;
                toast.success('List added.');
            },
            onFinish: () => {
                listProcessing.value = false;
            },
        },
    );
};

const syncListToContact = (listId: number) => {
    if (listProcessing.value) {
        return;
    }

    listProcessing.value = true;

    const currentListIds = props.contact.lists.map((l) => l.id);
    const newListIds = [...currentListIds, listId];

    router.post(
        `/contacts/${props.contact.id}/lists`,
        { list_ids: newListIds },
        {
            preserveScroll: true,
            onSuccess: () => {
                selectedListId.value = '';
                isAddingList.value = false;
                toast.success('List created and added.');
            },
            onFinish: () => {
                listProcessing.value = false;
            },
        },
    );
};

const handleListCreated = () => {
    showCreateListModal.value = false;
    const flash = page.props.flash as Record<string, any>;
    const createdList = flash?.createdList;
    if (createdList) {
        syncListToContact(createdList.id);
    }
};

const removeList = (listId: number) => {
    // Same full-set request as removeTag, so it needs the same in-flight guard.
    if (listProcessing.value) {
        return;
    }

    listProcessing.value = true;

    const newListIds = props.contact.lists
        .filter((l) => l.id !== listId)
        .map((l) => l.id);

    router.post(
        `/contacts/${props.contact.id}/lists`,
        { list_ids: newListIds },
        {
            preserveScroll: true,
            onSuccess: () => {
                toast.success('List removed.');
            },
            onFinish: () => {
                listProcessing.value = false;
            },
        },
    );
};

// Sequences management
const showAddToSequenceModal = ref(false);
const contactIdList = computed(() => [props.contact.id]);
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head :title="`Contact · ${contact.name || 'Unknown'}`" />

        <div class="space-y-6">
            <!-- Identity header -->
            <section
                class="overflow-hidden rounded-lg border border-border bg-card shadow-sm"
            >
                <div
                    class="flex flex-col gap-4 p-5 sm:flex-row sm:items-start sm:justify-between"
                >
                    <div class="flex min-w-0 items-start gap-4">
                        <div
                            class="flex size-14 shrink-0 items-center justify-center rounded-md bg-primary/10 font-mono text-lg font-semibold text-primary"
                            aria-hidden="true"
                        >
                            {{ initials }}
                        </div>

                        <div class="min-w-0">
                            <!-- Name (inline edit) -->
                            <form
                                v-if="isEditingName"
                                class="flex flex-wrap items-center gap-2"
                                @submit.prevent="
                                    skipNameBlurSave = false;
                                    saveName();
                                "
                            >
                                <input
                                    ref="nameInput"
                                    v-model="nameForm.name"
                                    type="text"
                                    aria-label="Contact name"
                                    class="min-w-[180px] rounded-md border border-border bg-background px-3 py-1.5 text-xl font-semibold text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                    placeholder="Contact name"
                                    @keydown.esc.prevent="cancelEditingName"
                                    @blur="handleNameBlur"
                                />
                                <Button
                                    type="submit"
                                    size="sm"
                                    :disabled="nameForm.processing"
                                    @pointerdown="skipNameBlurSave = true"
                                >
                                    {{
                                        nameForm.processing
                                            ? 'Saving...'
                                            : 'Save'
                                    }}
                                </Button>
                                <button
                                    type="button"
                                    class="text-sm text-muted-foreground hover:text-foreground"
                                    @pointerdown="skipNameBlurSave = true"
                                    @click="cancelEditingName"
                                >
                                    Cancel
                                </button>
                            </form>
                            <button
                                v-else
                                type="button"
                                :class="[
                                    'group inline-flex max-w-full items-center gap-2 rounded-md text-left text-xl font-semibold transition focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none',
                                    contact.name
                                        ? 'text-foreground hover:text-primary'
                                        : 'text-muted-foreground hover:text-foreground',
                                ]"
                                @click="startEditingName"
                            >
                                <span class="truncate">{{
                                    contact.name || 'Unknown'
                                }}</span>
                                <PhPencilSimple
                                    :size="15"
                                    class="shrink-0 text-muted-foreground opacity-0 transition group-hover:opacity-100"
                                />
                            </button>
                            <InputError
                                v-if="nameForm.errors.name"
                                class="mt-1"
                                :message="nameForm.errors.name"
                            />

                            <!-- Email (inline edit) -->
                            <div class="mt-1">
                                <form
                                    v-if="isEditingEmail"
                                    class="flex flex-wrap items-center gap-2"
                                    @submit.prevent="
                                        skipEmailBlurSave = false;
                                        saveEmail();
                                    "
                                >
                                    <input
                                        ref="emailInput"
                                        v-model="nameForm.email"
                                        type="email"
                                        aria-label="Contact email"
                                        class="min-w-[180px] rounded-md border border-border bg-background px-3 py-1.5 font-mono text-sm text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                        placeholder="Contact email"
                                        @keydown.esc.prevent="
                                            cancelEditingEmail
                                        "
                                        @blur="handleEmailBlur"
                                    />
                                    <Button
                                        type="submit"
                                        size="sm"
                                        :disabled="nameForm.processing"
                                        @pointerdown="skipEmailBlurSave = true"
                                    >
                                        {{
                                            nameForm.processing
                                                ? 'Saving...'
                                                : 'Save'
                                        }}
                                    </Button>
                                    <button
                                        type="button"
                                        class="text-sm text-muted-foreground hover:text-foreground"
                                        @pointerdown="skipEmailBlurSave = true"
                                        @click="cancelEditingEmail"
                                    >
                                        Cancel
                                    </button>
                                </form>
                                <div
                                    v-else
                                    class="group flex items-center gap-1.5 text-sm text-muted-foreground"
                                >
                                    <PhEnvelopeSimple
                                        :size="15"
                                        class="shrink-0"
                                    />
                                    <button
                                        type="button"
                                        class="truncate rounded font-mono transition hover:text-primary focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                        @click="startEditingEmail"
                                    >
                                        {{ contact.email }}
                                    </button>
                                    <button
                                        type="button"
                                        class="shrink-0 rounded p-0.5 text-muted-foreground transition hover:text-primary focus-visible:opacity-100 sm:opacity-0 sm:group-focus-within:opacity-100 sm:group-hover:opacity-100"
                                        aria-label="Copy email"
                                        @click="
                                            copyToClipboard(
                                                contact.email,
                                                'email',
                                            )
                                        "
                                    >
                                        <PhCheck
                                            v-if="copiedField === 'email'"
                                            :size="14"
                                            class="text-emerald-500"
                                        />
                                        <PhCopy v-else :size="14" />
                                    </button>
                                </div>
                                <InputError
                                    v-if="nameForm.errors.email"
                                    class="mt-1"
                                    :message="nameForm.errors.email"
                                />
                            </div>
                        </div>
                    </div>

                    <!-- Status + actions -->
                    <div
                        class="flex shrink-0 flex-col items-start gap-3 sm:items-end"
                    >
                        <span
                            class="inline-flex items-center gap-1.5 rounded px-2.5 py-1 font-mono text-xs font-medium"
                            :class="subscriptionBadgeClass"
                        >
                            <span
                                class="size-1.5 rounded-full"
                                :class="subscriptionDotClass"
                            />
                            {{ subscriptionStatus }}
                        </span>
                        <p
                            v-if="isSunset"
                            class="max-w-xs text-xs text-muted-foreground sm:text-right"
                        >
                            No clicks in the set period, so BYOMailer does not
                            mail this contact.
                        </p>

                        <p
                            v-if="isPermanentlySuppressed"
                            class="max-w-xs text-xs text-muted-foreground sm:text-right"
                        >
                            The address is on the suppression list after a
                            complaint, or the server operator blocked it.
                            BYOMailer does not mail it again.
                        </p>

                        <p
                            v-if="
                                contact.unsubscribed_at &&
                                contact.unsubscribed_by_contact
                            "
                            class="max-w-xs text-xs text-muted-foreground sm:text-right"
                        >
                            This contact unsubscribed. Only the contact can
                            subscribe again.
                        </p>

                        <div class="flex flex-wrap gap-2">
                            <Button
                                v-if="!contact.unsubscribed_at"
                                variant="destructive"
                                size="sm"
                                :disabled="subscriptionProcessing"
                                @click="isUnsubscribeConfirmOpen = true"
                            >
                                <PhProhibit :size="15" />
                                {{
                                    subscriptionProcessing
                                        ? 'Unsubscribing...'
                                        : 'Unsubscribe'
                                }}
                            </Button>
                            <Button
                                v-if="canResubscribe"
                                size="sm"
                                :disabled="subscriptionProcessing"
                                @click="requestSubscribe"
                            >
                                <PhArrowCounterClockwise :size="15" />
                                {{
                                    subscriptionProcessing
                                        ? 'Subscribing...'
                                        : 'Subscribe'
                                }}
                            </Button>
                            <Button
                                v-if="isSunset"
                                size="sm"
                                :disabled="subscriptionProcessing"
                                @click="reactivateContact"
                            >
                                <PhArrowCounterClockwise :size="15" />
                                {{
                                    subscriptionProcessing
                                        ? 'Reactivating...'
                                        : 'Reactivate'
                                }}
                            </Button>
                        </div>
                    </div>
                </div>

                <!-- Meta strip -->
                <div
                    class="flex flex-wrap items-center gap-x-6 gap-y-3 border-t border-border bg-muted/30 px-5 py-3"
                >
                    <div class="flex items-center gap-1.5">
                        <PhFingerprint
                            :size="14"
                            class="text-muted-foreground"
                        />
                        <span class="font-mono text-xs text-muted-foreground">
                            ID
                        </span>
                        <button
                            type="button"
                            class="group inline-flex items-center gap-1 rounded font-mono text-xs font-medium text-foreground transition hover:text-primary"
                            @click="copyToClipboard(String(contact.id), 'id')"
                        >
                            #{{ contact.id }}
                            <PhCheck
                                v-if="copiedField === 'id'"
                                :size="13"
                                class="text-emerald-500"
                            />
                            <PhCopy
                                v-else
                                :size="13"
                                class="opacity-0 transition group-hover:opacity-100"
                            />
                        </button>
                    </div>
                </div>
            </section>

            <div class="grid gap-6 lg:grid-cols-3">
                <div class="space-y-6 lg:col-span-1">
                    <ContactAttributesCard
                        :contact-id="contact.id"
                        :attributes="contact.extra_attributes ?? {}"
                        :attribute-names="contactAttributes"
                    />

                    <!-- Tags -->
                    <section
                        class="overflow-hidden rounded-lg border border-border bg-card shadow-sm"
                    >
                        <header
                            class="flex items-center justify-between border-b border-border px-4 py-3"
                        >
                            <div class="flex items-center gap-2">
                                <PhTag
                                    :size="15"
                                    class="text-muted-foreground"
                                />
                                <h2
                                    class="text-sm font-semibold text-foreground"
                                >
                                    Tags
                                </h2>
                                <span
                                    class="rounded bg-muted px-1.5 py-0.5 font-mono text-[10px] text-muted-foreground"
                                >
                                    {{ contact.tags.length }}
                                </span>
                            </div>
                            <button
                                type="button"
                                class="rounded-md p-1 text-muted-foreground transition hover:bg-muted hover:text-foreground"
                                aria-label="Add tag"
                                @click="isAddingTag = !isAddingTag"
                            >
                                <PhPlus :size="15" />
                            </button>
                        </header>

                        <div class="p-4">
                            <div
                                v-if="isAddingTag"
                                class="mb-3 rounded-md border border-border p-3"
                            >
                                <div class="flex items-end gap-2">
                                    <div class="flex-1">
                                        <select
                                            v-model="selectedTagId"
                                            aria-label="Tag to add"
                                            class="w-full rounded-md border border-border bg-background px-3 py-2 text-sm focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                            :disabled="tagProcessing"
                                            @change="
                                                selectedTagId ===
                                                    '__create__' &&
                                                ((showCreateTagModal = true),
                                                (selectedTagId = ''))
                                            "
                                        >
                                            <option value="" disabled>
                                                Select a tag
                                            </option>
                                            <option
                                                v-for="tag in availableTags"
                                                :key="tag.id"
                                                :value="String(tag.id)"
                                            >
                                                {{ tag.name }}
                                            </option>
                                            <option value="__create__">
                                                + Create new tag
                                            </option>
                                        </select>
                                    </div>
                                    <Button
                                        size="sm"
                                        :disabled="
                                            !selectedTagId || tagProcessing
                                        "
                                        @click="addTag"
                                    >
                                        {{
                                            tagProcessing ? 'Adding...' : 'Add'
                                        }}
                                    </Button>
                                </div>
                            </div>

                            <div class="flex flex-wrap gap-2">
                                <span
                                    v-for="tag in contact.tags"
                                    :key="tag.id"
                                    class="group inline-flex items-center gap-1 rounded border border-border bg-muted px-2.5 py-1 text-xs font-medium text-muted-foreground transition hover:text-foreground"
                                >
                                    {{ tag.name }}
                                    <button
                                        type="button"
                                        class="ml-0.5 inline-flex rounded text-muted-foreground hover:text-destructive disabled:opacity-50"
                                        :aria-label="`Remove tag ${tag.name}`"
                                        :disabled="tagProcessing"
                                        @click="removeTag(tag.id)"
                                    >
                                        <PhX :size="12" />
                                    </button>
                                </span>
                                <p
                                    v-if="!contact.tags.length"
                                    class="text-sm text-muted-foreground"
                                >
                                    No tags yet.
                                </p>
                            </div>
                        </div>
                    </section>

                    <!-- Lists -->
                    <section
                        class="overflow-hidden rounded-lg border border-border bg-card shadow-sm"
                    >
                        <header
                            class="flex items-center justify-between border-b border-border px-4 py-3"
                        >
                            <div class="flex items-center gap-2">
                                <PhStack
                                    :size="15"
                                    class="text-muted-foreground"
                                />
                                <h2
                                    class="text-sm font-semibold text-foreground"
                                >
                                    Lists
                                </h2>
                                <span
                                    class="rounded bg-muted px-1.5 py-0.5 font-mono text-[10px] text-muted-foreground"
                                >
                                    {{ contact.lists.length }}
                                </span>
                            </div>
                            <button
                                type="button"
                                class="rounded-md p-1 text-muted-foreground transition hover:bg-muted hover:text-foreground"
                                aria-label="Add list"
                                @click="isAddingList = !isAddingList"
                            >
                                <PhPlus :size="15" />
                            </button>
                        </header>

                        <div class="p-4">
                            <div
                                v-if="isAddingList"
                                class="mb-3 rounded-md border border-border p-3"
                            >
                                <div class="flex items-end gap-2">
                                    <div class="flex-1">
                                        <select
                                            v-model="selectedListId"
                                            aria-label="List to add"
                                            class="w-full rounded-md border border-border bg-background px-3 py-2 text-sm focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                            :disabled="listProcessing"
                                            @change="
                                                selectedListId ===
                                                    '__create__' &&
                                                ((showCreateListModal = true),
                                                (selectedListId = ''))
                                            "
                                        >
                                            <option value="" disabled>
                                                Select a list
                                            </option>
                                            <option
                                                v-for="list in availableLists"
                                                :key="list.id"
                                                :value="String(list.id)"
                                            >
                                                {{ list.name }}
                                            </option>
                                            <option value="__create__">
                                                + Create new list
                                            </option>
                                        </select>
                                    </div>
                                    <Button
                                        size="sm"
                                        :disabled="
                                            !selectedListId || listProcessing
                                        "
                                        @click="addList"
                                    >
                                        {{
                                            listProcessing ? 'Adding...' : 'Add'
                                        }}
                                    </Button>
                                </div>
                            </div>

                            <div class="flex flex-wrap gap-2">
                                <span
                                    v-for="list in contact.lists"
                                    :key="list.id"
                                    class="group inline-flex items-center gap-1 rounded border border-primary/20 bg-primary/10 px-2.5 py-1 text-xs font-medium text-primary"
                                >
                                    {{ list.name }}
                                    <button
                                        type="button"
                                        class="ml-0.5 inline-flex rounded text-primary/60 hover:text-primary disabled:opacity-50"
                                        :aria-label="`Remove from list ${list.name}`"
                                        :disabled="listProcessing"
                                        @click="removeList(list.id)"
                                    >
                                        <PhX :size="12" />
                                    </button>
                                </span>
                                <p
                                    v-if="!contact.lists.length"
                                    class="text-sm text-muted-foreground"
                                >
                                    Not assigned to any lists yet.
                                </p>
                            </div>
                        </div>
                    </section>

                    <!-- Sequences -->
                    <section
                        class="overflow-hidden rounded-lg border border-border bg-card shadow-sm"
                    >
                        <header
                            class="flex items-center justify-between border-b border-border px-4 py-3"
                        >
                            <div class="flex items-center gap-2">
                                <PhFlowArrow
                                    :size="15"
                                    class="text-muted-foreground"
                                />
                                <h2
                                    class="text-sm font-semibold text-foreground"
                                >
                                    Sequences
                                </h2>
                                <span
                                    class="rounded bg-muted px-1.5 py-0.5 font-mono text-[10px] text-muted-foreground"
                                >
                                    {{ contact.sequences.length }}
                                </span>
                            </div>
                            <button
                                type="button"
                                class="rounded-md p-1 text-muted-foreground transition hover:bg-muted hover:text-foreground"
                                aria-label="Add to sequence"
                                @click="showAddToSequenceModal = true"
                            >
                                <PhPlus :size="15" />
                            </button>
                        </header>

                        <div class="flex flex-wrap gap-2 p-4">
                            <span
                                v-for="sequence in contact.sequences"
                                :key="sequence.id"
                                class="inline-flex items-center gap-1.5 rounded border border-border bg-muted px-2.5 py-1 text-xs font-medium text-muted-foreground"
                            >
                                <PhFlowArrow :size="12" />
                                {{ sequence.name }}
                            </span>
                            <p
                                v-if="!contact.sequences.length"
                                class="text-sm text-muted-foreground"
                            >
                                Not enrolled in any sequences yet.
                            </p>
                        </div>
                    </section>

                    <!-- Topics -->
                    <section
                        class="overflow-hidden rounded-lg border border-border bg-card shadow-sm"
                    >
                        <header
                            class="flex items-center justify-between border-b border-border px-4 py-3"
                        >
                            <div class="flex items-center gap-2">
                                <PhListChecks
                                    :size="15"
                                    class="text-muted-foreground"
                                />
                                <h2
                                    class="text-sm font-semibold text-foreground"
                                >
                                    Topics
                                </h2>
                                <span
                                    class="rounded bg-muted px-1.5 py-0.5 font-mono text-[10px] text-muted-foreground"
                                >
                                    {{ contact.topics.length }}
                                </span>
                            </div>
                        </header>

                        <div class="flex flex-wrap gap-2 p-4">
                            <span
                                v-for="topic in contact.topics"
                                :key="topic.id"
                                class="inline-flex items-center gap-1.5 rounded border border-emerald-500/20 bg-emerald-500/10 px-2.5 py-1 text-xs font-medium text-emerald-600 dark:text-emerald-400"
                                :title="topic.list_name ?? ''"
                            >
                                <span
                                    class="size-1.5 rounded-full bg-emerald-500"
                                />
                                {{ topic.name }}
                            </span>
                            <p
                                v-if="!contact.topics.length"
                                class="text-sm text-muted-foreground"
                            >
                                No subscribed topics.
                            </p>
                        </div>
                    </section>
                </div>

                <!-- Messages -->
                <section
                    class="overflow-hidden rounded-lg border border-border bg-card shadow-sm lg:col-span-2"
                >
                    <header
                        class="flex items-center justify-between border-b border-border px-4 py-3"
                    >
                        <div class="flex items-center gap-2">
                            <PhPaperPlaneTilt
                                :size="15"
                                class="text-muted-foreground"
                            />
                            <h2 class="text-sm font-semibold text-foreground">
                                Messages sent
                            </h2>
                            <span
                                v-if="props.mails"
                                class="rounded bg-muted px-1.5 py-0.5 font-mono text-[10px] text-muted-foreground"
                            >
                                {{ mailHistory.length }}
                            </span>
                        </div>
                    </header>

                    <Deferred :data="['mails']">
                        <template #fallback>
                            <div class="space-y-2 px-4 py-4">
                                <div
                                    v-for="n in 4"
                                    :key="n"
                                    class="h-9 animate-pulse"
                                    style="
                                        background-color: var(--bm-surface-2);
                                        border-radius: var(--bm-radius);
                                    "
                                />
                            </div>
                        </template>

                        <div class="overflow-x-auto">
                            <table class="min-w-full text-sm">
                                <thead>
                                    <tr
                                        class="border-b border-border bg-muted/40 text-left text-[10px] font-semibold tracking-wide text-muted-foreground uppercase"
                                    >
                                        <th class="px-4 py-2.5 font-semibold">
                                            Subject
                                        </th>
                                        <th class="px-4 py-2.5 font-semibold">
                                            Status
                                        </th>
                                        <th class="px-4 py-2.5 font-semibold">
                                            Scheduled / Sent
                                        </th>
                                        <th class="px-4 py-2.5 font-semibold">
                                            Unsubscribed
                                        </th>
                                        <th class="px-4 py-2.5" />
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr
                                        v-for="mail in mailHistory"
                                        :key="mail.id"
                                        class="border-b border-border transition last:border-0 hover:bg-muted/40"
                                    >
                                        <td
                                            class="px-4 py-3 font-medium text-foreground"
                                        >
                                            {{
                                                mail.subject ??
                                                'Newsletter message'
                                            }}
                                        </td>
                                        <td class="px-4 py-3">
                                            <span
                                                class="inline-flex items-center gap-1.5 rounded px-2 py-0.5 font-mono text-[11px] font-medium capitalize"
                                                :class="
                                                    mailStatusMeta(mail.status)
                                                        .badge
                                                "
                                            >
                                                <span
                                                    class="size-1.5 rounded-full"
                                                    :class="
                                                        mailStatusMeta(
                                                            mail.status,
                                                        ).dot
                                                    "
                                                />
                                                {{ mail.status }}
                                            </span>
                                        </td>
                                        <td
                                            class="px-4 py-3 font-mono text-xs text-muted-foreground"
                                        >
                                            {{
                                                formatDateTime(
                                                    mail.sent_at ??
                                                        mail.scheduled_at,
                                                )
                                            }}
                                        </td>
                                        <td
                                            class="px-4 py-3 font-mono text-xs text-muted-foreground"
                                        >
                                            {{
                                                formatDateTime(
                                                    mail.unsubscribed_at,
                                                )
                                            }}
                                        </td>
                                        <td class="px-4 py-3 text-right">
                                            <Link
                                                class="inline-flex items-center gap-1 text-xs font-medium text-primary hover:underline"
                                                :href="
                                                    contactMailShow(mail.id).url
                                                "
                                            >
                                                View
                                            </Link>
                                        </td>
                                    </tr>
                                    <tr v-if="!mailHistory.length">
                                        <td
                                            colspan="5"
                                            class="px-4 py-10 text-center text-sm text-muted-foreground"
                                        >
                                            No messages yet.
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </Deferred>
                </section>
            </div>
        </div>

        <CreateTagModal
            v-model:is-open="showCreateTagModal"
            @created="handleTagCreated"
        />

        <CreateListModal
            v-model:is-open="showCreateListModal"
            :return-back="true"
            @created="handleListCreated"
        />

        <AddToSequenceModal
            v-model:is-open="showAddToSequenceModal"
            :sequences="allSequences"
            :contact-ids="contactIdList"
        />
        <RemoveSuppressionModal
            v-model:is-open="isRemoveSuppressionOpen"
            :email="contact.email"
            @confirm="subscribeContact"
        />
        <ConfirmDialog
            v-model:is-open="isUnsubscribeConfirmOpen"
            title="Unsubscribe this contact?"
            :description="`BYOMailer stops all email to ${contact.email} and cancels the pending emails. You can subscribe the contact again later.`"
            confirm-label="Unsubscribe"
            :processing="subscriptionProcessing"
            @confirm="unsubscribeContact"
        />
    </AppLayout>
</template>
