<script setup lang="ts">
import { Form, Head, router } from '@inertiajs/vue3';
import { ref } from 'vue';

import TeamController from '@/actions/App/Http/Controllers/Settings/TeamController';
import UserArchiveController from '@/actions/App/Http/Controllers/Settings/UserArchiveController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { ConfirmDialog } from '@/components/ui/confirm-dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import type { User } from '@/types';

interface TeamMember {
    id: number;
    uuid: string;
    name: string;
    email: string;
    role: User['role'];
    role_label: string;
    invite_expires_at: string | null;
    archived_at: string | null;
    created_at: string;
    is_current_user: boolean;
}

interface RoleOption {
    value: User['role'];
    label: string;
}

const props = defineProps<{
    teamMembers: TeamMember[];
    availableRoles: RoleOption[];
    canManageTeam: boolean;
    inviteExpiresInHours: number;
}>();

const formatDateTime = (value?: string | null) =>
    value ? new Date(value).toLocaleString() : null;

const invitationIsExpired = (value?: string | null) => {
    if (!value) {
        return false;
    }

    return new Date(value).getTime() < Date.now();
};

const memberIsPending = (member: TeamMember) =>
    Boolean(member.invite_expires_at);
const memberIsArchived = (member: TeamMember) => Boolean(member.archived_at);

const memberToArchive = ref<TeamMember | null>(null);
const archiving = ref(false);
const archiveErrors = ref<Record<number, string>>({});

const archiveMember = () => {
    const member = memberToArchive.value;

    if (!member) {
        return;
    }

    archiving.value = true;
    router.post(
        UserArchiveController.store.url({ teamMember: member.id }),
        {},
        {
            preserveScroll: true,
            onError: (errors) => {
                archiveErrors.value[member.id] = errors.member;
            },
            onFinish: () => {
                archiving.value = false;
                memberToArchive.value = null;
            },
        },
    );
};

