import { computed, reactive, readonly } from 'vue';

import { alert, announce } from './announce';
import { api, endpoint } from './api';

/**
 * Console state that outlives a single page: who is signed in, which apps they
 * may open, what they may do inside them, the deploy-wide settings, and the
 * flash messages.
 *
 * Small enough not to want a state library. Permissions are the only thing read
 * from more than a couple of places, and they never change within a session.
 */
const state = reactive({
    authenticated: false,
    user: null,
    // Every app this install has switched on, each with an `accessible` flag —
    // the server's answer, never inferred from the permission list here.
    apps: [],
    can: {},
    settings: {},
    reference: { repository_types: [], target_roles: [], permissions: {} },
    counts: {},
    flashes: [],
});

let nextFlashId = 1;

export const session = readonly(state);

/**
 * Seed from the payload the shell inlined, so the first paint has real
 * permissions rather than an empty chrome.
 */
export function hydrate() {
    const element = document.getElementById('console-bootstrap');

    if (element === null) {
        return;
    }

    try {
        apply(JSON.parse(element.textContent));
    } catch {
        // A malformed payload is not worth blocking the app over; the API call
        // below is authoritative anyway.
    }
}

export async function refreshSession() {
    apply(await api.get(endpoint('bootstrap')));
}

function apply(payload) {
    Object.assign(state, {
        authenticated: payload?.authenticated ?? false,
        user: payload?.user ?? null,
        apps: payload?.apps ?? [],
        can: payload?.can ?? {},
        settings: payload?.settings ?? {},
        reference: payload?.reference ?? state.reference,
        counts: payload?.counts ?? {},
    });
}

export const can = (ability) => state.can[ability] === true;

export const hasPermission = (permission) => (state.user?.permissions ?? []).includes(permission);

/** The console's name, as configured on the server. */
export const consoleName = computed(() => state.settings.console_name || state.settings.app_name || 'Console');

/** Every installed app, openable or not — the launcher shows both. */
export const apps = computed(() => state.apps);

/** The apps on this account's launcher. */
export const availableApps = computed(() => state.apps.filter((entry) => entry.accessible));

export const appById = (id) => state.apps.find((entry) => entry.id === id) ?? null;

/**
 * Whether this account may open an app. The server decided; this only reads the
 * answer, so a client-side bug cannot widen access.
 */
export const canUseApp = (id) => appById(id)?.accessible === true;

/**
 * True on a fresh install: nothing registered yet. The UI points at the import
 * screen when it sees this, because filling the registry in from the tree is
 * almost always the right first move.
 */
export const registryIsEmpty = computed(
    () => (state.counts.repositories ?? 0) === 0 && (state.counts.versions ?? 0) === 0,
);

/**
 * @param {'success'|'error'|'info'} kind
 */
export function flash(message, kind = 'success') {
    if (!message) {
        return;
    }

    const entry = { id: nextFlashId++, message, kind };

    state.flashes.push(entry);

    // A toast is a visual channel only; on its own it is invisible to a screen
    // reader. Errors interrupt, the rest wait their turn.
    (kind === 'error' ? alert : announce)(message);

    // Errors stay until dismissed: a failed deploy action is not something to
    // notice out of the corner of your eye.
    if (kind !== 'error') {
        window.setTimeout(() => dismissFlash(entry.id), 6000);
    }
}

export function flashError(error) {
    if (error?.isValidation) {
        error.all().forEach((message) => flash(message, 'error'));

        return;
    }

    // Not "something went wrong": if the request got far enough to fail, the
    // server said why, and where it did not, the useful thing to say is which
    // half of the console to suspect.
    flash(error?.message ?? 'The console could not reach the server. Check your connection and try again.', 'error');
}

export function dismissFlash(id) {
    const index = state.flashes.findIndex((entry) => entry.id === id);

    if (index !== -1) {
        state.flashes.splice(index, 1);
    }
}

export function countsChanged(changes) {
    Object.assign(state.counts, changes);
}
