<script setup>
/**
 * The three states every screen has: loading, failed, or empty. Wrapping them
 * once keeps a failed API call from rendering as a convincingly empty page —
 * "there are no deployments" and "we could not ask" must never look the same in
 * an ops tool.
 */
defineProps({
    loading: { type: Boolean, default: false },
    error: { type: Object, default: null },
    empty: { type: Boolean, default: false },
    emptyMessage: { type: String, default: 'Nothing here yet.' },
});

const emit = defineEmits(['retry']);
</script>

<template>
    <div v-if="loading" class="flex items-center gap-2 py-6 text-sm text-slate-500">
        <span class="inline-block h-3 w-3 animate-spin rounded-full border-2 border-slate-300 border-t-slate-700" />
        Loading…
    </div>

    <div v-else-if="error" class="rounded-md border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-900">
        <p class="font-medium">{{ error.message }}</p>
        <p v-if="error.body?.hint" class="mt-1 text-xs">{{ error.body.hint }}</p>
        <button type="button" class="mt-2 text-xs font-medium underline" @click="emit('retry')">Try again</button>
    </div>

    <p v-else-if="empty" class="py-6 text-sm text-slate-500">{{ emptyMessage }}</p>

    <slot v-else />
</template>
