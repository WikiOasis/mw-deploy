/**
 * The client-side app registry.
 *
 * Mirrors config/console.php: an app is installed when its manifest is listed
 * here, and the router, the chrome and the launcher are all built from the list.
 * Whether a given account may *open* an app is the server's answer, carried in
 * the bootstrap payload — a manifest being present only means the code shipped.
 */
import { deploymentsApp } from './deployments/manifest';

/** id => manifest, in launcher order. */
const manifests = {
    deployments: deploymentsApp,
};

export const allManifests = () => Object.values(manifests);

export const manifestFor = (id) => manifests[id] ?? null;

/**
 * Every app's routes, each tagged with the app it belongs to so the router guard
 * can turn away an account that has no access to it and the chrome knows which
 * nav to show.
 */
export const appRoutes = () =>
    allManifests().flatMap((manifest) =>
        manifest.routes.map((route) => ({
            ...route,
            meta: { ...(route.meta ?? {}), app: manifest.id },
        })),
    );
