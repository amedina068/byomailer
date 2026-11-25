import axios from 'axios';
import { ref } from 'vue';

import { awsDetails, awsList } from '@/routes/identities';

export interface AwsIdentity {
    name: string;
    sending_enabled: boolean;
    verification_status: string;
    already_added: boolean;
}

export function useAwsIdentities(hasCredential: boolean) {
    const identities = ref<AwsIdentity[]>([]);
    const loading = ref(false);
    const loadError = ref<string | null>(null);

    const mailFromExisting = ref<string | null>(null);
    const mailFromLoading = ref(false);
    const mailFromError = ref('');

    const loadIdentities = async (): Promise<void> => {
        if (!hasCredential) {
            loadError.value = 'No AWS credentials found. Finish the AWS onboarding first.';
            return;
        }
        loading.value = true;
        loadError.value = null;
        try {
            const { data } = await axios.get<{
                success: boolean;
                identities?: AwsIdentity[];
                error?: string;
            }>(awsList().url);
            if (!data.success) {
                throw new Error(data.error || 'Unable to list AWS identities.');
            }
            identities.value = data.identities ?? [];
        } catch (error: unknown) {
            if (axios.isAxiosError(error) && error.response?.data?.error) {
                loadError.value = error.response.data.error;
            } else if (error instanceof Error) {
                loadError.value = error.message;
            } else {
                loadError.value = 'Unable to list AWS identities.';
            }
        } finally {
            loading.value = false;
        }
    };

    const fetchDetails = async (
        domain: string,
        fallbackMailFromInput: (suggested: string) => void,
    ): Promise<void> => {
        mailFromLoading.value = true;
        mailFromError.value = '';
        mailFromExisting.value = null;
        try {
            const { data } = await axios.get<{
                success: boolean;
                mail_from_domain: string | null;
                error?: string;
            }>(awsDetails().url, { params: { domain } });
            if (!data.success) {
                throw new Error(data.error || 'Unable to load identity details.');
            }
            mailFromExisting.value = data.mail_from_domain;
            if (!data.mail_from_domain) {
                fallbackMailFromInput(`mas.${domain}`);
            }
        } catch (error: unknown) {
            mailFromExisting.value = null;
            fallbackMailFromInput(`mas.${domain}`);
            if (axios.isAxiosError(error) && error.response?.data?.error) {
                mailFromError.value = error.response.data.error;
            } else if (error instanceof Error) {
                mailFromError.value = error.message;
            }
        } finally {
            mailFromLoading.value = false;
        }
    };

    const reset = (): void => {
        mailFromExisting.value = null;
        mailFromError.value = '';
    };

    return {
        identities,
        loading,
        loadError,
        mailFromExisting,
        mailFromLoading,
        mailFromError,
        loadIdentities,
        fetchDetails,
        reset,
    };
}
