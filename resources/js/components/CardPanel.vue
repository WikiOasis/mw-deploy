<script setup>
/**
 * The panel every screen is built out of. Same chrome the Blade `x-card`
 * component had, so the SPA looks like the tool it replaces.
 */
defineProps({
    title: { type: String, default: null },
    subtitle: { type: String, default: null },
    flush: { type: Boolean, default: false },
});
</script>

<template>
    <section class="rounded-lg border border-slate-200 bg-white shadow-sm">
        <header
            v-if="title || $slots.actions"
            class="flex flex-wrap items-baseline justify-between gap-2 border-b border-slate-200 px-5 py-3"
        >
            <div>
                <h2 class="font-semibold tracking-tight">{{ title }}</h2>
                <p v-if="subtitle" class="mt-0.5 text-sm text-slate-500">{{ subtitle }}</p>
            </div>
            <div v-if="$slots.actions" class="flex items-center gap-2 text-sm">
                <slot name="actions" />
            </div>
        </header>

        <div :class="flush ? '' : 'p-5'">
            <slot />
        </div>
    </section>
</template>
