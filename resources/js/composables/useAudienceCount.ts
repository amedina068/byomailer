import axios from 'axios';
import { ref, type Ref } from 'vue';

import { preview as newsletterContactsPreview } from '@/routes/newsletters/contacts';
import type { NewsletterFilter } from '@/types/newsletters';

interface UseAudienceCount {
    /** Number of contacts matching the last fetched filters, or null while unknown. */
    count: Ref<number | null>;
    /** True while a request is in flight. */
    loading: Ref<boolean>;
    /** Fetch the count immediately for the given filters. */
    fetch: (filters: NewsletterFilter[]) => Promise<void>;
    /** Reset the count and fetch again after the debounce window. */
    schedule: (filters: NewsletterFilter[]) => void;
}

/**
 * Live recipient/audience count for a set of newsletter-style filters, backed by
 * the shared `newsletters.contacts.preview` endpoint. Callers own when to fetch
 * (on mount, on a wizard step, on a filters watcher); this only owns the request,
 * the debounce, and the silent-failure handling.
 */
export function useAudienceCount(debounceMs = 600): UseAudienceCount {
    const count = ref<number | null>(null);
    const loading = ref(false);
    let timer: ReturnType<typeof setTimeout> | null = null;

    const fetch = async (filters: NewsletterFilter[]): Promise<void> => {
        loading.value = true;

        try {
            const { data } = await axios.post<{ count: number }>(
                newsletterContactsPreview().url,
                { filters },
            );

            count.value = data.count;
        } catch {
            // silently fail
        } finally {
            loading.value = false;
        }
    };

    const schedule = (filters: NewsletterFilter[]): void => {
        if (timer) {
            clearTimeout(timer);
        }

        count.value = null;
        timer = setTimeout(() => fetch(filters), debounceMs);
    };

    return { count, loading, fetch, schedule };
}
