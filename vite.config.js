import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';
import vue from '@vitejs/plugin-vue';

export default defineConfig({
    plugins: [
        // La police Instrument Sans servie par Bunny Fonts a été retirée : la DA est composée en
        // Aspekta, la police de shakedesign.be, auto-hébergée depuis resources/fonts. Plus aucun
        // domaine tiers n'est requis pour que l'application s'affiche correctement.
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
        }),
        vue({
            template: {
                transformAssetUrls: {
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
        // Breeze 2.4 installs a Tailwind v3 toolchain (postcss + tailwind.config.js); this
        // project stays on v4 through the Vite plugin, so those were removed again.
        tailwindcss(),
    ],
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
