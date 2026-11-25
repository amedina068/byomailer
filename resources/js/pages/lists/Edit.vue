<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

import InputError from '@/components/InputError.vue';
import TemplatePickerModal from '@/components/templates/TemplatePickerModal.vue';
import TiptapEditor from '@/components/TiptapEditor.vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { Switch } from '@/components/ui/switch';
import type { EmailTemplateSummary } from '@/composables/useTemplateApply';
import AppLayout from '@/layouts/AppLayout.vue';
import { hasContentPlaceholder, hydrateTemplate } from '@/lib/template-slot';
import { create as emailIdentitiesCreate } from '@/routes/identities';
import {
    index as listsIndex,
    show as listsShow,
    update as listsUpdate,
} from '@/routes/lists';
import type { BreadcrumbItem } from '@/types';

interface EmailIdentity {
    id: number;
    email: string;
    name: string | null;
}

interface MailListMessage {
    subject: string;
    html_content: string | null;
    email_identity_id: number | null;
}

interface MailList {
    id: number;
    uuid: string;
    name: string;
    opt_in_enabled: boolean;
    message?: MailListMessage | null;
}

const props = defineProps<{
    list: MailList;
    emailIdentities: EmailIdentity[];
}>();

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Lists',
        href: listsIndex().url,
    },
    {
        title: props.list.name,
        href: listsShow(props.list.id).url,
    },
    {
        title: 'Edit',
        href: listsShow(props.list.id).url,
    },
];

const defaultContent =
    '<p>Welcome! Click the link below to confirm your subscription:</p><p><a href="{{opt_in_url}}">Confirm Subscription</a></p>';

const optInEnabled = ref(props.list.opt_in_enabled);
const optInContent = ref(props.list.message?.html_content ?? defaultContent);
const optInEmailIdentityId = ref(
    props.list.message?.email_identity_id
        ? String(props.list.message.email_identity_id)
        : '',
);

const hasEmailIdentities = computed(() => props.emailIdentities.length > 0);

const isTemplatePickerOpen = ref(false);

/**
 * Put the chosen template into the opt-in body. A template with a
 * `{{ content }}` slot keeps the current body (with its confirm link) inside it.
 */
