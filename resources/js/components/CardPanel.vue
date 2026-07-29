<script setup>
/**
 * The panel every screen is built out of.
 *
 * Elevation comes from a shadow rather than a heavy border, so a page of six
 * panels reads as six surfaces instead of six boxes. The hairline is still there:
 * on the dark theme it is what separates the panel from the canvas, since a
 * shadow over near-black is nothing.
 */
defineProps({
    title: { type: String, default: null },
    subtitle: { type: String, default: null },
    /** For content that owns its own padding — a table that has to reach the edge. */
    flush: { type: Boolean, default: false },
    /** Heading level, so a panel inside a section keeps the outline in order. */
    as: { type: String, default: 'h2' },
});
</script>

<template>
    <!-- `overflow-hidden` is what keeps a flush table's corners inside the
         panel's radius instead of squaring it off. -->
    <section class="panel overflow-hidden">
        <header
            v-if="title || $slots.actions"
            class="flex flex-wrap items-start justify-between gap-x-4 gap-y-2 border-b border-line px-5 py-3.5"
        >
            <div class="min-w-0">
                <component :is="as" class="text-sm font-semibold text-fg">{{ title }}</component>
                <p v-if="subtitle" class="mt-1 max-w-prose text-xs text-pretty text-fg-subtle">
                    {{ subtitle }}
                </p>
            </div>
            <div v-if="$slots.actions" class="flex flex-none items-center gap-2">
                <slot name="actions" />
            </div>
        </header>

        <div :class="flush ? '' : 'p-5'">
            <slot />
        </div>
    </section>
</template>
