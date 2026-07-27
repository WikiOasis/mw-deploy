import { createApp } from 'vue';

import './echo';
import AppShell from './components/AppShell.vue';
import { router } from './router';
import { hydrate } from './store';

/**
 * Entry point for the single-page app.
 *
 * The bootstrap payload is read out of the document before the app mounts, so the
 * first render already knows who is signed in and what they may do.
 */
hydrate();

const app = createApp(AppShell);

// Signing out is a form post to Fortify, and Blade is no longer rendering a token
// into every form, so the app exposes it once.
app.config.globalProperties.$csrf = document.querySelector('meta[name="csrf-token"]')?.content ?? '';

app.use(router);
app.mount('#app');
