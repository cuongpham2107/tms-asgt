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
            input: ['resources/css/filament/app/theme.css', 'resources/js/filament/forms/card-picker.tsx', 'resources/js/filament/widgets/trip-statistics-table.tsx', 'resources/js/filament/widgets/vehicle-tracking-map.tsx', 'resources/js/filament/widgets/vehicle-dispatch-table.tsx', 'resources/js/filament/gps-modal-map.ts'],
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
