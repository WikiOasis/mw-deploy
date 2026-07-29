import { createApp } from 'vue';

import './echo';
// Imported for its side effect: resolving the stored appearance onto the root
// element. The document head has already done this once before first paint; this
// is what keeps it followed when the OS setting changes mid-session.
import './theme';
import ConsoleShell from './console/ConsoleShell.vue';
import { router } from './router';
import { hydrate } from './store';

/**
 * Entry point for the console.
 *
 * The bootstrap payload is read out of the document before the app mounts, so the
 * first render already knows who is signed in, which apps they may open and what
 * they may do inside them.
 */
hydrate();

const app = createApp(ConsoleShell);

// Signing out is a form post to Fortify, and Blade is no longer rendering a token
// into every form, so the app exposes it once.
app.config.globalProperties.$csrf = document.querySelector('meta[name="csrf-token"]')?.content ?? '';

app.use(router);
app.mount('#app');
