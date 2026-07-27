import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';
import vue from '@vitejs/plugin-vue';

/**
 * Two entry points, on purpose:
 *
 *   app.js   the single-page app — Vue, the router, every screen.
 *   auth.js  a few kilobytes for the server-rendered sign-in and TOTP pages, which
 *            have to keep working when the application bundle is what broke.
 *
 * No remote webfonts: the portal runs on the Salt master, which is not necessarily
 * allowed to reach the public internet, and an ops tool that waits on a CDN to
 * render is an ops tool that fails when you need it. The system font stack in
 * resources/css/app.css covers it.
 */
export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js', 'resources/js/auth.js'],
            refresh: true,
        }),
        vue(),
        tailwindcss(),
    ],
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
