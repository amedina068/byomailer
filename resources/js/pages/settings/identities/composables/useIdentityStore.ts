import axios from 'axios';
import { ref } from 'vue';

import EmailIdentityController from '@/actions/App/Http/Controllers/Settings/EmailIdentityController';

export interface DnsRecord {
    type: string;
    name: string;
    value: string;
    priority?: number | null;
    verified_at?: string | null;
}

export interface CreatedIdentity {
    id: number;
    email: string;
    dns_records: DnsRecord[];
    identity_status?: string | null;
}

export interface StorePayload {
    email: string;
    name: string | null;
    existing_aws_identity: boolean;
    open_tracking_enabled: boolean;
    click_tracking_enabled: boolean;
    mail_from_subdomain?: string;
}

export function useIdentityStore() {
    const submitting = ref(false);
    const submitError = ref<string | null>(null);

    const store = async (
        payload: StorePayload,
    ): Promise<CreatedIdentity | null> => {
        submitting.value = true;
        submitError.value = null;
        try {
            const { data } = await axios.post<{ identity?: CreatedIdentity }>(
                EmailIdentityController.store.url(),
                payload,
            );
            return data.identity ?? null;
        } catch (error: unknown) {
            if (axios.isAxiosError(error) && error.response?.data?.errors) {
                const errs = error.response.data.errors as Record<
                    string,
                    string[]
                >;
                const messages = [
                    'email',
                    'name',
                    'mail_from_subdomain',
                    'general',
                ]
                    .map((field) => errs[field]?.[0])
                    .filter(Boolean);
                submitError.value = messages.length
                    ? messages.join(' ')
                    : 'Unable to save the email identity.';
            } else if (
                axios.isAxiosError(error) &&
                error.response?.data?.error
            ) {
                submitError.value = error.response.data.error;
            } else {
                submitError.value = 'Unable to save the email identity.';
            }
            return null;
        } finally {
            submitting.value = false;
        }
    };

    return { submitting, submitError, store };
}
