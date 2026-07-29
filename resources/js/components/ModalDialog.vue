<script setup>
import { nextTick, onMounted, onUnmounted, ref, useId } from 'vue';

import AppIcon from './AppIcon.vue';

/**
 * A modal for the handful of actions that need confirming in place: cutting a
 * version, removing one, answering a canary prompt.
 *
 * Escape closes it and the backdrop does not, on purpose — every dialog in this
 * app is a decision, and dismissing one by clicking slightly off-target is not a
 * mistake worth allowing.
 *
 * The keyboard side of that promise is the rest of this file. Opening moves focus
 * inside and marks the page behind it `inert`, so Tab cannot walk out into a nav
 * that is no longer reachable by mouse; closing puts focus back on whatever opened
 * the dialog, which is usually a row in a table someone was working down.
 */
const props = defineProps({
    title: { type: String, required: true },
    subtitle: { type: String, default: null },
    danger: { type: Boolean, default: false },
    wide: { type: Boolean, default: false },
});

const emit = defineEmits(['close']);

const uid = useId();
const titleId = `${uid}-title`;
const subtitleId = `${uid}-subtitle`;

const panel = ref(null);
const opener = ref(null);

const FOCUSABLE = [
    'a[href]',
    'button:not(:disabled)',
    'input:not(:disabled):not([type="hidden"])',
    'select:not(:disabled)',
    'textarea:not(:disabled)',
    '[tabindex]:not([tabindex="-1"])',
].join(',');

const focusable = () => [...(panel.value?.querySelectorAll(FOCUSABLE) ?? [])].filter((el) => el.offsetParent !== null);

const onKeydown = (event) => {
    if (event.key === 'Escape') {
        event.stopPropagation();
        emit('close');

        return;
    }

    if (event.key !== 'Tab') {
        return;
    }

    const items = focusable();

    if (items.length === 0) {
        event.preventDefault();

        return;
    }

    const first = items[0];
    const last = items[items.length - 1];
    const active = document.activeElement;

    // Wrap at both ends. Without this, Tab from the last control lands in the
    // browser chrome and the next Tab is back in the inert page behind.
    if (event.shiftKey && (active === first || !panel.value.contains(active))) {
        event.preventDefault();
        last.focus();
    } else if (!event.shiftKey && active === last) {
        event.preventDefault();
        first.focus();
    }
};

/**
 * Only the elements this dialog inerted, so closing it cannot un-inert something
 * another part of the console set for its own reasons.
 */
const inerted = [];

onMounted(async () => {
    opener.value = document.activeElement instanceof HTMLElement ? document.activeElement : null;

    document.addEventListener('keydown', onKeydown, true);
    document.body.classList.add('overflow-hidden');

    await nextTick();

    // The dialog is teleported to the body, which is what makes this work: the
    // console's root is now a sibling rather than an ancestor, so marking it
    // inert takes the whole page behind out of the tab order and out of the
    // accessibility tree. Left inside the routed page it would have been its own
    // ancestor and nothing would have been inerted at all.
    [...document.body.children].forEach((el) => {
        if (!el.contains(panel.value) && !el.hasAttribute('inert')) {
            el.setAttribute('inert', '');
            inerted.push(el);
        }
    });

    // The first control, or the panel itself for a dialog that is only a message.
    (focusable()[0] ?? panel.value)?.focus();
});

onUnmounted(() => {
    document.removeEventListener('keydown', onKeydown, true);
    document.body.classList.remove('overflow-hidden');

    inerted.forEach((el) => el.removeAttribute('inert'));
    inerted.length = 0;

    opener.value?.focus();
});
</script>

<template>
    <!-- Teleported so the console's root becomes a sibling this can mark inert;
         `overscroll-contain` stops a flick inside the dialog from scrolling the
         page underneath it once the dialog's own scroll runs out. -->
    <Teleport to="body">
        <div
            class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto overscroll-contain bg-overlay p-4 pt-[max(1rem,env(safe-area-inset-top))] backdrop-blur-[2px] sm:p-8"
        >
            <div
                ref="panel"
                class="w-full rounded-2xl border bg-raised shadow-raised motion-safe:animate-[dialog-in_180ms_cubic-bezier(0.2,0,0,1)]"
                :class="[wide ? 'max-w-3xl' : 'max-w-xl', danger ? 'border-danger-line' : 'border-line']"
                role="dialog"
                aria-modal="true"
                :aria-labelledby="titleId"
                :aria-describedby="subtitle ? subtitleId : undefined"
                tabindex="-1"
            >
                <header class="flex items-start justify-between gap-4 border-b border-line px-5 py-4">
                    <div class="min-w-0">
                        <h2
                            :id="titleId"
                            class="text-base font-semibold text-pretty"
                            :class="danger ? 'text-danger-text' : 'text-fg'"
                        >
                            {{ title }}
                        </h2>
                        <p v-if="subtitle" :id="subtitleId" class="mt-1 text-sm text-pretty text-fg-subtle">
                            {{ subtitle }}
                        </p>
                    </div>

                    <button
                        type="button"
                        class="btn btn-ghost btn-icon -me-1.5 -mt-1"
                        aria-label="Close"
                        @click="emit('close')"
                    >
                        <AppIcon name="close" class="size-4" />
                    </button>
                </header>

                <div class="px-5 py-4">
                    <slot />
                </div>

                <footer
                    v-if="$slots.footer"
                    class="flex flex-wrap justify-end gap-2 border-t border-line bg-sunken px-5 py-3.5 pb-[max(0.875rem,env(safe-area-inset-bottom))]"
                >
                    <slot name="footer" />
                </footer>
            </div>
        </div>
    </Teleport>
</template>

<style>
/* Not scoped: a keyframe name has to stay global to be referenced by a utility.
   Enter only, and softly — an exit animation on a dialog you just confirmed a
   production change in is 180ms of nothing useful. */
@keyframes dialog-in {
    from {
        opacity: 0;
        translate: 0 -6px;
        scale: 0.99;
    }
}

@media (prefers-reduced-motion: reduce) {
    @keyframes dialog-in {
        from {
            opacity: 0;
        }
    }
}
</style>
