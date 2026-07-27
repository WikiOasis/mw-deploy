<script setup>
/**
 * A status pill. The Tailwind classes come from the server, from the same enum
 * `badgeClasses()` methods the Blade views used — so "failed" is the same red
 * everywhere, and adding a status does not mean editing two colour maps.
 */
defineProps({
    label: { type: String, required: true },
    classes: { type: String, default: 'bg-slate-100 text-slate-700 ring-slate-300' },
});
</script>

<template>
    <span
        class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset"
        :class="classes"
    >
        {{ label }}
    </span>
</template>
