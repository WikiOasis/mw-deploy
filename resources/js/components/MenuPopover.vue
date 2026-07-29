<script setup>
import { nextTick, onUnmounted, ref, useId, watch } from 'vue';

import AppIcon from './AppIcon.vue';

/**
 * The account menu in the chrome, and anything else shaped like it.
 *
 * A menu is the one place a custom widget is worth building rather than reaching
 * for a native element, and the price is that every keyboard behaviour has to be
 * written out: Escape closes and puts focus back on the trigger, arrow keys walk
 * the items, Home and End jump to the ends, Tab leaves. Without that it is a div
 * that only works with a mouse.
 */
defineProps({
    label: { type: String, required: true },
    /** Aligns the panel to the trailing edge, which is where chrome menus sit. */
    align: { type: String, default: 'end' },
});

const uid = useId();
const panelId = `${uid}-panel`;

const open = ref(false);
const trigger = ref(null);
const panel = ref(null);

const items = () =>
    [...(panel.value?.querySelectorAll('a[href], button:not(:disabled)') ?? [])].filter(
        (el) => el.offsetParent !== null,
    );

const close = ({ restoreFocus = true } = {}) => {
    if (!open.value) {
        return;
    }

    open.value = false;

    if (restoreFocus) {
        trigger.value?.focus();
    }
};

const onDocumentPointerDown = (event) => {
    if (!panel.value?.contains(event.target) && !trigger.value?.contains(event.target)) {
        close({ restoreFocus: false });
    }
};

const onKeydown = (event) => {
    if (event.key === 'Escape') {
        event.preventDefault();
        close();

        return;
    }

    const list = items();

    if (list.length === 0) {
        return;
    }

    const index = list.indexOf(document.activeElement);

    if (event.key === 'ArrowDown') {
        event.preventDefault();
        list[(index + 1) % list.length].focus();
    } else if (event.key === 'ArrowUp') {
        event.preventDefault();
        list[(index - 1 + list.length) % list.length].focus();
    } else if (event.key === 'Home') {
        event.preventDefault();
        list[0].focus();
    } else if (event.key === 'End') {
        event.preventDefault();
        list[list.length - 1].focus();
    }
};

watch(open, async (isOpen) => {
    if (!isOpen) {
        document.removeEventListener('pointerdown', onDocumentPointerDown);

        return;
    }

    await nextTick();

    document.addEventListener('pointerdown', onDocumentPointerDown);
    items()[0]?.focus();
});

onUnmounted(() => document.removeEventListener('pointerdown', onDocumentPointerDown));
</script>

<template>
    <div class="relative" @keydown="onKeydown">
        <button
            ref="trigger"
            type="button"
            class="btn btn-ghost max-w-48"
            :aria-expanded="open"
            :aria-controls="open ? panelId : undefined"
            @click="open = !open"
        >
            <slot name="trigger">
                <span class="truncate">{{ label }}</span>
            </slot>
            <AppIcon
                name="chevron-down"
                class="size-3.5 shrink-0 motion-safe:transition-transform motion-safe:duration-150"
                :class="open ? 'rotate-180' : ''"
            />
        </button>

        <div
            v-if="open"
            :id="panelId"
            ref="panel"
            class="absolute z-30 mt-1.5 min-w-56 rounded-xl border border-line bg-raised p-1.5 shadow-raised motion-safe:animate-[menu-in_140ms_cubic-bezier(0.2,0,0,1)]"
            :class="align === 'end' ? 'end-0' : 'start-0'"
            role="menu"
            :aria-label="label"
        >
            <slot :close="close" />
        </div>
    </div>
</template>

<style>
@keyframes menu-in {
    from {
        opacity: 0;
        translate: 0 -4px;
    }
}

@media (prefers-reduced-motion: reduce) {
    @keyframes menu-in {
        from {
            opacity: 0;
        }
    }
}
</style>
