import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    build: {
        chunkSizeWarningLimit: 2000,
        rolldownOptions: {
            preserveEntrySignatures: 'exports-only',
        },
    },
    plugins: [
        laravel({
            input: ['resources/css/filament/app/theme.css', 'resources/js/filament/widgets/trip-statistics-table.tsx', 'resources/js/filament/widgets/vehicle-tracking-map.tsx'],
            refresh: true,
            fonts: [
                bunny('Instrument Sans', {
                    weights: [400, 500, 600],
                }),
            ],
        }),
        tailwindcss(),
    ],
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