const handleTemplateSelect = (template: EmailTemplateSummary) => {
    optInContent.value = hasContentPlaceholder(template.html_content)
        ? hydrateTemplate(template.html_content, optInContent.value)
        : template.html_content;
};
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head :title="`Edit · ${list.name}`" />

        <section class="space-y-6">
            <div
                class="rounded-xl border border-sidebar-border/60 bg-card p-6 dark:border-sidebar-border"
            >
                <div class="flex items-center justify-between gap-4">
                    <div class="space-y-1">
                        <h1 class="text-2xl font-semibold text-foreground">
                            Edit list
                        </h1>
                        <p class="text-sm text-muted-foreground">
                            Update list settings and opt-in configuration.
                        </p>
                    </div>
                    <Button as-child variant="ghost" size="sm">
                        <Link :href="listsShow(list.id).url">
                            Back to list
                        </Link>
                    </Button>
                </div>

                <div class="mt-6">
                    <Form
                        v-bind="listsUpdate.form(list.id)"
                        class="space-y-6"
                        #default="{ errors, processing, recentlySuccessful }"
                    >
                        <div class="grid gap-2">
                            <label
                                for="name"
                                class="text-sm font-medium text-foreground"
                            >
                                List name
                            </label>
                            <input
                                id="name"
                                name="name"
                                type="text"
                                :value="list.name"
                                required
                                class="rounded-lg border border-border bg-background px-3 py-2 text-sm text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                            />
                            <InputError :message="errors.name" />
                        </div>

                        <div
                            class="space-y-4 rounded-lg border border-border p-4"
                        >
                            <div class="flex items-center justify-between">
                                <div class="space-y-0.5">
                                    <label
                                        for="opt_in_enabled"
                                        class="text-sm font-medium text-foreground"
                                    >
                                        Double opt-in
                                    </label>
                                    <p class="text-xs text-muted-foreground">
                                        Each contact that joins this list gets a
                                        confirmation email. Newsletters and
                                        sequences reach the contact only after
                                        it confirms.
                                    </p>
                                </div>
                                <Switch
                                    id="opt_in_enabled"
                                    v-model="optInEnabled"
                                />
                                <input
                                    type="hidden"
                                    name="opt_in_enabled"
                                    :value="optInEnabled ? '1' : '0'"
                                />
                            </div>

                            <template v-if="optInEnabled">
                                <div class="grid gap-2">
                                    <label
                                        for="opt_in_email_identity_id"
                                        class="text-sm font-medium text-foreground"
                                    >
                                        Send from
                                    </label>
                                    <select
                                        id="opt_in_email_identity_id"
                                        v-model="optInEmailIdentityId"
                                        name="opt_in_email_identity_id"
                                        class="w-full rounded-lg border border-border bg-background px-3 py-2 text-sm text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                        :disabled="!hasEmailIdentities"
                                        required
                                    >
                                        <option value="" disabled>
                                            Select an email identity
                                        </option>
                                        <option
                                            v-for="identity in emailIdentities"
                                            :key="identity.id"
                                            :value="String(identity.id)"
                                        >
                                            {{
                                                identity.name || identity.email
                                            }}
                                            &lt;{{ identity.email }}&gt;
                                        </option>
                                    </select>
                                    <InputError
                                        :message="
                                            errors.opt_in_email_identity_id
                                        "
                                    />
                                    <p
                                        v-if="!hasEmailIdentities"
                                        class="text-xs text-muted-foreground"
                                    >
                                        No email identities yet.
                                        <Link
                                            :href="emailIdentitiesCreate().url"
                                            class="font-medium text-primary underline-offset-4 hover:underline"
                                        >
                                            Add an email identity
                                        </Link>
                                        first.
                                    </p>
                                </div>

                                <div class="grid gap-2">
                                    <label
                                        for="opt_in_subject"
                                        class="text-sm font-medium text-foreground"
                                    >
                                        Subject
                                    </label>
                                    <input
                                        id="opt_in_subject"
                                        name="opt_in_subject"
                                        type="text"
                                        :value="
                                            list.message?.subject ??
                                            'Confirm your subscription'
                                        "
                                        required
                                        class="rounded-lg border border-border bg-background px-3 py-2 text-sm text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                    />
                                    <InputError
                                        :message="errors.opt_in_subject"
                                    />
                                    <p class="text-xs text-muted-foreground">
                                        You can use template variables like
                                        <code
                                            class="rounded bg-muted px-1 py-0.5 text-xs"
                                            v-text="'{{ name }}'"
                                        />
                                        or
                                        <code
                                            class="rounded bg-muted px-1 py-0.5 text-xs"
                                            v-text="'{{ email }}'"
                                        />
                                        in the subject line.
                                    </p>
                                </div>

                                <div class="grid gap-2">
                                    <div
                                        class="flex items-center justify-between"
                                    >
                                        <label
                                            class="text-sm font-medium text-foreground"
                                        >
                                            Content
                                        </label>
                                        <button
                                            type="button"
                                            class="text-xs font-medium text-primary transition hover:underline"
                                            @click="isTemplatePickerOpen = true"
                                        >
                                            Use template
                                        </button>
                                    </div>
                                    <p class="text-xs text-muted-foreground">
                                        Use
                                        <code
                                            class="rounded bg-muted px-1 py-0.5 text-xs"
                                            v-text="'{{opt_in_url}}'"
                                        />
                                        for the confirmation link. Template
                                        variables like
                                        <code
                                            class="rounded bg-muted px-1 py-0.5 text-xs"
                                            v-text="'{{ name }}'"
                                        />
                                        are also supported.
                                    </p>
                                    <TiptapEditor
                                        v-model="optInContent"
                                        placeholder="Write your opt-in email content..."
                                    />
                                    <input
                                        type="hidden"
                                        name="opt_in_html_content"
                                        :value="optInContent"
                                    />
                                    <InputError
                                        :message="errors.opt_in_html_content"
                                    />
                                </div>
                            </template>
                        </div>

                        <div class="flex flex-wrap items-center gap-3">
                            <Button type="submit" :disabled="processing">
                                <Spinner v-if="processing" class="mr-2" />
                                Save changes
                            </Button>
                            <p
                                v-if="recentlySuccessful"
                                class="text-xs text-muted-foreground"
                            >
                                List updated.
                            </p>
                        </div>
                    </Form>
                </div>
            </div>
        </section>
        <TemplatePickerModal
            :is-open="isTemplatePickerOpen"
            @update:is-open="(open: boolean) => (isTemplatePickerOpen = open)"
            @select="handleTemplateSelect"
        />
    </AppLayout>
</template>
