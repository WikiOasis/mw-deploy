<script setup>
import { watch } from 'vue';

import AppButton from './AppButton.vue';
import AppIcon from './AppIcon.vue';
import { announce } from '../announce';

/**
 * The three states every screen has: loading, failed, or empty.
 *
 * Wrapping them once keeps a failed API call from rendering as a convincingly
 * empty page — "there are no deployments" and "we could not ask" must never look
 * the same in an ops tool.
 *
 * The empty state takes a title and a line of orientation rather than one flat
 * sentence, because an empty screen is where someone new to the console usually
 * lands first, and "nothing here yet" tells them neither what the screen is for
 * nor what to do about it. The `empty-action` slot is where the next step goes.
 *
 * Nothing here wraps the slot in an element: the screens put a panel and a
 * pagination bar inside this component and rely on their own `space-y-*` reaching
 * them. State changes are announced through the shell's live region instead.
 */
const props = defineProps({
    loading: { type: Boolean, default: false },
    error: { type: Object, default: null },
    empty: { type: Boolean, default: false },
    /** What this screen holds when it does have something. */
    emptyTitle: { type: String, default: 'Nothing here yet' },
    /** How it gets filled. */
    emptyMessage: { type: String, default: null },
    /** Rows of placeholder to show while loading, instead of a bare spinner. */
    skeletonRows: { type: Number, default: 0 },
});

const emit = defineEmits(['retry']);

watch(
    () => [props.loading, props.error, props.empty],
    ([loading, error, empty]) => {
        if (loading) {
            announce('Loading…');
        } else if (error) {
            announce(error.message);
        } else if (empty) {
            announce(props.emptyTitle);
        } else {
            announce('');
        }
    },
    { immediate: true },
);
</script>

<template>
    <div v-if="loading">
        <div v-if="skeletonRows > 0" class="space-y-2.5" aria-hidden="true">
            <div
                v-for="row in skeletonRows"
                :key="row"
                class="h-11 rounded-lg bg-sunken motion-safe:animate-pulse"
                :style="{ animationDelay: `${(row - 1) * 90}ms` }"
            />
        </div>
        <div v-else class="flex items-center gap-2 py-6 text-sm text-fg-subtle">
            <AppIcon name="spinner" class="size-4" />
            Loading…
        </div>
    </div>

    <div v-else-if="error" class="rounded-xl border border-danger-line bg-danger-surface px-4 py-3.5">
        <div class="flex items-start gap-2.5">
            <AppIcon name="error" class="mt-0.5 size-4 shrink-0 text-danger-text" />
            <div class="min-w-0">
                <p class="text-sm font-medium text-danger-text">{{ error.message }}</p>
                <p v-if="error.body?.hint" class="mt-1 text-xs text-pretty text-danger-text">
                    {{ error.body.hint }}
                </p>
                <AppButton variant="danger-quiet" icon="refresh" class="mt-3" @click="emit('retry')">
                    Try again
                </AppButton>
            </div>
        </div>
    </div>

    <div v-else-if="empty" class="px-6 py-12 text-center">
        <p class="text-sm font-medium text-fg">{{ emptyTitle }}</p>
        <p v-if="emptyMessage" class="mx-auto mt-1.5 max-w-md text-sm text-pretty text-fg-subtle">
            {{ emptyMessage }}
        </p>
        <div v-if="$slots['empty-action']" class="mt-5 flex flex-wrap justify-center gap-2">
            <slot name="empty-action" />
        </div>
    </div>

    <slot v-else />
</template>
