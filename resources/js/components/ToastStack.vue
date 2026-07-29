<script setup>
import AppIcon from './AppIcon.vue';
import { dismissFlash, session } from '../store';

/**
 * Flash messages.
 *
 * Errors stay until dismissed (see `flash()` in store.js) — a failed deploy action
 * is not something to catch out of the corner of your eye — so the dismiss control
 * has to be a real target rather than eight pixels of underlined text.
 *
 * The messages themselves are announced through the shell's single live region,
 * not from here, so a toast that appears and auto-dismisses cannot leave a
 * detached region behind.
 */
const TONES = {
    success: { classes: 'border-success-line bg-success-surface text-success-text', icon: 'check' },
    error: { classes: 'border-danger-line bg-danger-surface text-danger-text', icon: 'error' },
    info: { classes: 'border-info-line bg-info-surface text-info-text', icon: 'info' },
};

const tone = (kind) => TONES[kind] ?? { classes: 'border-line bg-surface text-fg', icon: 'info' };
</script>

<template>
    <TransitionGroup name="toast" tag="div" class="space-y-2 empty:hidden">
        <div
            v-for="entry in session.flashes"
            :key="entry.id"
            class="flex items-start gap-2.5 rounded-xl border px-4 py-3 shadow-panel"
            :class="tone(entry.kind).classes"
        >
            <AppIcon :name="tone(entry.kind).icon" class="mt-0.5 size-4 shrink-0" />
            <p class="min-w-0 grow text-sm text-pretty">{{ entry.message }}</p>
            <button
                type="button"
                class="-my-1 -me-1.5 inline-flex size-8 shrink-0 items-center justify-center rounded-md text-current opacity-60 hover:bg-current/10 hover:opacity-100 motion-safe:transition-opacity motion-safe:duration-150"
                :aria-label="`Dismiss: ${entry.message}`"
                @click="dismissFlash(entry.id)"
            >
                <AppIcon name="close" class="size-4" />
            </button>
        </div>
    </TransitionGroup>
</template>

<style scoped>
/* Enter and exit are both ease-out, and the exit is the softer of the two: a
   message leaving should not pull the eye back to it. */
.toast-enter-active,
.toast-leave-active {
    transition-property: opacity, translate;
    transition-duration: 180ms;
    transition-timing-function: cubic-bezier(0.2, 0, 0, 1);
}

.toast-enter-from {
    opacity: 0;
    translate: 0 -6px;
}

.toast-leave-to {
    opacity: 0;
    translate: 0 -4px;
}

/* Leaving elements are out of flow, so the survivors slide up rather than snap. */
.toast-leave-active {
    position: absolute;
    inline-size: 100%;
}

.toast-move {
    transition: translate 180ms cubic-bezier(0.2, 0, 0, 1);
}

@media (prefers-reduced-motion: reduce) {
    .toast-enter-active,
    .toast-leave-active,
    .toast-move {
        transition-property: opacity;
    }

    .toast-enter-from,
    .toast-leave-to {
        translate: none;
    }
}
</style>
