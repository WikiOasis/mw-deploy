import { computed, reactive, readonly } from 'vue';

import { api, endpoint } from './api';

/**
 * Application state that outlives a single page: who is signed in, what they may
 * do, the deploy-wide settings, and the flash messages.
 *
 * Small enough not to want a state library. Permissions are the only thing read
 * from more than a couple of places, and they never change within a session.
 */
const state = reactive({
    authenticated: false,
    user: null,
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
    const element = document.getElementById('mwdeploy-bootstrap');

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
        can: payload?.can ?? {},
        settings: payload?.settings ?? {},
        reference: payload?.reference ?? state.reference,
        counts: payload?.counts ?? {},
    });
}

export const can = (ability) => state.can[ability] === true;

export const hasPermission = (permission) => (state.user?.permissions ?? []).includes(permission);

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

    flash(error?.message ?? 'Something went wrong.', 'error');
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
