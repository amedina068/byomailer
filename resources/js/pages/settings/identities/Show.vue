<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { PhWarning } from '@phosphor-icons/vue';
import axios from 'axios';
import { computed, ref } from 'vue';

import EmailIdentityRefreshController from '@/actions/App/Http/Controllers/Settings/EmailIdentityRefreshController';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { index } from '@/routes/identities';
import { type BreadcrumbItem } from '@/types';

import IdentityCustomLinkDomainSection from './show/IdentityCustomLinkDomainSection.vue';
import IdentityCustomTrackingSection from './show/IdentityCustomTrackingSection.vue';
import IdentityDeleteDialog, {
    type IdentityUsage,
} from './show/IdentityDeleteDialog.vue';
import IdentityDnsSection from './show/IdentityDnsSection.vue';
import IdentityHeaderActions from './show/IdentityHeaderActions.vue';
import IdentityReplyToSection from './show/IdentityReplyToSection.vue';
import IdentityStatusBadges from './show/IdentityStatusBadges.vue';
import IdentityTrackingSection from './show/IdentityTrackingSection.vue';

interface DnsRecord {
    type: string;
    name: string;
    value: string;
    priority?: number | null;
    verified_at?: string | null;
}

interface Identity {
    id: number;
    email: string;
    name?: string | null;
    domain?: string | null;
    identity_status?: string | null;
    dkim_status?: string | null;
    mail_from_status?: string | null;
    mail_from_subdomain?: string | null;
    dns_records: DnsRecord[];
    verified_at?: string | null;
    open_tracking_enabled?: boolean;
    click_tracking_enabled?: boolean;
    reply_to_email?: string | null;
    reply_to_name?: string | null;
    custom_tracking_domain?: string | null;
    custom_tracking_domain_verified_at?: string | null;
    custom_tracking_a_record_ip?: string | null;
    custom_link_domain?: string | null;
    custom_link_domain_verified_at?: string | null;
    custom_link_cname_target?: string | null;
    has_configuration_set?: boolean;
}

const props = defineProps<{
    identity: Identity;
    usage: IdentityUsage;
}>();

const breadcrumbItems: BreadcrumbItem[] = [
    { title: 'Email identities', href: index().url },
    { title: props.identity.email },
];

const refreshing = ref(false);
const refreshError = ref<string | null>(null);
const deleteOpen = ref(false);

const isVerified = computed(
    () => props.identity.identity_status === 'verified',
);

const showDnsSection = computed(() => !isVerified.value);

const refreshStatus = async (): Promise<void> => {
    refreshing.value = true;
    refreshError.value = null;

    try {
        const { data } = await axios.patch<{
            success: boolean;
            error?: string;
        }>(
            EmailIdentityRefreshController.url({
                emailIdentity: props.identity.id,
            }),
        );

        if (data.success !== true) {
            throw new Error(
                data.error || 'Unable to refresh the email identity.',
            );
        }

        await router.reload({ only: ['identity'] });
    } catch (error: unknown) {
        if (axios.isAxiosError(error) && error.response?.data?.error) {
            refreshError.value = error.response.data.error;
        } else if (error instanceof Error) {
            refreshError.value = error.message;
        } else {
            refreshError.value = 'Unable to refresh the email identity.';
        }
    } finally {
        refreshing.value = false;
    }
};
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbItems">
        <Head :title="identity.email" />

        <SettingsLayout>
            <div class="flex flex-col gap-6">
                <IdentityHeaderActions
                    :title="identity.email"
                    :description="identity.name ?? identity.domain ?? ''"
                    :is-verified="isVerified"
                    :refreshing="refreshing"
                    @refresh="refreshStatus"
                    @delete="deleteOpen = true"
                />

                <IdentityStatusBadges
                    :identity-status="identity.identity_status"
                    :dkim-status="identity.dkim_status"
                    :mail-from-status="identity.mail_from_status"
                    :mail-from-subdomain="identity.mail_from_subdomain"
                />

                <Alert v-if="refreshError" variant="destructive">
                    <PhWarning :size="14" />
                    <AlertTitle>Unable to refresh</AlertTitle>
                    <AlertDescription>{{ refreshError }}</AlertDescription>
                </Alert>

                <IdentityDnsSection
                    v-if="showDnsSection"
                    :records="identity.dns_records ?? []"
                />

                <IdentityReplyToSection
                    :identity-id="identity.id"
                    :reply-to-email="identity.reply_to_email"
                    :reply-to-name="identity.reply_to_name"
                />

                <template v-if="identity.has_configuration_set">
                    <IdentityTrackingSection
                        :identity-id="identity.id"
                        :initial-open-tracking="
                            identity.open_tracking_enabled ?? true
                        "
                        :initial-click-tracking="
                            identity.click_tracking_enabled ?? true
                        "
                    />

                    <IdentityCustomTrackingSection
                        v-if="
                            identity.custom_tracking_a_record_ip ||
                            identity.custom_tracking_domain
                        "
                        :identity-id="identity.id"
                        :custom-tracking-domain="
                            identity.custom_tracking_domain
                        "
                        :custom-tracking-domain-verified-at="
                            identity.custom_tracking_domain_verified_at
                        "
                        :custom-tracking-a-record-ip="
                            identity.custom_tracking_a_record_ip
                        "
                    />
                </template>

                <IdentityCustomLinkDomainSection
                    v-if="
                        identity.custom_link_cname_target ||
                        identity.custom_link_domain
                    "
                    :identity-id="identity.id"
                    :custom-link-domain="identity.custom_link_domain"
                    :custom-link-domain-verified-at="
                        identity.custom_link_domain_verified_at
                    "
                    :custom-link-cname-target="
                        identity.custom_link_cname_target
                    "
                />
            </div>
        </SettingsLayout>

        <IdentityDeleteDialog
            v-model:open="deleteOpen"
            :identity-id="identity.id"
            :identity-email="identity.email"
            :usage="usage"
        />
    </AppLayout>
</template>
