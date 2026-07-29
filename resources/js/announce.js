import { readonly, ref } from 'vue';

/**
 * The console's two live regions.
 *
 * A screen reader announces an `aria-live` region reliably when the region was
 * already in the page and its text changed. Insert the region and its text in the
 * same tick — which is what a `v-if`-ed status message does — and whether it is
 * read at all depends on the screen reader. So there are exactly two, the shell
 * renders both for the life of the page, and everything that needs to say
 * something writes here.
 *
 * `announce` is polite: "loading", "no results", "queued". It waits its turn.
 * `alert` interrupts, and is only for the things that are worth interrupting for —
 * a deploy action that failed, a request that was refused.
 */
const politeText = ref('');
const assertiveText = ref('');

export const polite = readonly(politeText);
export const assertive = readonly(assertiveText);

/**
 * Setting a region to the text it already holds is a no-op to the accessibility
 * tree, so a repeat has to clear first. Two identical failures in a row is exactly
 * the case where the second one must still be heard.
 */
function say(target, text) {
    const next = text ?? '';

    if (next !== '' && next === target.value) {
        target.value = '';
        window.requestAnimationFrame(() => {
            target.value = next;
        });

        return;
    }

    target.value = next;
}

export function announce(text) {
    say(politeText, text);
}

export function alert(text) {
    say(assertiveText, text);
}
