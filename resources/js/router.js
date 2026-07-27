import { createRouter, createWebHistory } from 'vue-router';

import { can } from './store';

/**
 * Client-side routes.
 *
 * Every path here is also a real URL: the shell view is served for all of them, so
 * a deployment can be linked to, bookmarked and reloaded — which for an ops tool
 * matters more than the transition being instant. Pages are lazily imported so the
 * initial bundle is the dashboard, not the whole portal.
 *
 * Permission guards here are convenience, not enforcement. Every screen's data
 * comes from an API endpoint that authorises independently; hiding a route the user
 * cannot use just saves them the 403.
 */
const routes = [
    { path: '/', name: 'dashboard', component: () => import('./pages/DashboardPage.vue') },

    { path: '/deployments', name: 'deployments', component: () => import('./pages/DeploymentsPage.vue') },
    {
        path: '/deployments/new',
        name: 'deploy',
        component: () => import('./pages/DeployWizardPage.vue'),
        props: { intent: 'deploy' },
        meta: { requires: 'deploy' },
    },
    {
        path: '/deployments/undeploy',
        name: 'undeploy',
        component: () => import('./pages/DeployWizardPage.vue'),
        props: { intent: 'undeploy' },
        meta: { requires: 'undeploy' },
    },
    {
        path: '/deployments/:id',
        name: 'deployment',
        component: () => import('./pages/DeploymentShowPage.vue'),
        props: true,
    },

    { path: '/versions', name: 'versions', component: () => import('./pages/VersionsPage.vue') },
    {
        path: '/versions/:id',
        name: 'version',
        component: () => import('./pages/VersionShowPage.vue'),
        props: true,
    },

    { path: '/repositories', name: 'repositories', component: () => import('./pages/RepositoriesPage.vue') },
    {
        path: '/repositories/new',
        name: 'repository-new',
        component: () => import('./pages/RepositoryFormPage.vue'),
        meta: { requires: 'manage_repositories' },
    },
    {
        path: '/repositories/config',
        name: 'config-repository',
        component: () => import('./pages/ConfigRepositoryPage.vue'),
    },
    {
        path: '/repositories/:id',
        name: 'repository',
        component: () => import('./pages/RepositoryShowPage.vue'),
        props: true,
    },
    {
        path: '/repositories/:id/edit',
        name: 'repository-edit',
        component: () => import('./pages/RepositoryFormPage.vue'),
        props: true,
        meta: { requires: 'manage_repositories' },
    },

    {
        path: '/import',
        name: 'import',
        component: () => import('./pages/ImportPage.vue'),
        meta: { requires: 'manage_repositories' },
    },

    { path: '/patches', name: 'patches', component: () => import('./pages/PatchesPage.vue') },
    {
        path: '/targets',
        name: 'targets',
        component: () => import('./pages/TargetsPage.vue'),
        meta: { requires: 'manage_targets' },
    },
    {
        path: '/users',
        name: 'users',
        component: () => import('./pages/UsersPage.vue'),
        meta: { requires: 'manage_users' },
    },

    { path: '/:pathMatch(.*)*', name: 'not-found', component: () => import('./pages/NotFoundPage.vue') },
];

export const router = createRouter({
    history: createWebHistory(),
    routes,
    scrollBehavior: () => ({ top: 0 }),
});

router.beforeEach((to) => {
    const required = to.meta.requires;

    if (typeof required === 'string' && !can(required)) {
        return { name: 'dashboard' };
    }

    return true;
});