const breadcrumbs = [
    {
        title: 'Team',
        href: TeamController.index().url,
    },
];
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Team settings" />

        <SettingsLayout>
            <div class="flex flex-col space-y-10">
                <section class="space-y-4">
                    <Heading
                        variant="small"
                        title="Invite teammates"
                        :description="`Invite teammates. An invitation expires after ${
                            inviteExpiresInHours ?? 24
                        } hours.`"
                    />

                    <div
                        class="rounded-xl border border-border bg-card p-6 shadow-sm"
                    >
                        <Form
                            v-bind="TeamController.store.form()"
                            v-slot="{ errors, processing }"
                            class="grid gap-6 md:grid-cols-2"
                        >
                            <div class="grid gap-2">
                                <Label for="invite-name">Full name</Label>
                                <Input
                                    id="invite-name"
                                    name="name"
                                    type="text"
                                    required
                                    autocomplete="off"
                                    placeholder="Sofia Perez"
                                    :disabled="processing || !canManageTeam"
                                />
                                <InputError :message="errors.name" />
                            </div>

                            <div class="grid gap-2">
                                <Label for="invite-email">Email address</Label>
                                <Input
                                    id="invite-email"
                                    name="email"
                                    type="email"
                                    required
                                    autocomplete="off"
                                    placeholder="sofia@example.com"
                                    :disabled="processing || !canManageTeam"
                                />
                                <InputError :message="errors.email" />
                            </div>

                            <div class="grid gap-2">
                                <Label for="invite-role"
                                    >Role after joining</Label
                                >
                                <select
                                    id="invite-role"
                                    name="role"
                                    class="rounded-lg border border-border bg-background px-3 py-2 text-sm focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none disabled:opacity-70"
                                    :disabled="processing || !canManageTeam"
                                    :value="
                                        props.availableRoles[0]?.value ?? ''
                                    "
                                    required
                                >
                                    <option
                                        v-for="role in props.availableRoles"
                                        :key="role.value"
                                        :value="role.value"
                                    >
                                        {{ role.label }}
                                    </option>
                                </select>
                                <InputError :message="errors.role" />
                            </div>

                            <div class="md:col-span-2">
                                <Button
                                    type="submit"
                                    :disabled="processing || !canManageTeam"
                                >
                                    Send invitation
                                </Button>
                            </div>
                        </Form>
                    </div>
                </section>

                <section class="space-y-4">
                    <Heading
                        variant="small"
                        title="Team members"
                        description="Manage roles and remove members from your workspace"
                    />

                    <div
                        class="rounded-xl border border-border bg-card shadow-sm"
                    >
                        <ul class="divide-y divide-border">
                            <li
                                v-for="member in teamMembers"
                                :key="member.id"
                                class="flex flex-col gap-4 p-6 md:flex-row md:items-center md:justify-between"
                            >
                                <div>
                                    <div class="flex items-center gap-3">
                                        <div>
                                            <p class="text-base font-semibold">
                                                {{ member.name }}
                                            </p>
                                            <p
                                                class="text-sm text-muted-foreground"
                                            >
                                                {{ member.email }}
                                            </p>
                                        </div>
                                        <Badge
                                            :class="[
                                                memberIsArchived(member)
                                                    ? 'bg-muted text-muted-foreground'
                                                    : memberIsPending(member)
                                                      ? 'bg-amber-100 text-amber-800 dark:bg-amber-500/10 dark:text-amber-400'
                                                      : member.role === 'admin'
                                                        ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-400'
                                                        : 'bg-muted text-foreground',
                                            ]"
                                        >
                                            {{ member.role_label }}
                                        </Badge>
                                    </div>

                                    <div
                                        v-if="memberIsPending(member)"
                                        class="mt-3 space-y-1 text-sm text-muted-foreground"
                                    >
                                        <p>
                                            Invitation
                                            <span
                                                v-if="
                                                    invitationIsExpired(
                                                        member.invite_expires_at,
                                                    )
                                                "
                                            >
                                                expired — invite them again to
                                                send a fresh link.
                                            </span>
                                            <span v-else>
                                                expires
                                                {{
                                                    formatDateTime(
                                                        member.invite_expires_at,
                                                    )
                                                }}.
                                            </span>
                                        </p>
                                        <p>
                                            They will join as
                                            <span
                                                class="font-medium text-foreground"
                                            >
                                                {{ member.role_label }}
                                            </span>
                                            once they accept.
                                        </p>
                                    </div>

                                    <div
                                        v-if="memberIsArchived(member)"
                                        class="mt-3 text-sm text-muted-foreground"
                                    >
                                        Archived
                                        {{
                                            formatDateTime(
                                                member.archived_at,
                                            ) ?? 'recently'
                                        }}.
                                    </div>
                                </div>

                                <div class="flex flex-col gap-3 md:items-end">
                                    <Form
                                        v-if="
                                            canManageTeam &&
                                            !member.is_current_user &&
                                            !memberIsPending(member) &&
                                            !memberIsArchived(member)
                                        "
                                        v-bind="
                                            TeamController.update.form({
                                                teamMember: member.id,
                                            })
                                        "
                                        v-slot="{ processing, errors }"
                                        class="flex items-center gap-3"
                                    >
                                        <select
                                            name="role"
                                            :aria-label="`Role of ${member.name}`"
                                            class="rounded-lg border border-border bg-background px-3 py-1.5 text-sm focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none disabled:opacity-70"
                                            :default-value="member.role"
                                            :disabled="processing"
                                        >
                                            <option
                                                v-for="role in availableRoles"
                                                :key="role.value"
                                                :value="role.value"
                                            >
                                                {{ role.label }}
                                            </option>
                                        </select>
                                        <Button
                                            type="submit"
                                            variant="secondary"
                                            size="sm"
                                            :disabled="processing"
                                        >
                                            Update
                                        </Button>
                                        <InputError :message="errors.role" />
                                    </Form>

                                    <div
                                        v-if="
                                            canManageTeam &&
                                            !member.is_current_user &&
                                            !memberIsArchived(member)
                                        "
                                        class="flex flex-col items-start gap-2"
                                    >
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="sm"
                                            class="text-destructive hover:text-destructive"
                                            @click="memberToArchive = member"
                                        >
                                            Archive member
                                        </Button>
                                        <InputError
                                            :message="archiveErrors[member.id]"
                                        />
                                    </div>

                                    <Form
                                        v-if="
                                            canManageTeam &&
                                            memberIsArchived(member)
                                        "
                                        v-bind="
                                            UserArchiveController.destroy.form({
                                                teamMember: member.id,
                                            })
                                        "
                                        v-slot="{ processing, errors }"
                                        class="flex flex-col items-start gap-2"
                                    >
                                        <Button
                                            type="submit"
                                            variant="ghost"
                                            size="sm"
                                            :disabled="processing"
                                        >
                                            Restore member
                                        </Button>
                                        <InputError :message="errors.member" />
                                    </Form>
                                </div>
                            </li>
                        </ul>

                        <p
                            v-if="!teamMembers.length"
                            class="p-6 text-sm text-muted-foreground"
                        >
                            No team members yet. Invite someone above to get
                            started.
                        </p>
                    </div>
                </section>
            </div>
        </SettingsLayout>

        <ConfirmDialog
            :is-open="memberToArchive !== null"
            :title="`Archive ${memberToArchive?.name ?? ''}?`"
            description="The member cannot log in. All API tokens of the member are deleted. If you restore the member, the API tokens do not come back."
            confirm-label="Archive member"
            :processing="archiving"
            @update:is-open="(open) => !open && (memberToArchive = null)"
            @confirm="archiveMember"
        />
    </AppLayout>
</template>
