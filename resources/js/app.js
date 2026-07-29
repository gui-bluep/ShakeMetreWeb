import { createApp, h } from 'vue';
import { createInertiaApp } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
// Breeze's auth pages call route(), so Ziggy has to be registered globally.
import { ZiggyVue } from '../../vendor/tightenco/ziggy';

const appName = import.meta.env.VITE_APP_NAME || 'ShakeMetre';

createInertiaApp({
    title: (title) => (title ? `${title} — ${appName}` : appName),

    // Lazily resolved rather than eagerly globbed: the metre grid is a heavy page and has no
    // business being in the bundle that renders the login form.
    resolve: (name) =>
        resolvePageComponent(`./Pages/${name}.vue`, import.meta.glob('./Pages/**/*.vue')),

    setup({ el, App, props, plugin }) {
        return createApp({ render: () => h(App, props) })
            .use(plugin)
            .use(ZiggyVue)
            .mount(el);
    },

    progress: {
        color: '#4b5563',
    },
});
