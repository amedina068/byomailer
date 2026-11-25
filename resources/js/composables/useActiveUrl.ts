import type { InertiaLinkProps } from '@inertiajs/vue3';
import { usePage } from '@inertiajs/vue3';
import { computed, readonly } from 'vue';

import { toUrl } from '@/lib/utils';

const page = usePage();
const currentUrlReactive = computed(
    () =>
        new URL(
            page.url,
            typeof window !== 'undefined'
                ? window.location.origin
                : 'http://localhost',
        ).pathname,
);

export function useActiveUrl() {
    function urlIsActive(
        urlToCheck: NonNullable<InertiaLinkProps['href']>,
        currentUrl?: string,
    ) {
        const urlToCompare = currentUrl ?? currentUrlReactive.value;
        const target = toUrl(urlToCheck);

        // A detail page such as /newsletters/5 keeps its section active.
        return (
            target === urlToCompare ||
            (target !== '/' && urlToCompare.startsWith(`${target}/`))
        );
    }

    return {
        currentUrl: readonly(currentUrlReactive),
        urlIsActive,
    };
}
