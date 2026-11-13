import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/workspace.js', 'resources/js/create-order.js', 'resources/js/order-navigation.js', 'resources/js/delete-order.js', 'resources/js/record-payment.js', 'resources/js/order-checklist.js'],
            refresh: true,
        }),
        tailwindcss(),
    ],
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
