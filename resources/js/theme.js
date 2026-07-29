/**
 * Which appearance the console is in.
 *
 * Deliberately free of Vue: the sign-in and TOTP pages ship a few kilobytes of
 * vanilla JavaScript rather than the application bundle, and the theme control
 * has to work there too — the day the app bundle is what broke is not the day to
 * find the console stuck in the wrong appearance.
 *
 * Three states, not two. "System" is the default and follows the OS, and it stays
 * followed: pick it and the console changes with the desktop at dusk without
 * anyone touching a setting. Light and dark are explicit overrides.
 *
 * The attribute this writes is read by resources/css/app.css. A matching inline
 * snippet runs in the document head before first paint (see the layouts), so the
 * page never renders light and then flips.
 */
const STORAGE_KEY = 'console-theme';

const PREFERENCES = ['system', 'light', 'dark'];

const darkQuery = window.matchMedia('(prefers-color-scheme: dark)');

const listeners = new Set();

/** @returns {'system'|'light'|'dark'} */
export function preference() {
    try {
        const stored = window.localStorage.getItem(STORAGE_KEY);

        return PREFERENCES.includes(stored) ? stored : 'system';
    } catch {
        // Private browsing, a locked-down profile, a storage quota. Not worth
        // failing over; the console just stops remembering.
        return 'system';
    }
}

/** What `preference()` actually resolves to right now. @returns {'light'|'dark'} */
export function resolved() {
    const chosen = preference();

    return chosen === 'system' ? (darkQuery.matches ? 'dark' : 'light') : chosen;
}

function paint() {
    document.documentElement.dataset.theme = resolved();

    listeners.forEach((listener) => listener(preference(), resolved()));
}

export function setPreference(next) {
    if (!PREFERENCES.includes(next)) {
        return;
    }

    try {
        if (next === 'system') {
            window.localStorage.removeItem(STORAGE_KEY);
        } else {
            window.localStorage.setItem(STORAGE_KEY, next);
        }
    } catch {
        // Same as above: the choice still applies to this page.
    }

    paint();
}

/** Called on every change, and once immediately. Returns an unsubscribe. */
export function onThemeChange(listener) {
    listeners.add(listener);
    listener(preference(), resolved());

    return () => listeners.delete(listener);
}

// Only meaningful while the preference is "system", but harmless otherwise:
// `resolved()` ignores the query in the other two states.
darkQuery.addEventListener('change', paint);

paint();

/**
 * The three-way control, for the server-rendered pages that have no components.
 *
 *   <div data-theme-switch>
 *     <button data-theme-option="system">…</button>
 *   </div>
 */
export function wireThemeSwitch() {
    const groups = document.querySelectorAll('[data-theme-switch]');

    if (groups.length === 0) {
        return;
    }

    groups.forEach((group) => {
        group.querySelectorAll('[data-theme-option]').forEach((button) => {
            button.addEventListener('click', () => setPreference(button.dataset.themeOption));
        });
    });

    onThemeChange((chosen) => {
        groups.forEach((group) => {
            group.querySelectorAll('[data-theme-option]').forEach((button) => {
                button.setAttribute('aria-pressed', String(button.dataset.themeOption === chosen));
            });
        });
    });
}
