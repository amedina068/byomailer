import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

/**
 * The public base URL of this install (APP_URL), without a trailing slash.
 * Documentation examples use it so that copied commands reach this server.
 */
export function useAppUrl() {
    const page = usePage();

    return computed<string>(() => {
        const configured = page.props.appUrl as string | undefined;
        const fallback =
            typeof window !== 'undefined' ? window.location.origin : '';

        return (configured || fallback).replace(/\/+$/, '');
    });
}
