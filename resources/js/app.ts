import '../css/app.css';

import { createInertiaApp, router, usePage } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import type { DefineComponent } from 'vue';
import { createApp, h } from 'vue';

import { initializeTheme } from './composables/useAppearance';
import { toast } from './composables/useToast';
import { flashToasts } from './lib/flashToasts';
import { formatPageTitle } from './lib/pageTitle';
import type { AppPageProps } from './types';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

createInertiaApp({
    title: (title) => formatPageTitle(title, appName),
    resolve: (name) =>
        resolvePageComponent(
            `./pages/${name}.vue`,
            import.meta.glob<DefineComponent>('./pages/**/*.vue'),
        ),
    setup({ el, App, props, plugin }) {
        createApp({ render: () => h(App, props) })
            .use(plugin)
            .mount(el);
    },
    progress: {
        color: '#4B5563',
    },
});

// This will set light / dark mode on page load...
initializeTheme();

router.on('finish', (event) => {
    const flash = usePage<AppPageProps>().props.flash;

    for (const { variant, message } of flashToasts(event.detail.visit, flash)) {
        toast[variant](message);
    }
});
