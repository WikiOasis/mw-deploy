import { createRouter, createWebHistory } from 'vue-router';

import { appRoutes } from './apps';
import { can, canUseApp } from './store';

/**
 * Client-side routes for the console.
 *
 * Three tiers, in order: the launcher and the console's own screens, then every
 * installed app's routes under its own path prefix (contributed by its manifest,
 * see resources/js/apps/index.js), then redirects for the URLs this application
 * used before the console had apps at all.
 *
 * Every path here is also a real URL: the shell view is served for all of them,
 * so a deployment can be linked to, bookmarked and reloaded — which for an ops
 * tool matters more than the transition being instant.
 *
 * The guards below are convenience, not enforcement. Every screen's data comes
 * from an endpoint that authorises independently, and each app's endpoints sit
 * behind `app.access:<id>` on the server; turning a route away just saves the
 * account a 403.
 */
const consoleRoutes = [
    { path: '/', name: 'launcher', component: () => import('./console/pages/LauncherPage.vue') },
    {
        path: '/access',
        name: 'access',
        component: () => import('./console/pages/AccessPage.vue'),
        meta: { requires: 'manage_users' },
    },
];

/**
 * The pre-console URLs. Every screen that used to sit at the top level now lives
 * inside the deployments app, and links to them are in people's bookmarks, wikis
 * and incident notes.
 */
const legacyRoutes = [
    ...['/versions', '/repositories', '/patches', '/targets', '/import'].flatMap((prefix) => [
        { path: prefix, redirect: `/deployments${prefix}` },
        { path: `${prefix}/:rest(.*)`, redirect: (to) => `/deployments${prefix}/${to.params.rest}` },
    ]),
    // Users and roles became the console's central access management.
    { path: '/users', redirect: '/access' },
];

const routes = [
    ...consoleRoutes,
    ...appRoutes(),
    ...legacyRoutes,
    { path: '/:pathMatch(.*)*', name: 'not-found', component: () => import('./console/pages/NotFoundPage.vue') },
];

export const router = createRouter({
    history: createWebHistory(),
    routes,
    scrollBehavior: () => ({ top: 0 }),
});

router.beforeEach((to) => {
    // An app this account has no grant in: back to the launcher, which shows it
    // as a locked tile rather than pretending it does not exist.
    if (typeof to.meta.app === 'string' && !canUseApp(to.meta.app)) {
        return { name: 'launcher' };
    }

    const required = to.meta.requires;

    if (typeof required === 'string' && !can(required)) {
        return { name: 'launcher' };
    }

    return true;
});
