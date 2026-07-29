/**
 * The deployments app, from the browser's point of view.
 *
 * Everything the console shell needs to mount this app: where it lives, what
 * goes in the nav while you are inside it, which buttons belong in the chrome,
 * and its routes. The shell knows none of this itself — it reads the manifest,
 * the same way the server reads App\Apps\Deployments\DeploymentsApp.
 *
 * `requires` on a nav entry or a route is an ability from the bootstrap payload's
 * `can` map. It is convenience, not enforcement: every screen's data comes from
 * an endpoint that authorises independently, and the whole app is behind
 * `app.access:deployments` on the server. Hiding a link the account cannot use
 * just saves them the 403.
 */
const base = '/deployments';

/** Absolute path for a route inside this app. */
const at = (suffix = '') => `${base}${suffix}`;

export const deploymentsApp = {
    id: 'deployments',
    base,

    /** The app's own nav, shown in the console chrome while you are inside it. */
    nav: [
        { to: at(), label: 'Overview' },
        { to: at('/history'), label: 'History' },
        { to: at('/versions'), label: 'Versions' },
        { to: at('/repositories'), label: 'Repositories' },
        { to: at('/import'), label: 'Import', requires: 'manage_repositories' },
        { to: at('/patches'), label: 'Patches' },
        { to: at('/targets'), label: 'Targets', requires: 'manage_targets' },
        { to: at('/scoping'), label: 'Repository access', requires: 'manage_users' },
    ],

    /** The two things you come here to do, so they stay one click away. */
    actions: [
        { to: at('/undeploy'), label: 'Undeploy', requires: 'undeploy', variant: 'secondary' },
        { to: at('/new'), label: 'New deployment', requires: 'deploy', variant: 'primary' },
    ],

    /**
     * Rendered above every screen in this app. A fresh install has an empty
     * registry, and pointing at the import screen is almost always the right
     * first move — but that is this app's business, not the console's.
     */
    notice: () => import('./components/SetupNotice.vue'),

    /*
     * Pages are lazily imported, so opening the launcher does not download the
     * deployment wizard. Numeric ids are constrained so `/deployments/history`
     * is never read as a deployment called "history".
     */
    routes: [
        { path: at(), name: 'deployments.overview', component: () => import('./pages/DashboardPage.vue') },
        { path: at('/history'), name: 'deployments.history', component: () => import('./pages/DeploymentsPage.vue') },
        {
            path: at('/new'),
            name: 'deployments.deploy',
            component: () => import('./pages/DeployWizardPage.vue'),
            props: { intent: 'deploy' },
            meta: { requires: 'deploy' },
        },
        {
            path: at('/undeploy'),
            name: 'deployments.undeploy',
            component: () => import('./pages/DeployWizardPage.vue'),
            props: { intent: 'undeploy' },
            meta: { requires: 'undeploy' },
        },

        { path: at('/versions'), name: 'deployments.versions', component: () => import('./pages/VersionsPage.vue') },
        {
            path: at('/versions/:id(\\d+)'),
            name: 'deployments.version',
            component: () => import('./pages/VersionShowPage.vue'),
            props: true,
        },

        {
            path: at('/repositories'),
            name: 'deployments.repositories',
            component: () => import('./pages/RepositoriesPage.vue'),
        },
        {
            path: at('/repositories/new'),
            name: 'deployments.repository-new',
            component: () => import('./pages/RepositoryFormPage.vue'),
            meta: { requires: 'manage_repositories' },
        },
        {
            path: at('/repositories/config'),
            name: 'deployments.config-repository',
            component: () => import('./pages/ConfigRepositoryPage.vue'),
        },
        {
            path: at('/repositories/:id(\\d+)'),
            name: 'deployments.repository',
            component: () => import('./pages/RepositoryShowPage.vue'),
            props: true,
        },
        {
            path: at('/repositories/:id(\\d+)/edit'),
            name: 'deployments.repository-edit',
            component: () => import('./pages/RepositoryFormPage.vue'),
            props: true,
            meta: { requires: 'manage_repositories' },
        },

        {
            path: at('/import'),
            name: 'deployments.import',
            component: () => import('./pages/ImportPage.vue'),
            meta: { requires: 'manage_repositories' },
        },
        { path: at('/patches'), name: 'deployments.patches', component: () => import('./pages/PatchesPage.vue') },
        {
            path: at('/targets'),
            name: 'deployments.targets',
            component: () => import('./pages/TargetsPage.vue'),
            meta: { requires: 'manage_targets' },
        },
        {
            path: at('/scoping'),
            name: 'deployments.scoping',
            component: () => import('./pages/RepositoryScopePage.vue'),
            meta: { requires: 'manage_users' },
        },

        // Last, so a literal segment above always wins the match.
        {
            path: at('/:id(\\d+)'),
            name: 'deployments.run',
            component: () => import('./pages/DeploymentShowPage.vue'),
            props: true,
        },
    ],
};
